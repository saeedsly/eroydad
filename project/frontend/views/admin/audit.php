<?php
/** @var array $logs */
/** @var array $filters */
/** @var array $entities */
/** @var array $actions */
/** @var array $summary */
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">ممیزی</div><h1>گزارش فعالیت سامانه</h1><p><?= toPersianDigits((string)$summary['total']) ?> رخداد در ۷ روز اخیر از <?= toPersianDigits((string)$summary['actors']) ?> کاربر</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <form class="filter-bar" method="get" action="/admin/audit">
    <div class="filter-grid">
      <label class="field"><span>عملیات</span><select name="action"><option value="">همه</option><?php foreach ($actions as $action): ?><option value="<?= h($action) ?>" <?= ($filters['action'] ?? '')===$action?'selected':'' ?>><?= h($action) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>نوع موجودیت</span><select name="entity"><option value="">همه</option><?php foreach ($entities as $entity): ?><option value="<?= h($entity) ?>" <?= ($filters['entity'] ?? '')===$entity?'selected':'' ?>><?= h($entity) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>از تاریخ</span><input type="date" name="from" value="<?= h($filters['from'] ?? '') ?>"></label>
      <label class="field"><span>تا تاریخ</span><input type="date" name="to" value="<?= h($filters['to'] ?? '') ?>"></label>
      <label class="field"><span>جست‌وجو</span><input type="search" name="q" value="<?= h($filters['q'] ?? '') ?>" placeholder="جزئیات یا شناسه"></label>
    </div>
    <div class="filter-actions"><button class="btn btn-primary btn-small" type="submit">اعمال فیلتر</button><a class="btn btn-outline btn-small" href="/admin/audit">پاک کردن</a></div>
  </form>
  <?php if ($logs): ?>
    <div class="table-wrap"><table><thead><tr><th>زمان</th><th>عامل</th><th>عملیات</th><th>موجودیت</th><th>شناسه</th><th>جزئیات</th></tr></thead><tbody>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td><?= h(jalaliDate((string)$log['created_at'])) ?></td>
          <td><?= h($log['actor_name'] ?? 'سیستم') ?></td>
          <td class="ltr-text"><?= h($log['action']) ?></td>
          <td><?= h($log['entity_type']) ?></td>
          <td class="ltr-text"><?= h((string)($log['entity_id'] ?? '')) ?></td>
          <td class="ltr-text"><?= h(mb_substr((string)($log['safe_details'] ?? ''),0,90,'UTF-8')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty-box">رخدادی با این فیلتر یافت نشد.</div><?php endif; ?>
</section>
