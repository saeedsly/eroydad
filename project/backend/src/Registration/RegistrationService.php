<?php
declare(strict_types=1);

namespace Eroydad\Registration;

use Eroydad\Notifications\NotificationService;
use PDO;

final class RegistrationService
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db=$db; }

    /**
     * ثبت‌نام کاربر در رویداد.
     * - رویداد رایگان: بلافاصله قطعی و بلیت صادر می‌شود.
     * - رویداد پولی: ثبت‌نام در وضعیت «در انتظار پرداخت آزمایشی» قرار می‌گیرد.
     * - ظرفیت تکمیل: کاربر در فهرست انتظار قرار می‌گیرد.
     *
     * @return array{status:string,registration_id:int,payment_id:int,position:int,message:string}
     */
    public function register(int $eventId, int $userId, string $idempotencyKey): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $idempotencyKey)) throw new \DomainException('درخواست ثبت‌نام معتبر نیست. صفحه را تازه‌سازی کنید.');
        $this->db->beginTransaction();
        try {
            $eventStmt = $this->db->prepare("SELECT e.* FROM events e WHERE e.id=? FOR UPDATE");
            $eventStmt->execute([$eventId]);
            $event = $eventStmt->fetch();
            if (!$event || $event['status'] !== 'published') throw new \DomainException('این رویداد برای ثبت‌نام فعال نیست.');
            if (strtotime($event['starts_at_utc'] . ' UTC') <= time()) throw new \DomainException('مهلت ثبت‌نام این رویداد پایان یافته است.');
            $userStmt=$this->db->prepare("SELECT account_type FROM users WHERE id=? AND status='active' FOR UPDATE");
            $userStmt->execute([$userId]); $accountType=$userStmt->fetchColumn();
            if (!$accountType) throw new \DomainException('حساب فعال پیدا نشد.');
            $existing = $this->db->prepare('SELECT id,status FROM registrations WHERE event_id=? AND user_id=? LIMIT 1');
            $existing->execute([$eventId,$userId]);
            $old = $existing->fetch();
            if ($old && in_array($old['status'], ['confirmed','pending_payment'], true)) {
                throw new \DomainException('شما قبلاً در این رویداد ثبت‌نام کرده‌اید.');
            }
            $count = $this->db->prepare("SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='confirmed'");
            $count->execute([$eventId]);
            $confirmed = (int)$count->fetchColumn();
            $capacity = (int)$event['capacity'];
            $price = (int)$event['price_toman'];
            $full = $confirmed >= $capacity;
            $status = $full ? 'waitlisted' : ($price > 0 ? 'pending_payment' : 'confirmed');

            if ($old) {
                $upd = $this->db->prepare("UPDATE registrations SET status=?,idempotency_key=?,registered_at=UTC_TIMESTAMP(),cancelled_at=NULL,account_type=?,waitlist_position=?,waitlisted_at=?,amount_base_toman=0,amount_tax_toman=0,amount_total_toman=0 WHERE id=?");
                $upd->execute([$status,$idempotencyKey,$accountType,$full?$this->nextWaitlistPosition($eventId):null,$full?gmdate('Y-m-d H:i:s'):null,(int)$old['id']]);
                $registrationId = (int)$old['id'];
            } else {
                $ins = $this->db->prepare("INSERT INTO registrations (event_id,user_id,account_type,status,idempotency_key,waitlist_position,waitlisted_at) VALUES (?,?,?,?,?,?,?)");
                $ins->execute([$eventId,$userId,$accountType,$status,$idempotencyKey,$full?$this->nextWaitlistPosition($eventId):null,$full?gmdate('Y-m-d H:i:s'):null]);
                $registrationId = (int)$this->db->lastInsertId();
            }

            $paymentId = 0;
            if ($status === 'confirmed') {
                $this->issueTicket($registrationId, $userId);
                \auditLog($userId, 'registration.confirmed_free', 'registration', (string)$registrationId, ['event_id' => $eventId]);
            } elseif ($status === 'pending_payment') {
                $payments = new \Eroydad\Payments\PaymentService($this->db);
                $paymentId = $payments->createPending($registrationId, $eventId, $userId, $event);
                \auditLog($userId, 'registration.pending_payment', 'registration', (string)$registrationId, ['event_id' => $eventId, 'payment_id' => $paymentId]);
            } else {
                \auditLog($userId, 'registration.waitlisted', 'registration', (string)$registrationId, ['event_id' => $eventId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') throw new \DomainException('ثبت‌نام تکراری یا همزمان بود؛ صفحه را تازه کنید.');
            throw $e;
        }

        $notifications = new NotificationService($this->db);
        if ($status === 'confirmed') {
            $notifications->notify($userId, 'registration', 'ثبت‌نام شما قطعی شد', 'بلیت «' . $event['title'] . '» در پنل شما آماده است.', '/account', 'registration:' . $registrationId . ':confirmed');
        } elseif ($status === 'pending_payment') {
            $notifications->notify($userId, 'payment', 'پرداخت آزمایشی باقی مانده', 'برای نهایی‌شدن ثبت‌نام، پرداخت آزمایشی را از پنل انجام دهید.', '/account', 'registration:' . $registrationId . ':pending_payment');
        } else {
            $notifications->notify($userId, 'registration', 'در فهرست انتظار قرار گرفتید', 'به‌محض آزادشدن ظرفیت، ثبت‌نام شما قطعی می‌شود.', '/account', 'registration:' . $registrationId . ':waitlisted');
        }

        return [
            'status' => $status,
            'registration_id' => $registrationId,
            'payment_id' => $paymentId,
            'position' => $status === 'waitlisted' ? $this->nextWaitlistPosition($eventId) - 1 : 0,
            'message' => $status === 'confirmed'
                ? 'ثبت‌نام شما قطعی شد و بلیت در پنل شما آماده است.'
                : ($status === 'pending_payment'
                    ? 'ثبت‌نام شما ثبت شد؛ برای نهایی‌شدن، پرداخت آزمایشی را انجام دهید.'
                    : 'ظرفیت تکمیل است؛ شما در فهرست انتظار قرار گرفتید.'),
        ];
    }

    /** ثبت‌نام رایگان (سازگاری با مسیر قدیمی) */
    public function registerFree(int $eventId, int $userId, string $idempotencyKey): int
    {
        $result = $this->register($eventId, $userId, $idempotencyKey);
        if ($result['status'] !== 'confirmed') {
            throw new \DomainException('ثبت‌نام این رویداد نیازمند پرداخت آزمایشی یا فهرست انتظار است.');
        }
        return $result['registration_id'];
    }

    public function nextWaitlistPosition(int $eventId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(waitlist_position),0) FROM registrations WHERE event_id=? AND status='waitlisted'");
        $stmt->execute([$eventId]);
        return (int)$stmt->fetchColumn() + 1;
    }

    public function waitlistPosition(int $registrationId): int
    {
        $stmt = $this->db->prepare('SELECT waitlist_position FROM registrations WHERE id=? AND status=\'waitlisted\'');
        $stmt->execute([$registrationId]);
        return (int)$stmt->fetchColumn();
    }

    /** تأیید پرداخت آزمایشی و قطعی‌کردن ثبت‌نام */
    public function confirmPaid(int $registrationId, int $base, int $tax, int $total): void
    {
        $stmt = $this->db->prepare("UPDATE registrations SET status='confirmed', amount_base_toman=?, amount_tax_toman=?, amount_total_toman=?, registered_at=UTC_TIMESTAMP() WHERE id=?");
        $stmt->execute([$base, $tax, $total, $registrationId]);
        $row = $this->db->prepare('SELECT user_id FROM registrations WHERE id=?');
        $row->execute([$registrationId]);
        $userId = (int)$row->fetchColumn();
        if ($userId > 0) {
            $this->issueTicket($registrationId, $userId);
        }
    }

    /** لغو ثبت‌نام پولی (پس از لغو پرداخت یا استرداد) */
    public function cancelPaid(int $registrationId, string $reason): void
    {
        $stmt = $this->db->prepare("UPDATE registrations SET status='cancelled', cancelled_at=UTC_TIMESTAMP(), cancelled_reason=? WHERE id=?");
        $stmt->execute([$reason, $registrationId]);
        $this->db->prepare("UPDATE tickets SET status='revoked', revoked_at=UTC_TIMESTAMP() WHERE registration_id=?")->execute([$registrationId]);
    }

    /** انصراف کاربر و آزادسازی ظرفیت */
    public function cancelByUser(int $registrationId, int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT r.id, r.event_id, r.status, e.created_by, e.price_toman FROM registrations r JOIN events e ON e.id=r.event_id WHERE r.id=? AND r.user_id=? FOR UPDATE');
        $stmt->execute([$registrationId, $userId]);
        $registration = $stmt->fetch();
        if (!$registration) {
            throw new \DomainException('ثبت‌نام پیدا نشد.');
        }
        if (!in_array($registration['status'], ['confirmed', 'waitlisted', 'pending_payment'], true)) {
            throw new \DomainException('این ثبت‌نام قبلاً لغو شده است.');
        }
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare("UPDATE registrations SET status='cancelled', cancelled_at=UTC_TIMESTAMP(), cancelled_reason='انصراف کاربر', waitlist_position=NULL WHERE id=?");
            $update->execute([$registrationId]);
            $this->db->prepare("UPDATE tickets SET status='revoked', revoked_at=UTC_TIMESTAMP() WHERE registration_id=?")->execute([$registrationId]);
            \auditLog($userId, 'registration.cancelled', 'registration', (string)$registrationId, ['event_id' => (int)$registration['event_id']]);
            $this->promoteFromWaitlist((int)$registration['event_id']);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $notifications = new NotificationService($this->db);
        $notifications->notify($userId, 'registration', 'ثبت‌نام لغو شد', 'ظرفیت آزادشده به دیگران تخصیص یافت.', '/account', 'registration:' . $registrationId . ':cancelled');
        return true;
    }

    /** ارتقای نفر اول فهرست انتظار به ثبت‌نام قطعی (یا انتظار پرداخت) */
    public function promoteFromWaitlist(int $eventId): int
    {
        $eventStmt = $this->db->prepare('SELECT capacity, price_toman, title FROM events WHERE id=?');
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return 0;
        }
        $promoted = 0;
        while (true) {
            $count = $this->db->prepare("SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='confirmed'");
            $count->execute([$eventId]);
            if ((int)$count->fetchColumn() >= (int)$event['capacity']) {
                break;
            }
            $next = $this->db->prepare("SELECT id, user_id FROM registrations WHERE event_id=? AND status='waitlisted' ORDER BY waitlist_position ASC, id ASC LIMIT 1 FOR UPDATE");
            $next->execute([$eventId]);
            $row = $next->fetch();
            if (!$row) {
                break;
            }
            $registrationId = (int)$row['id'];
            $userId = (int)$row['user_id'];
            $price = (int)$event['price_toman'];
            if ($price > 0) {
                $this->db->prepare("UPDATE registrations SET status='pending_payment', waitlist_position=NULL, waitlisted_at=NULL WHERE id=?")->execute([$registrationId]);
                $payments = new \Eroydad\Payments\PaymentService($this->db);
                $payments->createPending($registrationId, $eventId, $userId, $event);
            } else {
                $this->db->prepare("UPDATE registrations SET status='confirmed', waitlist_position=NULL, waitlisted_at=NULL WHERE id=?")->execute([$registrationId]);
                $this->issueTicket($registrationId, $userId);
            }
            $notifications = new NotificationService($this->db);
            $notifications->notify($userId, 'registration', 'ظرفیت آزاد شد', 'ثبت‌نام شما از فهرست انتظار به حالت ' . ($price > 0 ? 'در انتظار پرداخت آزمایشی' : 'قطعی') . ' تغییر کرد.', '/account', 'waitlist:' . $registrationId . ':promoted');
            \auditLog(null, 'registration.promoted', 'registration', (string)$registrationId, ['event_id' => $eventId]);
            $promoted++;
            if ($promoted > 100) {
                break;
            }
        }
        return $promoted;
    }

    /** صدور بلیت (در صورت نبود، بلیت تازه ساخته می‌شود) */
    public function issueTicket(int $registrationId, int $actorId = 0): string
    {
        $check = $this->db->prepare('SELECT id, public_code, status FROM tickets WHERE registration_id=? LIMIT 1');
        $check->execute([$registrationId]);
        $ticket = $check->fetch();
        if ($ticket) {
            if ($ticket['status'] !== 'issued') {
                $this->db->prepare("UPDATE tickets SET status='issued', revoked_at=NULL WHERE id=?")->execute([(int)$ticket['id']]);
            }
            return (string)$ticket['public_code'];
        }
        $code = self::uuidV4();
        $stmt = $this->db->prepare('INSERT INTO tickets (registration_id,public_code,status,issued_by) VALUES (?, ?, \'issued\', ?)');
        $stmt->execute([$registrationId, $code, $actorId > 0 ? $actorId : null]);
        return $code;
    }

    public static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }
}
