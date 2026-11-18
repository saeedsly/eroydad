<?php
/** @var array $pending */
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">امنیت</div><h1>کدهای بازیابی رمز</h1><p>کد را فقط یک‌بار و روی کانال امن به کاربر برسانید؛ سامانه فقط هش آن را نگهداری می‌کند.</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <div class="content-card">
    <form method="post" action="/admin/password-resets" class="form-stack">
      <?= csrfField() ?>
      <label>شناسهٔ کاربر<input type="number" name="user_id" required min="1"></label>
      <label>اعتبار کد (دقیقه)<input type="number" name="minutes" value="60" min="10" max="1440"></label>
      <button class="btn btn-primary" type="submit">ساخت کد بازیابی</button>
    </form>
  </div>
  <?php if ($pending): ?>
    <div class="table-wrap"><table><thead><tr><th>کاربر</th><th>موبایل</th><th>انقضا</th><th>وضعیت</th></tr></thead><tbody>
      <?php foreach ($pending as $row): ?><tr><td><?= h($row['full_name']) ?></td><td class="ltr-text"><?= h($row['phone']) ?></td><td><?= h(jalaliDate((string)$row['expires_at'])) ?></td><td><span class="status status-<?= $row['used_at'] ? 'closed' : 'pending' ?>"><?= $row['used_at'] ? 'استفاده‌شده' : 'فعال' ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
