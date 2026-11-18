<?php
declare(strict_types=1);

namespace Eroydad\Media;

use PDO;

/**
 * PosterService — بارگذاری، اعتبارسنجی و نگهداری امن پوستر دوره.
 * فایل‌ها بیرون از ریشهٔ وب (پوشهٔ storage/private) ذخیره می‌شوند و فقط از طریق
 * کنترلر و با کنترل نوع محتوا ارائه می‌گردند.
 */
final class PosterService
{
    public const MAX_BYTES = 4194304; // ۴ مگابایت
    public const MIN_WIDTH = 480;
    public const MIN_HEIGHT = 320;
    public const MAX_WIDTH = 4000;
    public const MAX_HEIGHT = 4000;

    /** @var array<string,string> */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private PDO $db;
    private string $root;

    public function __construct(PDO $db, ?string $root = null)
    {
        $this->db = $db;
        $this->root = rtrim((string)($root ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT . '/storage/private' : __DIR__ . '/../../../storage/private')), '/');
    }

    public function directory(): string
    {
        return $this->root . '/posters';
    }

    public function absolutePath(?string $relative): ?string
    {
        if ($relative === null || trim($relative) === '') {
            return null;
        }
        $safe = basename($relative);
        $path = $this->directory() . '/' . $safe;
        return is_file($path) ? $path : null;
    }

    /**
     * ذخیرهٔ پوستر جدید. ورودی: $_FILES['poster']
     * @return array{path:string,mime:string,alt:string}
     */
    public function store(array $file, string $altText = ''): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \DomainException('بارگذاری فایل پوستر ناموفق بود.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new \DomainException('فایل موقت پوستر در دسترس نیست.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \DomainException('حجم پوستر باید حداکثر ۴ مگابایت باشد.');
        }
        $info = @getimagesize($tmp);
        if (!$info || empty($info['mime']) || !isset(self::ALLOWED[$info['mime']])) {
            throw new \DomainException('فقط تصویر JPG، PNG یا WebP پذیرفته می‌شود.');
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            throw new \DomainException('ابعاد پوستر باید دست‌کم ' . self::MIN_WIDTH . '×' . self::MIN_HEIGHT . ' پیکسل باشد.');
        }
        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            throw new \DomainException('ابعاد پوستر نباید از ' . self::MAX_WIDTH . '×' . self::MAX_HEIGHT . ' بیشتر باشد.');
        }
        $mime = (string)$info['mime'];
        $extension = self::ALLOWED[$mime];
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('پوشهٔ ذخیرهٔ پوستر قابل ایجاد نیست.');
        }
        $target = $directory . '/' . $name;
        if (!$this->writeValidatedImage($tmp, $target, $mime)) {
            throw new \RuntimeException('ذخیرهٔ پوستر روی دیسک ممکن نشد.');
        }
        $alt = trim($altText);
        if ($alt === '') {
            $alt = 'پوستر دوره';
        }
        if (mb_strlen($alt, 'UTF-8') > 200) {
            $alt = mb_substr($alt, 0, 200, 'UTF-8');
        }
        return ['path' => 'posters/' . $name, 'mime' => $mime, 'alt' => $alt];
    }

    /** حذف امن فایل پوستر */
    public function delete(?string $relative): void
    {
        if ($relative === null || trim($relative) === '') {
            return;
        }
        $path = $this->absolutePath($relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * بازنویسی تصویر با GD (برای حذف فراداده) یا انتقال مستقیم در نبود GD.
     */
    private function writeValidatedImage(string $source, string $target, string $mime): bool
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            return (bool)@move_uploaded_file($source, $target) || (bool)@copy($source, $target);
        }
        $image = null;
        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source);
                break;
            case 'image/webp':
                if (!function_exists('imagecreatefromwebp')) {
                    return (bool)@move_uploaded_file($source, $target) || (bool)@copy($source, $target);
                }
                $image = @imagecreatefromwebp($source);
                break;
        }
        if (!$image) {
            return (bool)@move_uploaded_file($source, $target) || (bool)@copy($source, $target);
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = 1600;
        $targetWidth = $width;
        $targetHeight = $height;
        if ($width > $maxWidth) {
            $targetWidth = $maxWidth;
            $targetHeight = (int)round($height * ($maxWidth / $width));
        }
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $ok = false;
        switch ($mime) {
            case 'image/jpeg':
                $ok = (bool)@imagejpeg($canvas, $target, 88);
                break;
            case 'image/png':
                $ok = (bool)@imagepng($canvas, $target, 6);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    $ok = (bool)@imagewebp($canvas, $target, 86);
                }
                break;
        }
        imagedestroy($canvas);
        imagedestroy($image);
        if (!$ok) {
            @unlink($target);
            return false;
        }
        return true;
    }
}
