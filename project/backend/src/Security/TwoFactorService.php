<?php
declare(strict_types=1);

namespace Eroydad\Security;

use PDO;

/**
 * TwoFactorService — احراز هویت دومرحله‌ای کاملاً محلی (TOTP + کدهای بازیابی).
 */
final class TwoFactorService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function status(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT enabled, secret, recovery_codes FROM two_factor WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $recovery = 0;
        if ($row && is_string($row['recovery_codes'] ?? null) && trim((string)$row['recovery_codes']) !== '') {
            $recovery = count(array_filter(explode("\n", (string)$row['recovery_codes'])));
        }
        return [
            'enabled' => (bool)($row['enabled'] ?? false),
            'has_secret' => ($row && trim((string)($row['secret'] ?? '')) !== ''),
            'recovery_count' => $recovery,
        ];
    }

    /** آغاز راه‌اندازی: راز ساخته و برای نمایش QR برگردانده می‌شود. */
    public function beginSetup(int $userId): array
    {
        $secret = Totp::generateSecret();
        $stmt = $this->db->prepare('INSERT INTO two_factor (user_id, secret, enabled) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 0');
        $stmt->execute([$userId, $secret]);
        $phone = '';
        $user = $this->db->prepare('SELECT phone FROM users WHERE id=?');
        $user->execute([$userId]);
        $phone = (string)$user->fetchColumn();
        return [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($secret, $phone),
        ];
    }

    /** فعال‌سازی پس از تأیید کد صحیح */
    public function enable(int $userId, string $code): array
    {
        $stmt = $this->db->prepare('SELECT secret FROM two_factor WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $secret = (string)$stmt->fetchColumn();
        if ($secret === '') {
            throw new \DomainException('ابتدا باید راه‌اندازی دومرحله‌ای را شروع کنید.');
        }
        if (!Totp::verify($secret, $code)) {
            throw new \DomainException('کد واردشده صحیح نیست.');
        }
        $recovery = $this->generateRecoveryCodes();
        $update = $this->db->prepare('UPDATE two_factor SET enabled=1, recovery_codes=? WHERE user_id=?');
        $update->execute([$this->hashCodes($recovery), $userId]);
        $this->db->prepare('UPDATE users SET two_factor_enabled=1 WHERE id=?')->execute([$userId]);
        \auditLog($userId, 'security.2fa.enabled', 'user', (string)$userId);
        return ['recovery_codes' => $recovery];
    }

    public function disable(int $userId, string $code): void
    {
        if (!$this->verify($userId, $code)) {
            throw new \DomainException('کد واردشده صحیح نیست.');
        }
        $this->db->prepare('UPDATE two_factor SET enabled=0, recovery_codes=NULL WHERE user_id=?')->execute([$userId]);
        $this->db->prepare('UPDATE users SET two_factor_enabled=0 WHERE id=?')->execute([$userId]);
        \auditLog($userId, 'security.2fa.disabled', 'user', (string)$userId);
    }

    /** بررسی کد هنگام ورود */
    public function verify(int $userId, string $code): bool
    {
        $stmt = $this->db->prepare('SELECT secret, enabled, recovery_codes FROM two_factor WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !(bool)$row['enabled']) {
            return true;
        }
        $secret = (string)$row['secret'];
        if ($secret !== '' && Totp::verify($secret, $code)) {
            return true;
        }
        $codes = array_filter(array_map('trim', explode("\n", (string)($row['recovery_codes'] ?? ''))));
        $clean = strtoupper(preg_replace('/\s+/', '', $code) ?? '');
        foreach ($codes as $stored) {
            if (hash_equals($stored, hash('sha256', $clean))) {
                $remaining = array_values(array_filter($codes, static fn ($c) => !hash_equals($c, hash('sha256', $clean))));
                $update = $this->db->prepare('UPDATE two_factor SET recovery_codes=? WHERE user_id=?');
                $update->execute([$remaining === [] ? null : implode("\n", $remaining), $userId]);
                \auditLog($userId, 'security.2fa.recovery_used', 'user', (string)$userId);
                return true;
            }
        }
        return false;
    }

    public function regenerateRecoveryCodes(int $userId): array
    {
        $codes = $this->generateRecoveryCodes();
        $stmt = $this->db->prepare('UPDATE two_factor SET recovery_codes=? WHERE user_id=?');
        $stmt->execute([$this->hashCodes($codes), $userId]);
        \auditLog($userId, 'security.2fa.recovery_regenerated', 'user', (string)$userId);
        return $codes;
    }

    /** @return array<int,string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(3)) . '-' . bin2hex(random_bytes(3)));
        }
        return $codes;
    }

    private function hashCodes(array $codes): string
    {
        $hashed = [];
        foreach ($codes as $code) {
            $hashed[] = hash('sha256', $code);
        }
        return implode("\n", $hashed);
    }
}
