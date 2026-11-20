# CURRENT_ARCHITECTURE.md — معماری واقعیِ موجود

> بازرسی مستقیم مخزن، ۲۰۲۶-۱۱-۲۰. هیچ فرضی بدون رصد کد نیامده است.

## ۱. پشتهٔ فناوری (واقعی، نه فرضی)

| لایه | فناوری واقعی | شواهد |
|---|---|---|
| زبان سرور | **PHP 7.4** (با `declare(strict_types=1)`) | `backend/**`؛ تست با parser واقعی ۷.۴ روی WASM |
| فریمورک | **بدون فریمورک** — Front Controller سفارشی | `frontend/public/index.php` + `backend/bootstrap.php` |
| پایگاه داده | **MySQL/MariaDB** از طریق PDO (InnoDB) | `backend/src/Database/Connection.php`؛ تست با MariaDB 11.8 واقعی |
| فرانت‌اند | **Vanilla HTML/CSS/JS** — هیچ فریمورک یا bundler | ۵۱ قالب PHP، ۲ فایل CSS (۱۴۹۰ خط)، ۱۲ فایل JS (۱۰ بومی + ۲ کتابخانهٔ QR) |
| PWA | Service Worker + Manifest بومی | `sw.js`، `manifest.webmanifest`، `pwa.js` |
| نصب | **ZIP تک‌فایلی برای XAMPP (PHP 7.4)** + مهاجرت‌گر | `build-package.mjs`، `deployment/local-xampp/` |
| زبان رابط | فارسی، RTL کامل، تقویم شمسی بومی | `jalali-date-picker.js` (۲۸۶ خط، دوطرفه) |
| وابستگی اجرای خارجی | **هیچ** — بدون Composer، بدون CDN، بدون سرویس بیرونی | `PROJECT_DEPENDENCIES.md` |

**نکتهٔ معماری:** سند معماری خواستار «محترم‌داشتن فریمورک جاوا» شده بود؛ بازرسی نشان داد
**هیچ کد جاواوجود ندارد** — پشته واقعی PHP است و طبق قاعدهٔ خودِ سند (`architectural_rule`)
کل طراحی هدف روی همین پشتهٔ واقعی بدون بازنویسیِ تکنولوژی انجام می‌شود.

## ۲. ساختار کد

```
project/
├── frontend/
│   ├── public/          ← نقطهٔ ورود وب + دارایی‌ها (css/js/fonts/icons/sw)
│   └── views/           ← ۵۱ قالب PHP (layout.php پوستهٔ سراسری)
├── backend/
│   ├── bootstrap.php    ← هسته: تنظیمات، session، هدرها، CSRF+Origin، توابع سراسری
│   ├── routes/web.php   ← ۴۴ مسیر عمومی/فراگیر (GET/POST)
│   ├── routes/admin.php ← ۵۳ مسیر نقش‌محور
│   └── src/             ← ۴۲ سرویس در ۱۷ ماژول (زیر)
├── database/
│   ├── schema.sql       ← ۳۷ جدول پایه
│   └── migrations/      ← 002_feature_set (.sql + .php) افزایشی
├── tests/               ← کاتالوک ۱۸ مرحله‌ای (497 PASS / 0 FAIL)
└── docs/                ← ۱۳ سند موجود + docs/audit/ (این مجموعه)
```

## ۳. ماژول‌های Backend (مرزهای واقعی)

| ماژول (Namespace) | وظیفه | تعداد کلاس |
|---|---|---|
| `Auth` | ورود/خروج/بازیابی رمز، نشست | 1 |
| `Security` | CSRF+Origin، مجوزها، RateLimiter، TOTP/2FA، SafeText، NationalId، Ics، PasswordReset | 9 |
| `Admin` | Administration، Audit، Backup، Health، Retention، Role، Session | 7 |
| `Events` | EventService (چرخهٔ رویداد) + EventContentService (جلسات/برچسب/پیوست) | 2 |
| `Registration` | RegistrationService (ثبت‌نام/فهرست انتظار) + TicketService (بلیت/HMAC) | 2 |
| `Payments` | PaymentService، SandboxGateway، Money | 3 |
| `Certificates` | صدور + کد یکتا + استعلام عمومی | 1 |
| `Notifications` | اعلان درون‌برنامه‌ای + یادآوری تنبل | 1 |
| `Support` | SupportService (فراگیر) + SupportDeskService (میز کاری، SLA، برچسب، پاسخ آماده) | 2 |
| `Feedback` | ارزیابی دوره/مدرس | 1 |
| `Reports` | ReportService + ExportService (XLSX) + ImportService | 3 |
| `Excel` | XlsxReader/Writer/Zip (بدون وابستگی خارجی) | 3 |
| `Employees` | ورود فهرست کارکنان با HMAC | 1 |
| `Media` | PosterService (اعتبارسنجی واقعی تصویر) | 1 |
| `Settings` | تنظیمات نمایش کاربر (تم/مقیاس قلم) | 1 |
| `Database` | Connection + MigrationRunner | 2 |
| `Http` | توضیحات مسیرها (فایل کمکی) | 1 |

