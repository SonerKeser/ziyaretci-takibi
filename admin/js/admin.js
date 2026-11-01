/**
 * Ziyaretçi Takibi — yönetim paneli uygulaması.
 * Tüm ekranlar istemci tarafında çizilir; veri admin-ajax üzerinden gelir.
 */
(function () {
	'use strict';

	var BOOT = window.ZT_BOOT || {};
	var app = document.getElementById('zt-app');
	if (!app) { return; }

	/* ═══════════════════ YARDIMCILAR ═══════════════════ */

	function api(action, data) {
		var body = new URLSearchParams();
		body.set('action', 'zt_' + action);
		body.set('nonce', BOOT.nonce || '');
		if (data) {
			Object.keys(data).forEach(function (k) {
				if (data[k] !== undefined && data[k] !== null) { body.set(k, data[k]); }
			});
		}
		return fetch(BOOT.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (res) {
			if (!res.ok) { throw new Error('Sunucu hatası (' + res.status + ')'); }
			return res.json();
		}).then(function (json) {
			if (!json || json.success !== true) {
				var msg = (json && json.data && json.data.message) ? json.data.message : 'Beklenmeyen yanıt alındı.';
				throw new Error(msg);
			}
			return json.data;
		});
	}

	function esc(value) {
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function n(x) {
		try { return Number(x || 0).toLocaleString('tr-TR'); } catch (e) { return String(x); }
	}

	function debounce(fn, ms) {
		var timer = null;
		return function () {
			var args = arguments, ctx = this;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(ctx, args); }, ms);
		};
	}

	// Kayıtlar sitenin WordPress saat diliminde saklanır. Ham değeri tarayıcının
	// saat dilimiyle karıştırmamak için önce gerçek zaman anına (UTC) çeviriyoruz;
	// gösterim sonra tarayıcının yerel saat diliminde yapılır.
	var SITE_TZ_OFFSET = Number(BOOT.gmtOffset || 0); // saniye

	function parseMysql(str) {
		if (!str) { return null; }
		var m = String(str).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
		if (!m) { return null; }
		var utcMs = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) - SITE_TZ_OFFSET * 1000;
		return new Date(utcMs);
	}

	var dtFmt = null, dFmt = null;
	try {
		dtFmt = new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
		dFmt = new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'long', year: 'numeric', weekday: 'long' });
	} catch (e) { /* yoksay */ }

	function fullTime(mysql) {
		var d = parseMysql(mysql);
		if (!d) { return '—'; }
		return dtFmt ? dtFmt.format(d) : d.toLocaleString();
	}

	function dayTitle(mysql) {
		var d = parseMysql(mysql);
		if (!d) { return '—'; }
		return dFmt ? dFmt.format(d) : d.toLocaleDateString();
	}

	var clockFmt = null;
	try {
		clockFmt = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit' });
	} catch (e) { /* yoksay */ }

	function clockOf(mysql) {
		var d = parseMysql(mysql);
		if (!d) { return '—'; }
		if (clockFmt) { return clockFmt.format(d); }
		return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
	}

	function relTime(mysql) {
		var d = parseMysql(mysql);
		if (!d) { return '—'; }
		var diff = Math.floor((Date.now() - d.getTime()) / 1000);
		if (diff < 0) { diff = 0; }
		if (diff < 60) { return 'az önce'; }
		if (diff < 3600) { return Math.floor(diff / 60) + ' dk önce'; }
		if (diff < 86400) { return Math.floor(diff / 3600) + ' sa önce'; }
		if (diff < 7 * 86400) { return Math.floor(diff / 86400) + ' gün önce'; }
		return fullTime(mysql);
	}

	function duration(sec) {
		sec = Math.max(0, Math.round(sec));
		if (sec < 60) { return sec + ' sn'; }
		var m = Math.floor(sec / 60);
		if (m < 60) { return m + ' dk ' + (sec % 60 ? (sec % 60) + ' sn' : '').trim(); }
		var h = Math.floor(m / 60);
		return h + ' sa ' + (m % 60 ? (m % 60) + ' dk' : '').trim();
	}

	function flag(cc) {
		if (!cc || cc.length !== 2) { return '🌐'; }
		try {
			return String.fromCodePoint.apply(null, cc.toUpperCase().split('').map(function (c) {
				return 127397 + c.charCodeAt(0);
			}));
		} catch (e) { return '🌐'; }
	}

	function urlPath(u) {
		if (!u) { return ''; }
		try {
			var parsed = new URL(u);
			var p = parsed.pathname + parsed.search;
			return p === '/' ? '/ (ana sayfa)' : p;
		} catch (e) { return u; }
	}

	/* ── İkonlar (feather stilinde, stroke tabanlı) ── */
	var I = {
		users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
		activity: '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
		bot: '<rect x="4" y="8" width="16" height="12" rx="2"/><path d="M12 8V4M8 4h8"/><circle cx="9" cy="13" r="1"/><circle cx="15" cy="13" r="1"/><path d="M9 17h6"/>',
		globe: '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
		clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		monitor: '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
		smartphone: '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
		tablet: '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
		search: '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
		download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
		trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		x: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		left: '<polyline points="15 18 9 12 15 6"/>',
		right: '<polyline points="9 18 15 12 9 6"/>',
		filter: '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
		external: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
		pin: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
		file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
		refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
		user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		check: '<polyline points="20 6 9 17 4 12"/>',
		alert: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
		radar: '<circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14"/>',
		link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		chart: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
		list: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
		shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
		pause: '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
		play: '<polygon points="5 3 19 12 5 21 5 3"/>',
		megaphone: '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
		mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>',
		wifi: '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
		ban: '<circle cx="12" cy="12" r="10"/><line x1="4.9" y1="4.9" x2="19.1" y2="19.1"/>',
		bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
		copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
		server: '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
		funnel: '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
		grip: '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>'
	};

	function icon(name, size) {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' +
			(size ? ' style="width:' + size + 'px;height:' + size + 'px"' : '') + '>' + (I[name] || '') + '</svg>';
	}

	var DEVICE_META = {
		desktop: { label: 'Masaüstü', icon: 'monitor', color: '#22d3ee' },
		mobile: { label: 'Mobil', icon: 'smartphone', color: '#a78bfa' },
		tablet: { label: 'Tablet', icon: 'tablet', color: '#fbbf24' },
		bot: { label: 'Bot', icon: 'bot', color: '#64748b' }
	};

	function deviceMeta(d) {
		return DEVICE_META[d] || { label: d || 'Bilinmiyor', icon: 'monitor', color: '#64748b' };
	}

	var PAGE_TYPES = {
		front: 'Ana Sayfa', home: 'Blog', page: 'Sayfa', single: 'Yazı',
		archive: 'Arşiv', search: 'Arama', '404': '404'
	};

	/* ── Toast ── */
	var toastWrap = null;
	function toast(msg, type) {
		if (!toastWrap) {
			toastWrap = document.createElement('div');
			toastWrap.className = 'zt-toasts';
			document.body.appendChild(toastWrap);
		}
		var t = document.createElement('div');
		t.className = 'zt-toast ' + (type === 'err' ? 'err' : 'ok');
		t.innerHTML = icon(type === 'err' ? 'alert' : 'check') + '<span>' + esc(msg) + '</span>';
		toastWrap.appendChild(t);
		setTimeout(function () {
			t.classList.add('out');
			setTimeout(function () { t.remove(); }, 260);
		}, 3400);
	}

	/* ── Onay penceresi ── */
	function confirmModal(opts) {
		return new Promise(function (resolve) {
			var overlay = document.createElement('div');
			overlay.className = 'zt-modal-overlay zt-shell';
			overlay.style.margin = '0';
			overlay.style.background = 'rgba(4,8,16,0.65)';
			overlay.style.border = 'none';
			overlay.style.borderRadius = '0';
			overlay.style.padding = '0';
			overlay.style.minHeight = '0';
			overlay.style.boxShadow = 'none';
			overlay.innerHTML =
				'<div class="zt-modal">' +
				'<h3>' + esc(opts.title || 'Emin misiniz?') + '</h3>' +
				'<p>' + esc(opts.text || '') + '</p>' +
				'<div class="acts">' +
				'<button type="button" class="zt-btn" data-act="cancel">Vazgeç</button>' +
				'<button type="button" class="zt-btn ' + (opts.danger ? 'zt-btn-danger' : 'zt-btn-primary') + '" data-act="ok">' + esc(opts.okLabel || 'Onayla') + '</button>' +
				'</div></div>';
			document.body.appendChild(overlay);
			overlay.getBoundingClientRect();
			setTimeout(function () { overlay.classList.add('is-open'); }, 15);

			function close(result) {
				overlay.classList.remove('is-open');
				setTimeout(function () { overlay.remove(); }, 200);
				resolve(result);
			}
			overlay.addEventListener('click', function (ev) {
				if (ev.target === overlay) { close(false); }
				var btn = ev.target.closest('[data-act]');
				if (btn) { close(btn.getAttribute('data-act') === 'ok'); }
			});
		});
	}

	/* ── Sayı animasyonu ── */
	function animateNum(el, target) {
		target = Number(target) || 0;
		var start = null, dur = 650, done = false;
		function stepFn(ts) {
			if (done) { return; }
			if (!start) { start = ts; }
			var p = Math.min(1, (ts - start) / dur);
			var eased = 1 - Math.pow(1 - p, 3);
			el.textContent = n(Math.round(target * eased));
			if (p < 1) { requestAnimationFrame(stepFn); } else { done = true; }
		}
		requestAnimationFrame(stepFn);
		// Sekme arka plandaysa rAF durur; son değeri her koşulda garanti et.
		setTimeout(function () {
			if (!done) { done = true; el.textContent = n(target); }
		}, dur + 150);
	}

	/* ── Sayfalama ── */
	function renderPager(container, total, page, perPage, onGo) {
		var pages = Math.max(1, Math.ceil(total / perPage));
		var start = total === 0 ? 0 : (page - 1) * perPage + 1;
		var end = Math.min(total, page * perPage);

		var html = '<div class="zt-pagination">';
		html += '<span class="info">' + n(total) + ' kayıt • ' + n(start) + '–' + n(end) + ' arası gösteriliyor</span>';
		html += '<button type="button" class="zt-page-btn" data-p="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '>' + icon('left', 12) + '</button>';

		var nums = [];
		if (pages <= 7) {
			for (var i = 1; i <= pages; i++) { nums.push(i); }
		} else {
			nums.push(1);
			if (page > 3) { nums.push('…'); }
			for (var j = Math.max(2, page - 1); j <= Math.min(pages - 1, page + 1); j++) { nums.push(j); }
			if (page < pages - 2) { nums.push('…'); }
			nums.push(pages);
		}
		nums.forEach(function (p) {
			if (p === '…') { html += '<span class="zt-page-dots">…</span>'; }
			else { html += '<button type="button" class="zt-page-btn ' + (p === page ? 'is-active' : '') + '" data-p="' + p + '">' + n(p) + '</button>'; }
		});

		html += '<button type="button" class="zt-page-btn" data-p="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '>' + icon('right', 12) + '</button>';
		html += '</div>';
		container.innerHTML = html;

		container.querySelectorAll('.zt-page-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var p = parseInt(btn.getAttribute('data-p'), 10);
				if (p >= 1 && p <= pages && p !== page) { onGo(p); }
			});
		});
	}

	/* ── Ortak başlık ── */
	function headerHtml(subtitle, rightHtml) {
		return '<div class="zt-header">' +
			'<div class="zt-brand">' +
			'<div class="zt-brand-icon">' + icon('radar') + '</div>' +
			'<div><h1>Ziyaretçi Takibi</h1><p>' + esc(subtitle) + '</p></div>' +
			'</div>' + (rightHtml || '') + bellHtml() + '</div>';
	}

	function bellHtml() {
		return '<div class="zt-bell-wrap">' +
			'<button type="button" class="zt-bell" id="zt-bell" title="Uyarılar" aria-label="Uyarılar">' + icon('bell') +
			'<span class="zt-bell-badge" id="zt-bell-badge" style="display:none">0</span></button>' +
			'<div class="zt-bell-panel" id="zt-bell-panel">' +
			'<div class="zt-bell-head"><b>' + icon('bell') + ' Uyarılar</b>' +
			'<button type="button" class="zt-bell-clear" id="zt-alerts-clear">Temizle</button></div>' +
			'<div class="zt-bell-list"></div>' +
			'<a class="zt-bell-foot" href="' + esc(BOOT.pages.settings) + '">Uyarı kurallarını yönet →</a>' +
			'</div></div>';
	}

	function navChips(active) {
		var items = [
			['dashboard', 'Genel Bakış'],
			['live', 'Canlı'],
			['visits', 'Ziyaretler'],
			['visitors', 'Ziyaretçiler'],
			['reports', 'Raporlar'],
			['funnel', 'Huniler'],
			['heatmap', 'Isı Haritası'],
			['cohort', 'Kohort & A/B'],
			['settings', 'Ayarlar']
		];
		return '<div class="zt-chips">' + items.map(function (it) {
			return '<a class="zt-chip ' + (active === it[0] ? 'is-active' : '') + '" href="' + esc(BOOT.pages[it[0]]) + '">' + esc(it[1]) + '</a>';
		}).join('') + '</div>';
	}

	function emptyHtml(msg) {
		return '<div class="zt-empty">' + icon('radar') + '<div>' + esc(msg || 'Henüz veri yok') + '</div></div>';
	}

	function geoCell(row) {
		var cc = row.country_code || '';
		var city = row.city || '';
		var country = row.country || '';
		if (!cc && !country) {
			return '<span class="zt-flag">🌐</span>';
		}
		var tip = (city ? city + ', ' : '') + country;
		return '<span class="zt-flag" title="' + esc(tip) + '">' + flag(cc) + '</span>';
	}

	function botBadge(row) {
		if (Number(row.is_bot) !== 1) { return ''; }
		return '<span class="zt-badge bot">' + icon('bot') + esc(row.bot_name || 'Bot') + '</span>';
	}

	/* ═══════════════════ PROFİL ÇEKMECESİ ═══════════════════ */

	var drawerEls = null;

	function ensureDrawer() {
		if (drawerEls) { return drawerEls; }
		var overlay = document.createElement('div');
		overlay.className = 'zt-drawer-overlay';
		overlay.style.display = 'none';
		var drawer = document.createElement('div');
		drawer.className = 'zt-drawer';
		document.body.appendChild(overlay);
		document.body.appendChild(drawer);

		overlay.addEventListener('click', closeDrawer);
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') { closeDrawer(); }
		});

		drawerEls = { overlay: overlay, drawer: drawer };
		return drawerEls;
	}

	function closeDrawer() {
		if (!drawerEls) { return; }
		drawerEls.drawer.classList.remove('is-open');
		drawerEls.overlay.classList.remove('is-open');
		setTimeout(function () { drawerEls.overlay.style.display = 'none'; }, 260);
	}

	function adminBarHeight() {
		var bar = document.getElementById('wpadminbar');
		return bar ? Math.round(bar.getBoundingClientRect().height) : 0;
	}

	function openProfile(ip) {
		var els = ensureDrawer();
		// WP admin bar'ın altından başlasın (üstü kesilmesin, boyu da buna göre kısalsın).
		var barH = adminBarHeight();
		els.overlay.style.top = barH + 'px';
		els.drawer.style.top = barH + 'px';
		els.overlay.style.display = 'block';
		els.overlay.getBoundingClientRect(); // geçiş animasyonu için reflow
		setTimeout(function () {
			els.overlay.classList.add('is-open');
			els.drawer.classList.add('is-open');
		}, 15);

		els.drawer.innerHTML =
			'<div class="zt-drawer-head"><div style="flex:1">' +
			'<div class="zt-skel" style="height:26px;width:190px"></div>' +
			'<div class="zt-skel" style="height:14px;width:130px;margin-top:9px"></div>' +
			'</div></div>' +
			'<div class="zt-drawer-body"><div class="zt-skel" style="height:74px"></div>' +
			'<div class="zt-skel" style="height:190px;margin-top:14px"></div></div>';

		api('profile', { ip: ip }).then(function (p) {
			renderProfile(p);
		}).catch(function (err) {
			els.drawer.innerHTML =
				'<div class="zt-drawer-head"><h2>' + esc(ip) + '</h2>' +
				'<button type="button" class="zt-drawer-close">' + icon('x') + '</button></div>' +
				'<div class="zt-drawer-body">' + emptyHtml(err.message) + '</div>';
			els.drawer.querySelector('.zt-drawer-close').addEventListener('click', closeDrawer);
		});
	}

	function renderProfile(p) {
		var els = ensureDrawer();
		var g = p.geo || {};
		var st = p.stats || {};

		var locParts = [];
		if (g.city) { locParts.push(g.city); }
		if (g.region && g.region !== g.city) { locParts.push(g.region); }
		if (g.country) { locParts.push(g.country); }
		var loc = locParts.join(', ');
		if (!loc) { loc = g.status === 'pending' ? 'Konum çözümleniyor…' : 'Konum bilinmiyor'; }

		var badges = '';
		if (p.blocked) { badges += '<span class="zt-badge proxy">' + icon('ban') + 'Engelli</span>'; }
		if (Number(st.has_bot) === 1) { badges += '<span class="zt-badge bot">' + icon('bot') + 'Bot trafiği</span>'; }
		if (Number(g.is_proxy) === 1) { badges += '<span class="zt-badge proxy">' + icon('shield') + 'Proxy/VPN</span>'; }
		if (Number(g.is_hosting) === 1) { badges += '<span class="zt-badge hosting">Veri Merkezi</span>'; }
		if (g.status === 'pending') { badges += '<span class="zt-badge geo-wait">' + icon('clock') + 'Konum bekliyor</span>'; }

		var kv = '';
		if (g.isp) { kv += '<div class="row"><span class="k">İnternet Sağlayıcı</span><span class="v">' + esc(g.isp) + '</span></div>'; }
		if (g.org && g.org !== g.isp) { kv += '<div class="row"><span class="k">Kuruluş</span><span class="v">' + esc(g.org) + '</span></div>'; }
		if (g.timezone) { kv += '<div class="row"><span class="k">Zaman Dilimi</span><span class="v">' + esc(g.timezone) + '</span></div>'; }
		kv += '<div class="row"><span class="k">İlk Görülme</span><span class="v">' + esc(fullTime(st.first_seen)) + '</span></div>';
		kv += '<div class="row"><span class="k">Son Görülme</span><span class="v">' + esc(fullTime(st.last_seen)) + ' <span style="color:#aab4c8">(' + esc(relTime(st.last_seen)) + ')</span></span></div>';

		var chips = function (items, labelFn) {
			if (!items || !items.length) { return '<span style="color:#aab4c8">—</span>'; }
			return items.map(function (it) {
				return '<span class="zt-badge type">' + labelFn(it) + ' • ' + n(it.n) + '</span>';
			}).join(' ');
		};

		var pagesHtml = '';
		(p.top_pages || []).forEach(function (tp) {
			var maxV = p.top_pages[0] ? Number(p.top_pages[0].views) : 1;
			pagesHtml +=
				'<div class="zt-bar-row"><div class="zt-bar-label" title="' + esc(tp.url) + '">' +
				'<span class="txt">' + esc(tp.title || urlPath(tp.url)) + '</span></div>' +
				'<span class="zt-bar-val">' + n(tp.views) + '</span>' +
				'<div class="zt-bar-track"><div class="zt-bar-fill" style="width:' + Math.round(tp.views / maxV * 100) + '%"></div></div></div>';
		});
		if (!pagesHtml) { pagesHtml = '<span style="color:#aab4c8">—</span>'; }

		var refHtml = '';
		(p.referrers || []).forEach(function (r) {
			refHtml += '<span class="zt-badge type">' + icon('link') + esc(r.k) + ' • ' + n(r.n) + '</span> ';
		});
		if (!refHtml) { refHtml = '<span style="color:#aab4c8">Hep doğrudan giriş</span>'; }

		var sessionsHtml = '';
		(p.sessions || []).forEach(function (s) {
			var dm = deviceMeta(s.device);
			var pagesRows = '';
			s.pages.forEach(function (pg) {
				var typeLabel = PAGE_TYPES[pg.page_type] || '';
				pagesRows +=
					'<div class="zt-sp"><span class="t">' + esc(clockOf(pg.time)) + '</span><span class="dot"></span>' +
					'<div class="body"><div class="ttl">' + esc(pg.title || urlPath(pg.url)) + '</div>' +
					'<div class="url">' + esc(urlPath(pg.url)) + (typeLabel ? ' • ' + typeLabel : '') + '</div></div></div>';
			});
			sessionsHtml +=
				'<div class="zt-session"><div class="zt-session-head">' +
				'<b>' + esc(dayTitle(s.start)) + '</b>' +
				'<span class="meta">' + icon('clock') + esc(clockOf(s.start)) + (s.page_count > 1 ? '–' + esc(clockOf(s.end)) : '') + '</span>' +
				'<span class="meta">' + esc(duration(s.duration)) + '</span>' +
				'<span class="meta">' + icon(dm.icon) + esc(dm.label) + (s.browser ? ' • ' + esc(s.browser) : '') + '</span>' +
				'<span class="meta" style="margin-left:auto">' + n(s.page_count) + ' sayfa</span>' +
				'</div><div class="zt-session-pages">' + pagesRows + '</div></div>';
		});
		if (!sessionsHtml) { sessionsHtml = emptyHtml('Oturum kaydı yok'); }

		els.drawer.innerHTML =
			'<div class="zt-drawer-head">' +
			'<span class="zt-flag">' + flag(g.country_code) + '</span>' +
			'<div style="flex:1;min-width:0">' +
			'<h2>' + esc(p.ip) + '</h2>' +
			'<div class="loc">' + icon('pin', 12) + ' ' + esc(loc) + '</div>' +
			'<div class="badges">' + badges + '</div>' +
			'</div>' +
			'<button type="button" class="zt-drawer-close">' + icon('x') + '</button>' +
			'</div>' +
			'<div class="zt-drawer-body">' +
			'<div class="zt-profile-stats">' +
			'<div class="zt-pstat"><b>' + n(st.visits) + '</b><span>Toplam Görüntüleme</span></div>' +
			'<div class="zt-pstat"><b>' + n(st.pages) + '</b><span>Farklı Sayfa</span></div>' +
			'<div class="zt-pstat"><b>' + n(st.days) + '</b><span>Aktif Gün</span></div>' +
			'</div>' +
			'<div class="zt-prof-section"><div class="zt-kv">' + kv + '</div></div>' +
			'<div class="zt-prof-section" style="display:flex;gap:8px;flex-wrap:wrap">' +
			'<a class="zt-btn" href="' + esc(BOOT.pages.visits) + '&ip=' + encodeURIComponent(p.ip) + '">' + icon('filter') + 'Ziyaretlerde Filtrele</a>' +
			'<button type="button" class="zt-btn" id="zt-prof-block">' + (p.blocked ? icon('check') + 'Engeli Kaldır' : icon('ban') + 'IP\'yi Engelle') + '</button>' +
			'<button type="button" class="zt-btn zt-btn-danger" data-zt-del-ip="' + esc(p.ip) + '">' + icon('trash') + 'Bu IP\'nin Verilerini Sil</button>' +
			'</div>' +
			'<div class="zt-prof-section"><h3>Cihazlar</h3><div>' + chips(p.devices, function (d) { return esc(deviceMeta(d.k).label); }) + '</div></div>' +
			'<div class="zt-prof-section"><h3>Tarayıcılar</h3><div>' + chips(p.browsers, function (b) { return esc(b.k); }) + '</div></div>' +
			'<div class="zt-prof-section"><h3>En Çok Görüntülenen Sayfalar</h3><div class="zt-bars">' + pagesHtml + '</div></div>' +
			'<div class="zt-prof-section"><h3>Geliş Kaynakları</h3><div>' + refHtml + '</div></div>' +
			'<div class="zt-prof-section"><h3>Oturumlar / Davranış Akışı</h3>' + sessionsHtml + '</div>' +
			'</div>';

		els.drawer.querySelector('.zt-drawer-close').addEventListener('click', closeDrawer);

		var delBtn = els.drawer.querySelector('[data-zt-del-ip]');
		if (delBtn) {
			delBtn.addEventListener('click', function () {
				confirmModal({
					title: 'IP verilerini sil',
					text: p.ip + ' adresine ait tüm ziyaret kayıtları kalıcı olarak silinecek. Bu işlem geri alınamaz.',
					okLabel: 'Evet, Sil',
					danger: true
				}).then(function (ok) {
					if (!ok) { return; }
					api('delete_ip', { ip: p.ip }).then(function (res) {
						toast(res.message || 'Silindi');
						closeDrawer();
						if (typeof window.__ztReload === 'function') { window.__ztReload(); }
					}).catch(function (err) { toast(err.message, 'err'); });
				});
			});
		}

		var blockBtn = els.drawer.querySelector('#zt-prof-block');
		if (blockBtn) {
			blockBtn.addEventListener('click', function () {
				var action = p.blocked ? 'unblock_ip' : 'block_ip';
				blockBtn.disabled = true;
				api(action, { ip: p.ip }).then(function (res) {
					p.blocked = res.blocked;
					toast(res.message || 'Güncellendi');
					renderProfile(p);
				}).catch(function (err) {
					blockBtn.disabled = false;
					toast(err.message, 'err');
				});
			});
		}
	}

	/* ═══════════════════ GENEL BAKIŞ ═══════════════════ */

	function initDashboard() {
		var state = { period: '7d', timer: null, firstLoad: true };

		app.innerHTML =
			headerHtml('Genel Bakış — sitenizin nabzı', navChips('dashboard') +
				'<div class="zt-segment" id="zt-period">' +
				'<button type="button" data-p="today">Bugün</button>' +
				'<button type="button" data-p="7d" class="is-active">7 Gün</button>' +
				'<button type="button" data-p="30d">30 Gün</button>' +
				'</div>' +
				'<button type="button" class="zt-btn" id="zt-pdf-btn">' + icon('file') + 'PDF</button>' +
				'<span class="zt-live-badge" id="zt-live" style="display:none"><span class="zt-live-dot"></span><span id="zt-live-num">0</span> aktif</span>') +
			'<div id="zt-dash-body">' + skeletonDash() + '</div>';

		document.getElementById('zt-period').addEventListener('click', function (ev) {
			var btn = ev.target.closest('button[data-p]');
			if (!btn) { return; }
			this.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-active'); });
			btn.classList.add('is-active');
			state.period = btn.getAttribute('data-p');
			state.firstLoad = true;
			document.getElementById('zt-dash-body').innerHTML = skeletonDash();
			load();
		});

		document.getElementById('zt-pdf-btn').addEventListener('click', function () {
			window.open(BOOT.pdfUrl + '&period=' + encodeURIComponent(state.period), '_blank');
		});

		function skeletonDash() {
			var html = '<div class="zt-stats">';
			for (var i = 0; i < 4; i++) { html += '<div class="zt-skel" style="height:118px;border-radius:16px"></div>'; }
			html += '</div><div class="zt-grid zt-grid-main">' +
				'<div class="zt-skel" style="height:330px;border-radius:16px"></div>' +
				'<div class="zt-skel" style="height:330px;border-radius:16px"></div></div>' +
				'<div class="zt-grid zt-grid-3">' +
				'<div class="zt-skel" style="height:250px;border-radius:16px"></div>' +
				'<div class="zt-skel" style="height:250px;border-radius:16px"></div>' +
				'<div class="zt-skel" style="height:250px;border-radius:16px"></div></div>';
			return html;
		}

		function statCard(iconName, glow, label, value, deltaHtml, id) {
			return '<div class="zt-card zt-stat" style="--zt-glow:' + glow + '">' +
				'<div class="zt-stat-head"><span class="zt-stat-ico" style="background:' + glow.replace('0.1', '0.16') + ';color:' + glowColor(glow) + '">' + icon(iconName) + '</span>' + esc(label) + '</div>' +
				'<div class="zt-stat-num" id="' + id + '">0</div>' +
				'<div class="zt-stat-foot">' + deltaHtml + '</div></div>';
		}

		function glowColor(glow) {
			if (glow.indexOf('34, 211, 238') > -1) { return '#22d3ee'; }
			if (glow.indexOf('167, 139, 250') > -1) { return '#a78bfa'; }
			if (glow.indexOf('52, 211, 153') > -1) { return '#34d399'; }
			return '#fbbf24';
		}

		function deltaHtml(cur, prev) {
			if (prev <= 0 && cur <= 0) { return '<span class="zt-delta flat">—</span><span>önceki dönem</span>'; }
			if (prev <= 0) { return '<span class="zt-delta up">↑ yeni</span><span>önceki dönemde veri yok</span>'; }
			var pct = Math.round((cur - prev) / prev * 100);
			if (pct > 0) { return '<span class="zt-delta up">↑ %' + n(pct) + '</span><span>önceki döneme göre</span>'; }
			if (pct < 0) { return '<span class="zt-delta down">↓ %' + n(Math.abs(pct)) + '</span><span>önceki döneme göre</span>'; }
			return '<span class="zt-delta flat">%0</span><span>değişim yok</span>';
		}

		function barList(rows, opts) {
			if (!rows || !rows.length) { return emptyHtml('Bu aralıkta veri yok'); }
			var maxV = Math.max.apply(null, rows.map(function (r) { return Number(r[opts.valueKey]); }));
			var html = '<div class="zt-bars">';
			rows.forEach(function (r) {
				var val = Number(r[opts.valueKey]);
				html += '<div class="zt-bar-row"><div class="zt-bar-label"' + (opts.titleFn ? ' title="' + esc(opts.titleFn(r)) + '"' : '') + '>' +
					(opts.prefixFn ? opts.prefixFn(r) : '') +
					'<span class="txt">' + esc(opts.labelFn(r)) + '</span>' +
					(opts.subFn ? '<span class="sub">' + esc(opts.subFn(r)) + '</span>' : '') +
					'</div><span class="zt-bar-val">' + n(val) + (opts.extraFn ? '<small>' + esc(opts.extraFn(r)) + '</small>' : '') + '</span>' +
					'<div class="zt-bar-track"><div class="zt-bar-fill' + (opts.alt ? ' alt' : '') + '" data-w="' + Math.max(3, Math.round(val / maxV * 100)) + '"></div></div></div>';
			});
			html += '</div>';
			return html;
		}

		function load() {
			api('dashboard', { period: state.period }).then(function (d) {
				render(d);
			}).catch(function (err) {
				document.getElementById('zt-dash-body').innerHTML = '<div class="zt-card">' + emptyHtml('Veri yüklenemedi: ' + err.message) + '</div>';
			});
		}

		function render(d) {
			var body = document.getElementById('zt-dash-body');
			if (!body) { return; }
			var t = d.totals;

			var live = document.getElementById('zt-live');
			if (live) {
				live.style.display = 'inline-flex';
				document.getElementById('zt-live-num').textContent = n(t.active_now);
			}

			var geoNote = '';
			if (BOOT.geoEnabled && d.geo_pending > 0) {
				geoNote = '<span class="zt-badge geo-wait" style="margin-left:8px">' + icon('pin') + n(d.geo_pending) + ' IP konum bekliyor</span>';
			}

			body.innerHTML =
				'<div class="zt-stats zt-stagger">' +
				statCard('users', 'rgba(34, 211, 238, 0.1)', 'Tekil Ziyaretçi', t.visitors, deltaHtml(t.visitors, t.visitors_prev), 'zt-n-visitors') +
				statCard('eye', 'rgba(167, 139, 250, 0.1)', 'Sayfa Görüntüleme', t.views, deltaHtml(t.views, t.views_prev), 'zt-n-views') +
				statCard('activity', 'rgba(52, 211, 153, 0.1)', 'Şu An Aktif', t.active_now, '<span>son 5 dakika</span>', 'zt-n-active') +
				statCard('bot', 'rgba(251, 191, 36, 0.1)', 'Bot Trafiği', t.bots, '<span>bu dönem yakalanan</span>', 'zt-n-bots') +
				'</div>' +
				'<div class="zt-grid zt-grid-main zt-stagger">' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('chart') + 'Trafik Eğilimi' +
				'<span class="zt-title-hint">' + (state.period === 'today' ? 'saatlik' : 'günlük') + geoNote + '</span></div>' +
				'<div id="zt-main-chart"></div>' +
				'<div class="zt-legend">' +
				'<span class="zt-legend-item"><span class="zt-legend-swatch" style="background:#22d3ee"></span>Görüntüleme</span>' +
				'<span class="zt-legend-item"><span class="zt-legend-swatch" style="background:#a78bfa"></span>Tekil Ziyaretçi</span>' +
				'</div></div>' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('monitor') + 'Cihaz Dağılımı</div><div id="zt-device-chart" style="padding-top:8px"></div></div>' +
				'</div>' +
				'<div class="zt-card zt-stagger" style="margin-bottom:14px"><div class="zt-card-title">' + icon('globe') + 'Ziyaretçi Haritası' +
				'<span class="zt-title-hint">ülkeye tıklayarak ziyaretleri filtreleyin</span></div><div id="zt-map"></div></div>' +
				'<div class="zt-grid zt-grid-3 zt-stagger">' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('globe') + 'Ülkeler</div><div id="zt-countries"></div></div>' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('file') + 'En Çok Görüntülenen Sayfalar</div><div id="zt-pages"></div></div>' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('link') + 'Geliş Kaynakları</div><div id="zt-refs"></div></div>' +
				'</div>' +
				'<div class="zt-grid zt-grid-3 zt-stagger">' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('globe') + 'Tarayıcılar</div><div id="zt-browsers"></div></div>' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('monitor') + 'İşletim Sistemleri</div><div id="zt-oses"></div></div>' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('bot') + 'Botlar</div><div id="zt-bots"></div></div>' +
				'</div>' +
				'<div class="zt-card zt-stagger" style="margin-bottom:14px"><div class="zt-card-title">' + icon('megaphone') + 'Kampanyalar (UTM)' +
				'<span class="zt-title-hint">utm_campaign etiketli bağlantılardan gelen trafik</span></div><div id="zt-campaigns"></div></div>' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('activity') + 'Son Ziyaretler' +
				'<span class="zt-title-hint"><a href="' + esc(BOOT.pages.visits) + '">Tümünü gör →</a></span></div>' +
				'<div id="zt-recent"></div></div>';

			// Sayaçlar.
			if (state.firstLoad) {
				animateNum(document.getElementById('zt-n-visitors'), t.visitors);
				animateNum(document.getElementById('zt-n-views'), t.views);
				animateNum(document.getElementById('zt-n-active'), t.active_now);
				animateNum(document.getElementById('zt-n-bots'), t.bots);
			} else {
				document.getElementById('zt-n-visitors').textContent = n(t.visitors);
				document.getElementById('zt-n-views').textContent = n(t.views);
				document.getElementById('zt-n-active').textContent = n(t.active_now);
				document.getElementById('zt-n-bots').textContent = n(t.bots);
			}

			// Ana grafik: bugün görünümünde gelecekteki saatleri çizme.
			var series = d.series || [];
			if (state.period === 'today') {
				series = series.filter(function (s) { return !s.future; });
			}
			ZTCharts.area(document.getElementById('zt-main-chart'), {
				labels: series.map(function (s) { return s.label; }),
				series: [
					{ name: 'Görüntüleme', color: '#22d3ee', data: series.map(function (s) { return s.views; }) },
					{ name: 'Tekil Ziyaretçi', color: '#a78bfa', data: series.map(function (s) { return s.visitors; }) }
				],
				height: 265,
				animate: state.firstLoad
			});

			// Cihaz halkası.
			var deviceItems = (d.devices || []).map(function (dv) {
				var meta = deviceMeta(dv.k);
				return { label: meta.label, value: Number(dv.n), color: meta.color };
			});
			ZTCharts.donut(document.getElementById('zt-device-chart'), {
				items: deviceItems,
				centerLabel: 'görüntüleme',
				animate: state.firstLoad
			});

			// Ziyaretçi haritası.
			if (ZTCharts.worldMap) {
				ZTCharts.worldMap(document.getElementById('zt-map'), {
					data: d.map || d.countries || [],
					onSelect: function (cc, row) {
						if (row && Number(row.visitors) > 0) {
							window.location.href = BOOT.pages.visits + '&country=' + encodeURIComponent(cc);
						} else {
							toast('Bu ülkeden kayıtlı ziyaret yok', 'err');
						}
					}
				});
			}

			// Listeler.
			document.getElementById('zt-countries').innerHTML = barList(d.countries, {
				valueKey: 'visitors',
				labelFn: function (r) { return r.name || 'Bilinmiyor'; },
				prefixFn: function (r) { return '<span class="zt-flag">' + flag(r.code) + '</span>'; },
				extraFn: function (r) { return n(r.views) + ' gör.'; }
			});
			document.getElementById('zt-pages').innerHTML = barList(d.top_pages, {
				valueKey: 'views',
				labelFn: function (r) { return r.title || urlPath(r.url); },
				titleFn: function (r) { return r.url; },
				alt: true
			});
			document.getElementById('zt-refs').innerHTML = barList(d.referrers, {
				valueKey: 'n',
				labelFn: function (r) { return r.k; },
				extraFn: function (r) { return n(r.visitors) + ' kişi'; }
			});
			document.getElementById('zt-browsers').innerHTML = barList(d.browsers, {
				valueKey: 'n',
				labelFn: function (r) { return r.k || 'Bilinmiyor'; }
			});
			document.getElementById('zt-oses').innerHTML = barList(d.oses, {
				valueKey: 'n',
				labelFn: function (r) { return r.k || 'Bilinmiyor'; },
				alt: true
			});
			document.getElementById('zt-bots').innerHTML = barList(d.top_bots, {
				valueKey: 'n',
				labelFn: function (r) { return r.k || 'Bilinmeyen bot'; }
			});
			var campEl = document.getElementById('zt-campaigns');
			if (!d.campaigns || !d.campaigns.length) {
				campEl.innerHTML = '<div class="zt-empty">' + icon('megaphone') +
					'<div>Bu aralıkta kampanya verisi yok.<br><span style="color:#7e89a0">Bağlantılarınıza <code style="color:#67e8f9">?utm_source=...&utm_campaign=...</code> ekleyerek kampanyaları ölçün.</span></div></div>';
			} else {
				campEl.innerHTML = barList(d.campaigns, {
					valueKey: 'n',
					labelFn: function (r) { return r.campaign || '(adsız)'; },
					subFn: function (r) { return [r.source, r.medium].filter(Boolean).join(' / '); },
					extraFn: function (r) { return n(r.visitors) + ' kişi'; },
					alt: true
				});
			}

			// Çubuk animasyonları (arka plan sekmesinde de çalışsın diye setTimeout).
			setTimeout(function () {
				body.querySelectorAll('.zt-bar-fill[data-w]').forEach(function (el) {
					el.style.width = el.getAttribute('data-w') + '%';
				});
			}, 30);

			// Son ziyaretler.
			var recentHtml;
			if (!d.recent || !d.recent.length) {
				recentHtml = emptyHtml('Henüz ziyaret kaydı yok. Siteniz ziyaret edildikçe burada görünecek.');
			} else {
				recentHtml = '<div class="zt-table-scroll"><table class="zt-table"><thead><tr>' +
					'<th>Zaman</th><th>Ziyaretçi</th><th>Sayfa</th><th>Cihaz</th><th>Tarayıcı</th><th></th>' +
					'</tr></thead><tbody>';
				d.recent.forEach(function (r) {
					var dm = deviceMeta(r.device);
					recentHtml += '<tr>' +
						'<td class="zt-time" title="' + esc(fullTime(r.visited_at)) + '">' + esc(relTime(r.visited_at)) + '</td>' +
						'<td><span class="zt-ip" data-ip="' + esc(r.ip) + '">' + geoCell(r) + esc(r.ip || '—') + '</span>' +
						(r.city ? '<div class="zt-cell-sub">' + esc(r.city) + (r.country ? ', ' + esc(r.country) : '') + '</div>' : '') + '</td>' +
						'<td><div class="zt-cell-main" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.url) + '">' + esc(r.page_title || urlPath(r.url)) + '</div></td>' +
						'<td><span class="zt-device">' + icon(dm.icon) + esc(dm.label) + '</span></td>' +
						'<td>' + esc(r.browser || '—') + '</td>' +
						'<td>' + botBadge(r) + '</td>' +
						'</tr>';
				});
				recentHtml += '</tbody></table></div>';
			}
			document.getElementById('zt-recent').innerHTML = recentHtml;

			bindIpClicks(body);
			state.firstLoad = false;
		}

		window.__ztReload = function () { load(); };
		load();

		// Canlı yenileme (sekme görünürken 30 sn'de bir).
		state.timer = setInterval(function () {
			if (document.visibilityState === 'visible') { load(); }
		}, 30000);
	}

	function bindIpClicks(scope) {
		scope.querySelectorAll('.zt-ip[data-ip]').forEach(function (el) {
			el.addEventListener('click', function () {
				var ip = el.getAttribute('data-ip');
				if (ip && ip !== '—') { openProfile(ip); }
			});
		});
	}

	/* ═══════════════════ CANLI ═══════════════════ */

	function sparklineBars(values) {
		values = values || [];
		var max = Math.max.apply(null, values.concat([1]));
		return '<div class="zt-spark">' + values.map(function (v, i) {
			var h = Math.max(4, Math.round((v / max) * 100));
			return '<span class="zt-spark-bar" title="' + n(v) + '" style="height:' + h + '%;animation-delay:' + (i * 12) + 'ms"></span>';
		}).join('') + '</div>';
	}

	function initLive() {
		var state = { paused: false, timer: null, firstLoad: true, lastMaxId: 0, loading: false, map: null };

		app.innerHTML =
			headerHtml('Canlı — şu anda sitende olan biten', navChips('live') +
				'<button type="button" class="zt-btn" id="zt-live-toggle">' + icon('pause') + '<span>Duraklat</span></button>') +
			'<div class="zt-live-hero zt-card">' +
			'<div class="zt-live-hero-main">' +
			'<div class="zt-live-pulse"><span class="ring"></span><span class="ring d2"></span>' +
			'<div class="core"><b id="zt-lv-count">0</b><span>aktif ziyaretçi</span></div></div>' +
			'</div>' +
			'<div class="zt-live-hero-side">' +
			'<div class="zt-live-mini"><span class="k">' + icon('eye') + 'Son 5 dakika</span><b id="zt-lv-5m">0</b></div>' +
			'<div class="zt-live-mini"><span class="k">' + icon('clock') + 'Son 1 saat</span><b id="zt-lv-hour">0</b></div>' +
			'<div class="zt-live-mini"><span class="k">' + icon('activity') + 'Bugün</span><b id="zt-lv-today">0</b></div>' +
			'<div class="zt-live-spark-wrap"><span class="lbl">Son 30 dakika</span><div id="zt-lv-spark"></div></div>' +
			'</div>' +
			'</div>' +
			'<div class="zt-card zt-live-map-card" style="margin-top:14px"><div class="zt-card-title">' + icon('globe') + 'Canlı Ziyaretçi Haritası' +
			'<span class="zt-title-hint">gelen ziyaretler anlık olarak belirir</span></div><div id="zt-live-map"></div></div>' +
			'<div class="zt-grid zt-grid-main" style="margin-top:14px">' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('wifi') + 'Canlı Akış' +
			'<span class="zt-title-hint" id="zt-live-status">bağlanıyor…</span></div>' +
			'<div id="zt-live-feed" class="zt-feed"></div></div>' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('file') + 'Şu An Aktif Sayfalar</div>' +
			'<div id="zt-live-pages"></div></div>' +
			'</div>';

		var toggleBtn = document.getElementById('zt-live-toggle');
		toggleBtn.addEventListener('click', function () {
			state.paused = !state.paused;
			if (state.paused) {
				if (state.timer) { clearInterval(state.timer); state.timer = null; }
				toggleBtn.innerHTML = icon('play') + '<span>Sürdür</span>';
				document.getElementById('zt-live-status').textContent = 'duraklatıldı';
			} else {
				toggleBtn.innerHTML = icon('pause') + '<span>Duraklat</span>';
				load();
				start();
			}
		});

		function render(d) {
			document.getElementById('zt-lv-count').textContent = n(d.active_visitors);
			document.getElementById('zt-lv-5m').textContent = n(d.views_5m);
			document.getElementById('zt-lv-hour').textContent = n(d.views_hour);
			document.getElementById('zt-lv-today').textContent = n(d.views_today);
			document.getElementById('zt-lv-spark').innerHTML = sparklineBars(d.spark);

			var status = document.getElementById('zt-live-status');
			if (status) { status.innerHTML = '<span class="zt-live-dot" style="display:inline-block;width:7px;height:7px"></span> canlı • ' + esc(clockOf(d.server_time)); }

			// Aktif sayfalar.
			var pagesEl = document.getElementById('zt-live-pages');
			if (!d.active_pages.length) {
				pagesEl.innerHTML = emptyHtml('Şu anda aktif sayfa yok');
			} else {
				var maxV = Number(d.active_pages[0].visitors) || 1;
				pagesEl.innerHTML = '<div class="zt-bars">' + d.active_pages.map(function (p) {
					return '<div class="zt-bar-row"><div class="zt-bar-label" title="' + esc(p.url) + '">' +
						'<span class="txt">' + esc(p.title || urlPath(p.url)) + '</span></div>' +
						'<span class="zt-bar-val">' + n(p.visitors) + '<small>kişi</small></span>' +
						'<div class="zt-bar-track"><div class="zt-bar-fill" style="width:' + Math.round(p.visitors / maxV * 100) + '%"></div></div></div>';
				}).join('') + '</div>';
			}

			renderFeed(d.feed);
		}

		function renderFeed(feed) {
			var holder = document.getElementById('zt-live-feed');
			if (!feed.length) {
				holder.innerHTML = emptyHtml('Henüz ziyaret kaydı yok');
				return;
			}
			if (!state.map && window.ZTCharts && ZTCharts.baseMap) {
				state.map = ZTCharts.baseMap(document.getElementById('zt-live-map'));
			}
			var wasFirst = state.firstLoad;
			var prevMax = state.lastMaxId;
			var maxId = state.lastMaxId;
			var html = feed.map(function (r) {
				var isNew = !state.firstLoad && Number(r.id) > state.lastMaxId;
				if (Number(r.id) > maxId) { maxId = Number(r.id); }
				var dm = deviceMeta(r.device);
				var loc = (r.city || r.country) ? (esc(r.city || '') + (r.city && r.country ? ', ' : '') + esc(r.country || '')) : 'Konum bekleniyor';
				var src = r.referrer_domain ? esc(r.referrer_domain) : 'doğrudan';
				return '<div class="zt-feed-item' + (isNew ? ' is-new' : '') + '" data-ip="' + esc(r.ip) + '">' +
					'<span class="zt-feed-flag">' + flag(r.country_code) + '</span>' +
					'<div class="zt-feed-body">' +
					'<div class="zt-feed-top"><span class="zt-feed-ip">' + esc(r.ip || '—') + '</span>' +
					botBadge(r) +
					'<span class="zt-feed-time">' + esc(relTime(r.visited_at)) + '</span></div>' +
					'<div class="zt-feed-page" title="' + esc(r.url) + '">' + esc(r.page_title || urlPath(r.url)) + '</div>' +
					'<div class="zt-feed-meta">' + icon(dm.icon) + esc(dm.label) +
					' • ' + esc(r.browser || '—') + ' • ' + esc(loc) + ' • ' + src + '</div>' +
					'</div></div>';
			}).join('');
			holder.innerHTML = html;
			state.lastMaxId = maxId;
			state.firstLoad = false;

			if (state.map) {
				var toPing = feed.filter(function (r) { return wasFirst ? true : Number(r.id) > prevMax; });
				if (wasFirst) { toPing = toPing.slice(0, 10); }
				toPing.reverse().forEach(function (r, idx) {
					setTimeout(function () { state.map.ping(r.lat, r.lon, r.country_code, Number(r.is_bot) === 1); }, idx * 260);
				});
			}

			holder.querySelectorAll('.zt-feed-item[data-ip]').forEach(function (el) {
				el.addEventListener('click', function () {
					var ip = el.getAttribute('data-ip');
					if (ip && ip !== '—') { openProfile(ip); }
				});
			});
		}

		function load() {
			if (state.loading) { return; }
			state.loading = true;
			api('live').then(function (d) {
				state.loading = false;
				render(d);
			}).catch(function (err) {
				state.loading = false;
				var status = document.getElementById('zt-live-status');
				if (status) { status.textContent = 'bağlantı hatası'; }
			});
		}

		function start() {
			if (state.timer) { clearInterval(state.timer); }
			state.timer = setInterval(function () {
				if (document.visibilityState === 'visible' && !state.paused) { load(); }
			}, 8000);
		}

		window.__ztReload = function () { load(); };
		load();
		start();
	}

	/* ═══════════════════ ZİYARETLER ═══════════════════ */

	function initVisits() {
		var state = {
			filters: {
				from: '', to: '', ip: BOOT.presetIp || '', country: BOOT.presetCountry || '', device: '',
				browser: '', os: '', bots: 'include', search: '', page_type: '', campaign: ''
			},
			paged: 1, per_page: 50, order: 'DESC',
			live: false, timer: null, loading: false
		};

		function quickRange(days) {
			var now = new Date();
			function fmt(dd) {
				return dd.getFullYear() + '-' + String(dd.getMonth() + 1).padStart(2, '0') + '-' + String(dd.getDate()).padStart(2, '0');
			}
			if (days === 'today') { return { from: fmt(now), to: fmt(now) }; }
			if (days === 'yesterday') {
				var y = new Date(now.getTime() - 86400000);
				return { from: fmt(y), to: fmt(y) };
			}
			if (days === 'all') { return { from: '', to: '' }; }
			var s = new Date(now.getTime() - (days - 1) * 86400000);
			return { from: fmt(s), to: fmt(now) };
		}

		app.innerHTML =
			headerHtml('Ziyaretler — tüm kayıtlar, tüm filtreler', navChips('visits')) +
			'<div class="zt-card" style="margin-bottom:14px">' +
			'<div class="zt-toolbar" style="margin-bottom:12px">' +
			'<div class="zt-chips" id="zt-quick">' +
			'<button type="button" class="zt-chip" data-r="today">Bugün</button>' +
			'<button type="button" class="zt-chip" data-r="yesterday">Dün</button>' +
			'<button type="button" class="zt-chip" data-r="7">Son 7 Gün</button>' +
			'<button type="button" class="zt-chip" data-r="30">Son 30 Gün</button>' +
			'<button type="button" class="zt-chip is-active" data-r="all">Tümü</button>' +
			'</div>' +
			'<input type="date" class="zt-input" id="zt-f-from" title="Başlangıç">' +
			'<input type="date" class="zt-input" id="zt-f-to" title="Bitiş">' +
			'<span class="zt-spacer"></span>' +
			'<label class="zt-switch" title="10 sn\'de bir otomatik yenile"><input type="checkbox" id="zt-f-live"><span class="track"></span><span style="font-size:12px;color:#aab4c8;font-weight:600">Canlı</span></label>' +
			'<a class="zt-btn" id="zt-export" href="#">' + icon('download') + 'CSV İndir</a>' +
			'</div>' +
			'<div class="zt-toolbar">' +
			'<span class="zt-search-wrap">' + icon('search') + '<input type="text" class="zt-input" id="zt-f-search" placeholder="Sayfa veya başlık ara…"></span>' +
			'<input type="text" class="zt-input" id="zt-f-ip" placeholder="IP ara…" style="width:130px;font-family:var(--zt-mono)">' +
			'<select class="zt-select" id="zt-f-country"><option value="">Tüm Ülkeler</option></select>' +
			'<select class="zt-select" id="zt-f-device">' +
			'<option value="">Tüm Cihazlar</option><option value="desktop">Masaüstü</option>' +
			'<option value="mobile">Mobil</option><option value="tablet">Tablet</option>' +
			'</select>' +
			'<select class="zt-select" id="zt-f-browser"><option value="">Tüm Tarayıcılar</option></select>' +
			'<select class="zt-select" id="zt-f-os"><option value="">Tüm Sistemler</option></select>' +
			'<select class="zt-select" id="zt-f-ptype"><option value="">Tüm Sayfa Türleri</option>' +
			Object.keys(PAGE_TYPES).map(function (k) { return '<option value="' + k + '">' + PAGE_TYPES[k] + '</option>'; }).join('') +
			'</select>' +
			'<select class="zt-select" id="zt-f-bots">' +
			'<option value="include">İnsanlar + Botlar</option>' +
			'<option value="exclude">Sadece İnsanlar</option>' +
			'<option value="only">Sadece Botlar</option>' +
			'</select>' +
			'<select class="zt-select" id="zt-f-pp">' +
			'<option value="25">25 kayıt</option><option value="50" selected>50 kayıt</option><option value="100">100 kayıt</option>' +
			'</select>' +
			'<button type="button" class="zt-btn" id="zt-f-reset">' + icon('x') + 'Sıfırla</button>' +
			'</div>' +
			'</div>' +
			'<div class="zt-card" style="animation-delay:0.08s"><div id="zt-visits-table">' + tableSkel() + '</div><div id="zt-visits-pager"></div></div>';

		function tableSkel() {
			var rows = '';
			for (var i = 0; i < 8; i++) {
				rows += '<div class="zt-skel" style="height:40px;margin-bottom:8px"></div>';
			}
			return rows;
		}

		// Filtre seçeneklerini doldur.
		api('options', {}).then(function (opts) {
			var cSel = document.getElementById('zt-f-country');
			(opts.countries || []).forEach(function (c) {
				var o = document.createElement('option');
				o.value = c.code;
				o.textContent = flag(c.code) + ' ' + c.name + ' (' + n(c.n) + ')';
				cSel.appendChild(o);
			});
			var bSel = document.getElementById('zt-f-browser');
			(opts.browsers || []).forEach(function (b) {
				var o = document.createElement('option');
				o.value = b; o.textContent = b;
				bSel.appendChild(o);
			});
			var oSel = document.getElementById('zt-f-os');
			(opts.oses || []).forEach(function (osName) {
				var o = document.createElement('option');
				o.value = osName; o.textContent = osName;
				oSel.appendChild(o);
			});
			// Haritadan/derin bağlantıdan gelen ülke ön filtresini seçili göster.
			if (state.filters.country) { cSel.value = state.filters.country; }
		}).catch(function () { /* seçenekler kritik değil */ });

		if (BOOT.presetIp) {
			document.getElementById('zt-f-ip').value = BOOT.presetIp;
		}

		var loadDebounced = debounce(function () { state.paged = 1; load(); }, 300);

		function bindFilter(id, key, immediate) {
			var el = document.getElementById(id);
			el.addEventListener(immediate ? 'change' : 'input', function () {
				state.filters[key] = el.value;
				if (immediate) { state.paged = 1; load(); } else { loadDebounced(); }
			});
		}
		bindFilter('zt-f-search', 'search');
		bindFilter('zt-f-ip', 'ip');
		bindFilter('zt-f-country', 'country', true);
		bindFilter('zt-f-device', 'device', true);
		bindFilter('zt-f-browser', 'browser', true);
		bindFilter('zt-f-os', 'os', true);
		bindFilter('zt-f-ptype', 'page_type', true);
		bindFilter('zt-f-bots', 'bots', true);
		bindFilter('zt-f-from', 'from', true);
		bindFilter('zt-f-to', 'to', true);

		document.getElementById('zt-f-pp').addEventListener('change', function () {
			state.per_page = parseInt(this.value, 10) || 50;
			state.paged = 1;
			load();
		});

		document.getElementById('zt-quick').addEventListener('click', function (ev) {
			var chip = ev.target.closest('.zt-chip');
			if (!chip) { return; }
			this.querySelectorAll('.zt-chip').forEach(function (c) { c.classList.remove('is-active'); });
			chip.classList.add('is-active');
			var r = chip.getAttribute('data-r');
			var range = quickRange(r === 'today' || r === 'yesterday' || r === 'all' ? r : parseInt(r, 10));
			state.filters.from = range.from;
			state.filters.to = range.to;
			document.getElementById('zt-f-from').value = range.from;
			document.getElementById('zt-f-to').value = range.to;
			state.paged = 1;
			load();
		});

		document.getElementById('zt-f-reset').addEventListener('click', function () {
			state.filters = { from: '', to: '', ip: '', country: '', device: '', browser: '', os: '', bots: 'include', search: '', page_type: '' };
			state.paged = 1;
			['zt-f-from', 'zt-f-to', 'zt-f-search', 'zt-f-ip'].forEach(function (id) { document.getElementById(id).value = ''; });
			['zt-f-country', 'zt-f-device', 'zt-f-browser', 'zt-f-os', 'zt-f-ptype'].forEach(function (id) { document.getElementById(id).value = ''; });
			document.getElementById('zt-f-bots').value = 'include';
			document.querySelectorAll('#zt-quick .zt-chip').forEach(function (c) { c.classList.toggle('is-active', c.getAttribute('data-r') === 'all'); });
			load();
		});

		document.getElementById('zt-f-live').addEventListener('change', function () {
			state.live = this.checked;
			if (state.timer) { clearInterval(state.timer); state.timer = null; }
			if (state.live) {
				state.timer = setInterval(function () {
					if (document.visibilityState === 'visible' && !state.loading) { load(true); }
				}, 10000);
				toast('Canlı mod açık — 10 saniyede bir yenilenecek');
			}
		});

		document.getElementById('zt-export').addEventListener('click', function (ev) {
			ev.preventDefault();
			var url = BOOT.exportUrl;
			Object.keys(state.filters).forEach(function (k) {
				if (state.filters[k]) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(state.filters[k]); }
			});
			window.location.href = url;
			toast('CSV dosyası hazırlanıyor…');
		});

		function load(silent) {
			state.loading = true;
			var holder = document.getElementById('zt-visits-table');
			if (!silent) { holder.style.opacity = '0.45'; }

			var payload = Object.assign({}, state.filters, {
				paged: state.paged, per_page: state.per_page, order: state.order
			});

			api('visits', payload).then(function (res) {
				state.loading = false;
				holder.style.opacity = '1';
				renderTable(res);
			}).catch(function (err) {
				state.loading = false;
				holder.style.opacity = '1';
				holder.innerHTML = emptyHtml('Yüklenemedi: ' + err.message);
			});
		}

		function renderTable(res) {
			var holder = document.getElementById('zt-visits-table');
			if (!res.rows.length) {
				holder.innerHTML = emptyHtml('Bu filtrelerle eşleşen ziyaret bulunamadı');
				document.getElementById('zt-visits-pager').innerHTML = '';
				return;
			}

			var html = '<div class="zt-table-scroll"><table class="zt-table"><thead><tr>' +
				'<th>Zaman</th><th>Ziyaretçi</th><th>Sayfa</th><th>Kaynak</th><th>Cihaz</th><th>Tarayıcı</th><th>Tür</th><th style="text-align:right">İşlem</th>' +
				'</tr></thead><tbody>';

			res.rows.forEach(function (r) {
				var dm = deviceMeta(r.device);
				var typeLabel = PAGE_TYPES[r.page_type] || r.page_type || '—';
				html += '<tr>' +
					'<td class="zt-time">' + esc(relTime(r.visited_at)) + '<div class="zt-cell-sub">' + esc(fullTime(r.visited_at)) + '</div></td>' +
					'<td><span class="zt-ip" data-ip="' + esc(r.ip) + '" title="Profili aç">' + geoCell(r) + esc(r.ip || '—') + '</span>' +
					((r.city || r.country) ? '<div class="zt-cell-sub">' + esc(r.city || '') + (r.city && r.country ? ', ' : '') + esc(r.country || '') + '</div>' : (BOOT.geoEnabled && r.geo_status === 'pending' ? '<div class="zt-cell-sub">konum bekleniyor…</div>' : '')) + '</td>' +
					'<td style="max-width:270px"><div class="zt-cell-main" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.url) + '">' + esc(r.page_title || '(başlıksız)') + '</div>' +
					'<div class="zt-cell-sub" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(urlPath(r.url)) + '</a></div></td>' +
					'<td>' + (r.referrer_domain ? '<span title="' + esc(r.referrer) + '">' + esc(r.referrer_domain) + '</span>' : '<span style="color:#7e89a0">doğrudan</span>') + '</td>' +
					'<td><span class="zt-device">' + icon(dm.icon) + esc(dm.label) + '</span>' + (r.os && r.os !== '—' ? '<div class="zt-cell-sub">' + esc(r.os) + '</div>' : '') + '</td>' +
					'<td>' + esc(r.browser || '—') + (r.browser_version ? ' <span style="color:#aab4c8">' + esc(r.browser_version) + '</span>' : '') + '</td>' +
					'<td>' + (Number(r.is_bot) === 1 ? botBadge(r) : '<span class="zt-badge type">' + esc(typeLabel) + '</span>') + '</td>' +
					'<td><div class="zt-row-actions">' +
					'<button type="button" class="zt-icon-btn" data-profile="' + esc(r.ip) + '" title="Ziyaretçi profili">' + icon('user') + '</button>' +
					'<button type="button" class="zt-icon-btn danger" data-del="' + esc(r.id) + '" title="Kaydı sil">' + icon('trash') + '</button>' +
					'</div></td>' +
					'</tr>';
			});
			html += '</tbody></table></div>';
			holder.innerHTML = html;

			bindIpClicks(holder);
			holder.querySelectorAll('[data-profile]').forEach(function (btn) {
				btn.addEventListener('click', function () { openProfile(btn.getAttribute('data-profile')); });
			});
			holder.querySelectorAll('[data-del]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					confirmModal({ title: 'Kaydı sil', text: 'Bu ziyaret kaydı kalıcı olarak silinecek.', okLabel: 'Sil', danger: true })
						.then(function (ok) {
							if (!ok) { return; }
							api('delete_visit', { id: btn.getAttribute('data-del') }).then(function () {
								toast('Kayıt silindi');
								load(true);
							}).catch(function (err) { toast(err.message, 'err'); });
						});
				});
			});

			renderPager(document.getElementById('zt-visits-pager'), res.total, res.page, res.per_page, function (p) {
				state.paged = p;
				load();
				window.scrollTo({ top: 0, behavior: 'smooth' });
			});
		}

		window.__ztReload = function () { load(true); };
		load();
	}

	/* ═══════════════════ ZİYARETÇİLER ═══════════════════ */

	function initVisitors() {
		var state = { search: '', bots: 'exclude', sort: 'last_seen', paged: 1, per_page: 25 };

		app.innerHTML =
			headerHtml('Ziyaretçiler — IP bazlı davranış profilleri', navChips('visitors')) +
			'<div class="zt-card" style="margin-bottom:14px"><div class="zt-toolbar">' +
			'<span class="zt-search-wrap">' + icon('search') + '<input type="text" class="zt-input" id="zt-v-search" placeholder="IP, şehir, ülke veya ISS ara…" style="width:250px"></span>' +
			'<select class="zt-select" id="zt-v-bots">' +
			'<option value="exclude">Sadece İnsanlar</option>' +
			'<option value="only">Sadece Botlar</option>' +
			'<option value="include">Hepsi</option>' +
			'</select>' +
			'<select class="zt-select" id="zt-v-sort">' +
			'<option value="last_seen">Son görülmeye göre</option>' +
			'<option value="first_seen">İlk görülmeye göre</option>' +
			'<option value="visits">Ziyaret sayısına göre</option>' +
			'<option value="pages">Sayfa sayısına göre</option>' +
			'</select>' +
			'<select class="zt-select" id="zt-v-pp">' +
			'<option value="25">25 ziyaretçi</option><option value="50">50 ziyaretçi</option><option value="100">100 ziyaretçi</option>' +
			'</select>' +
			'<span class="zt-spacer"></span>' +
			'<span style="color:#aab4c8;font-size:12px" id="zt-v-hint">Bir ziyaretçiye tıklayarak davranış akışını inceleyin</span>' +
			'</div></div>' +
			'<div class="zt-card" style="animation-delay:0.08s"><div id="zt-v-table"></div><div id="zt-v-pager"></div></div>';

		var loadDebounced = debounce(function () { state.paged = 1; load(); }, 300);

		document.getElementById('zt-v-search').addEventListener('input', function () {
			state.search = this.value;
			loadDebounced();
		});
		document.getElementById('zt-v-bots').addEventListener('change', function () {
			state.bots = this.value; state.paged = 1; load();
		});
		document.getElementById('zt-v-sort').addEventListener('change', function () {
			state.sort = this.value; state.paged = 1; load();
		});
		document.getElementById('zt-v-pp').addEventListener('change', function () {
			state.per_page = parseInt(this.value, 10) || 25; state.paged = 1; load();
		});

		function load() {
			var holder = document.getElementById('zt-v-table');
			holder.style.opacity = '0.45';
			api('visitors', {
				search: state.search, bots: state.bots, sort: state.sort,
				paged: state.paged, per_page: state.per_page
			}).then(function (res) {
				holder.style.opacity = '1';
				render(res);
			}).catch(function (err) {
				holder.style.opacity = '1';
				holder.innerHTML = emptyHtml('Yüklenemedi: ' + err.message);
			});
		}

		function render(res) {
			var holder = document.getElementById('zt-v-table');
			if (!res.rows.length) {
				holder.innerHTML = emptyHtml('Eşleşen ziyaretçi bulunamadı');
				document.getElementById('zt-v-pager').innerHTML = '';
				return;
			}

			var html = '<div class="zt-table-scroll"><table class="zt-table"><thead><tr>' +
				'<th>Ziyaretçi</th><th>Konum</th><th>İnternet Sağlayıcı</th><th>Ziyaret</th><th>Sayfa</th><th>İlk Görülme</th><th>Son Görülme</th><th>Cihaz</th><th style="text-align:right">İşlem</th>' +
				'</tr></thead><tbody>';

			res.rows.forEach(function (r) {
				var dm = deviceMeta(r.device);
				var badges = '';
				if (Number(r.has_bot) === 1) { badges += ' ' + '<span class="zt-badge bot">' + icon('bot') + 'Bot</span>'; }
				if (Number(r.is_proxy) === 1) { badges += ' <span class="zt-badge proxy">Proxy</span>'; }
				if (Number(r.is_hosting) === 1) { badges += ' <span class="zt-badge hosting">DC</span>'; }

				html += '<tr style="cursor:pointer" data-row-ip="' + esc(r.ip) + '">' +
					'<td><span class="zt-ip" data-ip="' + esc(r.ip) + '">' + geoCell(r) + esc(r.ip) + '</span>' + badges + '</td>' +
					'<td>' + (r.city || r.country ? esc(r.city || '') + (r.city && r.country ? ', ' : '') + esc(r.country || '') : '<span style="color:#7e89a0">—</span>') + '</td>' +
					'<td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (r.isp ? esc(r.isp) : '<span style="color:#7e89a0">—</span>') + '</td>' +
					'<td><b>' + n(r.visits) + '</b></td>' +
					'<td>' + n(r.pages) + '</td>' +
					'<td class="zt-time">' + esc(relTime(r.first_seen)) + '</td>' +
					'<td class="zt-time">' + esc(relTime(r.last_seen)) + '</td>' +
					'<td><span class="zt-device">' + icon(dm.icon) + esc(dm.label) + '</span></td>' +
					'<td><div class="zt-row-actions">' +
					'<button type="button" class="zt-icon-btn" data-profile="' + esc(r.ip) + '" title="Profil">' + icon('user') + '</button>' +
					'<a class="zt-icon-btn" href="' + esc(BOOT.pages.visits) + '&ip=' + encodeURIComponent(r.ip) + '" title="Ziyaretlerini listele">' + icon('list') + '</a>' +
					'<button type="button" class="zt-icon-btn" data-blockip="' + esc(r.ip) + '" title="IP\'yi engelle">' + icon('ban') + '</button>' +
					'<button type="button" class="zt-icon-btn danger" data-delip="' + esc(r.ip) + '" title="Tüm verilerini sil">' + icon('trash') + '</button>' +
					'</div></td></tr>';
			});
			html += '</tbody></table></div>';
			holder.innerHTML = html;

			holder.querySelectorAll('tr[data-row-ip]').forEach(function (tr) {
				tr.addEventListener('click', function (ev) {
					if (ev.target.closest('a, button')) { return; }
					openProfile(tr.getAttribute('data-row-ip'));
				});
			});
			holder.querySelectorAll('[data-profile]').forEach(function (btn) {
				btn.addEventListener('click', function () { openProfile(btn.getAttribute('data-profile')); });
			});
			holder.querySelectorAll('[data-delip]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var ip = btn.getAttribute('data-delip');
					confirmModal({
						title: 'IP verilerini sil',
						text: ip + ' adresine ait tüm kayıtlar silinecek. Bu işlem geri alınamaz.',
						okLabel: 'Evet, Sil', danger: true
					}).then(function (ok) {
						if (!ok) { return; }
						api('delete_ip', { ip: ip }).then(function (msgRes) {
							toast(msgRes.message || 'Silindi');
							load();
						}).catch(function (err) { toast(err.message, 'err'); });
					});
				});
			});
			holder.querySelectorAll('[data-blockip]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var ip = btn.getAttribute('data-blockip');
					confirmModal({
						title: 'IP\'yi engelle',
						text: ip + ' adresi engellenecek; bu IP sitenize eriştiğinde 403 alacak ve artık kaydedilmeyecek. (Ayarlar\'dan geri alınabilir.)',
						okLabel: 'Engelle', danger: true
					}).then(function (ok) {
						if (!ok) { return; }
						api('block_ip', { ip: ip }).then(function (res) {
							toast(res.message || 'Engellendi');
						}).catch(function (err) { toast(err.message, 'err'); });
					});
				});
			});

			renderPager(document.getElementById('zt-v-pager'), res.total, res.page, res.per_page, function (p) {
				state.paged = p;
				load();
				window.scrollTo({ top: 0, behavior: 'smooth' });
			});
		}

		window.__ztReload = function () { load(); };
		load();
	}

	/* ═══════════════════ RAPORLAR ═══════════════════ */

	function initReports() {
		var state = { period: '7d', metric: 'visitors', data: null, firstLoad: true };

		app.innerHTML =
			headerHtml('Raporlar — dönem karşılaştırması', navChips('reports') +
				'<div class="zt-segment" id="zt-rp-period">' +
				'<button type="button" data-p="7d" class="is-active">Son 7 Gün</button>' +
				'<button type="button" data-p="30d">Son 30 Gün</button>' +
				'<button type="button" data-p="90d">Son 90 Gün</button>' +
				'</div>' +
				'<button type="button" class="zt-btn" id="zt-rp-pdf">' + icon('file') + 'PDF</button>') +
			'<div id="zt-rp-body">' + skel() + '</div>';

		function skel() {
			var h = '<div class="zt-stats">';
			for (var i = 0; i < 3; i++) { h += '<div class="zt-skel" style="height:120px;border-radius:16px"></div>'; }
			h += '</div><div class="zt-skel" style="height:320px;border-radius:16px;margin-bottom:14px"></div>' +
				'<div class="zt-grid zt-grid-3"><div class="zt-skel" style="height:260px;border-radius:16px"></div>' +
				'<div class="zt-skel" style="height:260px;border-radius:16px"></div><div class="zt-skel" style="height:260px;border-radius:16px"></div></div>';
			return h;
		}

		document.getElementById('zt-rp-period').addEventListener('click', function (ev) {
			var btn = ev.target.closest('button[data-p]');
			if (!btn) { return; }
			this.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-active'); });
			btn.classList.add('is-active');
			state.period = btn.getAttribute('data-p');
			state.firstLoad = true;
			document.getElementById('zt-rp-body').innerHTML = skel();
			load();
		});

		document.getElementById('zt-rp-pdf').addEventListener('click', function () {
			var p = state.period === '90d' ? '30d' : state.period; // PDF özeti en fazla 30 gün
			window.open(BOOT.pdfUrl + '&period=' + encodeURIComponent(p), '_blank');
		});

		function deltaPill(cur, prev) {
			cur = Number(cur) || 0; prev = Number(prev) || 0;
			if (prev <= 0 && cur <= 0) { return '<span class="zt-delta flat">—</span>'; }
			if (prev <= 0) { return '<span class="zt-delta up">↑ yeni</span>'; }
			var pct = Math.round((cur - prev) / prev * 100);
			if (pct > 0) { return '<span class="zt-delta up">↑ %' + n(pct) + '</span>'; }
			if (pct < 0) { return '<span class="zt-delta down">↓ %' + n(Math.abs(pct)) + '</span>'; }
			return '<span class="zt-delta flat">%0</span>';
		}

		function statCard(label, glow, cur, prev) {
			return '<div class="zt-card zt-stat" style="--zt-glow:' + glow + '">' +
				'<div class="zt-stat-head">' + esc(label) + '</div>' +
				'<div class="zt-stat-num">' + n(cur) + '</div>' +
				'<div class="zt-stat-foot">' + deltaPill(cur, prev) + '<span>önceki: ' + n(prev) + '</span></div></div>';
		}

		function compareCard(title, iconName, rows, labelFn) {
			var body;
			if (!rows || !rows.length) {
				body = emptyHtml('Veri yok');
			} else {
				var maxV = Math.max.apply(null, rows.map(function (r) { return Number(r.cur); }).concat([1]));
				body = '<div class="zt-bars">' + rows.map(function (r) {
					return '<div class="zt-cmp-row"><div class="zt-cmp-head">' +
						'<span class="zt-cmp-label" title="' + esc(labelFn(r)) + '">' + esc(labelFn(r)) + '</span>' +
						'<span class="zt-cmp-nums"><b>' + n(r.cur) + '</b> <small>(' + n(r.prev) + ')</small> ' + deltaPill(r.cur, r.prev) + '</span>' +
						'</div><div class="zt-bar-track"><div class="zt-bar-fill" data-w="' + Math.max(3, Math.round(r.cur / maxV * 100)) + '"></div></div></div>';
				}).join('') + '</div>';
			}
			return '<div class="zt-card"><div class="zt-card-title">' + icon(iconName) + esc(title) + '</div>' + body + '</div>';
		}

		function drawChart() {
			var d = state.data;
			if (!d) { return; }
			ZTCharts.area(document.getElementById('zt-rp-chart'), {
				labels: d.labels,
				series: [
					{ name: 'Bu dönem', color: '#22d3ee', data: d.current[state.metric] },
					{ name: 'Önceki dönem', color: '#aab4c8', data: d.previous[state.metric], fill: false, dashed: true }
				],
				height: 265,
				animate: true
			});
		}

		function render(d) {
			state.data = d;
			var t = d.totals;
			var body = document.getElementById('zt-rp-body');

			var devLabel = function (r) {
				return (deviceMeta(r.key).label) || r.label || r.key;
			};

			body.innerHTML =
				'<div class="zt-rp-range">' + icon('clock') +
				' <b>Bu dönem:</b> ' + esc(d.range.cur_from) + ' – ' + esc(d.range.cur_to) +
				' &nbsp;•&nbsp; <b>Önceki dönem:</b> ' + esc(d.range.prev_from) + ' – ' + esc(d.range.prev_to) + '</div>' +
				'<div class="zt-stats zt-stagger">' +
				statCard('Tekil Ziyaretçi', 'rgba(34, 211, 238, 0.1)', t.visitors, t.visitors_prev) +
				statCard('Sayfa Görüntüleme', 'rgba(167, 139, 250, 0.1)', t.views, t.views_prev) +
				statCard('Bot Trafiği', 'rgba(251, 191, 36, 0.1)', t.bots, t.bots_prev) +
				'</div>' +
				'<div class="zt-card zt-stagger"><div class="zt-card-title">' + icon('chart') + 'Trend Karşılaştırması' +
				'<span class="zt-title-hint"><span class="zt-metric-toggle" id="zt-rp-metric">' +
				'<button type="button" data-m="visitors" class="is-active">Ziyaretçi</button>' +
				'<button type="button" data-m="views">Görüntüleme</button>' +
				'</span></span></div>' +
				'<div id="zt-rp-chart"></div>' +
				'<div class="zt-legend"><span class="zt-legend-item"><span class="zt-legend-swatch" style="background:#22d3ee"></span>Bu dönem</span>' +
				'<span class="zt-legend-item"><span class="zt-legend-swatch" style="background:#aab4c8"></span>Önceki dönem</span></div></div>' +
				'<div class="zt-grid zt-grid-main zt-stagger">' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('users') + 'Yeni vs Dönen Ziyaretçi<span class="zt-title-hint">günlük</span></div>' +
				'<div id="zt-rp-split"></div>' +
				'<div class="zt-legend"><span class="zt-legend-item"><span class="zt-legend-swatch" style="background:#818cf8"></span>Dönen</span>' +
				'<span class="zt-legend-item"><span class="zt-legend-swatch" style="background:#22d3ee"></span>Yeni</span></div></div>' +
				'<div class="zt-card"><div class="zt-card-title">' + icon('activity') + 'Dönem Toplamı</div>' +
				'<div id="zt-rp-split-donut" style="padding-top:6px"></div><div id="zt-rp-split-stats" class="zt-kv" style="margin-top:14px"></div></div>' +
				'</div>' +
				'<div class="zt-grid zt-grid-3 zt-stagger">' +
				compareCard('Ülkeler', 'globe', d.countries, function (r) { return r.label || r.key; }) +
				compareCard('En Çok Görüntülenen Sayfalar', 'file', d.pages, function (r) { return r.label || urlPath(r.key); }) +
				compareCard('Cihazlar', 'monitor', d.devices, devLabel) +
				'</div>';

			drawChart();

			// Yeni vs dönen: yığılmış çubuk + halka + özet.
			var sp = d.split || { new: [], returning: [], totals: {} };
			var spT = sp.totals || {};
			ZTCharts.stackedBars(document.getElementById('zt-rp-split'), {
				labels: d.labels,
				series: [
					{ name: 'Dönen', color: '#818cf8', data: sp.returning },
					{ name: 'Yeni', color: '#22d3ee', data: sp.new }
				],
				height: 235
			});
			ZTCharts.donut(document.getElementById('zt-rp-split-donut'), {
				items: [
					{ label: 'Yeni', value: Number(spT.new) || 0, color: '#22d3ee' },
					{ label: 'Dönen', value: Number(spT.returning) || 0, color: '#818cf8' }
				],
				centerValue: Number(spT.total) || 0,
				centerLabel: 'ziyaretçi'
			});
			document.getElementById('zt-rp-split-stats').innerHTML =
				'<div class="row"><span class="k">Dönen oranı</span><span class="v"><b style="font-size:15px">%' + n(spT.rate || 0) + '</b></span></div>' +
				'<div class="row"><span class="k">Yeni ziyaretçi</span><span class="v">' + n(spT.new || 0) + ' <small style="color:#aab4c8">(önceki: ' + n(spT.new_prev || 0) + ')</small> ' + deltaPill(spT.new, spT.new_prev) + '</span></div>' +
				'<div class="row"><span class="k">Dönen ziyaretçi</span><span class="v">' + n(spT.returning || 0) + ' <small style="color:#aab4c8">(önceki: ' + n(spT.returning_prev || 0) + ')</small> ' + deltaPill(spT.returning, spT.returning_prev) + '</span></div>';

			setTimeout(function () {
				body.querySelectorAll('.zt-bar-fill[data-w]').forEach(function (el) {
					el.style.width = el.getAttribute('data-w') + '%';
				});
			}, 30);

			document.getElementById('zt-rp-metric').addEventListener('click', function (ev) {
				var b = ev.target.closest('button[data-m]');
				if (!b) { return; }
				this.querySelectorAll('button').forEach(function (x) { x.classList.remove('is-active'); });
				b.classList.add('is-active');
				state.metric = b.getAttribute('data-m');
				drawChart();
			});

			state.firstLoad = false;
		}

		function load() {
			api('report', { period: state.period }).then(render).catch(function (err) {
				document.getElementById('zt-rp-body').innerHTML = '<div class="zt-card">' + emptyHtml('Rapor yüklenemedi: ' + err.message) + '</div>';
			});
		}

		load();
	}

	/* ═══════════════════ HUNİLER ═══════════════════ */

	function initFunnel() {
		var funnels = (BOOT.funnels || []).slice();
		var state = { curId: '', name: '', window: '30d', steps: [] };

		function blank() { return { label: '', match: '' }; }

		app.innerHTML =
			headerHtml('Huniler — sıralı dönüşüm analizi', navChips('funnel')) +
			'<div id="zt-fn-tabs" class="zt-fn-tabs"></div>' +
			'<div class="zt-grid zt-grid-funnel">' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('funnel') + 'Huni Adımları</div>' +
			'<input type="text" class="zt-input" id="zt-fn-name" placeholder="Huni adı (ör. Satın alma hunisi)" style="width:100%;margin-bottom:12px">' +
			'<p style="color:var(--zt-muted);font-size:12px;margin:0 0 12px">Her adım, ziyaret URL\'sinde geçen bir metinle eşleşir (ör. <code style="color:#67e8f9">/urun</code>, <code style="color:#67e8f9">/sepet</code>, <code style="color:#67e8f9">/tesekkur</code>). Ziyaretçiler adımları zaman sırasıyla geçmelidir.</p>' +
			'<div id="zt-fn-steps"></div>' +
			'<button type="button" class="zt-btn" id="zt-fn-add" style="margin-top:4px">' + icon('plus') + 'Adım Ekle</button>' +
			'<div class="zt-fn-actions">' +
			'<select class="zt-select" id="zt-fn-window">' +
			['7d:Son 7 gün', '30d:Son 30 gün', '90d:Son 90 gün', 'all:Tüm zamanlar'].map(function (o) {
				var p = o.split(':');
				return '<option value="' + p[0] + '">' + p[1] + '</option>';
			}).join('') +
			'</select>' +
			'<button type="button" class="zt-btn zt-btn-primary" id="zt-fn-run">' + icon('activity') + 'Çalıştır</button>' +
			'<button type="button" class="zt-btn" id="zt-fn-save">' + icon('check') + 'Kaydet</button>' +
			'<button type="button" class="zt-btn zt-btn-danger" id="zt-fn-delete" style="display:none">' + icon('trash') + 'Sil</button>' +
			'</div></div>' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('chart') + 'Dönüşüm Hunisi<span class="zt-title-hint" id="zt-fn-range"></span></div>' +
			'<div id="zt-fn-result"></div></div>' +
			'</div>';

		var stepsBox = document.getElementById('zt-fn-steps');
		var nameInput = document.getElementById('zt-fn-name');
		var winSelect = document.getElementById('zt-fn-window');
		var delBtn = document.getElementById('zt-fn-delete');

		function renderTabs() {
			var tabs = document.getElementById('zt-fn-tabs');
			var html = funnels.map(function (fn) {
				return '<button type="button" class="zt-chip ' + (fn.id === state.curId ? 'is-active' : '') + '" data-fid="' + esc(fn.id) + '">' + esc(fn.name || 'Adsız') + '</button>';
			}).join('');
			if (!state.curId) {
				html += '<button type="button" class="zt-chip is-active">' + esc(state.name || 'Yeni huni') + '</button>';
			}
			html += '<button type="button" class="zt-chip zt-fn-newtab" id="zt-fn-new">' + icon('plus') + 'Yeni</button>';
			tabs.innerHTML = html;
			tabs.querySelectorAll('[data-fid]').forEach(function (b) {
				b.addEventListener('click', function () { loadFunnel(b.getAttribute('data-fid')); });
			});
			document.getElementById('zt-fn-new').addEventListener('click', newFunnel);
			delBtn.style.display = state.curId ? '' : 'none';
		}

		function applyToEditor() {
			nameInput.value = state.name;
			winSelect.value = state.window;
			renderSteps();
		}

		function loadFunnel(id) {
			var fn = funnels.filter(function (x) { return x.id === id; })[0];
			if (!fn) { newFunnel(); return; }
			state.curId = fn.id;
			state.name = fn.name || '';
			state.window = fn.window || '30d';
			state.steps = (fn.steps && fn.steps.length) ? fn.steps.map(function (s) { return { label: s.label || '', match: s.match || '' }; }) : [blank(), blank()];
			applyToEditor();
			renderTabs();
			run();
		}

		function newFunnel() {
			state.curId = '';
			state.name = '';
			state.window = '30d';
			state.steps = [blank(), blank()];
			applyToEditor();
			renderTabs();
			document.getElementById('zt-fn-result').innerHTML = emptyHtml('Adımları doldurun ve Çalıştır\'a basın');
			document.getElementById('zt-fn-range').textContent = '';
		}

		var steps = state.steps; // renderSteps/syncFromInputs bunun üzerinden çalışır

		function renderSteps() {
			steps = state.steps;
			stepsBox.innerHTML = steps.map(function (s, i) {
				return '<div class="zt-fn-step" data-i="' + i + '">' +
					'<span class="zt-fn-num">' + (i + 1) + '</span>' +
					'<div class="zt-fn-inputs">' +
					'<input type="text" class="zt-input zt-fn-label" placeholder="Adım adı (ör. Ürün sayfası)" value="' + esc(s.label) + '">' +
					'<input type="text" class="zt-input zt-fn-match" placeholder="URL içinde geçen metin (ör. /urun)" value="' + esc(s.match) + '">' +
					'</div>' +
					'<div class="zt-fn-step-actions">' +
					(i > 0 ? '<button type="button" class="zt-icon-btn" data-act="up" title="Yukarı">' + icon('left') + '</button>' : '<span style="width:27px"></span>') +
					(i < steps.length - 1 ? '<button type="button" class="zt-icon-btn" data-act="down" title="Aşağı">' + icon('right') + '</button>' : '<span style="width:27px"></span>') +
					(steps.length > 1 ? '<button type="button" class="zt-icon-btn danger" data-act="del" title="Sil">' + icon('trash') + '</button>' : '') +
					'</div></div>';
			}).join('');
		}

		function syncFromInputs() {
			stepsBox.querySelectorAll('.zt-fn-step').forEach(function (row) {
				var i = parseInt(row.getAttribute('data-i'), 10);
				steps[i] = {
					label: row.querySelector('.zt-fn-label').value,
					match: row.querySelector('.zt-fn-match').value
				};
			});
		}

		stepsBox.addEventListener('click', function (e) {
			var btn = e.target.closest('button[data-act]');
			if (!btn) { return; }
			syncFromInputs();
			var i = parseInt(btn.closest('.zt-fn-step').getAttribute('data-i'), 10);
			var act = btn.getAttribute('data-act');
			if (act === 'del') { steps.splice(i, 1); }
			else if (act === 'up' && i > 0) { var t = steps[i - 1]; steps[i - 1] = steps[i]; steps[i] = t; }
			else if (act === 'down' && i < steps.length - 1) { var t2 = steps[i + 1]; steps[i + 1] = steps[i]; steps[i] = t2; }
			renderSteps();
		});

		document.getElementById('zt-fn-add').addEventListener('click', function () {
			if (steps.length >= 8) { toast('En fazla 8 adım eklenebilir', 'err'); return; }
			syncFromInputs();
			steps.push({ label: '', match: '' });
			renderSteps();
		});

		function collect() {
			syncFromInputs();
			return steps.filter(function (s) { return s.match.trim() !== ''; });
		}

		function run() {
			var valid = collect();
			var resEl = document.getElementById('zt-fn-result');
			if (!valid.length) {
				resEl.innerHTML = emptyHtml('En az bir adım tanımlayın (URL parçası girin) ve Çalıştır\'a basın');
				document.getElementById('zt-fn-range').textContent = '';
				return;
			}
			resEl.innerHTML = '<div class="zt-skel" style="height:70px;margin-bottom:10px"></div><div class="zt-skel" style="height:70px;margin-bottom:10px"></div><div class="zt-skel" style="height:70px"></div>';
			api('funnel_run', { steps: JSON.stringify(valid), window: document.getElementById('zt-fn-window').value }).then(renderResult).catch(function (err) {
				resEl.innerHTML = emptyHtml('Hesaplanamadı: ' + err.message);
			});
		}

		function renderResult(d) {
			document.getElementById('zt-fn-range').textContent = d.from + ' – ' + d.to;
			var resEl = document.getElementById('zt-fn-result');
			if (!d.steps || !d.steps.length) {
				resEl.innerHTML = emptyHtml('Adım bulunamadı');
				return;
			}
			var entered = d.entered || (d.steps[0] ? d.steps[0].count : 0);
			var html = '';
			if (d.truncated) {
				html += '<div class="zt-badge geo-wait" style="margin-bottom:12px">' + icon('alert') + 'Çok büyük veri: sonuçlar ilk 20.000 ziyaretçiyle sınırlandırıldı</div>';
			}
			html += '<div class="zt-funnel">';
			d.steps.forEach(function (s, i) {
				var w = entered > 0 ? Math.max(4, Math.round(s.count / entered * 100)) : 0;
				html += '<div class="zt-funnel-step" style="animation-delay:' + (i * 0.06) + 's">' +
					'<div class="zt-funnel-info"><span class="zt-fn-badge">' + (i + 1) + '</span>' +
					'<div><div class="zt-funnel-label">' + esc(s.label) + '</div>' +
					'<div class="zt-funnel-match">' + esc(s.match) + '</div></div>' +
					'<div class="zt-funnel-count"><b>' + n(s.count) + '</b><span>ziyaretçi</span></div></div>' +
					'<div class="zt-funnel-bar-track"><div class="zt-funnel-bar" data-w="' + w + '">' +
					'<span class="zt-funnel-pct">%' + n(s.pct_first) + '</span></div></div>' +
					'<div class="zt-funnel-meta">' +
					(i === 0 ? 'Huniye giriş' : 'Önceki adımdan <b style="color:' + (s.pct_prev >= 50 ? '#6ee7b7' : (s.pct_prev >= 20 ? '#fcd34d' : '#fca5a5')) + '">%' + n(s.pct_prev) + '</b> geçti' +
						(s.dropoff > 0 ? ' · <span style="color:#fca5a5">' + n(s.dropoff) + ' kişi ayrıldı</span>' : '')) +
					'</div></div>';
			});
			html += '</div>';

			// Genel dönüşüm özeti.
			var last = d.steps[d.steps.length - 1];
			if (d.steps.length > 1) {
				var overall = entered > 0 ? Math.round(last.count / entered * 100) : 0;
				html += '<div class="zt-funnel-summary">' + icon('activity') + ' Genel dönüşüm: <b>' + n(entered) + '</b> ziyaretçiden <b>' + n(last.count) + '</b> son adıma ulaştı — <b style="color:#22d3ee">%' + n(overall) + '</b></div>';
			}
			resEl.innerHTML = html;

			setTimeout(function () {
				resEl.querySelectorAll('.zt-funnel-bar[data-w]').forEach(function (el) {
					el.style.width = el.getAttribute('data-w') + '%';
				});
			}, 40);
		}

		document.getElementById('zt-fn-run').addEventListener('click', run);
		winSelect.addEventListener('change', function () { state.window = winSelect.value; run(); });

		document.getElementById('zt-fn-save').addEventListener('click', function () {
			var valid = collect();
			var nm = nameInput.value.trim() || 'Adsız huni';
			api('funnel_save', { id: state.curId, name: nm, steps: JSON.stringify(valid), window: winSelect.value })
				.then(function (res) {
					funnels = res.funnels || [];
					state.curId = res.id;
					state.name = nm;
					renderTabs();
					toast(res.message || 'Kaydedildi');
				})
				.catch(function (err) { toast(err.message, 'err'); });
		});

		delBtn.addEventListener('click', function () {
			if (!state.curId) { newFunnel(); return; }
			confirmModal({ title: 'Huniyi sil', text: '"' + (state.name || 'Adsız') + '" hunisi silinecek.', okLabel: 'Sil', danger: true }).then(function (ok) {
				if (!ok) { return; }
				api('funnel_delete', { id: state.curId }).then(function (res) {
					funnels = res.funnels || [];
					toast(res.message || 'Silindi');
					if (funnels.length) { loadFunnel(funnels[0].id); } else { newFunnel(); }
				}).catch(function (err) { toast(err.message, 'err'); });
			});
		});

		if (funnels.length) { loadFunnel(funnels[0].id); } else { newFunnel(); }
	}

	/* ═══════════════════ ISI HARİTASI ═══════════════════ */

	function initHeatmap() {
		app.innerHTML =
			headerHtml('Isı Haritası — tıklama ve kaydırma yoğunluğu', navChips('heatmap')) +
			'<div class="zt-card" style="margin-bottom:14px"><div class="zt-toolbar">' +
			'<span style="color:var(--zt-muted);font-size:12.5px">Sayfa:</span>' +
			'<select class="zt-select" id="zt-heat-url" style="min-width:280px;max-width:60%"><option value="">Yükleniyor…</option></select>' +
			'<span class="zt-spacer"></span>' +
			'<span id="zt-heat-summary" style="color:var(--zt-muted);font-size:12px"></span>' +
			'</div></div>' +
			'<div class="zt-grid" style="grid-template-columns:minmax(0,1fr) 300px;gap:14px" id="zt-heat-grid">' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('activity') + 'Tıklama Yoğunluğu' +
			'<span class="zt-title-hint">nokta = tıklama; kırmızı = en yoğun</span></div>' +
			'<div id="zt-heat-box" class="zt-heat-box"></div></div>' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('eye') + 'Kaydırma Derinliği</div>' +
			'<div id="zt-heat-scroll"></div></div>' +
			'</div>';

		var sel = document.getElementById('zt-heat-url');

		api('heat_urls', {}).then(function (d) {
			var urls = d.urls || [];
			if (!urls.length) {
				document.getElementById('zt-heat-grid').innerHTML =
					'<div class="zt-card" style="grid-column:1/-1">' + emptyHtml('Henüz ısı haritası verisi yok. Ayarlar → Isı Haritası\'ndan kaydı etkinleştirin; ziyaretçiler tıkladıkça veri birikir.') + '</div>';
				sel.innerHTML = '<option value="">—</option>';
				return;
			}
			sel.innerHTML = urls.map(function (u) {
				return '<option value="' + esc(u.url) + '">' + esc(u.url) + '  (' + n(u.clicks) + ' tıklama)</option>';
			}).join('');
			load(urls[0].url);
		}).catch(function (err) {
			sel.innerHTML = '<option value="">Yüklenemedi</option>';
		});

		sel.addEventListener('change', function () { if (sel.value) { load(sel.value); } });

		function load(url) {
			var box = document.getElementById('zt-heat-box');
			box.innerHTML = '<div class="zt-skel" style="height:560px"></div>';
			document.getElementById('zt-heat-scroll').innerHTML = '<div class="zt-skel" style="height:300px"></div>';
			api('heat', { url: url }).then(render).catch(function (err) {
				box.innerHTML = emptyHtml('Yüklenemedi: ' + err.message);
			});
		}

		function render(d) {
			document.getElementById('zt-heat-summary').innerHTML =
				'<b style="color:var(--zt-text)">' + n(d.click_count) + '</b> tıklama · <b style="color:var(--zt-text)">' + n(d.sessions) + '</b> kaydırma oturumu';

			ZTCharts.heatmap(document.getElementById('zt-heat-box'), d.clicks, { height: 560 });

			// Kaydırma derinliği (reach) çubukları.
			var reach = d.reach || [];
			var html = '<div class="zt-scroll-reach">';
			reach.forEach(function (pct, i) {
				var depth = (i + 1) * 10;
				var color = pct >= 60 ? '#34d399' : (pct >= 30 ? '#fbbf24' : '#f87171');
				html += '<div class="zt-reach-row"><span class="lbl">%' + depth + '</span>' +
					'<div class="zt-reach-track"><div class="zt-reach-fill" style="width:' + pct + '%;background:' + color + '"></div></div>' +
					'<span class="val">%' + n(pct) + '</span></div>';
			});
			html += '</div><p style="color:var(--zt-muted);font-size:11.5px;margin:12px 0 0">Sayfanın ilgili derinliğine ulaşan oturum yüzdesi.</p>';
			document.getElementById('zt-heat-scroll').innerHTML = reach.length ? html : emptyHtml('Kaydırma verisi yok');
		}
	}

	/* ═══════════════════ KOHORT & A/B ═══════════════════ */

	function initCohort() {
		app.innerHTML =
			headerHtml('Kohort & A/B — geri dönüş ve segment karşılaştırma', navChips('cohort')) +
			'<div class="zt-card zt-stagger" style="margin-bottom:14px"><div class="zt-card-title">' + icon('users') + 'Kohort Geri Dönüş (Retention)' +
			'<span class="zt-title-hint"><span class="zt-metric-toggle" id="zt-co-weeks">' +
			'<button type="button" data-w="8" class="is-active">8 hafta</button><button type="button" data-w="12">12 hafta</button></span></span></div>' +
			'<p style="color:var(--zt-muted);font-size:12px;margin:0 0 12px">Her satır, o hafta siteye <b>ilk kez</b> gelen ziyaretçi grubudur. Sağa doğru hücreler, sonraki haftalarda kaçının geri döndüğünü gösterir.</p>' +
			'<div id="zt-cohort-grid"></div></div>' +
			'<div class="zt-card zt-stagger"><div class="zt-card-title">' + icon('filter') + 'Segment Karşılaştırma (A/B)</div>' +
			'<p style="color:var(--zt-muted);font-size:12px;margin:0 0 12px">İki segmenti seçilen dönemde yan yana karşılaştırın.</p>' +
			'<div class="zt-ab-controls">' +
			segmentPicker('a', 'A') +
			'<div class="zt-ab-vs">VS</div>' +
			segmentPicker('b', 'B') +
			'</div>' +
			'<div class="zt-ab-run"><select class="zt-select" id="zt-ab-period"><option value="7d">Son 7 gün</option><option value="30d" selected>Son 30 gün</option><option value="90d">Son 90 gün</option></select>' +
			'<button type="button" class="zt-btn zt-btn-primary" id="zt-ab-go">' + icon('activity') + 'Karşılaştır</button></div>' +
			'<div id="zt-ab-result" style="margin-top:16px"></div></div>';

		var weeks = 8;
		loadCohorts();

		document.getElementById('zt-co-weeks').addEventListener('click', function (e) {
			var b = e.target.closest('button[data-w]');
			if (!b) { return; }
			this.querySelectorAll('button').forEach(function (x) { x.classList.remove('is-active'); });
			b.classList.add('is-active');
			weeks = parseInt(b.getAttribute('data-w'), 10);
			loadCohorts();
		});

		function loadCohorts() {
			var g = document.getElementById('zt-cohort-grid');
			g.innerHTML = '<div class="zt-skel" style="height:220px"></div>';
			api('cohorts', { weeks: weeks }).then(renderCohorts).catch(function (err) {
				g.innerHTML = emptyHtml('Yüklenemedi: ' + err.message);
			});
		}

		function renderCohorts(d) {
			var g = document.getElementById('zt-cohort-grid');
			if (!d.cohorts || !d.cohorts.length) {
				g.innerHTML = emptyHtml('Kohort verisi yok');
				return;
			}
			var wk = d.weeks;
			var html = '<div class="zt-table-scroll"><table class="zt-cohort"><thead><tr><th>Kohort (ilk hafta)</th><th>Ziyaretçi</th>';
			for (var i = 0; i < wk; i++) { html += '<th>H' + i + '</th>'; }
			html += '</tr></thead><tbody>';
			d.cohorts.slice().reverse().forEach(function (c) {
				html += '<tr><td class="zt-co-label">' + esc(c.label) + '</td><td class="zt-co-size">' + n(c.size) + '</td>';
				c.cells.forEach(function (v) {
					if (v === null) { html += '<td class="zt-co-cell empty"></td>'; }
					else {
						var alpha = Math.max(0.06, v / 100);
						html += '<td class="zt-co-cell" style="background:rgba(52,211,153,' + alpha.toFixed(2) + ')">' + (v > 0 ? '%' + v : '·') + '</td>';
					}
				});
				html += '</tr>';
			});
			html += '</tbody></table></div>';
			g.innerHTML = html;
		}

		function segmentPicker(key, letter) {
			return '<div class="zt-ab-seg"><div class="zt-ab-badge">' + letter + '</div>' +
				'<select class="zt-select" id="zt-' + key + '-type">' +
				'<option value="url">URL içerir</option><option value="campaign">Kampanya</option>' +
				'<option value="referrer">Kaynak (domain)</option><option value="device">Cihaz</option><option value="country">Ülke kodu</option>' +
				'</select>' +
				'<input type="text" class="zt-input" id="zt-' + key + '-value" placeholder="değer (ör. /blog, google.com, mobile, TR)">' +
				'</div>';
		}

		document.getElementById('zt-ab-go').addEventListener('click', function () {
			var payload = {
				a_type: document.getElementById('zt-a-type').value,
				a_value: document.getElementById('zt-a-value').value,
				b_type: document.getElementById('zt-b-type').value,
				b_value: document.getElementById('zt-b-value').value,
				period: document.getElementById('zt-ab-period').value
			};
			if (!payload.a_value.trim() || !payload.b_value.trim()) { toast('Her iki segment için değer girin', 'err'); return; }
			var res = document.getElementById('zt-ab-result');
			res.innerHTML = '<div class="zt-skel" style="height:130px"></div>';
			api('segments', payload).then(function (d) { renderAB(d, payload); }).catch(function (err) { res.innerHTML = emptyHtml('Hesaplanamadı: ' + err.message); });
		});

		function renderAB(d, p) {
			function metricRow(label, av, bv, unit) {
				var aw = Number(av) > Number(bv), bw = Number(bv) > Number(av);
				return '<div class="zt-ab-metric"><span class="m-a ' + (aw ? 'win' : '') + '">' + n(av) + (unit || '') + '</span>' +
					'<span class="m-lbl">' + esc(label) + '</span>' +
					'<span class="m-b ' + (bw ? 'win' : '') + '">' + n(bv) + (unit || '') + '</span></div>';
			}
			var labelA = p.a_value, labelB = p.b_value;
			document.getElementById('zt-ab-result').innerHTML =
				'<div class="zt-ab-head"><div class="zt-ab-h a">' + esc(labelA) + '</div><div></div><div class="zt-ab-h b">' + esc(labelB) + '</div></div>' +
				metricRow('Tekil Ziyaretçi', d.a.visitors, d.b.visitors) +
				metricRow('Görüntüleme', d.a.views, d.b.views) +
				metricRow('Sayfa / Ziyaret', d.a.ppv, d.b.ppv);
		}
	}

	/* ═══════════════════ AYARLAR ═══════════════════ */

	function initSettings() {
		var s = Object.assign({}, BOOT.settings || {});
		var roles = BOOT.roles || {};
		var dbInfo = BOOT.dbInfo || { rows: 0, sizeMb: 0, pending: 0 };
		var adminEmail = BOOT.adminEmail || '';
		var DAYS = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
		var dayOptions = DAYS.map(function (d, i) {
			return '<option value="' + i + '"' + (Number(s.report_day) === i ? ' selected' : '') + '>' + d + '</option>';
		}).join('');
		var dirty = false;

		// Saat dilimi durumu (site vs tarayıcı).
		var siteOffsetSec = Number(BOOT.gmtOffset || 0);
		var browserOffsetSec = -new Date().getTimezoneOffset() * 60;
		var tzMismatch = siteOffsetSec !== browserOffsetSec;
		function offLabel(sec) {
			var sign = sec < 0 ? '-' : '+';
			var abs = Math.abs(sec);
			var h = Math.floor(abs / 3600);
			var mm = Math.floor((abs % 3600) / 60);
			return 'UTC' + sign + h + (mm ? ':' + ('0' + mm).slice(-2) : '');
		}

		function switchHtml(key, checked) {
			return '<label class="zt-switch"><input type="checkbox" data-set="' + key + '" ' + (checked ? 'checked' : '') + '><span class="track"></span></label>';
		}

		var retentionOpts = [
			[7, '7 gün'], [14, '14 gün'], [30, '30 gün'], [90, '90 gün (önerilen)'],
			[180, '180 gün'], [365, '1 yıl'], [0, 'Süresiz sakla']
		];

		var rolesHtml = Object.keys(roles).map(function (key) {
			var on = (s.excluded_roles || []).indexOf(key) > -1;
			return '<label class="zt-role-chip ' + (on ? 'is-on' : '') + '"><input type="checkbox" data-role="' + esc(key) + '" ' + (on ? 'checked' : '') + '>' + esc(roles[key]) + '</label>';
		}).join('');

		app.innerHTML =
			headerHtml('Ayarlar — kayıt, saklama ve gizlilik tercihleri', navChips('settings')) +
			'<div class="zt-settings-grid zt-stagger">' +

			'<div class="zt-card" style="grid-column:1/-1"><div class="zt-card-title">' + icon('clock') + 'Saat Dilimi</div>' +
			'<div class="zt-dbinfo">' +
			'<span class="pill">Site saat dilimi: <b>' + esc(BOOT.tzLabel || '?') + ' (' + offLabel(siteOffsetSec) + ')</b></span>' +
			'<span class="pill">Tarayıcınız: <b>' + offLabel(browserOffsetSec) + '</b></span>' +
			'</div>' +
			(tzMismatch
				? '<p style="color:#fcd34d;font-size:12px;margin:10px 0 0;line-height:1.6;display:flex;gap:6px;align-items:flex-start">' + icon('alert', 14) +
				'<span>Paneldeki saatler <b>sizin saat diliminize</b> göre gösteriliyor (doğru). Ancak sunucu tarafındaki işlemler — PDF/e-posta raporu, <b>“Bugün”</b> filtresi ve günlük özetler — site saat dilimini kullanır. Tam tutarlılık için WordPress → <b>Ayarlar → Genel → Zaman Dilimi</b>\'ni <b>İstanbul</b> yapmanızı öneririm.</span></p>'
				: '<p style="color:#6ee7b7;font-size:12px;margin:10px 0 0">Site ve tarayıcı saat dilimi uyumlu — tüm saatler tutarlı.</p>') +
			'<div style="border-top:1px solid rgba(255,255,255,0.06);margin-top:14px;padding-top:14px">' +
			'<b style="font-size:12.5px;display:block;margin-bottom:8px">Sunucu Saati Doğrulama (harici kaynak)</b>' +
			'<p style="color:var(--zt-muted);font-size:12px;margin:0 0 10px;line-height:1.6">Bazı sunucularda sanallaştırma kaynaklı saat kayması olabilir. Eklenti bu yüzden kayıtları sunucunun kendi saatine değil, periyodik olarak dış kaynaklardan (Cloudflare / güvenilir HTTPS sunucuları) öğrendiği gerçek zamana göre yazar.</p>' +
			'<div id="zt-time-sync-box"></div>' +
			'<button type="button" class="zt-btn" id="zt-time-sync-now" style="margin-top:10px;min-height:32px;padding:6px 12px">' + icon('refresh') + 'Şimdi Senkronize Et</button>' +
			'</div>' +
			'</div>' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('clock') + 'Veri Saklama</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Saklama süresi</b>' +
			'<p>Bu süreden eski kayıtlar her gece otomatik silinir. Eski eklentideki "dosya şişmesi" sorunu bu sayede yaşanmaz.</p></div>' +
			'<div class="zt-set-ctl"><select class="zt-select" data-set="retention_days">' +
			retentionOpts.map(function (o) { return '<option value="' + o[0] + '" ' + (Number(s.retention_days) === o[0] ? 'selected' : '') + '>' + o[1] + '</option>'; }).join('') +
			'</select></div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Kayıt tavanı</b>' +
			'<p>Emniyet sınırı: kayıt sayısı bunu aşarsa en eskiler silinir. 0 = sınırsız.</p></div>' +
			'<div class="zt-set-ctl"><input type="number" class="zt-input" style="width:110px" min="0" step="1000" data-set="max_rows" value="' + esc(s.max_rows) + '"></div></div>' +
			'<div class="zt-dbinfo">' +
			'<span class="pill">Toplam kayıt: <b>' + n(dbInfo.rows) + '</b></span>' +
			'<span class="pill">Veritabanı boyutu: <b>' + esc(dbInfo.sizeMb) + ' MB</b></span>' +
			'</div></div>' +

			'<div class="zt-card"><div class="zt-card-title">' + icon('eye') + 'Kayıt Tercihleri</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Kayıt yöntemi</b>' +
			'<p><b>PHP:</b> sunucu tarafı, ek istek yok (varsayılan). <b>JS Beacon:</b> sayfa önbellekten sunulsa bile kaydeder — WP Super Cache, LiteSpeed, WP Rocket gibi önbellek eklentileri kullanıyorsanız önerilir.</p></div>' +
			'<div class="zt-set-ctl"><select class="zt-select" data-set="track_mode">' +
			'<option value="php"' + (s.track_mode !== 'beacon' ? ' selected' : '') + '>PHP (sunucu tarafı)</option>' +
			'<option value="beacon"' + (s.track_mode === 'beacon' ? ' selected' : '') + '>JS Beacon (önbellek uyumlu)</option>' +
			'</select></div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Botları da kaydet</b>' +
			'<p>Googlebot, tarayıcılar ve diğer botlar "bot" etiketiyle kaydedilir. Kapatırsanız hiç kaydedilmez.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('track_bots', s.track_bots) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Giriş yapmış kullanıcıları kaydet</b>' +
			'<p>Kapatırsanız yalnızca ziyaretçiler (giriş yapmamış) izlenir.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('track_logged_in', s.track_logged_in) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Hariç tutulan roller</b>' +
			'<p>Bu rollerle giriş yapmış kullanıcıların gezinmesi kaydedilmez.</p>' +
			'<div class="zt-role-grid" id="zt-roles" style="margin-top:10px">' + rolesHtml + '</div></div></div>' +
			'</div>' +

			'<div class="zt-card"><div class="zt-card-title">' + icon('pin') + 'Konum Tespiti</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>IP konumlarını çözümle</b>' +
			'<p>IP adresleri toplu olarak ip-api.com servisine sorulur (ülke, şehir, ISS) ve sitenizde önbelleklenir. Her IP yalnızca 1 kez sorgulanır.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('geo_enabled', s.geo_enabled) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Veri merkezi / proxy trafiğini bot say</b>' +
			'<p>Amazon, Google, Tencent gibi sunucu (datacenter) ve proxy/VPN IP\'leri gerçek tarayıcı gibi görünse bile "bot" olarak işaretlenir; böylece insan istatistikleri sahte trafikten temizlenir. (Konum tespiti gerektirir.)</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('flag_datacenter', s.flag_datacenter) + '</div></div>' +
			'<div class="zt-dbinfo"><span class="pill">Konum bekleyen IP: <b id="zt-geo-pending">' + n(dbInfo.pending) + '</b></span>' +
			'<button type="button" class="zt-btn" id="zt-geo-now" style="min-height:32px;padding:6px 12px">' + icon('refresh') + 'Şimdi Çözümle</button></div></div>' +

			'<div class="zt-card"><div class="zt-card-title">' + icon('mail') + 'Otomatik Rapor</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Haftalık e-posta özeti</b>' +
			'<p>Her hafta belirlenen gün, son 7 günün özetini (ziyaretçi, görüntüleme, ülkeler, sayfalar) e-posta olarak gönderir.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('weekly_report', s.weekly_report) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>PDF raporu ekle</b>' +
			'<p>E-postaya, tek sayfalık biçimlendirilmiş bir PDF raporu (istatistikler, trend, ülke/sayfa tabloları) eklenir.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('attach_pdf', s.attach_pdf) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Alıcı e-posta</b>' +
			'<p>Boş bırakılırsa site yöneticisi kullanılır' + (adminEmail ? ' (' + esc(adminEmail) + ')' : '') + '.</p></div>' +
			'<div class="zt-set-ctl"><input type="email" class="zt-input" data-set="report_email" value="' + esc(s.report_email || '') + '" placeholder="' + esc(adminEmail) + '" style="width:200px"></div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Gönderim günü</b><p>Rapor bu gün sabahı (site bakım saatinde) gönderilir.</p></div>' +
			'<div class="zt-set-ctl"><select class="zt-select" data-set="report_day">' + dayOptions + '</select></div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Deneme e-postası</b>' +
			'<p>Önce ayarları kaydedin, sonra bir test e-postası göndererek teslimatı doğrulayın.</p></div>' +
			'<div class="zt-set-ctl"><button type="button" class="zt-btn" id="zt-test-report">' + icon('mail') + 'Test Gönder</button></div></div>' +
			'</div>' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('shield') + 'Gizlilik ve Hariç Tutma</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>IP anonimleştirme (KVKK)</b>' +
			'<p>IPv4 adreslerinin son bölümü sıfırlanarak saklanır (85.102.34.0 gibi). Kişisel veri saklamayı azaltır; konum doğruluğu şehir düzeyinde kalır.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('anonymize_ip', s.anonymize_ip) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Hariç tutulan IP adresleri</b>' +
			'<p>Satır başına bir adres. Önek için * kullanın: <code style="color:#67e8f9">85.208.*</code></p>' +
			'<textarea class="zt-input zt-textarea" data-set="excluded_ips" placeholder="192.168.1.1&#10;85.208.*" style="margin-top:10px">' + esc(s.excluded_ips || '') + '</textarea></div></div>' +
			'</div>' +

			'<div class="zt-card"><div class="zt-card-title">' + icon('bell') + 'Uyarılar</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Uyarıları etkinleştir</b>' +
			'<p>Eşikler aşıldığında panel bildirim çanında uyarı (ve isteğe bağlı e-posta) oluşturur. Kurallar 5 dakikada bir, izlenen IP\'ler ziyaret anında değerlendirilir.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('alerts_enabled', s.alerts_enabled) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Yeni uyarıda e-posta gönder</b>' +
			'<p>Rapor alıcısına (yoksa site yöneticisine) e-posta gönderilir.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('alert_email', s.alert_email) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Trafik artışı eşiği</b><p>Son 1 saatteki tekil ziyaretçi bunu aşarsa uyarır. 0 = kapalı.</p></div>' +
			'<div class="zt-set-ctl"><input type="number" min="0" class="zt-input" style="width:96px" data-set="alert_spike" value="' + esc(s.alert_spike) + '"> <span style="color:#aab4c8;font-size:12px">/saat</span></div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>404 hatası eşiği</b><p>Son 1 saatteki 404 sayısı bunu aşarsa uyarır (tarama/bozuk bağlantı işareti). 0 = kapalı.</p></div>' +
			'<div class="zt-set-ctl"><input type="number" min="0" class="zt-input" style="width:96px" data-set="alert_404" value="' + esc(s.alert_404) + '"> <span style="color:#aab4c8;font-size:12px">/saat</span></div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Bot trafiği eşiği</b><p>Son 1 saatteki bot isteği bunu aşarsa uyarır. 0 = kapalı.</p></div>' +
			'<div class="zt-set-ctl"><input type="number" min="0" class="zt-input" style="width:96px" data-set="alert_bot" value="' + esc(s.alert_bot) + '"> <span style="color:#aab4c8;font-size:12px">/saat</span></div></div>' +
			'<div class="zt-set-row" style="border:0;padding-bottom:0"><div class="zt-set-info"><b>İzlenen IP adresleri</b>' +
			'<p>Bu IP\'ler siteyi ziyaret ettiğinde anında uyarı üretilir. Satır başına bir adres, * öneki desteklenir.</p>' +
			'<textarea class="zt-input zt-textarea" data-set="alert_watch_ips" placeholder="203.0.113.5&#10;198.51.*" style="margin-top:10px">' + esc(s.alert_watch_ips || '') + '</textarea></div></div>' +
			'</div>' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('activity') + 'Isı Haritası</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Tıklama/kaydırma kaydını etkinleştir</b>' +
			'<p>Ön yüze küçük bir script eklenir; ziyaretçilerin tıklama konumları ve kaydırma derinliği toplanır ve "Isı Haritası" ekranında görüntülenir.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('heatmap_enabled', s.heatmap_enabled) + '</div></div>' +
			'<div class="zt-set-row" style="border:0;padding-bottom:0"><div class="zt-set-info"><b>Örnekleme oranı</b>' +
			'<p>Ziyaretlerin yüzde kaçında kayıt yapılsın (yüksek trafikte düşürün). 100 = tümü.</p></div>' +
			'<div class="zt-set-ctl"><input type="number" min="1" max="100" class="zt-input" style="width:90px" data-set="heatmap_sample" value="' + esc(s.heatmap_sample) + '"> <span style="color:#aab4c8;font-size:12px">%</span></div></div>' +
			'</div>' +
			'<div class="zt-card"><div class="zt-card-title">' + icon('ban') + 'Erişim Engelleme</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Kara listeyi uygula</b>' +
			'<p>Açıkken, listedeki IP\'ler sitenize eriştiğinde 403 (Erişim Engellendi) alır ve artık kaydedilmez. Kapatırsanız liste saklanır ama uygulanmaz.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('block_enabled', s.block_enabled) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Engellenen IP adresleri</b>' +
			'<p>Satır başına bir adres. Önek için * kullanın: <code style="color:#67e8f9">45.146.*</code>. Ziyaretçiler ve profil ekranından tek tıkla da ekleyebilirsiniz.</p>' +
			'<textarea class="zt-input zt-textarea" data-set="blocklist" placeholder="45.146.56.1&#10;185.220.*" style="margin-top:10px">' + esc(s.blocklist || '') + '</textarea></div></div>' +
			'<div class="zt-set-row" style="border:0;padding-bottom:0"><div class="zt-set-info"><p style="color:#aab4c8;margin:0">Not: Önbellekten (cache) sunulan sayfalar WP\'ye ulaşmadan servis edildiğinden, tam engelleme için aşağıdaki sunucu düzeyi kuralları önerilir.</p></div><div></div></div>' +
			'</div>' +
			'<div class="zt-card" style="grid-column:1/-1"><div class="zt-card-title">' + icon('server') + 'Sunucu Düzeyi Engelleme' +
			'<span class="zt-title-hint">önbellekli sayfalar dahil, WP yüklenmeden engeller</span></div>' +
			'<div id="zt-rules-body"><div class="zt-skel" style="height:130px"></div></div></div>' +
			'<div class="zt-card" style="grid-column:1/-1"><div class="zt-card-title" style="color:#fca5a5">' + icon('alert') + 'Tehlikeli Bölge</div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Eklenti kaldırılırken verileri de sil</b>' +
			'<p>Açıksa, eklentiyi WordPress\'ten sildiğinizde tablolar ve ayarlar da kaldırılır.</p></div>' +
			'<div class="zt-set-ctl">' + switchHtml('uninstall_purge', s.uninstall_purge) + '</div></div>' +
			'<div class="zt-set-row"><div class="zt-set-info"><b>Tüm ziyaret verilerini sıfırla</b>' +
			'<p>Bütün ziyaret ve konum kayıtları kalıcı olarak silinir. Onaylamak için kutuya <b style="color:#fca5a5">SIFIRLA</b> yazın.</p></div>' +
			'<div class="zt-set-ctl"><input type="text" class="zt-input" id="zt-reset-confirm" placeholder="SIFIRLA" style="width:110px">' +
			'<button type="button" class="zt-btn zt-btn-danger" id="zt-reset-btn">' + icon('trash') + 'Sıfırla</button></div></div>' +
			'</div>' +

			'</div>' +
			'<div class="zt-savebar"><span class="note" id="zt-save-note">Değişiklik yok</span>' +
			'<button type="button" class="zt-btn zt-btn-primary" id="zt-save">' + icon('check') + 'Ayarları Kaydet</button></div>';

		function markDirty() {
			dirty = true;
			document.getElementById('zt-save-note').textContent = 'Kaydedilmemiş değişiklikler var';
		}

		app.querySelectorAll('[data-set]').forEach(function (el) {
			el.addEventListener('change', markDirty);
			el.addEventListener('input', markDirty);
		});

		document.getElementById('zt-roles').addEventListener('change', function (ev) {
			var input = ev.target.closest('input[data-role]');
			if (!input) { return; }
			input.closest('.zt-role-chip').classList.toggle('is-on', input.checked);
			markDirty();
		});

		document.getElementById('zt-save').addEventListener('click', function () {
			var payload = {};
			app.querySelectorAll('[data-set]').forEach(function (el) {
				var key = el.getAttribute('data-set');
				if (el.type === 'checkbox') { payload[key] = el.checked ? 1 : 0; }
				else { payload[key] = el.value; }
			});
			payload.excluded_roles = [];
			app.querySelectorAll('input[data-role]:checked').forEach(function (el) {
				payload.excluded_roles.push(el.getAttribute('data-role'));
			});

			var btn = this;
			btn.disabled = true;
			api('save_settings', { settings: JSON.stringify(payload) }).then(function (res) {
				btn.disabled = false;
				dirty = false;
				document.getElementById('zt-save-note').textContent = 'Kaydedildi ✓';
				toast(res.message || 'Ayarlar kaydedildi');
				loadRules(); // kara liste değişmiş olabilir → sunucu kurallarını tazele
			}).catch(function (err) {
				btn.disabled = false;
				toast(err.message, 'err');
			});
		});

		document.getElementById('zt-geo-now').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			api('geo_now', {}).then(function (res) {
				btn.disabled = false;
				document.getElementById('zt-geo-pending').textContent = n(res.pending);
				toast(res.done > 0 ? n(res.done) + ' IP çözümlendi' : 'Bekleyen IP yok ya da servis meşgul');
			}).catch(function (err) {
				btn.disabled = false;
				toast(err.message, 'err');
			});
		});

		/* ── Sunucu saati doğrulama ── */
		function fmtOffsetSec(sec) {
			sec = Number(sec) || 0;
			var sign = sec < 0 ? '-' : (sec > 0 ? '+' : '±');
			var abs = Math.abs(sec);
			if (abs < 60) { return sign + abs + ' sn'; }
			var m = Math.floor(abs / 60), s = abs % 60;
			if (abs < 3600) { return sign + m + ' dk' + (s ? ' ' + s + ' sn' : ''); }
			var h = Math.floor(abs / 3600), mm = Math.floor((abs % 3600) / 60);
			return sign + h + ' sa' + (mm ? ' ' + mm + ' dk' : '');
		}

		function renderTimeBox(ts) {
			ts = ts || {};
			var tsOffset = Number(ts.offset || 0);
			var tsSyncedAt = Number(ts.synced_at || 0);
			var tsSource = ts.source || '';
			var big = Math.abs(tsOffset) >= 30;
			var syncedLabel = 'Henüz senkronize edilmedi';
			if (tsSyncedAt > 0) {
				var d = new Date((tsSyncedAt + tsOffset) * 1000);
				syncedLabel = dtFmt ? dtFmt.format(d) : d.toLocaleString();
			}
			var box = document.getElementById('zt-time-sync-box');
			if (!box) { return; }
			box.innerHTML =
				'<div class="zt-dbinfo">' +
				'<span class="pill">Kaynak: <b>' + (tsSource ? esc(tsSource) : 'henüz yok') + '</b></span>' +
				'<span class="pill">Tespit edilen fark: <b style="color:' + (big ? '#fca5a5' : '#6ee7b7') + '">' + fmtOffsetSec(tsOffset) + '</b></span>' +
				'<span class="pill">Son senkron: <b>' + esc(syncedLabel) + '</b></span>' +
				'</div>' +
				(big ? '<p style="color:#fca5a5;font-size:12px;margin:10px 0 0;display:flex;gap:6px;align-items:flex-start">' + icon('alert', 14) + '<span>Sunucunuzun sistem saati harici kaynaktan ' + fmtOffsetSec(tsOffset) + ' sapmış durumda. Kayıtlar bu fark otomatik düzeltilerek yazılıyor; ek bir işlem yapmanız gerekmiyor.</span></p>' : '');
		}

		renderTimeBox(BOOT.timeStatus);

		document.getElementById('zt-time-sync-now').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			api('time_sync', {}).then(function (res) {
				btn.disabled = false;
				renderTimeBox(res.status);
				toast(res.message || 'Senkronize edildi');
			}).catch(function (err) {
				btn.disabled = false;
				toast(err.message, 'err');
			});
		});

		document.getElementById('zt-test-report').addEventListener('click', function () {
			var btn = this;
			if (dirty) {
				toast('Önce ayarları kaydedin, sonra test gönderin.', 'err');
				return;
			}
			btn.disabled = true;
			api('test_report', {}).then(function (res) {
				btn.disabled = false;
				toast(res.message || 'Test raporu gönderildi');
			}).catch(function (err) {
				btn.disabled = false;
				toast(err.message, 'err');
			});
		});

		document.getElementById('zt-reset-btn').addEventListener('click', function () {
			var val = document.getElementById('zt-reset-confirm').value.trim();
			if (val !== 'SIFIRLA') {
				toast('Onaylamak için kutuya SIFIRLA yazmalısınız', 'err');
				return;
			}
			confirmModal({
				title: 'Tüm veriler silinsin mi?',
				text: 'Bütün ziyaret kayıtları ve konum önbelleği kalıcı olarak silinecek. Bu işlem geri alınamaz.',
				okLabel: 'Evet, Hepsini Sil', danger: true
			}).then(function (ok) {
				if (!ok) { return; }
				api('reset_data', { confirm: 'SIFIRLA' }).then(function (res) {
					toast(res.message || 'Veriler silindi');
					document.getElementById('zt-reset-confirm').value = '';
				}).catch(function (err) { toast(err.message, 'err'); });
			});
		});

		/* ── Sunucu düzeyi engelleme kuralları ── */
		function snippetBlock(title, code, id) {
			return '<div class="zt-snippet"><div class="zt-snippet-head"><b>' + esc(title) + '</b>' +
				'<button type="button" class="zt-btn zt-copy" data-target="' + id + '">' + icon('copy') + 'Kopyala</button></div>' +
				'<pre class="zt-code" id="' + id + '">' + esc(code) + '</pre></div>';
		}

		function renderRules(d) {
			var body = document.getElementById('zt-rules-body');
			if (!body) { return; }
			var st = d.htaccess || {};
			var html = '<p style="color:var(--zt-muted);font-size:12px;margin:0 0 14px">Kaydedilmiş kara listenizdeki <b style="color:var(--zt-text)">' + n(d.count) + '</b> kuralı sunucu yapılandırmanıza ekleyin. Kara listeyi değiştirdikten sonra <b>Ayarları Kaydet</b>\'e basın; kurallar güncellensin.</p>';
			html += '<div class="zt-snippets">' + snippetBlock('Apache (.htaccess)', d.apache, 'zt-code-apache') + snippetBlock('Nginx (server bloğu)', d.nginx, 'zt-code-nginx') + '</div>';
			html += '<div class="zt-rules-auto"><div class="zt-rules-status">' +
				'<span class="zt-badge type" style="max-width:100%;overflow:hidden;text-overflow:ellipsis">' + esc(st.path || '?') + '</span>' +
				'<span class="zt-badge ' + (st.writable ? 'hosting' : 'proxy') + '">' + (st.writable ? 'Yazılabilir' : 'Yazılamıyor') + '</span>' +
				(st.managed ? '<span class="zt-badge geo-wait">' + icon('check') + 'Şu an yönetiliyor</span>' : '') +
				'</div>';
			if (st.writable) {
				html += '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">' +
					'<button type="button" class="zt-btn zt-btn-primary" id="zt-htaccess-apply">' + icon('server') + (st.managed ? 'Kuralları Güncelle' : '.htaccess\'e Yaz') + '</button>' +
					(st.managed ? '<button type="button" class="zt-btn zt-btn-danger" id="zt-htaccess-remove">' + icon('trash') + 'Kaldır</button>' : '') +
					'</div><p style="color:#aab4c8;font-size:11.5px;margin:8px 0 0">Kurallar WP işaretçileri arasına yazılır ve kara liste değiştikçe kayıtta otomatik güncellenir.</p>';
			} else {
				html += '<p style="color:#fca5a5;font-size:12px;margin:10px 0 0">.htaccess otomatik yazılamıyor (dosya izinleri). Yukarıdaki Apache kuralını elle ekleyebilirsiniz.</p>';
			}
			html += '</div>';
			body.innerHTML = html;

			var ap = document.getElementById('zt-htaccess-apply');
			if (ap) {
				ap.addEventListener('click', function () {
					ap.disabled = true;
					api('htaccess', { op: 'apply' }).then(function (r) { toast(r.message); renderRules(Object.assign({}, d, { htaccess: r.status })); })
						.catch(function (e) { ap.disabled = false; toast(e.message, 'err'); });
				});
			}
			var rm = document.getElementById('zt-htaccess-remove');
			if (rm) {
				rm.addEventListener('click', function () {
					rm.disabled = true;
					api('htaccess', { op: 'remove' }).then(function (r) { toast(r.message); renderRules(Object.assign({}, d, { htaccess: r.status })); })
						.catch(function (e) { rm.disabled = false; toast(e.message, 'err'); });
				});
			}
		}

		function loadRules() {
			api('block_rules').then(renderRules).catch(function () {
				var b = document.getElementById('zt-rules-body');
				if (b) { b.innerHTML = emptyHtml('Sunucu kuralları yüklenemedi'); }
			});
		}

		app.addEventListener('click', function (e) {
			var c = e.target.closest('.zt-copy');
			if (!c) { return; }
			var pre = document.getElementById(c.getAttribute('data-target'));
			if (!pre) { return; }
			var text = pre.textContent;
			function done() { toast('Panoya kopyalandı'); }
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
			} else { fallbackCopy(text, done); }
		});
		function fallbackCopy(text, done) {
			var ta = document.createElement('textarea');
			ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
			document.body.appendChild(ta); ta.select();
			try { document.execCommand('copy'); done(); } catch (e) { toast('Kopyalanamadı', 'err'); }
			ta.remove();
		}

		loadRules();
	}

	/* ═══════════════════ BAŞLAT ═══════════════════ */

	/* ═══════════════════ BİLDİRİM ÇANI ═══════════════════ */

	function initAlertsBell() {
		var bell = document.getElementById('zt-bell');
		var panel = document.getElementById('zt-bell-panel');
		var badge = document.getElementById('zt-bell-badge');
		if (!bell || !panel) { return; }
		var listEl = panel.querySelector('.zt-bell-list');
		var open = false;

		function setBadge(count) {
			count = Number(count) || 0;
			if (count > 0) {
				badge.textContent = count > 99 ? '99+' : count;
				badge.style.display = '';
				bell.classList.add('has-unseen');
			} else {
				badge.style.display = 'none';
				bell.classList.remove('has-unseen');
			}
		}

		function renderList(items) {
			if (!items || !items.length) {
				listEl.innerHTML = '<div class="zt-empty" style="padding:26px 16px">' + icon('bell') + '<div>Henüz uyarı yok</div></div>';
				return;
			}
			listEl.innerHTML = items.map(function (a) {
				var sev = a.severity === 'critical' ? 'crit' : (a.severity === 'warning' ? 'warn' : 'info');
				var ipLink = a.ip ? '<span class="zt-alert-ip" data-ip="' + esc(a.ip) + '">' + esc(a.ip) + '</span>' : '';
				return '<div class="zt-alert-item ' + (Number(a.seen) ? '' : 'is-unseen') + '">' +
					'<span class="zt-alert-dot ' + sev + '"></span>' +
					'<div class="zt-alert-body"><div class="zt-alert-title">' + esc(a.title) + '</div>' +
					'<div class="zt-alert-msg">' + esc(a.message) + '</div>' +
					'<div class="zt-alert-time">' + esc(relTime(a.created_at)) + ' ' + ipLink + '</div></div></div>';
			}).join('');
			listEl.querySelectorAll('.zt-alert-ip[data-ip]').forEach(function (el) {
				el.addEventListener('click', function () { closePanel(); openProfile(el.getAttribute('data-ip')); });
			});
		}

		function refreshBadge() {
			api('alerts').then(function (d) { setBadge(d.unseen); }).catch(function () {});
		}

		function openPanel() {
			open = true;
			panel.classList.add('is-open');
			listEl.innerHTML = '<div class="zt-skel" style="height:58px;margin:8px"></div><div class="zt-skel" style="height:58px;margin:8px"></div>';
			api('alerts').then(function (d) {
				renderList(d.items);
				if (d.unseen > 0) {
					api('alerts_seen').then(function () { setBadge(0); }).catch(function () {});
				}
			}).catch(function () { listEl.innerHTML = emptyHtml('Uyarılar yüklenemedi'); });
		}
		function closePanel() { open = false; panel.classList.remove('is-open'); }

		bell.addEventListener('click', function (e) {
			e.stopPropagation();
			if (open) { closePanel(); } else { openPanel(); }
		});
		document.addEventListener('click', function (e) {
			if (open && !panel.contains(e.target) && !bell.contains(e.target)) { closePanel(); }
		});
		panel.addEventListener('click', function (e) {
			var c = e.target.closest('#zt-alerts-clear');
			if (c) {
				api('alerts_clear').then(function () { renderList([]); setBadge(0); toast('Uyarılar temizlendi'); }).catch(function (err) { toast(err.message, 'err'); });
			}
		});

		refreshBadge();
		setInterval(function () {
			if (document.visibilityState === 'visible' && !open) { refreshBadge(); }
		}, 30000);
	}

	var page = app.getAttribute('data-page') || BOOT.page || 'dashboard';
	if (page === 'live') { initLive(); }
	else if (page === 'visits') { initVisits(); }
	else if (page === 'visitors') { initVisitors(); }
	else if (page === 'reports') { initReports(); }
	else if (page === 'funnel') { initFunnel(); }
	else if (page === 'heatmap') { initHeatmap(); }
	else if (page === 'cohort') { initCohort(); }
	else if (page === 'settings') { initSettings(); }
	else { initDashboard(); }

	initAlertsBell();
})();
