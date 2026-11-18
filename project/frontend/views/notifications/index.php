<?php
/** @var array $notifications */
/** @var int $unread */
$typeLabels = ['registration'=>'ثبت‌نام','payment'=>'پرداخت آزمایشی','reminder'=>'یادآوری','ticket'=>'پشتیبانی','general'=>'عمومی'];
?>
<section class="section container narrow">
  <div class="section-head">
    <div><div class="eyebrow">مرکز اعلان‌ها</div><h1>اعلان‌های شما</h1><p><?= toPersianDigits((string)$unread) ?> اعلان خوانده‌نشده</p></div>
    <form method="post" action="/notifications/read"><?= csrfField() ?><button class="btn btn-soft btn-small" type="submit">خواندن همه</button></form>
  </div>
  <?php if ($notifications): ?>
    <ul class="notification-list">
      <?php foreach ($notifications as $notification): ?>
        <li class="notification-item<?= (int)$notification['is_read']===0 ? ' is-unread' : '' ?>">
          <div class="notification-head"><span class="tag-chip"><?= h($typeLabels[$notification['type']] ?? $notification['type']) ?></span><time><?= h(jalaliDate((string)$notification['created_at'])) ?></time></div>
          <b><?= h($notification['title']) ?></b>
          <?php if ($notification['body'] !== ''): ?><p><?= h($notification['body']) ?></p><?php endif; ?>
          <?php if (!empty($notification['link'])): ?><a href="<?= h($notification['link']) ?>">مشاهده <span>←</span></a><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <div class="empty-box"><span aria-hidden="true">🔔</span><h3>اعلانی ندارید</h3><p>اعلان‌های مربوط به ثبت‌نام، پرداخت آزمایشی و یادآوری دوره‌ها اینجا نمایش داده می‌شود.</p></div>
  <?php endif; ?>
</section>
