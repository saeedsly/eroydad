<?php
$flash = $flash ?? takeFlash();
$currentPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$basePath = appBasePath();
if ($basePath !== '' && ($currentPath === $basePath || strpos($currentPath, $basePath . '/') === 0)) {
  $currentPath = substr($currentPath, strlen($basePath)) ?: '/';
}
$isEventAdmin = $user && (hasRole($user, 'event_admin') || hasRole($user, 'system_admin'));
$isSupportStaff = $user && (hasRole($user, 'support') || hasRole($user, 'event_admin') || hasRole($user, 'system_admin'));
$isSystemAdmin = $user && (hasRole($user, 'system_admin'));
$navCurrent = static function(string $path) use ($currentPath): string {
  return ($currentPath === $path || strpos($currentPath, $path . '/') === 0) ? 'aria-current="page"' : '';
};
$prefs = currentPreferences();
$unreadNotifications = 0;
if ($user) {
  try { $unreadNotifications = (new \Eroydad\Notifications\NotificationService(db()))->unreadCount((int)$user['id']); } catch (Throwable $e) { $unreadNotifications = 0; }
}
?><!doctype html>
<html lang="fa" dir="rtl" data-theme="<?= h($prefs['theme']) ?>" style="--font-scale:<?= (int)$prefs['font_scale'] ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#fafaf7">
  <meta name="description" content="ای‌رویداد؛ سامانه رویدادها و دوره‌های آموزشی شهرداری‌های خراسان رضوی.">
  <title><?= h($title ?? 'ای‌رویداد') ?> | ای‌رویداد</title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/assets/icons/app-icon.svg" type="image/svg+xml">
  <link rel="icon" href="/assets/icons/icon-192.png" sizes="192x192" type="image/png">
  <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
  <link rel="preload" href="/assets/fonts/vazirmatn-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= h(assetUrl('/assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= h(assetUrl('/assets/css/design-system.css')) ?>">
  <script src="<?= h(assetUrl('/assets/js/theme-boot.js')) ?>"></script>
</head>
<body>
<a class="skip-link" href="#main-content">پرش به محتوای اصلی</a>
<header class="site-header">
  <div class="container nav-row">
    <button class="menu-toggle" type="button" aria-label="باز کردن منو" aria-expanded="false" aria-controls="primary-navigation"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
    <a class="brand" href="/" aria-label="صفحه اصلی ای‌رویداد"><span class="brand-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M5 25h22M8 25V13l8-6 8 6v12M12 16h2v3h-2zm6 0h2v3h-2zM14 25v-5h4v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 12 16 4l12 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span><b>ای‌رویداد</b><small>رویدادهای الکترونیک شهرداری</small></span></a>
    <span class="header-spacer" aria-hidden="true"></span>
    <div class="nav-account">
      <?php if ($user): ?>
        <a class="nav-bell" href="/notifications" aria-label="اعلان‌ها (<?= toPersianDigits((string)$unreadNotifications) ?> خوانده‌نشده)"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true" width="16" height="16"><path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 2 6H4c.5-.5 2-2 2-6Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M10 19a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><?php if ($unreadNotifications > 0): ?><b><?= toPersianDigits((string)$unreadNotifications) ?></b><?php endif; ?></a>
        <a class="nav-user" href="/account"><?= h($user['full_name']) ?></a>
        <?php if ($isEventAdmin): ?><a class="btn btn-small btn-outline" href="/admin">مدیریت</a><?php endif; ?>
        <form method="post" action="/logout" class="inline-form"><?= csrfField() ?><button class="link-button" type="submit">خروج</button></form>
      <?php else: ?>
        <a class="nav-login" href="/login">ورود</a><a class="btn btn-small btn-primary" href="/register">ساخت حساب</a>
      <?php endif; ?>
      <button class="theme-toggle" type="button" data-theme-toggle aria-label="تغییر حالت روشن و تیره" title="حالت روشن/تیره"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true" width="18" height="18"><circle cx="12" cy="12" r="8.2" stroke="currentColor" stroke-width="1.7"/><path d="M12 3.8a8.2 8.2 0 0 1 0 16.4Z" fill="currentColor"/></svg></button>
    </div>
  </div>
