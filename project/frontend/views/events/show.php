<?php
/** @var array $event */
/** @var array $sessions */
/** @var array $tags */
/** @var array $instructor */
$preview = $preview ?? false;
$sessions = $sessions ?? [];
$tags = $tags ?? [];
$instructor = $instructor ?? ['name' => '', 'bio' => ''];
$price = (int)($event['price_toman'] ?? 0);
$taxPercent = (float)($event['tax_percent'] ?? 0);
$taxPayer = (string)($event['tax_payer'] ?? 'participant');
$breakdown = \Eroydad\Payments\Money::breakdown($price, $taxPercent, $taxPayer);
$registered = (int)($event['registered_count'] ?? 0);
$capacity = (int)($event['capacity'] ?? 1);
$isFull = $registered >= $capacity;
$descriptionHtml = \Eroydad\Security\SafeText::render((string)$event['description']);
$seat = $seat ?? null;
?>
<section class="section container event-detail">
  <a class="back-link" href="/events">← بازگشت به رویدادها</a>
  <?php if ($preview): ?><div class="notice-warning">پیش‌نمایش مدیر — این صفحه هنوز برای عموم منتشر نشده است.</div><?php endif; ?>
  <div class="detail-grid">
    <div class="detail-main">
      <?php if (!empty($event['poster_path'])): ?>
        <figure class="detail-poster"><img src="/events/<?= h($event['slug']) ?>/poster" alt="<?= h($event['poster_alt'] ?: ('پوستر ' . $event['title'])) ?>"></figure>
      <?php else: ?>
        <div class="detail-art"><span class="detail-ornament" aria-hidden="true">✦</span><span class="detail-category"><?= h($event['category']) ?></span><span class="detail-shape" aria-hidden="true"></span></div>
      <?php endif; ?>
      <div class="detail-kicker"><?= h($event['category']) ?> <span>·</span> <?= $event['delivery_mode']==='online'?'آنلاین':($event['delivery_mode']==='hybrid'?'ترکیبی':'حضوری') ?></div>
      <h1><?= h($event['title']) ?></h1>
      <p class="detail-summary"><?= h($event['summary']) ?></p>
      <?php if ($tags !== []): ?><div class="tag-list"><?php foreach ($tags as $tag): ?><a class="tag-chip" href="/events?tag=<?= rawurlencode($tag) ?>"><?= h($tag) ?></a><?php endforeach; ?></div><?php endif; ?>
      <div class="detail-body"><?= $descriptionHtml ?></div>

      <?php if ($sessions !== []): ?>
        <h2>جلسه‌های دوره</h2>
        <div class="session-list">
          <?php foreach ($sessions as $session): ?>
            <div class="session-item"><b><?= h($session['title']) ?></b><span><?= h(jalaliDate($session['starts_at_utc'])) ?> تا <?= h(jalaliDate($session['ends_at_utc'])) ?></span><?php if ($session['venue'] !== ''): ?><small><?= h($session['venue']) ?></small><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($instructor['name'])): ?>
        <h2>مدرس دوره</h2>
        <div class="instructor-card"><b><?= h($instructor['name']) ?></b><?php if (!empty($instructor['bio'])): ?><p><?= h($instructor['bio']) ?></p><?php endif; ?></div>
      <?php endif; ?>

      <h2>اطلاعات رویداد</h2>
      <div class="detail-facts">
        <div><small>تاریخ و ساعت شروع</small><b><?= h(jalaliDate($event['starts_at_utc'])) ?></b></div>
        <div><small>تاریخ و ساعت پایان</small><b><?= h(jalaliDate($event['ends_at_utc'])) ?></b></div>
        <div><small>محل برگزاری</small><b><?= h($event['delivery_mode']==='online'?'آنلاین':$event['venue']) ?></b></div>
        <div><small>ظرفیت</small><b><?= toPersianDigits((string)$registered) ?> از <?= toPersianDigits((string)$capacity) ?> نفر</b></div>
      </div>
    </div>

    <aside class="signup-card">
      <?php if ($price > 0): ?>
        <div class="price-box">
          <div class="price-row"><span>مبلغ پایه</span><b><?= number_format($breakdown['base']) ?> تومان</b></div>
          <div class="price-row"><span>مالیات (<?= rtrim(rtrim(number_format($taxPercent, 2, '.', ''), '0'), '.') ?>٪)</span><b><?= number_format($breakdown['tax']) ?> تومان</b></div>
          <div class="price-row"><span>پرداخت‌کنندهٔ مالیات</span><b><?= h(\Eroydad\Payments\Money::payerLabel($taxPayer)) ?></b></div>
          <div class="price-row price-total"><span>جمع پرداختی فراگیر</span><b><?= number_format($breakdown['total']) ?> تومان</b></div>
          <p class="sandbox-note">پرداخت کاملاً آزمایشی است و هیچ وجه واقعی جابه‌جا نمی‌شود.</p>
        </div>
      <?php else: ?>
        <div class="free-label">رایگان</div>
      <?php endif; ?>
      <h2>ثبت‌نام در رویداد</h2>
      <?php
      // فرم ثبت‌نام سریع برای مهمان: از خودِ لینک رویداد، بدون نیاز به ورود قبلی.
      $quickForm = '<form method="post" action="/events/' . h($event['slug']) . '/quick-register" class="quick-signup-form">'
          . csrfField()
          . '<label>نام و نام خانوادگی<input name="full_name" required minlength="2" maxlength="160" autocomplete="name"></label>'
          . '<label>شماره موبایل<input name="phone" required inputmode="tel" autocomplete="tel" placeholder="09121234567" dir="ltr"></label>'
          . '<label>رمز عبور برای ورودهای بعدی<input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password"><small>دست‌کم ۱۲ نویسه؛ همین را بعداً برای ورود نگه دارید.</small></label>'
          . '<button class="btn btn-primary btn-block" type="submit">' . ($isFull ? 'ثبت‌نام سریع در فهرست انتظار' : ($price > 0 ? 'ثبت‌نام سریع و پرداخت آزمایشی' : 'ثبت‌نام سریع')) . ' <span>←</span></button>'
          . '</form>';
      ?>
      <?php if ($user): ?>
        <p>ثبت‌نام با حساب «<?= h($user['full_name']) ?>» انجام می‌شود<?php if ($price > 0): ?> و پس از آن پرداخت آزمایشی دارید<?php endif; ?>.</p>
      <?php else: ?>
        <p>فیلدها را پر کنید و ثبت‌نام کنید — نیازی به ورود قبلی نیست؛ حساب همین‌جا ساخته می‌شود.</p>
      <?php endif; ?>
      <?php if ($preview): ?>
        <div class="disabled-note">در حالت پیش‌نمایش، ثبت‌نام فعال نیست.</div>
      <?php elseif ($isFull): ?>
        <div class="notice-warning">ظرفیت تکمیل شده است؛ ثبت‌نام در فهرست انتظار انجام می‌شود.</div>
        <?php if ($user): ?>
          <form method="post" action="/events/<?= (int)$event['id'] ?>/register"><?= csrfField() ?><input type="hidden" name="idempotency_key" value="<?= h(\Eroydad\Registration\RegistrationService::uuidV4()) ?>"><button class="btn btn-primary btn-block" type="submit">ورود به فهرست انتظار <span>←</span></button></form>
        <?php else: ?>
          <?= $quickForm ?>
          <a class="signup-create" href="/login?next=<?= rawurlencode('/events/'.$event['slug']) ?>">قبلاً حساب دارید؟ ورود</a>
        <?php endif; ?>
      <?php elseif ($user && $seat): ?>
        <div class="locked-field">
          <?php if ($seat['status'] === 'pending_payment'): ?>
            <b>ثبت‌نام شما هنوز پرداخت نشده است.</b>
            <?php if ($seat['payment_status'] === 'failed' || $seat['payment_status'] === 'cancelled'): ?>
              <p>پرداخت پیشین کامل نشد؛ می‌توانید دوباره امتحان کنید.</p>
            <?php endif; ?>
            <?php if ((int)$seat['payment_id'] > 0): ?>
              <p><a class="btn btn-primary btn-block" href="/payments/<?= (int)$seat['payment_id'] ?>/sandbox">ادامهٔ پرداخت آزمایشی <span>←</span></a></p>
            <?php endif; ?>
          <?php else: ?>
            <b>ثبت‌نام شما برای این رویداد قطعی شده است.</b>
            <?php if ($seat['reference_code'] !== null && $seat['reference_code'] !== ''): ?>
              <div class="price-row"><span>کد پیگیری</span><b class="ltr-text"><?= h($seat['reference_code']) ?></b></div>
            <?php endif; ?>
            <?php if ((int)$seat['ticket_id'] > 0): ?>
              <p><a class="btn btn-soft btn-block" href="/tickets/<?= (int)$seat['ticket_id'] ?>/print">مشاهدهٔ بلیت و چاپ <span>←</span></a></p>
            <?php endif; ?>
            <p><a class="hero-link" href="/account">همهٔ ثبت‌نام‌های من <span>←</span></a></p>
          <?php endif; ?>
        </div>
      <?php elseif ($user): ?>
        <form method="post" action="/events/<?= (int)$event['id'] ?>/register"><?= csrfField() ?><input type="hidden" name="idempotency_key" value="<?= h(\Eroydad\Registration\RegistrationService::uuidV4()) ?>"><button class="btn btn-primary btn-block" type="submit"><?= $price > 0 ? 'ثبت‌نام و پرداخت آزمایشی' : 'ثبت‌نام' ?> <span>←</span></button></form>
      <?php else: ?>
        <?= $quickForm ?>
        <a class="signup-create" href="/login?next=<?= rawurlencode('/events/'.$event['slug']) ?>">قبلاً حساب دارید؟ ورود</a>
      <?php endif; ?>
    </aside>
  </div>
</section>
