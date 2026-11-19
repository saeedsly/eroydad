# فهرست مسیرهای سامانه (سند تولیدشده)

> این سند از خطوط `route(…)` داخل `backend/routes/*.php` تولید می‌شود؛
> به‌روزرسانی: `node tests/gen-routes-doc.mjs` و کنترل هم‌خوانی: `node tests/gen-routes-doc.mjs --check`.
> دستی ویرایش نکنید — ویرایش شما با اجرای بعدی بازنویسی می‌شود.

مجموع مسیرها: **96** (مسیرهای عمومی و پنل فراگیر: 43، مسیرهای پنل مدیریت (نقش‌محور): 53).

قرارداد: متد فقط `GET` یا `POST` است؛ الگوها با جداکنندهٔ `#…#` (regex PHP) نوشته می‌شوند و بقیه مقایسهٔ دقیق‌اند. ترتیبِ اعلان = ترتیبِ بررسی در زمان اجرا؛ اولین تطبیق برنده است.

## مسیرهای عمومی و پنل فراگیر (`backend/routes/web.php`)

| # | متد | مسیر / الگو | نوع | بخش |
|---|------|-------------|------|------|
| 1 | `GET` | `/` | دقیق | صفحهٔ اصلی و فهرست دوره‌ها |
| 2 | `GET` | `/events` | دقیق | صفحهٔ اصلی و فهرست دوره‌ها |
| 3 | `GET` | `#^/events/([a-z0-9-]+)$#` | الگو | صفحهٔ اصلی و فهرست دوره‌ها |
| 4 | `POST` | `#^/events/(\d+)/register$#` | الگو | صفحهٔ اصلی و فهرست دوره‌ها |
| 5 | `GET` | `#^/events/([a-z0-9-]+)/poster$#` | الگو | صفحهٔ اصلی و فهرست دوره‌ها |
| 6 | `GET` | `#^/preview/([a-f0-9]{16,64})$#` | الگو | صفحهٔ اصلی و فهرست دوره‌ها |
| 7 | `GET` | `/terms` | دقیق | صفحهٔ اصلی و فهرست دوره‌ها |
| 8 | `GET` | `/privacy` | دقیق | صفحهٔ اصلی و فهرست دوره‌ها |
| 9 | `GET` | `/register` | دقیق | احراز هویت |
| 10 | `POST` | `/register` | دقیق | احراز هویت |
| 11 | `GET` | `/login` | دقیق | احراز هویت |
| 12 | `POST` | `/login` | دقیق | احراز هویت |
| 13 | `POST` | `/logout` | دقیق | احراز هویت |
| 14 | `GET` | `/account` | دقیق | پنل فراگیر |
| 15 | `POST` | `#^/account/registrations/(\d+)/cancel$#` | الگو | پنل فراگیر |
| 16 | `GET` | `/account/calendar.ics` | دقیق | پنل فراگیر |
| 17 | `GET` | `/notifications` | دقیق | پنل فراگیر |
| 18 | `POST` | `/notifications/read` | دقیق | پنل فراگیر |
| 19 | `GET` | `/account/settings` | دقیق | پنل فراگیر |
| 20 | `POST` | `/account/settings` | دقیق | پنل فراگیر |
| 21 | `GET` | `/account/organization` | دقیق | پنل فراگیر |
| 22 | `POST` | `/account/organization` | دقیق | پنل فراگیر |
| 23 | `GET` | `/account/security` | دقیق | پنل فراگیر |
| 24 | `POST` | `/account/security/2fa/setup` | دقیق | پنل فراگیر |
| 25 | `POST` | `/account/security/2fa/enable` | دقیق | پنل فراگیر |
| 26 | `POST` | `/account/security/2fa/disable` | دقیق | پنل فراگیر |
| 27 | `POST` | `/account/security/password` | دقیق | پنل فراگیر |
| 28 | `POST` | `/account/security/logout-all` | دقیق | پنل فراگیر |
| 29 | `GET` | `/password/reset` | دقیق | پنل فراگیر |
| 30 | `POST` | `/password/reset` | دقیق | پنل فراگیر |
| 31 | `GET` | `/account/payments` | دقیق | پرداخت آزمایشی |
| 32 | `GET` | `#^/payments/(\d+)/sandbox$#` | الگو | پرداخت آزمایشی |
| 33 | `POST` | `#^/payments/(\d+)/sandbox$#` | الگو | پرداخت آزمایشی |
| 34 | `GET` | `#^/payments/(\d+)/receipt$#` | الگو | پرداخت آزمایشی |
| 35 | `GET` | `#^/certificate/([a-f0-9]{16,64})$#` | الگو | گواهی و بازخورد |
| 36 | `GET` | `#^/feedback/(\d+)$#` | الگو | گواهی و بازخورد |
| 37 | `POST` | `#^/feedback/(\d+)$#` | الگو | گواهی و بازخورد |
| 38 | `GET` | `/support` | دقیق | پشتیبانی |
| 39 | `POST` | `/support` | دقیق | پشتیبانی |
| 40 | `GET` | `#^/support/(\d+)$#` | الگو | پشتیبانی |
| 41 | `POST` | `#^/support/(\d+)/reply$#` | الگو | پشتیبانی |
| 42 | `POST` | `#^/support/(\d+)/rate$#` | الگو | پشتیبانی |
| 43 | `GET` | `#^/tickets/(\d+)/print$#` | الگو | بلیت |

## مسیرهای پنل مدیریت (نقش‌محور) (`backend/routes/admin.php`)

