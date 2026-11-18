<?php
/** @var array $user */
/** @var array $two_factor */
/** @var array $sessions */
?>
<section class="section container narrow">
  <a href="/account" class="back-link">← بازگشت به پنل</a>
  <div class="content-card">
    <div class="eyebrow">امنیت</div>
    <h1>امنیت حساب</h1>

    <h2 class="card-title">ورود دومرحله‌ای</h2>
    <?php if (!empty($two_factor['enabled'])): ?>
      <p class="muted">ورود دومرحله‌ای فعال است. برای غیرفعال‌کردن، کد شش‌رقمی یا یکی از کدهای بازیابی را وارد کنید.</p>
      <form method="post" action="/account/security/2fa/disable" class="form-stack" data-confirm="با غیرفعال‌کردن ورود دومرحله‌ای امنیت حساب کم می‌شود. مطمئنید؟"><?= csrfField() ?><label>کد تأیید<input name="code" required inputmode="numeric" maxlength="20"></label><button class="btn btn-danger" type="submit">غیرفعال‌کردن ورود دومرحله‌ای</button></form>
    <?php else: ?>
      <p class="muted">با فعال‌کردن ورود دومرحله‌ای، حتی با دانستن رمز عبور هم ورود ممکن نیست. کد با اپلیکیشن احرازکنندهٔ شما تولید می‌شود و هیچ داده‌ای به بیرون ارسال نمی‌گردد.</p>
      <form method="post" action="/account/security/2fa/setup"><?= csrfField() ?><button class="btn btn-soft" type="submit">راه‌اندازی ورود دومرحله‌ای</button></form>
    <?php endif; ?>

    <h2 class="card-title">تغییر رمز عبور</h2>
    <form method="post" action="/account/security/password" class="form-stack"><?= csrfField() ?><label>رمز عبور فعلی<input type="password" name="current_password" required autocomplete="current-password"></label><label>رمز عبور تازه<input type="password" name="new_password" required minlength="12" maxlength="200" autocomplete="new-password"><small>دست‌کم ۱۲ نویسه</small></label><button class="btn btn-primary" type="submit">تغییر رمز</button></form>

    <h2 class="card-title">نشست‌های فعال</h2>
    <div class="table-wrap"><table><thead><tr><th>نشانی IP</th><th>مرورگر</th><th>آخرین فعالیت</th></tr></thead><tbody>
      <?php foreach ($sessions as $session): ?><tr><td class="ltr-text"><?= h($session['ip']) ?></td><td><?= h(mb_substr((string)$session['user_agent'],0,80,'UTF-8')) ?></td><td><?= h(jalaliDate((string)$session['last_seen_at'])) ?></td></tr><?php endforeach; ?>
      <?php if ($sessions === []): ?><tr><td colspan="3">نشستی ثبت نشده است.</td></tr><?php endif; ?>
    </tbody></table></div>
    <form method="post" action="/account/security/logout-all" data-confirm="از همهٔ دستگاه‌ها خارج می‌شوید. ادامه می‌دهید؟"><?= csrfField() ?><button class="btn btn-danger" type="submit">خروج از همهٔ دستگاه‌ها</button></form>
  </div>
</section>
