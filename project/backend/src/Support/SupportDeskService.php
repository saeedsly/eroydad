<?php
declare(strict_types=1);

namespace Eroydad\Support;

use PDO;

/**
 * SupportDeskService — مدیریت حرفه‌ای تیکت‌ها: اولویت، برچسب، تخصیص، SLA، پاسخ‌های آماده و سنجش رضایت.
 */
final class SupportDeskService
{
    /** ساعت‌های پاسخ هدف بر پایهٔ اولویت */
    public const SLA_HOURS = ['urgent' => 2, 'high' => 8, 'normal' => 24, 'low' => 72];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * فهرست تیکت‌ها با فیلتر و جست‌وجو.
     * @param array<string,string> $filters status, priority, q, assigned
     */
    public function listTickets(array $filters = [], int $limit = 250): array
    {
        $sql = 'SELECT st.*, u.full_name, u.phone, e.title AS event_title, a.full_name AS assigned_name
            FROM support_tickets st JOIN users u ON u.id=st.user_id
            LEFT JOIN events e ON e.id=st.event_id LEFT JOIN users a ON a.id=st.assigned_to WHERE 1=1';
        $params = [];
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $sql .= ' AND st.status=?';
            $params[] = $status;
        }
        $priority = trim((string)($filters['priority'] ?? ''));
        if ($priority !== '') {
            $sql .= ' AND st.priority=?';
            $params[] = $priority;
        }
        $assigned = trim((string)($filters['assigned'] ?? ''));
        if ($assigned !== '') {
            $sql .= ' AND st.assigned_to=?';
            $params[] = (int)$assigned;
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (st.subject LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $limit = max(1, min(1000, $limit));
        $sql .= ' ORDER BY FIELD(st.status,"open","in_progress","answered","closed"), FIELD(st.priority,"urgent","high","normal","low"), st.updated_at DESC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function setPriority(int $ticketId, string $priority, int $actorId): void
    {
        if (!isset(self::SLA_HOURS[$priority])) {
            throw new \DomainException('اولویت معتبر نیست.');
        }
        $hours = self::SLA_HOURS[$priority];
        $stmt = $this->db->prepare('UPDATE support_tickets SET priority=?, sla_due_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR), updated_by=? WHERE id=?');
        $stmt->execute([$priority, $hours, $actorId, $ticketId]);
        \auditLog($actorId, 'support.priority.updated', 'support_ticket', (string)$ticketId, ['priority' => $priority]);
    }

    public function assign(int $ticketId, ?int $assigneeId, int $actorId): void
    {
        if ($assigneeId !== null) {
            $check = $this->db->prepare('SELECT id FROM users WHERE id=? AND status=\'active\'');
            $check->execute([$assigneeId]);
            if (!$check->fetchColumn()) {
                throw new \DomainException('کاربر انتخاب‌شده معتبر نیست.');
            }
        }
        $stmt = $this->db->prepare('UPDATE support_tickets SET assigned_to=?, updated_by=? WHERE id=?');
        $stmt->execute([$assigneeId, $actorId, $ticketId]);
        \auditLog($actorId, 'support.assigned', 'support_ticket', (string)$ticketId, ['assignee' => $assigneeId]);
    }

    public function setStatus(int $ticketId, string $status, int $actorId): void
    {
        if (!in_array($status, ['open', 'in_progress', 'answered', 'closed'], true)) {
            throw new \DomainException('وضعیت معتبر نیست.');
        }
        $resolved = $status === 'closed' ? gmdate('Y-m-d H:i:s') : null;
        $stmt = $this->db->prepare('UPDATE support_tickets SET status=?, resolved_at=?, updated_by=? WHERE id=?');
        $stmt->execute([$status, $resolved, $actorId, $ticketId]);
        \auditLog($actorId, 'support.status.updated', 'support_ticket', (string)$ticketId, ['status' => $status]);
    }

    /** ثبت اولین پاسخ و SLA */
    public function markFirstResponse(int $ticketId, int $actorId): void
    {
        $stmt = $this->db->prepare('UPDATE support_tickets SET first_response_at=COALESCE(first_response_at, UTC_TIMESTAMP()), status=IF(status=\'open\',\'in_progress\',status), updated_by=? WHERE id=?');
        $stmt->execute([$actorId, $ticketId]);
    }

    /** برچسب‌ها */
    public function labels(): array
    {
        return $this->db->query('SELECT * FROM support_labels ORDER BY name ASC')->fetchAll();
    }

    public function ticketLabels(int $ticketId): array
    {
        $stmt = $this->db->prepare('SELECT l.id, l.name, l.color FROM support_labels l JOIN support_ticket_labels tl ON tl.label_id=l.id WHERE tl.ticket_id=? ORDER BY l.name ASC');
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    public function toggleLabel(int $ticketId, int $labelId, int $actorId): void
    {
        $check = $this->db->prepare('SELECT 1 FROM support_ticket_labels WHERE ticket_id=? AND label_id=?');
        $check->execute([$ticketId, $labelId]);
        if ($check->fetchColumn()) {
            $this->db->prepare('DELETE FROM support_ticket_labels WHERE ticket_id=? AND label_id=?')->execute([$ticketId, $labelId]);
        } else {
            $this->db->prepare('INSERT IGNORE INTO support_ticket_labels (ticket_id, label_id) VALUES (?,?)')->execute([$ticketId, $labelId]);
        }
        \auditLog($actorId, 'support.label.updated', 'support_ticket', (string)$ticketId, ['label_id' => $labelId]);
    }

    /** پاسخ‌های آماده */
    public function cannedResponses(): array
    {
        return $this->db->query('SELECT * FROM canned_responses ORDER BY id ASC')->fetchAll();
    }

    public function addCannedResponse(string $title, string $body, int $actorId): int
    {
        $title = trim($title);
        $body = trim($body);
        if (mb_strlen($title, 'UTF-8') < 3 || mb_strlen($body, 'UTF-8') < 5) {
            throw new \DomainException('عنوان و متن پاسخ آماده الزامی است.');
        }
        $stmt = $this->db->prepare('INSERT INTO canned_responses (title, body, role_code, created_by) VALUES (?,?,?,?)');
        $stmt->execute([mb_substr($title, 0, 160, 'UTF-8'), mb_substr($body, 0, 4000, 'UTF-8'), 'support', $actorId]);
        \auditLog($actorId, 'support.canned_created', 'canned_response', (string)$this->db->lastInsertId());
        return (int)$this->db->lastInsertId();
    }

    /** سنجش رضایت از پاسخ پشتیبانی */
    public function rate(int $ticketId, int $userId, int $rating, string $comment = ''): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new \DomainException('امتیاز باید بین ۱ تا ۵ باشد.');
        }
        $check = $this->db->prepare('SELECT user_id FROM support_tickets WHERE id=?');
        $check->execute([$ticketId]);
        $owner = (int)$check->fetchColumn();
        if ($owner !== $userId) {
            throw new \DomainException('این تیکت متعلق به شما نیست.');
        }
        $stmt = $this->db->prepare('UPDATE support_tickets SET satisfaction_rating=?, satisfaction_comment=? WHERE id=?');
        $stmt->execute([$rating, mb_substr(trim($comment), 0, 255, 'UTF-8'), $ticketId]);
        \auditLog($userId, 'support.rated', 'support_ticket', (string)$ticketId, ['rating' => $rating]);
    }

    /** کارکنان پشتیبان برای تخصیص */
    public function assignees(): array
    {
        return $this->db->query("SELECT u.id, u.full_name FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.code IN ('support','system_admin') AND u.status='active' GROUP BY u.id, u.full_name ORDER BY u.full_name ASC")->fetchAll();
    }
}
