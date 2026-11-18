<?php
/** @var array $health */
/** @var array $backups */
/** @var array $migration_runs */
/** @var array $applied */
/** @var array $policies */
/** @var array $retention_history */
$statusLabels = ['ok'=>'سالم','warn'=>'هشدار','fail'=>'خطا'];
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">نگهداری</div><h1>سلامت و نگهداری سامانه</h1><p>بررسی دیتابیس، افزونه‌ها، پشتیبان‌گیری، مهاجرت‌ها و سیاست نگهداری داده</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>

  <div class="content-card">
    <h2>بررسی سلامت</h2>
    <div class="table-wrap"><table><thead><tr><th>مورد</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>
      <?php foreach ($health as $check): ?>
        <tr><td><?= h($check['title']) ?></td><td><span class="status status-<?= $check['status']==='ok'?'active':($check['status']==='warn'?'pending':'cancelled') ?>"><?= h($statusLabels[$check['status']] ?? $check['status']) ?></span></td><td><?= h($check['detail']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>

  <div class="two-cols">
    <div class="content-card">
      <h2>پشتیبان‌گیری</h2>
      <form method="post" action="/admin/system/backup"><?= csrfField() ?><button class="btn btn-primary btn-small" type="submit">ساخت پشتیبان جدید</button></form>
      <?php if ($backups): ?>
        <div class="table-wrap"><table><thead><tr><th>فایل</th><th>حجم</th><th>سطر</th><th>آزمون بازیابی</th><th></th></tr></thead><tbody>
          <?php foreach ($backups as $backup): ?>
            <tr>
              <td class="ltr-text"><?= h($backup['file_name']) ?></td>
              <td><?= number_format((int)$backup['size_bytes'] / 1024) ?> KB</td>
              <td><?= toPersianDigits((string)$backup['rows_count']) ?></td>
              <td><span class="status status-<?= $backup['verified_at'] ? 'active' : 'pending' ?>"><?= $backup['verified_at'] ? 'آزموده‌شده' : 'آزمون‌نشده' ?></span></td>
              <td><form method="post" action="/admin/system/backup/<?= (int)$backup['id'] ?>/verify"><?= csrfField() ?><button class="btn btn-small btn-soft" type="submit">اجرای آزمون بازیابی</button></form></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?><p class="muted">هنوز پشتیبانی ساخته نشده است.</p><?php endif; ?>
    </div>

    <div class="content-card">
      <h2>تاریخچهٔ مهاجرت‌ها</h2>
      <div class="table-wrap"><table><thead><tr><th>نسخه</th><th>وضعیت</th><th>زمان</th></tr></thead><tbody>
        <?php foreach ($applied as $row): ?><tr><td class="ltr-text"><?= h($row['version']) ?></td><td><span class="status status-active">اجرا شده</span></td><td><?= h(jalaliDate((string)$row['applied_at'])) ?></td></tr><?php endforeach; ?>
        <?php if ($migration_runs): ?>
          <?php foreach ($migration_runs as $run): ?>
            <?php if (!in_array($run['version'], array_column($applied,'version'), true)): ?>
              <tr><td class="ltr-text"><?= h($run['version']) ?></td><td><span class="status status-<?= $run['status']==='success'?'active':'cancelled' ?>"><?= h($run['status']) ?></span></td><td><?= h(jalaliDate((string)$run['started_at'])) ?></td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody></table></div>
      <p class="muted">برای اجرای مهاجرت‌های جدید از خط فرمان: <code class="ltr-text">php deployment/local-xampp/migrate.php</code></p>
    </div>
  </div>

  <div class="content-card">
    <h2>سیاست نگهداری و پاک‌سازی داده</h2>
    <div class="table-wrap"><table><thead><tr><th>سیاست</th><th>مدت نگهداری</th><th>توضیح</th></tr></thead><tbody>
      <?php foreach ($policies as $policy): ?><tr><td><?= h($policy['title']) ?></td><td><?= toPersianDigits((string)$policy['days']) ?> روز</td><td><?= h($policy['description']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <form method="post" action="/admin/system/retention" class="form-stack" data-confirm="رکوردهای قدیمی حذف می‌شوند. ادامه می‌دهید؟">
      <?= csrfField() ?>
      <label>جایگزینی مدت نگهداری (روز، اختیاری)<input type="number" name="days" min="1" max="3650" placeholder="مثال: 180"></label>
      <button class="btn btn-danger btn-small" type="submit">اجرای پاک‌سازی</button>
    </form>
    <?php if ($retention_history): ?>
      <div class="table-wrap"><table><thead><tr><th>سیاست</th><th>حذف‌شده</th><th>زمان</th></tr></thead><tbody>
        <?php foreach ($retention_history as $row): ?><tr><td><?= h($row['policy']) ?></td><td><?= toPersianDigits((string)$row['deleted_rows']) ?></td><td><?= h(jalaliDate((string)$row['created_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</section>
