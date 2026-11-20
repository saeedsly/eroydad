# اسناد ممیزی و معماری هدف — پروژهٔ ای‌رویداد (ROIDAD)

> **وضعیت اجرا: مرحلهٔ اول (ممیزی) — بدون هیچ تغییری در کد، پیکربندی یا دیتابیس.**
> طبق سیاست تأیید `stage_13`، پیاده‌سازی فقط پس از تأیید صریح کارفرما آغاز می‌شود.

این پوشه خروجی فازهای ۱، ۲، ۳ و ۵ (طراحی دیتابیس) از سند معماری نسخهٔ ۲.۰ است.
همهٔ اعداد و ادعاهای این اسناد از بازرسی واقعی مخزن در تاریخ ۲۰۲۶-۱۱-۲۰ به‌دست آمده‌اند.

## فهرست اسناد

| سند | فاز | محتوا |
|---|---|---|
| [CURRENT_ARCHITECTURE.md](CURRENT_ARCHITECTURE.md) | ۱ | معماری واقعیِ موجود: پشته، لایه‌ها، ماژول‌ها، جریان درخواست |
| [CURRENT_FEATURES.md](CURRENT_FEATURES.md) | ۱ | موجودی قابلیت‌ها (۹۰ ویژگی نقشهٔ راه: ۷۷ کامل، ۱۳ نیمه) |
| [DATABASE_AUDIT.md](DATABASE_AUDIT.md) | ۱ | ممیزی ۳۸ جدول، قیدها، ایندکس‌ها، ناسازگاری‌ها |
| [API_INVENTORY.md](API_INVENTORY.md) | ۱ | موجودی ۹۷ مسیر HTTP + قراردادها |
| [SECURITY_AUDIT.md](SECURITY_AUDIT.md) | ۱ | وضعیت امنیت واقعی + شکاف‌ها |
| [TECHNICAL_DEBT.md](TECHNICAL_DEBT.md) | ۱ | بدهی فنی، کد نیمه‌کاره، شکاف‌های تست |
| [PROJECT_DEPENDENCIES.md](PROJECT_DEPENDENCIES.md) | ۱ | وابستگی‌های اجرا و تست (بدون وابستگیِ خارجیِ اجرا) |
| [FEATURE_GAP_ANALYSIS.md](FEATURE_GAP_ANALYSIS.md) | ۲ | مقایسه با محصول هدف (مرجع: Evand/Eventbrite) |
| [TARGET_ARCHITECTURE.md](TARGET_ARCHITECTURE.md) | ۳ | معماری هدف: Monolith ماژولار با Clean Architecture (روی پشتهٔ واقعی PHP 7.4) |
| [DATABASE_CURRENT_ERD.md](DATABASE_CURRENT_ERD.md) | ۵ | نمودار ER موجود (Mermaid) |
| [DATABASE_TARGET_ERD.md](DATABASE_TARGET_ERD.md) | ۵ | نمودار ER هدف + نمودار جریان دادهٔ ثبت‌نام/پرداخت/حضور |
| [DATABASE_DATA_DICTIONARY.md](DATABASE_DATA_DICTIONARY.md) | ۵ | واژه‌نامهٔ دادهٔ ۳۸ جدول موجود |
| [DATABASE_MIGRATION_PLAN.md](DATABASE_MIGRATION_PLAN.md) | ۵ | برنامهٔ مهاجرت افزایشی و برگشت‌پذیر |
| [IMPLEMENTATION_ROADMAP.md](IMPLEMENTATION_ROADMAP.md) | ۱۳ | نقشهٔ راه اجرای ۱۱ فاز با دروازه‌های تأیید |

## اسناد موجود قبلی (بدون تغییر)

`docs/architecture/overview.md`، `docs/architecture/data-model.md`، `docs/api/routes.md` (تولیدشده)，
`docs/api/auth.md`، `docs/security/release-checklist.md`، `docs/payment/*`، `docs/deployment/*`،
`docs/legal/*`، `docs/architecture/skills-playbook.md`.
