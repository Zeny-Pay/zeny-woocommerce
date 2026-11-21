<?php
/**
 * Setup problems shown to shop managers.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use Zeny\WooCommerce\Domain\Money;

/**
 * Tells a shop manager, on every admin page, what keeps the gateway from
 * working while it is turned on. Nothing is shown when it is off.
 */
final class AdminNotices {

	/**
	 * Prints the notices that apply.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = Settings::load();
		if ( ! $settings->enabled() ) {
			return;
		}
		foreach ( self::problems( $settings ) as $problem ) {
			printf(
				'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s</p></div>',
				esc_attr( $problem[0] ),
				esc_html__( 'Zeny:', 'zeny' ),
				esc_html( $problem[1] )
			);
		}
	}

	/**
	 * The problems found, as [notice type, message].
	 *
	 * @param Settings $settings Gateway settings.
	 * @return list<array{string, string}>
	 */
	private static function problems( Settings $settings ): array {
		$problems = array();
		$currency = get_woocommerce_currency();
		if ( Money::IDR !== $currency ) {
			$problems[] = array(
				'error',
				/* translators: %s: store currency code. */
				sprintf( __( 'The store currency is %s, but Zeny takes Indonesian rupiah (IDR) only, so Zeny is hidden at checkout. Set the currency under WooCommerce > Settings > General.', 'zeny' ), $currency ),
			);
		}
		if ( null === $settings->apiKey() ) {
			$problems[] = array( 'error', __( 'There is no valid secret API key, so Zeny is hidden at checkout. Add the key under WooCommerce > Settings > Payments > Zeny.', 'zeny' ) );
		}
		if ( null === $settings->webhookSecret() ) {
			$problems[] = array( 'warning', __( 'There is no webhook secret, so paid orders are not updated. Add the webhook endpoint in the Zeny dashboard and paste its secret under WooCommerce > Settings > Payments > Zeny.', 'zeny' ) );
		}
		if ( 0 !== strpos( home_url( '/' ), 'https://' ) ) {
			$problems[] = array( 'warning', __( 'The store is not on https. Shoppers are not sent back to the store after paying, and Zeny sends webhooks to https addresses only, so orders are not marked paid on their own. Move the store to https, or confirm each payment on the order screen with its Zeny payment id.', 'zeny' ) );
		}
		return $problems;
	}
}
