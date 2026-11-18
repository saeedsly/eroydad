(function () {
  'use strict';
  var root = document.documentElement;

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    try { window.localStorage.setItem('erooydad-theme', theme); } catch (e) {}
    document.cookie = 'erooydad_theme=' + encodeURIComponent(theme) + '; path=/; max-age=31536000; samesite=lax';
    var buttons = document.querySelectorAll('[data-theme-toggle]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.setAttribute('title', theme === 'dark' ? 'حالت روشن' : 'حالت تیره');
      button.setAttribute('aria-label', theme === 'dark' ? 'فعال‌کردن حالت روشن' : 'فعال‌کردن حالت تیره');
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-theme-toggle]'), function (button) {
    button.addEventListener('click', function () {
      var current = root.getAttribute('data-theme') || 'auto';
      var next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  });

  var scaleSelect = document.querySelector('select[name="font_scale"]');
  if (scaleSelect) {
    scaleSelect.addEventListener('change', function () {
      var scale = scaleSelect.value || '100';
      root.style.setProperty('--font-scale', scale);
      try { window.localStorage.setItem('erooydad-font-scale', scale); } catch (e) {}
      document.cookie = 'erooydad_font_scale=' + encodeURIComponent(scale) + '; path=/; max-age=31536000; samesite=lax';
    });
  }
})();
