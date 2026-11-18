<?php
/** @var array $summary */
/** @var string $from */
/** @var string $to */
/** @var array $support_stats */
/** @var array $employees */
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">گزارش مدیریتی</div><h1>گزارش‌های مدیریتی</h1><p>بازهٔ زمانی دلخواه را انتخاب کنید</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <form class="filter-bar" method="get" action="/admin/reports">
    <div class="filter-grid">
      <label class="field"><span>از تاریخ</span><input type="date" name="from" value="<?= h($from) ?>"></label>
      <label class="field"><span>تا تاریخ</span><input type="date" name="to" value="<?= h($to) ?>"></label>
    </div>
    <div class="filter-actions"><button class="btn btn-primary btn-small" type="submit">نمایش گزارش</button></div>
  </form>

  <div class="stats-grid stats-grid-4">
    <div class="stat-card"><small>رویدادهای ساخته‌شده</small><b><?= toPersianDigits((string)$summary['events_created']) ?></b></div>
    <div class="stat-card"><small>ثبت‌نام‌ها</small><b><?= toPersianDigits((string)$summary['registrations']) ?></b><small class="table-sub">قطعی: <?= toPersianDigits((string)$summary['registrations_confirmed']) ?></small></div>
    <div class="stat-card"><small>تیکت‌های پشتیبانی</small><b><?= toPersianDigits((string)$summary['support_tickets']) ?></b></div>
    <div class="stat-card"><small>اسناد مالی آزمایشی</small><b><?= number_format((int)$summary['payment_total']) ?></b></div>
  </div>

  <div class="two-cols">
    <div class="content-card">
      <h2>کیفیت پشتیبانی</h2>
      <div class="detail-facts">
        <div><small>میانگین زمان پاسخ</small><b><?= toPersianDigits((string)$support_stats['avg_response_minutes']) ?> دقیقه</b></div>
        <div><small>پاسخ در SLA</small><b><?= toPersianDigits((string)$support_stats['within_sla']) ?></b></div>
        <div><small>نقض SLA</small><b><?= toPersianDigits((string)$support_stats['breached']) ?></b></div>
        <div><small>رضایت (CSAT)</small><b><?= toPersianDigits((string)$support_stats['csat_average']) ?> از ۵</b></div>
      </div>
    </div>
    <div class="content-card">
      <h2>تطبیق کارکنان</h2>
      <div class="detail-facts">
        <div><small>کل کارکنان مجاز</small><b><?= toPersianDigits((string)$employees['allowlist_total']) ?></b></div>
        <div><small>ردیف‌های پذیرفته‌شده</small><b><?= toPersianDigits((string)$employees['accepted_total']) ?></b></div>
        <div><small>ردیف‌های ردشده</small><b><?= toPersianDigits((string)$employees['rejected_total']) ?></b></div>
        <div><small>نرخ رد</small><b><?= toPersianDigits((string)$employees['reject_rate']) ?>٪</b></div>
      </div>
    </div>
  </div>

  <div class="content-card">
    <h2>خروجی‌های Excel</h2>
    <ul class="link-list">
      <li><a href="/admin/exports/events.xlsx">رویدادها</a></li>
      <li><a href="/admin/exports/registrations.xlsx">ثبت‌نام‌ها</a></li>
      <li><a href="/admin/exports/users.xlsx">کاربران و نقش‌ها</a></li>
      <li><a href="/admin/exports/support.xlsx">پشتیبانی و گزارش سامانه</a></li>
      <li><a href="/admin/exports/checkin.xlsx">بلیت و کنترل ورود</a></li>
      <li><a href="/admin/exports/employees.xlsx">فهرست کارکنان</a></li>
      <li><a href="/admin/exports/finance.xlsx">گزارش مالی آزمایشی</a></li>
    </ul>
  </div>
</section>
