<?php
/** @var array $events */
$preselect = (int)($_GET['event_id'] ?? 0);
?>
<section class="section container narrow">
  <div class="section-head"><div><div class="eyebrow">ورود اطلاعات</div><h1>ورود اطلاعات از Excel یا CSV</h1><p>پیش‌نمایش، گزارش خطای ردیف‌به‌ردیف و بازگشت تراکنش هنگام خطا</p></div></div>
  <?php include PROJECT_ROOT . '/frontend/views/admin/partials/nav.php'; ?>

  <div class="content-card">
    <h2>۱) انتخاب نوع ورود</h2>
    <form method="post" action="/admin/import/preview" enctype="multipart/form-data" class="form-stack">
      <?= csrfField() ?>
      <label>مسیر مجاز ورود<select name="target"><option value="attendees">شرکت‌کنندگان یک دوره</option><option value="employees">فهرست کارکنان (فقط مدیر سامانه)</option></select></label>
      <label>دوره (برای ورود شرکت‌کنندگان)<select name="event_id"><option value="0">انتخاب دوره</option><?php foreach ($events as $event): ?><option value="<?= (int)$event['id'] ?>" <?= $preselect===(int)$event['id']?'selected':'' ?>><?= h($event['title']) ?></option><?php endforeach; ?></select></label>
      <label>فایل (CSV یا XLSX)<input type="file" name="file" accept=".csv,.txt,.xlsx" required></label>
      <button class="btn btn-primary" type="submit">بارگذاری و پیش‌نمایش</button>
      <small>حداکثر ۵ مگابایت و ۵۰۰۰ ردیف در هر بارگذاری.</small>
    </form>
  </div>

  <div class="content-card">
    <h2>ساختار ستون‌های مورد انتظار</h2>
    <div class="table-wrap"><table><thead><tr><th>مسیر</th><th>ستون‌های لازم</th><th>ستون‌های اختیاری</th></tr></thead><tbody>
      <tr><td>شرکت‌کنندگان دوره</td><td>نام، موبایل</td><td>کد ملی</td></tr>
      <tr><td>فهرست کارکنان</td><td>کد ملی، شهرداری</td><td>—</td></tr>
    </tbody></table></div>
    <p class="muted">نام ستون‌ها می‌تواند فارسی یا انگلیسی باشد. در صورت وجود ردیف نامعتبر، هیچ داده‌ای وارد نمی‌شود.</p>
  </div>
</section>
