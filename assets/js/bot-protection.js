/**
 * Bot/spam protection glue script — shared by the legacy and blocks checkout.
 *
 * Loaded only when a provider (reCAPTCHA v3 / Turnstile) is enabled
 * with both keys configured (see Bot_Protection::enqueue_scripts()). Fetches a
 * verification token from whichever provider is active, posts it to
 * Bot_Protection::handle_verify(), and re-fetches periodically so a shopper who
 * lingers on checkout doesn't get silently blocked at Place Order once the
 * server-side verification window (see Bot_Protection::VERIFICATION_TTL) lapses.
 *
 * Deliberately independent of the payment form itself — see Bot_Protection.php
 * for why enforcement happens via the WooCommerce session instead of a field on
 * the payment request.
 */
( function () {
	var cfg = window.apNmiBotProtection;
	if ( ! cfg || ! cfg.siteKey || ! cfg.provider ) {
		return;
	}

	function postToken( token ) {
		if ( ! token ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'token', token );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).catch( function () {
			// Nothing more to do client-side: if this never lands, the shopper's
			// session simply stays unverified and validate_fields()/
			// process_payment() reject the order at submit time.
		} );
	}

	var widgetId = null;

	// Turnstile has no true invisible size — 'normal'/'compact'/'flexible' are all
	// visible widget sizes, and whether an actual checkbox/challenge appears for a
	// given visitor is decided by the widget's Mode in the Cloudflare dashboard
	// (e.g. Managed), not by this size param. A shopper Cloudflare decides needs a
	// challenge has to be able to see and click it, so this container is kept
	// small and out of the way rather than display:none.
	function turnstileContainer() {
		var el = document.createElement( 'div' );
		el.style.position = 'fixed';
		el.style.bottom = '12px';
		el.style.left = '12px';
		el.style.zIndex = '99999';
		document.body.appendChild( el );
		return el;
	}

	function refreshRecaptcha() {
		grecaptcha.ready( function () {
			grecaptcha.execute( cfg.siteKey, { action: 'checkout' } ).then( postToken );
		} );
	}

	function refreshTurnstile() {
		if ( null === widgetId ) {
			widgetId = turnstile.render( turnstileContainer(), {
				sitekey: cfg.siteKey,
				size: 'normal',
				callback: postToken,
			} );
			return;
		}
		turnstile.execute( widgetId );
	}

	// Comfortably under Bot_Protection::VERIFICATION_TTL (5 minutes) so a fresh
	// token is always on file before the old one expires.
	var RECHECK_MS = 4 * 60 * 1000;

	var sdkReady = {
		recaptcha: function () { return typeof grecaptcha !== 'undefined'; },
		turnstile: function () { return typeof turnstile !== 'undefined'; },
	};
	var refresh = {
		recaptcha: refreshRecaptcha,
		turnstile: refreshTurnstile,
	}[ cfg.provider ];
	var isReady = sdkReady[ cfg.provider ];

	if ( ! refresh || ! isReady ) {
		return;
	}

	var attempts = 0;
	var waitForSdk = setInterval( function () {
		attempts++;
		if ( isReady() ) {
			clearInterval( waitForSdk );
			refresh();
			setInterval( refresh, RECHECK_MS );
		} else if ( attempts > 40 ) {
			// ~10s at 250ms — SDK blocked (network/adblock) or failed to load.
			clearInterval( waitForSdk );
		}
	}, 250 );
} )();
