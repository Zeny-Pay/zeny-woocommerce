<?php
/**
 * Keeps orders with an open link from the hold-stock cancellation.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use WC_Order;

/**
 * WooCommerce cancels unpaid orders once the hold-stock time passes, which
 * can be shorter than the link lifetime. A shopper still paying would then
 * pay a cancelled order. So an order whose current link has not expired is
 * kept; a later cron pass after the expiry cancels it. Its stock stays held
 * meanwhile.
 */
final class UnpaidOrderGuard {

	/**
	 * Filter callback of woocommerce_cancel_unpaid_order.
	 *
	 * @param bool|mixed $cancel Whether WooCommerce is about to cancel the order.
	 * @param mixed      $order  Unpaid order; a filter callback cannot trust its arguments.
	 * @return bool
	 */
	public static function filter( $cancel, $order ) {
		if ( ! $cancel || ! $order instanceof WC_Order || Gateway::ID !== $order->get_payment_method() ) {
			return (bool) $cancel;
		}
		$links   = new OrderLinks();
		$expires = $links->linkExpiresAt( $order );
		$open    = '' !== $links->linkId( $order ) && null !== $expires && $expires > time();
		return ! $open;
	}
}
