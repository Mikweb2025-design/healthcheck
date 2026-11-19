/* Health Check – core helpers: DOM, API, formatting, aggregation. No framework. Pure parts are exported for node tests. */
(function (root) {
  'use strict';
  var HC = root.HC = root.HC || {};

  /* ---------- formatting ---------- */
  function lang() { return (HC.i18n && HC.i18n.lang()) || 'en'; }
  function num(v, dec, l) {
    if (v === null || v === undefined || isNaN(v)) { return '–'; }
    l = l || lang();
    var s = Number(v).toFixed(dec || 0);
    var parts = s.split('.');
    var sep = l === 'en' ? ',' : '.';
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, sep);
    return parts.length > 1 ? parts[0] + (l === 'en' ? '.' : ',') + parts[1] : parts[0];
  }
  /* minutes -> "7 h 12" (compact) or "7 h 12 min" */
  function hm(min, long) {
    if (min === null || min === undefined || isNaN(min)) { return '–'; }
    min = Math.round(min);
    var neg = min < 0; min = Math.abs(min);
    var h = Math.floor(min / 60), m = min % 60;
    var s = h > 0 ? h + ' h ' + (m < 10 ? '0' : '') + m + (long ? ' min' : '') : m + ' min';
    return (neg ? '−' : '') + s;
  }
  function pct(fraction, dec) { return fraction === null || fraction === undefined ? '–' : num(fraction * 100, dec === undefined ? 1 : dec) + ' %'; }
  function day(iso, l) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) { return iso || '–'; }
    l = l || lang();
    return l === 'en' ? iso : iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4);
  }
  function shortDay(iso, l) {
    if (!iso) { return ''; }
    l = l || lang();
    return l === 'en' ? iso.slice(5) : iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.';
  }
  function dateTime(ts, l) {
    if (!ts) { return '–'; }
    var d = new Date(ts * 1000);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    var iso = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    return day(iso, l) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function clock(ts) {
    if (!ts) { return '–'; }
    var d = new Date(ts * 1000);
    return (d.getHours() < 10 ? '0' : '') + d.getHours() + ':' + (d.getMinutes() < 10 ? '0' : '') + d.getMinutes();
  }
  /* signed difference with unit, e.g. "+3,2 bpm" */
  function signed(v, dec, unit) {
    if (v === null || v === undefined || isNaN(v)) { return '–'; }
    var s = (v > 0 ? '+' : v < 0 ? '−' : '±') + num(Math.abs(v), dec);
    return unit ? s + ' ' + unit : s;
  }
  function addDays(iso, n) {
    var d = new Date(iso + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }

  /* ---------- aggregation for charts ---------- */
  function mondayOf(iso) {
    var d = new Date(iso + 'T12:00:00Z'); var dow = (d.getUTCDay() + 6) % 7;
    return addDays(iso, -dow);
  }
  /* rows -> [{x, y}] for one column; bucket: 'day' | 'week' | 'month' (average of days with data) */
  function series(rows, col, bucket, scale) {
    scale = scale || 1;
    var out = [], acc = {}, order = [];
    rows.forEach(function (r) {
      var v = r[col];
      if (v === null || v === undefined) { return; }
      var key = bucket === 'week' ? mondayOf(r.day) : bucket === 'month' ? r.day.slice(0, 7) + '-01' : r.day;
      if (!acc[key]) { acc[key] = [0, 0]; order.push(key); }
      acc[key][0] += Number(v); acc[key][1]++;
    });
    order.forEach(function (k) { out.push({ x: k, y: acc[k][0] / acc[k][1] * scale, n: acc[k][1] }); });
    return out;
  }
  function bucketFor(days) { return days <= 120 ? 'day' : days <= 800 ? 'week' : 'month'; }
  function avg(a) { var v = a.filter(function (x) { return x !== null && x !== undefined && !isNaN(x); }); return v.length ? v.reduce(function (s, x) { return s + Number(x); }, 0) / v.length : null; }

  /* ---------- DOM ---------- */
  function h(tag, attrs, children) {
    var el = root.document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) { return; }
        if (k === 'text') { el.textContent = String(v); } else if (k === 'class') { el.className = v; } else if (k.indexOf('on') === 0 && typeof v === 'function') { el.addEventListener(k.slice(2), v); } else { el.setAttribute(k, v === true ? '' : String(v)); }
      });
    }
    (Array.isArray(children) ? children : children === undefined ? [] : [children]).forEach(function (c) {
      if (c === null || c === undefined || c === false) { return; }
      el.appendChild(typeof c === 'string' || typeof c === 'number' ? root.document.createTextNode(String(c)) : c);
    });
    return el;
  }
  function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }

  /* ---------- API ---------- */
  function rootEl() { return root.document.getElementById('hc-root'); }
  function base() { var el = rootEl(); return (el && el.getAttribute('data-base')) || '/apps/healthcheck'; }
  function token() { var el = rootEl(); return (el && el.getAttribute('data-requesttoken')) || ''; }
  function ApiError(status, code, data) { this.status = status; this.code = code; this.data = data; this.message = code; }
  ApiError.prototype = Object.create(Error.prototype);
  function api(method, path, body, query) {
    var qs = query ? '?' + Object.keys(query).filter(function (k) { return query[k] !== null && query[k] !== undefined && query[k] !== ''; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); }).join('&') : '';
    var init = { method: method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', requesttoken: token() } };
    if (body !== undefined) { init.body = JSON.stringify(body); init.headers['Content-Type'] = 'application/json'; }
    return root.fetch(base() + path + qs, init).then(function (res) {
      return res.text().then(function (txt) {
        var data = null; try { data = txt ? JSON.parse(txt) : {}; } catch (e) { data = null; }
        if (res.ok && data !== null) { return data; }
        if (res.status === 401 || (data === null && res.redirected)) { root.location.reload(); }
        throw new ApiError(res.status, (data && data.error) || 'http_' + res.status, data);
      });
    }, function () { throw new ApiError(0, 'offline'); });
  }
  /* WebDAV upload of a file into the user's import folder */
  function upload(file, onProgress) {
    var el = rootEl();
    var dav = el.getAttribute('data-dav');
    var name = 'Export-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.zip';
    return new Promise(function (resolve, reject) {
      var x = new root.XMLHttpRequest();
      x.open('PUT', dav + '/Health/Import/' + encodeURIComponent(name));
      x.setRequestHeader('requesttoken', token());
      x.withCredentials = true;
      x.upload.onprogress = function (e) { if (e.lengthComputable && onProgress) { onProgress(e.loaded / e.total); } };
      x.onload = function () { if (x.status >= 200 && x.status < 300) { resolve(name); } else { reject(new ApiError(x.status, 'upload_failed')); } };
      x.onerror = function () { reject(new ApiError(0, 'offline')); };
      x.send(file);
    });
  }

  function store(op, key, val) {
    try {
      if (op === 'get') { return root.localStorage.getItem(key); }
      if (op === 'set') { root.localStorage.setItem(key, val); }
    } catch (e) { /* private mode */ }
    return null;
  }

  /* platform detection (pure) for the install hint */
  function detect(ua, platform, touch) {
    ua = String(ua || ''); platform = String(platform || ''); touch = Number(touch) || 0;
    var ios = /iPhone|iPad|iPod/.test(ua) || (platform === 'MacIntel' && touch > 1);
    return { ios: ios, android: /Android/i.test(ua), safari: ios && !/CriOS|FxiOS|EdgiOS/.test(ua) && /Safari/.test(ua) };
  }
  function standalone() {
    try { return root.navigator.standalone === true || root.matchMedia('(display-mode: standalone)').matches; } catch (e) { return false; }
  }

  var api_ = { num: num, hm: hm, pct: pct, day: day, shortDay: shortDay, dateTime: dateTime, clock: clock, signed: signed, addDays: addDays,
    mondayOf: mondayOf, series: series, bucketFor: bucketFor, avg: avg, detect: detect };
  Object.keys(api_).forEach(function (k) { HC[k] = api_[k]; });
  HC.h = h; HC.clear = clear; HC.api = api; HC.upload = upload; HC.store = store; HC.standalone = standalone; HC.ApiError = ApiError; HC.rootEl = rootEl;
  if (typeof module === 'object' && module.exports) { module.exports = api_; }
})(typeof self !== 'undefined' ? self : this);
