'use strict';

const assert = require('node:assert/strict');
const jalali = require('../frontend/public/assets/js/jalali-date-picker.js');

assert.deepEqual(jalali.jalaliToGregorian(1403, 1, 1), { year: 2024, month: 3, day: 20 });
assert.deepEqual(jalali.jalaliToGregorian(1404, 1, 1), { year: 2025, month: 3, day: 21 });
assert.deepEqual(jalali.gregorianToJalali(2024, 3, 20), { year: 1403, month: 1, day: 1 });
assert.deepEqual(jalali.gregorianToJalali(2025, 3, 21), { year: 1404, month: 1, day: 1 });
assert.deepEqual(jalali.jalaliToGregorian(1403, 12, 30), { year: 2025, month: 3, day: 20 });
assert.equal(jalali.daysInJalaliMonth(1403, 12), 30);
assert.equal(jalali.daysInJalaliMonth(1404, 12), 29);
assert.equal(jalali.parseDate('۱۴۰۴/۱۲/۳۰'), null);
assert.deepEqual(jalali.parseDate('١٤٠٤-١٢-٢٩'), { year: 1404, month: 12, day: 29 });
assert.deepEqual(jalali.parseDate('14040101'), { year: 1404, month: 1, day: 1 });
assert.equal(jalali.formatDate({ year: 1404, month: 1, day: 1 }), '۱۴۰۴/۰۱/۰۱');

for (let year = 1390; year <= 1410; year++) {
  for (let month = 1; month <= 12; month++) {
    const lastDay = jalali.daysInJalaliMonth(year, month);
    const date = jalali.jalaliToGregorian(year, month, lastDay);
    assert.deepEqual(jalali.gregorianToJalali(date.year, date.month, date.day), { year, month, day: lastDay });
  }
}

console.log('Jalali calendar conversion and validation tests passed.');
