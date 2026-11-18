<?php
/** @var array $preview */
/** @var string $target */
$rows = $preview['rows'] ?? [];
$errors = $preview['errors'] ?? [];
$columns = array_values(array_filter(array_keys($rows[0] ?? []), static fn ($key) => $key !== '_row'));
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">پیش‌نمایش</div><h1>پیش‌نمایش ورود اطلاعات</h1><p><?= toPersianDigits((string)($preview['total'] ?? 0)) ?> ردیف · <?= toPersianDigits((string)($preview['invalid'] ?? 0)) ?> ردیف نامعتبر</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>

  <?php if ($errors): ?>
    <div class="content-card">
      <h2>گزارش خطا</h2>
      <div class="table-wrap"><table><thead><tr><th>ردیف</th><th>ستون</th><th>پیام</th></tr></thead><tbody>
        <?php foreach ($errors as $error): ?><tr><td><?= toPersianDigits((string)$error['row']) ?></td><td><?= h($error['column']) ?></td><td><?= h($error['message']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  <?php endif; ?>

  <div class="content-card">
    <h2>پیش‌نمایش داده‌ها</h2>
    <div class="table-wrap"><table><thead><tr><th>#</th><?php foreach ($columns as $column): ?><th><?= h($column) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach (array_slice($rows, 0, 50) as $row): ?>
        <tr><td><?= toPersianDigits((string)($row['_row'] ?? '')) ?></td><?php foreach ($columns as $column): ?><td><?= h((string)($row[$column] ?? '')) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </tbody></table></div>

    <?php if (empty($errors) && $rows !== []): ?>
      <form method="post" action="/admin/import/commit">
        <?= csrfField() ?>
        <input type="hidden" name="target" value="<?= h($target) ?>">
        <input type="hidden" name="tmp" value="<?= h($preview['tmp'] ?? '') ?>">
        <input type="hidden" name="original" value="<?= h($preview['original'] ?? '') ?>">
        <?php if ($target === 'attendees'): ?><input type="hidden" name="event_id" value="<?= (int)($_POST['event_id'] ?? 0) ?>"><?php endif; ?>
        <button class="btn btn-primary" type="submit" data-confirm="داده‌های این فایل وارد شود؟">تأیید و ورود اطلاعات</button>
      </form>
    <?php else: ?>
      <div class="notice-error">به‌دلیل وجود ردیف نامعتبر، دکمهٔ ورود غیرفعال است. فایل را اصلاح و دوباره بارگذاری کنید.</div>
      <a class="btn btn-outline btn-small" href="/admin/import">بازگشت</a>
    <?php endif; ?>
  </div>
</section>
