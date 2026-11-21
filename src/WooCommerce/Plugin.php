<?php
/**
 * Plugin entry point.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use InvalidArgumentException;
use WC_Order;
use Zeny\WooCommerce\Infrastructure\Logger;
use Zeny\WooCommerce\Infrastructure\WpHttpTransport;
use Zeny\WooCommerce\Infrastructure\ZenyApi;

/**
 * Wires the plugin into WordPress and builds its services. Services are built
 * per use from the saved settings, so a changed key applies on the next request.
 */
final class Plugin {

	/**
	 * Hooks the plugin in, or shows an admin notice when WooCommerce is missing.
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'zeny', false, dirname( plugin_basename( ZENY_WC_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'renderMissingWooCommerceNotice' ) );
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( self::class, 'addGateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( self::class, 'registerBlocks' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'onOrderCancelled' ), 10, 2 );
		add_action( 'admin_notices', array( AdminNotices::class, 'render' ) );
		add_action( 'wp_ajax_' . SettingsPanel::AJAX_ACTION, array( SettingsPanel::class, 'ajaxTestConnection' ) );
		add_action( 'rest_api_init', array( WebhookController::class, 'register' ) );
		add_action( 'woocommerce_thankyou_' . Gateway::ID, array( ReturnHandler::class, 'render' ) );
		add_action( 'wp_ajax_' . ReturnHandler::AJAX_ACTION, array( ReturnHandler::class, 'ajaxStatus' ) );
		add_action( 'wp_ajax_nopriv_' . ReturnHandler::AJAX_ACTION, array( ReturnHandler::class, 'ajaxStatus' ) );
		add_action( 'add_meta_boxes', array( OrderMetaBox::class, 'register' ) );
		add_action( 'wp_ajax_' . OrderMetaBox::AJAX_ACTION, array( OrderMetaBox::class, 'ajaxConfirm' ) );
	}

	/**
	 * Adds the gateway to WooCommerce.
	 *
	 * @param array<mixed> $gateways Gateway classes or instances.
	 * @return array<mixed>
	 */
	public static function addGateway( array $gateways ): array {
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * Registers the Checkout block integration.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Block payment methods.
	 */
	public static function registerBlocks( $registry ): void {
		$registry->register( new BlocksSupport() );
	}

	/**
	 * Cancels the link of an order WooCommerce cancelled.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public static function onOrderCancelled( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		$api   = self::api();
		if ( $order instanceof WC_Order && null !== $api ) {
			( new LinkCanceller( $api, new OrderLinks(), self::logger() ) )->cancel( $order );
		}
	}

	/**
	 * The saved settings.
	 */
	public static function settings(): Settings {
		return Settings::load();
	}

	/**
	 * Logger that honours the debug setting.
	 */
	public static function logger(): Logger {
		return new Logger( self::settings()->debug() );
	}

	/**
	 * API client for the saved key, or null without a valid key.
	 */
	public static function api(): ?ZenyApi {
		$key = self::settings()->apiKey();
		if ( null === $key ) {
			return null;
		}
		/**
		 * Filters the Zeny API host, for a stub server in tests or a white-label
		 * host. It must be https (http only for localhost). Null keeps the host
		 * the key's prefix picks.
		 *
		 * @param string|null $base_url Host override.
		 */
		$base_url = apply_filters( 'zeny_api_base_url', null );
		try {
			return new ZenyApi( $key, new WpHttpTransport(), 'zeny-woocommerce/' . ZENY_WC_VERSION, is_string( $base_url ) ? $base_url : null );
		} catch ( InvalidArgumentException $e ) {
			self::logger()->error( 'The zeny_api_base_url filter returned a URL that is not allowed.' );
			return null;
		}
	}

	/**
	 * Checkout service, or null without a valid key.
	 */
	public static function checkout(): ?LinkCheckout {
		$api = self::api();
		if ( null === $api ) {
			return null;
		}
		return new LinkCheckout( $api, self::settings(), new OrderLinks(), new OrderLock(), self::logger(), self::siteHash() );
	}

	/**
	 * Short hash of the site URL. It keeps idempotency keys of two shops that
	 * share a Zeny project apart.
	 */
	public static function siteHash(): string {
		return substr( md5( site_url() ), 0, 12 );
	}

	/**
	 * Tells an administrator that the plugin needs WooCommerce.
	 */
	public static function renderMissingWooCommerceNotice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Zeny for WooCommerce needs WooCommerce. Install and activate WooCommerce to take Zeny payments.', 'zeny' )
		);
	}
}
