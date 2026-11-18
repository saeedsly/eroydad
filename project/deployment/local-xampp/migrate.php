<?php
declare(strict_types=1);

/**
 * runner مهاجرت‌های افزایشی — روی نصب موجود اجرا می‌شود و schema.sql را دوباره Import نمی‌کند.
 * اجرا:  php deployment/local-xampp/migrate.php
 *
 * منطق کار در Eroydad\Database\MigrationRunner است (تا در آزمون‌ها نیز قابل استفاده باشد)؛
 * این فایل فقط محیط خط فرمان را آماده می‌کند.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان اجرا می‌شود.');
}

require dirname(__DIR__, 2) . '/backend/bootstrap.php';
require_once dirname(__DIR__, 2) . '/backend/src/Database/MigrationRunner.php';

$db = db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$runner = new \Eroydad\Database\MigrationRunner($db, PROJECT_ROOT . '/database/migrations');
$result = $runner->run(static function (string $text): void { echo $text . "\n"; });

echo "پایان: " . $result['applied'] . " مهاجرت اجرا شد"
    . ($result['skipped'] > 0 ? "، " . $result['skipped'] . " مهاجرت اعمال‌شده بود." : ".") . "\n";

if ($result['failures'] !== []) {
    exit(1);
}
exit(0);
