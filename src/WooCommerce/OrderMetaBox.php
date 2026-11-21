<?php
/**
 * Zeny box on the order screen.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use InvalidArgumentException;
use WC_Order;
use WP_Post;
use Zeny\WooCommerce\Infrastructure\ZenyApiException;

/**
 * Shows an order's link, mode and payment id, and lets a shop manager confirm
 * an unpaid order with a Zeny payment id when the webhook never came. The
 * payment is read from the API and must pass the same checks as a webhook.
 */
final class OrderMetaBox {

	public const AJAX_ACTION = 'zeny_confirm_payment';

	/**
	 * Adds the box to the order screen (HPOS and posts).
	 */
	public static function register(): void {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'zeny-payment', __( 'Zeny', 'zeny' ), array( self::class, 'render' ), $screen, 'side' );
		}
	}

	/**
	 * Prints the box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Order, or its post on the posts screen.
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof WC_Order || Gateway::ID !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'This order was not paid with Zeny.', 'zeny' ) . '</p>';
			return;
		}
		$links = new OrderLinks();
		$rows  = array(
			__( 'Payment link', 'zeny' ) => $links->linkId( $order ),
			__( 'Link URL', 'zeny' )     => $links->linkUrl( $order ),
			__( 'Mode', 'zeny' )         => '' === $links->mode( $order ) ? '' : LinkCheckout::modeLabel( $links->mode( $order ) ),
			__( 'Payment', 'zeny' )      => $order->get_transaction_id(),
		);
		echo '<dl class="zeny-order">';
		foreach ( $rows as $label => $value ) {
			printf( '<dt>%s</dt><dd style="margin:0 0 .5em;word-break:break-all">%s</dd>', esc_html( $label ), '' === $value ? '&ndash;' : esc_html( $value ) );
		}
		echo '</dl>';

		if ( $order->is_paid() || '' === $links->linkId( $order ) ) {
			return;
		}
		wp_enqueue_script( 'zeny-admin', plugins_url( 'assets/js/admin.js', ZENY_WC_FILE ), array(), ZENY_WC_VERSION, true );
		?>
		<p>
			<label for="zeny-payment-id"><?php esc_html_e( 'Confirm with a Zeny payment id', 'zeny' ); ?></label>
			<input type="text" id="zeny-payment-id" class="widefat" placeholder="pay_…" autocomplete="off" />
		</p>
		<p>
			<button type="button" class="button" id="zeny-confirm-payment"
				data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_ACTION ) ); ?>"
				data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>"
				data-busy="<?php esc_attr_e( 'Checking…', 'zeny' ); ?>"
				data-failed="<?php esc_attr_e( 'The check could not run. Reload the page and try again.', 'zeny' ); ?>"><?php esc_html_e( 'Confirm payment', 'zeny' ); ?></button>
		</p>
		<p id="zeny-confirm-result" role="status"></p>
		<p class="description"><?php esc_html_e( 'Use this only when the webhook did not arrive. The payment must be paid through this order\'s link for the order total.', 'zeny' ); ?></p>
		<?php
	}

	/**
	 * AJAX handler of the confirm button.
	 */
	public static function ajaxConfirm(): void {
		check_ajax_referer( self::AJAX_ACTION );
		$order_id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'edit_post', $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'zeny' ) ), 403 );
		}
		$order      = wc_get_order( $order_id );
		$payment_id = isset( $_POST['payment'] ) ? sanitize_text_field( wp_unslash( $_POST['payment'] ) ) : '';
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'zeny' ) ), 404 );
		}
		$error = self::confirm( $order, $payment_id );
		if ( null === $error ) {
			wp_send_json_success( array( 'message' => __( 'Payment confirmed. Reloading…', 'zeny' ) ) );
		}
		wp_send_json_error( array( 'message' => $error ) );
	}

	/**
	 * Reads the payment and completes the order when it pays it.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id (pay_…).
	 * @return string|null Why the order was not completed, or null when it was.
	 */
	public static function confirm( WC_Order $order, string $payment_id ): ?string {
		$api = Plugin::api();
		if ( null === $api ) {
			return __( 'Save a valid secret API key first.', 'zeny' );
		}
		try {
			$payment = $api->retrievePayment( trim( $payment_id ) );
		} catch ( InvalidArgumentException $e ) {
			return __( 'A Zeny payment id starts with pay_.', 'zeny' );
		} catch ( ZenyApiException $e ) {
			/* translators: 1: error code, 2: request id. */
			return sprintf( __( 'Zeny could not return that payment: %1$s (request %2$s).', 'zeny' ), $e->errorCode(), (string) $e->requestId() );
		}

		$lock = new OrderLock();
		if ( ! $lock->acquire( $order->get_id() ) ) {
			return __( 'The order is being updated. Try again in a moment.', 'zeny' );
		}
		try {
			$fresh   = wc_get_order( $order->get_id() );
			$outcome = $fresh instanceof WC_Order
				? ( new OrderCompleter( new OrderLinks(), $api->mode() ) )->applyConfirmed( $fresh, $payment )
				: OrderCompleter::NOT_ALLOWED;
		} finally {
			$lock->release( $order->get_id() );
		}

		switch ( $outcome ) {
			case OrderCompleter::COMPLETED:
				return null;
			case OrderCompleter::ALREADY:
			case OrderCompleter::EXTRA:
				return __( 'The order is already paid.', 'zeny' );
			default:
				return __( 'That payment does not pay this order: it must be paid, through this order\'s payment link, for the order total, in the same environment as the saved key.', 'zeny' );
		}
	}
}
