<?php
declare(strict_types=1);
/**
 * آزمون واحد: اندیس ستون XLSX (XlsxReader::columnIndex).
 * اجرا: tests/unit-run.mjs (PHP 7.4 واقعی روی WASM)
 */
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Excel/XlsxReader.php';

use Eroydad\Excel\XlsxReader;

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

t('A → 0', XlsxReader::columnIndex('A') === 0, (string)XlsxReader::columnIndex('A'));
t('B → 1', XlsxReader::columnIndex('B') === 1, (string)XlsxReader::columnIndex('B'));
t('Z → 25', XlsxReader::columnIndex('Z') === 25, (string)XlsxReader::columnIndex('Z'));
t('AA → 26', XlsxReader::columnIndex('AA') === 26, (string)XlsxReader::columnIndex('AA'));
t('AB → 27', XlsxReader::columnIndex('AB') === 27, (string)XlsxReader::columnIndex('AB'));
t('BA → 52', XlsxReader::columnIndex('BA') === 52, (string)XlsxReader::columnIndex('BA'));
t('ZZ → 701', XlsxReader::columnIndex('ZZ') === 701, (string)XlsxReader::columnIndex('ZZ'));
t('حروف کوچک هم پذیرفته می‌شود', XlsxReader::columnIndex('ab') === 27, (string)XlsxReader::columnIndex('ab'));
t('نویسهٔ غیرحرف نادیده گرفته می‌شود', XlsxReader::columnIndex('A!') === 0, (string)XlsxReader::columnIndex('A!'));
t('ورودی خالی صفر می‌دهد', XlsxReader::columnIndex('') === 0, (string)XlsxReader::columnIndex(''));

echo "UNIT_DONE total=$total fail=$fails\n";
exit(0); // شمارش نهایی با UNIT_DONE انجام می‌شود
