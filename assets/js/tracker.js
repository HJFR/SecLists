/**
 * Simple Site Analytics — front-end tracker.
 *
 * Records one pageview per page load, accumulates "engaged" time (tab visible
 * only), and reports clicks on outbound links. No cookies, no localStorage.
 */
( function () {
	'use strict';

	var cfg = window.SSA_CFG;
	if ( ! cfg || ! cfg.endpoint || navigator.webdriver ) {
		return;
	}

	var viewId =
		window.crypto && window.crypto.randomUUID
			? window.crypto.randomUUID()
			: 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
					var r = ( Math.random() * 16 ) | 0;
					return ( c === 'x' ? r : ( r & 0x3 ) | 0x8 ).toString( 16 );
			  } );

	function send( path, data ) {
		var url = cfg.endpoint.replace( /\/$/, '' ) + path;
		var body = JSON.stringify( data );

		if ( navigator.sendBeacon ) {
			try {
				navigator.sendBeacon( url, new Blob( [ body ], { type: 'application/json' } ) );
				return;
			} catch ( e ) {
				// Fall through to fetch.
			}
		}

		try {
			window.fetch( url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
				credentials: 'omit',
			} );
		} catch ( e ) {
			// Tracking must never break the page.
		}
	}

	// 1. Pageview.
	send( '/view', {
		id: viewId,
		post_id: cfg.postId || 0,
		url: window.location.href,
		referrer: document.referrer || '',
	} );

	// 2. Engaged time — only counts while the tab is visible.
	var engagedMs = 0;
	var lastTick = document.visibilityState === 'visible' ? performance.now() : null;
	var lastSentSeconds = 0;

	function flushTime() {
		if ( lastTick !== null ) {
			engagedMs += performance.now() - lastTick;
			lastTick = performance.now();
		}
	}

	function sendDuration() {
		var seconds = Math.round( engagedMs / 1000 );
		if ( seconds > 0 && seconds !== lastSentSeconds ) {
			lastSentSeconds = seconds;
			send( '/duration', { id: viewId, seconds: seconds } );
		}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' ) {
			lastTick = performance.now();
		} else {
			flushTime();
			lastTick = null;
			sendDuration();
		}
	} );

	window.addEventListener( 'pagehide', function () {
		flushTime();
		sendDuration();
	} );

	// Periodic save so long reads survive browser/tab crashes.
	window.setInterval( function () {
		flushTime();
		sendDuration();
	}, 15000 );

	// 3. Outbound link clicks.
	document.addEventListener(
		'click',
		function ( event ) {
			var link = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;
			if ( ! link || ! /^https?:/i.test( link.href ) ) {
				return;
			}

			var host;
			try {
				host = new URL( link.href ).host;
			} catch ( e ) {
				return;
			}

			if ( host && host !== window.location.host ) {
				send( '/event', { id: viewId, type: 'outbound', url: link.href } );
			}
		},
		true
	);
} )();
