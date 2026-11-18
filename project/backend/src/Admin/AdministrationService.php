<?php
declare(strict_types=1);

namespace Eroydad\Admin;

use PDO;

final class AdministrationService
{
    private PDO $db;

    public function __construct(PDO $db) { $this->db=$db; }

    public function users(): array
    {
        return $this->db->query("SELECT u.id,u.phone,u.full_name,u.account_type,u.municipality,u.employee_verified_at,u.status,u.created_at,GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ', ') AS roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id,u.phone,u.full_name,u.account_type,u.municipality,u.employee_verified_at,u.status,u.created_at ORDER BY u.created_at DESC LIMIT 300")->fetchAll();
    }

    public function changeStatus(int $targetId, int $actorId, string $status): void
    {
        if (!in_array($status,['active','suspended'],true)) throw new \DomainException('وضعیت درخواستی معتبر نیست.');
        if ($targetId===$actorId && $status==='suspended') throw new \DomainException('مدیر واردشده نمی‌تواند حساب خودش را معلق کند.');
        $this->db->beginTransaction();
        try {
            $lock=$this->db->prepare('SELECT id,status FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$targetId]); $target=$lock->fetch();
            if (!$target) throw new \DomainException('حساب کاربری پیدا نشد.');
            if ($target['status']===$status) throw new \DomainException('وضعیت حساب تغییری نکرد.');
            if ($status==='suspended') {
                $admins=$this->db->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.code='system_admin' AND u.status='active' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array($targetId,array_map('intval',$admins),true) && count($admins)<=1) throw new \DomainException('آخرین مدیر فعال سامانه را نمی‌توان معلق کرد.');
            }
            $update=$this->db->prepare('UPDATE users SET status=?,session_version=session_version+1 WHERE id=?');
            $update->execute([$status,$targetId]);
            \auditLog($actorId,'user.status_change','user',(string)$targetId,['from'=>$target['status'],'to'=>$status]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function auditLogs(): array
    {
        return $this->db->query('SELECT a.id,a.actor_id,a.action,a.entity_type,a.entity_id,a.request_id,a.safe_details,a.created_at,u.full_name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 300')->fetchAll();
    }
}
