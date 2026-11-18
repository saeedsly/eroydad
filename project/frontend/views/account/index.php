<?php
/** @var array $user */
/** @var array $registrations */
/** @var int $unread */
/** @var array $certificates */
$statusLabels = ['confirmed'=>'قطعی','cancelled'=>'لغوشده','waitlisted'=>'در فهرست انتظار','pending_payment'=>'در انتظار پرداخت آزمایشی'];
?>
<section class="page-banner"><div class="container"><div class="eyebrow">پنل فراگیر</div><h1>سلام، <?= h($user['full_name']) ?></h1><p>ثبت‌نام‌ها، وضعیت حساب و بلیت‌های شما</p><?php if (empty($user['phone_verified_at'])): ?><div class="inline-warning">شماره موبایل شما هنوز با OTP تایید نشده است؛ ارسال پیامک در این نسخه خاموش است.</div><?php endif; ?></div></section>
<section class="section container">
  <div class="section-head">
    <div><h2>رویدادهای من</h2><p>بلیت هر ثبت‌نام قطعی را می‌توانید چاپ یا به PDF ذخیره کنید.</p></div>
    <a class="btn btn-primary" href="/events">پیدا کردن رویداد</a>
  </div>
  <?php if ($registrations): ?>
    <div class="table-wrap"><table><thead><tr><th>رویداد</th><th>زمان</th><th>هزینه</th><th>وضعیت</th><th>اقدام</th></tr></thead><tbody>
      <?php foreach ($registrations as $r): ?>
        <tr>
          <td><a href="/events/<?= h($r['slug']) ?>"><?= h($r['title']) ?></a><small class="table-sub"><?= h(jalaliDate($r['starts_at_utc'])) ?></small></td>
          <td><?= h(jalaliDate($r['starts_at_utc'])) ?></td>
          <td><?= (int)$r['price_toman'] > 0 ? number_format((int)$r['price_toman']) . ' تومان' : 'رایگان' ?></td>
          <td><span class="status status-<?= h($r['status']) ?>"><?= h($statusLabels[$r['status']] ?? $r['status']) ?></span><?php if ($r['status']==='waitlisted' && $r['waitlist_position']): ?><small class="table-sub">نوبت <?= toPersianDigits((string)$r['waitlist_position']) ?></small><?php endif; ?></td>
          <td class="row-actions">
            <?php if ($r['ticket_id'] && $r['status']==='confirmed'): ?><a class="btn btn-small btn-soft" href="/tickets/<?= (int)$r['ticket_id'] ?>/print">بلیت / PDF</a><?php endif; ?>
            <?php if ($r['status']==='confirmed'): ?><a class="btn btn-small btn-outline" href="/feedback/<?= (int)$r['id'] ?>">بازخورد</a><?php endif; ?>
            <?php if (in_array($r['status'],['confirmed','waitlisted','pending_payment'],true)): ?>
              <form method="post" action="/account/registrations/<?= (int)$r['id'] ?>/cancel" data-confirm="از لغو این ثبت‌نام مطمئن هستید؟"><?= csrfField() ?><button class="btn btn-small btn-danger" type="submit">لغو ثبت‌نام</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?>
    <div class="empty-box"><span aria-hidden="true">✧</span><h3>هنوز ثبت‌نامی ندارید</h3><p>از رویدادهای فعال یکی را انتخاب کنید.</p><a class="btn btn-primary" href="/events">مشاهده رویدادها</a></div>
  <?php endif; ?>

  <div class="two-cols section-gap">
    <div class="content-card">
      <h2>اعلان‌های شما</h2>
      <p class="muted"><?= toPersianDigits((string)$unread) ?> اعلان خوانده‌نشده دارید.</p>
      <a class="btn btn-soft btn-small" href="/notifications">مرکز اعلان‌ها</a>
    </div>
    <div class="content-card">
      <h2>گواهی‌های صادرشده</h2>
      <?php if ($certificates): ?>
        <ul><?php foreach ($certificates as $certificate): ?><li><a href="/certificate/<?= h($certificate['code']) ?>"><?= h($certificate['event_title']) ?></a> — <?= h(jalaliDate($certificate['issued_at'])) ?></li><?php endforeach; ?></ul>
      <?php else: ?>
        <p class="muted">پس از صدور گواهی توسط مدیر دوره، اینجا نمایش داده می‌شود.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="account-shortcuts">
    <a href="/account/calendar.ics">خروجی تقویم شخصی (.ics) <span>←</span></a>
    <a href="/account/payments">پرداخت‌های آزمایشی <span>←</span></a>
    <a href="/account/organization">تکمیل اطلاعات سازمانی <span>←</span></a>
    <a href="/support">درخواست پشتیبانی <span>←</span></a>
  </div>
</section>
