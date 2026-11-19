(function (root) {
  'use strict';

  var monthNames = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  var persianDigits = '۰۱۲۳۴۵۶۷۸۹';
  var arabicDigits = '٠١٢٣٤٥٦٧٨٩';

  function mod(a, b) { return a - Math.floor(a / b) * b; }
  function isGregorianLeap(year) { return year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0); }

  function jalaliToGregorian(jy, jm, jd) {
    var y = jy + 1595;
    var days = -355668 + 365 * y + Math.floor(y / 33) * 8 + Math.floor((mod(y, 33) + 3) / 4) + jd;
    days += jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186;
    var gy = 400 * Math.floor(days / 146097);
    days = mod(days, 146097);
    if (days > 36524) {
      gy += 100 * Math.floor((--days) / 36524);
      days = mod(days, 36524);
      if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days = mod(days, 1461);
    if (days > 365) {
      gy += Math.floor((days - 1) / 365);
      days = mod(days - 1, 365);
    }
    var gd = days + 1;
    var monthDays = [0,31,isGregorianLeap(gy) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
    var gm = 1;
    while (gm <= 12 && gd > monthDays[gm]) { gd -= monthDays[gm]; gm++; }
    return { year: gy, month: gm, day: gd };
  }

  function gregorianToJalali(gy, gm, gd) {
    var year = gy - 1600;
    var month = gm - 1;
    var day = gd - 1;
    var monthDays = [31,28,31,30,31,30,31,31,30,31,30,31];
    var dayNo = 365 * year + Math.floor((year + 3) / 4) - Math.floor((year + 99) / 100) + Math.floor((year + 399) / 400);
    for (var i = 0; i < month; i++) dayNo += monthDays[i];
    if (month > 1 && isGregorianLeap(gy)) dayNo++;
    dayNo += day;
    var jalaliDayNo = dayNo - 79;
    var cycle = Math.floor(jalaliDayNo / 12053);
    jalaliDayNo = mod(jalaliDayNo, 12053);
    var jy = 979 + 33 * cycle + 4 * Math.floor(jalaliDayNo / 1461);
    jalaliDayNo = mod(jalaliDayNo, 1461);
    if (jalaliDayNo >= 366) {
      jy += Math.floor((jalaliDayNo - 1) / 365);
      jalaliDayNo = mod(jalaliDayNo - 1, 365);
    }
    var jm, jd;
    if (jalaliDayNo < 186) {
      jm = 1 + Math.floor(jalaliDayNo / 31);
      jd = 1 + mod(jalaliDayNo, 31);
    } else {
      jalaliDayNo -= 186;
      jm = 7 + Math.floor(jalaliDayNo / 30);
      jd = 1 + mod(jalaliDayNo, 30);
    }
    return { year: jy, month: jm, day: jd };
  }

  function daysInJalaliMonth(year, month) {
    if (month <= 6) return 31;
    if (month <= 11) return 30;
    var current = jalaliToGregorian(year, 1, 1);
    var next = jalaliToGregorian(year + 1, 1, 1);
    return (Date.UTC(next.year, next.month - 1, next.day) - Date.UTC(current.year, current.month - 1, current.day)) / 86400000 === 366 ? 30 : 29;
  }

  function latinDigits(value) {
    return String(value).replace(/[۰-۹٠-٩]/g, function (digit) {
      var code = digit.charCodeAt(0);
      return String(code >= 0x6f0 && code <= 0x6f9 ? code - 0x6f0 : code - 0x660);
    });
  }

  function faDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) { return persianDigits[Number(digit)]; });
  }

  function parseDate(value) {
    var normalized = latinDigits(value).trim().replace(/[.\-]/g, '/');
    if (/^\d{8}$/.test(normalized)) normalized = normalized.slice(0, 4) + '/' + normalized.slice(4, 6) + '/' + normalized.slice(6);
    var match = normalized.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/);
    if (!match) return null;
    var year = Number(match[1]), month = Number(match[2]), day = Number(match[3]);
    if (year < 1200 || year > 1600 || month < 1 || month > 12 || day < 1 || day > daysInJalaliMonth(year, month)) return null;
    return { year: year, month: month, day: day };
  }

  function formatDate(date) {
    return faDigits(String(date.year).padStart(4, '0') + '/' + String(date.month).padStart(2, '0') + '/' + String(date.day).padStart(2, '0'));
  }

  var api = {
    jalaliToGregorian: jalaliToGregorian,
    gregorianToJalali: gregorianToJalali,
    daysInJalaliMonth: daysInJalaliMonth,
    parseDate: parseDate,
    formatDate: formatDate,
    latinDigits: latinDigits
  };
  root.EroydadJalali = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof document === 'undefined') return;

  function todayJalali() {
    var now = new Date();
    try {
      var parts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
      var values = {};
      parts.forEach(function (part) { values[part.type] = part.value; });
      return gregorianToJalali(Number(values.year), Number(values.month), Number(values.day));
    } catch (error) {
      return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    }
  }

  document.querySelectorAll('[data-jalali-picker]').forEach(function (field) {
    var input = field.querySelector('[data-jalali-date]');
    var time = field.querySelector('[data-jalali-time]');
    var hidden = field.querySelector('[data-gregorian-datetime]');
    var error = field.querySelector('[data-date-error]');
    var calendar = field.querySelector('[data-calendar]');
    var toggle = field.querySelector('[data-calendar-toggle]');
    var title = field.querySelector('[data-month-title]');
    var days = field.querySelector('[data-calendar-days]');
    var month = todayJalali();
    if (!input || !time || !hidden || !calendar || !toggle || !title || !days) return;

    function setError(message) {
      if (error) error.textContent = message || '';
      input.setAttribute('aria-invalid', message ? 'true' : 'false');
      input.setCustomValidity(message || '');
    }

    function syncHidden() {
      var date = parseDate(input.value);
      var hm = time.value.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
      if (!date || !hm) {
        hidden.value = '';
        if (!input.value.trim()) setError('');
        else if (!date) setError('تاریخ شمسی را مانند ۱۴۰۵/۰۸/۲۴ وارد کنید.');
        else setError('');
        return false;
      }
      var gregorian = jalaliToGregorian(date.year, date.month, date.day);
      hidden.value = String(gregorian.year).padStart(4, '0') + '-' + String(gregorian.month).padStart(2, '0') + '-' + String(gregorian.day).padStart(2, '0') + 'T' + String(hm[1]).padStart(2, '0') + ':' + hm[2];
      setError('');
      return true;
    }

    function updateMonth(date) { month = { year: date.year, month: date.month, day: 1 }; renderCalendar(); }

    function renderCalendar() {
      title.textContent = faDigits(String(month.year)) + ' ' + monthNames[month.month - 1];
      days.textContent = '';
      var first = jalaliToGregorian(month.year, month.month, 1);
      var weekday = new Date(Date.UTC(first.year, first.month - 1, first.day)).getUTCDay();
      var offset = (weekday + 1) % 7; // Persian week begins Saturday.
      for (var blank = 0; blank < offset; blank++) {
        var spacer = document.createElement('span');
        spacer.className = 'jalali-calendar-empty';
        spacer.setAttribute('aria-hidden', 'true');
        days.appendChild(spacer);
      }
      var selected = parseDate(input.value);
      var count = daysInJalaliMonth(month.year, month.month);
      for (var day = 1; day <= count; day++) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'jalali-calendar-day';
        button.textContent = faDigits(String(day));
        button.setAttribute('aria-label', faDigits(day + ' ' + monthNames[month.month - 1] + ' ' + month.year));
        if (selected && selected.year === month.year && selected.month === month.month && selected.day === day) {
          button.classList.add('is-selected');
          button.setAttribute('aria-pressed', 'true');
        }
        button.addEventListener('click', function (chosenDay) {
          return function () {
            input.value = formatDate({ year: month.year, month: month.month, day: chosenDay });
            syncHidden();
            closeCalendar();
            input.focus();
          };
        }(day));
        days.appendChild(button);
      }
    }

    function openCalendar() {
      var selected = parseDate(input.value);
      if (selected) month = { year: selected.year, month: selected.month, day: 1 };
      renderCalendar();
      calendar.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
    }

    function closeCalendar() {
      calendar.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }

    field.querySelector('[data-month-prev]').addEventListener('click', function () {
      month.month--;
      if (month.month < 1) { month.month = 12; month.year--; }
      renderCalendar();
    });
    field.querySelector('[data-month-next]').addEventListener('click', function () {
      month.month++;
      if (month.month > 12) { month.month = 1; month.year++; }
      renderCalendar();
    });
    toggle.addEventListener('click', function () { calendar.hidden ? openCalendar() : closeCalendar(); });
    input.addEventListener('focus', function () {
      var selected = parseDate(input.value);
      if (selected) updateMonth(selected);
    });
    input.addEventListener('input', function () {
      var date = parseDate(input.value);
      if (date) {
        input.value = formatDate(date);
        updateMonth(date);
      }
      syncHidden();
    });
    input.addEventListener('blur', function () {
      var date = parseDate(input.value);
      if (date) input.value = formatDate(date);
      syncHidden();
    });
    time.addEventListener('input', syncHidden);
    time.addEventListener('change', syncHidden);
    renderCalendar();
    syncHidden();
  });

  document.addEventListener('click', function (event) {
    document.querySelectorAll('[data-jalali-picker]').forEach(function (field) {
      var calendar = field.querySelector('[data-calendar]');
      var toggle = field.querySelector('[data-calendar-toggle]');
      if (calendar && !field.contains(event.target)) {
        calendar.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
      }
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[data-jalali-picker] [data-calendar]:not([hidden])').forEach(function (calendar) {
      calendar.hidden = true;
      var toggle = calendar.parentNode.querySelector('[data-calendar-toggle]');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    });
  });

  document.querySelectorAll('form[data-jalali-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var valid = true;
      form.querySelectorAll('[data-jalali-picker]').forEach(function (field) {
        var input = field.querySelector('[data-jalali-date]');
        var time = field.querySelector('[data-jalali-time]');
        var hidden = field.querySelector('[data-gregorian-datetime]');
        var date = input && parseDate(input.value);
        var hm = time && time.value.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
        if (date && hm && hidden) {
          var g = jalaliToGregorian(date.year, date.month, date.day);
          hidden.value = String(g.year).padStart(4, '0') + '-' + String(g.month).padStart(2, '0') + '-' + String(g.day).padStart(2, '0') + 'T' + String(hm[1]).padStart(2, '0') + ':' + hm[2];
          input.setCustomValidity('');
          input.setAttribute('aria-invalid', 'false');
        } else {
          valid = false;
          if (input) {
            input.setCustomValidity('تاریخ شمسی و ساعت را بررسی کنید.');
            input.reportValidity();
          }
        }
      });
      if (!valid) event.preventDefault();
    });
  });
})(typeof window !== 'undefined' ? window : globalThis);
