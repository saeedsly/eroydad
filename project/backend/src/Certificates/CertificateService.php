<?php
declare(strict_types=1);

namespace Eroydad\Certificates;

use PDO;

/**
 * CertificateService — صدور و راستی‌آزمایی گواهی پایان دوره.
 * کد گواهی عمومی است و از طریق نشانی /certificate/{code} قابل استعلام است.
 */
final class CertificateService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function issue(int $registrationId, int $actorId): string
    {
        $stmt = $this->db->prepare('SELECT r.id, r.user_id, r.event_id, r.status, e.title, e.ends_at_utc FROM registrations r JOIN events e ON e.id=r.event_id WHERE r.id=? LIMIT 1');
        $stmt->execute([$registrationId]);
        $registration = $stmt->fetch();
        if (!$registration) {
            throw new \DomainException('ثبت‌نام پیدا نشد.');
        }
        if ($registration['status'] !== 'confirmed') {
            throw new \DomainException('گواهی تنها برای ثبت‌نام قطعی صادر می‌شود.');
        }
        $existing = $this->db->prepare('SELECT code FROM certificates WHERE registration_id=? LIMIT 1');
        $existing->execute([$registrationId]);
        $code = (string)$existing->fetchColumn();
        if ($code !== '') {
            return $code;
        }
        $code = bin2hex(random_bytes(16));
        $insert = $this->db->prepare('INSERT INTO certificates (registration_id,event_id,user_id,code,issued_by) VALUES (?,?,?,?,?)');
        $insert->execute([$registrationId, (int)$registration['event_id'], (int)$registration['user_id'], $code, $actorId > 0 ? $actorId : null]);
        \auditLog($actorId, 'certificate.issued', 'certificate', (string)$registrationId, ['event_id' => (int)$registration['event_id']]);
        return $code;
    }

    /** صدور گروهی برای همهٔ شرکت‌کنندگان قطعی یک رویداد */
    public function issueForEvent(int $eventId, int $actorId): int
    {
        $stmt = $this->db->prepare("SELECT r.id FROM registrations r LEFT JOIN certificates c ON c.registration_id=r.id WHERE r.event_id=? AND r.status='confirmed' AND c.id IS NULL");
        $stmt->execute([$eventId]);
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            try {
                $this->issue((int)$row['id'], $actorId);
                $count++;
            } catch (\Throwable $e) {
                // رد شدن یک مورد مانع بقیه نیست
            }
        }
        return $count;
    }

    public function byCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT c.*, e.title AS event_title, e.starts_at_utc, e.ends_at_utc, e.category, u.full_name, r.status AS registration_status FROM certificates c JOIN events e ON e.id=c.event_id JOIN users u ON u.id=c.user_id JOIN registrations r ON r.id=c.registration_id WHERE c.code=? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function forRegistration(int $registrationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM certificates WHERE registration_id=? LIMIT 1');
        $stmt->execute([$registrationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** فهرست گواهی‌های یک کاربر */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT c.*, e.title AS event_title, e.ends_at_utc FROM certificates c JOIN events e ON e.id=c.event_id WHERE c.user_id=? ORDER BY c.id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** فهرست شرکت‌کنندگان قطعیِ بدون گواهی (برای صدور گروهی) */
    public function pendingForEvent(int $eventId): array
    {
        $stmt = $this->db->prepare("SELECT r.id, u.full_name, r.registered_at FROM registrations r JOIN users u ON u.id=r.user_id LEFT JOIN certificates c ON c.registration_id=r.id WHERE r.event_id=? AND r.status='confirmed' AND c.id IS NULL ORDER BY r.registered_at ASC");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }
}
