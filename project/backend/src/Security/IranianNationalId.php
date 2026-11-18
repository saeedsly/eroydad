<?php
declare(strict_types=1);

namespace Eroydad\Security;

final class IranianNationalId
{
    public static function normalize(string $value): string
    {
        $map = [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ];
        return preg_replace('/\D+/', '', strtr(trim($value), $map)) ?? '';
    }

    public static function isValid(string $value): bool
    {
        $code = self::normalize($value);
        if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $check = (int)$code[9];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$code[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        return $check === ($remainder < 2 ? $remainder : 11 - $remainder);
    }

    public static function hmac(string $value, string $secret): string
    {
        $normalized = self::normalize($value);
        return hash_hmac('sha256', $normalized, $secret);
    }
}
