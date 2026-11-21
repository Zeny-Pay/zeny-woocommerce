<?php
// Loads the WordPress install that tests/Integration/run.sh set up and points
// the plugin at the stub Zeny API.
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require '/var/www/html/wp-load.php';

add_filter(
	'zeny_api_base_url',
	static function () {
		return Zeny\WooCommerce\Tests\Integration\StubZeny::BASE_URL;
	}
);

// Checkout notices live in the WooCommerce session, which a CLI request lacks.
WC()->session = new WC_Session_Handler();
WC()->session->init();

// No mail server in the container; order emails are not under test.
add_filter( 'pre_wp_mail', '__return_false' );
