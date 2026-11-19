# معماری سامانهٔ ای‌رویداد

سند مروری بر ساختار کد و مسیر طی‌شده توسط هر درخواست. برای فهرست مسیرها به [`../api/routes.md`](../api/routes.md) و برای جزئیات داده به [`data-model.md`](data-model.md) مراجعه کنید.

## چارچوب کلی

- **بدون فریم‌ورک و بدون Composer:** PHP 7.4 خام با `declare(strict_types=1)`؛ بارگذاری فایل‌ها با `require` و فضای نام `Eroydad\…` در `backend/src/`.
- **تک‌کنترل‌کننده:** همهٔ درخواست‌ها به `frontend/public/index.php` می‌رسند (در XAMPP از طریق `.htaccess`)؛ روتر، محدودیت حجم، CSRF و رندر خطا همین‌جا انجام می‌شود.
- **لایه‌بندی ساده:** `routes` (اعلان مسیر + کنترل جریان) ← `backend/src/*` (سرویس‌ها با منطق کسب‌وکار و PDO) ← `frontend/views` (HTML با `h()` در برابر XSS).
- **دیتابیس:** MySQL/MariaDB با PDO از طریق `Eroydad\Database\Connection`؛ بدون ORM.

## نقشهٔ پوشه‌ها

```
project/
├── frontend/
│   ├── public/index.php        # تک‌کنترل‌کننده (فرانت‌کنترل)
│   ├── public/assets/          # CSS/JS/فونت/آیکون (محلی، بدون CDN)
│   └── views/                  # قالب‌های PHP (layout.php قالب پدر)
├── backend/
│   ├── bootstrap.php           # .env، پیکربندی، نشست، هدرهای امنیتی، توابع عمومی
│   ├── routes/web.php          # جدول مسیرهای عمومی (route())
│   ├── routes/admin.php        # جدول مسیرهای مدیریتی (route())
│   └── src/                    # سرویس‌ها به تفکیک دامنه (Auth, Events, Payments, …)
├── database/
│   ├── schema.sql              # پایه (نصب تازه)
│   └── migrations/             # مهاجرت افزایشی (نصب موجود)
├── deployment/local-xampp/     # اسکریپت‌های نصب محلی (migrate, create-admin, …)
├── tests/                      # بستهٔ آزمون (PHP/MariaDB روی WASM + بازرس بسته)
├── docs/                       # اسناد (api, architecture, deployment, payment, security, legal)
└── PLAN-FA.md                  # برنامهٔ بهبود جاری
```

## مسیر یک درخواست (چرخهٔ حیات)

1. **`bootstrap.php`** — خواندن `.env` (بدون بازنویسی متغیرهای محیطی موجود)، ساخت آرایهٔ پیکربندی (`appConfig()`)، و الزامِ سه راز حداقل ۳۲ نویسه‌ای (`APP_KEY`، `EMPLOYEE_HMAC_KEY`، `TICKET_SIGNING_KEY`) وگرنه پاسخ **503**.
2. **نشست** — `session.use_strict_mode`، کوکی فقط-با-کوکی، `httponly`، `SameSite=Lax`، `secure` فقط روی HTTPS؛ بیکاری بیش از **۳۰ دقیقه** یا عمر مطلق بیش از **۱۲ ساعت** ← نشست باطل و `session_regenerate_id`.
3. **هدرهای امنیتی** — `X-Request-ID` (برای صفحهٔ خطا و لاگ)، `Cache-Control: no-store`، `X-Content-Type-Options`، `X-Frame-Options: DENY`، `Referrer-Policy`، `Permissions-Policy` و `Content-Security-Policy` با `default-src 'self'`.
4. **`frontend/public/index.php`** — نرمال‌سازی `$path`، حذف `appBasePath()` (نصب در زیرپوشه؛ خارج از آن ← 404)، ساخت سرویس‌های پرکاربرد (`$events`, `$auth`, `$tickets`, `$support`).
5. **POSTها** — اول سقف `Content_LENGTH` (۶۴KB عمومی؛ ۱۶KB برای ورود/ثبت‌نام/چک‌این؛ ۶MB برای دو مسیر ورود اطلاعات) و بعد `verifyCsrf()`؛ توکن نامعتبر ← **419**.
6. **جدول مسیرها** — `require backend/src/Http/routes.php` سپس `routes/web.php` و `routes/admin.php`؛ هر مسیر با `route(method, pattern, …)` اعلان می‌شود، اولین تطبیق برنده است (فهرست: [`../api/routes.md`](../api/routes.md)).
7. **سرویس** — منطق کسب‌وکار در `backend/src/*`؛ خطاهای قابل‌پیش‌بینی با `DomainException` اعلام می‌شوند و مسیرها آن‌ها را به پیام فلش (`setFlash`) و `redirect` 303 تبدیل می‌کنند.
8. **خروجی** — `render(view, data)` با قالب `layout.php`، یا هدر مستقیم (ICS, XLSX, poster) یا `redirect()` با کد **303**.
9. **404 / 500** — نرسیدن به هیچ مسیر ← صفحهٔ ۴۰۴؛ هر `Throwable` ثبت‌نشده ← 500 با `request_id` در صفحه و لاگ.

## پیکربندی

- منبع حقیقت: `.env` (نمونه: `.env.example`)؛ `bootstrap.php` کلیدهای `APP_*`، `DB_*`، `SESSION_NAME` را می‌خواند.
- رمزهای عبور: `passwordMaterial()` = `sha256('erooydad-password-v1:' . رمز)` سپس `password_hash()` — یعنی رمز خام هرگز ذخیره نمی‌شود و پیشوند نسخه‌بندی‌شده اجازهٔ تغییر الگوریتم در آینده را می‌دهد.
- اجرای آزمون‌ها: `npm --prefix tests install` یک‌بار، سپس فرمان‌های [`../tests/README.md`](../tests/README.md)؛ PHP 7.4 و MariaDB 11.8 واقعی روی WebAssembly.

## تصمیم‌های آگاهانه

- **بدون CDN:** همهٔ دارایی‌ها محلی‌اند تا نصب آفلاین و ZIP قابل‌نصب کار کند (بند ۸۱ و ۹۰ نقشه‌راه).
- **پرداخت فقط sandbox:** `SandboxGateway::REAL_PAYMENT_ENABLED = false` و قفل صریح متغیرهای محیطی درگاه واقعی (بند ۴۰).
- **بدون جدول‌بندی کامل AJAX:** صفحات سمت سرور رندر می‌شوند؛ فقط موارد زنده (نمای شمارش، همگام‌سازی صف چک‌این) JSON دارند.
