<?php
declare(strict_types=1);

namespace Eroydad\Events;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class EventService
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db=$db; }

    public function featured(int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        $stmt = $this->db->query("SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS registered_count FROM events e WHERE e.status='published' AND e.starts_at_utc >= UTC_TIMESTAMP() ORDER BY e.starts_at_utc ASC LIMIT " . $limit);
        return $stmt->fetchAll();
    }

    /**
     * جست‌وجو و فیلتر پیشرفتهٔ دوره‌ها.
     * @param array<string,string> $filters q, category, mode, tag, from, to, price, sort
     */
    public function search(array $filters = []): array
    {
        $q = trim((string)($filters['q'] ?? ''));
        $category = trim((string)($filters['category'] ?? ''));
        $mode = trim((string)($filters['mode'] ?? ''));
        $tag = trim((string)($filters['tag'] ?? ''));
        $from = trim((string)($filters['from'] ?? ''));
        $to = trim((string)($filters['to'] ?? ''));
        $price = trim((string)($filters['price'] ?? ''));
        $sort = trim((string)($filters['sort'] ?? 'soon'));

        $sql = "SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS registered_count FROM events e WHERE e.status='published' AND e.starts_at_utc >= UTC_TIMESTAMP()";
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (e.title LIKE ? OR e.summary LIKE ? OR e.category LIKE ? OR e.description LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($category !== '') { $sql .= ' AND e.category = ?'; $params[] = $category; }
        if (in_array($mode, ['online', 'in_person', 'hybrid'], true)) { $sql .= ' AND e.delivery_mode = ?'; $params[] = $mode; }
        if ($tag !== '') { $sql .= ' AND EXISTS (SELECT 1 FROM event_tags t WHERE t.event_id=e.id AND t.tag = ?)'; $params[] = $tag; }
        if ($from !== '') {
            try { $sql .= ' AND e.starts_at_utc >= ?'; $params[] = self::localDateToUtc($from . 'T00:00'); }
            catch (\Throwable $e) { /* تاریخ نامعتبر نادیده گرفته می‌شود */ }
        }
        if ($to !== '') {
            try { $sql .= ' AND e.starts_at_utc <= ?'; $params[] = self::localDateToUtc($to . 'T23:59'); }
            catch (\Throwable $e) { /* تاریخ نامعتبر نادیده گرفته می‌شود */ }
        }
        if ($price === 'free') { $sql .= ' AND e.price_toman = 0'; }
        if ($price === 'paid') { $sql .= ' AND e.price_toman > 0'; }
        if ($sort === 'newest') {
            $sql .= ' ORDER BY e.created_at DESC';
        } elseif ($sort === 'capacity') {
            $sql .= ' ORDER BY (e.capacity - (SELECT COUNT(*) FROM registrations r2 WHERE r2.event_id=e.id AND r2.status=\'confirmed\')) ASC';
        } else {
            $sql .= ' ORDER BY e.starts_at_utc ASC';
        }
        $sql .= ' LIMIT 120';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function categories(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT category FROM events WHERE status='published' ORDER BY category ASC");
        return array_column($stmt->fetchAll(), 'category');
    }

    public function bySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS registered_count FROM events e WHERE e.slug=? AND e.status='published' LIMIT 1");
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function allForAdmin(int $actorId, bool $systemAdmin): array
    {
        $stmt=$this->db->prepare('SELECT e.*, u.full_name AS creator_name, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status="confirmed") AS registered_count FROM events e JOIN users u ON u.id=e.created_by WHERE e.created_by=? OR ?=1 ORDER BY e.created_at DESC LIMIT 300');
        $stmt->execute([$actorId,$systemAdmin?1:0]);
        return $stmt->fetchAll();
    }

    public function forAdmin(int $eventId, int $actorId, bool $systemAdmin): ?array
    {
        $stmt=$this->db->prepare('SELECT e.*,(SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status="confirmed") AS registered_count FROM events e WHERE e.id=? AND (e.created_by=? OR ?=1)');
        $stmt->execute([$eventId,$actorId,$systemAdmin?1:0]);
        return $stmt->fetch()?:null;
    }

    public function create(int $creatorId, array $input): int
    {
        $data = $this->validateInput($input);
        $slug = 'event-' . gmdate('ymd') . '-' . bin2hex(random_bytes(5));
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO events (slug,title,summary,description,category,delivery_mode,venue,starts_at_utc,ends_at_utc,capacity,price_toman,tax_percent,tax_payer,instructor_name,instructor_bio,is_multi_session,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)");
            $stmt->execute([
                $slug, $data['title'], $data['summary'], $data['description'], $data['category'], $data['mode'], $data['venue'],
                $data['start'], $data['end'], $data['capacity'], $data['price'], $data['tax_percent'], $data['tax_payer'],
                $data['instructor_name'], $data['instructor_bio'], $data['is_multi_session'], $creatorId,
            ]);
            $id = (int)$this->db->lastInsertId();
            \auditLog($creatorId, 'event.create', 'event', (string)$id, ['slug' => $slug, 'price' => $data['price']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    public function update(int $id, int $actorId, array $input, bool $systemAdmin = false): void
    {
        $data = $this->validateInput($input);
        $this->db->beginTransaction();
        try {
            $exists=$this->db->prepare("SELECT id FROM events WHERE id=? AND status IN ('draft','published') AND starts_at_utc>UTC_TIMESTAMP() AND (created_by=? OR ?=1) FOR UPDATE");
            $exists->execute([$id,$actorId,$systemAdmin?1:0]);
            if (!$exists->fetchColumn()) throw new \DomainException('رویداد پیدا نشد یا قابل ویرایش نیست.');
            $count=$this->db->prepare("SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='confirmed'"); $count->execute([$id]);
            if ($data['capacity']<(int)$count->fetchColumn()) throw new \DomainException('ظرفیت جدید از تعداد ثبت‌نام قطعی کمتر است.');
            $stmt=$this->db->prepare("UPDATE events SET title=?,summary=?,description=?,category=?,delivery_mode=?,venue=?,starts_at_utc=?,ends_at_utc=?,capacity=?,price_toman=?,tax_percent=?,tax_payer=?,instructor_name=?,instructor_bio=?,is_multi_session=? WHERE id=? AND status IN ('draft','published')");
            $stmt->execute([
                $data['title'],$data['summary'],$data['description'],$data['category'],$data['mode'],$data['venue'],
                $data['start'],$data['end'],$data['capacity'],$data['price'],$data['tax_percent'],$data['tax_payer'],
                $data['instructor_name'],$data['instructor_bio'],$data['is_multi_session'],$id,
            ]);
            \auditLog($actorId,'event.update','event',(string)$id,['capacity'=>$data['capacity'],'price'=>$data['price'],'tax_percent'=>$data['tax_percent']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validateInput(array $input): array
    {
        $title = trim((string)($input['title'] ?? ''));
        $summary = trim((string)($input['summary'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $category = trim((string)($input['category'] ?? ''));
        $mode = (string)($input['delivery_mode'] ?? 'in_person');
        $venue = trim((string)($input['venue'] ?? ''));
        $capacity = filter_var($input['capacity'] ?? null, FILTER_VALIDATE_INT);
        $price = filter_var($input['price_toman'] ?? '0', FILTER_VALIDATE_INT);
        $taxPercent = (float)str_replace(',', '.', (string)($input['tax_percent'] ?? '0'));
        $taxPayer = (string)($input['tax_payer'] ?? 'participant');
        $instructorName = trim((string)($input['instructor_name'] ?? ''));
        $instructorBio = trim((string)($input['instructor_bio'] ?? ''));
        $multiSession = !empty($input['is_multi_session']) ? 1 : 0;

        if (mb_strlen($title, 'UTF-8') < 5 || mb_strlen($title, 'UTF-8') > 220) throw new \DomainException('عنوان باید بین ۵ تا ۲۲۰ نویسه باشد.');
        if (mb_strlen($summary, 'UTF-8') < 10 || mb_strlen($summary, 'UTF-8') > 500) throw new \DomainException('خلاصه باید بین ۱۰ تا ۵۰۰ نویسه باشد.');
        if (mb_strlen($description, 'UTF-8') < 20 || mb_strlen($description, 'UTF-8') > 12000) throw new \DomainException('توضیحات باید بین ۲۰ تا ۱۲۰۰۰ نویسه باشد.');
        if ($category === '' || mb_strlen($category, 'UTF-8') > 100) throw new \DomainException('دسته‌بندی معتبر نیست.');
        if (!in_array($mode, ['online','in_person','hybrid'], true)) throw new \DomainException('شیوه برگزاری معتبر نیست.');
        if (($mode !== 'online') && $venue === '') throw new \DomainException('برای دوره حضوری/ترکیبی، محل برگزاری را وارد کنید.');
        if (!$capacity || $capacity < 1 || $capacity > 100000) throw new \DomainException('ظرفیت باید بین ۱ تا ۱۰۰۰۰۰ باشد.');
        if ($price === false || $price === null) $price = 0;
        if ($price < 0 || $price > 500000000) throw new \DomainException('مبلغ دوره نامعتبر است.');
        if ($taxPercent < 0 || $taxPercent > 100) throw new \DomainException('نرخ مالیات باید بین ۰ تا ۱۰۰ درصد باشد.');
        if (!in_array($taxPayer, ['participant','organizer'], true)) throw new \DomainException('پرداخت‌کنندهٔ مالیات معتبر نیست.');
        if ($price === 0 && $taxPercent > 0) throw new \DomainException('برای دورهٔ رایگان، نرخ مالیات باید صفر باشد.');
        if (mb_strlen($instructorName, 'UTF-8') > 160) throw new \DomainException('نام مدرس بیش از حد طولانی است.');
        if (mb_strlen($instructorBio, 'UTF-8') > 2000) throw new \DomainException('سوابق مدرس بیش از حد طولانی است.');
        $start = self::localDateToUtc((string)($input['starts_at'] ?? ''));
        $end = self::localDateToUtc((string)($input['ends_at'] ?? ''));
        if ($end <= $start) throw new \DomainException('زمان پایان باید بعد از زمان شروع باشد.');
        if ($start <= gmdate('Y-m-d H:i:s')) throw new \DomainException('زمان شروع باید در آینده باشد.');
        return [
            'title' => $title, 'summary' => $summary, 'description' => $description, 'category' => $category,
            'mode' => $mode, 'venue' => $venue, 'capacity' => (int)$capacity, 'price' => (int)$price,
            'tax_percent' => $taxPercent, 'tax_payer' => $taxPayer,
            'instructor_name' => $instructorName !== '' ? $instructorName : null,
            'instructor_bio' => $instructorBio !== '' ? $instructorBio : null,
            'is_multi_session' => $multiSession,
            'start' => $start, 'end' => $end,
        ];
    }

    public function publish(int $id, int $actorId, bool $systemAdmin = false): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE events SET status='published', published_at=UTC_TIMESTAMP() WHERE id=? AND status='draft' AND starts_at_utc > UTC_TIMESTAMP() AND (created_by=? OR ?=1)");
            $stmt->execute([$id,$actorId,$systemAdmin?1:0]);
            if ($stmt->rowCount() !== 1) throw new \DomainException('رویداد قابل انتشار نیست؛ وضعیت یا زمان آن را بررسی کنید.');
            \auditLog($actorId, 'event.publish', 'event', (string)$id);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function cancel(int $id, int $actorId, bool $systemAdmin = false): void
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT status,starts_at_utc,created_by FROM events WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $event = $lock->fetch();
            if (!$event || (!($systemAdmin) && (int)$event['created_by']!==$actorId) || !in_array($event['status'], ['draft','published'], true) || strtotime($event['starts_at_utc'] . ' UTC') <= time()) throw new \DomainException('رویداد پیدا نشد، شروع شده یا قابل لغو نیست.');
            $this->db->prepare("UPDATE events SET status='cancelled' WHERE id=?")->execute([$id]);
            $this->db->prepare("UPDATE registrations SET status='cancelled', cancelled_at=UTC_TIMESTAMP(), cancelled_reason='لغو رویداد' WHERE event_id=? AND status IN ('confirmed','waitlisted','pending_payment')")->execute([$id]);
            $this->db->prepare("UPDATE tickets t JOIN registrations r ON r.id=t.registration_id SET t.status='revoked', revoked_at=UTC_TIMESTAMP() WHERE r.event_id=?")->execute([$id]);
            $this->db->prepare("UPDATE payments SET status='cancelled', failure_reason='لغو رویداد' WHERE event_id=? AND status='pending'")->execute([$id]);
            \auditLog($actorId, 'event.cancel', 'event', (string)$id);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function attendees(int $eventId, int $actorId, bool $systemAdmin): array
    {
        $stmt = $this->db->prepare("SELECT u.full_name,u.phone,r.account_type,r.registered_at,ci.checked_at,r.id AS registration_id,r.status,r.waitlist_position,t.id AS ticket_id,t.public_code,t.status AS ticket_status FROM registrations r JOIN users u ON u.id=r.user_id JOIN tickets t ON t.registration_id=r.id JOIN events e ON e.id=r.event_id LEFT JOIN checkins ci ON ci.ticket_id=t.id WHERE r.event_id=? AND r.status IN ('confirmed','waitlisted') AND (e.created_by=? OR ?=1) ORDER BY r.registered_at DESC");
        $stmt->execute([$eventId,$actorId,$systemAdmin?1:0]);
        return $stmt->fetchAll();
    }

    /** شمارش زندهٔ شرکت‌کنندگان */
    public function liveCount(int $eventId): array
    {
        $stmt = $this->db->prepare("SELECT
            SUM(CASE WHEN r.status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN r.status='waitlisted' THEN 1 ELSE 0 END) AS waitlisted,
            SUM(CASE WHEN r.status='pending_payment' THEN 1 ELSE 0 END) AS pending_payment,
            COUNT(*) AS total
            FROM registrations r WHERE r.event_id=?");
        $stmt->execute([$eventId]);
        $row = $stmt->fetch() ?: [];
        $checkStmt = $this->db->prepare('SELECT COUNT(*) FROM checkins ci JOIN registrations r ON r.id=ci.ticket_id WHERE r.event_id=?');
        $checkStmt->execute([$eventId]);
        $eventStmt = $this->db->prepare('SELECT capacity FROM events WHERE id=?');
        $eventStmt->execute([$eventId]);
        return [
            'confirmed' => (int)($row['confirmed'] ?? 0),
            'waitlisted' => (int)($row['waitlisted'] ?? 0),
            'pending_payment' => (int)($row['pending_payment'] ?? 0),
            'total' => (int)($row['total'] ?? 0),
            'checked_in' => (int)$checkStmt->fetchColumn(),
            'capacity' => (int)$eventStmt->fetchColumn(),
        ];
    }

    public static function localDateToUtc(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \DomainException('تاریخ/ساعت معتبر نیست.');
        }
        $value = str_replace(' ', 'T', $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Tehran'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) throw new \DomainException('تاریخ/ساعت معتبر نیست.');
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
