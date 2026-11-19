# احراز هویت، مجوز و محافظت‌های درخواست

مرجع کد: `backend/bootstrap.php`، `backend/src/Auth/AuthService.php`، `backend/src/Security/*`، `backend/src/Security/Permissions.php`. فهرست مسیرها: [`routes.md`](routes.md).

## نشست (Session)

- کوکی نشست: فقط با کوکی، `httponly`، `SameSite=Lax`، `secure` فقط وقتی HTTPS باشد؛ نام کوکی از `SESSION_NAME` (پیش‌فرض `erooydad_session`).
- `session.use_strict_mode=1` — نشستِ کوکیِ ناشناخته پذیرفته نمی‌شود.
- **بیکاری ۳۰ دقیقه** (`_last_activity` بیش از 1800 ثانیه) یا **عمر مطلق ۱۲ ساعت** (`_created_at` بیش از 43200 ثانیه) ← نشست خالی و `session_regenerate_id(true)`.
- ورود/ثبت‌نام موفق: `session_regenerate_id(true)` برای جلوگیری از Session Fixation.
- `session_version` روی ردیف کاربر: با تغییر رمز یا خروج از همهٔ دستگاه‌ها بالا می‌رود؛ نشست‌های قدیمی هنگام بررسی نامعتبر می‌شوند.

## CSRF

- هر فرم POST فیلد مخفی با `csrfField()` دارد (توکن داخل نشست، `Csrf::token()`).
- `verifyCsrf()` روی **همهٔ** درخواست‌های POST پیش از رسیدن به جدول مسیرها اجرا می‌شود؛ نامعتبر ← **419** با پیام «صفه را تازه‌سازی کنید».
- GET هرگز وضعیت را عوض نمی‌کند؛ همهٔ عملیات حساس POST هستند.

## ورود و ثبت‌نام

- ورود با `phone` + `password` (+ کد `two_factor_code` در صورت فعال بودن ۲FA).
- رمز: اول `passwordMaterial()` = `sha256('erooydad-password-v1:' . رمز)`، بعد `password_hash()` (توضیح: [`../architecture/overview.md`](../architecture/overview.md)).
- **محدودیت نرخ** (`RateLimiter`): ورود با جفت (تلفن، IP) و ثبت‌نام با جفت (تلفن، IP) شمارش می‌شوند؛ آستانه‌ها در `login_attempts`/`registration_attempts` ثبت و در `/admin/rate-limits` دیده می‌شوند.
- کد موبایل (OTP) و بازیابی رمز با `PasswordResetService` — کد یک‌بارمصرف.

## ورود دومرحله‌ای (TOTP)

- `TwoFactorService`: `beginSetup()` کلید + QR، `enable(code)` با تأیید کد، `disable(code)`.
- هنگام فعال بودن، فرم ورود `two_factor_code` می‌خواهد؛ کدهای بازیابی یک‌بارمصرف‌اند.

## نقش و مجوز

- چهار نقش پایه: `attendee` (بدون مجوز ادمین)، `event_admin`، `support`، `system_admin`.
- ۲۲ مجوز در `Permissions::CATALOG` (مثلاً `events.publish`، `payments.refund`، `users.manage`).
- پیش‌فرض هر نقش در `Permissions::ROLE_DEFAULTS`؛ ماتریس در دیتابیس (`role_permissions`) و در صفحهٔ `/admin/roles` قابل ویرایش است.
- در کد: `requireAuth()` (ورود الزامی)، `requireRole('a','b')` (حداقل یکی)، `Permissions::allows($user, 'permission')` برای مجوزهای ریز.
- صفحه‌های admin همه با `requireRole` شروع می‌شوند؛ کاربر بدون نقش ← 403.

## مالکیت (در برابر IDOR)

هر مسیری که شناسهٔ عددی می‌گیرد، مالکیت را داخل سرویس یا همان مسیر بررسی می‌کند:

- `GET /payments/{id}/sandbox` و `/payments/{id}/receipt` ← `user_id` باید مالک سند باشد وگرنه 404 (نه 403، تا وجود شناسه فاش نشود).
- `GET /tickets/{id}/print` ← `TicketService::forUser(ticket, user)`.
- `GET /feedback/{id}` ← ثبت‌نام باید متعلق به کاربر باشد.
- لغو ثبت‌نام ← `RegistrationService::cancelByUser(id, user)` داخل سرویس مالکیت را می‌سنجد.

آزمون این رفتار در `tests/Security/` (کاربر الف در برابر کاربر ب) پوشش داده می‌شود.

## هدرهای امنیتی (روی همهٔ پاسخ‌ها)

`X-Request-ID` · `Cache-Control: no-store, private` · `X-Content-Type-Options: nosniff` · `X-Frame-Options: DENY` · `Referrer-Policy: strict-origin-when-cross-origin` · `Permissions-Policy: camera=(), microphone=(), geolocation=()` · `CSP` با `default-src 'self'` و `frame-ancestors 'none'`.

> استثنای CSP برای QR اسکنر: دوربین مرورگر از همان مبدأ لود می‌شود؛ اسکریپت بیرونی مجاز نیست.

## رازهای الزامی

bootstrap بدون `APP_KEY`، `EMPLOYEE_HMAC_KEY` یا `TICKET_SIGNING_KEY` (هر کدام ≥ ۳۲ نویسه و بدون پیشوند `CHANGE_ME`) پاسخ **503** می‌دهد و بالا نمی‌آید. تولید: `deployment/local-xampp/generate-secrets.php`.

## حسابرسی عملیات حساس

`auditLog(actor, action, entity, id)` برای: تغییر رمز، تغییر نقش/تعلیق کاربر، ابطال نشست، بازصدور بلیت، استرداد، پشتیبان‌گیری، ورود اطلاعات. خروجی در `/admin/audit` با فیلتر و جست‌وجو.
