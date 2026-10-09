/**
 * Speed X-Ray admin screen behaviour.
 *
 * @package SpeedXRay
 */
( function () {
	// Confirm destructive actions.
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// Switching the report dropdown loads that report.
	document.querySelectorAll( '[data-sxr-autosubmit]' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			select.form.submit();
		} );
	} );

	// Scans take a while: show progress and prevent double submits.
	document.querySelectorAll( '[data-sxr-scan]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			var button = form.querySelector( '[type=submit]' );
			var note = form.querySelector( '.sxr-scanning' );
			if ( button ) {
				button.disabled = true;
			}
			if ( note ) {
				note.hidden = false;
			}
		} );
	} );

	// Google PageSpeed test: one run per click, then reload to show the new median.
	var cfg = window.sxrAdmin || {};
	document.querySelectorAll( '[data-sxr-psi]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var msg = button.parentNode.querySelector( '.sxr-psi-msg' );
			var body = new URLSearchParams();
			body.append( 'action', 'speedxray_psi' );
			body.append( 'nonce', cfg.psiNonce );
			body.append( 'url', button.getAttribute( 'data-url' ) );
			body.append( 'strategy', button.getAttribute( 'data-strategy' ) );
			button.disabled = true;
			msg.className = 'sxr-psi-msg';
			msg.textContent = cfg.i18n.testing;
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					if ( json && json.success ) {
						window.location.reload();
						return;
					}
					button.disabled = false;
					msg.className = 'sxr-psi-msg is-error';
					msg.textContent = cfg.i18n.failed + ' ' + ( json && json.data && json.data.message ? json.data.message : '' );
				} )
				.catch( function () {
					button.disabled = false;
					msg.className = 'sxr-psi-msg is-error';
					msg.textContent = cfg.i18n.failed;
				} );
		} );
	} );
} )();
