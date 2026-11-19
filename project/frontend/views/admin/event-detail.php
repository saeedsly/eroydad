<?php
/** @var array $event */
/** @var array $attendees */
/** @var array $sessions */
/** @var array $tags */
/** @var array $attachments */
/** @var array $finance */
/** @var array $payments */
/** @var array $feedback */
/** @var array $certificate_pending */
/** @var string $preview_token */
/** @var array $live */
$statusLabels = ['confirmed'=>'قطعی','waitlisted'=>'در انتظار','cancelled'=>'لغوشده','pending_payment'=>'در انتظار پرداخت'];
$price = (int)$event['price_toman'];
$breakdown = \Eroydad\Payments\Money::breakdown($price, (float)$event['tax_percent'], (string)$event['tax_payer']);
?>
<section class="section container">
  <a href="/admin/events" class="back-link">← بازگشت به رویدادها</a>
  <div class="section-head">
    <div><div class="eyebrow">مدیریت رویداد</div><h1><?= h($event['title']) ?></h1><p><?= h(jalaliDate($event['starts_at_utc'])) ?> · <?= h($event['venue']) ?></p></div>
    <div class="row-actions">
      <span class="status status-<?= h($event['status']) ?>"><?= h($event['status']) ?></span>
      <a class="btn btn-small btn-outline" href="/admin/events/<?= (int)$event['id'] ?>/edit">ویرایش</a>
      <a class="btn btn-small btn-soft" href="/preview/<?= h($preview_token) ?>" target="_blank" rel="noopener">پیش‌نمایش</a>
      <?php if ($event['status'] === 'draft'): ?><form method="post" action="/admin/events/<?= (int)$event['id'] ?>/publish"><?= csrfField() ?><button class="btn btn-small btn-primary" type="submit">انتشار</button></form><?php endif; ?>
      <?php if (in_array($event['status'],['draft','published'],true)): ?><form method="post" action="/admin/events/<?= (int)$event['id'] ?>/cancel" data-confirm="لغو این رویداد و آزادسازی همهٔ ثبت‌نام‌ها؟"><?= csrfField() ?><button class="btn btn-small btn-danger" type="submit">لغو رویداد</button></form><?php endif; ?>
    </div>
  </div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>

  <div class="stats-grid stats-grid-4" id="liveCount" data-event="<?= (int)$event['id'] ?>" data-url="/admin/events/<?= (int)$event['id'] ?>/live-count.json">
    <div class="stat-card"><small>ثبت‌نام قطعی</small><b data-live="confirmed"><?= toPersianDigits((string)$live['confirmed']) ?></b></div>
    <div class="stat-card"><small>در فهرست انتظار</small><b data-live="waitlisted"><?= toPersianDigits((string)$live['waitlisted']) ?></b></div>
    <div class="stat-card"><small>ورود ثبت‌شده</small><b data-live="checked_in"><?= toPersianDigits((string)$live['checked_in']) ?></b></div>
    <div class="stat-card"><small>ظرفیت</small><b data-live="capacity"><?= toPersianDigits((string)$live['capacity']) ?></b></div>
  </div>

  <div class="two-cols">
    <div class="content-card">
      <h2>پوستر دوره</h2>
      <?php if (!empty($event['poster_path'])): ?>
        <img class="poster-preview" src="/events/<?= h($event['slug']) ?>/poster" alt="<?= h($event['poster_alt'] ?: ('پوستر ' . $event['title'])) ?>">
        <form method="post" action="/admin/events/<?= (int)$event['id'] ?>/poster/delete" data-confirm="پوستر حذف شود؟"><?= csrfField() ?><button class="btn btn-small btn-danger" type="submit">حذف پوستر</button></form>
      <?php endif; ?>
      <form method="post" action="/admin/events/<?= (int)$event['id'] ?>/poster" enctype="multipart/form-data" class="form-stack">
        <?= csrfField() ?>
        <label>انتخاب تصویر پوستر<input type="file" name="poster" accept="image/jpeg,image/png,image/webp" required></label>
        <label>متن جایگزین پوستر<input name="poster_alt" maxlength="200" value="<?= h($event['poster_alt'] ?? '') ?>" placeholder="توضیح کوتاه تصویر برای صفحه‌خوان‌ها"></label>
        <button class="btn btn-soft btn-small" type="submit">بارگذاری پوستر</button>
        <small>JPG، PNG یا WebP تا ۴ مگابایت — بیرون از مسیر وب ذخیره می‌شود.</small>
      </form>
    </div>

    <div class="content-card">
      <h2>مبلغ و مالیات</h2>
      <div class="price-box">
        <div class="price-row"><span>مبلغ پایه</span><b><?= number_format($breakdown['base']) ?> تومان</b></div>
        <div class="price-row"><span>مالیات (<?= rtrim(rtrim(number_format((float)$event['tax_percent'], 2, '.', ''), '0'), '.') ?>٪)</span><b><?= number_format($breakdown['tax']) ?> تومان</b></div>
        <div class="price-row"><span>پرداخت‌کنندهٔ مالیات</span><b><?= h(\Eroydad\Payments\Money::payerLabel((string)$event['tax_payer'])) ?></b></div>
        <div class="price-row price-total"><span>جمع پرداختی فراگیر</span><b><?= number_format($breakdown['total']) ?> تومان</b></div>
      </div>
      <div class="detail-facts">
        <div><small>اسناد پرداخت آزمایشی</small><b><?= toPersianDigits((string)$finance['docs']) ?></b></div>
        <div><small>جمع پرداخت‌شده</small><b><?= number_format((int)$finance['paid_total']) ?></b></div>
        <div><small>مالیات وصول‌شده</small><b><?= number_format((int)$finance['paid_tax']) ?></b></div>
        <div><small>استردادشده</small><b><?= number_format((int)$finance['refunded_total']) ?></b></div>
      </div>
      <a class="btn btn-soft btn-small" href="/admin/events/<?= (int)$event['id'] ?>/finance">گزارش مالی کامل</a>
    </div>
  </div>

  <div class="two-cols">
    <div class="content-card">
      <h2>جلسه‌های دوره</h2>
      <?php if ($sessions): ?>
        <ul class="link-list"><?php foreach ($sessions as $session): ?><li><b><?= h($session['title']) ?></b> — <?= h(jalaliDate($session['starts_at_utc'])) ?><form method="post" action="/admin/events/<?= (int)$event['id'] ?>/sessions/<?= (int)$session['id'] ?>/delete" data-confirm="این جلسه حذف شود؟"><?= csrfField() ?><button class="link-button" type="submit">حذف</button></form></li><?php endforeach; ?></ul>
      <?php else: ?><p class="muted">جلسه‌ای ثبت نشده است.</p><?php endif; ?>
      <form method="post" action="/admin/events/<?= (int)$event['id'] ?>/sessions" class="form-stack" data-jalali-form>
        <?= csrfField() ?>
        <label>عنوان جلسه<input name="title" required maxlength="190"></label>
        <div class="two-cols">
          <div class="jalali-field" data-jalali-picker>
            <label for="session_start_date">تاریخ شروع</label>
            <div class="jalali-date-row"><input id="session_start_date" name="starts_at_date" type="text" inputmode="numeric" maxlength="10" data-jalali-date required><button class="calendar-trigger" type="button" data-calendar-toggle aria-label="انتخاب تاریخ">▦</button></div>
            <div class="jalali-calendar" data-calendar hidden><div class="jalali-calendar-head"><button type="button" data-month-prev aria-label="ماه قبل">›</button><strong data-month-title></strong><button type="button" data-month-next aria-label="ماه بعد">‹</button></div><div class="jalali-calendar-weekdays" aria-hidden="true"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="jalali-calendar-days" data-calendar-days></div></div>
            <label class="time-label" for="session_start_time">ساعت شروع</label><input id="session_start_time" name="starts_at_time" type="time" data-jalali-time required>
            <small class="date-input-error" id="session_start_error" data-date-error aria-live="polite"></small>
            <input type="hidden" name="starts_at" data-gregorian-datetime>
          </div>
          <div class="jalali-field" data-jalali-picker>
            <label for="session_end_date">تاریخ پایان</label>
            <div class="jalali-date-row"><input id="session_end_date" name="ends_at_date" type="text" inputmode="numeric" maxlength="10" data-jalali-date required><button class="calendar-trigger" type="button" data-calendar-toggle aria-label="انتخاب تاریخ">▦</button></div>
            <div class="jalali-calendar" data-calendar hidden><div class="jalali-calendar-head"><button type="button" data-month-prev aria-label="ماه قبل">›</button><strong data-month-title></strong><button type="button" data-month-next aria-label="ماه بعد">‹</button></div><div class="jalali-calendar-weekdays" aria-hidden="true"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div><div class="jalali-calendar-days" data-calendar-days></div></div>
            <label class="time-label" for="session_end_time">ساعت پایان</label><input id="session_end_time" name="ends_at_time" type="time" data-jalali-time required>
            <small class="date-input-error" id="session_end_error" data-date-error aria-live="polite"></small>
            <input type="hidden" name="ends_at" data-gregorian-datetime>
          </div>
        </div>
        <label>محل جلسه<input name="venue" maxlength="240"></label>
        <button class="btn btn-soft btn-small" type="submit">افزودن جلسه</button>
      </form>
    </div>

    <div class="content-card">
      <h2>پیوست‌ها</h2>
      <?php if ($attachments): ?>
        <ul class="link-list"><?php foreach ($attachments as $attachment): ?><li><b><?= h($attachment['title']) ?></b> <small>(<?= number_format((int)$attachment['size_bytes'] / 1024, 1) ?> کیلوبایت)</small><form method="post" action="/admin/events/<?= (int)$event['id'] ?>/attachments/<?= (int)$attachment['id'] ?>/delete" data-confirm="این پیوست و فایل آن حذف شود؟"><?= csrfField() ?><button class="link-button" type="submit">حذف</button></form></li><?php endforeach; ?></ul>
      <?php else: ?><p class="muted">پیوستی ثبت نشده است.</p><?php endif; ?>
      <form method="post" action="/admin/events/<?= (int)$event['id'] ?>/attachments" enctype="multipart/form-data" class="form-stack">
        <?= csrfField() ?>
        <label>عنوان پیوست<input name="title" maxlength="190"></label>
        <label>فایل<input type="file" name="attachment" required></label>
        <button class="btn btn-soft btn-small" type="submit">افزودن پیوست</button>
      </form>
    </div>
  </div>

  <div class="content-card">
    <h2>شرکت‌کنندگان</h2>
    <div class="row-actions">
      <a class="btn btn-small btn-outline" href="/admin/events/<?= (int)$event['id'] ?>/attendees.csv">خروجی CSV</a>
      <a class="btn btn-small btn-outline" href="/admin/import?event_id=<?= (int)$event['id'] ?>">ورود اطلاعات از Excel</a>
    </div>
    <?php if ($attendees): ?>
      <div class="table-wrap"><table><thead><tr><th>نام</th><th>موبایل</th><th>نوع</th><th>وضعیت</th><th>ثبت‌نام</th><th>ورود</th><th>اقدام</th></tr></thead><tbody>
        <?php foreach ($attendees as $attendee): ?>
          <tr>
            <td><?= h($attendee['full_name']) ?></td>
            <td class="ltr-text"><?= h($attendee['phone']) ?></td>
            <td><?= $attendee['account_type']==='municipal'?'کارمند':'عمومی' ?></td>
            <td><span class="status status-<?= h($attendee['status']) ?>"><?= h($statusLabels[$attendee['status']] ?? $attendee['status']) ?></span><?php if (!empty($attendee['waitlist_position'])): ?><small class="table-sub">نوبت <?= toPersianDigits((string)$attendee['waitlist_position']) ?></small><?php endif; ?></td>
            <td><?= h(jalaliDate((string)$attendee['registered_at'])) ?></td>
            <td><?= $attendee['checked_at'] ? h(jalaliDate((string)$attendee['checked_at'])) : '—' ?></td>
            <td class="row-actions">
              <?php if (!empty($attendee['ticket_id'])): ?>
                <form method="post" action="/admin/tickets/<?= (int)$attendee['ticket_id'] ?>/reissue" data-confirm="بلیت فعلی باطل و بلیت تازه صادر می‌شود. ادامه می‌دهید؟"><?= csrfField() ?><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>"><button class="btn btn-small btn-outline" type="submit">بازصدور بلیت</button></form>
              <?php endif; ?>
              <?php if ($attendee['status'] === 'confirmed'): ?>
                <form method="post" action="/admin/events/<?= (int)$event['id'] ?>/attendees/<?= (int)$attendee['registration_id'] ?>/cancel" data-confirm="ثبت‌نام این شرکت‌کننده لغو و ظرفیت آزاد شود؟"><?= csrfField() ?><button class="btn btn-small btn-danger" type="submit">لغو</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted">هنوز شرکت‌کننده‌ای ثبت نشده است.</p><?php endif; ?>
  </div>

  <div class="two-cols">
    <div class="content-card">
      <h2>گواهی پایان دوره</h2>
      <p class="muted"><?= toPersianDigits((string)count($certificate_pending)) ?> شرکت‌کننده بدون گواهی</p>
      <form method="post" action="/admin/events/<?= (int)$event['id'] ?>/certificates"><?= csrfField() ?><button class="btn btn-soft btn-small" type="submit">صدور گروهی گواهی</button></form>
      <a class="btn btn-outline btn-small" href="/admin/events/<?= (int)$event['id'] ?>/certificates/print">چاپ گروهی گواهی‌ها</a>
    </div>
    <div class="content-card">
      <h2>بازخورد دوره</h2>
      <div class="detail-facts">
        <div><small>تعداد بازخورد</small><b><?= toPersianDigits((string)$feedback['total']) ?></b></div>
        <div><small>میانگین امتیاز</small><b><?= toPersianDigits((string)$feedback['average']) ?> از ۵</b></div>
      </div>
    </div>
  </div>
</section>
<script src="/assets/js/jalali-date-picker.js" defer></script>
<script src="/assets/js/live-count.js" defer></script>