</header>
<button class="drawer-scrim" type="button" data-menu-scrim aria-label="بستن منو" hidden></button>
<aside class="nav-links" id="primary-navigation" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="drawer-title">
  <div class="drawer-head"><div><span class="drawer-kicker">ای‌رویداد</span><h2 id="drawer-title">دسترسی‌ها</h2></div><button class="drawer-close" type="button" data-menu-close aria-label="بستن منو">×</button></div>
  <?php if ($user): ?><div class="drawer-user"><span class="drawer-user-avatar" aria-hidden="true"><?= h(function_exists('mb_substr')?mb_substr($user['full_name'],0,1,'UTF-8'):substr($user['full_name'],0,1)) ?></span><span><b><?= h($user['full_name']) ?></b><small><?= $isSystemAdmin?'مدیر سامانه':($isEventAdmin?'مدیر رویداد':($isSupportStaff?'پشتیبان':'فراگیر')) ?></small></span></div><?php endif; ?>
  <nav class="drawer-body" aria-label="پیوندهای سامانه">
    <div class="drawer-section-title">سامانه</div>
    <a class="drawer-link" href="/" <?= $navCurrent('/') ?>><span class="drawer-icon" aria-hidden="true">⌂</span><span>صفحهٔ اصلی</span></a>
    <a class="drawer-link" href="/events" <?= $navCurrent('/events') ?>><span class="drawer-icon" aria-hidden="true">▦</span><span>رویدادها</span></a>
    <a class="drawer-link" href="/#about"><span class="drawer-icon" aria-hidden="true">◉</span><span>دربارهٔ مؤسسه</span></a>
    <a class="drawer-link" href="/support" <?= $navCurrent('/support') ?>><span class="drawer-icon" aria-hidden="true">✉</span><span>پشتیبانی فراگیران</span></a>
    <a class="drawer-link" href="/terms" <?= $navCurrent('/terms') ?>><span class="drawer-icon" aria-hidden="true">§</span><span>شرایط استفاده و مالکیت</span></a>
    <a class="drawer-link" href="/privacy" <?= $navCurrent('/privacy') ?>><span class="drawer-icon" aria-hidden="true">¶</span><span>حریم خصوصی</span></a>
    <?php if ($user): ?>
      <div class="drawer-section-title">حساب من</div>
      <a class="drawer-link" href="/account" <?= $navCurrent('/account') ?>><span class="drawer-icon" aria-hidden="true">♙</span><span>پنل فراگیر</span></a>
      <a class="drawer-link" href="/notifications" <?= $navCurrent('/notifications') ?>><span class="drawer-icon" aria-hidden="true">🔔</span><span>اعلان‌ها<?= $unreadNotifications>0 ? ' (' . toPersianDigits((string)$unreadNotifications) . ')' : '' ?></span></a>
      <a class="drawer-link" href="/account/settings" <?= $navCurrent('/account/settings') ?>><span class="drawer-icon" aria-hidden="true">◐</span><span>تنظیمات نمایش</span></a>
      <a class="drawer-link" href="/account/security" <?= $navCurrent('/account/security') ?>><span class="drawer-icon" aria-hidden="true">⚿</span><span>امنیت حساب</span></a>
      <a class="drawer-link" href="/account/payments" <?= $navCurrent('/account/payments') ?>><span class="drawer-icon" aria-hidden="true">₺</span><span>پرداخت‌های آزمایشی</span></a>
    <?php endif; ?>
    <?php if ($isEventAdmin || $isSupportStaff): ?>
      <div class="drawer-section-title">مدیریت</div>
      <?php if ($isEventAdmin): ?>
        <a class="drawer-link" href="/admin" <?= $currentPath === '/admin' ? 'aria-current="page"' : '' ?>><span class="drawer-icon" aria-hidden="true">▤</span><span>نمای کلی مدیریت</span></a>
        <a class="drawer-link" href="/admin/events" <?= $navCurrent('/admin/events') ?>><span class="drawer-icon" aria-hidden="true">▣</span><span>مدیریت رویدادها</span></a>
        <a class="drawer-link" href="/admin/events/new"><span class="drawer-icon" aria-hidden="true">＋</span><span>ساخت رویداد</span></a>
        <a class="drawer-link" href="/admin/check-in" <?= $navCurrent('/admin/check-in') ?>><span class="drawer-icon" aria-hidden="true">▦</span><span>کنترل ورود و QR</span></a>
        <a class="drawer-link" href="/admin/reports" <?= $navCurrent('/admin/reports') ?>><span class="drawer-icon" aria-hidden="true">▤</span><span>گزارش‌های مدیریتی</span></a>
        <a class="drawer-link" href="/admin/import" <?= $navCurrent('/admin/import') ?>><span class="drawer-icon" aria-hidden="true">⇧</span><span>ورود اطلاعات (Excel)</span></a>
        <a class="drawer-link" href="/admin/exports/events.xlsx"><span class="drawer-icon" aria-hidden="true">⇩</span><span>خروجی Excel رویدادها</span></a>
      <?php endif; ?>
      <?php if ($isSupportStaff): ?><a class="drawer-link" href="/admin/support" <?= $navCurrent('/admin/support') ?>><span class="drawer-icon" aria-hidden="true">☏</span><span>مدیریت پشتیبانی</span></a><?php endif; ?>
      <?php if ($isSystemAdmin): ?>
        <a class="drawer-link" href="/admin/users" <?= $navCurrent('/admin/users') ?>><span class="drawer-icon" aria-hidden="true">♧</span><span>کاربران و دسترسی‌ها</span></a>
        <a class="drawer-link" href="/admin/roles" <?= $navCurrent('/admin/roles') ?>><span class="drawer-icon" aria-hidden="true">⊞</span><span>ماتریس نقش و مجوز</span></a>
        <a class="drawer-link" href="/admin/employees" <?= $navCurrent('/admin/employees') ?>><span class="drawer-icon" aria-hidden="true">▥</span><span>فهرست کارکنان</span></a>
        <a class="drawer-link" href="/admin/audit" <?= $navCurrent('/admin/audit') ?>><span class="drawer-icon" aria-hidden="true">◷</span><span>گزارش فعالیت سامانه</span></a>
        <a class="drawer-link" href="/admin/sessions" <?= $navCurrent('/admin/sessions') ?>><span class="drawer-icon" aria-hidden="true">⇱</span><span>نشست‌های فعال</span></a>
        <a class="drawer-link" href="/admin/system" <?= $navCurrent('/admin/system') ?>><span class="drawer-icon" aria-hidden="true">⚙</span><span>سلامت و نگهداری سامانه</span></a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!$user): ?><div class="drawer-section-title">ورود به سامانه</div><a class="drawer-link" href="/login"><span class="drawer-icon" aria-hidden="true">↪</span><span>ورود</span></a><a class="drawer-link" href="/register"><span class="drawer-icon" aria-hidden="true">＋</span><span>ساخت حساب</span></a><?php endif; ?>
  </nav>
  <div class="drawer-foot">
    <?php if ($user): ?><form method="post" action="/logout"><?= csrfField() ?><button class="btn btn-outline btn-block" type="submit">خروج از حساب</button></form>
    <?php else: ?><a class="btn btn-primary btn-block" href="/register">ساخت حساب فراگیر</a><?php endif; ?>
    <small>درگاه پرداخت این نسخه کاملاً آزمایشی است و هیچ وجه واقعی جابه‌جا نمی‌شود.</small>
  </div>
