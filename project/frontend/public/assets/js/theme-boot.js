/** theme-boot.js — اعمال فوری تم و اندازهٔ قلم پیش از رندر (مطابق CSP: فقط فایل‌های self). */
(function () {
  try {
    var stored = localStorage.getItem('erooydad-theme');
    if (stored) { document.documentElement.setAttribute('data-theme', stored); }
    var scale = localStorage.getItem('erooydad-font-scale');
    if (scale) { document.documentElement.style.setProperty('--font-scale', scale); }
  } catch (e) {}
})();
