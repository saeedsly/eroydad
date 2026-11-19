<?php
declare(strict_types=1);
/**
 * آزمون واحد: کد ملی ایران (IranianNationalId).
 * اجرا: tests/unit-run.mjs (PHP 7.4 واقعی روی WASM)
 */
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Security/IranianNationalId.php';

use Eroydad\Security\IranianNationalId;

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

// 001354039 + رقم کنترل 4 → 0013540394 (مجموع وزنی 106، 106 mod 11 = 7، 11-7 = 4)
t('کد معتبر پذیرفته می‌شود', IranianNationalId::isValid('0013540394') === true);
t('رقم کنترل غلط رد می‌شود', IranianNationalId::isValid('0013540395') === false);
t('کد یکنواخت رد می‌شود', IranianNationalId::isValid('1111111111') === false);
t('کوتاه‌تر از ۱۰ رقم رد می‌شود', IranianNationalId::isValid('123') === false);
t('غیرعددی رد می‌شود', IranianNationalId::isValid('001354039a') === false);

t('ارقام فارسی/عربی نرمال می‌شود', IranianNationalId::normalize('۰۰۱۳۵۴۰۳۹۴') === '0013540394', IranianNationalId::normalize('۰۰۱۳۵۴۰۳۹۴'));
t('جداکنندهها حذف می‌شوند', IranianNationalId::normalize(' 0013-5403 94 ') === '0013540394', IranianNationalId::normalize(' 0013-5403 94 '));
t('نرمال‌سازی، اعتبارسنجی را درست می‌کند', IranianNationalId::isValid('۰۰۱۳۵۴۰۳۹۴') === true);

$h1 = IranianNationalId::hmac('0013540394', 'secret-key');
$h2 = IranianNationalId::hmac('۰۰۱۳۵۴۰۳۹۴', 'secret-key');
t('HMAC با ورودی معادل یکسان است', is_string($h1) && strlen($h1) === 64 && $h1 === $h2);
t('HMAC به کلید حساس است', $h1 !== IranianNationalId::hmac('0013540394', 'other-key'));

echo "UNIT_DONE total=$total fail=$fails\n";
exit(0); // شمارش نهایی با UNIT_DONE انجام می‌شود
