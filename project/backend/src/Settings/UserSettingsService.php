<?php
declare(strict_types=1);

namespace Eroydad\Settings;

use PDO;

/**
 * UserSettingsService — تنظیمات کاربر: پوستهٔ روشن/تیره و اندازهٔ قلم.
 */
final class UserSettingsService
{
    public const SCALES = [90 => 'کوچک', 100 => 'معمولی', 110 => 'بزرگ', 125 => 'خیلی بزرگ'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function get(int $userId): array
    {
        $theme = 'auto';
        $scale = 100;
        try {
            $stmt = $this->db->prepare('SELECT theme, font_scale FROM user_preferences WHERE user_id=? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row) {
                $theme = (string)($row['theme'] ?? 'auto');
                $scale = (int)($row['font_scale'] ?? 100);
            } else {
                $fallback = $this->db->prepare('SELECT theme, font_scale FROM users WHERE id=? LIMIT 1');
                $fallback->execute([$userId]);
                $user = $fallback->fetch();
                if ($user) {
                    $theme = (string)($user['theme'] ?? 'auto');
                    $scale = (int)($user['font_scale'] ?? 100);
                }
            }
        } catch (\Throwable $e) {
            // جدول تنظیمات هنوز ساخته نشده — مقدار پیش‌فرض
        }
        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            $theme = 'auto';
        }
        if (!isset(self::SCALES[$scale])) {
            $scale = 100;
        }
        return ['theme' => $theme, 'font_scale' => $scale];
    }

    public function save(int $userId, string $theme, int $scale): void
    {
        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            throw new \DomainException('حالت پوسته معتبر نیست.');
        }
        if (!isset(self::SCALES[$scale])) {
            throw new \DomainException('اندازهٔ قلم معتبر نیست.');
        }
        $stmt = $this->db->prepare('INSERT INTO user_preferences (user_id, theme, font_scale) VALUES (?,?,?) ON DUPLICATE KEY UPDATE theme=VALUES(theme), font_scale=VALUES(font_scale)');
        $stmt->execute([$userId, $theme, $scale]);
        $update = $this->db->prepare('UPDATE users SET theme=?, font_scale=? WHERE id=?');
        $update->execute([$theme, $scale, $userId]);
        \auditLog($userId, 'user.preferences.updated', 'user', (string)$userId, ['theme' => $theme, 'font_scale' => $scale]);
    }
}
