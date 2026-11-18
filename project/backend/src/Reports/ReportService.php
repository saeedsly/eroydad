<?php
declare(strict_types=1);

namespace Eroydad\Reports;

use PDO;

/**
 * ReportService — گزارش‌های مدیریتی: روند ثبت‌نام، ظرفیت، تطبیق کارکنان، مالی و پشتیبانی.
 */
final class ReportService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** روند ثبت‌نام روزانهٔ یک دوره */
    public function registrationTrend(int $eventId, int $days = 30): array
    {
        $days = max(1, min(180, $days));
        $stmt = $this->db->prepare("SELECT DATE(registered_at) AS day, COUNT(*) AS total FROM registrations WHERE event_id=? AND status IN ('confirmed','pending_payment') AND registered_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY) GROUP BY DATE(registered_at) ORDER BY day ASC");
        $stmt->execute([$eventId, $days]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string)$row['day']] = (int)$row['total'];
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $series[] = [
                'date' => $day,
                'label' => jalaliDate($day . ' 00:00:00', 'Y/m/d'),
                'count' => $map[$day] ?? 0,
            ];
        }
        return $series;
    }

    /** گزارش ظرفیت و نرخ تکمیل */
    public function capacityReport(int $eventId): array
    {
        $stmt = $this->db->prepare("SELECT e.capacity, e.title,
            SUM(CASE WHEN r.status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN r.status='waitlisted' THEN 1 ELSE 0 END) AS waitlisted,
            SUM(CASE WHEN r.status='pending_payment' THEN 1 ELSE 0 END) AS pending_payment,
            SUM(CASE WHEN r.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
            COUNT(r.id) AS total_rows
            FROM events e LEFT JOIN registrations r ON r.event_id=e.id WHERE e.id=? GROUP BY e.id, e.capacity, e.title");
        $stmt->execute([$eventId]);
        $row = $stmt->fetch() ?: [];
        $capacity = (int)($row['capacity'] ?? 0);
        $confirmed = (int)($row['confirmed'] ?? 0);
        $checkin = $this->db->prepare('SELECT COUNT(*) FROM checkins ci JOIN registrations r ON r.id=ci.ticket_id WHERE r.event_id=?');
        $checkin->execute([$eventId]);
        $checkedIn = (int)$checkin->fetchColumn();
        return [
            'title' => (string)($row['title'] ?? ''),
            'capacity' => $capacity,
            'confirmed' => $confirmed,
            'waitlisted' => (int)($row['waitlisted'] ?? 0),
            'pending_payment' => (int)($row['pending_payment'] ?? 0),
            'cancelled' => (int)($row['cancelled'] ?? 0),
            'checked_in' => $checkedIn,
            'fill_rate' => $capacity > 0 ? round($confirmed / $capacity * 100, 1) : 0.0,
            'attendance_rate' => $confirmed > 0 ? round($checkedIn / $confirmed * 100, 1) : 0.0,
        ];
    }

    /** گزارش تطبیق کارکنان و ردیف‌های ردشده */
    public function employeeReconciliation(): array
    {
        $batches = $this->db->query('SELECT b.*, u.full_name AS creator_name FROM employee_import_batches b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 50')->fetchAll();
        $total = (int)$this->db->query('SELECT COUNT(*) FROM employee_allowlist WHERE is_active=1')->fetchColumn();
        $accepted = 0;
        $rejected = 0;
        foreach ($batches as $batch) {
            $accepted += (int)$batch['accepted_rows'];
            $rejected += (int)$batch['rejected_rows'];
        }
        return [
            'batches' => $batches,
            'allowlist_total' => $total,
            'accepted_total' => $accepted,
            'rejected_total' => $rejected,
            'reject_rate' => ($accepted + $rejected) > 0 ? round($rejected / ($accepted + $rejected) * 100, 1) : 0.0,
        ];
    }

    /**
     * گزارش مدیریتی با بازهٔ زمانی.
     */
    public function managementReport(string $fromDate, string $toDate): array
    {
        $from = $fromDate . ' 00:00:00';
        $to = $toDate . ' 23:59:59';
        $events = $this->db->prepare("SELECT COUNT(*) FROM events WHERE created_at BETWEEN ? AND ?");
        $events->execute([$from, $to]);
        $registrations = $this->db->prepare("SELECT COUNT(*) FROM registrations WHERE registered_at BETWEEN ? AND ?");
        $registrations->execute([$from, $to]);
        $confirmed = $this->db->prepare("SELECT COUNT(*) FROM registrations WHERE status='confirmed' AND registered_at BETWEEN ? AND ?");
        $confirmed->execute([$from, $to]);
        $tickets = $this->db->prepare('SELECT COUNT(*) FROM support_tickets WHERE created_at BETWEEN ? AND ?');
        $tickets->execute([$from, $to]);
        $feedback = $this->db->prepare('SELECT COUNT(*) AS total, COALESCE(AVG(rating),0) AS average FROM feedbacks WHERE created_at BETWEEN ? AND ?');
        $feedback->execute([$from, $to]);
        $payments = $this->db->prepare("SELECT COUNT(*) AS docs, COALESCE(SUM(CASE WHEN status='paid' THEN total_toman ELSE 0 END),0) AS paid FROM payments WHERE created_at BETWEEN ? AND ?");
        $payments->execute([$from, $to]);
        $feedbackRow = $feedback->fetch() ?: [];
        $paymentRow = $payments->fetch() ?: [];
        return [
            'from' => $fromDate,
            'to' => $toDate,
            'events_created' => (int)$events->fetchColumn(),
            'registrations' => (int)$registrations->fetchColumn(),
            'registrations_confirmed' => (int)$confirmed->fetchColumn(),
            'support_tickets' => (int)$tickets->fetchColumn(),
            'feedback_total' => (int)($feedbackRow['total'] ?? 0),
            'feedback_average' => round((float)($feedbackRow['average'] ?? 0), 2),
            'payment_documents' => (int)($paymentRow['docs'] ?? 0),
            'payment_total' => (int)($paymentRow['paid'] ?? 0),
        ];
    }

    /** آمار پشتیبانی: SLA و زمان پاسخ */
    public function supportStats(): array
    {
        $row = $this->db->query("SELECT COUNT(*) AS total,
            SUM(CASE WHEN status IN ('open','in_progress') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN first_response_at IS NOT NULL THEN 1 ELSE 0 END) AS responded,
            SUM(CASE WHEN sla_due_at IS NOT NULL AND first_response_at IS NOT NULL AND first_response_at <= sla_due_at THEN 1 ELSE 0 END) AS within_sla,
            SUM(CASE WHEN sla_due_at IS NOT NULL AND first_response_at IS NULL AND sla_due_at < UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS breached
            FROM support_tickets")->fetch() ?: [];
        $avgStmt = $this->db->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) AS avg_minutes FROM support_tickets WHERE first_response_at IS NOT NULL");
        $avg = $avgStmt->fetchColumn();
        $csat = $this->db->query('SELECT AVG(satisfaction_rating) AS avg_rating, COUNT(*) AS total FROM support_tickets WHERE satisfaction_rating IS NOT NULL');
        $csatRow = $csat->fetch() ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'open' => (int)($row['open_count'] ?? 0),
            'responded' => (int)($row['responded'] ?? 0),
            'within_sla' => (int)($row['within_sla'] ?? 0),
            'breached' => (int)($row['breached'] ?? 0),
            'avg_response_minutes' => $avg !== null ? round((float)$avg, 1) : 0.0,
            'csat_average' => round((float)($csatRow['avg_rating'] ?? 0), 2),
            'csat_total' => (int)($csatRow['total'] ?? 0),
        ];
    }

    /** گزارش کنترل ورود با فیلتر */
    public function checkinReport(array $filters = [], int $limit = 500): array
    {
        $sql = 'SELECT ci.checked_at, ci.source, e.title AS event_title, u.full_name AS checked_by_name, t.public_code, us.full_name, us.phone
            FROM checkins ci JOIN tickets t ON t.id=ci.ticket_id JOIN registrations r ON r.id=t.registration_id JOIN events e ON e.id=r.event_id
            JOIN users us ON us.id=r.user_id JOIN users u ON u.id=ci.checked_by';
        $params = [];
        if (!empty($filters['event_id'])) {
            $sql .= ' WHERE r.event_id=?';
            $params[] = (int)$filters['event_id'];
        }
        if (!empty($filters['q'])) {
            $sql .= (empty($filters['event_id']) ? ' WHERE ' : ' AND ') . '(us.full_name LIKE ? OR t.public_code LIKE ?)';
            $like = '%' . trim((string)$filters['q']) . '%';
            array_push($params, $like, $like);
        }
        $limit = max(1, min(2000, $limit));
        $sql .= ' ORDER BY ci.id DESC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** فهرست رویدادها برای فیلتر گزارش */
    public function eventOptions(): array
    {
        return $this->db->query('SELECT id, title FROM events ORDER BY id DESC LIMIT 200')->fetchAll();
    }
}
