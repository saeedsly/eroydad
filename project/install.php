<?php
declare(strict_types=1);
/**
 * نصب‌کنندهٔ یک‌مرحله‌ای ای‌رویداد برای XAMPP (بدون خط فرمان و بدون phpMyAdmin).
 *
 * کاربر: استخراج بسته ← Apache و MySQL روشن ← باز کردن این نشانی:
 *   http://localhost/erooydad/install.php
 * و پرکردن فرم. نصب‌کننده به‌ترتیب انجام می‌دهد:
 *   ۱) ساخت دیتابیس (در صورت نبود)  ۲) اجرای database/schema.sql
 *   ۳) ساخت کاربر دیتابیس با دسترسی محدود  ۴) نوشتن فایل .env با کلیدهای تصادفی
 *   ۵) ساخت حساب مدیر اول با نقش system_admin
 *
 * پس از نصب موفق، برای امنیت این فایل را حذف کنید.
 * اگر .env معتبر موجود باشد، نصب‌کننده قفل می‌شود و هیچ چیزی را تغییر نمی‌دهد.
 */

$root = __DIR__;
$envPath = $root . '/.env';

header('Content-Type: text/html; charset=utf-8');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** خواندن سادهٔ فایل KEY=VALUE (همان قواعد backend/bootstrap.php). */
function readEnvFile(string $path): array
{
    $result = [];
    if (!is_file($path)) {
        return $result;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $result[trim($key)] = trim(trim($value), "\"'");
    }
    return $result;
}

/** آیا .env سه راز لازم را دارد؟ (همان شرط 503 در bootstrap.php) */
function envIsConfigured(array $env): bool
{
    foreach (['APP_KEY', 'EMPLOYEE_HMAC_KEY', 'TICKET_SIGNING_KEY'] as $key) {
        $value = (string)($env[$key] ?? '');
        if (strlen($value) < 32 || strpos($value, 'CHANGE_ME') === 0) {
            return false;
        }
    }
    return true;
}

/**
 * شکستن اسکریپت SQL به دستورهای مستقل؛ رشتهٔ تک‌/دوگیمیت و کامنت را رعایت می‌کند
 * (همان الگوریتم tests/support/harness.mjs → splitStatements).
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : null;
        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($ch === '-' && $next === '-') {
                $after = $i + 2 < $len ? $sql[$i + 2] : null;
                if ($after === ' ' || $after === "\n" || $after === null) {
                    $inLineComment = true;
                    continue;
                }
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $i++;
                $inBlockComment = true;
                continue;
            }
            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }
        }
        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        } elseif ($ch === '\\' && $inSingle && !$inDouble && !$inBacktick) {
            $current .= $ch;
            if ($i + 1 < $len) {
                $i++;
                $current .= $sql[$i];
            }
            continue;
        }
        $current .= $ch;
    }
    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}

/** نشانی پایه بر اساس همین درخواست (مثلاً http://localhost/erooydad). */
function detectBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '/install.php';
    $dir = rtrim(dirname($script), '/');
    return $scheme . '://' . $host . ($dir === '/' ? '' : $dir);
}

