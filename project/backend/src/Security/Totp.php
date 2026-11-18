<?php
declare(strict_types=1);

namespace Eroydad\Security;

/**
 * Totp — احراز هویت دومرحله‌ای محلی بر پایهٔ RFC 6238 (TOTP/HOTP با SHA-1).
 * هیچ سرویس بیرونی یا پیامکی درگیر نیست؛ کد با اپلیکیشن احرازکنندهٔ کاربر تولید می‌شود.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $raw): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($raw) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }
        return $output;
    }

    public static function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = strtoupper(preg_replace('/[\s=-]+/', '', $encoded) ?? '');
        $bits = '';
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $position = strpos($alphabet, $encoded[$i]);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $raw = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $raw .= chr(bindec($chunk));
            }
        }
        return $raw;
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = (int)floor($timestamp / self::PERIOD);
        return self::hotp($secret, $counter);
    }

    public static function hotp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $binary = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $modulo = 10 ** self::DIGITS;
        return str_pad((string)($value % $modulo), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** بررسی کد با پنجرهٔ ±۱ گام (۹۰ ثانیه) */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $timestamp = $timestamp ?? time();
        $counter = (int)floor($timestamp / self::PERIOD);
        for ($delta = -1; $delta <= 1; $delta++) {
            if (hash_equals(self::hotp($secret, $counter + $delta), $code)) {
                return true;
            }
        }
        return false;
    }

    /** نشانی otpauth:// برای نمایش QR در برنامه */
    public static function provisioningUri(string $secret, string $account, string $issuer = 'ای‌رویداد'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }
}
