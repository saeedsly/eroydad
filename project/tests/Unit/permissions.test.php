<?php
declare(strict_types=1);
/**
 * آزمون واحد: ماتریس نقش و مجوز (Permissions).
 * اجرا: tests/unit-run.mjs (PHP 7.4 واقعی روی WASM)
 */
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Security/Permissions.php';

use Eroydad\Security\Permissions;

$total = 0;
$fails = 0;
function t(string $label, bool $cond, string $detail = ''): void
{
    global $total, $fails;
    $total++;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        $fails++;
        echo "FAIL  $label" . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
    }
}

$catalog = Permissions::all();
t('کاتالوگ مجوزها خالی نیست', count($catalog) >= 20, (string)count($catalog));

$roles = array_keys(Permissions::ROLE_DEFAULTS);
t('دقیقاً چهار نقش پایه داریم', count($roles) === 4 && in_array('attendee', $roles, true) && in_array('system_admin', $roles, true) && in_array('event_admin', $roles, true) && in_array('support', $roles, true), implode(',', $roles));

$defaultsOk = true;
foreach (Permissions::ROLE_DEFAULTS as $role => $perms) {
    foreach ($perms as $perm) {
        if (!array_key_exists($perm, $catalog)) {
            $defaultsOk = false;
            echo "  مجوزِ ناشناخته در پیش‌فرض نقش $role: $perm\n";
        }
    }
}
t('هر مجوزِ پیش‌فرضِ نقش‌ها در کاتالوگ هست', $defaultsOk);

t('کاربر بدون نشست هیچ مجوزی ندارد', Permissions::allows(null, 'events.view_admin') === false);
t('فراگیر مجوز ادمین ندارد', Permissions::allows(['roles' => ['attendee']], 'events.view_admin') === false);
t('system_admin همهٔ مجوزها را دارد', Permissions::allows(['roles' => ['system_admin']], 'payments.refund') === true && Permissions::allows(['roles' => ['system_admin']], 'settings.manage') === true);
t('مدیر رویداد مجوز انتشار دارد ولی مجوز کاربران را ندارد', Permissions::allows(['roles' => ['event_admin']], 'events.publish') === true && Permissions::allows(['roles' => ['event_admin']], 'users.manage') === false);
t('پشتیبان مجوز مدیریت تیکت دارد ولی استرداد ندارد', Permissions::allows(['roles' => ['support']], 'support.manage') === true && Permissions::allows(['roles' => ['support']], 'payments.refund') === false);
t('مجوز ناشناخته برای نقش عادی false است', Permissions::allows(['roles' => ['attendee']], 'no.such.permission') === false && Permissions::allows(['roles' => ['support']], 'no.such.permission') === false);

// لیست صریح مجوزهای نشست بر پیش‌فرض نقش مقدم است
$explicit = ['roles' => ['attendee'], 'permissions' => ['reports.view']];
t('لیست صریح مجوزِ نشست اعمال می‌شود', Permissions::allows($explicit, 'reports.view') === true && Permissions::allows($explicit, 'events.create') === false);

echo "UNIT_DONE total=$total fail=$fails\n";
exit(0); // شمارش نهایی با UNIT_DONE انجام می‌شود
