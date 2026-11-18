<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$projectRoot=dirname(__DIR__,2);
$envPath=$projectRoot.'/.env';
$fail=static function(string $message): void { fwrite(STDERR,$message.PHP_EOL); exit(1); };
if (PHP_VERSION_ID < 70400) $fail('این بسته برای PHP 7.4 یا جدیدتر است.');
if (!extension_loaded('pdo_mysql') || !extension_loaded('mbstring') || !extension_loaded('openssl')) $fail('افزونهٔ pdo_mysql، mbstring یا openssl در PHP این XAMPP فعال نیست.');

$readEnv=static function(string $path): array {
    $result=[];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line=trim($line);
        if ($line==='' || $line[0]==='#' || strpos($line,'=')===false) continue;
        [$key,$value]=explode('=',$line,2);
        $result[trim($key)]=trim(trim($value), "\\\"'");
    }
    return $result;
};
$writeEnv=static function(array $values, bool $preserveExisting) use ($projectRoot,$envPath,$fail): void {
    if ($preserveExisting) {
        $lines=file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines===false) $fail('فایل .env خوانده نشد؛ هیچ تغییری اعمال نشد.');
    } else {
        $template=file_get_contents($projectRoot.'/.env.example');
        if ($template===false) $fail('فایل .env.example خوانده نشد.');
        $lines=preg_split('/\\r\\n|\\n|\\r/',$template);
    }
    $seen=[];
    foreach($lines as &$line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=/', $line, $m) && array_key_exists($m[1],$values)) {
            $key=$m[1];
            if (isset($seen[$key])) { $line=''; continue; }
            $line=$key.'='.$values[$key];
            $seen[$key]=true;
        }
    }
    unset($line);
    foreach($values as $key=>$value) if(!isset($seen[$key])) $lines[]=$key.'='.$value;
    $text=implode(PHP_EOL,$lines).PHP_EOL;
    if(file_put_contents($envPath,$text,LOCK_EX)===false) $fail('ذخیرهٔ تنظیم دیتابیس در .env انجام نشد.');
};
$connect=static function(string $host,string $port,string $name,string $user,string $password): PDO {
    return new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
};
$schemaProblem=static function(PDO $db): ?string {
    try {
        $version=$db->query("SELECT COUNT(*) FROM schema_migrations WHERE version='001_initial_schema'")->fetchColumn();
        if((int)$version!==1) return 'Schema پایهٔ ای‌رویداد پیدا نشد؛ دیتابیس دوباره Import نشد.';
        $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach(['users','roles','user_roles','events','registrations','tickets','checkins','support_tickets','employee_allowlist','registration_attempts','audit_logs'] as $table)
            if(!in_array($table,$tables,true)) return 'جدول '.$table.' پیدا نشد؛ هیچ جدولی تغییر نکرد.';
        $columns=$db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        foreach(['municipality','last_login_at','session_version'] as $column)
            if(!in_array($column,$columns,true)) return 'ستون '.$column.' در جدول users پیدا نشد؛ schema را روی دیتابیس دارای اطلاعات دوباره اجرا نکنید.';
        $roles=(int)$db->query("SELECT COUNT(*) FROM roles WHERE code IN ('attendee','event_admin','support','system_admin')")->fetchColumn();
        if($roles!==4) return 'نقش‌های پایه در دیتابیس کامل نیستند.';
        return null;
    } catch(Throwable $e) { return 'بررسی schema دیتابیس انجام نشد؛ دیتابیس تغییری نکرد.'; }
};
$makeAppUser=static function(PDO $rootDb,string $database): string {
    if(!preg_match('/^[A-Za-z0-9_]+$/',$database)) throw new RuntimeException('نام دیتابیس در تنظیمات معتبر نیست.');
    $password=bin2hex(random_bytes(24));
    $quoted=$rootDb->quote($password);
    $rootDb->exec("CREATE USER IF NOT EXISTS 'erooydad_app'@'127.0.0.1' IDENTIFIED BY ".$quoted);
    $rootDb->exec("ALTER USER 'erooydad_app'@'127.0.0.1' IDENTIFIED BY ".$quoted);
    $rootDb->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `".$database."`.* TO 'erooydad_app'@'127.0.0.1'");
    return $password;
};

