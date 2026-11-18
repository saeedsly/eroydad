<?php
declare(strict_types=1);

namespace Eroydad\Support;

use PDO;

final class SupportService
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db=$db; }

    public function create(int $userId, ?int $eventId, string $subject, string $body): int
    {
        $subject=trim($subject); $body=trim($body);
        if (mb_strlen($subject,'UTF-8')<4 || mb_strlen($subject,'UTF-8')>180) throw new \DomainException('عنوان تیکت باید بین ۴ تا ۱۸۰ نویسه باشد.');
        if (mb_strlen($body,'UTF-8')<10 || mb_strlen($body,'UTF-8')>5000) throw new \DomainException('متن پیام باید بین ۱۰ تا ۵۰۰۰ نویسه باشد.');
        if ($eventId) {
            $check=$this->db->prepare("SELECT id FROM registrations WHERE event_id=? AND user_id=? AND status='confirmed'");
            $check->execute([$eventId,$userId]);
            if (!$check->fetchColumn()) $eventId=null;
        }
        $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('INSERT INTO support_tickets (user_id,event_id,subject) VALUES (?,?,?)');
            $s->execute([$userId,$eventId,$subject]);
            $id=(int)$this->db->lastInsertId();
            $m=$this->db->prepare('INSERT INTO support_messages (ticket_id,author_id,body) VALUES (?,?,?)');
            $m->execute([$id,$userId,$body]);
            \auditLog($userId,'support.create','support_ticket',(string)$id);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function forUser(int $userId): array
    {
        $s=$this->db->prepare('SELECT st.*,e.title AS event_title FROM support_tickets st LEFT JOIN events e ON e.id=st.event_id WHERE st.user_id=? ORDER BY st.updated_at DESC LIMIT 100');
        $s->execute([$userId]); return $s->fetchAll();
    }

    public function messages(int $ticketId, int $userId, bool $isAdmin): array
    {
        $check=$this->db->prepare('SELECT id,user_id FROM support_tickets WHERE id=?');
        $check->execute([$ticketId]); $ticket=$check->fetch();
        if (!$ticket || (!$isAdmin && (int)$ticket['user_id']!==$userId)) throw new \DomainException('تیکت پیدا نشد.');
        $s=$this->db->prepare("SELECT m.*,u.full_name,EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code IN ('support','event_admin','system_admin')) AS is_staff FROM support_messages m JOIN users u ON u.id=m.author_id WHERE m.ticket_id=? ORDER BY m.created_at");
        $s->execute([$ticketId]); return $s->fetchAll();
    }

    public function reply(int $ticketId, int $authorId, string $body, bool $isSupport): void
    {
        $body=trim($body);
        if (mb_strlen($body,'UTF-8')<2 || mb_strlen($body,'UTF-8')>5000) throw new \DomainException('پاسخ باید بین ۲ تا ۵۰۰۰ نویسه باشد.');
        $this->db->beginTransaction();
        try {
            $check=$this->db->prepare('SELECT user_id,status FROM support_tickets WHERE id=? FOR UPDATE');
            $check->execute([$ticketId]); $ticket=$check->fetch();
            if (!$ticket) throw new \DomainException('تیکت پیدا نشد.');
            if (!$isSupport && (int)$ticket['user_id']!==$authorId) throw new \DomainException('دسترسی مجاز نیست.');
            if ($ticket['status']==='closed') throw new \DomainException('این تیکت بسته شده است.');
            $m=$this->db->prepare('INSERT INTO support_messages (ticket_id,author_id,body) VALUES (?,?,?)');
            $m->execute([$ticketId,$authorId,$body]);
            $next=$isSupport?'answered':'open';
            $u=$this->db->prepare('UPDATE support_tickets SET status=? WHERE id=?'); $u->execute([$next,$ticketId]);
            \auditLog($authorId,$isSupport?'support.reply':'support.user_reply','support_ticket',(string)$ticketId);
            $this->db->commit();
        } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }

    public function adminList(): array
    {
        return $this->db->query('SELECT st.*,u.full_name,u.phone,e.title AS event_title FROM support_tickets st JOIN users u ON u.id=st.user_id LEFT JOIN events e ON e.id=st.event_id ORDER BY FIELD(st.status,"open","in_progress","answered","closed"),st.updated_at DESC LIMIT 250')->fetchAll();
    }
}
