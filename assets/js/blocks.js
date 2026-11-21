/**
 * Registers Zeny as a payment method of the Checkout block. No build step:
 * it uses the globals WooCommerce and WordPress load (wc.*, wp.*).
 */
( function ( wc, wp ) {
	'use strict';

	var settings = wc.wcSettings.getSetting( 'zeny_data', {} );
	var decode = wp.htmlEntities.decodeEntities;
	var title = decode( settings.title || 'Zeny' );

	function Content() {
		return wp.element.createElement( 'div', null, decode( settings.description || '' ) );
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'zeny',
		label: title,
		ariaLabel: title,
		content: wp.element.createElement( Content ),
		edit: wp.element.createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )( window.wc, window.wp );
