<?php
/** @var array $event */
/** @var array $summary */
/** @var array $payments */
/** @var array $ledger */
$statusLabels = ['pending'=>'در انتظار','paid'=>'پرداخت‌شده','failed'=>'ناموفق','cancelled'=>'لغوشده','refunded'=>'استردادشده'];
?>
<section class="section container">
  <a href="/admin/events/<?= (int)$event['id'] ?>" class="back-link">← بازگشت به رویداد</a>
  <div class="section-head"><div><div class="eyebrow">مالی آزمایشی</div><h1>گزارش مالی «<?= h($event['title']) ?>»</h1><p>همهٔ ارقام آزمایشی‌اند و اعتبار مالی ندارند.</p></div>
    <a class="btn btn-soft btn-small" href="/admin/exports/finance.xlsx">خروجی Excel</a>
  </div>
  <div class="stats-grid stats-grid-4">
    <div class="stat-card"><small>اسناد</small><b><?= toPersianDigits((string)$summary['docs']) ?></b></div>
    <div class="stat-card"><small>جمع پرداخت‌شده</small><b><?= number_format((int)$summary['paid_total']) ?></b></div>
    <div class="stat-card"><small>مالیات وصول‌شده</small><b><?= number_format((int)$summary['paid_tax']) ?></b></div>
    <div class="stat-card"><small>خالص پس از استرداد</small><b><?= number_format((int)$summary['net_total']) ?></b></div>
  </div>
  <div class="content-card">
    <h2>اسناد پرداخت</h2>
    <?php if ($payments): ?>
      <div class="table-wrap"><table><thead><tr><th>کد</th><th>کاربر</th><th>پایه</th><th>مالیات</th><th>جمع</th><th>وضعیت</th><th>زمان</th></tr></thead><tbody>
        <?php foreach ($payments as $payment): ?>
          <tr>
            <td class="ltr-text"><?= h($payment['reference_code']) ?></td>
            <td><?= h($payment['full_name']) ?></td>
            <td><?= number_format((int)$payment['base_toman']) ?></td>
            <td><?= number_format((int)$payment['tax_toman']) ?></td>
            <td><?= number_format((int)$payment['total_toman']) ?></td>
            <td><span class="status status-<?= h($payment['status']) ?>"><?= h($statusLabels[$payment['status']] ?? $payment['status']) ?></span></td>
            <td><?= h(jalaliDate((string)$payment['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted">سندی ثبت نشده است.</p><?php endif; ?>
  </div>
  <div class="content-card">
    <h2>دفتر ثبت مالی آزمایشی</h2>
    <?php if ($ledger): ?>
      <div class="table-wrap"><table><thead><tr><th>نوع</th><th>مبلغ</th><th>توضیح</th><th>زمان</th></tr></thead><tbody>
        <?php foreach ($ledger as $entry): ?><tr><td><?= h($entry['entry_type']) ?></td><td><?= number_format((int)$entry['amount_toman']) ?></td><td><?= h($entry['note']) ?></td><td><?= h(jalaliDate((string)$entry['created_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted">رویدادی در دفتر ثبت نشده است.</p><?php endif; ?>
  </div>
</section>
