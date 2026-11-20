<?php $crumbs = [['label'=>'خانه','href'=>'/'], ['label'=>'پرداخت‌ها','href'=>'/account/payments'], ['label'=>'رسید آزمایشی']]; ?>
<?php
/** @var array $receipt */
$breakdown = $receipt['breakdown'];
?>
<section class="section container narrow">
  <a href="/account/payments" class="back-link">← بازگشت به پرداخت‌ها</a>
  <div class="content-card receipt-card">
    <div class="eyebrow">رسید آزمایشی</div>
    <h1>رسید پرداخت</h1>
    <p class="muted">این رسید فقط برای سناریوی آزمایشی است و اعتبار مالی ندارد.</p>
    <div class="table-wrap"><table><tbody>
      <tr><th>کد پیگیری</th><td class="ltr-text"><?= h($receipt['reference_code']) ?></td></tr>
      <tr><th>دوره</th><td><?= h($receipt['event_title']) ?></td></tr>
      <tr><th>زمان دوره</th><td><?= h(jalaliDate((string)$receipt['starts_at_utc'])) ?></td></tr>
      <tr><th>پرداخت‌کننده</th><td><?= h($receipt['full_name']) ?> — <span class="ltr-text"><?= h($receipt['phone']) ?></span></td></tr>
      <tr><th>مبلغ پایه</th><td><?= number_format((int)$breakdown['base']) ?> تومان</td></tr>
      <tr><th>مالیات</th><td><?= number_format((int)$breakdown['tax']) ?> تومان (پرداخت‌کننده: <?= h($breakdown['payer_label']) ?>)</td></tr>
      <tr><th>جمع نهایی</th><td><b><?= number_format((int)$breakdown['total']) ?> تومان</b></td></tr>
      <tr><th>وضعیت</th><td><?= h($receipt['status']) ?></td></tr>
      <tr><th>زمان ثبت</th><td><?= h(jalaliDate((string)$receipt['created_at'])) ?></td></tr>
    </tbody></table></div>
    <?php if (!empty($receipt['ledger'])): ?>
      <h2 class="card-title">دفتر ثبت آزمایشی</h2>
      <div class="table-wrap"><table><thead><tr><th>نوع</th><th>مبلغ</th><th>توضیح</th><th>زمان</th></tr></thead><tbody>
        <?php foreach ($receipt['ledger'] as $entry): ?><tr><td><?= h($entry['entry_type']) ?></td><td><?= number_format((int)$entry['amount_toman']) ?></td><td><?= h($entry['note']) ?></td><td><?= h(jalaliDate((string)$entry['created_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <a class="btn btn-primary" href="/account">بازگشت به پنل</a>
  </div>
</section>
