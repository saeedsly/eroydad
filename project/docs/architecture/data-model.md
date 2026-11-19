# مدل داده

مرجع کامل: [`database/schema.sql`](../../database/schema.sql) (نصب تازه) و [`database/migrations/`](../../database/migrations) (به‌روزرسانی نصب موجود). این سند نقشهٔ روابط را نشان می‌دهد؛ جزئیات ستون‌ها را از خود SQL بخوانید.

## گروه ۱ — هویت و دسترسی

| جدول | نقش |
|------|-----|
| `users` | حساب‌ها؛ `phone` یکتا، `account_type` (`public`/`municipal`)، `status` (`active`/`suspended`)، `password_hash`، `session_version` (با تغییر رمز بالا می‌رود و نشست‌های قبلی را باطل می‌کند) |
| `roles` / `user_roles` | نقش‌های `attendee`, `event_admin`, `support`, `system_admin` و نگاشت N:M کاربر–نقش |
| `permissions` / `role_permissions` | ۲۳ مجوز از `Permissions::CATALOG` و ماتریس قابل‌ویرایش نقش–مجوز (صفحهٔ `/admin/roles`) |
| `user_sessions` | نشست‌های فعال هر کاربر (دستگاه، زمان)؛ خروج از همه و ابطال تکی در پنل |
| `password_resets` | کدهای بازیابی رمز (یک‌بارمصرف) |
| `two_factor` | کلید TOTP و کدهای بازیابی ورود دومرحله‌ای |
| `user_preferences` | تم و اندازهٔ قلم هر کاربر |
| `login_attempts` | شمارندهٔ محدودیت نرخ ورود (تلفن + IP) |

## گروه ۲ — رویداد

| جدول | نقش |
|------|-----|
| `events` | دوره/رویداد؛ `status` (`draft`/`published`/`cancelled`/`completed`)، `delivery_mode`، `price_toman`، `tax_percent`، `tax_payer` (`participant`/`organizer`)، `capacity`، پوستر و توکن پیش‌نمایش |
| `event_sessions` | برنامهٔ چندجلسه‌ای |
| `event_tags` / `event_tags` مرتبط | برچسب‌های چندگانه و پرکاربرد |
| `event_attachments` | پیوست‌های دوره |
| `employee_import_batches` / `employee_allowlist` | ورود امن فهرست کارکنان با HMAC و تشخیص تکراری |

## گروه ۳ — ثبت‌نام، بلیت، حضور

| جدول | نقش |
|------|-----|
| `registrations` | صندلی کاربر در رویداد؛ `status` (`confirmed`/`pending_payment`/`waitlisted`/`cancelled`)، `amount_total_toman`، `waitlist_position`؛ یکتا روی (event, user) |
| `registration_attempts` | محدودیت نرخ تلاش ثبت‌نام (برای جلوگیری از اسپم) |
| `tickets` | بلیت صادرشده؛ `status` (`issued`/`revoked`)، کد امضاشده برای QR |
| `checkins` | ورود حضوری؛ `source` (`online`/`offline_queue`)، یکتایی در برابر ثبت تکراری |
| `certificates` | گواهی پایان دوره با کد استعلام |
| `feedbacks` | بازخورد پس از دوره (امتیاز + نظر)، یکی برای هر ثبت‌نام |
| `organization_profiles` | اطلاعات سازمانی فراگیر (نام، کدملی، نشانی، تماس) |
| `notifications` | اعلان درون‌برنامه‌ای (اعلان بلیت، یادآوری، …) |

## گروه ۴ — پول (آزمایشی)

| جدول | نقش |
|------|-----|
| `payments` | سند پرداخت؛ `status` (`pending`/`paid`/`failed`/`cancelled`/`refunded`)، مبلغ پایه/مالیات/جمع، `reference_code` (`SBX-…`)، `scenario` |
| `payment_ledger` | دفتر ثبت مالی؛ `entry_type` (`charge`/`refund`/`adjustment`) — هر تغییر مالی یک ردیف، بدون حذف |

> **قاعدهٔ «آخرین پرداخت»:** یک ثبت‌نام می‌تواند چند ردیف `payments` داشته باشد (ناموفقِ بعد از موفق). برای «پرداختِ» یک ثبت‌نام همیشه زیرپرسش همبسته با `ORDER BY p.id DESC LIMIT 1` است، نه `LEFT JOIN` — نگاه کنید به `GET /events/{slug}` در `backend/routes/web.php`.

## گروه ۵ — پشتیبانی

| جدول | نقش |
|------|-----|
| `support_tickets` | تیکت؛ `status` (`open`/`in_progress`/`answered`/`closed`)، `priority`، اولویت/برچسب/تخصیص |
| `support_messages` | گفت‌وگوی تیکت (کاربر/پشتیبان) |
| `support_labels` / `support_ticket_labels` | برچسب‌های دلخواه |
| `canned_responses` | پاسخ‌های آمادهٔ پشتیبان |

## گروه ۶ — حسابرسی و نگهداری سامانه

| جدول | نقش |
|------|-----|
| `audit_logs` | گزارش فعالیت؛ هر عمل حساس با `auditLog(actor, action, entity, id)` |
| `schema_migrations` / `migration_runs` | نسخهٔ اعمال‌شدهٔ اسکریپت + تاریخچهٔ اجرای هر مهاجرت |
| `backup_runs` | پشتیبان‌ها و نتیجهٔ «آزمون بازیابی» (`verify`) |
| `admin_confirmations` | تأیید دومرحله‌ای عملیات حساس مدیر |
| `data_retention_log` | پاک‌سازی زمان‌بندی‌شدهٔ دادهٔ گزارش |

## روابط کلیدی

```
users ─┬─< registrations >── events ─┬─< event_sessions
       │        │                    ├─< event_tags
       │        ├─< payments ── (ledger)
       │        ├─< tickets ──< checkins
       │        ├─< feedbacks
       │        └─< certificates
       ├─< user_sessions / notifications / organization_profiles
       ├─< support_tickets ──< support_messages
       └─< user_roles >── roles ──< role_permissions >── permissions
```

## قیدهایی که صحت را نگه می‌دارند

- یکتای `registrations (event_id, user_id)` — جلوگیری از دو صندلی برای یک نفر.
- یکتای `checkins (ticket_id)` — جلوگیری از ورود تکراری.
- `ON DELETE CASCADE` از کاربر/رویداد به رکوردهای وابسته؛ `roles` با `RESTRICT` تا نقش پایه حذف نشود.
- `schema_migrations` فقط نسخهٔ اعمال‌شده را نگه می‌دارد؛ `migration_runs` تاریخچهٔ اجرا (تکرارِ «همان نسخه» = گامِ ردشده، نه خطا) — رفتاری که `migration-test.mjs` می‌سنجد.
