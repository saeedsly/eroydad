<?php
/** ناوبری مشترک پنل مدیریت — نقش‌محور */
$adminUser = $adminUser ?? ($user ?? null);
$isSys = $adminUser && hasRole($adminUser, 'system_admin');
$isEvt = $adminUser && (hasRole($adminUser, 'event_admin') || $isSys);
$isSup = $adminUser && (hasRole($adminUser, 'support') || $isEvt);
$adminLinks = [];
if ($isEvt) {
    $adminLinks['/admin'] = 'نمای کلی';
    $adminLinks['/admin/events'] = 'رویدادها';
    $adminLinks['/admin/check-in'] = 'کنترل ورود';
    $adminLinks['/admin/reports'] = 'گزارش‌ها';
    $adminLinks['/admin/import'] = 'ورود اطلاعات';
}
if ($isSup) {
    $adminLinks['/admin/support'] = 'پشتیبانی';
}
if ($isSys) {
    $adminLinks['/admin/users'] = 'کاربران';
    $adminLinks['/admin/roles'] = 'نقش و مجوز';
    $adminLinks['/admin/employees'] = 'کارکنان';
    $adminLinks['/admin/sessions'] = 'نشست‌ها';
    $adminLinks['/admin/audit'] = 'گزارش فعالیت';
    $adminLinks['/admin/system'] = 'سلامت سامانه';
}
$currentAdmin = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$baseAdmin = appBasePath();
if ($baseAdmin !== '' && strpos($currentAdmin, $baseAdmin) === 0) {
    $currentAdmin = substr($currentAdmin, strlen($baseAdmin)) ?: '/';
}
?>
<nav class="admin-nav" aria-label="ناوبری پنل مدیریت">
  <?php foreach ($adminLinks as $href => $label): ?>
    <a href="<?= h($href) ?>" <?= ($currentAdmin === $href || strpos($currentAdmin, $href . '/') === 0) ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
