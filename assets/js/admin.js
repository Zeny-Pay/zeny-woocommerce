/**
 * Zeny admin buttons: "Test connection" on the settings page and
 * "Confirm payment" on the order screen. Both post to admin-ajax and show
 * the answer's message.
 */
( function () {
	'use strict';

	function wire( button, result, fields, onSuccess ) {
		if ( ! button || ! result ) {
			return;
		}
		button.addEventListener( 'click', function () {
			var label = button.textContent;
			var body = new URLSearchParams( fields() );
			body.set( 'action', button.dataset.action );
			body.set( '_ajax_nonce', button.dataset.nonce );
			button.disabled = true;
			button.textContent = button.dataset.busy;
			result.textContent = '';

			window
				.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					result.textContent = json && json.data ? json.data.message : button.dataset.failed;
					if ( json && json.success && onSuccess ) {
						onSuccess();
					}
				} )
				.catch( function () {
					result.textContent = button.dataset.failed;
				} )
				.then( function () {
					button.disabled = false;
					button.textContent = label;
				} );
		} );
	}

	wire(
		document.getElementById( 'zeny-test-connection' ),
		document.getElementById( 'zeny-test-result' ),
		function () {
			return {};
		}
	);

	var confirm = document.getElementById( 'zeny-confirm-payment' );
	wire(
		confirm,
		document.getElementById( 'zeny-confirm-result' ),
		function () {
			return {
				order: confirm.dataset.order,
				payment: document.getElementById( 'zeny-payment-id' ).value,
			};
		},
		function () {
			window.location.reload();
		}
	);
} )();
