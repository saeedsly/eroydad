# مبلغ، مالیات و درگاه آزمایشی

مرجع کد: `backend/src/Payments/Money.php`، `SandboxGateway.php`، `PaymentService.php` و مسیرهای `payments` در [`routes.md`](../api/routes.md).

> **این نسخه هیچ پول واقعی جابه‌جا نمی‌کند.** `SandboxGateway::REAL_PAYMENT_ENABLED = false` و هر متغیر محیطی که نشان درگاه واقعی بدهد (`PAYMENT_PROVIDER`، `ZARINPAL_MERCHANT_ID`، `IDPAY_API_KEY`، …) باعث توقف اجرا می‌شود (`assertRealGatewayLocked`).

## قواعد مالی (`Money::breakdown`)

همهٔ مبالغ **عدد صحیح تومان**‌اند (بدون اعشار، بدون ریال).

| ورودی | خروجی |
|-------|-------|
| `baseToman` | `base` — مبلغ پایه (منفی صفر می‌شود) |
| `taxPercent` | درصد مالیات، محدود به `[0, 100]`؛ `tax = round(base × percent / 100)` |
| `taxPayer` | `participant` ← مالیات روی جمع فراگیر؛ `organizer` ← مالیات روی برگزارکننده و جمع فراگیر = پایه |

خروجی: `base`، `tax`، `total` (آنچه فراگیر می‌پردازد)، `participant_total`، `organizer_tax`، به‌علاوهٔ برچسب فارسی با `format()` و `payerLabel()`.

نمونه: پایه ۲۵۰٬۰۰۰، مالیات ۱۰٪، پرداخت‌کنندهٔ مالیات «فراگیر» ← مالیات ۲۵٬۰۰۰، جمع ۲۷۵٬۰۰۰ تومان.

نرخ مالیات و پرداخت‌کننده **برای هر رویداد جداگانه** در ستون‌های `events.tax_percent` و `events.tax_payer` ثبت می‌شوند و در لحظهٔ ساخت سند پرداخت در `payments` کپی می‌شوند (تغییر بعدی رویداد، سند قبلی را عوض نمی‌کند).

## گردش یک پرداخت

```
ثبت‌نام (رویداد پولی)
   └─ RegistrationService → PaymentService::createPending()  → payments: pending
          └─ 303 به /payments/{id}/sandbox (صفحهٔ انتخاب سناریو)
                 └─ POST با scenario
                        └─ PaymentService::process(id, user, scenario)
                               ├─ SandboxGateway::charge() → status + reference (SBX-…)
                               ├─ ثبت payments (paid/failed/cancelled) + ردیف payment_ledger
                               └─ ثبت‌نام: confirmed (موفق) یا ماندن pending_payment (ناموفق)
```

- **موفق:** ثبت‌نام `confirmed`، بلیت صادر می‌شود (`issueTicket`)، اعلان درون‌برنامه‌ای، صفحهٔ رویداد «قطعی شده» با پیوند چاپ بلیت.
- **ناموفق/لغو/timeout:** ثبت‌نام `pending_payment` می‌ماند؛ صفحهٔ رویداد هشدار «هنوز پرداخت نشده» + دکمهٔ «ادامهٔ پرداخت آزمایشی» نشان می‌دهد. `failure` و `cancelled` پیام تکرارپرداخت هم دارند.
- **استرداد:** `PaymentService::refund()` ← وضعیت `refunded` + ردیف `refund` در دفتر (هیچ ردیفی حذف نمی‌شود).
- **رسید:** `GET /payments/{id}/receipt` با ریز پایه/مالیات/جمع و کد پیگیری.

## سناریوها (`SandboxGateway::SCENARIOS`)

| کلید | پیام رابط | نتیجهٔ تراکنش |
|------|-----------|----------------|
| `success` | پرداخت موفق (آزمایشی) | `paid` — قطعی‌شدن + بلیت |
| `failure` | پرداخت ناموفق (آزمایشی) | `failed` — شبیه‌سازی خطای بانک |
| `cancelled` | انصراف کاربر (آزمایشی) | `cancelled` |
| `timeout` | خطای موقت سرور (آزمایشی) | `failed` — بدون پاسخ درگاه |

هر تراکنش کد `SBX-` + ۱۲ هگز (تصادفی) به‌عنوان `reference_code` می‌گیرد.

## دفتر مالی (`payment_ledger`)

هر حرکت پول یک ردیف دائمی است:

- `charge` — پرداخت موفق
- `refund` — استرداد
- `adjustment` — اصلاح دستی (کاربر دارای مجوز `payments.refund`/`settings.manage`)

گزارش مالی (`/admin/events/{id}/finance` و خروجی Excel) از همین دفتر + `payments` ساخته می‌شود؛ صفرکردن جدول غیرممکن است (پاک‌سازی فقط با سیاست `RetentionService` و با ثبت در `data_retention_log`).

## صفحهٔ آزمایشی

`GET/POST /payments/{id}/sandbox` فقط به **مالک سند** نشان داده می‌شود (ورود + تطابق `user_id`، وگرنه 404). سقف حجم POST این مسیر مثل بقیهٔ فرم‌ها ۶۴KB و CSRF الزامی است.
