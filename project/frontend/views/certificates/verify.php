<?php
/** @var array $certificate */
?>
<section class="section container narrow">
  <div class="content-card certificate-card">
    <div class="eyebrow">استعلام گواهی</div>
    <h1>گواهی پایان دوره</h1>
    <p class="muted">این صفحه صحت گواهی را بر اساس کد یکتای آن بررسی می‌کند.</p>
    <div class="table-wrap"><table><tbody>
      <tr><th>کد گواهی</th><td class="ltr-text"><?= h($certificate['code']) ?></td></tr>
      <tr><th>نام فراگیر</th><td><?= h($certificate['full_name']) ?></td></tr>
      <tr><th>دوره</th><td><?= h($certificate['event_title']) ?></td></tr>
      <tr><th>دسته‌بندی</th><td><?= h($certificate['category']) ?></td></tr>
      <tr><th>زمان برگزاری</th><td><?= h(jalaliDate((string)$certificate['starts_at_utc'])) ?> تا <?= h(jalaliDate((string)$certificate['ends_at_utc'])) ?></td></tr>
      <tr><th>وضعیت ثبت‌نام</th><td><?= h($certificate['registration_status']) ?></td></tr>
      <tr><th>تاریخ صدور</th><td><?= h(jalaliDate((string)$certificate['issued_at'])) ?></td></tr>
    </tbody></table></div>
    <div class="certificate-seal">✦</div>
    <p class="muted">ای‌رویداد — مؤسسه آموزش، تحقیقات و مشاوره شهرداری‌های خراسان رضوی</p>
  </div>
</section>
