<?php
/** @var array $tickets */
/** @var array $filters */
/** @var array $labels */
/** @var array $assignees */
$statusLabels = ['open'=>'باز','in_progress'=>'در حال بررسی','answered'=>'پاسخ داده‌شده','closed'=>'بسته‌شده'];
$priorityLabels = ['low'=>'کم','normal'=>'عادی','high'=>'زیاد','urgent'=>'فوری'];
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">پشتیبانی</div><h1>تیکت‌های پشتیبانی</h1><p>فیلتر، تخصیص، اولویت و برچسب‌گذاری تیکت‌ها</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <form class="filter-bar" method="get" action="/admin/support">
    <div class="filter-grid">
      <label class="field"><span>وضعیت</span><select name="status"><option value="">همه</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= h($value) ?>" <?= ($filters['status'] ?? '')===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>اولویت</span><select name="priority"><option value="">همه</option><?php foreach ($priorityLabels as $value => $label): ?><option value="<?= h($value) ?>" <?= ($filters['priority'] ?? '')===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>مسئول</span><select name="assigned"><option value="">همه</option><?php foreach ($assignees as $assignee): ?><option value="<?= (int)$assignee['id'] ?>" <?= (int)($filters['assigned'] ?? 0)===(int)$assignee['id']?'selected':'' ?>><?= h($assignee['full_name']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>جست‌وجو</span><input type="search" name="q" value="<?= h($filters['q'] ?? '') ?>" placeholder="موضوع، نام یا موبایل"></label>
    </div>
    <div class="filter-actions"><button class="btn btn-primary btn-small" type="submit">اعمال فیلتر</button><a class="btn btn-outline btn-small" href="/admin/support">پاک کردن</a></div>
  </form>

  <?php if ($tickets): ?>
    <div class="table-wrap"><table><thead><tr><th>تیکت</th><th>فراگیر</th><th>وضعیت</th><th>اولویت</th><th>مسئول</th><th>زمان</th></tr></thead><tbody>
      <?php foreach ($tickets as $ticket): ?>
        <tr>
          <td><a href="/admin/support/<?= (int)$ticket['id'] ?>"><?= h($ticket['subject']) ?></a><small class="table-sub">#<?= toPersianDigits((string)$ticket['id']) ?> · <?= h($ticket['event_title'] ?? '—') ?></small></td>
          <td><?= h($ticket['full_name']) ?><small class="table-sub ltr-text"><?= h($ticket['phone']) ?></small></td>
          <td><span class="status status-<?= h($ticket['status']) ?>"><?= h($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span></td>
          <td><span class="status status-<?= $ticket['priority']==='urgent'?'cancelled':'pending' ?>"><?= h($priorityLabels[$ticket['priority'] ?? 'normal'] ?? 'عادی') ?></span></td>
          <td><?= h($ticket['assigned_name'] ?? '—') ?></td>
          <td><?= h(jalaliDate((string)$ticket['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty-box">تیکتی با این فیلتر یافت نشد.</div><?php endif; ?>
</section>
