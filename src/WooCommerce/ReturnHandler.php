<?php
/**
 * Order-received page while the payment is confirmed.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use WC_Order;

/**
 * A shopper can land on the order-received page before the webhook arrives.
 * The page then says the payment is being confirmed and checks the order
 * every 5 seconds for 2 minutes (a local read, no API call), reloading once
 * it is paid. Only a verified webhook or an admin confirmation marks it paid.
 */
final class ReturnHandler {

	public const AJAX_ACTION = 'zeny_order_status';

	/**
	 * Prints the notice and starts the check on the thank-you page of an unpaid Zeny order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->is_paid() || ! $order->has_status( 'pending' ) ) {
			return;
		}
		wp_enqueue_script( 'zeny-order-status', plugins_url( 'assets/js/order-status.js', ZENY_WC_FILE ), array(), ZENY_WC_VERSION, true );
		wp_localize_script(
			'zeny-order-status',
			'zenyOrderStatus',
			array(
				'url'     => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'order'   => $order->get_id(),
				'key'     => $order->get_order_key(),
				'timeout' => __( 'We have not received the confirmation yet. You will get an email once your payment is confirmed.', 'zeny' ),
			)
		);
		printf(
			'<p class="woocommerce-info zeny-confirming" role="status">%s</p>',
			esc_html__( 'We are confirming your payment. This page updates on its own.', 'zeny' )
		);
	}

	/**
	 * AJAX handler: whether the order is paid. Needs the order key, as the
	 * order-received page does, so no one can probe other orders.
	 */
	public static function ajaxStatus(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key authorises this read-only check; a nonce would expire on cached pages.
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$paid = self::status( $order_id, $key );
		if ( null === $paid ) {
			wp_send_json_error( null, 404 );
		}
		wp_send_json_success( array( 'paid' => $paid ) );
	}

	/**
	 * Whether the order is paid, or null when the id and key do not match an order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $key      Order key.
	 */
	public static function status( int $order_id, string $key ): ?bool {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		return $order->is_paid();
	}
}
