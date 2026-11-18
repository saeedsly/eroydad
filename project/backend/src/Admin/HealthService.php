<?php
declare(strict_types=1);

namespace Eroydad\Admin;

use PDO;

/**
 * HealthService — بررسی سلامت دیتابیس، افزونه‌های PHP، فضای ذخیره‌سازی و پیکربندی.
 */
final class HealthService
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /** @return array<int,array<string,mixed>> */
    public function run(): array
    {
        $checks = [];
        $checks[] = $this->connectionCheck();
        $checks[] = $this->tableCheck();
        $checks[] = $this->migrationCheck();
        $checks[] = $this->extensionCheck();
        $checks[] = $this->storageCheck();
        $checks[] = $this->secretCheck();
        $checks[] = $this->phpVersionCheck();
        return $checks;
    }

    private function connectionCheck(): array
    {
        try {
            $version = (string)$this->db->query('SELECT VERSION()')->fetchColumn();
            return [
                'id' => 'database',
                'title' => 'اتصال دیتابیس',
                'status' => 'ok',
                'detail' => 'نسخهٔ ' . $version,
            ];
        } catch (\Throwable $e) {
            return ['id' => 'database', 'title' => 'اتصال دیتابیس', 'status' => 'fail', 'detail' => 'اتصال برقرار نشد.'];
        }
    }

    private function tableCheck(): array
    {
        $required = ['users', 'roles', 'user_roles', 'events', 'registrations', 'tickets', 'checkins', 'support_tickets', 'audit_logs', 'schema_migrations'];
        $missing = [];
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        foreach ($required as $table) {
            $stmt->execute([$table]);
            if ((int)$stmt->fetchColumn() === 0) {
                $missing[] = $table;
            }
        }
        return [
            'id' => 'tables',
            'title' => 'جدول‌های پایه',
            'status' => $missing === [] ? 'ok' : 'fail',
            'detail' => $missing === [] ? 'همهٔ جدول‌های پایه موجود است.' : ('جدول‌های ناقص: ' . implode('، ', $missing)),
        ];
    }

    private function migrationCheck(): array
    {
        try {
            $stmt = $this->db->query('SELECT version FROM schema_migrations ORDER BY version ASC');
            $versions = array_column($stmt->fetchAll(), 'version');
            $files = glob((defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3)) . '/database/migrations/*.php') ?: [];
            $pending = [];
            foreach ($files as $file) {
                $definition = require $file;
                if (is_array($definition) && !empty($definition['version']) && !in_array($definition['version'], $versions, true)) {
                    $pending[] = $definition['version'];
                }
            }
            return [
                'id' => 'migrations',
                'title' => 'وضعیت مهاجرت‌ها',
                'status' => $pending === [] ? 'ok' : 'warn',
                'detail' => $pending === [] ? 'همهٔ مهاجرت‌ها اجرا شده است.' : ('مهاجرت‌های اجرا نشده: ' . implode('، ', $pending)),
            ];
        } catch (\Throwable $e) {
            return ['id' => 'migrations', 'title' => 'وضعیت مهاجرت‌ها', 'status' => 'warn', 'detail' => 'جدول schema_migrations در دسترس نیست.'];
        }
    }

    private function extensionCheck(): array
    {
        $required = ['pdo_mysql', 'mbstring', 'json', 'session'];
        $optional = ['gd', 'fileinfo', 'zlib', 'openssl', 'intl'];
        $missing = [];
        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        $absentOptional = [];
        foreach ($optional as $extension) {
            if (!extension_loaded($extension)) {
                $absentOptional[] = $extension;
            }
        }
        return [
            'id' => 'extensions',
            'title' => 'افزونه‌های PHP',
            'status' => $missing === [] ? 'ok' : 'fail',
            'detail' => $missing === []
                ? ('افزونه‌های ضروری موجود است.' . ($absentOptional === [] ? '' : ' افزونه‌های اختیاری غایب: ' . implode('، ', $absentOptional)))
                : ('افزونه‌های ضروری غایب: ' . implode('، ', $missing)),
        ];
    }

    private function storageCheck(): array
    {
        $root = (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3)) . '/storage';
        $writable = is_dir($root) ? is_writable($root) : @mkdir($root, 0775, true);
        $free = @disk_free_space(dirname($root));
        return [
            'id' => 'storage',
            'title' => 'فضای ذخیره‌سازی',
            'status' => $writable ? 'ok' : 'fail',
            'detail' => $writable
                ? ('قابل نوشتن است. فضای آزاد: ' . ($free !== false ? round($free / 1048576) . ' مگابایت' : 'نامشخص'))
                : 'پوشهٔ storage قابل نوشتن نیست.',
        ];
    }

    private function secretCheck(): array
    {
        $problems = [];
        foreach (['app_key', 'employee_hmac_key', 'ticket_signing_key'] as $key) {
            $value = (string)($this->config['app'][$key] ?? '');
            if (strlen($value) < 32) {
                $problems[] = $key;
            }
        }
        return [
            'id' => 'secrets',
            'title' => 'کلیدهای امنیتی',
            'status' => $problems === [] ? 'ok' : 'fail',
            'detail' => $problems === [] ? 'همهٔ کلیدها طول لازم را دارند.' : ('کلیدهای ناکافی: ' . implode('، ', $problems)),
        ];
    }

    private function phpVersionCheck(): array
    {
        $version = PHP_VERSION;
        $ok = version_compare($version, '7.4.0', '>=');
        return [
            'id' => 'php',
            'title' => 'نسخهٔ PHP',
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $version . ($ok ? ' (سازگار)' : ' — نسخهٔ ۷.۴ یا بالاتر توصیه می‌شود)'),
        ];
    }
}
