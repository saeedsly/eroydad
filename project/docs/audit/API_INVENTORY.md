# API_INVENTORY.md — موجودی رابط‌های HTTP (مرحلهٔ ۱)

> منبع: `routes/web.php` (**۴۴**) + `routes/admin.php` (**۵۳**) = **۹۷ مسیر**؛
> `docs/api/routes.md` (فهرست تولیدی، ۹۰ خط) هم‌سان‌سازی شده. فقط **GET/POST** — بدون JSON API عمومی.

## ۱. قراردادهای کلی (واقعی)

| موضوع | قرارداد |
|---|---|
| متدها | GET (نمایش/دانلود) + POST (تغییر وضعیت؛ همهٔ تغییرات POST هستند) |
| مسیرها | `/`, `/admin/...` — پیشوند `admin` با پیشوند واقعی `prefixAdminPath()` |
| اعتبار | نشست کوکی (SameSite=Lax) + توکن CSRF فرم + بررسی Origin هم‌مبدأ (خطای 419) |
| نقش‌ها | مسیرهای admin با `requireRole('event_admin'|'support'|'system_admin'|...)` |
| خروجی | HTML از قالب‌ها؛ دانلودها با هدر Content-Disposition؛ خطاها به قالب خطای داخلی |
| شناسهٔ درخواست | هدر `X-Request-ID` در همهٔ پاسخ‌ها (ردیابی با `audit_logs.request_id`) |
| محدودیت نرخ | ورود/ثبت‌نام: ۸ شکست روی شماره (ساعتی) + سطل IP |

## ۲. مسیرهای عمومی/فراگیر (web.php — ۴۴)

| گروه | مسیرها (نمونه) |
|---|---|
| صفحات اصلی | `/` ، `/events` ، `/events/{slug}` ، `/calendar` |
| حساب | `/account`(ویرایش) `/account/registrations`، `/account/tickets`، `/account/security`، `/account/sessions`، `/account/notifications` |
| احراز | `/login`، `/register`، `/logout`، `/password/reset` |
| ثبت‌نام رویداد | `POST /events/{slug}/register`، `POST /events/{slug}/quick-register` |
| پرداخت | `/payments/{reg}/review`، `POST /payments/{reg}/pay`، `/payments/{reg}/status`، `POST .../refund`، `/payments/{reg}/receipt` |
| بلیت | `/tickets/{code}`، `/checkin/{code}`، `POST /checkin/scan`، `POST /checkin/sync` |
| گواهی | `GET /certificate` (استعلام عمومی با code) |
| یادآوری/اعلان | `GET /notifications` (یادآوری‌های تنبل را هم می‌سازد) |
| پشتیبانی | `/support`، `/support/ticket/{n}`، POSTهای ایجاد/پاسخ/نظرسنجی |
| بازخورد | `POST /feedback` |
| فایل/ورودی | `GET /poster` (تصویر)، `GET /attachments/{id}` |
| متفرقه | `/manifest.webmanifest`، `/sw.js` |

## ۳. مسیرهای مدیریتی (admin.php — ۵۳)

| گروه | نمونه مسیرها |
|---|---|
| داشبورد/آمار | `/admin`، `/admin/statistics` |
| رویدادها | `/admin/events`(فهرست/پوستر/حذف/تکثیر)، `/admin/events/new|{id}/edit`، `/admin/events/{id}/sessions|tags|attachments|preview|attendees` |
| ثبت‌نام/بلیت | `/admin/events/{id}/registrations`(لیست/تأیید/رد/یادداشت/وارد-کردن)، `/admin/events/{id}/tickets/issue|revoke|reissue` |
| حضور | `/admin/events/{id}/checkin` (مدیریت ورود دستی)، `/admin/scan` (اسکنر) |
| تیم/نقش | `/admin/team` (دعوت)، `/admin/roles` (ماتریس مجوز) |
| کارکنان | `/admin/employees` (ویرایش/حذف/فهرست) |
| تیکت‌ها | `/admin/support` (کیورد/فیلتر)، `/admin/support/ticket/{id}` (پاسخ داخلی/تغییر وضعیت/اولویت/SLA/برچسب) |
| گزارش/خروجی | `/admin/reports` (+export)، `/admin/excel`(خروجی/قالب/ورودی/فرم)، `/admin/registrations-export` |
| پشتیبانی/عملیات | `/admin/system/backup` (ایجاد/استعلام/فهرست)، `/admin/system/health`، `/admin/system/retention`، `/admin/system/sessions`، `/admin/system/roles` |
| تنظیمات/کاربران | `/admin/settings`، `/admin/users` (وضعیت/تأیید موبایل/رمز) |
| اعلان | `POST /admin/notifications/mark-read` |

## ۴. نقش‌محوری واقعی

- `web.php`: بدون نقش خاصی (فراگیر) + جلسات فراگیر
- `admin.php`: **event_admin** (رویداد/ثبت‌نام/بلیت/حضور)، **support** (تیکت/امور کاربر)، **system_admin** (تنظیمات/کاربران/نقش/عملیات/سازمان‌ها)، ترکیبی برای برخی (مثلاً `roles` فقط system_admin)

## ۵. شکاف‌های رابط (برای مرحلهٔ ۱۰ — یکپارچگی)

| شکاف | اهمیت |
|---|---|
| بدون API عمومی JSON / کلید API | P1 — برای یکپارچگی/وب‌هوک لازم است |
| بدون Webhook خروجی (رویدادهای پرداخت/ثبت‌نام) | P1 |
| بدون Webhook ورودی از دروازهٔ پرداخت (فقط callback وب‌ای) | P0 برای پرداخت واقعی |
| بدون نسخه‌بندی API (`/api/v1`) | P1 |
| `GET /payments/{reg}/status` بدون خروجی JSON صریح | P1 (پرداخت واقعی نیاز دارد) |
