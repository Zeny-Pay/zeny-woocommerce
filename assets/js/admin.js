/**
 * "Test connection" button on the Zeny settings page.
 */
( function () {
	'use strict';

	var button = document.getElementById( 'zeny-test-connection' );
	var result = document.getElementById( 'zeny-test-result' );
	if ( ! button || ! result ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var label = button.textContent;
		var body = new URLSearchParams( {
			action: button.dataset.action,
			_ajax_nonce: button.dataset.nonce,
		} );
		button.disabled = true;
		button.textContent = button.dataset.busy;
		result.textContent = '';

		window
			.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				result.textContent = json && json.data ? json.data.message : '';
			} )
			.catch( function () {
				result.textContent = button.dataset.failed;
			} )
			.then( function () {
				button.disabled = false;
				button.textContent = label;
			} );
	} );
} )();
