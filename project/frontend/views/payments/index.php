<?php
/** @var array $payments */
$statusLabels = ['pending'=>'در انتظار پرداخت','paid'=>'پرداخت‌شده (آزمایشی)','failed'=>'ناموفق','cancelled'=>'لغوشده','refunded'=>'استردادشده'];
?>
<section class="section container narrow">
  <div class="section-head"><div><div class="eyebrow">مالی آزمایشی</div><h1>پرداخت‌های من</h1><p>همهٔ اسناد این بخش آزمایشی‌اند و هیچ وجه واقعی جابه‌جا نمی‌شود.</p></div></div>
  <?php if ($payments): ?>
    <div class="table-wrap"><table><thead><tr><th>کد پیگیری</th><th>دوره</th><th>مبلغ</th><th>وضعیت</th><th></th></tr></thead><tbody>
      <?php foreach ($payments as $payment): ?>
        <tr>
          <td class="ltr-text"><?= h($payment['reference_code']) ?></td>
          <td><a href="/events/<?= h($payment['slug']) ?>"><?= h($payment['event_title']) ?></a></td>
          <td><?= number_format((int)$payment['total_toman']) ?> تومان<small class="table-sub">پایه: <?= number_format((int)$payment['base_toman']) ?> · مالیات: <?= number_format((int)$payment['tax_toman']) ?></small></td>
          <td><span class="status status-<?= h($payment['status']) ?>"><?= h($statusLabels[$payment['status']] ?? $payment['status']) ?></span></td>
          <td class="row-actions">
            <?php if ($payment['status']==='pending'): ?><a class="btn btn-small btn-primary" href="/payments/<?= (int)$payment['id'] ?>/sandbox">درگاه آزمایشی</a><?php endif; ?>
            <?php if ($payment['status']==='paid'): ?><a class="btn btn-small btn-soft" href="/payments/<?= (int)$payment['id'] ?>/receipt">رسید</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?>
    <div class="empty-box"><span aria-hidden="true">₺</span><h3>سند پرداختی ندارید</h3><p>برای دوره‌های دارای هزینه، پس از ثبت‌نام سند پرداخت آزمایشی ساخته می‌شود.</p></div>
  <?php endif; ?>
</section>
