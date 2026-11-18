<?php
/** @var array $sessions */
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">امنیت</div><h1>نشست‌های فعال</h1><p>هر نشست یعنی یک دستگاه یا مرورگر که کاربر با آن وارد شده است.</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>
  <?php if ($sessions): ?>
    <div class="table-wrap"><table><thead><tr><th>کاربر</th><th>IP</th><th>مرورگر</th><th>ایجاد</th><th>آخرین فعالیت</th><th>وضعیت</th><th></th></tr></thead><tbody>
      <?php foreach ($sessions as $session): ?>
        <tr>
          <td><?= h($session['full_name']) ?><small class="table-sub ltr-text"><?= h($session['phone']) ?></small></td>
          <td class="ltr-text"><?= h($session['ip']) ?></td>
          <td><?= h(mb_substr((string)$session['user_agent'],0,70,'UTF-8')) ?></td>
          <td><?= h(jalaliDate((string)$session['created_at'])) ?></td>
          <td><?= h(jalaliDate((string)$session['last_seen_at'])) ?></td>
          <td><span class="status status-<?= $session['revoked_at'] ? 'cancelled' : 'active' ?>"><?= $session['revoked_at'] ? 'باطل‌شده' : 'فعال' ?></span></td>
          <td><?php if (!$session['revoked_at']): ?><form method="post" action="/admin/sessions/<?= (int)$session['id'] ?>/revoke" data-confirm="این نشست همین حالا باطل شود؟"><?= csrfField() ?><button class="btn btn-small btn-danger" type="submit">ابطال</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty-box">نشستی ثبت نشده است.</div><?php endif; ?>
</section>
