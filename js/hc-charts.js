/* Health Check – plain SVG charts: rings, smooth lines with moving average, personal normal band and anomaly marks,
 * bars (stacked), sleep window (hypnogram style), donut, heatmap, progress. Tap/hover tooltips, CSS animations. */
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
  /* drawing width: narrow phones get a narrower canvas so labels stay readable after scaling */
  function cw() { try { return root.innerWidth && root.innerWidth < 560 ? 380 : 640; } catch (e) { return 640; } }
  function svg(w, h, cls, label) {
    return s('svg', { viewBox: '0 0 ' + w + ' ' + h, class: 'hc-svg ' + (cls || ''), role: 'img', 'aria-label': label || '', preserveAspectRatio: 'xMidYMid meet' });
  }
  function empty(w, W, H, text) {
    w.appendChild(s('rect', { x: 8, y: 8, width: W - 16, height: H - 16, rx: 14, class: 'hc-empty-box' }));
    w.appendChild(s('text', { x: W / 2, y: H / 2 - 6, 'text-anchor': 'middle', class: 'hc-empty-ico', text: '◌' }));
    w.appendChild(s('text', { x: W / 2, y: H / 2 + 22, 'text-anchor': 'middle', class: 'hc-empty', text: text || '–' }));
    return w;
  }

  /* ---------- statistics helpers (pure) ---------- */
  function meanSd(vals) {
    var v = vals.filter(function (x) { return x !== null && x !== undefined && !isNaN(x); });
    if (v.length < 2) { return null; }
    var m = v.reduce(function (a, b) { return a + b; }, 0) / v.length;
    var sd = Math.sqrt(v.reduce(function (a, b) { return a + (b - m) * (b - m); }, 0) / (v.length - 1));
    return { mean: m, sd: sd, n: v.length };
  }
  /* trailing moving average over calendar days */
  function movingAvg(pts, days) {
    days = days || 7;
    return pts.map(function (p, i) {
      var t = Date.parse(p.x), acc = 0, n = 0;
      for (var j = i; j >= 0; j--) {
        if (t - Date.parse(pts[j].x) > (days - 1) * 864e5) { break; }
        acc += pts[j].y; n++;
      }
      return { x: p.x, y: acc / n };
    });
  }
  /* smooth path through points (monotone-ish cardinal spline, tension 0.5) */
  function smoothPath(xy) {
    if (xy.length < 3) { return xy.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' '); }
    var d = 'M' + xy[0][0].toFixed(1) + ' ' + xy[0][1].toFixed(1);
    for (var i = 0; i < xy.length - 1; i++) {
      var p0 = xy[i - 1] || xy[i], p1 = xy[i], p2 = xy[i + 1], p3 = xy[i + 2] || p2, k = 0.5 / 3;
      var c1 = [p1[0] + (p2[0] - p0[0]) * k, p1[1] + (p2[1] - p0[1]) * k], c2 = [p2[0] - (p3[0] - p1[0]) * k, p2[1] - (p3[1] - p1[1]) * k];
      // keep control points between the two y values: no overshoot above/below real data
      c1[1] = Math.max(Math.min(p1[1], p2[1]), Math.min(Math.max(p1[1], p2[1]), c1[1]));
      c2[1] = Math.max(Math.min(p1[1], p2[1]), Math.min(Math.max(p1[1], p2[1]), c2[1]));
      d += ' C' + c1[0].toFixed(1) + ' ' + c1[1].toFixed(1) + ' ' + c2[0].toFixed(1) + ' ' + c2[1].toFixed(1) + ' ' + p2[0].toFixed(1) + ' ' + p2[1].toFixed(1);
    }
    return d;
  }

  /* ---------- tooltip: nearest point to the pointer, works with mouse and touch ---------- */
  function tooltip(w, W, H, P, items) {
    if (!items.length) { return; }
    var g = s('g', { class: 'hc-tip', visibility: 'hidden' });
    var ln = s('line', { y1: P.t, y2: H - P.b, class: 'hc-tip-line' });
    var dot = s('circle', { r: 5, class: 'hc-tip-dot' });
    var box = s('rect', { rx: 8, height: 0, width: 0, class: 'hc-tip-box' });
    var t1 = s('text', { class: 'hc-tip-t1' }), t2 = s('text', { class: 'hc-tip-t2' });
    g.appendChild(ln); g.appendChild(dot); g.appendChild(box); g.appendChild(t1); g.appendChild(t2);
    w.appendChild(g);
    var hit = s('rect', { x: P.l, y: 0, width: W - P.l - P.r, height: H, fill: 'transparent', class: 'hc-hit' });
    w.appendChild(hit);
    function show(ev) {
      var r = w.getBoundingClientRect();
      var cx = ev.touches ? ev.touches[0].clientX : ev.clientX;
      var x = (cx - r.left) / r.width * W, best = items[0];
      items.forEach(function (it) { if (Math.abs(it.px - x) < Math.abs(best.px - x)) { best = it; } });
      ln.setAttribute('x1', best.px); ln.setAttribute('x2', best.px);
      dot.setAttribute('cx', best.px); dot.setAttribute('cy', best.py); dot.setAttribute('fill', best.color || 'currentColor');
      t1.textContent = best.l1; t2.textContent = best.l2 || '';
      var bw = Math.max(best.l1.length, (best.l2 || '').length) * 7.2 + 18, bh = best.l2 ? 44 : 26;
      var bx = Math.min(W - P.r - bw, Math.max(P.l, best.px - bw / 2)), by = Math.max(2, Math.min(best.py - bh - 12, H - P.b - bh));
      if (by < 2 || best.py - bh - 12 < 0) { by = Math.min(H - bh - 2, best.py + 14); }
      box.setAttribute('x', bx); box.setAttribute('y', by); box.setAttribute('width', bw); box.setAttribute('height', bh);
      t1.setAttribute('x', bx + 9); t1.setAttribute('y', by + 17); t2.setAttribute('x', bx + 9); t2.setAttribute('y', by + 35);
      g.setAttribute('visibility', 'visible');
    }
    function hide() { g.setAttribute('visibility', 'hidden'); }
    hit.addEventListener('pointermove', show);
    hit.addEventListener('pointerdown', show);
    hit.addEventListener('touchmove', function (e) { show(e); }, { passive: true });
    hit.addEventListener('pointerleave', hide);
  }

  /* activity ring: score 0..100 (null = no data) */
  function ring(score, color, size, sub) {
    size = size || 120;
    var r = size / 2 - 10, c = 2 * Math.PI * r, w = svg(size, size, 'hc-ring');
    var gid = 'g' + Math.random().toString(36).slice(2, 8);
    var defs = s('defs', {}, [s('linearGradient', { id: gid, x1: 0, y1: 0, x2: 1, y2: 1 }, [s('stop', { offset: '0%', 'stop-color': color, 'stop-opacity': 0.75 }), s('stop', { offset: '100%', 'stop-color': color })])]);
    w.appendChild(defs);
    w.appendChild(s('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: color, 'stroke-opacity': 0.16, 'stroke-width': 14 }));
    var arc = s('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: 'url(#' + gid + ')', 'stroke-width': 14, 'stroke-linecap': 'round',
      'stroke-dasharray': c.toFixed(1), 'stroke-dashoffset': c.toFixed(1), transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')', class: 'hc-ring-arc' });
    w.appendChild(arc);
    w.appendChild(s('text', { x: size / 2, y: size / 2 + (sub ? 2 : 10), 'text-anchor': 'middle', class: 'hc-ring-num', fill: color, text: score === null || score === undefined ? '–' : score }));
    if (sub) { w.appendChild(s('text', { x: size / 2, y: size / 2 + 22, 'text-anchor': 'middle', class: 'hc-ring-sub', text: sub })); }
    var target = score === null || score === undefined ? c : c * (1 - Math.max(0, Math.min(100, score)) / 100);
    root.requestAnimationFrame(function () { root.requestAnimationFrame(function () { arc.setAttribute('stroke-dashoffset', target.toFixed(1)); }); });
    return w;
  }

  function niceRange(min, max) {
    if (min === max) { min -= 1; max += 1; }
    var pad = (max - min) * 0.12;
    return [min - pad, max + pad];
  }
  function axis(w, W, H, P, lo, hi, fmt, xs, X) {
    [0, 0.5, 1].forEach(function (f) {
      var v = lo + (hi - lo) * f, y = H - P.b - f * (H - P.t - P.b);
      w.appendChild(s('line', { x1: P.l, x2: W - P.r, y1: y, y2: y, class: f === 0 ? 'hc-grid' : 'hc-grid hc-grid-soft' }));
      w.appendChild(s('text', { x: P.l - 6, y: y + 4, 'text-anchor': 'end', class: 'hc-ax', text: fmt(v) }));
    });
    if (xs && xs.length) {
      var n = Math.min(5, xs.length), used = {};
      for (var i = 0; i < n; i++) {
        var idx = n === 1 ? 0 : Math.round(i * (xs.length - 1) / (n - 1));
        if (used[idx]) { continue; }
        used[idx] = 1;
        w.appendChild(s('text', { x: X(xs[idx], idx), y: H - 6, 'text-anchor': i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle', class: 'hc-ax', text: HC.shortDay(xs[idx]) }));
      }
    }
  }

  /* line chart
   * pts [{x:iso, y}]; opts {color, fmt, unit, label, empty, band:[lo,hi]|'auto', ma: days|0, smooth, markers:[iso], markerLabel, anomalies: bool, min, max, dots} */
  function line(pts, opts) {
    opts = opts || {};
    var W = cw(), H = 220, P = { l: 50, r: 12, t: 14, b: 28 }, w = svg(W, H, 'hc-line', opts.label);
    if (!pts.length) { return empty(w, W, H, opts.empty); }
    var fmt = opts.fmt || function (v) { return HC.num(v, 0); };
    var band = opts.band;
    var stat = null;
    if (band === 'auto') {
      stat = meanSd(pts.map(function (p) { return p.y; }));
      band = stat && pts.length >= 10 ? [stat.mean - stat.sd, stat.mean + stat.sd] : null;
    }
    var ma = opts.ma && pts.length >= 10 ? movingAvg(pts, opts.ma) : null;
    var ys = pts.map(function (p) { return p.y; });
    if (band) { ys = ys.concat(band); }
    if (opts.goal !== undefined && opts.goal !== null) { ys.push(opts.goal); }
    var r = niceRange(Math.min.apply(null, ys), Math.max.apply(null, ys));
    var t0 = Date.parse(pts[0].x), t1 = Date.parse(pts[pts.length - 1].x), span = Math.max(1, t1 - t0);
    var X = function (iso) { return P.l + (pts.length === 1 ? 0.5 : (Date.parse(iso) - t0) / span) * (W - P.l - P.r); };
    var Y = function (v) { return H - P.b - (v - r[0]) / (r[1] - r[0]) * (H - P.t - P.b); };
    axis(w, W, H, P, r[0], r[1], fmt, pts.map(function (p) { return p.x; }), function (x) { return X(x); });
    if (band) {
      w.appendChild(s('rect', { x: P.l, width: W - P.l - P.r, y: Y(band[1]), height: Math.max(1, Y(band[0]) - Y(band[1])), class: 'hc-band', fill: opts.color }));
    }
    if (opts.goal !== undefined && opts.goal !== null) {
      w.appendChild(s('line', { x1: P.l, x2: W - P.r, y1: Y(opts.goal), y2: Y(opts.goal), class: 'hc-target' }));
      w.appendChild(s('text', { x: W - P.r, y: Y(opts.goal) - 5, 'text-anchor': 'end', class: 'hc-ax', text: (opts.goalLabel || '') + ' ' + fmt(opts.goal) }));
    }
    (opts.markers || []).forEach(function (iso) {
      var t = Date.parse(iso);
      if (t < t0 || t > t1) { return; }
      w.appendChild(s('path', { d: 'M' + X(iso).toFixed(1) + ' ' + (H - P.b) + ' l-4 7 h8 z', class: 'hc-marker' }, [s('title', { text: HC.day(iso) + ' · ' + (opts.markerLabel || '') })]));
    });
    var xy = pts.map(function (p) { return [X(p.x), Y(p.y)]; });
    var main = opts.smooth === false ? xy.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ') : smoothPath(xy);
    if (pts.length > 1) {
      var gid = 'a' + Math.random().toString(36).slice(2, 8);
      w.appendChild(s('defs', {}, [s('linearGradient', { id: gid, x1: 0, y1: 0, x2: 0, y2: 1 }, [s('stop', { offset: '0%', 'stop-color': opts.color, 'stop-opacity': 0.28 }), s('stop', { offset: '100%', 'stop-color': opts.color, 'stop-opacity': 0 })])]));
      w.appendChild(s('path', { d: main + ' L' + xy[xy.length - 1][0].toFixed(1) + ' ' + (H - P.b) + ' L' + xy[0][0].toFixed(1) + ' ' + (H - P.b) + ' Z', fill: 'url(#' + gid + ')', class: 'hc-area' }));
    }
    w.appendChild(s('path', { d: main, fill: 'none', stroke: opts.color, 'stroke-width': ma ? 1.4 : 2.4, 'stroke-opacity': ma ? 0.55 : 1, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', class: 'hc-path', pathLength: 1 }));
    if (ma) {
      w.appendChild(s('path', { d: smoothPath(ma.map(function (p) { return [X(p.x), Y(p.y)]; })), fill: 'none', stroke: opts.color, 'stroke-width': 3, 'stroke-linecap': 'round', class: 'hc-path hc-ma', pathLength: 1 }));
    }
    if (opts.dots || pts.length <= 40) {
      xy.forEach(function (p) { w.appendChild(s('circle', { cx: p[0], cy: p[1], r: pts.length <= 40 ? 2.6 : 1.6, fill: opts.color, class: 'hc-dot' })); });
    }
    if (opts.anomalies && band) {
      pts.forEach(function (p, i) {
        if (p.y > band[1] + (band[1] - band[0]) * 0.5 || p.y < band[0] - (band[1] - band[0]) * 0.5) {
          w.appendChild(s('circle', { cx: xy[i][0], cy: xy[i][1], r: 5.5, class: 'hc-anom' }, [s('title', { text: HC.day(p.x) + ': ' + fmt(p.y) })]));
        }
      });
    }
    var last = pts[pts.length - 1];
    w.appendChild(s('circle', { cx: X(last.x), cy: Y(last.y), r: 4.5, fill: opts.color, class: 'hc-last' }));
    var maAt = {};
    (ma || []).forEach(function (p) { maAt[p.x] = p.y; });
    tooltip(w, W, H, P, pts.map(function (p, i) {
      return { px: xy[i][0], py: xy[i][1], color: opts.color, l1: HC.day(p.x) + ' · ' + fmt(p.y) + (opts.unit ? ' ' + opts.unit : ''),
        l2: ma ? (HC.t ? HC.t('ma_short', { n: opts.ma }) : 'MA') + ' ' + fmt(maAt[p.x]) + (p.n > 1 ? ' · n=' + p.n : '') : (p.n > 1 ? 'n=' + p.n : '') };
    }));
    return w;
  }

  /* bars; pts [{x, y}] or stacked [{x, parts:[v...]}] with opts.colors / opts.names */
  function bars(pts, opts) {
    opts = opts || {};
    var W = cw(), H = 220, P = { l: 50, r: 12, t: 14, b: 28 }, w = svg(W, H, 'hc-bars', opts.label);
    if (!pts.length) { return empty(w, W, H, opts.empty); }
    var tot = pts.map(function (p) { return p.parts ? p.parts.reduce(function (a, b) { return a + (b || 0); }, 0) : p.y; });
    var max = Math.max.apply(null, tot.concat([opts.target || 0])) * 1.1 || 1;
    var fmt = opts.fmt || function (v) { return HC.num(v, 0); };
    var bw = (W - P.l - P.r) / pts.length, gap = Math.min(4, bw * 0.22);
    axis(w, W, H, P, 0, max, fmt, pts.map(function (p) { return p.x; }), function (x, i) { return P.l + i * bw + bw / 2; });
    var Y = function (v) { return (H - P.t - P.b) * v / max; };
    pts.forEach(function (p, i) {
      var x = P.l + i * bw + gap / 2, y = H - P.b;
      (p.parts || [p.y]).forEach(function (v, j) {
        var hh = Y(v || 0);
        if (hh <= 0) { return; }
        y -= hh;
        var top = j === (p.parts || [p.y]).length - 1 || !(p.parts || [])[j + 1];
        var rect = s('rect', { x: x.toFixed(1), y: y.toFixed(1), width: Math.max(1, bw - gap).toFixed(1), height: hh.toFixed(1), rx: top ? Math.min(4, bw / 3) : 0, fill: (opts.colors || [opts.color])[j], class: 'hc-bar' });
        rect.style.animationDelay = Math.min(600, i * 8) + 'ms';
        w.appendChild(rect);
      });
      if (opts.highlight && opts.highlight(p)) { w.appendChild(s('circle', { cx: x + (bw - gap) / 2, cy: y - 7, r: 3, class: 'hc-anom-dot' })); }
    });
    if (opts.target) {
      var ty = H - P.b - Y(opts.target);
      w.appendChild(s('line', { x1: P.l, x2: W - P.r, y1: ty, y2: ty, class: 'hc-target' }));
      w.appendChild(s('text', { x: W - P.r, y: ty - 5, 'text-anchor': 'end', class: 'hc-ax', text: (opts.targetLabel || '') + ' ' + fmt(opts.target) }));
    }
    tooltip(w, W, H, P, pts.map(function (p, i) {
      var total = tot[i], l2 = '';
      if (p.parts && opts.names) { l2 = p.parts.map(function (v, j) { return v ? opts.names[j] + ' ' + fmt(v) : ''; }).filter(Boolean).join(' · '); }
      return { px: P.l + i * bw + bw / 2, py: H - P.b - Y(total), color: (opts.colors || [opts.color])[0], l1: HC.day(p.x) + ' · ' + fmt(total), l2: l2 };
    }));
    return w;
  }

  /* sleep window ("hypnogram style"): one bar per night from falling asleep to waking up on a clock axis 20:00 → 12:00,
   * filled by the share of deep / core / REM / awake. rows need sleep_start, sleep_end (unix), stage minutes. */
  function sleepWindow(rows, opts) {
    opts = opts || {};
    var W = cw(), H = 240, P = { l: 50, r: 12, t: 12, b: 28 }, w = svg(W, H, 'hc-sleepwin', opts.label);
    var nights = rows.filter(function (r) { return r.sleep_start && r.sleep_end && r.sleep_total; });
    if (!nights.length) { return empty(w, W, H, opts.empty); }
    var clock = function (ts) { var d = new Date(ts * 1000), m = d.getHours() * 60 + d.getMinutes() - 20 * 60; return m < 0 ? m + 1440 : m; }; // minutes after 20:00
    var lo = 0, hi = 16 * 60; // 20:00 .. 12:00
    var Y = function (m) { return P.t + Math.max(0, Math.min(hi, m)) / hi * (H - P.t - P.b); };
    [0, 4, 8, 12, 16].forEach(function (hh) {
      var y = Y(hh * 60), label = ((20 + hh) % 24) + ':00';
      w.appendChild(s('line', { x1: P.l, x2: W - P.r, y1: y, y2: y, class: 'hc-grid hc-grid-soft' }));
      w.appendChild(s('text', { x: P.l - 6, y: y + 4, 'text-anchor': 'end', class: 'hc-ax', text: label }));
    });
    var bw = (W - P.l - P.r) / nights.length, gap = Math.min(4, bw * 0.25);
    var cols = opts.colors || {};
    nights.forEach(function (r, i) {
      var a = clock(r.sleep_start), b = a + (r.sleep_end - r.sleep_start) / 60;
      var x = P.l + i * bw + gap / 2, y0 = Y(a), y1 = Y(b), total = (r.sleep_deep || 0) + (r.sleep_core || 0) + (r.sleep_rem || 0) + (r.sleep_awake || 0);
      var g = s('g', { class: 'hc-night' });
      g.style.animationDelay = Math.min(700, i * 10) + 'ms';
      if (!total) {
        g.appendChild(s('rect', { x: x, y: y0, width: Math.max(1, bw - gap), height: Math.max(1, y1 - y0), rx: 3, fill: cols.core || '#888' }));
      } else {
        // stages are not timed in the export: show them as shares, deep first (typical early in the night)
        var yy = y0;
        [['deep', r.sleep_deep], ['core', r.sleep_core], ['rem', r.sleep_rem], ['awake', r.sleep_awake]].forEach(function (st) {
          if (!st[1]) { return; }
          var hh = (y1 - y0) * st[1] / total;
          g.appendChild(s('rect', { x: x, y: yy, width: Math.max(1, bw - gap), height: Math.max(0.5, hh), fill: cols[st[0]] }));
          yy += hh;
        });
      }
      w.appendChild(g);
    });
    tooltip(w, W, H, P, nights.map(function (r, i) {
      return { px: P.l + i * bw + bw / 2, py: Y(clock(r.sleep_start)), color: cols.core, l1: HC.day(r.day) + ' · ' + HC.hm(r.sleep_total), l2: HC.clock(r.sleep_start) + '–' + HC.clock(r.sleep_end) + (r.sleep_deep ? ' · ' + (HC.t ? HC.t('stage_deep') : 'deep') + ' ' + HC.hm(r.sleep_deep) : '') };
    }));
    var xs = nights.map(function (r) { return r.day; });
    [0, xs.length - 1].forEach(function (idx, k) { w.appendChild(s('text', { x: k ? W - P.r : P.l, y: H - 6, 'text-anchor': k ? 'end' : 'start', class: 'hc-ax', text: HC.shortDay(xs[idx]) })); });
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
      w.appendChild(s('circle', { cx: size / 2, cy: size / 2, r: r, fill: 'none', stroke: p.color, 'stroke-width': 18, 'stroke-dasharray': Math.max(0, len - 2).toFixed(1) + ' ' + c.toFixed(1),
        'stroke-dashoffset': (-off).toFixed(1), transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')', class: 'hc-donut-arc' }));
      off += len;
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
    w.style.maxWidth = Math.round(W * 1.5) + 'px';
    (labels || []).forEach(function (l, i) { if (i % 2 === 0) { w.appendChild(s('text', { x: 0, y: 18 + i * (cs + gap) + cs - 3, class: 'hc-ax', text: l })); } });
    var lastMonth = '';
    cells.forEach(function (c, i) {
      var k = i + offset, col = Math.floor(k / 7), row = k % 7, x = 26 + col * (cs + gap), y = 18 + row * (cs + gap);
      var m = c.day.slice(0, 7);
      if ((row === 0 || i === 0) && m !== lastMonth && c.day.slice(8) <= '07') { w.appendChild(s('text', { x: x, y: 10, class: 'hc-ax', text: HC.shortMonth ? HC.shortMonth(c.day) : m })); lastMonth = m; }
      var rect = s('rect', { x: x, y: y, width: cs, height: cs, rx: 3, fill: c.level === null ? 'transparent' : colors[c.level], class: 'hc-cell' + (c.level === null ? ' hc-cell-empty' : '') });
      rect.style.animationDelay = Math.min(900, i * 3) + 'ms';
      rect.appendChild(s('title', { text: HC.day(c.day) + (c.steps !== null ? ' · ' + HC.num(c.steps, 0) + ' ' + (HC.t ? HC.t('u_steps') : '') : '') + (c.workout_min ? ' · ' + c.workout_min + ' min' : '') }));
      w.appendChild(rect);
    });
    return w;
  }

  /* horizontal bars for small comparisons (e.g. weekday means): items [{label, v, color}] */
  function hbars(items, fmt) {
    var W = cw(), rowH = 26, H = items.length * rowH + 6, w = svg(W, H, 'hc-hbars');
    var max = Math.max.apply(null, items.map(function (i) { return i.v || 0; })) || 1;
    items.forEach(function (it, i) {
      var y = 3 + i * rowH, len = (W - 150) * (it.v || 0) / max;
      w.appendChild(s('text', { x: 0, y: y + 16, class: 'hc-ax hc-ax-l', text: it.label }));
      var r = s('rect', { x: 60, y: y + 3, width: Math.max(1, len), height: rowH - 9, rx: 5, fill: it.color, class: 'hc-hbar' });
      r.style.animationDelay = i * 40 + 'ms';
      w.appendChild(r);
      w.appendChild(s('text', { x: 66 + len, y: y + 16, class: 'hc-ax', text: it.v === null ? '–' : fmt(it.v) }));
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

  HC.charts = { ring: ring, line: line, bars: bars, sleepWindow: sleepWindow, donut: donut, heatmap: heatmap, hbars: hbars, progress: progress, s: s, svg: svg, meanSd: meanSd, movingAvg: movingAvg, smoothPath: smoothPath };
  if (typeof module === 'object' && module.exports) { module.exports = { meanSd: meanSd, movingAvg: movingAvg, smoothPath: smoothPath }; }
})(typeof self !== 'undefined' ? self : this);
