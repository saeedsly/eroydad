<?php
declare(strict_types=1);

/**
 * جدول مسیرهای برنامه.
 *
 * هر مسیر در فایل‌های backend/routes/*.php با یک فراخوانیِ route() اعلان می‌شود:
 *
 *     if (route('GET', '/admin/events', $method, $path)) { … }
 *     if (route('GET', '#^/events/([a-z0-9-]+)$#', $method, $path, $m)) { … }
 *
 * قرارداد:
 *  - آرگومان دوم اگر با # شروع شود الگوی regex است (همان جداکننده‌ای که در کد قدیمی
 *    به preg_match داده می‌شد)؛ وگرگنه مقایسهٔ دقیق رشته است.
 *  - فقط وقتی متد و الگو هر دو خوردند true برمی‌گردد؛ خروجی false یعنی مسیر بعدی
 *    باید بررسی شود (رفتار قبلی عیناً حفظ شده است).
 *  - هر فراخوانی، جفت (method, pattern) را در جدول اجرا-زمان ثبت می‌کند تا تست‌ها
 *    و ابزار تولید مستندات بتوانند روی همان جدول حساب کنند.
 *
 * این فایل فقط تعریف تابع است؛ هیچ اثری روی جریان درخواست ندارد.
 */

function route(string $wantMethod, string $pattern, string $method, string $path, ?array &$captures = null): bool
{
    RouteRegistry::add($wantMethod, $pattern);
    if ($method !== $wantMethod) {
        return false;
    }
    if ($pattern !== '' && $pattern[0] === '#') {
        return (bool)preg_match($pattern, $path, $captures);
    }
    return $path === $pattern;
}

/**
 * جدول اجرا-زمان مسیرها: لیست جفت‌های [method, pattern] به ترتیبِ اعلان.
 */
final class RouteRegistry
{
    /** @var array<int, array{0:string,1:string}> */
    private static array $entries = [];

    public static function add(string $method, string $pattern): void
    {
        self::$entries[] = [$method, $pattern];
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function all(): array
    {
        return self::$entries;
    }
}