$installed = is_file($envPath) && envIsConfigured(readEnvFile($envPath));
$error = null;
$stepDone = [];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(16));
}
$token = (string)$_SESSION['install_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $host = '127.0.0.1';
    $port = 3306;
    $dbName = 'erooydad';
    $dbUser = 'root';
    $dbPass = '';
    $baseUrl = '';
    $phone = '';
    $adminName = '';
    $password = '';
    $password2 = '';

    try {
        if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
            throw new RuntimeException('نشست نامعتبر است؛ صفحه را دوباره باز کنید.');
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('افزونهٔ pdo_mysql در PHP فعال نیست (در XAMPP پیش‌فرض فعال است).');
        }

        $host = (string)($_POST['db_host'] ?? '127.0.0.1');
        $port = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim((string)($_POST['db_name'] ?? 'erooydad'));
        $dbUser = trim((string)($_POST['db_user'] ?? 'root'));
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $baseUrl = rtrim(trim((string)($_POST['base_url'] ?? '')), '/');
        $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? '')) ?? '';
        $adminName = trim((string)($_POST['admin_name'] ?? ''));
        $password = (string)($_POST['admin_password'] ?? '');
        $password2 = (string)($_POST['admin_password2'] ?? '');

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('پورت دیتابیس معتبر نیست.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new RuntimeException('نام دیتابیس فقط می‌تواند حروف انگلیسی، عدد و زیرخط باشد.');
        }
        if ($dbUser === '') {
            throw new RuntimeException('کاربر دیتابیس خالی است.');
        }
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || strpos($baseUrl, 'http') !== 0) {
            throw new RuntimeException('نشانی پایهٔ برنامه معتبر نیست (مثال: http://localhost/erooydad).');
        }
        if (!preg_match('/^09\d{9}$/', $phone)) {
            throw new RuntimeException('شماره موبایل مدیر باید ۱۱ رقم و با ۰۹ شروع شود.');
        }
        $nameLen = function_exists('mb_strlen') ? mb_strlen($adminName, 'UTF-8') : strlen($adminName);
        if ($nameLen < 2) {
            throw new RuntimeException('نام مدیر باید دست‌کم ۲ نویسه باشد.');
        }
        $pwLen = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($pwLen < 12 || $pwLen > 200) {
            throw new RuntimeException('رمز عبور مدیر باید ۱۲ تا ۲۰۰ نویسه باشد.');
        }
        if ($password !== $password2) {
            throw new RuntimeException('رمز عبور و تکرار آن یکسان نیستند.');
        }

        // ---------- ۱) اتصال و ساخت دیتابیس ----------
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . $dbName . '`');
        $stepDone[] = 'دیتابیس «' . $dbName . '» آماده شد.';

        // ---------- ۲) اجرای schema ----------
        $schemaFile = $root . '/database/schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException('فایل database/schema.sql در بسته پیدا نشد.');
        }
        $schemaSql = file_get_contents($schemaFile);
        if ($schemaSql === false) {
            throw new RuntimeException('خواندن database/schema.sql ممکن نشد.');
        }
        $statements = splitSqlStatements($schemaSql);
        if (count($statements) < 10) {
            throw new RuntimeException('schema.sql به‌درستی شکسته نشد؛ نصب متوقف شد.');
        }
        foreach ($statements as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException('اجرای بخش ' . ($index + 1) . ' از schema ناموفق بود: ' . $e->getMessage());
            }
        }
        $stepDone[] = 'schema (database/schema.sql) با ' . count($statements) . ' دستور اجرا شد؛ جدول‌ها و داده‌های پایه ساخته شد.';

        // ---------- ۳) کاربر دیتابیس با دسترسی محدود ----------
        $envDbUser = $dbUser;
        $envDbPass = $dbPass;
        try {
            $appPass = bin2hex(random_bytes(24));
            $quoted = $pdo->quote($appPass);
            $pdo->exec("CREATE USER IF NOT EXISTS 'erooydad_app'@'127.0.0.1' IDENTIFIED BY " . $quoted);
            $pdo->exec("ALTER USER 'erooydad_app'@'127.0.0.1' IDENTIFIED BY " . $quoted);
            $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `' . $dbName . '`.* TO \'erooydad_app\'@\'127.0.0.1\'');
            $pdo->exec('FLUSH PRIVILEGES');
            $envDbUser = 'erooydad_app';
            $envDbPass = $appPass;
            $stepDone[] = 'کاربر محدود «erooydad_app» برای برنامه ساخته شد.';
        } catch (PDOException $e) {
            // اگر کاربر انتخابی مجوز CREATE USER ندارد، با همان کاربرِ واردشده کار می‌کنیم.
            $stepDone[] = 'کاربر محدود ساخته نشد؛ برنامه با همان کاربر واردشده کار خواهد کرد.';
        }

        // ---------- ۴) حساب مدیر اول ----------
        $roleRow = $pdo->query("SELECT id FROM roles WHERE code='system_admin' LIMIT 1")->fetch();
        if (!$roleRow) {
            $pdo->exec("INSERT IGNORE INTO roles (code, label_fa) VALUES ('system_admin', 'مدیر سامانه')");
            $roleRow = $pdo->query("SELECT id FROM roles WHERE code='system_admin' LIMIT 1")->fetch();
        }
        if (!$roleRow) {
            throw new RuntimeException('نقش system_admin ساخته نشد.');
        }
        $roleId = (int)$roleRow['id'];

        // همان الگوریتم passwordMaterial در backend/bootstrap.php
        $material = hash('sha256', 'erooydad-password-v1:' . $password);
        $passwordHash = password_hash($material, PASSWORD_DEFAULT);

        $find = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $find->execute([$phone]);
        $existing = $find->fetch();
        if ($existing) {
            $update = $pdo->prepare("UPDATE users SET password_hash = ?, full_name = ?, status = 'active' WHERE id = ?");
            $update->execute([$passwordHash, $adminName, (int)$existing['id']]);
            $userId = (int)$existing['id'];
            $stepDone[] = 'حساب موجودِ این شماره به‌روز شد و رمز مدیر همان شماره قرار گرفت.';
        } else {
            $insert = $pdo->prepare("INSERT INTO users (phone, full_name, password_hash, account_type, status, phone_verified_at) VALUES (?, ?, ?, 'public', 'active', UTC_TIMESTAMP())");
            $insert->execute([$phone, $adminName, $passwordHash]);
            $userId = (int)$pdo->lastInsertId();
            $stepDone[] = 'حساب مدیر ساخته شد.';
        }
        $grantCheck = $pdo->prepare('SELECT user_id FROM user_roles WHERE user_id = ? AND role_id = ? LIMIT 1');
        $grantCheck->execute([$userId, $roleId]);
        if (!$grantCheck->fetch()) {
            $grant = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            $grant->execute([$userId, $roleId]);
        }
        $stepDone[] = 'نقش «مدیر سامانه» به این حساب داده شد.';

        // ---------- ۵) نوشتن .env (فقط پس از موفقیت همهٔ مراحل) ----------
        $envLines = [
            'APP_NAME=ای‌رویداد',
            'APP_BASE_URL=' . $baseUrl,
            'APP_TIMEZONE=Asia/Tehran',
            'APP_DEBUG=false',
            'SESSION_NAME=erooydad_session',
            'DB_HOST=127.0.0.1',
            'DB_PORT=' . $port,
            'DB_NAME=' . $dbName,
            'DB_USER=' . $envDbUser,
            'DB_PASSWORD=' . $envDbPass,
            'APP_KEY=' . bin2hex(random_bytes(32)),
            'EMPLOYEE_HMAC_KEY=' . bin2hex(random_bytes(32)),
            'TICKET_SIGNING_KEY=' . bin2hex(random_bytes(32)),
        ];
        if (file_put_contents($envPath, implode("\n", $envLines) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('نوشتن فایل .env ممکن نشد؛ پوشهٔ برنامه را بررسی کنید.');
        }
        $stepDone[] = 'فایل .env با کلیدهای امن تصادفی نوشته شد.';

        $installed = true;
        unset($_SESSION['install_token']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/** صفحهٔ فرم نصب. */
function renderForm(string $token, string $baseUrl, ?string $error, array $stepDone): void
{
    $doneHtml = '';
    if ($stepDone) {
        $doneHtml = '<div class="box ok"><b>پیشرفت تا این لحظه:</b><ul>';
        foreach ($stepDone as $step) {
            $doneHtml .= '<li>' . h($step) . '</li>';
        }
        $doneHtml .= '</ul><p class="muted">می‌توانید فرم را با همان تنظیمات دوباره بفرستید؛ مراحل قبل تکرارپذیرند.</p></div>';
    }
    $errorHtml = $error !== null ? '<div class="box err"><b>نصب انجام نشد:</b> ' . h($error) . '</div>' : '';
    $tokenH = h($token);
    $baseUrlH = h($baseUrl);
    echo <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>نصب ای‌رویداد</title>
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; font-family: Tahoma, "Segoe UI", Arial, sans-serif; background: #f4f7f5; color: #17251f; line-height: 1.7; }
.wrap { max-width: 720px; margin: 32px auto; padding: 0 16px; }
header { background: #16221b; color: #eaf5ef; padding: 18px 20px; border-radius: 14px 14px 0 0; }
header b { font-size: 20px; }
header small { display: block; opacity: .8; }
form { background: #fff; border: 1px solid #d9e4de; border-top: 0; border-radius: 0 0 14px 14px; padding: 20px; }
h2 { font-size: 16px; margin: 18px 0 8px; color: #0f5c3f; }
h2:first-child { margin-top: 0; }
label { display: block; margin: 10px 0 4px; font-size: 14px; }
input { width: 100%; padding: 9px 11px; border: 1px solid #cbd8d2; border-radius: 8px; font-size: 15px; background: #fbfdfc; }
input:focus { outline: 2px solid #2e8b66; border-color: transparent; }
.row { display: flex; gap: 12px; flex-wrap: wrap; }
.row > div { flex: 1 1 180px; }
button { margin-top: 18px; width: 100%; padding: 12px; font-size: 16px; font-weight: bold; background: #157347; color: #fff; border: 0; border-radius: 10px; cursor: pointer; }
button:hover { background: #0f5c3f; }
.box { padding: 12px 14px; border-radius: 10px; margin: 12px 0; font-size: 14px; }
.err { background: #fdecea; border: 1px solid #f2b8b5; color: #8a1f1a; }
.ok { background: #e9f7f0; border: 1px solid #b7e3ce; color: #0f5c3f; }
.muted { color: #5d6f67; font-size: 13px; }
code { background: #eef3f0; padding: 1px 6px; border-radius: 6px; direction: ltr; display: inline-block; }
</style>
</head>
<body>
<div class="wrap">
<header><b>نصب یک‌مرحله‌ای ای‌رویداد</b><small>PHP 7.4 + MySQL — بدون خط فرمان، بدون phpMyAdmin</small></header>
<form method="post" action="">
<input type="hidden" name="token" value="{$tokenH}">
{$errorHtml}
{$doneHtml}
<h2>۱) دیتابیس</h2>
<div class="row">
<div><label>میزبان</label><input name="db_host" value="127.0.0.1" dir="ltr"></div>
<div><label>پورت</label><input name="db_port" value="3306" dir="ltr"></div>
<div><label>نام دیتابیس</label><input name="db_name" value="erooydad" dir="ltr"></div>
</div>
<div class="row">
<div><label>کاربر (XAMPP پیش‌فرض: root)</label><input name="db_user" value="root" dir="ltr"></div>
<div><label>رمز کاربر (پیش‌فرض XAMPP: خالی)</label><input name="db_pass" value="" type="password" dir="ltr"></div>
</div>
<p class="muted">دیتابیس و جدول‌ها در صورت نبود ساخته می‌شوند؛ اگر از قبل وارد کرده‌اید، دست‌نخورده می‌مانند.</p>
<h2>۲) نشانی برنامه</h2>
<label>نشانی پایه (Base URL)</label>
<input name="base_url" value="{$baseUrlH}" dir="ltr">
<h2>۳) حساب مدیر اول</h2>
<div class="row">
<div><label>شماره موبایل</label><input name="phone" placeholder="09123456789" dir="ltr" required></div>
<div><label>نام مدیر</label><input name="admin_name" placeholder="مدیر سامانه" required></div>
</div>
<div class="row">
<div><label>رمز عبور (۱۲+ نویسه)</label><input name="admin_password" type="password" required></div>
<div><label>تکرار رمز</label><input name="admin_password2" type="password" required></div>
</div>
<button type="submit">نصب کامل را اجرا کن</button>
<p class="muted">پس از نصب موفق، این فایل (install.php) را حذف کنید. رمز مدیر را جایی امن نگه دارید.</p>
</form>
</div>
</body>
</html>
HTML;
}

/** صفحهٔ «نصب کامل است». */
function renderInstalled(array $stepDone): void
{
    $stepsHtml = '';
    if ($stepDone) {
        $stepsHtml = '<div class="box ok"><b>نصب با موفقیت انجام شد:</b><ul>';
        foreach ($stepDone as $step) {
            $stepsHtml .= '<li>' . h($step) . '</li>';
        }
        $stepsHtml .= '</ul></div>';
    }
    $homeH = h(detectBaseUrl());
    echo <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>نصب کامل است — ای‌رویداد</title>
<style>
body { margin: 0; font-family: Tahoma, "Segoe UI", Arial, sans-serif; background: #f4f7f5; color: #17251f; line-height: 1.8; }
.wrap { max-width: 640px; margin: 40px auto; padding: 0 16px; }
.card { background: #fff; border: 1px solid #d9e4de; border-radius: 14px; padding: 24px; }
h1 { font-size: 22px; color: #0f5c3f; margin-top: 0; }
.box { padding: 12px 14px; border-radius: 10px; margin: 12px 0; font-size: 14px; background: #e9f7f0; border: 1px solid #b7e3ce; color: #0f5c3f; }
.btn { display: inline-block; margin: 8px 6px 0 0; padding: 10px 18px; background: #157347; color: #fff; text-decoration: none; border-radius: 9px; font-weight: bold; }
.btn.alt { background: #16221b; }
.muted { color: #5d6f67; font-size: 13px; }
code { background: #eef3f0; padding: 1px 6px; border-radius: 6px; direction: ltr; display: inline-block; }
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>✅ نصب کامل است</h1>
{$stepsHtml}
<p>اکنون وارد حساب مدیر شوید (شماره‌ای که در فرم نصب وارد کردید + رمز همان فرم):</p>
<a class="btn" href="{$homeH}/login">صفحهٔ ورود</a>
<a class="btn alt" href="{$homeH}/">خانهٔ برنامه</a>
<div class="box"><b>برای امنیت:</b> فایل <code>install.php</code> را از پوشهٔ برنامه حذف کنید؛ دیگر لازم نیست. اگر روزی خواستید مدیر دیگری بسازید: پس از ورود، یا از اسکریپت <code>deployment/local-xampp/create-admin.php</code> استفاده کنید.</div>
<p class="muted">نصب‌کننده پس از ساخت فایل .env قفل می‌شود و دوباره اجرا نمی‌شود.</p>
</div>
</div>
</body>
</html>
HTML;
}

if ($installed) {
    renderInstalled($stepDone);
} else {
    renderForm($token, detectBaseUrl(), $error, $stepDone);
}
