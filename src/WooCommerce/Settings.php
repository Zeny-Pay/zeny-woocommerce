<?php
/**
 * Gateway settings.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use Zeny\WooCommerce\Domain\ApiKey;
use Zeny\WooCommerce\Domain\InvalidCredential;
use Zeny\WooCommerce\Domain\LinkSpec;
use Zeny\WooCommerce\Domain\WebhookSecret;

/**
 * Typed read access to the gateway's saved settings, plus the form fields
 * that edit them. Read fresh on each use, so a saved change applies at once.
 */
final class Settings {

	public const OPTION = 'woocommerce_zeny_settings';

	public const DEFAULT_LIFETIME = 60;

	/**
	 * Saved values.
	 *
	 * @var array<string, mixed>
	 */
	private $values;

	/**
	 * Wraps saved values.
	 *
	 * @param array<string, mixed> $values Values as WooCommerce stores them.
	 */
	public function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * The saved settings.
	 */
	public static function load(): self {
		$values = get_option( self::OPTION, array() );
		return new self( is_array( $values ) ? $values : array() );
	}

	/**
	 * The raw saved values.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return $this->values;
	}

	/**
	 * Whether the merchant turned the gateway on.
	 */
	public function enabled(): bool {
		return 'yes' === ( $this->values['enabled'] ?? 'no' );
	}

	/**
	 * The secret key, or null when missing or malformed.
	 */
	public function apiKey(): ?ApiKey {
		try {
			return ApiKey::parse( $this->text( 'api_key' ) );
		} catch ( InvalidCredential $e ) {
			return null;
		}
	}

	/**
	 * The webhook secret, or null when missing or malformed.
	 */
	public function webhookSecret(): ?WebhookSecret {
		try {
			return WebhookSecret::parse( $this->text( 'webhook_secret' ) );
		} catch ( InvalidCredential $e ) {
			return null;
		}
	}

	/**
	 * Link lifetime in minutes, within 15 to 10080.
	 */
	public function lifetimeMinutes(): int {
		$minutes = (int) ( $this->values['link_lifetime'] ?? self::DEFAULT_LIFETIME );
		return max( LinkSpec::MIN_LIFETIME_MINUTES, min( LinkSpec::MAX_LIFETIME_MINUTES, $minutes ) );
	}

	/**
	 * Instrument codes to offer; [] for every instrument.
	 *
	 * @return list<string>
	 */
	public function instruments(): array {
		return self::splitCodes( $this->text( 'instruments' ) );
	}

	/**
	 * Payer details the payer must give.
	 *
	 * @return list<string>
	 */
	public function requiredPayerFields(): array {
		$fields = $this->values['required_payer_fields'] ?? array();
		return is_array( $fields ) ? array_values( array_map( 'strval', $fields ) ) : array();
	}

	/**
	 * Whether debug logging is on.
	 */
	public function debug(): bool {
		return 'yes' === ( $this->values['debug'] ?? 'no' );
	}

	/**
	 * Splits "QRIS, bca GOPAY" into upper-case codes.
	 *
	 * @param string $raw Codes separated by commas or spaces.
	 * @return list<string>
	 */
	public static function splitCodes( string $raw ): array {
		$codes = preg_split( '/[\s,]+/', strtoupper( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( is_array( $codes ) ? $codes : array() ) );
	}

	/**
	 * The settings form, in WC_Settings_API format.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function formFields(): array {
		return array(
			'enabled'               => array(
				'title'   => __( 'Enable', 'zeny' ),
				'type'    => 'checkbox',
				'label'   => __( 'Take payments with Zeny', 'zeny' ),
				'default' => 'no',
			),
			'title'                 => array(
				'title'       => __( 'Title', 'zeny' ),
				'type'        => 'text',
				'description' => __( 'The payment method name shoppers see at checkout.', 'zeny' ),
				'default'     => __( 'QRIS, virtual account or e-wallet', 'zeny' ),
				'desc_tip'    => true,
			),
			'description'           => array(
				'title'       => __( 'Description', 'zeny' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'zeny' ),
				'default'     => __( 'You will choose how to pay on the Zeny payment page.', 'zeny' ),
				'desc_tip'    => true,
			),
			'api_key'               => array(
				'title'       => __( 'Secret API key', 'zeny' ),
				'type'        => 'zeny_secret',
				'description' => __( 'From the Zeny dashboard. A key starting with sk_test_ uses Sandbox; sk_live_ uses Production.', 'zeny' ),
			),
			'webhook_secret'        => array(
				'title'       => __( 'Webhook secret', 'zeny' ),
				'type'        => 'zeny_secret',
				'description' => __( 'The whsec_ secret of the webhook endpoint you add in the Zeny dashboard.', 'zeny' ),
			),
			'link_lifetime'         => array(
				'title'             => __( 'Link lifetime (minutes)', 'zeny' ),
				'type'              => 'number',
				'description'       => __( 'How long a shopper has to pay, from 15 minutes to 7 days (10080 minutes). Stock stays held for an unpaid order while its link is open, even past the WooCommerce hold stock time; the order is cancelled once the link expires.', 'zeny' ),
				'default'           => (string) self::DEFAULT_LIFETIME,
				'custom_attributes' => array(
					'min'  => (string) LinkSpec::MIN_LIFETIME_MINUTES,
					'max'  => (string) LinkSpec::MAX_LIFETIME_MINUTES,
					'step' => '1',
				),
			),
			'instruments'           => array(
				'title'       => __( 'Payment methods', 'zeny' ),
				'type'        => 'text',
				'description' => __( 'Instrument codes to offer, separated by commas, such as QRIS, BCA, GOPAY. Leave empty to offer every method.', 'zeny' ),
				'default'     => '',
				'placeholder' => 'QRIS, BCA, GOPAY',
			),
			'required_payer_fields' => array(
				'title'       => __( 'Required payer details', 'zeny' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Details the shopper must enter on the Zeny payment page.', 'zeny' ),
				'default'     => array(),
				'options'     => array(
					'name'  => __( 'Name', 'zeny' ),
					'email' => __( 'Email', 'zeny' ),
					'phone' => __( 'Phone', 'zeny' ),
				),
			),
			'debug'                 => array(
				'title'       => __( 'Debug log', 'zeny' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log API calls and webhooks', 'zeny' ),
				'description' => __( 'Writes to WooCommerce > Status > Logs under "zeny". Keys and secrets are never logged.', 'zeny' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * A saved text value.
	 *
	 * @param string $key Setting key.
	 */
	private function text( string $key ): string {
		$value = $this->values[ $key ] ?? '';
		return is_string( $value ) ? trim( $value ) : '';
	}
}
