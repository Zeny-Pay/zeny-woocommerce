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
 * pay a cancelled order. So an order whose current link has not expired, and
 * belongs to the configured key's environment, is kept; a later cron pass
 * after the expiry cancels it. Its stock stays held meanwhile.
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
		$link_id = $links->linkId( $order );
		$key     = Settings::load()->apiKey();
		$expires = $links->linkExpiresAt( $order );
		// A link of the other environment, or with no usable key, cannot be paid through this shop's settings.
		$open = '' !== $link_id && null !== $key && $links->linkMode( $order, $link_id ) === $key->mode()
			&& null !== $expires && $expires > time();
		return ! $open;
	}
}
