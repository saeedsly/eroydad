<?php
declare(strict_types=1);
/**
 * آزمون واحد: تبدیل تاریخ شمسی سمت سرور و فیلدهای جایگزین فرم (بدون JS).
 * اجرا: tests/unit-run.mjs (PHP 7.4 واقعی روی WASM)
 */
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Events/EventService.php';

use Eroydad\Events\EventService;

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

// ---------- gregorianFromJalali ----------
t('۱۴۰۵/۰۹/۰۴ لاتین ← ۲۵ نوامبر ۲۰۲۶', gregorianFromJalali('1405/09/04') === '2026-11-25', (string)gregorianFromJalali('1405/09/04'));
t('ارقام فارسی ۱۴۰۵/۰۹/۰۴ همان نتیجه را می‌دهد', gregorianFromJalali('۱۴۰۵/۰۹/۰۴') === '2026-11-25', (string)gregorianFromJalali('۱۴۰۵/۰۹/۰۴'));
t('جداکنندهٔ خط تیره پذیرفته می‌شود', gregorianFromJalali('1404-01-01') === '2025-03-21', (string)gregorianFromJalali('1404-01-01'));
t('نوروز ۱۴۰۵ ← ۲۱ مارس ۲۰۲۶', gregorianFromJalali('1405/01/01') === '2026-03-21', (string)gregorianFromJalali('1405/01/01'));
t('ماه نامعتبر (۱۳) رد می‌شود', gregorianFromJalali('1405/13/01') === null);
t('روز غیرممکن (۳۰ اسفند غیرکبیسه) رد می‌شود', gregorianFromJalali('1404/12/30') === null);
t('۲۹ اسفند ۱۴۰۴ معتبر است', gregorianFromJalali('1404/12/29') === '2026-03-20', (string)gregorianFromJalali('1404/12/29'));
t('متن بی‌معنی رد می‌شود', gregorianFromJalali('فردا') === null);
t('رشتهٔ خالی رد می‌شود', gregorianFromJalali('') === null);

// برگشت دوطرفه با تبدیل نمایشی موجود
$g = gregorianFromJalali('1405/09/04');
[$jy, $jm, $jd] = gregorianToJalali((int)substr($g, 0, 4), (int)substr($g, 5, 2), (int)substr($g, 8, 2));
t('چرخهٔ دوطرفه سالم است', sprintf('%04d/%02d/%02d', $jy, $jm, $jd) === '1405/09/04', sprintf('%04d/%02d/%02d', $jy, $jm, $jd));

// ---------- EventService::dateTimeFromInput ----------
$hidden = EventService::dateTimeFromInput(['starts_at' => '2026-12-01T10:00', 'starts_at_date' => '۱۴۰۵/۱۰/۱۰', 'starts_at_time' => '09:00'], 'starts_at');
t('فیلد مخفیِ پر شده مقدم است', $hidden === '2026-12-01T10:00', $hidden);

$fallback = EventService::dateTimeFromInput(['starts_at' => '', 'starts_at_date' => '۱۴۰۵/۰۹/۰۴', 'starts_at_time' => '10:30'], 'starts_at');
t('بدون JS، از فیلدهای شمسی + ساعت ساخته می‌شود', $fallback === '2026-11-25T10:30', $fallback);

$pad = EventService::dateTimeFromInput(['starts_at' => '', 'starts_at_date' => '1405/09/04', 'starts_at_time' => '9:5'], 'starts_at');
t('ساعت تک‌رقمی به دو رقم صفر می‌شود', $pad === '2026-11-25T09:05', $pad);

$empty = EventService::dateTimeFromInput([], 'starts_at');
t('هر دو فیلد خالی ← رشتهٔ خالی (برای پیام استاندارد)', $empty === '', $empty);

$thrown = null;
try {
    EventService::dateTimeFromInput(['starts_at' => '', 'starts_at_date' => '1405/99/01', 'starts_at_time' => '10:00'], 'starts_at');
} catch (DomainException $e) {
    $thrown = $e->getMessage();
}
t('تاریخ خراب با فیلد زمان → پیام فارسی روشن', $thrown === 'تاریخ شمسی و ساعت را بررسی کنید.', (string)$thrown);

$thrown2 = null;
try {
    EventService::dateTimeFromInput(['starts_at' => '', 'starts_at_date' => '1405/09/04', 'starts_at_time' => ''], 'starts_at');
} catch (DomainException $e) {
    $thrown2 = $e->getMessage();
}
t('تاریخ هست ولی ساعت نیست → همان پیام', $thrown2 === 'تاریخ شمسی و ساعت را بررسی کنید.', (string)$thrown2);


// ---------- نرمال‌سازی مقدار مخفی خراب (باگ قدیمی تاریخ‌شمار: HH:MM:HH) ----------
$total2 = 0; // شمارنده ادامه در همان فایل
t('مقدار مخفی با ثانیهٔ اضافه پذیرفته و نرمال می‌شود',
    EventService::dateTimeFromInput(['starts_at' => '2026-11-26T10:00:10'], 'starts_at') === '2026-11-26T10:00',
    EventService::dateTimeFromInput(['starts_at' => '2026-11-26T10:00:10'], 'starts_at'));
t('localDateToUtc مقدار با ثانیه را می‌پذیرد',
    EventService::localDateToUtc('2026-11-26T10:00:10') === '2026-11-26 06:30:00',
    EventService::localDateToUtc('2026-11-26T10:00:10'));
t('مقدار مخفی خراب از فیلدهای قابل‌مشاهده نجات می‌یابد',
    EventService::dateTimeFromInput(
        ['starts_at' => 'broken-value', 'starts_at_date' => '۱۴۰۵/۰۹/۰۵', 'starts_at_time' => '10:00'],
        'starts_at'
    ) === '2026-11-26T10:00',
    EventService::dateTimeFromInput(
        ['starts_at' => 'broken-value', 'starts_at_date' => '۱۴۰۵/۰۹/۰۵', 'starts_at_time' => '10:00'],
        'starts_at'
    ));

echo "UNIT_DONE total=$total fail=$fails\n";
exit(0); // شمارش نهایی با UNIT_DONE انجام می‌شود
