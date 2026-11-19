# ای‌رویداد — نسخه اول قابل نصب

سامانه فارسی و RTL برای فهرست/مدیریت رویداد، ثبت‌نام رایگان، پنل فراگیر و مدیریت کاربران، مدیریت رویداد با محدودیت مالکیت/نقش، گزارش audit، تطبیق اختیاری کارکنان با فهرست CSV، بلیت چاپی/PDF با QR، ثبت ورود یک‌باره و پشتیبانی.

**این نسخه پرداخت، درگاه، callback، پیامک و OTP ندارد.** حساب‌ها با موبایل و رمز عبور وارد می‌شوند و موبایل تاییدشده تلقی نمی‌شود. قیمت رویدادهای این نسخه فقط صفر است. اتصال کارمند شهرداری با CSV مجاز مدیر انجام می‌شود، نه با استعلام دولتی.

## طراحی رابط

پوستهٔ جدید از توکن‌های معنایی CSS، سطوح و اجزای قابل‌دسترسی الهام‌گرفته از [shadcn/ui](https://github.com/shadcn-ui/ui) و [Radix Themes](https://github.com/radix-ui/themes) استفاده می‌کند؛ بدون افزودن وابستگی React یا CDN. فونت Vazirmatn به‌صورت محلی همراه بسته و با مجوز OFL آمده است. جزئیات مجوز در `THIRD_PARTY_NOTICES.md` است.

## نیازمندی

- PHP 7.4.25+ با `pdo_mysql`, `mbstring` و `openssl` فعال؛ `intl` برای قالب تاریخ فارسی بهتر است، اما تبدیل داخلی تاریخ هم در نظر گرفته شده. PHP 7.4 پایان‌عمر است؛ این بسته فقط برای تست محلی مناسب است.
- MariaDB 10.4.32 (نسخه هدف XAMPP) یا MySQL سازگار با InnoDB، `utf8mb4` و CHECK constraint.
- XAMPP پیش‌فرض با `mod_rewrite` و خواندن `.htaccess`؛ پوشهٔ `htdocs/erooydad` از مسیر `/erooydad` به برنامه هدایت می‌شود. در XAMPP استاندارد نباید نیازی به ویرایش `php.ini`، `httpd.conf`، VirtualHost یا Alias باشد.
- webroot باید `project/frontend/public/` باشد. بسته با `.htaccess` در `htdocs` مسیرها را به آن هدایت و مسیرهای خصوصی را می‌بندد.

## نصب سریع برای تست در XAMPP (Windows)

**روش پیشنهادی — یک صفحه، بدون phpMyAdmin و بدون خط فرمان:** پوشهٔ `erooydad` را زیر `C:\\xampp\\htdocs` استخراج کن، Apache و MySQL را Start کن، بعد در مرورگر باز کن:

```
http://localhost/erooydad/install.php
```

فرم را با پیش‌فرض‌های XAMPP پر کن (دیتابیس با `root` بدون رمز + شماره/نام/رمز مدیر) و «نصب کامل را اجرا کن» را بزن. نصب‌کننده خودش دیتابیس و schema و `.env` (با کلیدهای تصادفی) و حساب مدیر اول را می‌سازد. بعد از پیام موفقیت، `install.php` را حذف کن و با همان شماره/رمز وارد شو. جزئیات و روش دستی در ادامه و در [`INSTALL-XAMPP-FA.txt`](INSTALL-XAMPP-FA.txt) است.

### روش دستی (جایگزین)

1. پوشهٔ `erooydad` را مستقیم زیر `C:\xampp\htdocs` استخراج کن. اگر فایل `.env` از نصب قبلی داری، آن را نگه دار. XAMPP Control Panel را باز و MySQL را Start کن.
2. دیتابیس را دوباره Import نکن اگر قبلاً `schema.sql` را وارد کرده‌ای. اگر هنوز انجام نداده‌ای، در phpMyAdmin دیتابیس `erooydad` را بساز و `database/schema.sql` را فقط یک‌بار Import کن.
3. از CMD این دستور را اجرا کن:

   ```bat
   C:\xampp\php\php.exe C:\xampp\htdocs\erooydad\deployment\local-xampp\setup-local-test.php
   ```

   اتصال و schema را بررسی می‌کند. اگر `.env` موجود باشد، کلیدهای برنامه را حفظ می‌کند؛ اگر اتصال دیتابیسش ناموفق باشد، تلاش می‌کند با حساب پیش‌فرض محلی XAMPP (`root` بدون رمز) فقط تنظیمات `DB_*` را خودکار ترمیم کند. اگر `.env` وجود نداشته باشد، DB user محدود و کلیدهای تصادفی را در همان رایانه می‌سازد. دیتابیس و جدول‌ها حذف یا دوباره‌سازی نمی‌شوند. اگر برای root رمز تعیین شده باشد، ترمیم خودکار با تنظیم پیش‌فرض انجام نمی‌شود. اگر مدیر از قبل وجود داشته باشد، مدیر دیگری ساخته نمی‌شود؛ در غیر این صورت اطلاعات مدیر را در CMD وارد کن. رمز را در گفتگو نفرست؛ هنگام تایپ در Windows ممکن است دیده شود.
4. Apache را Start کن و برو به `https://localhost/erooydad`. اگر HTTPS در XAMPP فعال نیست، `http://localhost/erooydad` را امتحان کن. مسیر به `.htaccess`، `mod_rewrite` و اجازهٔ Apache برای خواندن `.htaccess` وابسته است.
5. برای smoke check:

   ```bat
   C:\xampp\php\php.exe C:\xampp\htdocs\erooydad\tests\smoke.php
   ```

اگر setup به دلیل رمز root، نام/پورت دیتابیس یا schema متوقف شد، چیزی را در گفتگو نفرست و دیتابیس را دوباره Import نکن. این بسته در محیط توسعه بررسی شده، اما روی XAMPP رایانهٔ شما اجرای واقعی نشده است.

## آزمون خودکار (اختیاری، برای توسعۀ همین مخزن)

در این بسته پوشهٔ `tests/` دو دسته آزمون دارد:

1. **آزمون سبک روی XAMPP** (برای همین رایانه، بدون ابزار اضافه): `php tests/smoke.php` از خط فرمان، که سرویس‌های ثبت‌نام و دسترسی را با PHP 7.4 واقعی اجرا می‌کند.
2. **مجموعهٔ کامل خودکار** که در محیط توسعه اجرا می‌شود و به Node.js ۲۲٫۶+ نیاز دارد؛ موتورهای واقعی PHP 7.4 و MariaDB 11.8 روی WebAssembly بالا می‌آید و چیزی از XAMPP شما استفاده نمی‌کند. توضیح کامل و ترتیب اجرا در `tests/README.md` است (نصب وابستگی‌ها اینترنت و حدود ۱۰۰ مگابایت موتور WASM لازم دارد؛ برای نصب سایت اختیاری است).

```bash
npm --prefix tests install
npm --prefix tests run analyze       # تحلیل ایستا: route/سرویس/view + فرم‌ها + دسترس‌پذیری
npm --prefix tests run routes         # هم‌خوانی سند مسیرها (docs/api/routes.md) با کد
npm --prefix tests run security       # آزمون امنیت (مالکیت، CSRF، XSS، محدودیت نرخ)
npm --prefix tests run acceptance     # آزمون پذیرش (سه سفر کامل کاربر/پرداخت/مدیر)
npm --prefix tests run browser        # آزمون مرورگر و اندازه‌های صفحه (Chromium محلی)
npm --prefix tests run install:clean  # آزمون نصب تمیز از خودِ بستهٔ ZIP
npm --prefix tests run all            # کل زنجیره به‌ترتیب (بدون هم‌زمانی)
npm --prefix tests run lint          # نحوی PHP 7.4 برای همهٔ فایل‌ها
npm --prefix tests run schema        # اجرای schema.sql روی MariaDB واقعی
npm --prefix tests run migration     # گام‌های مهاجرت ۰۰۲ و بی‌خطر‌بودن اجرای دوباره
npm --prefix tests run migration:runner   # خودِ runner مهاجرت روی «نصب قدیمی»
npm --prefix tests run integration   # رفتار سرویس‌ها (۱۵۶ بررسی)
npm --prefix tests run http          # صفحه‌ها، پیوندها و مسیرهای PWA در سطح HTTP (۱۳۶ بررسی)
npm --prefix tests run datepicker    # تبدیل تاریخ شمسی در اسکریپت تقویم
npm --prefix tests run package       # ساخت بستهٔ ZIP قابل‌تحویل از پوشهٔ پروژه
npm --prefix tests run zip           # بازرس بسته: بدون راز، قابل‌نصب (۳۰ بررسی)
```

`npm run a11y` همان `analyze` است (تحلیل ایستا شامل rules دسترس‌پذیری و فرم‌هاست).

آخرین اجرای این مجموعه در محیط توسعه: نحوی ۹۹ فایل معتبر، schema و مهاجرت ۰۰۲ درست، ۱۵۶ بررسی یکپارچه و
۱۳۶ بررسی HTTP موفق، تحلیل ایستا بدون خطا، و بازرسی بستهٔ ZIP (۳۰ بررسی) روی همان خروجی. آزمون‌ها Apache/`.htaccess`، HTTPS و مرورگرهای واقعی را
نمی‌آزمایند و جایگزین اجرای واقعی روی XAMPP مقصد نیستند.

## CSV کارکنان

فقط `national_id,municipality` به همین نام انگلیسی در سطر اول پذیرفته می‌شود؛ فایل حداکثر ۲ MiB و ۲۰٬۰۰۰ ردیف دارد. کد ملی خام در پایگاه داده نگهداری نمی‌شود؛ برای مقایسه HMAC با کلید اختصاصی ذخیره می‌شود. فقط مدیر سامانه می‌تواند فهرست را وارد کند. حذف/غیرفعال‌کردن فهرست، حساب‌های قبلاً تاییدشده را خودکار معلق نمی‌کند؛ این رفتار در نسخه فعلی نیازمند سیاست و عملیات مدیریتی است.

## اسناد

- [`PLAN-FA.md`](PLAN-FA.md) — برنامهٔ بهبود جاری (چهار کاربرگ)
- [`ROADMAP-FA.md`](ROADMAP-FA.md) — نقشهٔ راه ۹۰ قابلیت و وضعیت هر مورد
- **مسیرها و API:** [`docs/api/routes.md`](docs/api/routes.md) (تولیدشده از کد) · [`docs/api/auth.md`](docs/api/auth.md) (نشست، CSRF، مجوز)
- **معماری:** [`docs/architecture/overview.md`](docs/architecture/overview.md) · [`docs/architecture/data-model.md`](docs/architecture/data-model.md)
- **استقرار:** [`docs/deployment/xampp.md`](docs/deployment/xampp.md) (نصب تمیز + چک‌لیست دستی) · [`docs/deployment/linux.md`](docs/deployment/linux.md) · [`docs/deployment/backup.md`](docs/deployment/backup.md)
- **پرداخت:** [`docs/payment/sandbox.md`](docs/payment/sandbox.md) · [`docs/payment/money.md`](docs/payment/money.md)
- **امنیت:** [`docs/security/release-checklist.md`](docs/security/release-checklist.md)
- **حقوقی:** [`docs/legal/terms-fa.md`](docs/legal/terms-fa.md) · [`docs/legal/privacy-fa.md`](docs/legal/privacy-fa.md) (صفحه‌های کاربری: `/terms` و `/privacy`)

## انتشار امن و محدوده

این راهنما برای نصب محلی/آزمایشی است، نه ادعای آمادگی تولید. چک‌لیست امنیت پیش از انتشار در `docs/security/release-checklist.md` است و باید بند به بند روی محیط مقصد پر شود. برای محیط سازمانی TLS اجباری، backup رمزگذاری‌شده و آزموده‌شده، محدودیت دسترسی به export، retention و پاکسازی log، مانیتورینگ، آزمون بازیابی، بازبینی امنیتی و تست مرورگرهای واقعی لازم است. اطلاعات دیتابیس، `APP_KEY`، HMAC و کلید امضای بلیت secrets هستند. خروجی CSV شرکت‌کنندگان شامل نام و موبایل است؛ آن را فقط برای هدف اجرایی مجاز نگهدار و پس از استفاده حذف کن.

بلیت از QR حاوی توکن تصادفی امضاشده استفاده می‌کند و در QR هیچ کد ملی یا شماره موبایلی نیست. Print to PDF از پنجره چاپ مرورگر استفاده می‌کند، نه PDF server-side. check-in ورود را یک‌بار ثبت می‌کند و صف آفلاین پس از اتصال، فقط با پاسخ موفق سرور از حافظهٔ مرورگر پاک می‌شود. احراز هویت دوم با اپلیکیشن TOTP و بازنشانی رمز با توکن یک‌بارمصرف پیاده شده است؛ پیامک (SMS) و تأیید شمارهٔ موبایل از طریق اپراتور در این نسخه وجود ندارد. پرداخت و استرداد فقط در درگاه آزمایشی است و `SandboxGateway::REAL_PAYMENT_ENABLED` روی `false` هیچ درگاه واقعی را فراخوانی نمی‌کند.