| # | متد | مسیر / الگو | نوع | بخش |
|---|------|-------------|------|------|
| 1 | `GET` | `/admin` | دقیق | نمای کلی |
| 2 | `GET` | `/admin/events` | دقیق | رویدادها |
| 3 | `GET` | `/admin/events/new` | دقیق | رویدادها |
| 4 | `POST` | `/admin/events/new` | دقیق | رویدادها |
| 5 | `GET` | `#^/admin/events/(\d+)/edit$#` | الگو | رویدادها |
| 6 | `POST` | `#^/admin/events/(\d+)/edit$#` | الگو | رویدادها |
| 7 | `GET` | `#^/admin/events/(\d+)$#` | الگو | رویدادها |
| 8 | `POST` | `#^/admin/events/(\d+)/publish$#` | الگو | رویدادها |
| 9 | `POST` | `#^/admin/events/(\d+)/cancel$#` | الگو | رویدادها |
| 10 | `POST` | `#^/admin/events/(\d+)/poster$#` | الگو | رویدادها |
| 11 | `POST` | `#^/admin/events/(\d+)/poster/delete$#` | الگو | رویدادها |
| 12 | `POST` | `#^/admin/events/(\d+)/sessions$#` | الگو | رویدادها |
| 13 | `POST` | `#^/admin/events/(\d+)/sessions/(\d+)/delete$#` | الگو | رویدادها |
| 14 | `POST` | `#^/admin/events/(\d+)/attachments$#` | الگو | رویدادها |
| 15 | `POST` | `#^/admin/events/(\d+)/attachments/(\d+)/delete$#` | الگو | رویدادها |
| 16 | `POST` | `#^/admin/events/(\d+)/attendees/(\d+)/cancel$#` | الگو | رویدادها |
| 17 | `POST` | `#^/admin/events/(\d+)/certificates$#` | الگو | رویدادها |
| 18 | `GET` | `#^/admin/events/(\d+)/certificates/print$#` | الگو | رویدادها |
| 19 | `GET` | `#^/admin/events/(\d+)/live-count\.json$#` | الگو | رویدادها |
| 20 | `GET` | `#^/admin/events/(\d+)/attendees\.csv$#` | الگو | رویدادها |
| 21 | `GET` | `#^/admin/events/(\d+)/finance$#` | الگو | رویدادها |
| 22 | `GET` | `#^/admin/exports/([a-z]+)\.xlsx$#` | الگو | خروجی‌های Excel |
| 23 | `GET` | `/admin/import` | دقیق | ورود اطلاعات |
| 24 | `POST` | `/admin/import/preview` | دقیق | ورود اطلاعات |
| 25 | `POST` | `/admin/import/commit` | دقیق | ورود اطلاعات |
| 26 | `GET` | `/admin/reports` | دقیق | گزارش‌ها |
| 27 | `GET` | `/admin/reports/checkin` | دقیق | گزارش‌ها |
| 28 | `GET` | `/admin/rate-limits` | دقیق | گزارش‌ها |
| 29 | `GET` | `/admin/roles` | دقیق | نقش‌ها، مجوزها و کاربران |
| 30 | `POST` | `/admin/roles` | دقیق | نقش‌ها، مجوزها و کاربران |
| 31 | `POST` | `/admin/roles/reset` | دقیق | نقش‌ها، مجوزها و کاربران |
| 32 | `GET` | `/admin/users` | دقیق | نقش‌ها، مجوزها و کاربران |
| 33 | `POST` | `#^/admin/users/(\d+)/(suspend\|activate)$#` | الگو | نقش‌ها، مجوزها و کاربران |
| 34 | `POST` | `#^/admin/users/(\d+)/role$#` | الگو | نقش‌ها، مجوزها و کاربران |
| 35 | `GET` | `/admin/sessions` | دقیق | نقش‌ها، مجوزها و کاربران |
| 36 | `POST` | `#^/admin/sessions/(\d+)/revoke$#` | الگو | نقش‌ها، مجوزها و کاربران |
| 37 | `GET` | `/admin/password-resets` | دقیق | نقش‌ها، مجوزها و کاربران |
| 38 | `POST` | `/admin/password-resets` | دقیق | نقش‌ها، مجوزها و کاربران |
| 39 | `GET` | `/admin/audit` | دقیق | گزارش فعالیت |
| 40 | `GET` | `/admin/check-in` | دقیق | کنترل ورود |
| 41 | `POST` | `/admin/check-in` | دقیق | کنترل ورود |
| 42 | `POST` | `/admin/check-in/sync` | دقیق | کنترل ورود |
| 43 | `POST` | `#^/admin/tickets/(\d+)/reissue$#` | الگو | کنترل ورود |
| 44 | `GET` | `/admin/support` | دقیق | پشتیبانی |
| 45 | `GET` | `#^/admin/support/(\d+)$#` | الگو | پشتیبانی |
| 46 | `POST` | `#^/admin/support/(\d+)/reply$#` | الگو | پشتیبانی |
| 47 | `POST` | `#^/admin/support/(\d+)/(priority\|assign\|status\|label\|canned)$#` | الگو | پشتیبانی |
| 48 | `GET` | `/admin/employees` | دقیق | کارکنان |
| 49 | `POST` | `/admin/employees/import` | دقیق | کارکنان |
| 50 | `GET` | `/admin/system` | دقیق | سامانه: سلامت، پشتیبان، مهاجرت، نگهداری داده |
| 51 | `POST` | `/admin/system/backup` | دقیق | سامانه: سلامت، پشتیبان، مهاجرت، نگهداری داده |
| 52 | `POST` | `#^/admin/system/backup/(\d+)/verify$#` | الگو | سامانه: سلامت، پشتیبان، مهاجرت، نگهداری داده |
| 53 | `POST` | `/admin/system/retention` | دقیق | سامانه: سلامت، پشتیبان، مهاجرت، نگهداری داده |
