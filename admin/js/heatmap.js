/**
 * Ziyaretçi Takibi — ısı haritası toplayıcı.
 * Tıklamaları (sayfa genişliği/yüksekliğine göre binde konum) ve en derin kaydırma
 * yüzdesini toplar; sayfa gizlenirken REST ucuna gönderir. Örnekleme ile hacim sınırlanır.
 */
(function () {
	'use strict';

	var cfg = window.ZT_HEAT;
	if (!cfg || !cfg.url) { return; }
	if (Math.random() * 100 >= (cfg.sample || 100)) { return; } // örnekleme kapısı

	var path = location.pathname || '/';
	var clicks = [];
	var maxDepth = 0;
	var sent = false;

	function docHeight() {
		return Math.max(
			document.documentElement ? document.documentElement.scrollHeight : 0,
			document.body ? document.body.scrollHeight : 0,
			1
		);
	}

	document.addEventListener('click', function (e) {
		var w = window.innerWidth || 1;
		var h = docHeight();
		var pageY = (typeof e.pageY === 'number') ? e.pageY : (e.clientY + (window.scrollY || 0));
		var x = Math.round((e.clientX / w) * 1000);
		var y = Math.round((pageY / h) * 1000);
		if (x >= 0 && x <= 1000 && y >= 0 && y <= 1000) {
			clicks.push([x, y]);
			if (clicks.length > 200) { clicks.shift(); }
		}
	}, true);

	function updateDepth() {
		var d = Math.round((((window.scrollY || 0) + (window.innerHeight || 0)) / docHeight()) * 1000);
		if (d > maxDepth) { maxDepth = Math.min(1000, d); }
	}
	updateDepth();
	window.addEventListener('scroll', updateDepth, { passive: true });

	function send() {
		if (sent) { return; }
		if (!clicks.length && maxDepth <= 0) { return; }
		sent = true;
		var payload = { url: path, clicks: clicks, scroll: maxDepth };
		try {
			var body = JSON.stringify(payload);
			if (navigator.sendBeacon && navigator.sendBeacon(cfg.url, new Blob([body], { type: 'application/json' }))) {
				return;
			}
			fetch(cfg.url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
				credentials: 'same-origin'
			}).catch(function () {});
		} catch (e) { /* yoksay */ }
	}

	window.addEventListener('pagehide', send);
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') { send(); }
	});
})();
