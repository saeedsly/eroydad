<?php
declare(strict_types=1);

namespace Eroydad\Auth;

use Eroydad\Database\Connection;
use Eroydad\Security\IranianNationalId;
use Eroydad\Security\RateLimiter;
use PDO;

final class AuthService
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db=$db;
        $this->config=$config;
    }

    public function register(string $name, string $phone, string $password, string $accountType, ?string $nationalId, ?string $municipality): int
    {
        $name = trim($name);
        $phone = \normalizePhone($phone);
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 160) throw new \DomainException('نام باید بین ۲ تا ۱۶۰ نویسه باشد.');
        if (!\validIranMobile($phone)) throw new \DomainException('شماره موبایل معتبر نیست.');
        if (mb_strlen($password,'UTF-8') < 12 || mb_strlen($password,'UTF-8') > 200) throw new \DomainException('رمز عبور باید بین ۱۲ تا ۲۰۰ نویسه داشته باشد.');
        if (!in_array($accountType, ['public', 'municipal'], true)) throw new \DomainException('نوع حساب معتبر نیست.');

        $isEmployee = false;
        $verifiedMunicipality = null;
        if ($accountType === 'municipal') {
            $normalizedId = IranianNationalId::normalize((string)$nationalId);
            if (!IranianNationalId::isValid($normalizedId)) throw new \DomainException('کد ملی معتبر نیست.');
            $hmac = IranianNationalId::hmac($normalizedId, $this->config['app']['employee_hmac_key']);
            $check = $this->db->prepare('SELECT municipality FROM employee_allowlist WHERE national_id_hmac = ? AND is_active = 1 LIMIT 1');
            $check->execute([$hmac]);
            $verifiedMunicipality = $check->fetchColumn();
            if (!$verifiedMunicipality) throw new \DomainException('این کد ملی در فهرست مجاز کارکنان ثبت نشده است. با پشتیبانی مؤسسه تماس بگیرید.');
            $isEmployee = true;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO users (phone, full_name, municipality, password_hash, account_type, employee_verified_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$phone, $name, $verifiedMunicipality, password_hash(\passwordMaterial($password), PASSWORD_DEFAULT), $accountType, $isEmployee ? gmdate('Y-m-d H:i:s') : null]);
            $userId = (int)$this->db->lastInsertId();
            $role = $this->db->prepare("SELECT id FROM roles WHERE code = 'attendee'");
            $role->execute();
            $roleId = (int)$role->fetchColumn();
            if ($roleId < 1) throw new \RuntimeException('نقش attendee در schema وجود ندارد.');
            $grant = $this->db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            $grant->execute([$userId, $roleId]);
            \auditLog($userId, 'account.register', 'user', (string)$userId, ['account_type' => $accountType, 'employee_verified' => $isEmployee]);
            $this->db->commit();
            return $userId;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e->getCode() === '23000') throw new \DomainException('این شماره موبایل قبلاً حساب دارد.');
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function login(string $phone, string $password, string $ip, string $twoFactorCode = ''): int
    {
        $phone = \normalizePhone($phone);
        $limiter = new RateLimiter($this->db, $this->config['app']['app_key']);
        $limiter->assertLoginAllowed($phone, $ip);
        $stmt = $this->db->prepare("SELECT id, password_hash, status, session_version, two_factor_enabled FROM users WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $passwordOk = password_verify(\passwordMaterial(mb_substr($password,0,200,'UTF-8')), $row['password_hash'] ?? $dummyHash) && mb_strlen($password,'UTF-8')<=200;
        $ok = $row && $row['status'] === 'active' && $passwordOk;
        $limiter->record($phone, $ip, (bool)$ok);
        if (!$ok) throw new \DomainException('شماره موبایل یا رمز عبور اشتباه است.');
        if ((int)($row['two_factor_enabled'] ?? 0) === 1) {
            $twoFactor = new \Eroydad\Security\TwoFactorService($this->db);
            if (!$twoFactor->verify((int)$row['id'], $twoFactorCode)) {
                \auditLog((int)$row['id'], 'auth.login.2fa_failed', 'user', (string)$row['id']);
                throw new \DomainException('کد تأیید دومرحله‌ای نادرست یا منقضی شده است.');
            }
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $update = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $update->execute([password_hash(\passwordMaterial($password), PASSWORD_DEFAULT), (int)$row['id']]);
        }
        $touch = $this->db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?');
        $touch->execute([(int)$row['id']]);
        session_regenerate_id(true);
        $_SESSION['_created_at'] = time();
        $_SESSION['_last_activity'] = time();
        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['session_version'] = (int)$row['session_version'];
        unset($_SESSION['_csrf']);
        \auditLog((int)$row['id'], 'auth.login', 'user', (string)$row['id']);
        try {
            (new \Eroydad\Admin\SessionService($this->db))->recordCurrent((int)$row['id']);
        } catch (\Throwable $e) {
            error_log('session record failed: ' . $e->getMessage());
        }
        return (int)$row['id'];
    }

    public function logout(): void
    {
        $user = \currentUser();
        if ($user) \auditLog((int)$user['id'], 'auth.logout', 'user', (string)$user['id']);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }
}
