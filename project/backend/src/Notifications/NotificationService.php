<?php
declare(strict_types=1);

namespace Eroydad\Notifications;

use PDO;

/**
 * NotificationService — اعلان‌های درون‌برنامه‌ای (بدون پیامک و بدون ایمیل).
 */
final class NotificationService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function notify(int $userId, string $type, string $title, string $body = '', string $link = '', string $dedupeKey = ''): int
    {
        if ($userId < 1) {
            return 0;
        }
        $title = mb_substr(trim($title), 0, 190, 'UTF-8');
        $body = mb_substr(trim($body), 0, 500, 'UTF-8');
        $link = $link !== '' ? mb_substr($link, 0, 255, 'UTF-8') : null;
        $dedupe = $dedupeKey !== '' ? mb_substr($dedupeKey, 0, 120, 'UTF-8') : null;
        try {
            $stmt = $this->db->prepare('INSERT INTO notifications (user_id,type,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$userId, $type, $title, $body, $link, $dedupe]);
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 0; // اعلان تکراری (dedupe)
            }
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT " . $limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function markRead(int $userId, ?int $notificationId = null): void
    {
        if ($notificationId !== null) {
            $stmt = $this->db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?');
            $stmt->execute([$notificationId, $userId]);
            return;
        }
        $stmt = $this->db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');
        $stmt->execute([$userId]);
    }

    /** ساخت یادآوری موعد دوره برای ثبت‌نام‌شده‌ها (idempotent) */
    public function createEventReminders(int $hoursBefore = 24): int
    {
        $stmt = $this->db->prepare("SELECT r.id, r.user_id, e.id AS event_id, e.title, e.starts_at_utc FROM registrations r JOIN events e ON e.id=r.event_id WHERE r.status='confirmed' AND e.starts_at_utc > UTC_TIMESTAMP() AND e.starts_at_utc <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR)");
        $stmt->execute([$hoursBefore]);
        $created = 0;
        foreach ($stmt->fetchAll() as $row) {
            $key = 'reminder:' . $row['event_id'] . ':' . $row['id'] . ':' . $hoursBefore;
            $before = $this->notify(
                (int)$row['user_id'],
                'reminder',
                'یادآوری شروع دوره: ' . $row['title'],
                'این دوره به‌زودی آغاز می‌شود. بلیت خود را همراه داشته باشید.',
                '/account',
                $key
            );
            if ($before > 0) {
                $created++;
            }
        }
        return $created;
    }
}
