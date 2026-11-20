<?php
/** @var array $stats */
/** @var array $daily */
/** @var array $support_stats */
$maxCount = 1;
foreach ($daily as $day) { if ((int)$day['count'] > $maxCount) { $maxCount = (int)$day['count']; } }
?>
<section class="page-banner"><div class="container"><div class="section-head"><div><div class="eyebrow">پنل مدیریت</div><h1>نمای کلی سامانه</h1><p>وضعیت رویدادها، ثبت‌نام‌ها، پشتیبانی و مالی آزمایشی</p></div><a class="btn btn-primary" href="/admin/events/new">＋ ساخت رویداد</a></div></div></section>
<section class="section container">  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>

  <div class="stats-grid stats-grid-5">
    <div class="stat-card"><small>رویدادهای منتشرشده</small><b><?= toPersianDigits((string)$stats['events']) ?></b></div>
    <div class="stat-card"><small>ثبت‌نام قطعی</small><b><?= toPersianDigits((string)$stats['attendees']) ?></b></div>
    <div class="stat-card"><small>در فهرست انتظار</small><b><?= toPersianDigits((string)$stats['waitlisted']) ?></b></div>
    <div class="stat-card"><small>در انتظار پرداخت آزمایشی</small><b><?= toPersianDigits((string)$stats['pending_payments']) ?></b></div>
    <div class="stat-card"><small>تیکت‌های باز پشتیبانی</small><b><?= toPersianDigits((string)$stats['support']) ?></b></div>
  </div>

  <div class="content-card">
    <h2>روند ثبت‌نام ۱۴ روز اخیر</h2>
    <div class="chart" role="img" aria-label="نمودار ستونی روند ثبت‌نام چهارده روز اخیر">
      <?php foreach ($daily as $day): ?>
        <div class="chart-col">
          <span class="chart-bar" style="height:<?= (int)round(((int)$day['count'] / $maxCount) * 100) ?>%" title="<?= h($day['label'] . ': ' . $day['count']) ?>"></span>
          <b><?= toPersianDigits((string)$day['count']) ?></b>
          <small><?= h($day['label']) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="two-cols">
    <div class="content-card">
      <h2>وضعیت پشتیبانی</h2>
      <div class="detail-facts">
        <div><small>کل تیکت‌ها</small><b><?= toPersianDigits((string)$support_stats['total']) ?></b></div>
        <div><small>باز</small><b><?= toPersianDigits((string)$support_stats['open']) ?></b></div>
        <div><small>میانگین زمان پاسخ</small><b><?= toPersianDigits((string)$support_stats['avg_response_minutes']) ?> دقیقه</b></div>
        <div><small>پاسخ در محدودهٔ SLA</small><b><?= toPersianDigits((string)$support_stats['within_sla']) ?></b></div>
        <div><small>نقض SLA</small><b><?= toPersianDigits((string)$support_stats['breached']) ?></b></div>
        <div><small>میانگین رضایت</small><b><?= toPersianDigits((string)$support_stats['csat_average']) ?> از ۵</b></div>
      </div>
    </div>
    <div class="content-card">
      <h2>خروجی‌های سریع</h2>
      <ul class="link-list">
        <li><a href="/admin/exports/events.xlsx">خروجی Excel رویدادها</a></li>
        <li><a href="/admin/exports/registrations.xlsx">خروجی Excel ثبت‌نام‌ها</a></li>
        <li><a href="/admin/exports/checkin.xlsx">خروجی Excel کنترل ورود</a></li>
        <li><a href="/admin/exports/finance.xlsx">خروجی Excel مالی آزمایشی</a></li>
        <li><a href="/admin/reports">گزارش‌های مدیریتی</a></li>
        <li><a href="/admin/system">سلامت و پشتیبان‌گیری سامانه</a></li>
      </ul>
    </div>
  </div>
</section>
