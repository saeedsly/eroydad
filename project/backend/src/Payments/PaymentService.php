<?php
declare(strict_types=1);

namespace Eroydad\Payments;

use Eroydad\Notifications\NotificationService;
use Eroydad\Registration\RegistrationService;
use PDO;

/**
 * PaymentService — اسناد مالی کاملاً آزمایشی (sandbox).
 * هیچ وجه واقعی جابه‌جا نمی‌شود؛ فقط رکوردهای آزمایشی در دیتابیس ثبت می‌گردد.
 */
final class PaymentService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * ساخت سند پرداخت آزمایشی برای یک ثبت‌نام در انتظار پرداخت.
     * @return int شناسهٔ سند
     */
    public function createPending(int $registrationId, int $eventId, int $userId, array $event): int
    {
        $price = (int)($event['price_toman'] ?? 0);
        $taxPercent = (float)($event['tax_percent'] ?? 0);
        $taxPayer = (string)($event['tax_payer'] ?? 'participant');
        $breakdown = Money::breakdown($price, $taxPercent, $taxPayer);
        $reference = 'SBX-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $this->db->prepare("INSERT INTO payments (registration_id,event_id,user_id,provider,is_sandbox,status,base_toman,tax_toman,total_toman,tax_payer,scenario,reference_code) VALUES (?,?,?,'sandbox',1,'pending',?,?,?,?, 'success', ?)");
        $stmt->execute([
            $registrationId, $eventId, $userId,
            $breakdown['base'], $breakdown['tax'], $breakdown['total'], $taxPayer, $reference,
        ]);
        $paymentId = (int)$this->db->lastInsertId();
        $update = $this->db->prepare('UPDATE registrations SET amount_base_toman=?, amount_tax_toman=?, amount_total_toman=? WHERE id=?');
        $update->execute([$breakdown['base'], $breakdown['tax'], $breakdown['total'], $registrationId]);
        \auditLog($userId, 'payment.sandbox.created', 'payment', (string)$paymentId, [
            'event_id' => $eventId, 'total' => $breakdown['total'], 'sandbox' => true,
        ]);
        return $paymentId;
    }

    /**
     * اجرای تراکنش آزمایشی و اعمال نتیجه روی ثبت‌نام.
     * @return array{status:string,message:string,payment:array}
     */
    public function process(int $paymentId, int $userId, string $scenario): array
    {
        $payment = $this->payment($paymentId);
        if (!$payment) {
            throw new \DomainException('سند پرداخت پیدا نشد.');
        }
        if ((int)$payment['user_id'] !== $userId) {
            throw new \DomainException('این سند پرداخت متعلق به شما نیست.');
        }
        if ($payment['status'] === 'paid') {
            throw new \DomainException('این سند پرداخت قبلاً با موفقیت ثبت شده است.');
        }
        if ($payment['status'] === 'refunded') {
            throw new \DomainException('این سند پرداخت قبلاً استرداد شده است.');
        }
        // ادامهٔ پرداخت: سندِ «ناموفق» قابل تلاش دوباره است (دکمهٔ «ادامهٔ پرداخت آزمایشی»).
        // سندِ لغو‌شده ادامه‌پذیر نیست؛ کاربر دوباره ثبت‌نام می‌کند و سند تازه می‌گیرد.
        if ($payment['status'] !== 'pending' && $payment['status'] !== 'failed') {
            throw new \DomainException('این سند پرداخت در وضعیت قابل پرداخت نیست.');
        }
        $result = SandboxGateway::charge($scenario, (int)$payment['total_toman'], ['payment_id' => $paymentId]);
        $this->db->beginTransaction();
        try {
            if ($result['status'] === 'paid') {
                $update = $this->db->prepare("UPDATE payments SET status='paid', paid_at=UTC_TIMESTAMP(), reference_code=?, scenario=?, failure_reason='' WHERE id=?");
                $update->execute([$result['reference'], $scenario, $paymentId]);
                $registrations = new RegistrationService($this->db);
                $registrations->confirmPaid((int)$payment['registration_id'], (int)$payment['base_toman'], (int)$payment['tax_toman'], (int)$payment['total_toman']);
                $ledger = $this->db->prepare("INSERT INTO payment_ledger (payment_id,event_id,entry_type,amount_toman,note,actor_id) VALUES (?,?,'charge',?,?,?)");
                $ledger->execute([$paymentId, (int)$payment['event_id'], (int)$payment['total_toman'], 'تراکنش آزمایشی موفق', $userId]);
                $notifications = new NotificationService($this->db);
                $notifications->notify((int)$payment['user_id'], 'payment', 'پرداخت آزمایشی ثبت شد', 'بلیت شما صادر شد و در پنل فراگیر در دسترس است.', '/account', 'payment:' . $paymentId . ':paid');
            } elseif ($result['status'] === 'cancelled') {
                $update = $this->db->prepare("UPDATE payments SET status='cancelled', scenario=?, failure_reason=? WHERE id=?");
                $update->execute([$scenario, $result['message'], $paymentId]);
                $registrations = new RegistrationService($this->db);
                $registrations->cancelPaid((int)$payment['registration_id'], 'انصراف از پرداخت آزمایشی');
            } else {
                $update = $this->db->prepare("UPDATE payments SET status='failed', scenario=?, failure_reason=? WHERE id=?");
                $update->execute([$scenario, $result['message'], $paymentId]);
            }
            \auditLog($userId, 'payment.sandbox.' . $result['status'], 'payment', (string)$paymentId, ['scenario' => $scenario, 'sandbox' => true]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return ['status' => $result['status'], 'message' => $result['message'], 'payment' => $this->payment($paymentId) ?? []];
    }

    /** استرداد آزمایشی */
    public function refund(int $paymentId, int $actorId, string $note = ''): void
    {
        $payment = $this->payment($paymentId);
        if (!$payment) {
            throw new \DomainException('سند پرداخت پیدا نشد.');
        }
        if ($payment['status'] !== 'paid') {
            throw new \DomainException('فقط سند پرداخت موفق قابل استرداد است.');
        }
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare("UPDATE payments SET status='refunded', refunded_at=UTC_TIMESTAMP() WHERE id=?");
            $update->execute([$paymentId]);
            $ledger = $this->db->prepare("INSERT INTO payment_ledger (payment_id,event_id,entry_type,amount_toman,note,actor_id) VALUES (?,?,'refund',?,?,?)");
            $ledger->execute([$paymentId, (int)$payment['event_id'], -(int)$payment['total_toman'], $note !== '' ? $note : 'استرداد آزمایشی', $actorId]);
            $registrations = new RegistrationService($this->db);
            $registrations->cancelPaid((int)$payment['registration_id'], 'استرداد آزمایشی');
            \auditLog($actorId, 'payment.sandbox.refunded', 'payment', (string)$paymentId, ['amount' => (int)$payment['total_toman']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function payment(int $paymentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE id=? LIMIT 1');
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** رسید با ریز مبلغ و مالیات */
    public function receipt(int $paymentId, int $viewerId, bool $isAdmin = false): ?array
    {
        $stmt = $this->db->prepare('SELECT p.*, e.title AS event_title, e.slug AS event_slug, e.starts_at_utc, e.ends_at_utc, u.full_name, u.phone FROM payments p JOIN events e ON e.id=p.event_id JOIN users u ON u.id=p.user_id WHERE p.id=? LIMIT 1');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            return null;
        }
        if (!$isAdmin && (int)$payment['user_id'] !== $viewerId) {
            return null;
        }
        $breakdown = Money::breakdown((int)$payment['base_toman'], 0, (string)$payment['tax_payer']);
        $payment['payer_label'] = Money::payerLabel((string)$payment['tax_payer']);
        $payment['breakdown'] = [
            'base' => (int)$payment['base_toman'],
            'tax' => (int)$payment['tax_toman'],
            'total' => (int)$payment['total_toman'],
            'payer_label' => $payment['payer_label'],
        ];
        $ledgerStmt = $this->db->prepare('SELECT entry_type, amount_toman, note, created_at FROM payment_ledger WHERE payment_id=? ORDER BY id ASC');
        $ledgerStmt->execute([$paymentId]);
        $payment['ledger'] = $ledgerStmt->fetchAll();
        return $payment;
    }

    /** فهرست اسناد یک رویداد */
    public function forEvent(int $eventId): array
    {
        $stmt = $this->db->prepare('SELECT p.*, u.full_name FROM payments p JOIN users u ON u.id=p.user_id WHERE p.event_id=? ORDER BY p.id DESC LIMIT 1000');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    /** دفتر ثبت مالی آزمایشی */
    public function ledger(int $limit = 500, int $eventId = 0): array
    {
        $sql = 'SELECT l.*, p.reference_code, p.status AS payment_status, e.title AS event_title FROM payment_ledger l JOIN payments p ON p.id=l.payment_id JOIN events e ON e.id=l.event_id';
        $params = [];
        if ($eventId > 0) {
            $sql .= ' WHERE l.event_id=?';
            $params[] = $eventId;
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ' . max(1, min(2000, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** جمع‌بندی مالی یک رویداد */
    public function eventSummary(int $eventId): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS docs, COALESCE(SUM(CASE WHEN status='paid' THEN total_toman ELSE 0 END),0) AS paid_total, COALESCE(SUM(CASE WHEN status='paid' THEN tax_toman ELSE 0 END),0) AS paid_tax, COALESCE(SUM(CASE WHEN status='refunded' THEN total_toman ELSE 0 END),0) AS refunded_total, COALESCE(SUM(CASE WHEN status='pending' THEN total_toman ELSE 0 END),0) AS pending_total FROM payments WHERE event_id=?");
        $stmt->execute([$eventId]);
        $summary = $stmt->fetch() ?: [];
        $summary['docs'] = (int)($summary['docs'] ?? 0);
        $summary['paid_total'] = (int)($summary['paid_total'] ?? 0);
        $summary['paid_tax'] = (int)($summary['paid_tax'] ?? 0);
        $summary['refunded_total'] = (int)($summary['refunded_total'] ?? 0);
        $summary['pending_total'] = (int)($summary['pending_total'] ?? 0);
        $summary['net_total'] = $summary['paid_total'] - $summary['refunded_total'];
        return $summary;
    }
}
