<?php
/**
 * Zeny payment gateway.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use WC_Admin_Settings;
use WC_Order;
use WC_Payment_Gateway;
use Zeny\WooCommerce\Domain\ApiKey;
use Zeny\WooCommerce\Domain\InvalidCredential;
use Zeny\WooCommerce\Domain\LinkSpec;
use Zeny\WooCommerce\Domain\Money;
use Zeny\WooCommerce\Domain\WebhookSecret;

/**
 * Sends the shopper to a Zeny payment link, where they choose QRIS, a virtual
 * account or an e-wallet. Refunds are not declared: the Merchant API has no
 * refund route, so merchants refund from the Zeny dashboard.
 */
final class Gateway extends WC_Payment_Gateway {

	public const ID = 'zeny';

	/**
	 * Sets the gateway up from its saved settings.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Zeny', 'zeny' );
		$this->method_description = __( 'Shoppers pay on a Zeny payment link with QRIS, a virtual account or an e-wallet. Orders are marked paid when Zeny confirms the payment.', 'zeny' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) ); // @phpstan-ignore return.void (WooCommerce's own pattern; the bool is unused.)
	}

	/**
	 * Declares the settings form.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = Settings::formFields();
	}

	/**
	 * Available only when turned on, with a valid secret key, in a store that
	 * sells in IDR (the only currency payment links take).
	 */
	public function is_available() {
		return parent::is_available()
			&& null !== ( new Settings( $this->settings ) )->apiKey()
			&& Money::IDR === get_woocommerce_currency();
	}

	/**
	 * Creates or reuses the order's payment link and redirects to it.
	 *
	 * @param int $order_id Order id.
	 * @return array{result: string, redirect?: string}
	 */
	public function process_payment( $order_id ) {
		$order    = wc_get_order( $order_id );
		$checkout = Plugin::checkout();
		if ( ! $order instanceof WC_Order || null === $checkout ) {
			wc_add_notice( __( 'Zeny is not available for this order. Choose another payment method.', 'zeny' ), 'error' );
			return array( 'result' => 'failure' );
		}
		try {
			return array(
				'result'   => 'success',
				'redirect' => $checkout->start( $order ),
			);
		} catch ( CheckoutFailed $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Settings page: the form, then the webhook URL, mode and connection test.
	 *
	 * @return void
	 */
	public function admin_options() {
		parent::admin_options();
		SettingsPanel::render( new Settings( $this->settings ) );
	}

	/**
	 * Renders a secret field empty, so the saved secret never reaches the page.
	 *
	 * @param string               $key  Field key.
	 * @param array<string, mixed> $data Field definition.
	 * @return string
	 */
	public function generate_zeny_secret_html( $key, $data ) {
		$field_key   = $this->get_field_key( $key );
		$placeholder = '' === $this->get_option( $key ) ? '' : __( 'Saved. Leave blank to keep it.', 'zeny' );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<input class="input-text regular-input" type="password" autocomplete="new-password"
						name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>"
						value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes it. ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Keeps the saved key when the field is blank or the new value is not a key.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_api_key_field( $key, $value ) {
		return $this->validateSecret(
			$key,
			$value,
			static function ( string $raw ): void {
				ApiKey::parse( $raw );
			},
			__( 'The secret API key must start with sk_test_ or sk_live_. The saved key was kept.', 'zeny' )
		);
	}

	/**
	 * Keeps the saved secret when the field is blank or the new value is not a secret.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_webhook_secret_field( $key, $value ) {
		return $this->validateSecret(
			$key,
			$value,
			static function ( string $raw ): void {
				WebhookSecret::parse( $raw );
			},
			__( 'The webhook secret must start with whsec_. The saved secret was kept.', 'zeny' )
		);
	}

	/**
	 * Keeps the lifetime within 15 to 10080 minutes.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_link_lifetime_field( $key, $value ) {
		$minutes = absint( is_scalar( $value ) ? $value : 0 );
		return (string) max( LinkSpec::MIN_LIFETIME_MINUTES, min( LinkSpec::MAX_LIFETIME_MINUTES, $minutes ) );
	}

	/**
	 * Keeps valid instrument codes, upper case, and reports the others.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_instruments_field( $key, $value ) {
		$codes   = Settings::splitCodes( sanitize_text_field( wp_unslash( is_string( $value ) ? $value : '' ) ) );
		$valid   = preg_grep( LinkSpec::INSTRUMENT_PATTERN, $codes );
		$valid   = is_array( $valid ) ? array_values( $valid ) : array();
		$invalid = array_diff( $codes, $valid );
		if ( array() !== $invalid ) {
			/* translators: %s: the codes that were removed. */
			WC_Admin_Settings::add_error( sprintf( __( 'These payment method codes were removed because they are not valid: %s', 'zeny' ), implode( ', ', $invalid ) ) );
		}
		return implode( ', ', $valid );
	}

	/**
	 * Shared rule of the secret fields.
	 *
	 * @param string   $key     Field key.
	 * @param mixed    $value   Posted value.
	 * @param callable $parse   Throws InvalidCredential for a bad value.
	 * @param string   $invalid Error shown for a bad value.
	 */
	private function validateSecret( string $key, $value, callable $parse, string $invalid ): string {
		$raw = trim( wp_unslash( is_string( $value ) ? $value : '' ) );
		if ( '' === $raw ) {
			return (string) $this->get_option( $key );
		}
		try {
			$parse( $raw );
			return $raw;
		} catch ( InvalidCredential $e ) {
			WC_Admin_Settings::add_error( $invalid );
			return (string) $this->get_option( $key );
		}
	}
}
