<?php
/** @var array $event */
/** @var array $certificates */
?>
<section class="section container">
  <div class="ticket-toolbar"><a href="/admin/events/<?= (int)$event['id'] ?>" class="btn btn-outline">بازگشت به رویداد</a><button class="btn btn-primary" type="button" onclick="window.print()">چاپ / ذخیره به PDF</button></div>
  <h1>گواهی‌های «<?= h($event['title']) ?>»</h1>
  <p class="muted"><?= toPersianDigits((string)count($certificates)) ?> گواهی برای چاپ گروهی آماده است.</p>
  <div class="certificate-grid">
    <?php foreach ($certificates as $certificate): ?>
      <article class="certificate-card print-certificate">
        <div class="certificate-head"><span class="certificate-seal">✦</span><div><b>گواهی پایان دوره</b><small>ای‌رویداد — مؤسسه آموزش، تحقیقات و مشاوره شهرداری‌های خراسان رضوی</small></div></div>
        <p class="certificate-name"><?= h($certificate['full_name']) ?></p>
        <p>در دورهٔ «<b><?= h($event['title']) ?></b>» شرکت کرد و الزامات آن را با موفقیت به پایان رساند.</p>
        <div class="certificate-foot"><span>کد استعلام: <span class="ltr-text"><?= h($certificate['code']) ?></span></span><span><?= h(jalaliDate((string)$certificate['issued_at'])) ?></span></div>
      </article>
    <?php endforeach; ?>
    <?php if (!$certificates): ?><div class="empty-box">گواهی‌ای برای این دوره صادر نشده است.</div><?php endif; ?>
  </div>
</section>
