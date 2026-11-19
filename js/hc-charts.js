/* Health Check – plain SVG charts (rings, line, bars, stacked bars, donut, heatmap). Animated with CSS on insertion. */
(function (root) {
  'use strict';
  var HC = root.HC = root.HC || {};
  var NS = 'http://www.w3.org/2000/svg';

  function s(tag, attrs, children) {
    var el = root.document.createElementNS(NS, tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (attrs[k] === null || attrs[k] === undefined) { return; }
      if (k === 'text') { el.textContent = String(attrs[k]); } else { el.setAttribute(k, String(attrs[k])); }
    });
    (children || []).forEach(function (c) { if (c) { el.appendChild(c); } });
    return el;
  }
  function svg(w, h, cls, label) {
    return s('svg', { viewBox: '0 0 ' + w + ' ' + h, class: 'hc-svg ' + (cls || ''), role: 'img', 'aria-label': label || '', preserveAspectRatio: 'xMidYMid meet' });
  }

  /* activity ring: score 0..100 (null = no data) */
  function ring(score, color, size, sub) {
    size = size || 120;
    var r = size / 2 - 10, c = 2 * Math.PI * r, w = svg(size, size, 'hc-ring');
    w.appendChild(s('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: color, 'stroke-opacity': 0.18, 'stroke-width': 14 }));
    var arc = s('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: color, 'stroke-width': 14, 'stroke-linecap': 'round',
      'stroke-dasharray': c.toFixed(1), 'stroke-dashoffset': c.toFixed(1), transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')', class: 'hc-ring-arc' });
    w.appendChild(arc);
    w.appendChild(s('text', { x: size / 2, y: size / 2 + (sub ? 2 : 9), 'text-anchor': 'middle', class: 'hc-ring-num', fill: color, text: score === null || score === undefined ? '–' : score }));
    if (sub) { w.appendChild(s('text', { x: size / 2, y: size / 2 + 22, 'text-anchor': 'middle', class: 'hc-ring-sub', text: sub })); }
    var target = score === null || score === undefined ? c : c * (1 - Math.max(0, Math.min(100, score)) / 100);
    root.requestAnimationFrame(function () { root.requestAnimationFrame(function () { arc.setAttribute('stroke-dashoffset', target.toFixed(1)); }); });
    return w;
  }

  function niceRange(min, max) {
    if (min === max) { min -= 1; max += 1; }
    var pad = (max - min) * 0.1;
    return [min - pad, max + pad];
  }
  function axisLabels(g, W, H, P, lo, hi, fmt, first, last) {
    g.appendChild(s('text', { x: P.l - 6, y: P.t + 4, 'text-anchor': 'end', class: 'hc-ax', text: fmt(hi) }));
    g.appendChild(s('text', { x: P.l - 6, y: H - P.b, 'text-anchor': 'end', class: 'hc-ax', text: fmt(lo) }));
    g.appendChild(s('line', { x1: P.l, x2: W - P.r, y1: H - P.b, y2: H - P.b, class: 'hc-grid' }));
    g.appendChild(s('line', { x1: P.l, x2: W - P.r, y1: P.t, y2: P.t, class: 'hc-grid hc-grid-soft' }));
    if (first) { g.appendChild(s('text', { x: P.l, y: H - 4, class: 'hc-ax', text: first })); }
    if (last) { g.appendChild(s('text', { x: W - P.r, y: H - 4, 'text-anchor': 'end', class: 'hc-ax', text: last })); }
  }

  /* line chart; pts [{x:iso, y}], opts {color, fmt, band:[lo,hi], label, min, max} */
  function line(pts, opts) {
    opts = opts || {};
    var W = 640, H = 200, P = { l: 52, r: 10, t: 12, b: 26 }, w = svg(W, H, 'hc-line', opts.label);
    if (!pts.length) { w.appendChild(s('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'hc-empty', text: opts.empty || '–' })); return w; }
    var ys = pts.map(function (p) { return p.y; });
    if (opts.band) { ys = ys.concat(opts.band); }
    var r = niceRange(opts.min !== undefined ? Math.min(opts.min, Math.min.apply(null, ys)) : Math.min.apply(null, ys), opts.max !== undefined ? Math.max(opts.max, Math.max.apply(null, ys)) : Math.max.apply(null, ys));
    var t0 = Date.parse(pts[0].x), t1 = Date.parse(pts[pts.length - 1].x), span = Math.max(1, t1 - t0);
    var X = function (iso) { return P.l + (Date.parse(iso) - t0) / span * (W - P.l - P.r); };
    var Y = function (v) { return H - P.b - (v - r[0]) / (r[1] - r[0]) * (H - P.t - P.b); };
    var fmt = opts.fmt || function (v) { return HC.num(v, 0); };
    axisLabels(w, W, H, P, r[0], r[1], fmt, HC.shortDay(pts[0].x), HC.shortDay(pts[pts.length - 1].x));
    if (opts.band) {
      w.appendChild(s('rect', { x: P.l, width: W - P.l - P.r, y: Y(opts.band[1]), height: Math.max(1, Y(opts.band[0]) - Y(opts.band[1])), class: 'hc-band', fill: opts.color }));
    }
    var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + X(p.x).toFixed(1) + ' ' + Y(p.y).toFixed(1); }).join(' ');
    if (pts.length > 1) {
      var area = d + ' L' + X(pts[pts.length - 1].x).toFixed(1) + ' ' + (H - P.b) + ' L' + X(pts[0].x).toFixed(1) + ' ' + (H - P.b) + ' Z';
      w.appendChild(s('path', { d: area, fill: opts.color, class: 'hc-area' }));
    }
    w.appendChild(s('path', { d: d, fill: 'none', stroke: opts.color, 'stroke-width': 2.2, 'stroke-linejoin': 'round', class: 'hc-path', pathLength: 1 }));
    var last = pts[pts.length - 1];
    w.appendChild(s('circle', { cx: X(last.x), cy: Y(last.y), r: 4, fill: opts.color }));
    w.appendChild(s('text', { x: Math.min(W - P.r - 2, X(last.x)), y: Math.max(P.t + 10, Y(last.y) - 8), 'text-anchor': 'end', class: 'hc-val', fill: opts.color, text: fmt(last.y) }));
    return w;
  }

  /* bars; pts [{x, y}] or stacked [{x, parts:[v...]}] with opts.colors */
  function bars(pts, opts) {
    opts = opts || {};
    var W = 640, H = 200, P = { l: 52, r: 10, t: 12, b: 26 }, w = svg(W, H, 'hc-bars', opts.label);
    if (!pts.length) { w.appendChild(s('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'hc-empty', text: opts.empty || '–' })); return w; }
    var tot = pts.map(function (p) { return p.parts ? p.parts.reduce(function (a, b) { return a + (b || 0); }, 0) : p.y; });
    var max = Math.max.apply(null, tot.concat([opts.target || 0])) * 1.08 || 1;
    var fmt = opts.fmt || function (v) { return HC.num(v, 0); };
    axisLabels(w, W, H, P, 0, max, fmt, HC.shortDay(pts[0].x), HC.shortDay(pts[pts.length - 1].x));
    var bw = (W - P.l - P.r) / pts.length, gap = Math.min(3, bw * 0.2);
    var Y = function (v) { return (H - P.t - P.b) * v / max; };
    pts.forEach(function (p, i) {
      var x = P.l + i * bw + gap / 2, y = H - P.b;
      var parts = p.parts || [p.y];
      parts.forEach(function (v, j) {
        var hh = Y(v || 0);
        if (hh <= 0) { return; }
        y -= hh;
        var rect = s('rect', { x: x.toFixed(1), y: y.toFixed(1), width: Math.max(1, bw - gap).toFixed(1), height: hh.toFixed(1), rx: Math.min(3, bw / 4), fill: (opts.colors || [opts.color])[j], class: 'hc-bar' });
        rect.style.animationDelay = Math.min(600, i * 8) + 'ms';
        rect.appendChild(s('title', { text: HC.day(p.x) + ': ' + fmt(v) }));
        w.appendChild(rect);
      });
    });
    if (opts.target) {
      var ty = H - P.b - Y(opts.target);
      w.appendChild(s('line', { x1: P.l, x2: W - P.r, y1: ty, y2: ty, class: 'hc-target' }));
      w.appendChild(s('text', { x: W - P.r, y: ty - 4, 'text-anchor': 'end', class: 'hc-ax', text: fmt(opts.target) }));
    }
    return w;
  }

  /* donut of shares [{v, color}] */
  function donut(parts, size, center) {
    size = size || 140;
    var r = size / 2 - 14, c = 2 * Math.PI * r, w = svg(size, size, 'hc-donut'), off = 0;
    var tot = parts.reduce(function (a, p) { return a + (p.v || 0); }, 0) || 1;
    w.appendChild(s('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: 'currentColor', 'stroke-opacity': 0.08, 'stroke-width': 18 }));
    parts.forEach(function (p) {
      var len = c * (p.v || 0) / tot;
      var arc = s('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: p.color, 'stroke-width': 18, 'stroke-dasharray': len.toFixed(1) + ' ' + c.toFixed(1),
        'stroke-dashoffset': (-off).toFixed(1), transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')', class: 'hc-donut-arc' });
      off += len;
      w.appendChild(arc);
    });
    if (center) { w.appendChild(s('text', { x: size / 2, y: size / 2 + 6, 'text-anchor': 'middle', class: 'hc-donut-c', text: center })); }
    return w;
  }

  /* GitHub-style heatmap; cells [{day, level 0..4|null, steps, workout_min}] */
  function heatmap(cells, colors, labels) {
    var cs = 13, gap = 3, first = cells.length ? cells[0].day : null;
    var offset = first ? (new Date(first + 'T12:00:00Z').getUTCDay() + 6) % 7 : 0;
    var cols = Math.ceil((cells.length + offset) / 7);
    var W = 26 + cols * (cs + gap), H = 7 * (cs + gap) + 18, w = svg(W, H, 'hc-heat');
    w.style.maxWidth = Math.round(W * 1.5) + 'px'; // short periods must not be blown up to full width
    (labels || []).forEach(function (l, i) { if (i % 2 === 0) { w.appendChild(s('text', { x: 0, y: 18 + i * (cs + gap) + cs - 3, class: 'hc-ax', text: l })); } });
    var lastMonth = '';
    cells.forEach(function (c, i) {
      var k = i + offset, col = Math.floor(k / 7), row = k % 7, x = 26 + col * (cs + gap), y = 18 + row * (cs + gap);
      var m = c.day.slice(0, 7);
      if (row === 0 || i === 0) {
        if (m !== lastMonth && c.day.slice(8) <= '07') { w.appendChild(s('text', { x: x, y: 10, class: 'hc-ax', text: HC.shortMonth ? HC.shortMonth(c.day) : m })); lastMonth = m; }
      }
      var rect = s('rect', { x: x, y: y, width: cs, height: cs, rx: 3, fill: c.level === null ? 'transparent' : colors[c.level], class: 'hc-cell' + (c.level === null ? ' hc-cell-empty' : '') });
      rect.style.animationDelay = Math.min(900, i * 3) + 'ms';
      rect.appendChild(s('title', { text: HC.day(c.day) + (c.steps !== null ? ' · ' + HC.num(c.steps, 0) + ' ' + (HC.t ? HC.t('u_steps') : '') : '') + (c.workout_min ? ' · ' + c.workout_min + ' min' : '') }));
      w.appendChild(rect);
    });
    return w;
  }

  /* horizontal progress bar */
  function progress(value, target, color) {
    var p = target > 0 ? Math.max(0, Math.min(1, value / target)) : 0;
    var bar = root.document.createElement('div'); bar.className = 'hc-prog';
    var fill = root.document.createElement('div'); fill.className = 'hc-prog-fill'; fill.style.background = color; fill.style.width = '0%';
    bar.appendChild(fill);
    root.requestAnimationFrame(function () { root.requestAnimationFrame(function () { fill.style.width = (p * 100).toFixed(1) + '%'; }); });
    return bar;
  }

  HC.charts = { ring: ring, line: line, bars: bars, donut: donut, heatmap: heatmap, progress: progress, s: s, svg: svg };
})(typeof self !== 'undefined' ? self : this);
