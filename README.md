# ای‌رویداد — eroydad

سامانهٔ ثبت‌نام و فروش بلیت رویدادهای الکترونیک (RTL/فارسی) با PHP 7.4 + MySQL.

**همهٔ کد، مستندات و آزمون‌ها در پوشه [`project/`](project/) است** — راهنمای کامل، وضعیت صادقانه و نحوهٔ اجرا: [`project/README.md`](project/README.md).

## سریع‌ترین راه اجرا (XAMPP)

بستهٔ نصبی آماده را از مسیر زیر بردارید و طبق `project/README.md` روی XAMPP (PHP 7.4) نصب کنید:

- `eroydad-xampp-php74-updated.zip` (کپی Dropbox: [`eroydad-xampp-php74-updated.zip`](https://www.dropbox.com/scl/fi/8eqxhvwf96p2jwg7c9k5m/eroydad-xampp-php74-updated.zip?rlkey=ev0fy21eid40xaaj50pthacgc&dl=0))

## آزمون‌ها

از داخل `project/`:

```bash
npm --prefix tests install        # یک بار
npm --prefix tests run all        # کل زنجیره: تحلیل ایستا، schema، migration، یکپارچگی، HTTP، بسته
```

جزئیات هر آزمون در [`project/tests/README.md`](project/tests/README.md) و نقشهٔ راه بهبود در [`project/tests/ROADMAP-FA.md`](project/tests/ROADMAP-FA.md) است.

## پوشه‌های دیگر ریشه

- `demo-local/` — نمایشگر آفلاین بدون PHP/MySQL (فقط دمو با دادهٔ ساختگی؛ برای محیط عملیاتی نیست).
- `database/`، `docs/`، `index.html`، `styles.css`، `app.js` — بقایای مرحلهٔ اولیهٔ طراحی؛ نسخهٔ معتبر همان چیزی است که داخل `project/` است.
