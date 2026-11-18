<?php
declare(strict_types=1);

namespace Eroydad\Security;

/**
 * Permissions — ماتریس نقش و مجوز.
 * هر مجوز یک کد متنی دارد و در جدول permissions نگهداری می‌شود تا مدیر سامانه بتواند آن را مدیریت کند.
 */
final class Permissions
{
    /** @var array<string,string> */
    public const CATALOG = [
        'events.view_admin' => 'مشاهدهٔ فهرست رویدادها',
        'events.create' => 'ساخت رویداد',
        'events.edit' => 'ویرایش رویداد',
        'events.publish' => 'انتشار رویداد',
        'events.cancel' => 'لغو رویداد',
        'events.manage_content' => 'مدیریت پوستر، جلسات، برچسب و پیوست',
        'attendees.view' => 'مشاهدهٔ فهرست شرکت‌کنندگان',
        'attendees.manage' => 'ثبت‌نام دستی و لغو شرکت‌کننده',
        'tickets.checkin' => 'کنترل ورود',
        'tickets.reissue' => 'بازصدور بلیت',
        'certificates.issue' => 'صدور گواهی',
        'payments.view' => 'مشاهدهٔ اسناد مالی آزمایشی',
        'payments.refund' => 'استرداد آزمایشی',
        'reports.view' => 'مشاهدهٔ گزارش‌های مدیریتی',
        'reports.export' => 'خروجی Excel گزارش‌ها',
        'import.employees' => 'ورود فهرست کارکنان',
        'import.attendees' => 'ورود فهرست شرکت‌کنندگان',
        'support.manage' => 'مدیریت تیکت‌های پشتیبانی',
        'users.manage' => 'مدیریت کاربران و نقش‌ها',
        'audit.view' => 'مشاهدهٔ گزارش فعالیت سامانه',
        'system.health' => 'بررسی سلامت سامانه و پشتیبان‌گیری',
        'settings.manage' => 'تنظیمات سامانه و نگهداری داده',
    ];

    /** @var array<string,string[]> مجوزهای پیش‌فرض هر نقش */
    public const ROLE_DEFAULTS = [
        'attendee' => [],
        'event_admin' => [
            'events.view_admin', 'events.create', 'events.edit', 'events.publish', 'events.cancel', 'events.manage_content',
            'attendees.view', 'attendees.manage', 'tickets.checkin', 'tickets.reissue', 'certificates.issue',
            'payments.view', 'reports.view', 'reports.export', 'import.attendees',
        ],
        'support' => [
            'support.manage', 'attendees.view', 'events.view_admin', 'reports.view',
        ],
        'system_admin' => [
            'events.view_admin', 'events.create', 'events.edit', 'events.publish', 'events.cancel', 'events.manage_content',
            'attendees.view', 'attendees.manage', 'tickets.checkin', 'tickets.reissue', 'certificates.issue',
            'payments.view', 'payments.refund', 'reports.view', 'reports.export', 'import.employees', 'import.attendees',
            'support.manage', 'users.manage', 'audit.view', 'system.health', 'settings.manage',
        ],
    ];

    public static function all(): array
    {
        return self::CATALOG;
    }

    /** آیا کاربر این مجوز را دارد؟ (نقش‌ها از نشست خوانده می‌شوند) */
    public static function allows(?array $user, string $permission): bool
    {
        if (!$user) {
            return false;
        }
        if (in_array('system_admin', $user['roles'] ?? [], true)) {
            return true;
        }
        $granted = $user['permissions'] ?? null;
        if (is_array($granted)) {
            return in_array($permission, $granted, true);
        }
        foreach (($user['roles'] ?? []) as $role) {
            $defaults = self::ROLE_DEFAULTS[$role] ?? [];
            if (in_array($permission, $defaults, true)) {
                return true;
            }
        }
        return false;
    }
}
