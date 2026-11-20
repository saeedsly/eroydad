<?php $crumbs = [['label'=>'خانه','href'=>'/'], ['label'=>'بازخورد دوره']]; ?>
<?php
/** @var array $registration */
/** @var array $feedback */
?>
<section class="section container narrow">
  <a href="/account" class="back-link">← بازگشت به پنل</a>
  <div class="content-card">
    <div class="eyebrow">بازخورد</div>
    <h1>ارزیابی دوره</h1>
    <p class="muted">نظر شما دربارهٔ «<?= h($registration['title']) ?>» به بهبود کیفیت دوره‌ها کمک می‌کند.</p>
    <form method="post" action="/feedback/<?= (int)$registration['id'] ?>" class="form-stack">
      <?= csrfField() ?>
      <label>امتیاز شما (۱ تا ۵)<select name="rating" required><?php for ($i=1; $i<=5; $i++): ?><option value="<?= $i ?>" <?= (int)($feedback['rating'] ?? 0)===$i?'selected':'' ?>><?= toPersianDigits((string)$i) ?> ستاره</option><?php endfor; ?></select></label>
      <label>توضیح شما<textarea name="comment" maxlength="800" rows="5" placeholder="نقاط قوت و پیشنهادهای شما"><?= h($feedback['comment'] ?? '') ?></textarea></label>
      <button class="btn btn-primary" type="submit">ثبت بازخورد</button>
    </form>
  </div>
</section>
