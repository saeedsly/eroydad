<?php
declare(strict_types=1);

namespace Eroydad\Security;

use PDO;

final class RateLimiter
{
    private PDO $db;
    private string $secret;

    public function __construct(PDO $db, string $secret)
    {
        $this->db=$db;
        $this->secret=$secret;
    }

    public function assertLoginAllowed(string $phone, string $ip): void
    {
        $phoneHash = hash_hmac('sha256', $phone, $this->secret);
        $ipHash = hash_hmac('sha256', $ip, $this->secret);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE phone_hmac = ? AND was_successful = 0 AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)');
        $stmt->execute([$phoneHash]);
        $phoneCount = (int)$stmt->fetchColumn();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hmac = ? AND was_successful = 0 AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)');
        $stmt->execute([$ipHash]);
        $ipCount = (int)$stmt->fetchColumn();
        if ($phoneCount >= 8 || $ipCount >= 30) {
            throw new \DomainException('تعداد تلاش‌ها زیاد است. کمی بعد دوباره تلاش کنید.');
        }
    }

    public function assertRegistrationAllowed(string $phone, string $ip): void
    {
        $phoneHash = hash_hmac('sha256', $phone, $this->secret);
        $ipHash = hash_hmac('sha256', $ip, $this->secret);
        $byPhone=$this->db->prepare('SELECT COUNT(*) FROM registration_attempts WHERE phone_hmac=? AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)');
        $byPhone->execute([$phoneHash]);
        $byIp=$this->db->prepare('SELECT COUNT(*) FROM registration_attempts WHERE ip_hmac=? AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)');
        $byIp->execute([$ipHash]);
        if ((int)$byPhone->fetchColumn()>=5 || (int)$byIp->fetchColumn()>=20) throw new \DomainException('تعداد درخواست‌های ساخت حساب زیاد است. کمی بعد دوباره تلاش کنید.');
    }

    public function recordRegistration(string $phone, string $ip): void
    {
        $stmt=$this->db->prepare('INSERT INTO registration_attempts (phone_hmac,ip_hmac) VALUES (?,?)');
        $stmt->execute([hash_hmac('sha256',$phone,$this->secret),hash_hmac('sha256',$ip,$this->secret)]);
    }

    public function record(string $phone, string $ip, bool $success): void
    {
        $stmt = $this->db->prepare('INSERT INTO login_attempts (phone_hmac, ip_hmac, was_successful) VALUES (?, ?, ?)');
        $stmt->execute([
            hash_hmac('sha256', $phone, $this->secret),
            hash_hmac('sha256', $ip, $this->secret),
            $success ? 1 : 0,
        ]);
    }
}
