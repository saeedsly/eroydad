# DATABASE_DATA_DICTIONARY.md — واژه‌نامهٔ دادهٔ ۳۸ جدول موجود (مرحلهٔ ۵)

> ستون‌های کلیدی از رصد مستقیم SQL. ستون PII با ✋ علامت خورده.

## ۱. هویت و مجوز

| جدول | هدف | ستون‌های کلیدی |
|---|---|---|
| `users` ✋ | حساب کاربران | id, phone(U)✗, full_name, municipality, password_hash, account_type, phone_verified_at, employee_verified_at, status, session_version, two_factor_enabled, font_scale, theme |
| `roles` | ۴ نقش سیستم | id, code(U), title |
| `permissions` | ۲۲ کد مجوز | id, code(U), title, category |
| `user_roles` | انتساب نقش | user_id, role_id (U) |
| `role_permissions` | ماتریس مجوز | role_id, permission_id (U) |
| `security_sessions` | نشست‌های فعال | id, user_id, issued_at, expires_at, ip, user_agent, last_seen_at |
| `user_password_resets` | کلید بازیابی رمز | user_id(U), key, created_at |
| `user_password_reset_requests` | درخواست بازیابی | id, user_id, requested_at |
| `employee_allowlist` ✋ | موبایل کارکنان (HMAC) | id, phone_hmac(U), full_name, employee_code, allow_duplicate |

## ۲. رویداد

| جدول | هدف | ستون‌های کلیدی |
|---|---|---|
| `events` | پروندهٔ رویداد | id, slug(U), title, category, delivery_mode, venue, starts_at_utc, ends_at_utc, capacity, price_toman, tax_percent, tax_payer, poster_path/alt/mime, instructor_name/bio, status, preview_token, created_by, published_at |
| `event_sessions` | جلسات چندگانه | id, event_id, title, starts_at, ends_at, venue, is_online |
| `event_daily_statistics` | آمار روزانه | event_id, day, … (در 002) |
| `tags` / `event_tags` | برچسب‌گذاری | name(U) / (event_id, tag_id) |
| `event_attachments` | پیوست‌های رویداد | id, event_id, path, title |

## ۳. ثبت‌نام / بلیت / مالی

| جدول | هدف | ستون‌های کلیدی |
|---|---|---|
| `registrations` | رکورد ثبت‌نام | id, event_id, user_id, account_type, status, idempotency_key(U), amount_base/tax/total_toman, waitlist_position, organisation_completed, cancelled_* |
| `tickets` | بلیت QR | id, registration_id, public_code(U), status, issued_at, issued_by, revoked_at, reissued_from, reissue_count |
| `waitlist` | فهرست انتظار | id, event_id, user_id, position, status |
| `payments` | پرداخت | id, registration_id, event_id, user_id, provider, is_sandbox, status, *_toman, scenario, reference_code, paid_at, refunded_at — U(registration_id, provider) |
| `payment_ledger` | دفتر روزنامه | id, payment_id, event_id, entry_type, amount_toman, actor_id |
| `checkins` | حضور | id, ticket_id, checked_by, checked_at, source, client_ref(U), synced_at |
| `certificates` | گواهی | id, registration_id, event_id, user_id, code(U), issued_by |
| `feedbacks` | ارزیابی | id, event_id, user_id, scores, comment |

## ۴. پشتیبانی

| جدول | هدف | ستون‌های کلیدی |
|---|---|---|
| `support_tickets` | تیکت | id, user_id, event_id, subject, status, priority, assigned_to, first_response_at, sla_due_at, resolved_at, satisfaction_rating |
| `support_ticket_events` | تایم‌لاین | id, ticket_id, event_type, actor_id |
| `support_ticket_messages` | پیام مشتری | id, ticket_id, body |
| `support_internal_replies` | پاسخ داخلی | id, ticket_id, body |
| `support_labels` / `support_ticket_labels` | برچسب | name(U) / (ticket_id, label_id) |
| `support_ticket_responses` | پاسخ آماده | id, title, body |

## ۵. عملیات / امنیت / تنظیمات

| جدول | هدف | ستون‌های کلیدی |
|---|---|---|
| `settings` | تنظیمات سراسری | key(U), value |
| `notifications` | اعلان درون‌برنامه‌ای | id, user_id, kind, title, body, dedupe_key, read_at |
| `notification_preferences` | ترجیحات | user_id(U), channel, enabled |
| `organization_profiles` | پروفایل سازمان تک‌سطحی ✋ | user_id(U), organization_name, national_id, address, contact_email, contact_phone |
| `audit_logs` | ممیزی | id, actor_id, action, entity_type, entity_id, request_id, safe_details, created_at |
| `admin_confirmations` | تأیید عملیات خطرناک | id, kind, payload, created_at |
| `backup_records` | پشتیبان‌ها | id, file_name, status, created_by |
| `schema_migrations` | نسخهٔ مهاجرت | version(U), applied_at |
