<?php
$employees = db()->query('SELECT a.id, a.municipality, a.created_at, a.import_batch_id AS batch_id FROM employee_allowlist a ORDER BY a.id DESC LIMIT 300')->fetchAll();
$batches = db()->query('SELECT b.*, u.full_name AS creator_name FROM employee_import_batches b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.id DESC LIMIT 20')->fetchAll();
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">کارکنان</div><h1>فهرست کارکنان</h1><p>کد ملی خام ذخیره نمی‌شود؛ فقط هش آن نگهداری می‌گردد.</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <div class="content-card">
    <h2>ورود فهرست کارکنان</h2>
    <p class="muted">فایل CSV باید دقیقاً دو ستون <span class="ltr-text">national_id</span> و <span class="ltr-text">municipality</span> داشته باشد. برای ورود فایل Excel از صفحهٔ <a href="/admin/import">ورود اطلاعات</a> استفاده کنید.</p>
    <form method="post" action="/admin/employees/import" enctype="multipart/form-data" class="form-stack">
      <?= csrfField() ?>
      <label>فایل CSV<input type="file" name="csv" accept=".csv,text/csv" required></label>
      <button class="btn btn-primary" type="submit">بارگذاری و پردازش</button>
    </form>
  </div>
  <div class="content-card">
    <h2>دسته‌های ورود اخیر</h2>
    <?php if ($batches): ?>
      <div class="table-wrap"><table><thead><tr><th>شناسه</th><th>اپراتور</th><th>پذیرفته‌شده</th><th>ردشده</th><th>زمان</th></tr></thead><tbody>
        <?php foreach ($batches as $batch): ?><tr><td>#<?= toPersianDigits((string)$batch['id']) ?></td><td><?= h($batch['creator_name'] ?? '—') ?></td><td><?= toPersianDigits((string)$batch['accepted_rows']) ?></td><td><?= toPersianDigits((string)$batch['rejected_rows']) ?></td><td><?= h(jalaliDate((string)$batch['imported_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted">هنوز فهرستی وارد نشده است.</p><?php endif; ?>
  </div>
  <div class="content-card">
    <h2>کارکنان ثبت‌شده</h2>
    <?php if ($employees): ?>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>شهرداری</th><th>دستهٔ ورود</th><th>زمان ثبت</th></tr></thead><tbody>
        <?php foreach ($employees as $employee): ?><tr><td><?= toPersianDigits((string)$employee['id']) ?></td><td><?= h($employee['municipality']) ?></td><td>#<?= toPersianDigits((string)$employee['batch_id']) ?></td><td><?= h(jalaliDate((string)$employee['created_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted">کارمندی ثبت نشده است.</p><?php endif; ?>
  </div>
</section>
