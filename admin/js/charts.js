/**
 * Ziyaretçi Takibi — hafif SVG grafik kütüphanesi.
 * Harici bağımlılık yoktur. Alan (area) ve halka (donut) grafikleri çizer.
 */
window.ZTCharts = (function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var uid = 0;

	function svgEl(tag, attrs) {
		var node = document.createElementNS(SVG_NS, tag);
		if (attrs) {
			for (var key in attrs) {
				if (Object.prototype.hasOwnProperty.call(attrs, key)) {
					node.setAttribute(key, attrs[key]);
				}
			}
		}
		return node;
	}

	function fmtNum(n) {
		try { return Number(n || 0).toLocaleString('tr-TR'); } catch (e) { return String(n); }
	}

	/** Yuvarlak "hoş" üst sınır: 1-2-5 basamakları. */
	function niceCeil(value) {
		if (value <= 4) { return 4; }
		var pow = Math.pow(10, Math.floor(Math.log10(value)));
		var base = value / pow;
		var nice;
		if (base <= 1) { nice = 1; }
		else if (base <= 2) { nice = 2; }
		else if (base <= 5) { nice = 5; }
		else { nice = 10; }
		return nice * pow;
	}

	/** Catmull-Rom → kübik bezier ile yumuşatılmış yol. */
	function smoothPath(points) {
		if (points.length === 0) { return ''; }
		if (points.length === 1) { return 'M' + points[0][0] + ',' + points[0][1]; }
		var d = 'M' + points[0][0] + ',' + points[0][1];
		for (var i = 0; i < points.length - 1; i++) {
			var p0 = points[i - 1] || points[i];
			var p1 = points[i];
			var p2 = points[i + 1];
			var p3 = points[i + 2] || p2;
			var c1x = p1[0] + (p2[0] - p0[0]) / 6;
			var c1y = p1[1] + (p2[1] - p0[1]) / 6;
			var c2x = p2[0] - (p3[0] - p1[0]) / 6;
			var c2y = p2[1] - (p3[1] - p1[1]) / 6;
			d += 'C' + c1x.toFixed(2) + ',' + c1y.toFixed(2) + ' ' + c2x.toFixed(2) + ',' + c2y.toFixed(2) + ' ' + p2[0] + ',' + p2[1];
		}
		return d;
	}

	function observeResize(container, rerender) {
		if (container.__ztObserved || typeof ResizeObserver === 'undefined') { return; }
		container.__ztObserved = true;
		var timer = null;
		var lastWidth = container.clientWidth;
		var ro = new ResizeObserver(function () {
			var w = container.clientWidth;
			if (Math.abs(w - lastWidth) < 4) { return; }
			lastWidth = w;
			clearTimeout(timer);
			timer = setTimeout(function () {
				if (container.__ztRender) { container.__ztRender(false); }
			}, 140);
		});
		ro.observe(container);
		container.__ztRO = ro;
	}

	/* ─────────────────────────────────────────────
	 * ALAN GRAFİĞİ
	 * ───────────────────────────────────────────── */
	function area(container, opts) {
		container.__ztRender = function (animate) {
			renderArea(container, opts, animate !== false && opts.animate !== false);
		};
		observeResize(container);
		container.__ztRender(true);
	}

	function renderArea(container, opts, animate) {
		container.innerHTML = '';
		container.classList.add('zt-chart-wrap');

		var labels = opts.labels || [];
		var series = opts.series || [];
		var height = opts.height || 260;
		var width = Math.max(280, container.clientWidth || 600);

		var padL = 44, padR = 14, padT = 14, padB = 26;
		var plotW = width - padL - padR;
		var plotH = height - padT - padB;
		var n = labels.length;

		var maxVal = 0;
		series.forEach(function (s) {
			(s.data || []).forEach(function (v) { if (v > maxVal) { maxVal = v; } });
		});
		var yMax = niceCeil(maxVal || 4);

		var svg = svgEl('svg', {
			width: '100%',
			height: height,
			viewBox: '0 0 ' + width + ' ' + height,
			preserveAspectRatio: 'none',
			style: 'display:block;overflow:visible;'
		});

		var defs = svgEl('defs');
		svg.appendChild(defs);

		// Yatay ızgara + Y eksen etiketleri.
		var ticks = 4;
		for (var t = 0; t <= ticks; t++) {
			var y = padT + plotH - (plotH * t / ticks);
			svg.appendChild(svgEl('line', {
				x1: padL, y1: y, x2: width - padR, y2: y,
				stroke: 'rgba(255,255,255,0.055)', 'stroke-width': 1,
				'stroke-dasharray': t === 0 ? 'none' : '3 5'
			}));
			var yText = svgEl('text', {
				x: padL - 9, y: y + 4, 'text-anchor': 'end',
				fill: '#aab4c8', 'font-size': '10.5', 'font-family': 'inherit'
			});
			yText.textContent = fmtNum(Math.round(yMax * t / ticks));
			svg.appendChild(yText);
		}

		function xAt(i) {
			return n <= 1 ? padL + plotW / 2 : padL + (plotW * i / (n - 1));
		}
		function yAt(v) {
			return padT + plotH - (plotH * (v / yMax));
		}

		// X eksen etiketleri (en fazla ~7 adet).
		var step = Math.max(1, Math.ceil(n / 7));
		for (var i = 0; i < n; i += step) {
			var xText = svgEl('text', {
				x: xAt(i), y: height - 7, 'text-anchor': 'middle',
				fill: '#aab4c8', 'font-size': '10.5', 'font-family': 'inherit'
			});
			xText.textContent = labels[i];
			svg.appendChild(xText);
		}

		var hoverDots = [];

		series.forEach(function (s, si) {
			var color = s.color || '#22d3ee';
			var pts = (s.data || []).map(function (v, idx) {
				return [xAt(idx), yAt(v || 0)];
			});
			if (pts.length === 0) { return; }

			var gid = 'zt-grad-' + (++uid);
			var grad = svgEl('linearGradient', { id: gid, x1: 0, y1: 0, x2: 0, y2: 1 });
			var stop1 = svgEl('stop', { offset: '0%', 'stop-color': color, 'stop-opacity': 0.28 });
			var stop2 = svgEl('stop', { offset: '100%', 'stop-color': color, 'stop-opacity': 0 });
			grad.appendChild(stop1);
			grad.appendChild(stop2);
			defs.appendChild(grad);

			var lineD = smoothPath(pts);
			var baseY = padT + plotH;
			var showFill = s.fill !== false;
			var dashed = !!s.dashed;

			var lineAttrs = {
				d: lineD, fill: 'none', stroke: color,
				'stroke-width': dashed ? 1.8 : 2.2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round'
			};
			if (dashed) {
				lineAttrs['stroke-dasharray'] = '5 4';
				lineAttrs['stroke-opacity'] = 0.7;
			}
			var linePath = svgEl('path', lineAttrs);

			var areaPath = null;
			if (showFill) {
				var areaD = lineD + 'L' + pts[pts.length - 1][0] + ',' + baseY + 'L' + pts[0][0] + ',' + baseY + 'Z';
				areaPath = svgEl('path', { d: areaD, fill: 'url(#' + gid + ')', stroke: 'none' });
				svg.appendChild(areaPath);
			}
			svg.appendChild(linePath);

			// Kesikli (karşılaştırma) çizgide çizim animasyonu yapma; dasharray ile çakışır.
			if (animate && !dashed) {
				if (areaPath) {
					areaPath.style.opacity = '0';
					areaPath.style.transition = 'opacity 0.7s ease 0.35s';
				}
				try {
					var len = linePath.getTotalLength();
					linePath.style.strokeDasharray = len + ' ' + len;
					linePath.style.strokeDashoffset = String(len);
					linePath.getBoundingClientRect(); // stil değişimini araya sıkıştır (reflow)
					linePath.style.transition = 'stroke-dashoffset 0.9s cubic-bezier(0.4,0,0.2,1)';
					linePath.style.strokeDashoffset = '0';
				} catch (e) { /* getTotalLength desteklenmiyorsa animasyonsuz */ }
				if (areaPath) { areaPath.style.opacity = '1'; }
			}

			// Hover noktaları (gizli başlar).
			var dots = pts.map(function (p) {
				var dot = svgEl('circle', {
					cx: p[0], cy: p[1], r: 3.6, fill: '#0b101b',
					stroke: color, 'stroke-width': 2, opacity: 0
				});
				svg.appendChild(dot);
				return dot;
			});
			hoverDots.push(dots);
		});

		// Hover kılavuz çizgisi.
		var guide = svgEl('line', {
			x1: 0, y1: padT, x2: 0, y2: padT + plotH,
			stroke: 'rgba(255,255,255,0.16)', 'stroke-width': 1, opacity: 0
		});
		svg.appendChild(guide);

		container.appendChild(svg);

		// Araç ipucu.
		var tooltip = document.createElement('div');
		tooltip.className = 'zt-chart-tooltip';
		container.appendChild(tooltip);

		var overlay = svgEl('rect', {
			x: padL, y: padT, width: plotW, height: plotH,
			fill: 'transparent', style: 'cursor:crosshair;'
		});
		svg.appendChild(overlay);

		function setHover(idx) {
			var gx = xAt(idx);
			guide.setAttribute('x1', gx);
			guide.setAttribute('x2', gx);
			guide.setAttribute('opacity', 1);

			hoverDots.forEach(function (dots) {
				dots.forEach(function (d, di) { d.setAttribute('opacity', di === idx ? 1 : 0); });
			});

			var html = '<div class="zt-tt-label">' + String(labels[idx] || '').replace(/[<>&]/g, '') + '</div>';
			series.forEach(function (s) {
				html += '<div class="zt-tt-row"><span class="zt-tt-swatch" style="background:' + (s.color || '#22d3ee') + '"></span>' +
					String(s.name || '').replace(/[<>&]/g, '') + '<b>' + fmtNum((s.data || [])[idx] || 0) + '</b></div>';
			});
			tooltip.innerHTML = html;
			tooltip.classList.add('is-on');

			var contW = container.clientWidth;
			var px = (gx / width) * contW;
			var ttW = tooltip.offsetWidth || 120;
			var left = px + 14;
			if (left + ttW > contW - 6) { left = px - ttW - 14; }
			tooltip.style.left = Math.max(2, left) + 'px';
			tooltip.style.top = '12px';
		}

		function clearHover() {
			guide.setAttribute('opacity', 0);
			hoverDots.forEach(function (dots) {
				dots.forEach(function (d) { d.setAttribute('opacity', 0); });
			});
			tooltip.classList.remove('is-on');
		}

		overlay.addEventListener('mousemove', function (ev) {
			var rect = container.getBoundingClientRect();
			var relX = (ev.clientX - rect.left) / (rect.width || 1) * width;
			var frac = (relX - padL) / (plotW || 1);
			var idx = Math.round(frac * (n - 1));
			idx = Math.max(0, Math.min(n - 1, idx));
			setHover(idx);
		});
		overlay.addEventListener('mouseleave', clearHover);
	}

	/* ─────────────────────────────────────────────
	 * HALKA GRAFİK
	 * ───────────────────────────────────────────── */
	function donut(container, opts) {
		container.__ztRender = function (animate) {
			renderDonut(container, opts, animate !== false && opts.animate !== false);
		};
		observeResize(container);
		container.__ztRender(true);
	}

	function renderDonut(container, opts, animate) {
		container.innerHTML = '';

		var items = (opts.items || []).filter(function (it) { return it.value > 0; });
		var size = opts.size || 172;
		var thickness = opts.thickness || 20;
		var total = items.reduce(function (acc, it) { return acc + it.value; }, 0);

		var flex = document.createElement('div');
		flex.className = 'zt-donut-flex';

		var holder = document.createElement('div');
		holder.style.cssText = 'position:relative;width:' + size + 'px;height:' + size + 'px;flex:none;';

		if (total === 0) {
			var empty = document.createElement('div');
			empty.className = 'zt-empty';
			empty.textContent = 'Bu aralıkta veri yok';
			container.appendChild(empty);
			return;
		}

		var svg = svgEl('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size });
		var r = (size - thickness) / 2 - 2;
		var C = 2 * Math.PI * r;
		var cx = size / 2, cy = size / 2;

		// Zemin halkası.
		svg.appendChild(svgEl('circle', {
			cx: cx, cy: cy, r: r, fill: 'none',
			stroke: 'rgba(255,255,255,0.05)', 'stroke-width': thickness
		}));

		var offset = 0;
		items.forEach(function (it) {
			var frac = it.value / total;
			var arcLen = Math.max(0, frac * C - 2.5); // dilimler arası minik boşluk
			var circle = svgEl('circle', {
				cx: cx, cy: cy, r: r, fill: 'none',
				stroke: it.color, 'stroke-width': thickness, 'stroke-linecap': 'round',
				transform: 'rotate(-90 ' + cx + ' ' + cy + ')',
				'stroke-dasharray': (animate ? 0 : arcLen) + ' ' + C,
				'stroke-dashoffset': -offset
			});
			var title = svgEl('title');
			title.textContent = it.label + ': ' + fmtNum(it.value);
			circle.appendChild(title);
			svg.appendChild(circle);
			if (animate) {
				circle.getBoundingClientRect(); // reflow
				circle.style.transition = 'stroke-dasharray 0.9s cubic-bezier(0.4,0,0.2,1)';
				circle.setAttribute('stroke-dasharray', arcLen + ' ' + C);
			}
			offset += frac * C;
		});

		holder.appendChild(svg);

		var center = document.createElement('div');
		center.className = 'zt-donut-center';
		var b = document.createElement('b');
		b.textContent = fmtNum(opts.centerValue !== undefined ? opts.centerValue : total);
		var s = document.createElement('span');
		s.textContent = opts.centerLabel || '';
		center.appendChild(b);
		center.appendChild(s);
		holder.appendChild(center);

		flex.appendChild(holder);

		var legend = document.createElement('div');
		legend.className = 'zt-donut-legend';
		items.forEach(function (it) {
			var row = document.createElement('div');
			row.className = 'row';
			var sw = document.createElement('span');
			sw.className = 'zt-legend-swatch';
			sw.style.background = it.color;
			var lbl = document.createElement('span');
			lbl.textContent = it.label;
			var pct = document.createElement('span');
			pct.className = 'pct';
			pct.textContent = Math.round(it.value / total * 100) + '%';
			var val = document.createElement('b');
			val.textContent = fmtNum(it.value);
			row.appendChild(sw);
			row.appendChild(lbl);
			row.appendChild(pct);
			row.appendChild(val);
			legend.appendChild(row);
		});
		flex.appendChild(legend);

		container.appendChild(flex);
	}

	/* ─────────────────────────────────────────────
	 * YIĞILMIŞ ÇUBUK GRAFİĞİ
	 * ───────────────────────────────────────────── */
	function stackedBars(container, opts) {
		container.__ztRender = function (animate) {
			renderStacked(container, opts, animate !== false && opts.animate !== false);
		};
		observeResize(container);
		container.__ztRender(true);
	}

	function renderStacked(container, opts, animate) {
		container.innerHTML = '';
		container.classList.add('zt-chart-wrap');

		var labels = opts.labels || [];
		var series = opts.series || [];
		var height = opts.height || 260;
		var width = Math.max(280, container.clientWidth || 600);
		var n = labels.length;

		var padL = 40, padR = 14, padT = 14, padB = 26;
		var plotW = width - padL - padR;
		var plotH = height - padT - padB;

		var totals = [];
		for (var i = 0; i < n; i++) {
			var s = 0;
			series.forEach(function (ser) { s += Number((ser.data || [])[i] || 0); });
			totals.push(s);
		}
		var yMax = niceCeil(Math.max.apply(null, totals.concat([1])));

		var svg = svgEl('svg', {
			width: '100%', height: height, viewBox: '0 0 ' + width + ' ' + height,
			preserveAspectRatio: 'none', style: 'display:block;overflow:visible;'
		});

		// Y ızgara + etiket.
		var ticks = 4;
		for (var t = 0; t <= ticks; t++) {
			var gy = padT + plotH - (plotH * t / ticks);
			svg.appendChild(svgEl('line', {
				x1: padL, y1: gy, x2: width - padR, y2: gy,
				stroke: 'rgba(255,255,255,0.055)', 'stroke-width': 1,
				'stroke-dasharray': t === 0 ? 'none' : '3 5'
			}));
			var yt = svgEl('text', { x: padL - 9, y: gy + 4, 'text-anchor': 'end', fill: '#aab4c8', 'font-size': '10.5', 'font-family': 'inherit' });
			yt.textContent = fmtNum(Math.round(yMax * t / ticks));
			svg.appendChild(yt);
		}

		var slot = plotW / Math.max(1, n);
		var barW = Math.min(38, slot * 0.62);

		function xCenter(i) { return padL + slot * i + slot / 2; }
		function yAt(v) { return padT + plotH - (plotH * (v / yMax)); }

		// X etiketleri.
		var step = Math.max(1, Math.ceil(n / 10));
		for (var xi = 0; xi < n; xi += step) {
			var xt = svgEl('text', { x: xCenter(xi), y: height - 7, 'text-anchor': 'middle', fill: '#aab4c8', 'font-size': '10.5', 'font-family': 'inherit' });
			xt.textContent = labels[xi];
			svg.appendChild(xt);
		}

		var baseY = padT + plotH;
		var rectsByIdx = [];
		for (var idx = 0; idx < n; idx++) {
			var cum = 0;
			var x = xCenter(idx) - barW / 2;
			var group = [];
			for (var si = 0; si < series.length; si++) {
				var val = Number((series[si].data || [])[idx] || 0);
				if (val <= 0) { continue; }
				var top = yAt(cum + val);
				var bottom = yAt(cum);
				var h = Math.max(0, bottom - top);
				var isTop = ( cum + val >= totals[idx] - 0.001 );
				var rect = svgEl('rect', {
					x: x, width: barW,
					y: animate ? baseY : top,
					height: animate ? 0 : h,
					fill: series[si].color || '#22d3ee',
					rx: isTop ? 3 : 0
				});
				svg.appendChild(rect);
				group.push({ rect: rect, y: top, h: h });
				cum += val;
			}
			rectsByIdx.push(group);
			if (animate) {
				(function (grp) {
					requestAnimationFrame(function () {
						grp.forEach(function (o) {
							o.rect.style.transition = 'y 0.7s cubic-bezier(0.22,0.9,0.3,1), height 0.7s cubic-bezier(0.22,0.9,0.3,1)';
							o.rect.setAttribute('y', o.y);
							o.rect.setAttribute('height', o.h);
						});
					});
				})(group);
			}
		}

		container.appendChild(svg);

		// Tooltip + hover sütunları.
		var tooltip = document.createElement('div');
		tooltip.className = 'zt-chart-tooltip';
		container.appendChild(tooltip);

		for (var hi = 0; hi < n; hi++) {
			var hx = padL + slot * hi;
			var hrect = svgEl('rect', { x: hx, y: padT, width: slot, height: plotH, fill: 'transparent', style: 'cursor:pointer;' });
			(function (index) {
				hrect.addEventListener('mouseenter', function () {
					var html = '<div class="zt-tt-label">' + String(labels[index] || '').replace(/[<>&]/g, '') + '</div>';
					series.forEach(function (ser) {
						html += '<div class="zt-tt-row"><span class="zt-tt-swatch" style="background:' + (ser.color || '#22d3ee') + '"></span>' +
							String(ser.name || '').replace(/[<>&]/g, '') + '<b>' + fmtNum((ser.data || [])[index] || 0) + '</b></div>';
					});
					html += '<div class="zt-tt-row" style="border-top:1px solid rgba(255,255,255,0.12);margin-top:4px;padding-top:4px">Toplam<b>' + fmtNum(totals[index]) + '</b></div>';
					tooltip.innerHTML = html;
					tooltip.classList.add('is-on');
					var contW = container.clientWidth;
					var px = ((padL + slot * index + slot / 2) / width) * contW;
					var ttW = tooltip.offsetWidth || 120;
					var left = px + 12;
					if (left + ttW > contW - 6) { left = px - ttW - 12; }
					tooltip.style.left = Math.max(2, left) + 'px';
					tooltip.style.top = '12px';
				});
				hrect.addEventListener('mouseleave', function () { tooltip.classList.remove('is-on'); });
			})(hi);
			svg.appendChild(hrect);
		}
	}

	/* ─────────────────────────────────────────────
	 * DÜNYA HARİTASI (choropleth)
	 * ───────────────────────────────────────────── */
	function worldMap(container, opts) {
		container.innerHTML = '';
		var world = window.ZT_WORLD;
		if (!world || !world.paths) {
			container.innerHTML = '<div class="zt-empty">Harita verisi yüklenemedi</div>';
			return;
		}

		var rows = opts.data || [];
		var byCC = {};
		var max = 0;
		rows.forEach(function (r) {
			var cc = String(r.code || '').toUpperCase();
			if (!cc) { return; }
			byCC[cc] = r;
			if (Number(r.visitors) > max) { max = Number(r.visitors); }
		});
		max = max || 1;

		var LOW = [21, 47, 71];      // veri olan düşük yoğunluk
		var HIGH = [34, 211, 238];   // yüksek yoğunluk (cyan)
		function fillFor(cc) {
			var r = byCC[cc];
			if (!r || Number(r.visitors) <= 0) { return 'rgba(255,255,255,0.045)'; }
			var t = Math.pow(Number(r.visitors) / max, 0.55);
			var c = [
				Math.round(LOW[0] + (HIGH[0] - LOW[0]) * t),
				Math.round(LOW[1] + (HIGH[1] - LOW[1]) * t),
				Math.round(LOW[2] + (HIGH[2] - LOW[2]) * t)
			];
			return 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')';
		}

		var paths = '';
		for (var cc in world.paths) {
			if (!Object.prototype.hasOwnProperty.call(world.paths, cc)) { continue; }
			var has = byCC[cc] && Number(byCC[cc].visitors) > 0;
			paths += '<path d="' + world.paths[cc] + '" data-cc="' + cc + '" fill="' + fillFor(cc) +
				'" class="zt-map-country' + (has ? ' has-data' : '') + '"></path>';
		}

		var svg = '<svg viewBox="' + world.viewBox + '" class="zt-map-svg" preserveAspectRatio="xMidYMid meet">' + paths + '</svg>';
		container.classList.add('zt-map-wrap');
		container.innerHTML = svg +
			'<div class="zt-map-tooltip"></div>' +
			'<div class="zt-map-legend"><span>az</span><i class="zt-map-ramp"></i><span>çok</span></div>';

		var svgEl2 = container.querySelector('svg');
		var tip = container.querySelector('.zt-map-tooltip');

		function flag(cc) {
			if (!cc || cc.length !== 2) { return ''; }
			try {
				return String.fromCodePoint.apply(null, cc.toUpperCase().split('').map(function (c) { return 127397 + c.charCodeAt(0); }));
			} catch (e) { return ''; }
		}

		svgEl2.addEventListener('mouseover', function (ev) {
			var t = ev.target;
			if (t.tagName !== 'path') { return; }
			t.classList.add('is-hover');
			var cc = t.getAttribute('data-cc');
			var r = byCC[cc];
			var name = r ? r.name : cc;
			var val = r ? fmtNum(r.visitors) + ' ziyaretçi · ' + fmtNum(r.views) + ' görüntüleme' : 'veri yok';
			tip.innerHTML = '<b>' + flag(cc) + ' ' + String(name).replace(/[<>&]/g, '') + '</b><span>' + val + '</span>';
			tip.classList.add('is-on');
		});
		svgEl2.addEventListener('mouseout', function (ev) {
			if (ev.target.tagName === 'path') { ev.target.classList.remove('is-hover'); }
			tip.classList.remove('is-on');
		});
		svgEl2.addEventListener('mousemove', function (ev) {
			var rect = container.getBoundingClientRect();
			var x = ev.clientX - rect.left, y = ev.clientY - rect.top;
			var tw = tip.offsetWidth || 120;
			var left = x + 14;
			if (left + tw > rect.width - 6) { left = x - tw - 14; }
			tip.style.left = Math.max(4, left) + 'px';
			tip.style.top = Math.max(4, y + 14) + 'px';
		});
		if (typeof opts.onSelect === 'function') {
			svgEl2.addEventListener('click', function (ev) {
				if (ev.target.tagName === 'path') {
					opts.onSelect(ev.target.getAttribute('data-cc'), byCC[ev.target.getAttribute('data-cc')]);
				}
			});
			svgEl2.classList.add('is-clickable');
		}
	}

	/* ─────────────────────────────────────────────
	 * CANLI HARİTA (temel harita + ping)
	 * ───────────────────────────────────────────── */
	function baseMap(container) {
		var world = window.ZT_WORLD;
		if (!world || !world.paths) {
			container.innerHTML = '<div class="zt-empty">Harita verisi yüklenemedi</div>';
			return null;
		}
		var paths = '';
		for (var cc in world.paths) {
			if (!Object.prototype.hasOwnProperty.call(world.paths, cc)) { continue; }
			paths += '<path d="' + world.paths[cc] + '" data-cc="' + cc + '" class="zt-livemap-country"></path>';
		}
		container.classList.add('zt-livemap-wrap');
		container.innerHTML = '<svg viewBox="' + world.viewBox + '" class="zt-livemap-svg" preserveAspectRatio="xMidYMid meet">' +
			paths + '<g class="zt-livemap-pings"></g></svg>';

		var svg = container.querySelector('svg');
		var layer = svg.querySelector('.zt-livemap-pings');
		var NS = 'http://www.w3.org/2000/svg';

		function project(lat, lon) {
			return { x: (lon + 180) / 360 * 1000, y: (90 - lat) / 180 * 500 };
		}

		function ping(lat, lon, cc, isBot) {
			lat = Number(lat); lon = Number(lon);
			if (!isFinite(lat) || !isFinite(lon) || (0 === lat && 0 === lon)) { return; }
			var p = project(lat, lon);
			var color = isBot ? '#fbbf24' : '#34d399';
			var g = document.createElementNS(NS, 'g');
			g.setAttribute('class', 'zt-ping');
			var ring = document.createElementNS(NS, 'circle');
			ring.setAttribute('cx', p.x); ring.setAttribute('cy', p.y); ring.setAttribute('r', '2');
			ring.setAttribute('class', 'zt-ping-ring'); ring.setAttribute('stroke', color);
			var dot = document.createElementNS(NS, 'circle');
			dot.setAttribute('cx', p.x); dot.setAttribute('cy', p.y); dot.setAttribute('r', '2.4');
			dot.setAttribute('class', 'zt-ping-dot'); dot.setAttribute('fill', color);
			g.appendChild(ring); g.appendChild(dot);
			layer.appendChild(g);

			if (cc) {
				var cel = svg.querySelector('path[data-cc="' + cc + '"]');
				if (cel) {
					cel.classList.add('is-live');
					setTimeout(function () { cel.classList.remove('is-live'); }, 2600);
				}
			}
			setTimeout(function () { if (g.parentNode) { g.parentNode.removeChild(g); } }, 2700);
		}

		return { project: project, ping: ping };
	}

	/* ─────────────────────────────────────────────
	 * ISI HARİTASI (tıklama yoğunluğu, canvas)
	 * ───────────────────────────────────────────── */
	function heatColor(t) {
		// 0..1 → mavi → yeşil → sarı → kırmızı
		var stops = [
			[0.00, [0, 150, 255]],
			[0.35, [0, 220, 130]],
			[0.65, [255, 214, 0]],
			[1.00, [255, 60, 40]]
		];
		for (var i = 1; i < stops.length; i++) {
			if (t <= stops[i][0]) {
				var a = stops[i - 1], b = stops[i];
				var f = (t - a[0]) / (b[0] - a[0] || 1);
				return [
					Math.round(a[1][0] + (b[1][0] - a[1][0]) * f),
					Math.round(a[1][1] + (b[1][1] - a[1][1]) * f),
					Math.round(a[1][2] + (b[1][2] - a[1][2]) * f)
				];
			}
		}
		return stops[stops.length - 1][1];
	}

	function heatmap(container, points, opts) {
		opts = opts || {};
		points = points || [];
		container.innerHTML = '';
		if (!points.length) {
			container.innerHTML = '<div class="zt-empty">Bu sayfa için tıklama verisi yok</div>';
			return;
		}
		var W = Math.max(240, container.clientWidth || 600);
		var H = opts.height || 560;
		var r = opts.radius || Math.max(16, Math.round(W * 0.028));

		var tmp = document.createElement('canvas');
		tmp.width = W; tmp.height = H;
		var tctx = tmp.getContext('2d');
		points.forEach(function (p) {
			var x = p[0] / 1000 * W, y = p[1] / 1000 * H;
			var g = tctx.createRadialGradient(x, y, 0, x, y, r);
			g.addColorStop(0, 'rgba(0,0,0,0.16)');
			g.addColorStop(1, 'rgba(0,0,0,0)');
			tctx.fillStyle = g;
			tctx.fillRect(x - r, y - r, r * 2, r * 2);
		});

		var canvas = document.createElement('canvas');
		canvas.width = W; canvas.height = H;
		canvas.className = 'zt-heat-canvas';
		var ctx = canvas.getContext('2d');
		var src = tctx.getImageData(0, 0, W, H).data;
		var out = ctx.createImageData(W, H);
		var o = out.data;
		for (var i = 0; i < src.length; i += 4) {
			var a = src[i + 3] / 255;
			if (a <= 0.01) { o[i + 3] = 0; continue; }
			var c = heatColor(Math.min(1, a));
			o[i] = c[0]; o[i + 1] = c[1]; o[i + 2] = c[2];
			o[i + 3] = Math.min(255, Math.round(a * 255 * 2.3));
		}
		ctx.putImageData(out, 0, 0);
		container.appendChild(canvas);

		// Derinlik kılavuzları (0/25/50/75/100%).
		[0, 25, 50, 75, 100].forEach(function (pct) {
			var ln = document.createElement('div');
			ln.className = 'zt-heat-depth';
			ln.style.top = pct + '%';
			ln.innerHTML = '<span>%' + pct + '</span>';
			container.appendChild(ln);
		});
	}

	return { area: area, donut: donut, worldMap: worldMap, stackedBars: stackedBars, baseMap: baseMap, heatmap: heatmap };
})();
