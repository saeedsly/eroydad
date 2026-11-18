<?php
declare(strict_types=1);

namespace Eroydad\Feedback;

use PDO;

/**
 * FeedbackService — بازخورد پس از دوره و سنجش رضایت.
 */
final class FeedbackService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function submit(int $registrationId, int $userId, int $rating, string $comment): int
    {
        if ($rating < 1 || $rating > 5) {
            throw new \DomainException('امتیاز باید بین ۱ تا ۵ باشد.');
        }
        $comment = mb_substr(trim($comment), 0, 800, 'UTF-8');
        $stmt = $this->db->prepare('SELECT r.user_id, r.event_id, r.status, e.title FROM registrations r JOIN events e ON e.id=r.event_id WHERE r.id=? LIMIT 1');
        $stmt->execute([$registrationId]);
        $registration = $stmt->fetch();
        if (!$registration || (int)$registration['user_id'] !== $userId) {
            throw new \DomainException('ثبت‌نام پیدا نشد.');
        }
        if ($registration['status'] !== 'confirmed') {
            throw new \DomainException('بازخورد تنها برای ثبت‌نام قطعی ثبت می‌شود.');
        }
        $existing = $this->db->prepare('SELECT id FROM feedbacks WHERE registration_id=? LIMIT 1');
        $existing->execute([$registrationId]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId > 0) {
            $update = $this->db->prepare('UPDATE feedbacks SET rating=?, comment=? WHERE registration_id=?');
            $update->execute([$rating, $comment, $registrationId]);
            \auditLog($userId, 'feedback.updated', 'feedback', (string)$registrationId, ['rating' => $rating]);
            return $existingId;
        }
        $insert = $this->db->prepare('INSERT INTO feedbacks (registration_id,event_id,user_id,rating,comment) VALUES (?,?,?,?,?)');
        $insert->execute([$registrationId, (int)$registration['event_id'], $userId, $rating, $comment]);
        $id = (int)$this->db->lastInsertId();
        \auditLog($userId, 'feedback.submitted', 'feedback', (string)$registrationId, ['rating' => $rating]);
        return $id;
    }

    public function forRegistration(int $registrationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM feedbacks WHERE registration_id=? LIMIT 1');
        $stmt->execute([$registrationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** میانگین و تعداد بازخورد یک رویداد */
    public function summaryForEvent(int $eventId): array
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS total, COALESCE(AVG(rating),0) AS average FROM feedbacks WHERE event_id=?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch() ?: [];
        $rows = $this->db->prepare('SELECT rating, COUNT(*) AS total FROM feedbacks WHERE event_id=? GROUP BY rating ORDER BY rating DESC');
        $rows->execute([$eventId]);
        $distribution = [];
        foreach ($rows->fetchAll() as $item) {
            $distribution[(int)$item['rating']] = (int)$item['total'];
        }
        return [
            'total' => (int)($row['total'] ?? 0),
            'average' => round((float)($row['average'] ?? 0), 2),
            'distribution' => $distribution,
        ];
    }

    public function listForEvent(int $eventId, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->db->prepare('SELECT f.*, u.full_name, e.title AS event_title FROM feedbacks f JOIN users u ON u.id=f.user_id JOIN events e ON e.id=f.event_id WHERE f.event_id=? ORDER BY f.id DESC LIMIT ' . $limit);
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }
}
