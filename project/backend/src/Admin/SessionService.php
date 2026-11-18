<?php
declare(strict_types=1);

namespace Eroydad\Admin;

use PDO;

/**
 * SessionService — نشست‌های فعال کاربران و خروج از همهٔ دستگاه‌ها.
 */
final class SessionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** ثبت نشست جاری کاربر */
    public function recordCurrent(int $userId): void
    {
        if ($userId < 1 || !isset($_SESSION['user_id'])) {
            return;
        }
        $sessionId = session_id();
        if (!is_string($sessionId) || $sessionId === '') {
            return;
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $agent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
        $stmt = $this->db->prepare('INSERT INTO user_sessions (user_id, session_hash, ip, user_agent) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP(), ip=VALUES(ip), user_agent=VALUES(user_agent)');
        $stmt->execute([$userId, $this->hash($sessionId), $ip, $agent]);
    }

    /** @return array<int,array<string,mixed>> */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, ip, user_agent, created_at, last_seen_at, revoked_at FROM user_sessions WHERE user_id=? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function isCurrentSession(int $sessionRowId): bool
    {
        if (!isset($_SESSION['user_id']) || !is_string(session_id()) || session_id() === '') {
            return false;
        }
        $stmt = $this->db->prepare('SELECT session_hash FROM user_sessions WHERE id=?');
        $stmt->execute([$sessionRowId]);
        $hash = (string)$stmt->fetchColumn();
        return $hash !== '' && hash_equals($hash, $this->hash(session_id()));
    }

    /** خروج از یک نشست مشخص */
    public function revoke(int $sessionRowId, int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND revoked_at IS NULL');
        $stmt->execute([$sessionRowId, $userId]);
        \auditLog($userId, 'security.session.revoked', 'session', (string)$sessionRowId);
    }

    /** خروج از همهٔ دستگاه‌ها */
    public function revokeAll(int $userId, int $actorId): int
    {
        $stmt = $this->db->prepare('UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL');
        $stmt->execute([$userId]);
        $affected = $stmt->rowCount();
        $this->db->prepare('UPDATE users SET session_version=session_version+1 WHERE id=?')->execute([$userId]);
        \auditLog($actorId, 'security.session.revoked_all', 'user', (string)$userId, ['sessions' => $affected]);
        return $affected;
    }

    /** پاک‌سازی نشست‌های قدیمی */
    public function cleanup(int $days = 30): int
    {
        $stmt = $this->db->prepare('DELETE FROM user_sessions WHERE last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
        $stmt->execute([max(1, min(365, $days))]);
        return $stmt->rowCount();
    }
}
