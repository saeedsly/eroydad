<?php
declare(strict_types=1);

namespace Eroydad\Admin;

use Eroydad\Security\Permissions;
use PDO;

/**
 * RoleService — ماتریس نقش و مجوز و مدیریت نقش کاربران.
 */
final class RoleService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> */
    public function roles(): array
    {
        $stmt = $this->db->query('SELECT id, code, label_fa FROM roles ORDER BY id ASC');
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function permissions(): array
    {
        try {
            $stmt = $this->db->query('SELECT id, code, label_fa FROM permissions ORDER BY id ASC');
            $rows = $stmt->fetchAll();
            if ($rows !== []) {
                return $rows;
            }
        } catch (\Throwable $e) {
            // جدول هنوز ساخته نشده — از فهرست پیش‌فرض استفاده می‌شود
        }
        $rows = [];
        foreach (Permissions::all() as $code => $label) {
            $rows[] = ['id' => 0, 'code' => $code, 'label_fa' => $label];
        }
        return $rows;
    }

    /**
     * ماتریس نقش × مجوز.
     * @return array{roles:array<int,array<string,mixed>>,permissions:array<int,array<string,mixed>>,granted:array<string,bool>}
     */
    public function matrix(): array
    {
        $roles = $this->roles();
        $permissions = $this->permissions();
        $granted = [];
        try {
            $stmt = $this->db->query('SELECT r.code AS role_code, p.code AS permission_code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id');
            foreach ($stmt->fetchAll() as $row) {
                $granted[$row['role_code'] . '|' . $row['permission_code']] = true;
            }
        } catch (\Throwable $e) {
            foreach ($roles as $role) {
                foreach (Permissions::ROLE_DEFAULTS[$role['code']] ?? [] as $permission) {
                    $granted[$role['code'] . '|' . $permission] = true;
                }
            }
        }
        return ['roles' => $roles, 'permissions' => $permissions, 'granted' => $granted];
    }

    public function setPermission(string $roleCode, string $permissionCode, bool $enabled, int $actorId): void
    {
        $role = $this->db->prepare('SELECT id FROM roles WHERE code=?');
        $role->execute([$roleCode]);
        $roleId = (int)$role->fetchColumn();
        if ($roleId < 1) {
            throw new \DomainException('نقش موردنظر وجود ندارد.');
        }
        $permission = $this->db->prepare('SELECT id FROM permissions WHERE code=?');
        $permission->execute([$permissionCode]);
        $permissionId = (int)$permission->fetchColumn();
        if ($permissionId < 1) {
            throw new \DomainException('مجوز موردنظر وجود ندارد.');
        }
        if ($enabled) {
            $this->db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)')->execute([$roleId, $permissionId]);
        } else {
            $this->db->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?')->execute([$roleId, $permissionId]);
        }
        \auditLog($actorId, 'admin.role_permission.updated', 'role', $roleCode, ['permission' => $permissionCode, 'enabled' => $enabled]);
    }

    /** بازگرداندن نقش «مدیر سامانه» به حالت پیش‌فرض (نگهبان همیشه همهٔ مجوزها را دارد) */
    public function resetToDefaults(int $actorId): int
    {
        $this->db->exec('DELETE FROM role_permissions');
        $count = 0;
        $permStmt = $this->db->prepare('SELECT id FROM permissions WHERE code=?');
        $roleStmt = $this->db->prepare('SELECT id FROM roles WHERE code=?');
        $link = $this->db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)');
        foreach (Permissions::ROLE_DEFAULTS as $roleCode => $permissions) {
            $roleStmt->execute([$roleCode]);
            $roleId = (int)$roleStmt->fetchColumn();
            if ($roleId < 1) {
                continue;
            }
            foreach ($permissions as $permissionCode) {
                $permStmt->execute([$permissionCode]);
                $permissionId = (int)$permStmt->fetchColumn();
                if ($permissionId > 0) {
                    $link->execute([$roleId, $permissionId]);
                    $count++;
                }
            }
        }
        \auditLog($actorId, 'admin.role_permission.reset', 'role', null, ['links' => $count]);
        return $count;
    }

    /** @return array<int,string> کدهای مجوز کاربر */
    public function permissionsForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT p.code FROM role_permissions rp JOIN user_roles ur ON ur.role_id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=?');
        $stmt->execute([$userId]);
        $codes = array_column($stmt->fetchAll(), 'code');
        if ($codes !== []) {
            return $codes;
        }
        $fallback = $this->db->prepare('SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=?');
        $fallback->execute([$userId]);
        $result = [];
        foreach ($fallback->fetchAll() as $row) {
            foreach (Permissions::ROLE_DEFAULTS[$row['code']] ?? [] as $permission) {
                $result[] = $permission;
            }
        }
        return array_values(array_unique($result));
    }

    /** @return array<int,array<string,mixed>> کاربران به‌همراه نقش‌ها */
    public function userRoles(int $limit = 300): array
    {
        $stmt = $this->db->prepare('SELECT u.id, u.full_name, u.phone, u.account_type, u.status, GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ",") AS roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.id DESC LIMIT ' . max(1, min(1000, $limit)));
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function assignRole(int $userId, string $roleCode, bool $grant, int $actorId): void
    {
        $role = $this->db->prepare('SELECT id FROM roles WHERE code=?');
        $role->execute([$roleCode]);
        $roleId = (int)$role->fetchColumn();
        if ($roleId < 1) {
            throw new \DomainException('نقش موردنظر وجود ندارد.');
        }
        if ($grant) {
            $this->db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id, granted_by) VALUES (?,?,?)')->execute([$userId, $roleId, $actorId]);
        } else {
            $this->db->prepare('DELETE FROM user_roles WHERE user_id=? AND role_id=?')->execute([$userId, $roleId]);
        }
        \auditLog($actorId, 'admin.user_role.updated', 'user', (string)$userId, ['role' => $roleCode, 'granted' => $grant]);
    }
}
