<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/backend/bootstrap.php';
require dirname(__DIR__) . '/backend/src/Events/EventService.php';
require dirname(__DIR__) . '/backend/src/Admin/AdministrationService.php';

$failures=[];
$check=static function(bool $ok,string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[]=$label;
};
$check(PHP_VERSION_ID>=70400,'PHP 7.4 or newer is active');
$check(extension_loaded('pdo_mysql'),'pdo_mysql extension is enabled');
$check(extension_loaded('mbstring'),'mbstring extension is enabled');
$check(gregorianToJalali(2024,3,20)===[1403,1,1],'Jalali date conversion at Nowruz');
$check(gregorianToJalali(2025,3,21)===[1404,1,1],'Jalali date conversion in leap cycle');
$check(normalizePhone('۰۹۱۲۱۲۳۴۵۶۷')==='09121234567','Persian-digit phone normalization');
$check(validIranMobile('+989121234567'),'Iranian mobile international normalization');
$hash=password_hash(passwordMaterial('یک رمز آزمایشی طولانی'),PASSWORD_DEFAULT);
$check(password_verify(passwordMaterial('یک رمز آزمایشی طولانی'),$hash),'password storage/verification supports Unicode input');
$check(!password_verify(passwordMaterial('رمز دیگر'),$hash),'different passwords do not verify');
$check(!Eroydad\Security\IranianNationalId::isValid('1111111111'),'repeated-digit national IDs are rejected');
$check(Eroydad\Events\EventService::localDateToUtc('2024-03-20T12:00')==='2024-03-20 08:30:00','Tehran local datetime converts to UTC');
try {
    $pdo=db();
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach(['users','roles','events','registrations','tickets','checkins','support_tickets','employee_allowlist','registration_attempts','schema_migrations'] as $table)
        $check(in_array($table,$tables,true),'database table exists: ' . $table);
    $migration=$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='001_initial_schema'")->fetchColumn();
    $check((int)$migration===1,'baseline schema migration is recorded');
    $roles=$pdo->query('SELECT code FROM roles')->fetchAll(PDO::FETCH_COLUMN);
    foreach(['attendee','event_admin','support','system_admin'] as $role)
        $check(in_array($role,$roles,true),'seed role exists: ' . $role);
} catch(Throwable $e) {
    $check(false,'database connection/schema check: ' . $e->getMessage());
}
if ($failures) { fwrite(STDERR,count($failures).' smoke check(s) failed.' . PHP_EOL); exit(1); }
echo 'All configured smoke checks passed.' . PHP_EOL;
