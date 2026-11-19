<?php
$isEdit = isset($event) && is_array($event);
$tags = $tags ?? [];
$tehranValue = static function(string $value): DateTimeImmutable {
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'));
};
$startDateValue = $isEdit ? jalaliDate($event['starts_at_utc'], 'Y/m/d') : '';
$endDateValue = $isEdit ? jalaliDate($event['ends_at_utc'], 'Y/m/d') : '';
$startTimeValue = $isEdit ? $tehranValue($event['starts_at_utc'])->format('H:i') : '';
$endTimeValue = $isEdit ? $tehranValue($event['ends_at_utc'])->format('H:i') : '';
$action = $isEdit ? '/admin/events/' . (int)$event['id'] . '/edit' : '/admin/events/new';
?>
<section class="section container narrow">
  <a href="/admin/events" class="back-link">← بازگشت به رویدادها</a>
  <div class="content-card">
    <div class="eyebrow">مدیریت رویداد</div>
    <h1><?= $isEdit?'ویرایش رویداد':'ساخت رویداد' ?></h1>
    <p class="muted">دوره‌های رایگان و دوره‌های دارای هزینه (با درگاه کاملاً آزمایشی) پشتیبانی می‌شوند.</p>
    <form method="post" action="<?= h($action) ?>" class="form-stack" data-jalali-form>
      <?= csrfField() ?>
      <label>عنوان رویداد<input name="title" required minlength="5" maxlength="220" value="<?= h($event['title']??'') ?>"></label>
      <label>خلاصه<input name="summary" required minlength="10" maxlength="500" value="<?= h($event['summary']??'') ?>"></label>
      <label>شرح دوره<textarea name="description" required minlength="20" maxlength="12000" rows="7"><?= h($event['description']??'') ?></textarea><small>قالب‌بندی ساده پشتیبانی می‌شود: خط تیره برای فهرست، دو ستاره برای بولد و # برای عنوان.</small></label>
      <label>دسته‌بندی<input name="category" required maxlength="100" value="<?= h($event['category']??'') ?>" placeholder="مدیریت شهری"></label>
      <label>برچسب‌ها<input name="tags" maxlength="400" value="<?= h(implode('، ', $tags)) ?>" placeholder="مدیریت شهری، آموزش، کاربردی"><small>برچسب‌ها را با ویرگول جدا کنید (حداکثر ۱۲ برچسب).</small></label>
      <label>شیوه برگزاری<select name="delivery_mode"><option value="in_person" <?= ($event['delivery_mode']??'in_person')==='in_person'?'selected':'' ?>>حضوری</option><option value="online" <?= ($event['delivery_mode']??'in_person')==='online'?'selected':'' ?>>آنلاین</option><option value="hybrid" <?= ($event['delivery_mode']??'in_person')==='hybrid'?'selected':'' ?>>ترکیبی</option></select></label>
      <label>نشانی محل / لینک<input name="venue" maxlength="240" value="<?= h($event['venue']??'') ?>" placeholder="نشانی یا محل جلسه"></label>

      <div class="date-time-group">
        <div class="date-time-group-title">تاریخ و ساعت دوره</div>
        <div class="two-cols">
          <?php foreach ([['starts_at','تاریخ شروع','ساعت شروع',$startDateValue,$startTimeValue],['ends_at','تاریخ پایان','ساعت پایان',$endDateValue,$endTimeValue]] as [$field,$dateLabel,$timeLabel,$dateValue,$timeValue]): ?>
          <div class="jalali-field" data-jalali-picker>
            <label for="<?= h($field) ?>_date"><?= h($dateLabel) ?></label>
            <div class="jalali-date-row">
              <input id="<?= h($field) ?>_date" name="<?= h($field) ?>_date" type="text" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="۱۴۰۵/۰۸/۲۴" value="<?= h($dateValue) ?>" data-jalali-date aria-describedby="<?= h($field) ?>_error" required>
              <button class="calendar-trigger" type="button" data-calendar-toggle aria-label="باز کردن تقویم شمسی" aria-expanded="false">▦</button>
            </div>
            <div class="jalali-calendar" data-calendar hidden>
              <div class="jalali-calendar-head"><button type="button" data-month-prev aria-label="ماه قبل">›</button><strong data-month-title></strong><button type="button" data-month-next aria-label="ماه بعد">‹</button></div>
              <div class="jalali-calendar-weekdays" aria-hidden="true"><span>ش</span><span>ی</span><span>د</span><span>س</span><span>چ</span><span>پ</span><span>ج</span></div>
              <div class="jalali-calendar-days" data-calendar-days></div>
            </div>
            <small class="date-input-error" id="<?= h($field) ?>_error" data-date-error aria-live="polite"></small>
            <label class="time-label" for="<?= h($field) ?>_time"><?= h($timeLabel) ?></label>
            <input id="<?= h($field) ?>_time" name="<?= h($field) ?>_time" type="time" value="<?= h($timeValue) ?>" data-jalali-time required>
            <input type="hidden" name="<?= h($field) ?>" value="" data-gregorian-datetime>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="two-cols">
        <label>ظرفیت نفرات<input type="number" name="capacity" required min="1" max="100000" value="<?= h((string)($event['capacity']??30)) ?>"></label>
        <label>مبلغ پایه (تومان)<input type="number" name="price_toman" min="0" max="500000000" step="1000" value="<?= h((string)($event['price_toman']??0)) ?>"><small>صفر یعنی رایگان. پرداخت فقط آزمایشی است.</small></label>
        <label>نرخ مالیات (درصد)<input type="number" name="tax_percent" min="0" max="100" step="0.01" value="<?= h((string)($event['tax_percent']??0)) ?>"></label>
        <label>پرداخت‌کنندهٔ مالیات<select name="tax_payer"><option value="participant" <?= ($event['tax_payer']??'participant')==='participant'?'selected':'' ?>>فراگیر</option><option value="organizer" <?= ($event['tax_payer']??'participant')==='organizer'?'selected':'' ?>>برگزارکننده</option></select></label>
      </div>
      <label class="choice-field"><legend>دوره چندجلسه‌ای</legend><label class="radio-row"><input type="checkbox" name="is_multi_session" value="1" <?= !empty($event['is_multi_session'])?'checked':'' ?>> این دوره چند جلسه دارد (پس از ذخیره، جلسات را اضافه کنید).</label></label>
      <label>نام مدرس<input name="instructor_name" maxlength="160" value="<?= h($event['instructor_name']??'') ?>"></label>
      <label>سوابق مدرس<textarea name="instructor_bio" maxlength="2000" rows="3"><?= h($event['instructor_bio']??'') ?></textarea></label>

      <noscript><p class="notice-error">برای انتخاب و ثبت زمان شمسی، JavaScript مرورگر باید فعال باشد.</p></noscript>
      <button class="btn btn-primary btn-large" type="submit"><?= $isEdit?'ذخیره تغییرات':'ذخیره پیش‌نویس' ?></button>
    </form>
  </div>
</section>
<script src="/assets/js/jalali-date-picker.js" defer></script>
