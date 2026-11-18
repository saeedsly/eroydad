<?php
declare(strict_types=1);

namespace Eroydad\Security;

use PDO;

/**
 * PasswordResetService — گردش‌کار امن بازیابی رمز بدون پیامک و ایمیل.
 * کد بازیابی توسط مدیر سامانه ساخته و به کاربر تحویل داده می‌شود؛ کد فقط به‌صورت هش نگهداری می‌گردد.
 */
final class PasswordResetService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * ساخت کد یک‌بارمصرف برای کاربر.
     * @return array{code:string,expires_at:string}
     */
    public function generate(int $userId, int $createdBy, int $validMinutes = 60): array
    {
        $code = strtoupper(bin2hex(random_bytes(3)) . '-' . bin2hex(random_bytes(3)));
        $expires = gmdate('Y-m-d H:i:s', time() + max(10, min(1440, $validMinutes)) * 60);
        $stmt = $this->db->prepare('INSERT INTO password_resets (user_id, code_hash, created_by, expires_at) VALUES (?,?,?,?)');
        $stmt->execute([$userId, hash('sha256', $code), $createdBy > 0 ? $createdBy : null, $expires]);
        \auditLog($createdBy > 0 ? $createdBy : null, 'security.password_reset.generated', 'user', (string)$userId, ['expires_at' => $expires]);
        return ['code' => $code, 'expires_at' => $expires];
    }

    /**
     * اعمال رمز جدید با کد.
     */
    public function reset(int $userId, string $code, string $newPassword): void
    {
        $code = strtoupper(trim($code));
        $stmt = $this->db->prepare('SELECT * FROM password_resets WHERE user_id=? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \DomainException('کد بازیابی برای این حساب ثبت نشده است. با پشتیبانی تماس بگیرید.');
        }
        if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
            throw new \DomainException('کد بازیابی صحیح نیست.');
        }
        if (strtotime((string)$row['expires_at'] . ' UTC') < time()) {
            throw new \DomainException('اعتبار کد بازیابی به پایان رسیده است.');
        }
        if (mb_strlen($newPassword, 'UTF-8') < 12 || mb_strlen($newPassword, 'UTF-8') > 200) {
            throw new \DomainException('رمز عبور باید بین ۱۲ تا ۲۰۰ نویسه باشد.');
        }
        $update = $this->db->prepare('UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE id=?');
        $update->execute([(int)$row['id']]);
        $userUpdate = $this->db->prepare('UPDATE users SET password_hash=?, session_version=session_version+1 WHERE id=?');
        $userUpdate->execute([password_hash(\passwordMaterial($newPassword), PASSWORD_DEFAULT), $userId]);
        \auditLog($userId, 'security.password_reset.applied', 'user', (string)$userId);
    }

    /** کدهای فعال یک کاربر */
    public function pendingFor(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, expires_at, created_at, used_at FROM password_resets WHERE user_id=? ORDER BY id DESC LIMIT 20');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** فهرست درخواست‌های بازیابی برای مدیر سامانه */
    public function listPending(): array
    {
        $stmt = $this->db->prepare('SELECT p.id, p.user_id, u.full_name, u.phone, p.expires_at, p.created_at, p.used_at FROM password_resets p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 100');
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
