<?php
declare(strict_types=1);
/**
 * آزمون واحد: خروجی تقویم ICS (Ics::build).
 * اجرا: tests/unit-run.mjs (PHP 7.4 واقعی روی WASM)
 */
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Security/Ics.php';

use Eroydad\Security\Ics;

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

$ics = Ics::build([
    [
        'uid' => 'event-test',
        'title' => 'دورهٔ آزمون',
        'description' => 'توضیح کوتاه',
        'location' => 'سالن یک',
        'start' => '2026-01-01 10:00:00',
        'end' => '2026-01-01 12:00:00',
        'url' => 'http://localhost/events/test',
    ],
], 'تقویم تست');

t('شروع با BEGIN:VCALENDAR است', str_starts_with($ics, "BEGIN:VCALENDAR\r\n"));
t('با END:VCALENDAR تمام می‌شود', substr($ics, -strlen("END:VCALENDAR\r\n")) === "END:VCALENDAR\r\n");
t('نسخهٔ 2.0 ثبت شده', strpos($ics, "VERSION:2.0\r\n") !== false);
t('یک رویداد دارد', substr_count($ics, 'BEGIN:VEVENT') === 1 && substr_count($ics, 'END:VEVENT') === 1);
t('UID از رویداد می‌آید', strpos($ics, 'UID:event-test@erooydad.local') !== false);
t('SUMMARY و LOCATION و URL هستند', strpos($ics, 'SUMMARY:') !== false && strpos($ics, 'LOCATION:') !== false && strpos($ics, 'URL:http://localhost/events/test') !== false);
t('زمان‌ها به صورت UTC ثبت شده‌اند', strpos($ics, 'DTSTART:20260101T100000Z') !== false && strpos($ics, 'DTEND:20260101T120000Z') !== false, substr($ics, 0, 400));
t('تقویم خالی هم ساختار معتبر دارد', str_starts_with(Ics::build([], 'خالی'), "BEGIN:VCALENDAR\r\n") && substr_count(Ics::build([], 'خالی'), 'END:VCALENDAR') === 1);

echo "UNIT_DONE total=$total fail=$fails\n";
exit(0); // شمارش نهایی با UNIT_DONE انجام می‌شود