## ۴. جریان یک درخواست (واقعی)

```
مرورگر → frontend/public/index.php
       → backend/bootstrap.php
          ۱) هدرهای امنیتی (CSP, XFO, nosniff, X-Request-ID, …)
          ۲) پیکربندی از .env (بدون لاگینِ رمزها)
          ۳) session: SameSite=Lax، Idle ۳۰ دقیقه، Absolute ۱۲ ساعت
          ۴) بارگذاری routes/web.php یا admin.php (ترتیب اعلان = اولین برد)
          ۵) محافظ CSRF: توکن فرم + بررسی Origin هم‌مبدأ (419)
          ۶) requireRole(...) برای مسیرهای مدیریتی
          ۷) render(view, data) → layout.php → خروجی با prefixInternalUrls
       → پاسخ HTML (فقط GET/POST؛ بدون JSON API عمومی)
```

## ۵. نقش‌ها و مجوزها (RBAC موجود)

- **۴ نقش**: `attendee` ، `event_admin` ، `support` ، `system_admin`
- **۲۲ کد مجوز** در `Permissions::CATALOG` (events.*, attendees.*, tickets.*, certificates.*, payments.*, reports.*, import.*, support.manage, users.manage, audit.view, system.health, settings.manage)
- جداول `roles/permissions/user_roles/role_permissions` + ماتریس قابل ویرایش در `/admin/roles`
- **نکتهٔ حیاتی:** اِعمالِ مسیرها با `requireRole()` (سطح نقش) است؛ کدهای مجوز
  ذخیره و قابل‌پرس‌وجو هستند (`RoleService::permissionsFor`) اما هنوز **اصلِ default-deny در سطح مجوز در مسیرها اجرا نشده** —
  بزرگ‌ترین شکاف RBAC (ثبت در SECURITY_AUDIT و TARGET_ARCHITECTURE).

## ۶. دیتابیس

- **۳۸ جدول یکتا** (schema.sql: ۳۷ + مهاجرت 002: ۲۲ با هم‌پوشانی)
- پول: **تومانِ صحیح** (`*_toman` بدون اعشار) + `Money.php` + مالیات (`tax_percent`, `tax_payer`)
- زمان‌ها: `*_at` UTC؛ وضعیت‌ها با enum متنی (draft/published/…)
- راستی‌آزمایی خودکار: `schema-test` + `migration-test` + `migrate-run-test` در دروازه

## ۷. آزمون و استقرار

- دروازهٔ `npm run all` = **۱۸ مرحله، ۴۹۷ PASS / ۰ FAIL** (لینت PHP 7.4 واقعی، schema، مهاجرت، واحد، یکپارچه، امنیت، پذیرش ۵ سفر، HTTP ۱۴۶، مرورگر Chromium، جریان تاریخ، نصب‌کننده E2E، ساخت ZIP، نصب تمیز، بازرسی ZIP)
- تحویل: ZIP ۵۸۹,۶۲۵ بایت، تأییدشده با هش GitHub؛ پیش‌نمایش زنده روی PHP WASM + MariaDB

## ۸. نقاط قوت و ضعف معماری

**قوت:** مرزهای ماژولی تمیز؛ بدون وابستگی خارجیِ اجرا؛ دروازهٔ آزمون واقعی‌گرا؛ امنیت لایه‌ای (CSRF دوگانه، هدرها، rate-limit، audit)؛ نصب تک‌مرحله‌ای.

**ضعف (مبنای TECHNICAL_DEBT):** نبود لایهٔ Application مستقل (منطق در سرویس‌ها + routes درهم)؛ اِعمال مجوز نقش‌محور نه مجوزمحور؛ نبود کار پس‌زمینه (یادآوری‌ها «تنبل» روی دیدن صفحه)؛ نبود API عمومی/JSON؛ نبود لاگ ساختاریافتهٔ فایل؛ جدول‌های تحلیلی و سازمانی غایب‌اند.
