<?php
/** @var array $profile */
/** @var array $user */
$profile = $profile ?? [];
?>
<section class="section container narrow">
  <a href="/account" class="back-link">← بازگشت به پنل</a>
  <div class="content-card">
    <div class="eyebrow">اطلاعات سازمانی</div>
    <h1>تکمیل اطلاعات سازمانی</h1>
    <p class="muted">برای دوره‌های ویژهٔ کارکنان شهرداری‌ها، اطلاعات سازمانی شما لازم است. این اطلاعات فقط در اختیار مدیران همان دوره قرار می‌گیرد.</p>
    <form method="post" action="/account/organization" class="form-stack">
      <?= csrfField() ?>
      <label>نام سازمان<input name="organization_name" required minlength="2" maxlength="190" value="<?= h($profile['organization_name'] ?? '') ?>" placeholder="شهرداری شهرستان ..."></label>
      <label>شناسهٔ ملی / شناسهٔ سازمان<input name="national_id" maxlength="20" value="<?= h($profile['national_id'] ?? '') ?>"></label>
      <label>نشانی<input name="address" maxlength="240" value="<?= h($profile['address'] ?? '') ?>"></label>
      <label>ایمیل تماس<input type="email" name="contact_email" maxlength="160" value="<?= h($profile['contact_email'] ?? '') ?>"></label>
      <label>تلفن ثابت یا همره<input name="contact_phone" maxlength="20" value="<?= h($profile['contact_phone'] ?? '') ?>"></label>
      <button class="btn btn-primary" type="submit">ذخیره اطلاعات</button>
    </form>
  </div>
</section>
