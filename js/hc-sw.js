/* Health Check – service worker. Caches only the app's own static files (js, css, img). Never pages, never /api/,
 * never health data: navigations and API calls always go to the server. */
'use strict';
var CACHE = 'hc-shell-v1';
var ASSET_RE = /\/healthcheck\/(js|css|img)\/[^?#]+\.(js|css|png|svg)$/;

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('hc-shell-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET' || req.mode === 'navigate') { return; }
  var m = /^https?:\/\/[^/]+(\/[^?#]*)/.exec(req.url);
  if (!m || req.url.indexOf(self.location.origin) !== 0) { return; }
  var path = m[1];
  if (path.indexOf('/api/') >= 0 || !ASSET_RE.test(path)) { return; }
  event.respondWith(caches.open(CACHE).then(function (cache) {
    return cache.match(req).then(function (hit) {
      var net = fetch(req).then(function (res) {
        if (res && res.ok && res.type === 'basic') { cache.put(req, res.clone()); }
        return res;
      }).catch(function () { return hit || Response.error(); });
      return hit || net;
    });
  }));
});
