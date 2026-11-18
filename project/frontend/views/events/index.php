<?php
/** @var array $events */
/** @var array $filters */
/** @var array $categories */
/** @var array $tags */
$filters = $filters ?? [];
$categories = $categories ?? [];
$tags = $tags ?? [];
?>
<section class="section container">
  <div class="section-head">
    <div><div class="eyebrow">کاتالوگ دوره‌ها</div><h1>همه رویدادها</h1><p>جست‌وجو و فیلتر پیشرفته بر اساس موضوع، شیوه برگزاری، تاریخ و هزینه</p></div>
  </div>

  <form class="filter-bar" method="get" action="/events" role="search" aria-label="فیلتر رویدادها">
    <div class="filter-grid">
      <label class="field"><span>جست‌وجو</span><input type="search" name="q" value="<?= h($filters['q'] ?? '') ?>" placeholder="عنوان، موضوع یا توضیح دوره"></label>
      <label class="field"><span>دسته‌بندی</span><select name="category"><option value="">همهٔ دسته‌ها</option><?php foreach ($categories as $category): ?><option value="<?= h($category) ?>" <?= ($filters['category'] ?? '') === $category ? 'selected' : '' ?>><?= h($category) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>شیوه برگزاری</span><select name="mode"><option value="">همه</option><option value="in_person" <?= ($filters['mode'] ?? '') === 'in_person' ? 'selected' : '' ?>>حضوری</option><option value="online" <?= ($filters['mode'] ?? '') === 'online' ? 'selected' : '' ?>>آنلاین</option><option value="hybrid" <?= ($filters['mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>ترکیبی</option></select></label>
      <label class="field"><span>از تاریخ</span><input type="date" name="from" value="<?= h($filters['from'] ?? '') ?>"></label>
      <label class="field"><span>تا تاریخ</span><input type="date" name="to" value="<?= h($filters['to'] ?? '') ?>"></label>
      <label class="field"><span>هزینه</span><select name="price"><option value="">همه</option><option value="free" <?= ($filters['price'] ?? '') === 'free' ? 'selected' : '' ?>>رایگان</option><option value="paid" <?= ($filters['price'] ?? '') === 'paid' ? 'selected' : '' ?>>دارای هزینه (آزمایشی)</option></select></label>
      <label class="field"><span>ترتیب</span><select name="sort"><option value="soon" <?= ($filters['sort'] ?? '') === 'soon' ? 'selected' : '' ?>>نزدیک‌ترین</option><option value="newest" <?= ($filters['sort'] ?? '') === 'newest' ? 'selected' : '' ?>>جدیدترین</option><option value="capacity" <?= ($filters['sort'] ?? '') === 'capacity' ? 'selected' : '' ?>>ظرفیت باقی‌مانده</option></select></label>
      <?php if ($tags !== []): ?>
        <label class="field"><span>برچسب</span><select name="tag"><option value="">همهٔ برچسب‌ها</option><?php foreach ($tags as $tag): ?><option value="<?= h($tag['tag']) ?>" <?= ($filters['tag'] ?? '') === $tag['tag'] ? 'selected' : '' ?>><?= h($tag['tag']) ?> (<?= toPersianDigits((string)$tag['total']) ?>)</option><?php endforeach; ?></select></label>
      <?php endif; ?>
    </div>
    <div class="filter-actions"><button class="btn btn-primary btn-small" type="submit">اعمال فیلتر</button><a class="btn btn-outline btn-small" href="/events">پاک کردن</a></div>
  </form>

  <?php if ($events): ?>
    <div class="event-grid">
      <?php foreach ($events as $event): ?>
        <article class="event-card">
          <?php if (!empty($event['poster_path'])): ?>
            <a class="event-art event-art-poster" href="/events/<?= h($event['slug']) ?>"><img src="/events/<?= h($event['slug']) ?>/poster" alt="<?= h($event['poster_alt'] ?: ('پوستر ' . $event['title'])) ?>" loading="lazy"></a>
          <?php else: ?>
            <a class="event-art" href="/events/<?= h($event['slug']) ?>" aria-label="مشاهده <?= h($event['title']) ?>"><span class="art-tag"><?= h($event['category']) ?></span><span class="art-symbol" aria-hidden="true">✦</span><span class="art-lines" aria-hidden="true"></span></a>
          <?php endif; ?>
          <div class="event-card-body">
            <div class="event-meta"><span><?= h(jalaliDate($event['starts_at_utc'])) ?></span><span>·</span><span><?= $event['delivery_mode'] === 'online' ? 'آنلاین' : ($event['delivery_mode'] === 'hybrid' ? 'ترکیبی' : 'حضوری') ?></span></div>
            <h3><a href="/events/<?= h($event['slug']) ?>"><?= h($event['title']) ?></a></h3>
            <p><?= h(mb_substr((string)$event['summary'], 0, 140, 'UTF-8')) ?></p>
            <div class="event-card-bottom"><span>ظرفیت: <?= toPersianDigits((string)$event['registered_count']) ?> از <?= toPersianDigits((string)$event['capacity']) ?></span><b><?= (int)$event['price_toman'] > 0 ? number_format((int)$event['price_toman']) . ' تومان' : 'رایگان' ?></b></div>
            <a class="card-link" href="/events/<?= h($event['slug']) ?>">جزئیات و ثبت‌نام <span>←</span></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-box"><span aria-hidden="true">∅</span><h3>نتیجه‌ای یافت نشد</h3><p>فیلترها را تغییر دهید یا عبارت دیگری جست‌وجو کنید.</p></div>
  <?php endif; ?>
</section>
