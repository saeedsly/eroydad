# TARGET_ARCHITECTURE.md — معماری هدف (مرحلهٔ ۳)

> قاعدهٔ الزامی: **بدون جایگزینی تکنولوژی، بدون بازنویسی** — همان پشتهٔ واقعی
> **PHP 7.4 + PDO/MySQL + Vanilla JS/HTML/CSS + XAMPP** باقی می‌ماند؛
> چیزی که اضافه می‌شود **ساختار** (Monolith ماژولار + Clean Architecture) است، نه زبان/فریمورک جدید.

## ۱. اصل طراحی

```
┌────────────────────────────────────────────────────────────┐
│                     Monolith واحد (یک استقرار)              │
│  ┌──────────┐  ┌──────────────┐  ┌───────────────────────┐ │
│  │  HTTP     │→│ Application  │→│  Domain (Core)         │ │
│  │ (routes + │  │ (Use-Cases,  │  │  Entities + Rules     │ │
│  │  present)│  │  DTO, guards)│  │  (Enterprise Rules)   │ │
│  └──────────┘  └──────┬───────┘  └───────────▲───────────┘ │
│                       │                      │             │
│                 ┌─────▼──────────────────────┴──────┐      │
│                 │ Infrastructure (PDO, Mail, Gate…)  │      │
│                 │  پورت‌ها/آداپترها قابل تعویض       │      │
│                 └───────────────────────────────────┘      │
└────────────────────────────────────────────────────────────┘
```

- **Clean Architecture در مقیاس کوچک:** هر ماژول پوشه‌های `Domain/`, `Application/`, `Infrastructure/` دارد؛
  وابستگی فقط به داخل (Dependencies Inversion با interface سبک PHP 7.4).
- **بدون تغییر رفتار بیرونی:** URLها، قالب‌ها، دیتابیس، مجوزها حفظ می‌شوند؛ مهاجرت‌ها افزایشی.

## ۲. نمودار وابستگی ماژول‌ها (Module Dependency Diagram)

```mermaid
graph TD
    subgraph Core [هسته - بدون وابستگی به بیرون]
        Money[Money] ; Auth[Auth] ; RBAC[RBAC] ; EventBus[Event-Bus داخلی]
        ID[ID/Code generators] ; Clock[Clock]
    end
    subgraph Modules [ماژول‌های کسب‌وکار]
        Org[Org: سازمان/دپارتمان/اعضا]
        EVT[Events: رویداد/جلسه/قالب]
        REG[Registration: ثبت‌نام/گروه/فرم]
        TIC[Tickets: لاین/بلیت/QR]
        PAY[Payments: پرداخت/تخفیف/بازپرداخت/تسویه]
        CHK[Attendance: چک‌این/همگام‌سازی]
        CRT[Certificates: گواهی]
        WRK[Workflows: گردش تأیید]
        CM[Communications: پیام/قالب/لاگ]
        ANA[Analytics: آمار/گزارش]
        SUP[Support: تیکت]
        OPS[Ops: پشتیبان/سلامت/نشست]
    end
    subgraph Ports [پورت‌های یکپارچگی]
        GW[PaymentGateway] ; MSG[MessageSender] ; WH[WebhookDispatcher]
    end
    EVT --> Core
    REG --> EVT
    TIC --> REG
    PAY --> TIC
    CHK --> TIC
    CRT --> REG
    WRK --> EVT
    CM --> Core
    ANA --> EVT
    SUP --> Core
    OPS --> Core
    Org --> Core
    PAY --> GW
    CM --> MSG
    PAY --> WH
    REG --> WRK
```

**قواعد:** ماژول‌های لبه (Support, Analytics) فقط با Core و رویدادهای داخلی حرف می‌زنند؛
کسی به جز Core به `Money/Clock` دست نمی‌زند؛ عبور از مرز فقط با DTO ساده.

## ۳. نقشهٔ پوشهٔ هدف (بدون حذف فایل‌های موجود)

