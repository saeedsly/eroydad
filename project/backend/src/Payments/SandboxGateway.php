<?php
declare(strict_types=1);

namespace Eroydad\Payments;

/**
 * SandboxGateway — درگاه کاملاً آزمایشی.
 * هیچ ارتباط شبکه‌ای برقرار نمی‌شود و هیچ وجه واقعی جابه‌جا نمی‌گردد.
 * تمام مسیرهای پرداخت واقعی در این نسخه قفل شده‌اند (assertRealGatewayLocked).
 */
final class SandboxGateway
{
    public const PROVIDER = 'sandbox';
    public const REAL_PAYMENT_ENABLED = false;

    /** @var array<string,string> */
    public const SCENARIOS = [
        'success' => 'پرداخت موفق (آزمایشی)',
        'failure' => 'پرداخت ناموفق (آزمایشی)',
        'cancelled' => 'انصراف کاربر (آزمایشی)',
        'timeout' => 'خطای موقت سرور (آزمایشی)',
    ];

    /**
     * قفل صریح مسیرهای پرداخت واقعی.
     * اگر کسی در پیکربندی یا متغیرهای محیطی درگاه واقعی تعریف کند، اجرا متوقف می‌شود.
     */
    public static function assertRealGatewayLocked(): void
    {
        if (self::REAL_PAYMENT_ENABLED) {
            throw new \RuntimeException('درگاه واقعی در این نسخه فعال نیست.');
        }
        foreach (['PAYMENT_PROVIDER', 'APP_PAYMENT_PROVIDER', 'ZARINPAL_MERCHANT_ID', 'IDPAY_API_KEY'] as $key) {
            $value = getenv($key);
            if ($value !== false && trim((string)$value) !== '' && strtolower(trim((string)$value)) !== 'sandbox' && strtolower(trim((string)$value)) !== 'none') {
                throw new \RuntimeException('پیکربندی درگاه واقعی شناسایی شد؛ اجرای پرداخت برای جلوگیری از جابه‌جایی وجه متوقف شد.');
            }
        }
    }

    /**
     * اجرای تراکنش ساختگی.
     * @return array{status:string,reference:string,message:string}
     */
    public static function charge(string $scenario, int $amountToman, array $context = []): array
    {
        self::assertRealGatewayLocked();
        $scenario = in_array($scenario, array_keys(self::SCENARIOS), true) ? $scenario : 'success';
        $reference = 'SBX-' . strtoupper(bin2hex(random_bytes(6)));
        switch ($scenario) {
            case 'failure':
                return ['status' => 'failed', 'reference' => $reference, 'message' => 'تراکنش آزمایشی ناموفق بود (شبیه‌سازی خطای بانک).'];
            case 'cancelled':
                return ['status' => 'cancelled', 'reference' => $reference, 'message' => 'پرداخت توسط کاربر لغو شد (آزمایشی).'];
            case 'timeout':
                return ['status' => 'failed', 'reference' => $reference, 'message' => 'پاسخی از درگاه دریافت نشد (شبیه‌سازی خطای موقت).'];
            default:
                return ['status' => 'paid', 'reference' => $reference, 'message' => 'پرداخت آزمایشی با موفقیت ثبت شد.'];
        }
    }

    public static function isSandbox(): bool
    {
        return self::PROVIDER === 'sandbox' && self::REAL_PAYMENT_ENABLED === false;
    }
}