try {
    $hasEnv=is_file($envPath);
    if($hasEnv) {
        $config=$readEnv($envPath);
        foreach(['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD'] as $key)
            if(!isset($config[$key]) || $config[$key]==='') $fail('تنظیم '.$key.' در .env پیدا نشد؛ فایل دست‌نخورده ماند.');
        $host=$config['DB_HOST']; $port=$config['DB_PORT']; $name=$config['DB_NAME']; $user=$config['DB_USER']; $password=$config['DB_PASSWORD'];
        try {
            $db=$connect($host,$port,$name,$user,$password);
        } catch(PDOException $appConnectionError) {
            // XAMPP's default local root account has no password. Use it only as a local recovery path.
            try { $rootDb=$connect('localhost','3306',$name,'root',''); }
            catch(PDOException $rootConnectionError) {
                $fail('اتصال برنامه به دیتابیس ناموفق است و ترمیم خودکار هم ممکن نشد. فایل .env و جدول‌های دیتابیس تغییر نکردند.');
            }
            $problem=$schemaProblem($rootDb);
            if($problem!==null) $fail($problem);
            $password=$makeAppUser($rootDb,$name);
            $host='127.0.0.1'; $port='3306'; $user='erooydad_app';
            $writeEnv(['DB_HOST'=>$host,'DB_PORT'=>$port,'DB_NAME'=>$name,'DB_USER'=>$user,'DB_PASSWORD'=>$password],true);
            $db=$connect($host,$port,$name,$user,$password);
            echo "اتصال محلی ترمیم شد؛ کلیدهای برنامه حفظ شدند و فقط تنظیم DB در .env به‌روز شد.".PHP_EOL;
        }
    } else {
        $name='erooydad';
        try { $rootDb=$connect('localhost','3306',$name,'root',''); }
        catch(PDOException $e) { $fail('اتصال root محلی XAMPP برقرار نشد؛ انتظار می‌رود MySQL روی پورت 3306 و root بدون رمز باشد.'); }
        $problem=$schemaProblem($rootDb);
        if($problem!==null) $fail($problem);
        $password=$makeAppUser($rootDb,$name);
        $values=[
            'APP_NAME'=>'ای‌رویداد','APP_BASE_URL'=>'https://localhost/erooydad','APP_TIMEZONE'=>'Asia/Tehran',
            'APP_DEBUG'=>'false','SESSION_NAME'=>'erooydad_session','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306',
            'DB_NAME'=>$name,'DB_USER'=>'erooydad_app','DB_PASSWORD'=>$password,'APP_KEY'=>bin2hex(random_bytes(32)),
            'EMPLOYEE_HMAC_KEY'=>bin2hex(random_bytes(32)),'TICKET_SIGNING_KEY'=>bin2hex(random_bytes(32)),
        ];
        $writeEnv($values,false);
        $db=$connect('127.0.0.1','3306',$name,'erooydad_app',$password);
        echo "تنظیم محلی و کلیدهای تصادفی ساخته شدند؛ دیتابیس و جدول‌ها دست‌نخورده ماندند.".PHP_EOL;
    }

    $problem=$schemaProblem($db);
    if($problem!==null) $fail($problem);
    echo "اتصال و schema دیتابیس سالم است؛ دیتابیس دوباره Import نشده است.".PHP_EOL;
    $adminCount=(int)$db->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.code='system_admin'")->fetchColumn();
    if($adminCount>0) echo "حساب مدیر سامانه از قبل وجود دارد؛ مدیر تکراری ساخته نشد.".PHP_EOL;
    else {
        echo "حساب مدیر پیدا نشد؛ حالا نام، موبایل و رمز مدیر را در همین CMD وارد کن.".PHP_EOL;
        require $projectRoot.'/deployment/local-xampp/create-admin.php';
    }
    echo "اکنون Apache را Start کن و https://localhost/erooydad را باز کن.".PHP_EOL;
} catch(PDOException $e) {
    $fail('اتصال دیتابیس برقرار نشد. هیچ جدول یا داده‌ای دوباره ساخته نشد.');
} catch(Throwable $e) {
    $fail('راه‌اندازی انجام نشد؛ دیتابیس دوباره Import نشد.');
}
