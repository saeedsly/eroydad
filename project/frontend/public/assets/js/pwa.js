/* pwa.js — ثبت سرویس‌ورکر.
   مسیرها از پیوند manifest صفحه خوانده می‌شوند تا نصب در زیرپوشه
   (مثلاً http://localhost/eroydad/) نیز درست کار کند. */
(function () {
  'use strict';

  function appBaseUrl() {
    try {
      var link = document.querySelector('link[rel="manifest"]');
      if (link && link.getAttribute('href')) {
        return new URL(link.getAttribute('href'), window.location.href).href.replace(/[^/]*$/, '');
      }
    } catch (e) { /* ignore */ }
    return window.location.pathname.replace(/[^/]*$/, '');
  }

  if (!('serviceWorker' in navigator)) return;

  window.addEventListener('load', function () {
    var base = appBaseUrl();
    navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(function () {
      /* ثبت سرویس‌ورکر اختیاری است و نبود آن خطایی ایجاد نمی‌کند. */
    });
  });
})();
