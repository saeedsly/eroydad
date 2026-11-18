<?php
declare(strict_types=1);

namespace Eroydad\Admin;

use PDO;

/**
 * RetentionService — سیاست نگهداری و پاک‌سازی داده‌های گزارش و لاگ.
 */
final class RetentionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> */
    public function policies(): array
    {
        return [
            ['id' => 'audit_logs', 'title' => 'گزارش فعالیت سامانه', 'days' => 365, 'description' => 'نگهداری یک سال از رخدادهای ممیزی'],
            ['id' => 'login_attempts', 'title' => 'تلاش‌های ورود', 'days' => 90, 'description' => 'نگهداری سه ماه از تلاش‌های ناموفق و موفق ورود'],
            ['id' => 'registration_attempts', 'title' => 'تلاش‌های ساخت حساب', 'days' => 90, 'description' => 'نگهداری سه ماه از تلاش‌های ثبت‌نام'],
            ['id' => 'notifications', 'title' => 'اعلان‌های خوانده‌شده', 'days' => 180, 'description' => 'حذف اعلان‌های خوانده‌شده پس از شش ماه'],
            ['id' => 'password_resets', 'title' => 'کدهای بازیابی رمز', 'days' => 30, 'description' => 'حذف کدهای منقضی‌شده پس از یک ماه'],
            ['id' => 'user_sessions', 'title' => 'نشست‌های کاربران', 'days' => 60, 'description' => 'حذف نشست‌های غیرفعال پس از دو ماه'],
        ];
    }

    /**
     * اجرای سیاست‌های پاک‌سازی.
     * @return array<int,array<string,mixed>>
     */
    public function run(int $actorId, ?int $daysOverride = null): array
    {
        $results = [];
        foreach ($this->policies() as $policy) {
            $days = $daysOverride !== null ? max(1, min(3650, $daysOverride)) : (int)$policy['days'];
            $deleted = 0;
            try {
                switch ($policy['id']) {
                    case 'audit_logs':
                        $stmt = $this->db->prepare('DELETE FROM audit_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
                        $stmt->execute([$days]);
                        $deleted = $stmt->rowCount();
                        break;
                    case 'login_attempts':
                        $stmt = $this->db->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
                        $stmt->execute([$days]);
                        $deleted = $stmt->rowCount();
                        break;
                    case 'registration_attempts':
                        $stmt = $this->db->prepare('DELETE FROM registration_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
                        $stmt->execute([$days]);
                        $deleted = $stmt->rowCount();
                        break;
                    case 'notifications':
                        $stmt = $this->db->prepare('DELETE FROM notifications WHERE is_read=1 AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
                        $stmt->execute([$days]);
                        $deleted = $stmt->rowCount();
                        break;
                    case 'password_resets':
                        $stmt = $this->db->prepare('DELETE FROM password_resets WHERE (expires_at < UTC_TIMESTAMP() OR used_at IS NOT NULL) AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
                        $stmt->execute([$days]);
                        $deleted = $stmt->rowCount();
                        break;
                    case 'user_sessions':
                        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
                        $stmt->execute([$days]);
                        $deleted = $stmt->rowCount();
                        break;
                }
            } catch (\Throwable $e) {
                $results[] = ['policy' => $policy['title'], 'deleted' => 0, 'note' => 'خطا: ' . $e->getMessage()];
                continue;
            }
            $log = $this->db->prepare('INSERT INTO data_retention_log (policy, deleted_rows, note, actor_id) VALUES (?,?,?,?)');
            $log->execute([$policy['id'], $deleted, $policy['description'], $actorId > 0 ? $actorId : null]);
            $results[] = ['policy' => $policy['title'], 'deleted' => $deleted, 'note' => $policy['description']];
        }
        \auditLog($actorId > 0 ? $actorId : null, 'system.retention.executed', 'retention', null, ['policies' => count($results)]);
        return $results;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $limit = 50): array
    {
        $stmt = $this->db->prepare('SELECT * FROM data_retention_log ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
