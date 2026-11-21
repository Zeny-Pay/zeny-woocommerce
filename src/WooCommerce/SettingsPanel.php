<?php
/**
 * Connection panel on the settings page.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use Zeny\WooCommerce\Infrastructure\ZenyApiException;

/**
 * Shows the mode the saved key uses, the webhook URL to add in the Zeny
 * dashboard, and a button that tests the saved key against the API.
 */
final class SettingsPanel {

	public const AJAX_ACTION = 'zeny_test_connection';

	/**
	 * Prints the panel and loads its script.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public static function render( Settings $settings ): void {
		$key  = $settings->apiKey();
		$mode = null === $key ? __( 'No key saved', 'zeny' ) : LinkCheckout::modeLabel( $key->mode() );

		wp_enqueue_script( 'zeny-admin', plugins_url( 'assets/js/admin.js', ZENY_WC_FILE ), array(), ZENY_WC_VERSION, true );
		?>
		<h3><?php esc_html_e( 'Connection', 'zeny' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'zeny' ); ?></th>
				<td><strong class="zeny-mode"><?php echo esc_html( $mode ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook URL', 'zeny' ); ?></th>
				<td>
					<code><?php echo esc_html( WebhookController::url() ); ?></code>
					<p class="description"><?php esc_html_e( 'In the Zeny dashboard, add a webhook endpoint with this URL and the payment.paid event, then paste its secret in the Webhook secret field above.', 'zeny' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Test', 'zeny' ); ?></th>
				<td>
					<button type="button" class="button" id="zeny-test-connection"
						data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_ACTION ) ); ?>"
						data-busy="<?php esc_attr_e( 'Testing…', 'zeny' ); ?>"
						data-failed="<?php esc_attr_e( 'The test could not run. Reload the page and try again.', 'zeny' ); ?>"><?php esc_html_e( 'Test connection', 'zeny' ); ?></button>
					<span id="zeny-test-result" role="status"></span>
					<p class="description"><?php esc_html_e( 'Tests the saved key. Save your changes first.', 'zeny' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * AJAX handler of the test button.
	 */
	public static function ajaxTestConnection(): void {
		check_ajax_referer( self::AJAX_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'zeny' ) ), 403 );
		}
		$result = self::testConnection();
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Lists one payment link with the saved key.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function testConnection(): array {
		$api = Plugin::api();
		if ( null === $api ) {
			return array(
				'ok'      => false,
				'message' => __( 'Save a valid secret API key first.', 'zeny' ),
			);
		}
		try {
			$api->listPaymentLinks( 1 );
			return array(
				'ok'      => true,
				/* translators: %s: Sandbox or Production. */
				'message' => sprintf( __( 'Connected to Zeny %s.', 'zeny' ), LinkCheckout::modeLabel( $api->mode() ) ),
			);
		} catch ( ZenyApiException $e ) {
			$message = null === $e->statusCode()
				? __( 'Could not reach Zeny. Check that this server can make outgoing https requests.', 'zeny' )
				/* translators: 1: error code, 2: request id. */
				: sprintf( __( 'Zeny refused the request: %1$s (request %2$s).', 'zeny' ), $e->errorCode(), (string) $e->requestId() );
			return array(
				'ok'      => false,
				'message' => $message,
			);
		}
	}
}
