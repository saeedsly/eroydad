<?php
declare(strict_types=1);

namespace Eroydad\Excel;

/**
 * Zip — نوشتن و خواندن فایل ZIP بدون وابستگی بیرونی.
 * از این کلاس برای ساخت و خواندن فایل‌های واقعی XLSX استفاده می‌شود.
 * اگر افزونهٔ ZipArchive موجود نباشد، این پیاده‌سازی خالص PHP کار می‌کند.
 */
final class Zip
{
    /**
     * ساخت بایت‌های ZIP از نگاشت «نام → محتوا».
     * @param array<string,string> $entries
     */
    public static function create(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;
        // برچسب زمان DOS/ZIP: زمان = ساعت<<11 | دقیقه<<5 | ثانیه/۲ و تاریخ = (سال-۱۹۸۰)<<9 | ماه<<5 | روز
        $now = time();
        $parts = getdate($now);
        $year = max(1980, min(2107, (int)$parts['year']));
        $dosTime = (((int)$parts['hours'] & 0x1F) << 11) | (((int)$parts['minutes'] & 0x3F) << 5) | (((int)$parts['seconds'] / 2) & 0x1F);
        $dosDate = ((($year - 1980) & 0x7F) << 9) | (((int)$parts['mon'] & 0x0F) << 5) | ((int)$parts['mday'] & 0x1F);
        foreach ($entries as $name => $data) {
            $name = (string)$name;
            $raw = (string)$data;
            $crc = crc32($raw);
            $deflated = function_exists('gzdeflate') ? @gzdeflate($raw, 6) : false;
            if ($deflated !== false && strlen($deflated) < strlen($raw)) {
                $method = 8;
                $payload = $deflated;
            } else {
                $method = 0;
                $payload = $raw;
            }
            $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($payload), strlen($raw), strlen($name), 0);
            $local .= $header . $name . $payload;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x031E, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($payload), strlen($raw), strlen($name), 0, 0, 0, 0, 0, $offset);
            $central .= $name;
            $offset += strlen($header) + strlen($name) + strlen($payload);
            $count++;
        }
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
        return $local . $central . $eocd;
    }

    /**
     * خواندن محتوای یک ZIP در حافظه.
     * @return array<string,string>
     */
    public static function read(string $binary): array
    {
        $entries = [];
        $eocdPos = strrpos($binary, pack('V', 0x06054b50));
        if ($eocdPos === false) {
            throw new \RuntimeException('فایل فشرده معتبر نیست.');
        }
        $eocd = unpack('Vsignature/vdisk/vcdDisk/vcountDisk/vcount/VcdSize/VcdOffset/vcommentLen', substr($binary, $eocdPos, 22));
        if (!$eocd) {
            throw new \RuntimeException('ساختار ZIP قابل خواندن نیست.');
        }
        $pointer = (int)$eocd['cdOffset'];
        $total = (int)$eocd['count'];
        for ($i = 0; $i < $total; $i++) {
            if ($pointer + 46 > strlen($binary)) {
                break;
            }
            $central = unpack('Vsignature/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/VrawSize/vnameLen/vextraLen/vcommentLen/vdiskStart/vinternal/Vexternal/VlocalOffset', substr($binary, $pointer, 46));
            if (!$central || $central['signature'] !== 0x02014b50) {
                break;
            }
            $name = substr($binary, $pointer + 46, (int)$central['nameLen']);
            $pointer += 46 + (int)$central['nameLen'] + (int)$central['extraLen'] + (int)$central['commentLen'];
            $localOffset = (int)$central['localOffset'];
            if ($localOffset + 30 > strlen($binary)) {
                continue;
            }
            $local = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/VrawSize/vnameLen/vextraLen', substr($binary, $localOffset, 30));
            if (!$local) {
                continue;
            }
            $dataStart = $localOffset + 30 + (int)$local['nameLen'] + (int)$local['extraLen'];
            $payload = substr($binary, $dataStart, (int)$central['compSize']);
            if ((int)$central['method'] === 8) {
                $inflated = function_exists('gzinflate') ? @gzinflate($payload) : false;
                if ($inflated === false) {
                    continue;
                }
                $payload = $inflated;
            }
            $entries[$name] = $payload;
        }
        return $entries;
    }
}
