<?php $crumbs = [['label'=>'خانه','href'=>'/'], ['label'=>'پشتیبانی','href'=>'/support'], ['label'=>'پیگیری تیکت']]; ?>
<?php
/** @var int $ticketId */
/** @var array $messages */
/** @var array $ticket */
$ticket = $ticket ?? [];
$statusLabels = ['open'=>'باز','in_progress'=>'در حال بررسی','answered'=>'پاسخ داده‌شده','closed'=>'بسته‌شده'];
$priorityLabels = ['low'=>'کم','normal'=>'عادی','high'=>'زیاد','urgent'=>'فوری'];
?>
<section class="section container">
  <a href="/support" class="back-link">← بازگشت به پشتیبانی</a>
  <div class="content-card">
    <div class="section-head"><div><h1>گفت‌وگوی تیکت #<?= toPersianDigits((string)$ticketId) ?></h1><p><?= h($ticket['subject'] ?? '') ?></p></div></div>
    <?php if ($ticket !== []): ?>
      <div class="detail-facts">
        <div><small>وضعیت</small><b><?= h($statusLabels[$ticket['status']] ?? $ticket['status']) ?></b></div>
        <div><small>اولویت</small><b><?= h($priorityLabels[$ticket['priority'] ?? 'normal'] ?? 'عادی') ?></b></div>
        <div><small>دوره مرتبط</small><b><?= h($ticket['event_title'] ?? '—') ?></b></div>
        <div><small>زمان ایجاد</small><b><?= h(jalaliDate((string)($ticket['created_at'] ?? ''))) ?></b></div>
      </div>
    <?php endif; ?>
    <div class="message-list"><?php foreach($messages as $message): ?><article class="message <?= empty($message['is_staff'])?'message-user':'message-staff' ?>"><div class="message-head"><b><?= h($message['full_name']) ?></b><time><?= h(jalaliDate($message['created_at'])) ?></time></div><p><?= nl2br(h($message['body'])) ?></p></article><?php endforeach; ?></div>
    <form method="post" action="/support/<?= (int)$ticketId ?>/reply" class="form-stack"><?= csrfField() ?><label>پیام جدید<textarea name="body" required minlength="2" maxlength="5000" rows="4"></textarea></label><button class="btn btn-primary" type="submit">ارسال پیام</button></form>

    <?php if (in_array($ticket['status'] ?? '', ['answered','closed'], true)): ?>
      <h2 class="card-title">سنجش رضایت از پاسخ پشتیبانی</h2>
      <?php if (!empty($ticket['satisfaction_rating'])): ?>
        <p class="muted">امتیاز ثبت‌شده: <?= toPersianDigits((string)$ticket['satisfaction_rating']) ?> از ۵ — <?= h($ticket['satisfaction_comment'] ?? '') ?></p>
      <?php else: ?>
        <form method="post" action="/support/<?= (int)$ticketId ?>/rate" class="form-stack"><?= csrfField() ?>
          <label>امتیاز<select name="rating" required><?php for ($i=1; $i<=5; $i++): ?><option value="<?= $i ?>"><?= toPersianDigits((string)$i) ?> ستاره</option><?php endfor; ?></select></label>
          <label>توضیح کوتاه<input name="comment" maxlength="255"></label>
          <button class="btn btn-soft" type="submit">ثبت امتیاز</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
