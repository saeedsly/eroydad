<?php
/** @var array $matrix */
$roles = $matrix['roles'] ?? [];
$permissions = $matrix['permissions'] ?? [];
$granted = $matrix['granted'] ?? [];
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">دسترسی‌ها</div><h1>ماتریس نقش و مجوز</h1><p>برای هر نقش، مجوزهای فعال را تعیین کنید. نقش «مدیر سامانه» همیشه همهٔ مجوزها را دارد.</p></div>
    <form method="post" action="/admin/roles/reset" data-confirm="همهٔ مجوزها به حالت پیش‌فرض برگردد؟"><?= csrfField() ?><button class="btn btn-outline btn-small" type="submit">بازگردانی پیش‌فرض</button></form>
  </div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <div class="table-wrap"><table><thead><tr><th>مجوز</th><?php foreach ($roles as $role): ?><th><?= h($role['label_fa']) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($permissions as $permission): ?>
      <tr>
        <td><b><?= h($permission['label_fa']) ?></b><small class="table-sub ltr-text"><?= h($permission['code']) ?></small></td>
        <?php foreach ($roles as $role): ?>
          <?php $isOn = !empty($granted[$role['code'] . '|' . $permission['code']]); ?>
          <td>
            <form method="post" action="/admin/roles" class="inline-form">
              <?= csrfField() ?>
              <input type="hidden" name="role" value="<?= h($role['code']) ?>">
              <input type="hidden" name="permission" value="<?= h($permission['code']) ?>">
              <?php if (!$isOn): ?><input type="hidden" name="enabled" value="1"><?php endif; ?>
              <button class="btn btn-small <?= $isOn ? 'btn-soft' : 'btn-outline' ?>" type="submit" aria-label="<?= h($permission['label_fa']) ?> برای <?= h($role['label_fa']) ?>"><?= $isOn ? 'فعال' : 'غیرفعال' ?></button>
            </form>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>
