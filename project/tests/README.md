# آزمون‌های خودکار

این پوشه مجموعهٔ آزمون سامانه را دارد. چون PHP و MySQL روی ماشین توسعهٔ ما نصب نیستند،
آزمون‌های سنگین از **موتورهای واقعی اجراشده در WebAssembly** استفاده می‌کنند:

| ابزار | موتور واقعی | آنچه می‌آزماید |
| --- | --- | --- |
| `php-lint-wasm.mjs` | parser واقعی PHP 7.4 | نحوی همهٔ فایل‌های PHP (۹۹ فایل) |
| `schema-test.mjs` | MariaDB 11.8 | اجرای `database/schema.sql`، جدول‌ها، ستون‌ها، enumها، قیدها و seedها |
| `migration-test.mjs` | MariaDB 11.8 | گام‌های SQL مهاجرت ۰۰۲ و بی‌خطر‌بودن اجرای دوباره |
| `migrate-run-test.mjs` | MariaDB 11.8 + PHP 7.4 | خودِ runner مهاجرت روی «نصب قدیمی» (حذف چند جدول/ستون و رکورد دفترچه) |
| `integration-run.mjs` | MariaDB 11.8 + PHP 7.4 | ۱۵۶ بررسی رفتار سرویس‌ها روی ۱۷ محور: رویداد، ثبت‌نام سه‌حالته، مالیات و پرداخت آزمایشی، بلیت/HMAC/کنترل ورود، لغو و فهرست انتظار، گواهی و بازخورد، اعلان و یادآور، نقش و مجوز، متن امن/ICS/TOTP، Excel (ورود و خروج)، دسک پشتیبانی، گزارش‌ها، پوستر، مالکیت رویداد، پوشاندن داده، نشست/بازیابی رمز/مهاجرت/پشتیبان/retention، قفل درگاه واقعی |
| `http-smoke-test.mjs` | MariaDB 11.8 + PHP 7.4 | ۱۳۶ بررسی در سطح HTTP روی فرانت‌کنترل واقعی: رندر صفحه‌ها، نشست، CSRF، فلش، کنترل دسترسی (مجبور‌سازی ۴۰۳ و دیدن مسیر مجاز)، منوی نقش‌محور، فرم ثبت‌نام واقعی و تکراری، پنل وضعیت جایگاه در صفحهٔ رویداد (قطعی/پرداخت‌نشده/ادامهٔ پرداخت)، ریز مبلغ و مالیات روی صفحهٔ رویداد پولی، اطلاعات سازمانی، تقویم ICS، JSON همگام‌سازی صف، دانلود XLSX، مسیرهای PWA، نصب در زیرپوشه و یکپارچگی پیوندها (هر href/src/action باید مسیر ثبت‌شدۀ برنامه یا فایل استاتیک باشد، و لنگرهای درون‌صفحه‌ای به قطعهٔ موجود برسند) |
| `static-analyze.js` | — (تحلیل متن) | سازگاری route/سرویس/view، پوشش `action` فرم‌ها، وجود فایل‌های require، `prepare()` بدون `execute()`، کلاس namespaced بدون `import`، دسترس‌پذیری (alt، برچسب ورودی‌ها، `lang`/`dir`) و تأییدیهٔ `data-confirm` روی فرم‌های حساس |
| `gen-routes-doc.mjs` | — (متن) | تولید `docs/api/routes.md` از خطوط `route(…)` و کنترل `--check` هم‌خوانی سند با کد؛ جفت (method, pattern) تکراری و متد غیرمجاز را تحلیل ایستا گزارش می‌کند |
| `unit-run.mjs` + `Unit/*.php` | PHP 7.4 (WASM) | آزمون واحدِ منطق خالص: مالیات و Money، ماتریس مجوزها، اندیس ستون XLSX، خروجی ICS، کد ملی (۵ فایل، ۵۱ بررسی) |
| `security-run.mjs` | PHP 7.4 + MariaDB | امنیت در سطح HTTP: مالکیت/IDOR روی پرداخت-بلیت-بازخورد-رسید، 403 نقش، CSRF (بدون/نادرست/درست)، XSS ذخیره‌شده در نام سازمانی، باطل‌شدن نشست با «خروج از همه»، محدودیت نرخ ورود (۲۳ بررسی) |
| `acceptance-run.mjs` | PHP 7.4 + MariaDB | سه سفر کامل: فراگیر (ثبت‌نام تا لغو و بازخورد و تیکت)، پولی (شکست ← ادامه ← موفق ← رسید)، مدیر (داشبورد + بازکردن واقعی zip/XML خروجی XLSX) — ۳۳ بررسی |
| `clean-install-test.mjs` | PHP 7.4 + MariaDB | بند ۸۵ نقشه‌راه: استخراج واقعیِ ZIP تحویلی ← بررسی ساختار (بدون `.env`، بدون `node_modules`) ← دیتابیس تازه + schema + migration ← بالا آوردن فرانت‌کنترل از نسخهٔ استخراج‌شده ← یک نوشتن واقعی (ساخت حساب) — ۲۵ بررسی |
| `browser-smoke.mjs` | Chromium 131 واقعی (npm) | بند ۸۶ نقشه‌راه: سه اندازهٔ ۳۶۰/۷۶۸/۱۲۸۰ روی چهار صفحهٔ کلیدی — بدون اسکرول افقی، بدون خطای JS، بدون درخواست شکسته، منوی کشویی در موبایل و دسکتاپ، فرم ورود در دیدرس، فونت محلی — ۱۹ بررسی |
| `jalali-date.test.js` | — (Node) | تبدیل و اعتبارسنجی تقویم شمسی در `jalali-date-picker.js` |
| `zip-audit.mjs` | — (Node) | ساختار بستهٔ ZIP: نبود رازها و `node_modules`، بودن فایل‌های لازم نصب، نبود دادهٔ کاربری در `storage/private`، نبود ارجاع CDN، نبود فایل تکراری |
| `build-package.mjs` | — (Node) | ساخت بستهٔ ZIP قابل‌تحویل از پوشهٔ پروژه (بدون راز، `node_modules` و دادهٔ کاربری)؛ خروجی مرتب و تکرارپذیر است و پیش‌پوشهٔ `erooydad` دارد |

