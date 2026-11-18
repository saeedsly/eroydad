<?php
declare(strict_types=1);

namespace Eroydad\Admin;

use PDO;

/**
 * BackupService — پشتیبان‌گیری محلی از دیتابیس و آزمون بازیابی.
 * خروجی یک فایل SQL در پوشهٔ storage/private/backups است که هرگز از راه وب در دسترس نیست.
 */
final class BackupService
{
    private PDO $db;
    private string $directory;

    public function __construct(PDO $db, ?string $directory = null)
    {
        $this->db = $db;
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3);
        $this->directory = rtrim((string)($directory ?? $root . '/storage/private/backups'), '/');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * ساخت فایل پشتیبان.
     * @return array<string,mixed>
     */
    public function create(int $actorId): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('پوشهٔ پشتیبان قابل ایجاد نیست.');
        }
        $name = 'backup-' . gmdate('Ymd-His') . '.sql';
        $path = $this->directory . '/' . $name;
        $tables = array_column($this->db->query('SHOW TABLES')->fetchAll(), 0);
        $lines = [];
        $lines[] = '-- ای‌رویداد | پشتیبان محلی دیتابیس';
        $lines[] = '-- زمان: ' . gmdate('Y-m-d H:i:s') . ' UTC';
        $lines[] = 'SET NAMES utf8mb4;';
        $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $rowsTotal = 0;
        foreach ($tables as $table) {
            $create = $this->db->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch();
            if (!$create) {
                continue;
            }
            $createSql = (string)($create['Create Table'] ?? '');
            $lines[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
            $lines[] = $createSql . ';';
            $rows = $this->db->query('SELECT * FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $columns = array_keys($rows[0]);
                $columnList = '`' . implode('`,`', $columns) . '`';
                $chunk = [];
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($columns as $column) {
                        $value = $row[$column] ?? null;
                        if ($value === null) {
                            $values[] = 'NULL';
                            continue;
                        }
                        $values[] = $this->db->quote((string)$value);
                    }
                    $chunk[] = 'INSERT INTO `' . $table . '` (' . $columnList . ') VALUES (' . implode(',', $values) . ');';
                    $rowsTotal++;
                }
                foreach (array_chunk($chunk, 200) as $part) {
                    $lines = array_merge($lines, $part);
                }
            }
            $lines[] = '';
        }
        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $sql = implode("\n", $lines) . "\n";
        if (@file_put_contents($path, $sql) === false) {
            throw new \RuntimeException('نوشتن فایل پشتیبان ممکن نشد.');
        }
        $size = (int)filesize($path);
        $hash = (string)hash_file('sha256', $path);
        $stmt = $this->db->prepare('INSERT INTO backup_runs (file_name,size_bytes,tables_count,rows_count,sha256,status,message,created_by) VALUES (?,?,?,?,?,\'ok\',?,?)');
        $stmt->execute([$name, $size, count($tables), $rowsTotal, $hash, 'پشتیبان‌گیری کامل انجام شد.', $actorId > 0 ? $actorId : null]);
        $id = (int)$this->db->lastInsertId();
        \auditLog($actorId > 0 ? $actorId : null, 'system.backup.created', 'backup', (string)$id, ['size' => $size, 'tables' => count($tables)]);
        return ['id' => $id, 'file' => $name, 'size' => $size, 'tables' => count($tables), 'rows' => $rowsTotal, 'sha256' => $hash];
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $stmt = $this->db->query('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 50');
        return $stmt->fetchAll();
    }

    public function path(string $fileName): string
    {
        return $this->directory . '/' . basename($fileName);
    }

    /**
     * آزمون بازیابی: بررسی صحت فایل و اجرای آزمایشیِ یکی از جدول‌ها در جدول موقت.
     * @return array<string,mixed>
     */
    public function verify(int $backupId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM backup_runs WHERE id=? LIMIT 1');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();
        if (!$backup) {
            throw new \DomainException('سند پشتیبان پیدا نشد.');
        }
        $path = $this->path((string)$backup['file_name']);
        if (!is_file($path)) {
            throw new \DomainException('فایل پشتیبان روی دیسک موجود نیست.');
        }
        $hash = (string)hash_file('sha256', $path);
        $content = (string)file_get_contents($path);
        $creates = preg_match_all('/CREATE TABLE/i', $content);
        $inserts = preg_match_all('/INSERT INTO/i', $content);
        $checksumOk = hash_equals((string)$backup['sha256'], $hash);
        $rehearsal = $this->rehearse($content);
        $update = $this->db->prepare('UPDATE backup_runs SET verified_at=UTC_TIMESTAMP() WHERE id=?');
        $update->execute([$backupId]);
        $ok = $checksumOk && $creates > 0 && $rehearsal['ok'];
        \auditLog(null, 'system.backup.verified', 'backup', (string)$backupId, ['ok' => $ok]);
        return [
            'ok' => $ok,
            'checksum_ok' => $checksumOk,
            'sha256' => $hash,
            'create_statements' => (int)$creates,
            'insert_statements' => (int)$inserts,
            'rehearsal' => $rehearsal,
        ];
    }

    /**
     * اجرای آزمایشی: یکی از جدول‌های کوچک در جدول موقت بازیابی و سپس حذف می‌شود.
     * @return array<string,mixed>
     */
    private function rehearse(string $content): array
    {
        if (!preg_match('/CREATE TABLE `([^`]+)` \((.*?)\);/is', $content, $match)) {
            return ['ok' => false, 'detail' => 'ساختار جدول در فایل پیدا نشد.'];
        }
        $table = 'restore_rehearsal_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $create = 'CREATE TABLE `' . $table . '` (' . $match[2] . ')';
        try {
            $this->db->exec($create);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'ساخت جدول موقت ناموفق بود: ' . $e->getMessage()];
        }
        $rows = 0;
        $pattern = '/INSERT INTO `' . preg_quote($table, '/') . '`.*?;/is';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[0] as $statement) {
                try {
                    $this->db->exec($statement);
                    $rows++;
                } catch (\Throwable $e) {
                    // یک سطر ناسازگار مانع آزمون نیست
                }
            }
        }
        try {
            $this->db->exec('DROP TABLE IF EXISTS `' . $table . '`');
        } catch (\Throwable $e) {
            // بی‌اهمیت
        }
        return ['ok' => $rows > 0, 'detail' => $rows . ' سطر در جدول موقت بازیابی شد.', 'rows' => $rows];
    }
}
