# DATABASE_AUDIT.md — ممیزی دیتابیس واقعی (مرحلهٔ ۱)

> بازرسی `database/schema.sql` + `migrations/002_feature_set.sql`، ۲۰۲۶-۱۱-۲۰.
> **۳۸ جدول یکتا** (۳۷ در schema + افزودنی‌های 002؛ اجرای ترکیبی = ۳۸).

## ۱. نقش هر جدول (دسته‌ای)

| دسته | جدول‌ها | تعداد |
|---|---|---|
| هویت | users, user_roles, roles, permissions, role_permissions, security_sessions, user_password_resets, user_password_reset_requests, employee_allowlist | 9 |
| رویداد | events, event_sessions, event_daily_statistics, tags, event_tags, event_attachments | 6 |
| ثبت‌نام/بلیت | registrations, tickets, waitlist | 3 |
| مالی | payments, payment_ledger | 2 |
| حضور/گواهی | checkins, certificates | 2 |
| بازخورد | feedbacks | 1 |
| پشتیبانی | support_tickets, support_ticket_events, support_ticket_messages, support_labels, support_ticket_labels, support_ticket_responses, support_internal_replies | 7 |
| تنظیمات/اعلان | settings, notifications, notification_preferences | 3 |
| سازمانی | organization_profiles | 1 |
| امنیت/عملیات | audit_logs, admin_confirmations, backup_records | 3 |
| مهاجرت | schema_migrations | 1 |

## ۲. قراردادهای کلی (رعایت‌شده ✓)

- موتور **InnoDB**، کلیدهای اصلی `BIGINT` خودکار، `*_at` زمان UTC
- **پول = تومان صحیح** (`price_toman`, `amount_base/tax/total_toman`) — بدون اعشار، بدون FLOAT ✓
- وضعیت‌ها = enum متنی (`VARCHAR`) + ایندکس روی وضعیت‌های پرجست‌وجو
- FKهای `ON DELETE` آگاهانه: رویدادِ حذف‌شده → مالک (created_by) پاک می‌شود؛ پرداختِ کاربرِ حذف‌شده → مالک (`SET NULL` در payments)؛ بقیهٔ رکوردها `RESTRICT` ✓
- یکپارچگی پرداخت: `registrations.idempotency_key` UNIQUE + `UNIQUE(registration_id, provider)` در payments ✓
- جلوگیری از دوبلی: `tickets.public_code` UNIQUE، `certificates.code` UNIQUE، `checkins.client_ref` UNIQUE (تضمین اسکن تکراری)

## ۳. ناسازگاری‌ها و نکات حساس (بدون اصلاح در این مرحله)

| # | یافته | شدت | توضیح |
|---|---|---|---|
| D1 | هم‌پوشانی schema.sql با مهاجرت 002 | متوسط | ۲۲ جدولِ 002 هم‌گرد با schema.sql‌اند (`IF NOT EXISTS` دوباره) — ریسک دوپلی در نگهداری؛ migration-plan هدف یک منبع حقیقت می‌کند |
| D2 | جدول `waitlist` و ستون `waitlist_position` هر دو وجود دارند | کم | جایگاه فهرست انتظار دو جا نگهداری می‌شود — باید یکی منبع حقیقت شود |
| D3 | `event_daily_statistics` با احتساب دو منبع | کم | در schema نیست؛ در 002 هست؛ رفتار داشبورد باید در پیاده‌سازی بعدی تأیید شود |
| D4 | نبود ایندکس ترکیبی روی `(event_id, status)` در registrations | کم | پرجست‌وجوترین گزارش‌ها روی این ترکیباند؛ طرح هدف اضافه می‌کند |
| D5 | `users.session_version` وجود دارد اما `security_sessions` نیز نشست نگه می‌دارد | کم | دو مکانیزم خروج-همه؛ در معماری هدف یکی می‌شود |
| D6 | نبود جدول‌های تحلیلی/سازمانی/گردش‌کار | بالا | سازمان/دپارتمان/تخفیف/لاین‌بلیت/نظرسنجی/API-key غایب‌اند (مرحلهٔ ۲ و ۵) |
| D7 | جدول `tickets` وضعیت `revoked_at/issued_by` دارد اما انتقال مالکیت ندارد | متوسط | برای «انتقال بلیت» باید افزوده شود |
| D8 | نبود جدول لاگ تحویل پیام (ایمیل/پیامک) | بالا | فعلاً اعلان درون‌برنامه‌ای است؛ برای یکپارچگی پیام باید اضافه شود |

## ۴. ایندکس‌های موجود (نمونه‌های کلیدی)

- `registrations(event_id, status)`، `registrations(user_id, event_id)`، `registrations(idempotency_key)` UNIQUE
- `payments(registration_id, provider)` UNIQUE، `tickets(public_code)` UNIQUE، `checkins(ticket_id)`، `checkins(client_ref)` UNIQUE
- `audit_logs(actor_id, created_at)`، `audit_logs(entity_type, entity_id)`، `security_sessions(user_id, expires_at)`
- `support_tickets(user_id, status)`، `support_tickets(status, priority)`، `support_ticket_events(ticket_id, created_at)`
- `notifications(user_id, created_at)`، `notifications(user_id, dedupe_key)` (UNIQUE شرطی)

## ۵. سلامت و حفاظت

- **تست سلامت `schema-test`** در دروازه: جداول/ستون‌ها/ایندکس‌ها/FK با واقعیت موتور می‌خوانند — **۴۹۷ PASS**
- **مهاجرت‌گر**: جدول `schema_migrations` + ترتیب + افزایشی/برگشت‌پذیر (`migrate-run-test`, `migration-test`)
- **پشتیبان**: `BackupService` (ایجاد/فهرست/استعلام) + `backup_records` + راهنمای `docs/deployment/backup.md`
- **بدون حذف/تغییر مخرب** در هیچ‌یک از مهاجرت‌های فعلی ✓

## ۶. خلاصه برای تصمیم‌گیر

موجودیت‌های **هستهٔ رویداد، ثبت‌نام، پرداخت، حضور، گواهی، پشتیبانی، مجوز** سالم و کامل‌اند؛
شکاف اصلی در **لایهٔ سازمانی/بازاریابی/یکپارچگی** است (D6–D8) که در
[DATABASE_TARGET_ERD.md](DATABASE_TARGET_ERD.md) و [DATABASE_MIGRATION_PLAN.md](DATABASE_MIGRATION_PLAN.md) طراحی شده.
