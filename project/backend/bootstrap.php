<?php
declare(strict_types=1);

use Eroydad\Database\Connection;
use Eroydad\Security\Csrf;

const PROJECT_ROOT = __DIR__ . '/..';
require_once __DIR__ . '/src/Database/Connection.php';
require_once __DIR__ . '/src/Security/Csrf.php';
require_once __DIR__ . '/src/Security/IranianNationalId.php';
require_once __DIR__ . '/src/Security/RateLimiter.php';
require_once __DIR__ . '/src/Settings/UserSettingsService.php';

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, 0, strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
loadEnvFile(PROJECT_ROOT . '/.env');

function envValue(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$config = [
    'app' => [
        'name' => envValue('APP_NAME', 'ای‌رویداد'),
        'base_url' => rtrim((string)envValue('APP_BASE_URL', 'http://localhost'), '/'),
        'timezone' => envValue('APP_TIMEZONE', 'Asia/Tehran'),
        'debug' => envValue('APP_DEBUG', 'false') === 'true',
        'app_key' => (string)envValue('APP_KEY', ''),
        'employee_hmac_key' => (string)envValue('EMPLOYEE_HMAC_KEY', ''),
        'ticket_signing_key' => (string)envValue('TICKET_SIGNING_KEY', ''),
        'session_name' => envValue('SESSION_NAME', 'erooydad_session'),
    ],
    'db' => [
        'host' => envValue('DB_HOST', '127.0.0.1'),
        'port' => (int)envValue('DB_PORT', '3306'),
        'name' => envValue('DB_NAME', 'erooydad'),
        'user' => envValue('DB_USER', 'erooydad_app'),
        'password' => envValue('DB_PASSWORD', ''),
    ],
];

$requiredSecrets = ['app_key', 'employee_hmac_key', 'ticket_signing_key'];
foreach ($requiredSecrets as $secretName) {
    if (strlen($config['app'][$secretName]) < 32 || str_starts_with($config['app'][$secretName], 'CHANGE_ME')) {
        http_response_code(503);
        echo 'پیکربندی امن کامل نیست. فایل .env را طبق راهنمای نصب تنظیم کنید.';
        exit;
    }
}

date_default_timezone_set($config['app']['timezone']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
session_name($config['app']['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (isset($_SESSION['_last_activity']) && time() - (int)$_SESSION['_last_activity'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (isset($_SESSION['_created_at']) && time() - (int)$_SESSION['_created_at'] > 43200) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['_last_activity'] = time();
$_SESSION['_created_at'] = $_SESSION['_created_at'] ?? time();

$GLOBALS['request_id'] = bin2hex(random_bytes(12));
header('X-Request-ID: ' . $GLOBALS['request_id']);
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

function db(): PDO { global $config; return Connection::get($config); }
function appConfig(): array { global $config; return $config; }
function appBasePath(): string
{
    $path=parse_url((string)appConfig()['app']['base_url'],PHP_URL_PATH);
    $path=is_string($path)?rtrim($path,'/'):'';
    return $path==='/'?'':$path;
}
function prefixInternalUrls(string $html): string
{
    $base=appBasePath();
    if($base==='')return $html;
    return preg_replace_callback('~\\b(href|src|action)(\\s*=\\s*)"/(?!/)~i',static fn($m)=>$m[1].$m[2].'"'.$base.'/', $html)??$html;
}
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function assetUrl(string $path): string
{
    $clean = preg_replace('/\?.*$/', '', $path) ?? $path;
    $full = PROJECT_ROOT . '/frontend/public' . $clean;
    $version = is_file($full) ? (string)filemtime($full) : '1';
    $separator = strpos($path, '?') === false ? '?' : '&';
    return $path . $separator . 'v=' . $version;
}
/** تنظیمات نمایش کاربر (پوسته و اندازهٔ قلم) برای رندر صفحه */
function currentPreferences(): array
{
    static $prefs = null;
    if ($prefs !== null) {
        return $prefs;
    }
    $theme = 'auto';
    $scale = 100;
    $user = null;
    try { $user = currentUser(); } catch (Throwable $e) { $user = null; }
    if ($user) {
        try {
            $settings = new \Eroydad\Settings\UserSettingsService(db());
            $stored = $settings->get((int)$user['id']);
            $theme = (string)$stored['theme'];
            $scale = (int)$stored['font_scale'];
        } catch (Throwable $e) {
            // جدول تنظیمات هنوز ساخته نشده است
        }
    } else {
        $cookieTheme = (string)($_COOKIE['erooydad_theme'] ?? '');
        if (in_array($cookieTheme, ['light', 'dark', 'auto'], true)) { $theme = $cookieTheme; }
        $cookieScale = (int)($_COOKIE['erooydad_font_scale'] ?? 0);
        if ($cookieScale > 0) { $scale = $cookieScale; }
    }
    $prefs = ['theme' => $theme, 'font_scale' => $scale];
    return $prefs;
}
function csrfField(): string { return '<input type="hidden" name="_csrf" value="' . h(Csrf::token()) . '">'; }
function verifyCsrf(): void
{
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        exit('درخواست معتبر نیست یا منقضی شده است. صفحه را تازه‌سازی کنید.');
    }
}
function setFlash(string $type, string $message): void { $_SESSION['_flash'] = ['type' => $type, 'message' => $message]; }
function takeFlash(): ?array { $f = $_SESSION['_flash'] ?? null; unset($_SESSION['_flash']); return is_array($f) ? $f : null; }
function redirect(string $path) { $base=appBasePath(); if($base!=='' && !str_starts_with($path,$base.'/') && $path!==$base) $path=$base.'/'.ltrim($path,'/'); header('Location: ' . $path, true, 303); exit; }
function currentUser(): ?array
{
    static $loaded = false; static $user = null;
    if ($loaded) return $user;
    $loaded = true;
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) return null;
    $stmt = db()->prepare('SELECT id, phone, full_name, account_type, phone_verified_at, employee_verified_at, status, session_version FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$id]);
    $found = $stmt->fetch();
    if (!$found || $found['status'] !== 'active' || (int)$found['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $roles = db()->prepare('SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=?');
    $roles->execute([(int)$id]);
    $found['roles'] = array_column($roles->fetchAll(), 'code');
    $user = $found;
    return $user;
}
function requireAuth(): array
{
    $user = currentUser();
    if (!$user) { setFlash('error', 'برای ادامه ابتدا وارد حساب شوید.'); redirect('/login'); }
    return $user;
}
function hasRole(array $user, string $role): bool { return in_array($role, $user['roles'] ?? [], true); }
function requireRole(string ...$allowed): array
{
    $user = requireAuth();
    foreach ($allowed as $role) if (hasRole($user, $role)) return $user;
    http_response_code(403); exit('دسترسی مجاز نیست.');
}
function render(string $view, array $data = []): void
{
    $viewFile = PROJECT_ROOT . '/frontend/views/' . $view . '.php';
    if (!is_file($viewFile)) { http_response_code(500); exit('قالب پیدا نشد.'); }
    extract($data, EXTR_SKIP);
    try { $user = currentUser(); } catch (Throwable $e) { $user = null; }
    $flash = takeFlash();
    ob_start();
    require $viewFile;
    $content = ob_get_clean();
    ob_start();
    require PROJECT_ROOT . '/frontend/views/layout.php';
    echo prefixInternalUrls((string)ob_get_clean());
}
function auditLog(?int $actorId, string $action, string $entity, ?string $entityId = null, array $details = []): void
{
    $safe = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    // رویدادهای سامانه‌ای (کاربر سیستمی) بازیگر ندارند؛ مقدار صفر کلید خارجی را می‌شکند.
    $actor = ($actorId !== null && $actorId > 0) ? $actorId : null;
    $stmt = db()->prepare('INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, request_id, safe_details) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$actor, $action, $entity, $entityId, $GLOBALS['request_id'] ?? null, $safe ?: null]);
}
function normalizePhone(string $phone): string
{
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    $phone = preg_replace('/[\s\-()]+/u', '', strtr(trim($phone), $map)) ?? '';
    if (str_starts_with($phone, '+98')) $phone = '0' . substr($phone, 3);
    if (str_starts_with($phone, '98') && strlen($phone) === 12) $phone = '0' . substr($phone, 2);
    return $phone;
}
function validIranMobile(string $phone): bool { return preg_match('/^09\d{9}$/', normalizePhone($phone)) === 1; }
function passwordMaterial(string $password): string { return hash('sha256', 'erooydad-password-v1:' . $password); }
function toPersianDigits(string $value): string { return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
function jalaliDate(?string $utcDate, string $format = 'Y/m/d H:i'): string
{
    if (!$utcDate) return '';
    try { $dt = new DateTimeImmutable($utcDate, new DateTimeZone('UTC')); $dt = $dt->setTimezone(new DateTimeZone('Asia/Tehran')); }
    catch (Throwable $e) { return h($utcDate); }
    if (class_exists(IntlDateFormatter::class)) {
        $formatter = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, $format === 'Y/m/d' ? 'yyyy/MM/dd' : 'yyyy/MM/dd HH:mm');
        $result = $formatter->format($dt);
        if ($result !== false) return (string)$result;
    }
    [$jy,$jm,$jd] = gregorianToJalali((int)$dt->format('Y'),(int)$dt->format('n'),(int)$dt->format('j'));
    $date = sprintf('%04d/%02d/%02d',$jy,$jm,$jd);
    if ($format !== 'Y/m/d') $date .= ' ' . $dt->format('H:i');
    return toPersianDigits($date);
}
function gregorianToJalali(int $gy, int $gm, int $gd): array
{
    $gy -= 1600; $gm-- ; $gd--;
    $gDays = [31,28,31,30,31,30,31,31,30,31,30,31];
    $gDayNo = 365*$gy + intdiv($gy+3,4) - intdiv($gy+99,100) + intdiv($gy+399,400);
    for ($i=0; $i<$gm; $i++) $gDayNo += $gDays[$i];
    $leap = (($gy%4===0 && $gy%100!==0) || $gy%400===0);
    if ($gm>1 && $leap) $gDayNo++;
    $gDayNo += $gd;
    $jDayNo = $gDayNo - 79;
    $jNp = intdiv($jDayNo,12053); $jDayNo %= 12053;
    $jy = 979 + 33*$jNp + 4*intdiv($jDayNo,1461); $jDayNo %= 1461;
    if ($jDayNo >= 366) { $jy += intdiv($jDayNo-1,365); $jDayNo = ($jDayNo-1)%365; }
    $jDays = [31,31,31,31,31,31,30,30,30,30,30,29];
    $jm=0; while ($jm<11 && $jDayNo >= $jDays[$jm]) { $jDayNo -= $jDays[$jm]; $jm++; }
    return [$jy,$jm+1,$jDayNo+1];
}
/** تبدیل تاریخ شمسی به میلادی (همان الگوریتم jalali-date-picker.js). */
function jalaliToGregorian(int $jy, int $jm, int $jd): array
{
    $y = $jy + 1595;
    $days = -355668 + 365 * $y + intdiv($y, 33) * 8 + intdiv((($y % 33) + 3), 4) + $jd;
    $days += $jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186;
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 365) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = ($gy % 4 === 0 && ($gy % 100 !== 0 || $gy % 400 === 0));
    $monthDays = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 1;
    while ($gm <= 12 && $gd > $monthDays[$gm]) {
        $gd -= $monthDays[$gm];
        $gm++;
    }
    return [$gy, $gm, $gd];
}

/** رشتهٔ تاریخ شمسی (فارسی/لاتین، با جداکنندهٔ / یا -) → «2026-11-25»؛ نامعتبر → null. */
function gregorianFromJalali(string $value): ?string
{
    $value = strtr(trim($value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '.' => '/', '-' => '/',
    ]);
    if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $m)) {
        return null;
    }
    $jy = (int)$m[1];
    $jm = (int)$m[2];
    $jd = (int)$m[3];
    if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1) {
        return null;
    }
    if ($jm <= 6) {
        $dim = 31;
    } elseif ($jm <= 11) {
        $dim = 30;
    } else {
        $a = jalaliToGregorian($jy, 1, 1);
        $b = jalaliToGregorian($jy + 1, 1, 1);
        $dim = (int)((gmmktime(0, 0, 0, $b[1], $b[2], $b[0]) - gmmktime(0, 0, 0, $a[1], $a[2], $a[0])) / 86400) === 366 ? 30 : 29;
    }
    if ($jd > $dim) {
        return null;
    }
    [$gy, $gm, $gd] = jalaliToGregorian($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

function ticketSignature(string $publicCode): string
{
    $key = appConfig()['app']['ticket_signing_key'];
    return hash_hmac('sha256', $publicCode, $key);
}
