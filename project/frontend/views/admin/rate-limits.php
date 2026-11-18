<?php
/** @var int $login */
/** @var int $failed */
/** @var int $registration */
/** @var array $top */
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">امنیت</div><h1>محدودسازی درخواست‌ها</h1><p>آمار تلاش‌های ورود و ساخت حساب در ۲۴ ساعت گذشته</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <div class="stats-grid stats-grid-4">
    <div class="stat-card"><small>تلاش ورود (۲۴ ساعت)</small><b><?= toPersianDigits((string)$login) ?></b></div>
    <div class="stat-card"><small>ورود ناموفق</small><b><?= toPersianDigits((string)$failed) ?></b></div>
    <div class="stat-card"><small>تلاش ساخت حساب</small><b><?= toPersianDigits((string)$registration) ?></b></div>
    <div class="stat-card"><small>شماره‌های پردرخواست</small><b><?= toPersianDigits((string)count($top)) ?></b></div>
  </div>
  <div class="content-card">
    <h2>شماره‌های پردرخواست (۷ روز اخیر)</h2>
    <?php if ($top): ?>
      <div class="table-wrap"><table><thead><tr><th>شناسهٔ هش‌شدهٔ شماره</th><th>کل تلاش</th><th>ناموفق</th></tr></thead><tbody>
        <?php foreach ($top as $row): ?><tr><td class="ltr-text"><?= h(substr((string)$row['phone_hmac'],0,16)) ?>…</td><td><?= toPersianDigits((string)$row['total']) ?></td><td><?= toPersianDigits((string)$row['failed']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="muted">شماره‌ها به‌صورت هش HMAC نگهداری می‌شوند و در این گزارش قابل بازیابی نیستند.</p>
    <?php else: ?><p class="muted">داده‌ای برای نمایش وجود ندارد.</p><?php endif; ?>
  </div>
</section>
