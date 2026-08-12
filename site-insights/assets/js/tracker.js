/**
 * Site Insights front-end tracker.
 *
 * Records one pageview per page load, then keeps the server updated with
 * engaged (visible) time, max scroll depth and the link the visitor leaves
 * through — via sendBeacon so data survives the page unload.
 */
(function () {
	'use strict';

	var cfg = window.SiteInsightsCfg;
	if (!cfg || !cfg.viewUrl) {
		return;
	}

	// Honor Do Not Track when the site owner enabled that setting.
	if (cfg.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.msDoNotTrack === '1')) {
		return;
	}

	// Skip automated browsers.
	if (navigator.webdriver) {
		return;
	}

	function randomHex32() {
		var s = '';
		try {
			var a = new Uint8Array(16);
			crypto.getRandomValues(a);
			for (var i = 0; i < a.length; i++) {
				s += ('0' + a[i].toString(16)).slice(-2);
			}
		} catch (e) {
			while (s.length < 32) {
				s += Math.floor(Math.random() * 16).toString(16);
			}
		}
		return s.slice(0, 32);
	}

	var sessionId;
	try {
		sessionId = sessionStorage.getItem('si_session');
		if (!sessionId || !/^[a-f0-9]{32}$/.test(sessionId)) {
			sessionId = randomHex32();
			sessionStorage.setItem('si_session', sessionId);
		}
	} catch (e) {
		sessionId = randomHex32();
	}

	var viewId = null;
	var viewToken = null;
	var engagedMs = 0;
	var visibleSince = document.hidden ? null : Date.now();
	var maxScroll = 0;
	var exitUrl = '';
	var exitType = 'none';
	var lastFlushedSeconds = -1;

	/* ------------------------------------------------------------------
	 * 1. Register the pageview
	 * ---------------------------------------------------------------- */

	fetch(cfg.viewUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		credentials: 'omit',
		keepalive: true,
		body: JSON.stringify({
			session: sessionId,
			post_id: cfg.postId || 0,
			post_type: cfg.postType || '',
			path: location.pathname,
			referrer: document.referrer || ''
		})
	})
		.then(function (r) { return r.ok ? r.json() : null; })
		.then(function (data) {
			if (data && data.id && data.token) {
				viewId = data.id;
				viewToken = data.token;
			}
		})
		.catch(function () { /* tracking must never break the page */ });

	/* ------------------------------------------------------------------
	 * 2. Engaged time (only counts while the tab is visible)
	 * ---------------------------------------------------------------- */

	function engagedSeconds() {
		var ms = engagedMs;
		if (visibleSince !== null) {
			ms += Date.now() - visibleSince;
		}
		return Math.round(ms / 1000);
	}

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			if (visibleSince !== null) {
				engagedMs += Date.now() - visibleSince;
				visibleSince = null;
			}
			flush();
		} else if (visibleSince === null) {
			visibleSince = Date.now();
		}
	});

	/* ------------------------------------------------------------------
	 * 3. Scroll depth
	 * ---------------------------------------------------------------- */

	function onScroll() {
		var doc = document.documentElement;
		var total = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0);
		if (total <= 0) {
			return;
		}
		var seen = (window.pageYOffset || doc.scrollTop || 0) + window.innerHeight;
		var pct = Math.min(100, Math.round((seen / total) * 100));
		if (pct > maxScroll) {
			maxScroll = pct;
		}
	}
	window.addEventListener('scroll', onScroll, { passive: true });
	onScroll();

	/* ------------------------------------------------------------------
	 * 4. Exit destination (internal navigation or outbound click)
	 * ---------------------------------------------------------------- */

	document.addEventListener(
		'click',
		function (event) {
			var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
			if (!link) {
				return;
			}

			var url;
			try {
				url = new URL(link.href, location.href);
			} catch (e) {
				return;
			}

			if (url.protocol !== 'http:' && url.protocol !== 'https:') {
				return;
			}

			if (url.host === location.host) {
				if (url.pathname !== location.pathname) {
					exitUrl = url.pathname;
					exitType = 'internal';
				}
			} else {
				exitUrl = url.href;
				exitType = 'outbound';
				// Outbound = leaving the site; flush right away.
				flush(true);
			}
		},
		true
	);

	/* ------------------------------------------------------------------
	 * 5. Flushing to the server
	 * ---------------------------------------------------------------- */

	function flush(force) {
		if (!viewId) {
			return;
		}

		var seconds = engagedSeconds();
		if (!force && seconds === lastFlushedSeconds && exitType === 'none') {
			return;
		}
		lastFlushedSeconds = seconds;

		var body = JSON.stringify({
			id: viewId,
			token: viewToken,
			seconds: seconds,
			scroll: maxScroll,
			exit_url: exitUrl,
			exit_type: exitType
		});

		if (navigator.sendBeacon) {
			navigator.sendBeacon(cfg.engageUrl, new Blob([body], { type: 'application/json' }));
		} else {
			fetch(cfg.engageUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'omit',
				keepalive: true,
				body: body
			}).catch(function () {});
		}
	}

	// Periodic flush so long reading sessions are not lost on hard closes.
	setInterval(function () { flush(); }, 15000);

	window.addEventListener('pagehide', function () {
		if (visibleSince !== null) {
			engagedMs += Date.now() - visibleSince;
			visibleSince = null;
		}
		flush(true);
	});
})();
