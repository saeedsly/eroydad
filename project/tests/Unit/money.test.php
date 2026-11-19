<?php
declare(strict_types=1);
/**
 * آزمون واحد: محاسبهٔ مالیات و جمع (Money).
 * اجرا: tests/unit-run.mjs (PHP 7.4 واقعی روی WASM)
 */
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Payments/Money.php';

use Eroydad\Payments\Money;

$total = 0;
$fails = 0;
function t(string $label, bool $cond, string $detail = ''): void
{
    global $total, $fails;
    $total++;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        $fails++;
        echo "FAIL  $label" . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
    }
}

// حالت استاندارد: فراگیر مالیات را می‌پردازد
$b = Money::breakdown(250000, 10.0, 'participant');
t('مالیات ۱۰٪ روی ۲۵۰٬۰۰۰ = ۲۵٬۰۰۰', $b['tax'] === 25000, (string)$b['tax']);
t('جمع فراگیر = پایه + مالیات', $b['total'] === 275000 && $b['participant_total'] === 275000, (string)$b['total']);
t('سهم برگزارکننده صفر است', $b['organizer_tax'] === 0, (string)$b['organizer_tax']);

// برگزارکننده مالیات را می‌پردازد
$o = Money::breakdown(250000, 10.0, 'organizer');
t('با پرداخت‌کنندهٔ برگزارکننده، جمع فراگیر = پایه', $o['total'] === 250000, (string)$o['total']);
t('سهم مالیات برگزارکننده جدا ثبت می‌شود', $o['organizer_tax'] === 25000 && $o['tax'] === 25000);

// درصد صفر
$z = Money::breakdown(100000, 0.0, 'participant');
t('درصد صفر یعنی بدون مالیات', $z['tax'] === 0 && $z['total'] === 100000);

// محدودسازی درصد
$low = Money::breakdown(1000, -5.0, 'participant');
t('درصد منفی به صفر محدود می‌شود', $low['tax_percent'] === 0.0 && $low['tax'] === 0, (string)$low['tax_percent']);
$high = Money::breakdown(1000, 150.0, 'participant');
t('درصد بالای ۱۰۰ به ۱۰۰ محدود می‌شود', $high['tax_percent'] === 100.0 && $high['tax'] === 1000, (string)$high['tax_percent']);

// پایهٔ منفی
$n = Money::breakdown(-500, 10.0, 'participant');
t('پایهٔ منفی صفر می‌شود', $n['base'] === 0 && $n['tax'] === 0 && $n['total'] === 0);

// پرداخت‌کنندهٔ ناشناخته → فراگیر (محافظ دادهٔ خراب)
$u = Money::breakdown(1000, 10.0, 'unknown-payer');
t('پرداخت‌کنندهٔ ناشناخته به فراگیر برمی‌گردد', $u['tax_payer'] === 'participant' && $u['participant_total'] === 1100, $u['tax_payer']);

// گرد کردن
$r = Money::breakdown(999, 10.0, 'participant');
t('گرد کردن مالیات استاندارد است (۹۹٫۹ → ۱۰۰)', $r['tax'] === 100 && $r['total'] === 1099, $r['tax'] . '/' . $r['total']);

// نمایش
t('format با جداکنندهٔ هزارگان و واحد تومان', Money::format(250000) === '250,000 تومان', Money::format(250000));
t('برچسب پرداخت‌کننده فارسی است', Money::payerLabel('organizer') === 'برگزارکننده' && Money::payerLabel('participant') === 'فراگیر');

echo "UNIT_DONE total=$total fail=$fails\n";
exit(0); // شمارش نهایی با UNIT_DONE انجام می‌شود
