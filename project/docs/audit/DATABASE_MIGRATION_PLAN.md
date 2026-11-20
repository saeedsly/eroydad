# DATABASE_MIGRATION_PLAN.md — برنامهٔ مهاجرت دیتابیس (مرحلهٔ ۵)

> قواعد طلایی: **افزایشی**، **برگشت‌پذیر**، **بدون حذف/تغییر مخرب**، **پشتیبان اجباری قبل از اجرا**،
> **تأیید صریح کارفرما** برای هر دستهٔ اجرا (stage_13).

## ۱. وضعیت فعلی

- `MigrationRunner` + جدول `schema_migrations` + نسخه‌ها: `001` (baseline ضمنی schema.sql) و `002_feature_set`
- فایل‌های مهاجرت: `.sql` و `.php` (هر دو پشتیبانی)؛ افزایشی با `IF NOT EXISTS`
- هم‌پوشانی D1 (schema.sql با 002) → در طرح هدف یکسان می‌شود

## ۲. نسخه‌های پیشنهادی (دسته‌بندی + پیش‌نیاز)

| نسخه | محتوا | پیش‌نیاز تأیید |
|---|---|---|
| `003_events_extensions` | `events`: `is_private`, `publish_at`, `close_at`, `template_of`, `workflow_state` + ایندکس | تأیید فاز ۲ |
| `004_forms` | `event_forms`, `form_fields`, `registration_answers` | تأیید فاز ۲ |
| `005_ticket_tiers_discounts` | `ticket_tiers`, `discount_codes`, `discount_uses` + ستون‌های tickets/registrations | تأیید فاز ۲ |
| `006_groups_invites` | `groups`, `group_items`, `event_invites` + ستون registrations | تأیید فاز ۲ |
| `007_workflows` | `workflow_instances`, `workflow_steps` | تأیید فاز ۳ |
| `008_org_hierarchy` | `organizations`, `departments`, `organization_members`, `projects` + مهاجرت داده از organization_profiles | تأیید فاز ۴ |
| `009_comms` | `message_templates`, `message_log`, `webhooks`, `webhook_deliveries` | تأیید فاز ۶ |
| `010_analytics_api` | `event_stats_daily`, `api_keys`, `api_requests`, `jobs` | تأیید فاز ۷–۸ |

هر نسخه یک `down()` (حذف فقط جدول/ستون **تازه‌ایجادشده**) دارد.

## ۳. روش اجرای امن (SOP)

```
۱) پشتیبان کامل: BackupService::create()  +  mysqldump دستی
۲) اجرای تست: npm run migration-test && migrate-run-test   (باید سبز)
۳) اجرای مهاجرت روی محیط هدف: php database/migrations/migrate.php 00X_...
۴) راستی‌آزمایی: schema-test + smoke اولیه (ورود/رویداد/ثبت‌نام)
۵) در صورت خطا: down() + بازگردانی فایل پشتیبان + گزارش
۶) ثبت در audit_logs و گزارش به کارفرما
```

## ۴. مهاجرت داده (برای 008)

1. خواندن `organization_profiles` → ساخت `organizations` (یک سازمان برای هر پروفایل)
2. نگاشت `user_id → organization_members(role=owner)`
3. ستون‌های قدیمی حفظ می‌شوند (read-only) تا یک چرخهٔ بازبینی؛ سپس در نسخهٔ بعدی فقط علامت‌گذاری می‌شوند (بدون حذف)

## ۵. معیار پذیرش هر مهاجرت

- [ ] `migration-test` و `schema-test` سبز
- [ ] `down()` روی کپی محیط تست برگشت خورده و DB سالم است
- [ ] پشتیبان قبل از اجرا ذخیره و هش آن ثبت شده
- [ ] هیچ رکورد/ستون موجودی حذف نشده (grep بازرسی)
- [ ] گزارش فارسی + تأیید کارفرما ثبت شده
