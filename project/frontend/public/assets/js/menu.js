(function () {
  'use strict';
  var button = document.querySelector('.menu-toggle');
  var menu = document.querySelector('#primary-navigation');
  var scrim = document.querySelector('[data-menu-scrim]');
  var closeButton = document.querySelector('[data-menu-close]');
  if (!button || !menu || !scrim) return;

  function setOpen(open, returnFocus) {
    menu.classList.toggle('open', open);
    menu.setAttribute('aria-hidden', open ? 'false' : 'true');
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.setAttribute('aria-label', open ? 'بستن منو' : 'باز کردن منو');
    scrim.hidden = !open;
    document.body.classList.toggle('menu-open', open);
    if (open) {
      var first = menu.querySelector('button:not([disabled]),a[href]');
      if (first) first.focus();
    } else if (returnFocus) {
      button.focus();
    }
  }

  button.addEventListener('click', function () {
    setOpen(button.getAttribute('aria-expanded') !== 'true', false);
  });
  if (closeButton) closeButton.addEventListener('click', function () { setOpen(false, true); });
  scrim.addEventListener('click', function () { setOpen(false, true); });
  menu.addEventListener('click', function (event) {
    var target = event.target;
    while (target && target !== menu && target.tagName !== 'A') target = target.parentNode;
    if (target && target.tagName === 'A') setOpen(false, false);
  });
  document.addEventListener('click', function (event) {
    if (button.getAttribute('aria-expanded') === 'true' && !button.contains(event.target) && !menu.contains(event.target)) setOpen(false, false);
    var target = event.target;
    while (target && target !== document && !target.getAttribute('data-confirm')) target = target.parentNode;
    if (target && target !== document && !window.confirm(target.getAttribute('data-confirm'))) event.preventDefault();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
      setOpen(false, true);
      return;
    }
    if (event.key !== 'Tab' || button.getAttribute('aria-expanded') !== 'true') return;
    var focusable = menu.querySelectorAll('a[href],button:not([disabled])');
    if (!focusable.length) return;
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
})();
