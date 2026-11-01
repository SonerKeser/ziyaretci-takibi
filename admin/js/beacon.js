/**
 * Ziyaretçi Takibi — önbellek güvenli beacon.
 * Sayfa (önbellekten bile olsa) yüklendiğinde çalışır; sayfa bağlamını
 * <body> sınıflarından okur ve REST ucuna küçük bir istek gönderir.
 * IP ve User-Agent sunucu tarafında ziyaretçinin isteğinden alınır.
 */
(function () {
	'use strict';

	var cfg = window.ZT_BEACON;
	if (!cfg || !cfg.url) { return; }

	function pageTypeFromBody() {
		var c = ' ' + (document.body.className || '') + ' ';
		function has(cls) { return c.indexOf(' ' + cls + ' ') > -1 || c.indexOf(' ' + cls) > -1; }
		if (c.indexOf('error404') > -1) { return '404'; }
		if (c.indexOf('search') > -1) { return 'search'; }
		if (c.indexOf('home') > -1) { return 'front'; }   // WP ön sayfaya 'home' sınıfı ekler
		if (c.indexOf('blog') > -1) { return 'home'; }
		if (c.indexOf('single') > -1) { return 'single'; }
		if (c.indexOf('archive') > -1 || c.indexOf('category') > -1 || c.indexOf('tag') > -1 ||
			c.indexOf('author') > -1 || c.indexOf('date') > -1 || c.indexOf('tax-') > -1) { return 'archive'; }
		if (c.indexOf('page') > -1) { return 'page'; }
		return 'page';
	}

	function postIdFromBody() {
		var m = (document.body.className || '').match(/\b(?:postid|page-id)-(\d+)\b/);
		return m ? parseInt(m[1], 10) : 0;
	}

	function send() {
		var payload = {
			url: location.href,
			title: document.title,
			referrer: document.referrer || '',
			page_type: pageTypeFromBody(),
			post_id: postIdFromBody()
		};

		try {
			var body = JSON.stringify(payload);
			if (navigator.sendBeacon) {
				var blob = new Blob([body], { type: 'application/json' });
				if (navigator.sendBeacon(cfg.url, blob)) { return; }
			}
			// Yedek: sendBeacon yoksa ya da kuyruğa alınamazsa.
			fetch(cfg.url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
				credentials: 'same-origin'
			}).catch(function () { /* sessizce yut */ });
		} catch (e) { /* yoksay */ }
	}

	// Sayfa etkileşime hazır olduğunda bir kez gönder.
	if (document.readyState === 'complete' || document.readyState === 'interactive') {
		setTimeout(send, 0);
	} else {
		window.addEventListener('DOMContentLoaded', function () { setTimeout(send, 0); });
	}
})();
