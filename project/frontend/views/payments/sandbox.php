<?php $crumbs = [['label'=>'خانه','href'=>'/'], ['label'=>'پرداخت‌ها','href'=>'/account/payments'], ['label'=>'درگاه آزمایشی']]; ?>
<?php
/** @var array $payment */
/** @var array $scenarios */
$statusLabels = ['pending'=>'در انتظار پرداخت','paid'=>'پرداخت‌شده (آزمایشی)','failed'=>'ناموفق','cancelled'=>'لغوشده','refunded'=>'استردادشده'];
?>
<section class="section container narrow">
  <a href="/account/payments" class="back-link">← بازگشت به پرداخت‌ها</a>
  <div class="content-card sandbox-card">
    <div class="eyebrow">درگاه آزمایشی</div>
    <h1>پرداخت آزمایشی</h1>
    <div class="sandbox-warning" role="note">این یک درگاه شبیه‌سازی‌شده است. هیچ ارتباط شبکه‌ای با بانک یا درگاه واقعی برقرار نمی‌شود و هیچ وجهی جابه‌جا نمی‌گردد. تمام مسیرهای پرداخت واقعی در این نسخه قفل شده‌اند.</div>
    <div class="price-box">
      <div class="price-row"><span>مبلغ پایه</span><b><?= number_format((int)$payment['base_toman']) ?> تومان</b></div>
      <div class="price-row"><span>مالیات</span><b><?= number_format((int)$payment['tax_toman']) ?> تومان</b></div>
      <div class="price-row"><span>پرداخت‌کنندهٔ مالیات</span><b><?= h(\Eroydad\Payments\Money::payerLabel((string)$payment['tax_payer'])) ?></b></div>
      <div class="price-row price-total"><span>جمع پرداختی</span><b><?= number_format((int)$payment['total_toman']) ?> تومان</b></div>
      <div class="price-row"><span>کد پیگیری</span><b class="ltr-text"><?= h($payment['reference_code']) ?></b></div>
      <div class="price-row"><span>وضعیت</span><b><?= h($statusLabels[$payment['status']] ?? $payment['status']) ?></b></div>
    </div>
    <?php if ($payment['status'] === 'pending'): ?>
      <form method="post" action="/payments/<?= (int)$payment['id'] ?>/sandbox" class="form-stack">
        <?= csrfField() ?>
        <label>سناریوی شبیه‌سازی<select name="scenario"><?php foreach ($scenarios as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary" type="submit">اجرای تراکنش آزمایشی</button>
      </form>
    <?php else: ?>
      <div class="disabled-note">این سند در وضعیت «<?= h($statusLabels[$payment['status']] ?? $payment['status']) ?>» است و دوباره پرداخت نمی‌شود.</div>
      <?php if ($payment['status'] === 'paid'): ?><a class="btn btn-soft" href="/payments/<?= (int)$payment['id'] ?>/receipt">مشاهده رسید</a><?php endif; ?>
    <?php endif; ?>
  </div>
</section>
