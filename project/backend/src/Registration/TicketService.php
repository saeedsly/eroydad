<?php
declare(strict_types=1);

namespace Eroydad\Registration;

use PDO;

final class TicketService
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db=$db;
        $this->config=$config;
    }

    public function forUser(int $ticketId, int $userId): ?array
    {
        $sql = "SELECT t.id AS ticket_id,t.public_code,t.status AS ticket_status,r.id AS registration_id,r.status AS registration_status,u.full_name,e.title,e.slug,e.venue,e.delivery_mode,e.starts_at_utc,e.ends_at_utc,e.category FROM tickets t JOIN registrations r ON r.id=t.registration_id JOIN users u ON u.id=r.user_id JOIN events e ON e.id=r.event_id WHERE t.id=? AND r.user_id=? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ticketId,$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function token(array $ticket): string
    {
        return $ticket['public_code'] . '.' . hash_hmac('sha256',$ticket['public_code'],$this->config['app']['ticket_signing_key']);
    }

    public function checkIn(string $token, int $adminId, bool $systemAdmin = false, string $source = 'online', string $clientRef = ''): array
    {
        $token=trim($token);
        if (str_starts_with($token,'eroydad-ticket:')) $token=substr($token,15);
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9-]{36}$/i',$parts[0])) throw new \DomainException('QR یا کد بلیت معتبر نیست.');
        [$code,$signature] = $parts;
        $expected = hash_hmac('sha256',$code,$this->config['app']['ticket_signing_key']);
        if (!hash_equals($expected,$signature)) throw new \DomainException('امضای بلیت معتبر نیست.');
        if ($this->db->inTransaction()) throw new \RuntimeException('Check-in must start outside an existing transaction.');
        $this->db->beginTransaction();
        $ticket=null;
        try {
            $stmt=$this->db->prepare("SELECT t.id,t.status,r.status AS registration_status,e.title,e.id AS event_id,e.created_by FROM tickets t JOIN registrations r ON r.id=t.registration_id JOIN events e ON e.id=r.event_id WHERE t.public_code=? FOR UPDATE");
            $stmt->execute([$code]);
            $ticket=$stmt->fetch();
            if (!$ticket || (!$systemAdmin && (int)$ticket['created_by']!==$adminId) || $ticket['status'] !== 'issued' || $ticket['registration_status'] !== 'confirmed') {
                if ($this->db->inTransaction()) $this->db->rollBack();
                if ($ticket) \auditLog($adminId,'ticket.checkin.rejected','ticket',(string)$ticket['id'],['reason'=>'not_issued_or_authorized']);
                throw new \DomainException('بلیت پیدا نشد، لغو شده یا معتبر نیست.');
            }
            $insert=$this->db->prepare('INSERT INTO checkins (ticket_id,checked_by,source,client_ref,synced_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
            $insert->execute([(int)$ticket['id'],$adminId,$source,$clientRef !== '' ? $clientRef : null]);
            \auditLog($adminId,'ticket.checkin','ticket',(string)$ticket['id'],['event_id'=>(int)$ticket['event_id'],'source'=>$source]);
            $this->db->commit();
            return ['event'=>$ticket['title'],'checked_at'=>gmdate('Y-m-d H:i:s')];
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e->getCode()==='23000') {
                if (!empty($ticket['id'])) \auditLog($adminId,'ticket.checkin.rejected','ticket',(string)$ticket['id'],['reason'=>'already_checked_in']);
                throw new \DomainException('این بلیت قبلاً برای ورود استفاده شده است.');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * بازصدور امن بلیت: کد قبلی باطل و کد تازه صادر می‌شود.
     */
    public function reissue(int $ticketId, int $actorId, bool $systemAdmin = false): array
    {
        $stmt = $this->db->prepare('SELECT t.id, t.public_code, t.registration_id, t.reissue_count, r.user_id, r.event_id, e.created_by, r.status FROM tickets t JOIN registrations r ON r.id=t.registration_id JOIN events e ON e.id=r.event_id WHERE t.id=? LIMIT 1');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            throw new \DomainException('بلیت پیدا نشد.');
        }
        if (!$systemAdmin && (int)$ticket['created_by'] !== $actorId) {
            throw new \DomainException('دسترسی مجاز نیست.');
        }
        if ($ticket['status'] === 'revoked') {
            throw new \DomainException('این بلیت قبلاً باطل شده است.');
        }
        $oldCode = (string)$ticket['public_code'];
        $newCode = RegistrationService::uuidV4();
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare("UPDATE tickets SET public_code=?, status='issued', revoked_at=NULL, reissue_count=reissue_count+1, issued_by=? WHERE id=?");
            $update->execute([$newCode, $actorId, $ticketId]);
            \auditLog($actorId, 'ticket.reissued', 'ticket', (string)$ticketId, ['old_code' => $oldCode, 'new_code' => $newCode]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['ticket_id' => $ticketId, 'public_code' => $newCode, 'old_code' => $oldCode];
    }

    /** ثبت ورود از صف آفلاین (پس از بازگشت شبکه) */
    public function checkInFromQueue(int $ticketId, int $adminId, string $clientRef, bool $systemAdmin = false): array
    {
        $stmt = $this->db->prepare('SELECT t.id, t.status, t.public_code, r.status AS registration_status, r.event_id, e.title, e.created_by FROM tickets t JOIN registrations r ON r.id=t.registration_id JOIN events e ON e.id=r.event_id WHERE t.id=? LIMIT 1');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            throw new \DomainException('بلیت پیدا نشد.');
        }
        if (!$systemAdmin && (int)$ticket['created_by'] !== $adminId) {
            throw new \DomainException('دسترسی مجاز نیست.');
        }
        if ($ticket['status'] !== 'issued' || $ticket['registration_status'] !== 'confirmed') {
            throw new \DomainException('بلیت پیدا نشد، لغو شده یا معتبر نیست.');
        }
        $exists = $this->db->prepare('SELECT id FROM checkins WHERE ticket_id=?');
        $exists->execute([$ticketId]);
        if ($exists->fetchColumn()) {
            throw new \DomainException('این بلیت قبلاً برای ورود استفاده شده است.');
        }
        $insert = $this->db->prepare('INSERT INTO checkins (ticket_id, checked_by, source, client_ref, synced_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
        $insert->execute([$ticketId, $adminId, 'offline_queue', $clientRef !== '' ? $clientRef : null]);
        \auditLog($adminId, 'ticket.checkin.offline_synced', 'ticket', (string)$ticketId, ['client_ref' => $clientRef]);
        return ['event' => (string)$ticket['title'], 'checked_at' => gmdate('Y-m-d H:i:s')];
    }

    /** فهرست بلیت‌های یک رویداد برای پنل کنترل ورود */
    public function forEvent(int $eventId): array
    {
        $stmt = $this->db->prepare("SELECT t.id, t.public_code, t.status, r.status AS registration_status, u.full_name, u.phone FROM tickets t JOIN registrations r ON r.id=t.registration_id JOIN users u ON u.id=r.user_id WHERE r.event_id=? AND r.status='confirmed' ORDER BY r.registered_at ASC LIMIT 500");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }
}
