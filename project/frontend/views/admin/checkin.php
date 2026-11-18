<?php
/** @var array $events */
/** @var array $tickets */
$selected = (int)($_GET['event_id'] ?? 0);
?>
<section class="section container">
  <div class="section-head"><div><div class="eyebrow">حضور و غیاب</div><h1>کنترل ورود</h1><p>اسکن QR با دوربین موبایل، ورود دستی و صف آفلاین در قطعی موقت شبکه</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>

  <div class="two-cols">
    <div class="content-card">
      <h2>اسکن QR با دوربین</h2>
      <p class="muted">اگر مرورگر شما از <span class="ltr-text">BarcodeDetector</span> پشتیبانی کند، دوربین فعال می‌شود؛ در غیر این صورت از ورود دستی استفاده کنید.</p>
      <div class="scanner-wrap">
        <video id="scannerVideo" class="scanner-video" playsinline muted aria-label="نمای دوربین برای اسکن QR"></video>
        <canvas id="scannerCanvas" class="scanner-canvas" hidden></canvas>
        <div class="scanner-actions">
          <button class="btn btn-soft btn-small" type="button" id="scannerStart">شروع دوربین</button>
          <button class="btn btn-outline btn-small" type="button" id="scannerStop" disabled>توقف دوربین</button>
        </div>
        <p class="scanner-status" id="scannerStatus" role="status"></p>
      </div>
    </div>

    <div class="content-card">
      <h2>ثبت دستی ورود</h2>
      <form method="post" action="/admin/check-in" class="form-stack" id="manualCheckin">
        <?= csrfField() ?>
        <input type="hidden" name="event_id" value="<?= $selected ?>">
        <label>کد بلیت یا QR<input name="ticket_token" required autocomplete="off" class="ltr-text" placeholder="eroydad-ticket:..."></label>
        <button class="btn btn-primary" type="submit">ثبت ورود</button>
      </form>
      <h2 class="card-title">صف آفلاین</h2>
      <p class="muted">اگر شبکه قطع شود، اسکن‌ها در همین مرورگر نگهداری و پس از اتصال همگام می‌شوند.</p>
      <p class="muted">تعداد در صف: <b id="queueCount">۰</b></p>
      <form method="post" action="/admin/check-in/sync" id="queueForm">
        <?= csrfField() ?>
        <input type="hidden" name="queue" id="queuePayload" value="[]">
        <button class="btn btn-soft btn-small" type="submit">همگام‌سازی صف</button>
      </form>
      <ul class="link-list" id="queueList"></ul>
    </div>
  </div>

  <div class="content-card">
    <h2>بلیت‌های دوره</h2>
    <form class="filter-bar" method="get" action="/admin/check-in">
      <div class="filter-grid">
        <label class="field"><span>دوره</span><select name="event_id" onchange="this.form.submit()"><option value="0">انتخاب دوره</option><?php foreach ($events as $event): ?><option value="<?= (int)$event['id'] ?>" <?= $selected===(int)$event['id']?'selected':'' ?>><?= h($event['title']) ?></option><?php endforeach; ?></select></label>
      </div>
    </form>
    <?php if ($tickets): ?>
      <div class="table-wrap"><table><thead><tr><th>شرکت‌کننده</th><th>موبایل</th><th>کد بلیت</th><th>وضعیت</th></tr></thead><tbody>
        <?php foreach ($tickets as $ticket): ?><tr><td><?= h($ticket['full_name']) ?></td><td class="ltr-text"><?= h($ticket['phone']) ?></td><td class="ltr-text"><?= h(substr((string)$ticket['public_code'],0,12)) ?></td><td><span class="status status-<?= h($ticket['status']) ?>"><?= h($ticket['status']) ?></span></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted">برای دیدن بلیت‌ها یک دوره انتخاب کنید.</p><?php endif; ?>
  </div>
</section>
<script src="/assets/js/checkin.js" defer></script>
