(function () {
  'use strict';
  var container = document.getElementById('liveCount');
  if (!container) return;
  var url = container.getAttribute('data-url');
  if (!url) return;

  function toPersian(value) {
    return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
  }

  function refresh() {
    window.fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data) return;
        Array.prototype.forEach.call(container.querySelectorAll('[data-live]'), function (node) {
          var key = node.getAttribute('data-live');
          if (data[key] !== undefined) node.textContent = toPersian(data[key]);
        });
      })
      .catch(function () {});
  }

  // تازه‌سازی فقط وقتی صفحه دیده می‌شود؛ در پس‌زمینه شبکه و CPU هدر نمی‌رود.
  var timer = null;
  function start() { if (!timer) timer = window.setInterval(refresh, 15000); }
  function stop() { if (timer) { window.clearInterval(timer); timer = null; } }

  refresh();
  start();
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop();
    else { refresh(); start(); }
  });
})();
