/**
 * effects.js — progressive visual polish (Erooydad).
 * Only ADDS classes when JS is available: without this file every page
 * renders fully visible (no content ever depends on it).
 * - Reveal-on-scroll for main sections/cards with a gentle stagger.
 * - Header elevation once the page is scrolled.
 * - Honors prefers-reduced-motion: does nothing for those users.
 */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var root = document.documentElement;

  function onScroll() {
    var header = document.querySelector('.site-header');
    if (!header) return;
    header.classList.toggle('is-scrolled', (window.scrollY || 0) > 8);
  }

  if (reduce) {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return;
  }

  root.classList.add('fx-ready');

  var targets = document.querySelectorAll(
    '.section-head, .event-card, .stat-card, .content-card, .signup-card, ' +
    '.auth-card, .notification-item, .session-item, .instructor-card, ' +
    '.support-form-card, .detail-main, .about-copy, .faq-grid details, ' +
    '.empty-box, .ticket-print, .price-box'
  );

  if ('IntersectionObserver' in window) {
    var index = 0;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('fx-in');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.06 });

    targets.forEach(function (el, i) {
      el.classList.add('fx-reveal');
      el.style.setProperty('--fx-delay', ((i % 4) * 70) + 'ms');
      index = i;
      observer.observe(el);
    });
    // Anything already in view on load reveals immediately (no flash of hidden content).
    window.requestAnimationFrame(function () {
      targets.forEach(function (el) {
        var rect = el.getBoundingClientRect();
        if (rect.top < (window.innerHeight || 0) * 0.94) el.classList.add('fx-in');
      });
    });
  }

  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
})();
