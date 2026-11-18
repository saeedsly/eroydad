<?php
/** @var array $rows */
/** @var array $event_options */
/** @var array $filters */
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">گزارش حضور</div><h1>گزارش کنترل ورود</h1><p>فیلتر بر اساس دوره یا نام شرکت‌کننده و کد بلیت</p></div>
    <a class="btn btn-soft btn-small" href="/admin/exports/checkin.xlsx">خروجی Excel</a>
  </div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <form class="filter-bar" method="get" action="/admin/reports/checkin">
    <div class="filter-grid">
      <label class="field"><span>دوره</span><select name="event_id"><option value="0">همهٔ دوره‌ها</option><?php foreach ($event_options as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (int)$filters['event_id']===(int)$option['id']?'selected':'' ?>><?= h($option['title']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>جست‌وجو</span><input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="نام شرکت‌کننده یا کد بلیت"></label>
    </div>
    <div class="filter-actions"><button class="btn btn-primary btn-small" type="submit">اعمال فیلتر</button></div>
  </form>
  <?php if ($rows): ?>
    <div class="table-wrap"><table><thead><tr><th>دوره</th><th>شرکت‌کننده</th><th>کد بلیت</th><th>زمان ورود</th><th>اپراتور</th><th>منبع</th></tr></thead><tbody>
      <?php foreach ($rows as $row): ?><tr><td><?= h($row['event_title']) ?></td><td><?= h($row['full_name']) ?></td><td class="ltr-text"><?= h(substr((string)$row['public_code'],0,12)) ?></td><td><?= h(jalaliDate((string)$row['checked_at'])) ?></td><td><?= h($row['checked_by_name']) ?></td><td><?= h($row['source']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty-box">رکوردی با این فیلتر یافت نشد.</div><?php endif; ?>
</section>
