/**
 * Checks every 5 seconds, for up to 2 minutes, whether the order shown on the
 * order-received page is paid, and reloads the page when it is.
 */
( function ( config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	var INTERVAL_MS = 5000;
	var MAX_CHECKS = 24;
	var checks = 0;
	var url =
		config.url +
		'?action=' + encodeURIComponent( config.action ) +
		'&order=' + encodeURIComponent( config.order ) +
		'&key=' + encodeURIComponent( config.key );

	function giveUp() {
		var notice = document.querySelector( '.zeny-confirming' );
		if ( notice ) {
			notice.textContent = config.timeout;
		}
	}

	function check() {
		checks++;
		window
			.fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data.paid ) {
					window.location.reload();
					return;
				}
				next();
			} )
			.catch( next );
	}

	function next() {
		if ( checks >= MAX_CHECKS ) {
			giveUp();
			return;
		}
		window.setTimeout( check, INTERVAL_MS );
	}

	next();
} )( window.zenyOrderStatus );
