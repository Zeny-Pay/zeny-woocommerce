<?php
/**
 * Block checkout integration.
 *
 * @package Zeny\WooCommerce
 */

namespace Zeny\WooCommerce\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Zeny in the Checkout block. Payment itself goes through the
 * gateway's process_payment, as in the classic checkout.
 */
final class BlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name, the gateway id.
	 *
	 * @var string
	 */
	protected $name = Gateway::ID;

	/**
	 * Loads the saved settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = Settings::load()->toArray();
	}

	/**
	 * Shown only when the gateway is available.
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ Gateway::ID ] ) && $gateways[ Gateway::ID ]->is_available();
	}

	/**
	 * The script that registers the method.
	 *
	 * @return list<string>
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'zeny-blocks',
			plugins_url( 'assets/js/blocks.js', ZENY_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			ZENY_WC_VERSION,
			true
		);
		return array( 'zeny-blocks' );
	}

	/**
	 * Data the script reads as getSetting( 'zeny_data' ).
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array( 'products' ),
		);
	}
}
