/* سرویس‌ورکر محلی ای‌رویداد — فقط دارایی‌های همین سامانه را کش می‌کند.
   همهٔ مسیرها نسبت به محل خود سرویس‌ورکر محاسبه می‌شوند تا نصب در
   زیرپوشه (مثلاً htdocs/eroydad) نیز درست کار کند. */
var CACHE_NAME = 'erooydad-static-v1';
var BASE_URL = new URL('./', self.location).href;          /* مثال: /eroydad/ */
var BASE_PATH = new URL(BASE_URL).pathname;                  /* مثال: /eroydad/ */
var OFFLINE_URLS = [
  'assets/css/app.css',
  'assets/css/design-system.css',
  'assets/fonts/vazirmatn-variable.woff2',
  'assets/js/menu.js',
  'assets/js/theme.js',
  'assets/js/pwa.js'
].map(function (path) { return BASE_URL + path.replace(/^\/+/, ''); });

function isInternalReserved(pathname) {
  return pathname.indexOf(BASE_PATH + 'admin/') === 0
    || pathname.indexOf(BASE_PATH + 'account') === 0
    || pathname.indexOf(BASE_PATH + 'login') === 0
    || pathname.indexOf(BASE_PATH + 'register') === 0
    || pathname.indexOf(BASE_PATH + 'api/') === 0;
}


self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      // «./» صفحهٔ شروع است و برای حالت آفلاین کش می‌شود.
      return cache.addAll([BASE_URL].concat(OFFLINE_URLS)).catch(function () { return null; });
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        return key === CACHE_NAME ? null : caches.delete(key);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;
  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  // مسیرهای نشسته/حساس کش نمی‌شوند و صفحهٔ جایگزین هم نمی‌گیرند.
  if (isInternalReserved(url.pathname)) return;
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(function () {
        return caches.match(request).then(function (cached) {
          return cached || caches.match(BASE_URL);
        });
      })
    );
    return;
  }
  event.respondWith(
    caches.match(request).then(function (cached) {
      var network = fetch(request).then(function (response) {
        if (response && response.status === 200 && response.type === 'basic') {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
        }
        return response;
      }).catch(function () { return cached; });
      return cached || network;
    })
  );
});
