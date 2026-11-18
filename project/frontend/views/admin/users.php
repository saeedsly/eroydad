<?php
/** @var array $users */
/** @var array $roles */
/** @var array $role_users */
$roleMap = [];
foreach ($role_users as $row) { $roleMap[(int)$row['id']] = (string)($row['roles'] ?? ''); }
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">مدیریت حساب‌ها</div><h1>کاربران و دسترسی‌ها</h1><p>تغییر وضعیت حساب، نقش‌ها و ساخت کد بازیابی رمز</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <div class="notice-warning">این فهرست شامل اطلاعات تماس است. آن را فقط برای مدیریت ضروری باز کنید؛ کد ملی خام در سامانه ذخیره نمی‌شود.</div>
  <?php if($users): ?>
    <div class="table-wrap"><table><thead><tr><th>کاربر</th><th>نوع حساب</th><th>وضعیت کارمند</th><th>نقش‌ها</th><th>وضعیت حساب</th><th>عملیات</th></tr></thead><tbody>
      <?php foreach($users as $u): ?>
        <tr>
          <td><b><?= h($u['full_name']) ?></b><small class="table-sub ltr-text"><?= h($u['phone']) ?> · #<?= toPersianDigits((string)$u['id']) ?></small></td>
          <td><?= $u['account_type']==='municipal'?'شهرداری':'عمومی' ?><?php if($u['municipality']): ?><small class="table-sub"><?= h($u['municipality']) ?></small><?php endif; ?></td>
          <td><?= $u['employee_verified_at']?'تطبیق‌شده':'—' ?></td>
          <td><?= h($u['roles']??'—') ?></td>
          <td><span class="status status-<?= h($u['status']) ?>"><?= $u['status']==='active'?'فعال':'معلق' ?></span></td>
          <td class="row-actions">
            <?php if((int)$u['id']!==(int)$user['id']): ?>
              <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/<?= $u['status']==='active'?'suspend':'activate' ?>" <?= $u['status']==='active' ? 'data-confirm="تعلیق حساب، همهٔ نشست‌های فعال آن را هم باطل می‌کند. ادامه می‌دهید؟"' : '' ?>><?= csrfField() ?><button class="btn btn-small <?= $u['status']==='active'?'btn-danger':'btn-soft' ?>" type="submit"><?= $u['status']==='active'?'تعلیق':'فعال‌سازی' ?></button></form>
            <?php else: ?><small>حساب جاری</small><?php endif; ?>
            <form method="post" action="/admin/password-resets" class="inline-form"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="btn btn-small btn-outline" type="submit">کد بازیابی</button></form>
          </td>
        </tr>
        <tr class="sub-row"><td colspan="6">
          <form method="post" action="/admin/users/<?= (int)$u['id'] ?>/role" class="role-assign-form">
            <?= csrfField() ?>
            <label>نقش<select name="role"><?php foreach ($roles as $role): ?><option value="<?= h($role['code']) ?>"><?= h($role['label_fa']) ?></option><?php endforeach; ?></select></label>
            <label class="radio-row"><input type="checkbox" name="grant" value="1" checked> اعطای نقش</label>
            <button class="btn btn-small btn-soft" type="submit">به‌روزرسانی نقش</button>
            <small class="muted">نقش‌های فعلی: <?= h($roleMap[(int)$u['id']] ?? '—') ?></small>
          </form>
        </td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty-box">کاربری ثبت نشده است.</div><?php endif; ?>
</section>
