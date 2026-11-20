<?php $crumbs = [['label'=>'خانه','href'=>'/'], ['label'=>'پنل فراگیر','href'=>'/account'], ['label'=>'تنظیمات نمایش']]; ?>
<?php
/** @var array $settings */
/** @var array $scales */
?>
<section class="section container narrow">
  <a href="/account" class="back-link">← بازگشت به پنل</a>
  <div class="content-card">
    <div class="eyebrow">دسترس‌پذیری</div>
    <h1>تنظیمات نمایش</h1>
    <p class="muted">حالت روشن/تیره و اندازهٔ قلم به‌صورت شخصی ذخیره می‌شود و روی همین دستگاه اعمال می‌گردد.</p>
    <form method="post" action="/account/settings" class="form-stack">
      <?= csrfField() ?>
      <fieldset class="choice-field"><legend>حالت پوسته</legend>
        <label class="radio-row"><input type="radio" name="theme" value="light" <?= $settings['theme']==='light'?'checked':'' ?>> روشن</label>
        <label class="radio-row"><input type="radio" name="theme" value="dark" <?= $settings['theme']==='dark'?'checked':'' ?>> تیره</label>
        <label class="radio-row"><input type="radio" name="theme" value="auto" <?= $settings['theme']==='auto'?'checked':'' ?>> خودکار (بر اساس سیستم)</label>
      </fieldset>
      <label>اندازهٔ قلم<select name="font_scale"><?php foreach ($scales as $value => $label): ?><option value="<?= (int)$value ?>" <?= (int)$settings['font_scale']===(int)$value?'selected':'' ?>><?= h($label) ?> (<?= toPersianDigits((string)$value) ?>٪)</option><?php endforeach; ?></select></label>
      <button class="btn btn-primary" type="submit">ذخیره تنظیمات</button>
    </form>
  </div>
</section>
