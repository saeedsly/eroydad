<?php
/** @var array $setup */
?>
<section class="section container narrow">
  <a href="/account/security" class="back-link">← بازگشت به امنیت حساب</a>
  <div class="content-card">
    <div class="eyebrow">ورود دومرحله‌ای</div>
    <h1>مرحلهٔ دوم: تأیید کد</h1>
    <p class="muted">کد را در اپلیکیشن احرازکنندهٔ خود ببینید و در فرم زیر وارد کنید. اگر QR نمایش داده نشد، کلید متنی زیر را دستی وارد کنید.</p>
    <div class="two-factor-setup">
      <div class="ticket-qr" id="twoFactorQr" data-qr="<?= h($setup['uri']) ?>" aria-label="کد QR ورود دومرحله‌ای"></div>
      <div>
        <p><b>کلید دستی:</b></p>
        <code class="ltr-text manual-key"><?= h($setup['secret']) ?></code>
      </div>
    </div>
    <form method="post" action="/account/security/2fa/enable" class="form-stack">
      <?= csrfField() ?>
      <label>کد شش‌رقمی<input name="code" required inputmode="numeric" maxlength="6" autocomplete="one-time-code" pattern="[0-9]{6}"></label>
      <button class="btn btn-primary" type="submit">فعال‌سازی ورود دومرحله‌ای</button>
    </form>
  </div>
</section>
<script src="/assets/js/qrcode.js" defer></script>
<script>
window.addEventListener('load', function () {
  var element = document.getElementById('twoFactorQr');
  if (!element || typeof qrcode !== 'function') return;
  try {
    var qr = qrcode(0, 'M');
    qr.addData(element.getAttribute('data-qr'), 'Byte');
    qr.make();
    element.innerHTML = qr.createSvgTag(4, 0);
  } catch (e) {
    element.textContent = 'QR ساخته نشد؛ کلید متنی را دستی وارد کنید.';
  }
});
</script>
