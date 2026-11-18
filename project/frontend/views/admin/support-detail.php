<?php
/** @var int $ticketId */
/** @var array $messages */
/** @var array $ticket */
/** @var array $labels */
/** @var array $ticket_labels */
/** @var array $canned */
/** @var array $assignees */
$statusLabels = ['open'=>'باز','in_progress'=>'در حال بررسی','answered'=>'پاسخ داده‌شده','closed'=>'بسته‌شده'];
$priorityLabels = ['low'=>'کم','normal'=>'عادی','high'=>'زیاد','urgent'=>'فوری'];
$currentLabelIds = array_map('intval', array_column($ticket_labels, 'id'));
?>
<section class="section container">
  <a href="/admin/support" class="back-link">← بازگشت به فهرست تیکت‌ها</a>
  <div class="content-card">
    <h1><?= h($ticket['subject'] ?? ('تیکت #' . $ticketId)) ?></h1>
    <p class="muted">فراگیر: <?= h($ticket['full_name'] ?? '') ?> · <span class="ltr-text"><?= h($ticket['phone'] ?? '') ?></span></p>
    <div class="detail-facts">
      <div><small>وضعیت</small><b><?= h($statusLabels[$ticket['status'] ?? 'open'] ?? 'باز') ?></b></div>
      <div><small>اولویت</small><b><?= h($priorityLabels[$ticket['priority'] ?? 'normal'] ?? 'عادی') ?></b></div>
      <div><small>مسئول</small><b><?= h($ticket['assigned_name'] ?? '—') ?></b></div>
      <div><small>هدف SLA</small><b><?= !empty($ticket['sla_due_at']) ? h(jalaliDate((string)$ticket['sla_due_at'])) : '—' ?></b></div>
      <div><small>اولین پاسخ</small><b><?= !empty($ticket['first_response_at']) ? h(jalaliDate((string)$ticket['first_response_at'])) : '—' ?></b></div>
      <div><small>رضایت کاربر</small><b><?= !empty($ticket['satisfaction_rating']) ? toPersianDigits((string)$ticket['satisfaction_rating']) . ' از ۵' : '—' ?></b></div>
    </div>

    <div class="row-actions admin-actions">
      <form method="post" action="/admin/support/<?= (int)$ticketId ?>/priority"><?= csrfField() ?><select name="priority" aria-label="اولویت"><?php foreach ($priorityLabels as $value => $label): ?><option value="<?= h($value) ?>" <?= ($ticket['priority'] ?? 'normal')===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select><button class="btn btn-small btn-soft" type="submit">ثبت اولویت</button></form>
      <form method="post" action="/admin/support/<?= (int)$ticketId ?>/assign"><?= csrfField() ?><select name="assignee" aria-label="مسئول"><option value="">بدون مسئول</option><?php foreach ($assignees as $assignee): ?><option value="<?= (int)$assignee['id'] ?>" <?= (int)($ticket['assigned_to'] ?? 0)===(int)$assignee['id']?'selected':'' ?>><?= h($assignee['full_name']) ?></option><?php endforeach; ?></select><button class="btn btn-small btn-soft" type="submit">تخصیص</button></form>
      <form method="post" action="/admin/support/<?= (int)$ticketId ?>/status"><?= csrfField() ?><select name="status" aria-label="وضعیت"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= h($value) ?>" <?= ($ticket['status'] ?? 'open')===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select><button class="btn btn-small btn-soft" type="submit">ثبت وضعیت</button></form>
    </div>

    <h2 class="card-title">برچسب‌ها</h2>
    <div class="tag-list">
      <?php foreach ($labels as $label): ?>
        <form method="post" action="/admin/support/<?= (int)$ticketId ?>/label" class="inline-form"><?= csrfField() ?><input type="hidden" name="label_id" value="<?= (int)$label['id'] ?>"><button class="tag-chip<?= in_array((int)$label['id'], $currentLabelIds, true) ? ' is-active' : '' ?>" type="submit"><?= h($label['name']) ?></button></form>
      <?php endforeach; ?>
    </div>

    <h2 class="card-title">گفت‌وگو</h2>
    <div class="message-list"><?php foreach($messages as $message): ?><article class="message <?= empty($message['is_staff'])?'message-user':'message-staff' ?>"><div class="message-head"><b><?= h($message['full_name']) ?></b><time><?= h(jalaliDate($message['created_at'])) ?></time></div><p><?= nl2br(h($message['body'])) ?></p></article><?php endforeach; ?></div>

    <form method="post" action="/admin/support/<?= (int)$ticketId ?>/reply" class="form-stack">
      <?= csrfField() ?>
      <?php if ($canned): ?>
        <label>پاسخ آماده<select name="canned_select" data-canned><option value="">انتخاب کنید…</option><?php foreach ($canned as $item): ?><option value="<?= h($item['body']) ?>"><?= h($item['title']) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <label>پاسخ شما<textarea name="body" required minlength="2" maxlength="5000" rows="4" id="replyBody"></textarea></label>
      <button class="btn btn-primary" type="submit">ثبت پاسخ</button>
    </form>
    <script>
    (function () {
      var select = document.querySelector('[data-canned]');
      var body = document.getElementById('replyBody');
      if (!select || !body) return;
      select.addEventListener('change', function () {
        if (select.value) body.value = select.value;
      });
    })();
    </script>
  </div>
</section>