</aside>
<main id="main-content">
<?php if ($flash): ?><div class="container flash-wrap"><div class="flash flash-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div></div><?php endif; ?>
<div class="system-notice"><div class="container"><span>ثبت‌نام و مدیریت دوره فعال است</span><span>درگاه پرداخت فقط آزمایشی است — بدون جابه‌جایی وجه</span></div></div>
<?= $content ?? '' ?>
</main>
<footer class="site-footer"><div class="container footer-grid"><div><a class="brand footer-logo" href="/"><span class="brand-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M5 25h22M8 25V13l8-6 8 6v12M12 16h2v3h-2zm6 0h2v3h-2zM14 25v-5h4v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 12 16 4l12 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span><b>ای‌رویداد</b><small>مؤسسه آموزش، تحقیقات و مشاوره شهرداری‌های خراسان رضوی</small></span></a><p>یادگیری امروز، خدمت‌رسانی بهتر به شهروندان فردا.</p></div><div><b>دسترسی سریع</b><a href="/events">رویدادها</a><a href="/#about">درباره مؤسسه</a><a href="/support">پشتیبانی</a></div><div><b>اطلاعات</b><a href="/#faq">پرسش‌های متداول</a><a href="/privacy">حریم خصوصی</a><a href="/terms">شرایط استفاده و مالکیت</a></div></div><div class="container footer-bottom"><span>© تمامی حقوق مادی و معنوی ای‌رویداد، شامل نام، نشان، طراحی، متن‌ها و نرم‌افزار، متعلق به مؤسسه آموزش، تحقیقات و مشاوره شهرداری‌های خراسان رضوی است. بازنشر یا بهره‌برداری تجاری بدون اجازهٔ کتبی ممنوع است.</span><span>نسخهٔ محلی — درگاه واقعی و پیامک غیرفعال</span></div></footer>
<script src="<?= h(assetUrl('/assets/js/menu.js')) ?>" defer></script>
<script src="<?= h(assetUrl('/assets/js/theme.js')) ?>" defer></script>
<script src="<?= h(assetUrl('/assets/js/effects.js')) ?>" defer></script>
<script src="<?= h(assetUrl('/assets/js/pwa.js')) ?>" defer></script>
</body>
</html>