```
backend/
├── bootstrap.php              (کاهش‌یافته: فقط سیم‌کشی)
├── routes/                    (فقط delegate به Application)
├── src/
│   ├── Core/                  ← جدید: Money, Clock, Id, EventBus
│   ├── Security/              (حفظ + استخراج HeaderService/Csrf)
│   ├── Auth/  Org/  Events/  Registration/  Tickets/  Payments/
│   ├── Attendance/  Certificates/  Workflows/  Communications/
│   ├── Analytics/  Support/  Ops/  Reports/  Excel/  Media/
│   └── *​/Domain|Application|Infrastructure   (ساختار داخلی هر ماژول)
└── ...
```

## ۴. سه جریان دادهٔ حیاتی (Data Flow)

### ۴.۱ ثبت‌نام (Registration Flow)

```mermaid
sequenceDiagram
    participant U as کاربر
    participant W as Web/Routes
    participant A as RegistrationApp
    participant D as Domain(Reg)
    participant DB as DB
    U->>W: POST /events/{slug}/register
    W->>W: CSRF + Origin + RateLimit
    W->>A: RegisterCommand(eventId, userId, answers)
    A->>D: ظرفیت+وضعیت+فرم را بساز
    D->>DB: SELECT ... FOR UPDATE (اتمیک)
    D->>DB: INSERT registration (+custom answers)
    D-->>A: RegistrationId
    A-->>U: redirect /payments/{id}/review
    A->>DB: audit_logs + notifications
```

### ۴.۲ پرداخت (Payment Flow)

```mermaid
sequenceDiagram
    participant U as کاربر
    participant P as PaymentsApp
    participant G as PaymentGateway(port)
    participant DB as DB
    U->>P: POST /payments/{reg}/pay
    P->>DB: INSERT payment(status=pending, sandbox)
    P->>G: charge(amount, reference)
    G-->>P: result(success|decline|timeout)
    alt success
        P->>DB: UPDATE paid + ledger entries + registration=paid
        P->>DB: issue ticket (QR HMAC)
        P-->>U: receipt + ticket
    else decline
        P->>DB: status=failed + failure_reason
        P-->>U: پیام خطا (بدون بستن ثبت‌نام)
    end
    Note over P,DB: وب‌هوک ورودی (فاز پرداخت واقعی): امضا → idempotency_key → همان تراکنش
```

### ۴.۳ حضور/چک‌این (Attendance Flow)

```mermaid
sequenceDiagram
    participant S as اسکنر(آفلاین)
    participant A as CheckinApp
    participant DB as DB
    S->>A: POST /checkin/scan (client_ref یکتا)
    A->>DB: INSERT checkins ON DUPLICATE client_ref
    A-->>S: ok / duplicate / invalid
    S->>A: POST /checkin/sync (دسته‌ای)
    A->>DB: upsert synced_at (idempotent)
    Note over A,DB: یک بلیت = یک حضور؛ گواهی پس از پایان رویداد (status=completed)
```

## ۵. تصمیم‌های کلیدی معماری هدف

| تصمیم | انتخاب | چرا |
|---|---|---|
| الگو | **Modular Monolith** (نه میکروسرویس) | استقرار XAMPP تک‌پرونده‌ای حفظ می‌شود |
| پشته | همان PHP 7.4 / بدون framework | architectural_rule: بدون بازنویسی |
| API | `/api/v1` همان monolith با کلید | بدون سرویس جدید |
| کار پس‌زمینه | اسکریپت cron PHP + جدول jobs | بدون Redis/Queue خارجی |
| پیام | Port `MessageSender` با آداپتر محلی/SMTP/SMS | در نسخهٔ محلی mock، در واقعی واقعی |
| گردش کار | موتور state-machine سبک (جدول workflow_instances) | قابل تست بدون کتابخانه خارجی |
| چند-tenant سطحی | ستون org_id + فیلتر در Data-Access لایه | مهاجرت افزایشی، بدون شاردینگ |

## ۶. چه چیزی تغییر **نمی‌کند**

- هیچ تغییری در رمزگذاری/الگوری فعلی پول، QR، CSRF، نشست، مجوزها بدون تست
- هیچ حذف جدول/ستون در مهاجرت‌ها (فقط افزودن/سازگاری)
- هیچ جایگزینی کتابخانه/فریمورک — ابزار تست همان WASM می‌ماند
