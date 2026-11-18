<?php
declare(strict_types=1);

namespace Eroydad\Database;

use PDO;
use PDOException;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $db = $config['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        try {
            self::$pdo = new PDO($dsn, $db['user'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // «SET NAMES» و «time_zone» جداگانه اجرا می‌شوند تا به درایور
            // یا نسخهٔ PHP وابسته نباشیم (برخی ساخت‌ها init-command را اجرا نمی‌کنند).
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            error_log('Database connection failed; request_id=' . ($GLOBALS['request_id'] ?? 'unknown'));
            throw new \RuntimeException('ارتباط با پایگاه داده برقرار نشد. تنظیمات اتصال را بررسی کنید.');
        }
        return self::$pdo;
    }
}
