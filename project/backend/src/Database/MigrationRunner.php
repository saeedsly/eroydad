<?php
declare(strict_types=1);

namespace Eroydad\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * MigrationRunner — اجرای مهاجرت‌های افزایشی روی نصب موجود.
 *
 * همین کلاس هم توسط `deployment/local-xampp/migrate.php` (خط فرمان) و هم توسط
 * آزمون‌های یکپارچه استفاده می‌شود؛ به همین دلیل منطق مهاجرت در اسکریپت
 * خط فرمان قفل نمی‌ماند.
 *
 * هر گام با «نگهبان» (guard) بررسی می‌شود تا اجرای دوباره بی‌خطر باشد و
 * هر اجرا در جدول‌های `schema_migrations` و `migration_runs` ثبت می‌شود.
 */
final class MigrationRunner
{
    /** @var PDO */
    private $db;
    /** @var string */
    private $migrationsDir;

    public function __construct(PDO $db, string $migrationsDir)
    {
        $this->db = $db;
        $this->migrationsDir = rtrim($migrationsDir, '/');
    }

    /**
     * بررسی وضعیت یک نگهبان: «table:x»، «column:table:x»، «column-enum:table:x:مقدار»،
     * «index:table:x» و «constraint:table:x».
     */
    public function guardSatisfied(string $guard): bool
    {
        $parts = explode(':', $guard);
        $type = $parts[0] ?? '';
        try {
            if ($type === 'table') {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $stmt->execute([$parts[1]]);
                return (int)$stmt->fetchColumn() > 0;
            }
            if ($type === 'column') {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
                $stmt->execute([$parts[1], $parts[2]]);
                return (int)$stmt->fetchColumn() > 0;
            }
            if ($type === 'column-enum') {
                $stmt = $this->db->prepare('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
                $stmt->execute([$parts[1], $parts[2]]);
                $typeText = (string)$stmt->fetchColumn();
                return stripos($typeText, (string)$parts[3]) !== false;
            }
            if ($type === 'index') {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
                $stmt->execute([$parts[1], $parts[2]]);
                return (int)$stmt->fetchColumn() > 0;
            }
            if ($type === 'constraint') {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?');
                $stmt->execute([$parts[1], $parts[2]]);
                return (int)$stmt->fetchColumn() > 0;
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * جدول‌های دفترچهٔ خودِ runner باید پیش از نخستین مهاجرت موجود باشند،
     * وگرنه ثبت اجرای مهاجرت شکست می‌خورد. این دو جدول فقط متعلق به runner است.
     */
    public function ensureLedgerTables(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(80) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->exec("CREATE TABLE IF NOT EXISTS migration_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(80) NOT NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  message TEXT NULL,
  steps_total INT UNSIGNED NOT NULL DEFAULT 0,
  steps_applied INT UNSIGNED NOT NULL DEFAULT 0,
  steps_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_migration_runs_recent (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * خطاهایی که برای بی‌خطر‌بودن اجرای دوباره قابل چشم‌پوشی‌اند:
     * «ساختن چیزی که هست» و «حذف چیزی که نیست» هر دو یعنی وضعیت مطلوب.
     */
    public static function isIgnorableStepError(string $message): bool
    {
        $text = strtolower($message);
        if (strpos($text, 'duplicate') !== false) {
            return true;
        }
        if (strpos($text, 'already exists') !== false) {
            return true;
        }
        if (strpos($text, 'check constraint') !== false) {
            return true;
        }
        // خطای ۱۰۹۱: ستون/کلید/قیدی که قرار بود حذف شود موجود نیست.
        if (strpos($text, "can't drop") !== false && strpos($text, 'exists') !== false) {
            return true;
        }
        if (strpos($text, 'check that it exists') !== false) {
            return true;
        }
        if (strpos($text, 'check that column/key exists') !== false) {
            return true;
        }
        if (strpos($text, 'cant drop') !== false) {
            return true;
        }
        return false;
    }

    /**
     * اجرای همهٔ مهاجرت‌های اعمال‌نشده.
     *
     * @param callable|null $emit برای نوشتن خطوط پیشرفت (پیش‌فرض echo)
     * @return array{applied:int,skipped:int,failures:array<int,array{version:string,message:string}>}
     */
    public function run(?callable $emit = null): array
    {
        $line = $emit ?: static function (string $text): void { echo $text . "\n"; };

        $this->ensureLedgerTables();

        $applied = [];
        $check = $this->db->prepare('SELECT version FROM schema_migrations');
        $check->execute();
        $applied = array_column($check->fetchAll(), 'version');

        $files = glob($this->migrationsDir . '/*.php') ?: [];
        sort($files);
        $totalApplied = 0;
        $totalSkipped = 0;
        $failures = [];

        foreach ($files as $file) {
            $definition = require $file;
            if (!is_array($definition) || empty($definition['version'])) {
                continue;
            }
            $version = (string)$definition['version'];
            if (in_array($version, $applied, true)) {
                $line('↷ ' . $version . ' قبلاً اجرا شده — رد شد.');
                $totalSkipped++;
                continue;
            }
            $started = microtime(true);
            $runStmt = $this->db->prepare('INSERT INTO migration_runs (version, status, steps_total) VALUES (?, \'running\', ?)');
            $runStmt->execute([$version, count($definition['steps'] ?? [])]);
            $runId = (int)$this->db->lastInsertId();
            $stepsApplied = 0;
            $stepsSkipped = 0;
            try {
                foreach (($definition['steps'] ?? []) as $step) {
                    $sql = (string)$step['sql'];
                    $guard = isset($step['guard']) ? (string)$step['guard'] : '';
                    if ($guard !== '' && $this->guardSatisfied($guard)) {
                        $stepsSkipped++;
                        continue;
                    }
                    $variants = array_merge([$sql], is_array($step['alt'] ?? null) ? $step['alt'] : []);
                    $lastError = null;
                    $done = false;
                    foreach ($variants as $variant) {
                        try {
                            $this->db->exec($variant);
                            $done = true;
                            break;
                        } catch (Throwable $e) {
                            $lastError = $e;
                            if (self::isIgnorableStepError($e->getMessage())) {
                                // گام از قبل انجام شده است؛ بی‌خطر رد می‌شود.
                                $lastError = null;
                                $done = true;
                                break;
                            }
                        }
                    }
                    if (!$done) {
                        throw new RuntimeException($lastError ? $lastError->getMessage() : 'اجرای گام ناموفق بود.');
                    }
                    $stepsApplied++;
                }
                if (isset($definition['after']) && is_callable($definition['after'])) {
                    $definition['after']($this->db);
                }
                $duration = (int)round((microtime(true) - $started) * 1000);
                $update = $this->db->prepare('UPDATE migration_runs SET status = \'success\', steps_applied = ?, steps_skipped = ?, duration_ms = ?, finished_at = UTC_TIMESTAMP(), message = ? WHERE id = ?');
                $update->execute([$stepsApplied, $stepsSkipped, $duration, 'با موفقیت اجرا شد.', $runId]);
                $this->db->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)')->execute([$version]);
                $line('✔ ' . $version . ' اجرا شد (گام‌ها: ' . $stepsApplied . ' اجرا، ' . $stepsSkipped . ' تکراری).');
                $totalApplied++;
            } catch (Throwable $e) {
                $duration = (int)round((microtime(true) - $started) * 1000);
                $message = mb_substr($e->getMessage(), 0, 900);
                $update = $this->db->prepare('UPDATE migration_runs SET status = \'failed\', steps_applied = ?, steps_skipped = ?, duration_ms = ?, finished_at = UTC_TIMESTAMP(), message = ? WHERE id = ?');
                $update->execute([$stepsApplied, $stepsSkipped, $duration, $message, $runId]);
                $line('✖ خطا در ' . $version . ': ' . $e->getMessage());
                $failures[] = ['version' => $version, 'message' => $message];
            }
        }

        return ['applied' => $totalApplied, 'skipped' => $totalSkipped, 'failures' => $failures];
    }
}