## آماده‌سازی

```bash
cd tests
npm install          # @php-wasm/node، @php-wasm/universal، @adrianfalleiro/mariadb-wasm
```

نیازمند Node.js نسخهٔ ۲۲٫۶ یا بالاتر است (موتور MariaDB WASM به آن وابسته است).

## اجرا

هر آزمون یک موتور PHP و یک دیتابیس MariaDB درون‌فرایندی بالا می‌آورد؛ پس **یکی‌یکی** اجرا شوند
(اجرای موازی روی ماشین‌های کم‌رمحاش با کرش WASM همراه است).

```bash
node tests/static-analyze.js        # تحلیل ایستا (سریع، بدون WASM)
node tests/gen-routes-doc.mjs --check  # هم‌خوانی سند مسیرها با کد
node tests/unit-run.mjs              # آزمون واحد (tests/Unit/*.php)
node tests/security-run.mjs          # آزمون امنیت
node tests/acceptance-run.mjs        # آزمون پذیرش (سه سفر کامل)
node tests/clean-install-test.mjs    # آزمون نصب تمیز از خودِ بستهٔ ZIP (بند ۸۵)
node tests/browser-smoke.mjs         # آزمون مرورگر و اندازه‌های صفحه (بند ۸۶؛ Chromium از devDependencies نصب می‌شود)
node tests/jalali-date.test.js      # آزمون تقویم شمسی (سریع، بدون WASM)
> `jalali-date.test.js` فایل زیر آزمون را با مسیر نسبی نسبت به پوشهٔ `tests/` می‌خواند؛ پس باید از ریشهٔ پروژه (`project/`) اجرا شود، نه از یک پوشهٔ کپی‌شده.

node tests/php-lint-wasm.mjs        # نحوی PHP 7.4 همهٔ فایل‌ها
node tests/schema-test.mjs          # اجرای schema.sql روی MariaDB واقعی
node tests/migration-test.mjs       # گام‌های SQL مهاجرت
node tests/migrate-run-test.mjs     # خودِ runner مهاجرت روی «نصب قدیمی»
node tests/integration-run.mjs      # رفتار سرویس‌ها
node tests/http-smoke-test.mjs      # صفحه‌ها و مسیرهای PWA در سطح HTTP
node tests/zip-audit.mjs [مسیر.zip] # بازرسی بستهٔ قابل‌نصب
node tests/build-package.mjs [out.zip] # ساخت بستهٔ قابل‌تحویل (بعد از آن zip-audit را اجرا کنید)
```

یا از npm:

```bash
npm --prefix tests run analyze
npm --prefix tests run lint
npm --prefix tests run schema
npm --prefix tests run migration
npm --prefix tests run migration:runner
npm --prefix tests run integration
npm --prefix tests run http
npm --prefix tests run datepicker
npm --prefix tests run zip
npm --prefix tests run package   # ساخت بسته، بعد npm --prefix tests run zip
```

مسیر پروژه به‌صورت پیش‌فرض پوشهٔ والد `tests/` است؛ می‌توان مسیر را صریح داد:
`node tests/schema-test.mjs /مسیر/پروژه`.

## دربارهٔ دقت آزمون‌ها

* دیتابیس هر آزمون در حافظه و تازه است؛ هیچ داده‌ای از محیط واقعی پاک یا تغییر نمی‌کند.
* آزمون‌ها فقط با موتور MariaDB واقعی (هم‌خانواده با MariaDB ۱۰٫۴+ در XAMPP) اجرا می‌شوند؛
  رفتار Apache (mod_rewrite، `.htaccess`، HTTPS) و مرورگرهای واقعی را نمی‌آزمایند.
  مسیرها در آزمون HTTP با request handler درون‌فرایندی شبیه‌سازی می‌شوند (فایل نبودن ⇒ `/index.php`).
* در XAMPP رایانهٔ مقصد اجرای واقعی انجام نشده و نباید ادعا شود.
* درخواست‌های POST را در آزمون HTTP با `Uint8Array` بفرستید؛ اگر شیء را مستقیم به `PHPRequestHandler` بدهید،
  بدنه‌های چندبایتی (متن فارسی) کوتاه می‌شوند و خطای encoding به‌جای رفتار واقعی برنامه دیده می‌شود.
  تابع `req()` در همین فایل این کار را می‌کند.
* آزمون‌ها نباید هم‌زمان اجرا شوند: هر کدام موتور MariaDB درون‌فرایندی خود را بالا می‌آورد و اجرای موازی
  `integration-run.mjs` و `http-smoke-test.mjs` روی ماشین‌های کم‌رمحاش باعث کرش WASM می‌شود.
* `MYSQL_ATTR_INIT_COMMAND` در ساخت PHP-WASM کرش می‌کند؛ به همین دلیل `SET NAMES` در
  `backend/src/Database/Connection.php` پس از اتصال اجرا می‌شود (روی PHP واقعی هم بی‌ضرر است).
