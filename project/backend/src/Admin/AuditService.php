<?php
declare(strict_types=1);

namespace Eroydad\Admin;

use PDO;

/**
 * AuditService — فیلتر و جست‌وجوی گزارش ممیزی سامانه.
 */
final class AuditService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<string,string> $filters action, entity, actor, from, to, q
     * @return array<int,array<string,mixed>>
     */
    public function query(array $filters = [], int $limit = 300): array
    {
        $sql = 'SELECT a.*, u.full_name AS actor_name, u.phone AS actor_phone FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id WHERE 1=1';
        $params = [];
        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '') {
            $sql .= ' AND a.action LIKE ?';
            $params[] = '%' . $action . '%';
        }
        $entity = trim((string)($filters['entity'] ?? ''));
        if ($entity !== '') {
            $sql .= ' AND a.entity_type = ?';
            $params[] = $entity;
        }
        $actor = trim((string)($filters['actor'] ?? ''));
        if ($actor !== '') {
            $sql .= ' AND a.actor_id = ?';
            $params[] = (int)$actor;
        }
        $from = trim((string)($filters['from'] ?? ''));
        if ($from !== '') {
            $sql .= ' AND a.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = trim((string)($filters['to'] ?? ''));
        if ($to !== '') {
            $sql .= ' AND a.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (a.safe_details LIKE ? OR a.entity_id LIKE ? OR a.action LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $limit = max(1, min(2000, $limit));
        $sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int,string> فهرست یکتای نوع موجودیت برای فیلتر */
    public function entities(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC');
        return array_column($stmt->fetchAll(), 'entity_type');
    }

    /** @return array<int,string> فهرست یکتای عملیات */
    public function actions(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC LIMIT 200');
        return array_column($stmt->fetchAll(), 'action');
    }

    /** آمار کلی گزارش فعالیت */
    public function summary(int $days = 7): array
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS total FROM audit_logs WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
        $stmt->execute([max(1, min(365, $days))]);
        $total = (int)$stmt->fetchColumn();
        $actors = $this->db->prepare('SELECT COUNT(DISTINCT actor_id) FROM audit_logs WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
        $actors->execute([max(1, min(365, $days))]);
        return ['total' => $total, 'actors' => (int)$actors->fetchColumn(), 'days' => $days];
    }
}
