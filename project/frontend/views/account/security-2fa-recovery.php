<?php
/** @var array $codes */
?>
<section class="section container narrow">
  <div class="content-card">
    <div class="eyebrow">کدهای بازیابی</div>
    <h1>کدهای بازیابی یک‌بارمصرف</h1>
    <p class="muted">این کدها را چاپ کنید یا در جای امن نگه دارید. هر کد فقط یک‌بار کار می‌کند. پس از خروج از این صفحه دوباره نمایش داده نمی‌شود.</p>
    <ul class="recovery-codes ltr-text"><?php foreach ($codes as $code): ?><li><?= h($code) ?></li><?php endforeach; ?></ul>
    <a class="btn btn-primary" href="/account/security">بازگشت به امنیت حساب</a>
  </div>
</section>
