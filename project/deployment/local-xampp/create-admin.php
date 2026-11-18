<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/backend/bootstrap.php';

function promptLine(string $label): string { echo $label; return trim((string)fgets(STDIN)); }
function promptPassword(string $label): string
{
    echo $label;
    if (PHP_OS_FAMILY === 'Windows') return trim((string)fgets(STDIN));
    system('stty -echo');
    try { $value=trim((string)fgets(STDIN)); } finally { system('stty echo'); echo PHP_EOL; }
    return $value;
}
$phone=normalizePhone(promptLine('شماره موبایل مدیر: '));
$name=promptLine('نام مدیر: ');
$password=promptPassword('رمز عبور اولیه (حداقل ۱۲ نویسه؛ هنگام تایپ در Windows دیده می‌شود): ');
$confirm=promptPassword('تکرار رمز: ');
if (!validIranMobile($phone) || mb_strlen($name,'UTF-8')<2 || mb_strlen($password,'UTF-8')<12 || mb_strlen($password,'UTF-8')>200 || $password!==$confirm) {
    fwrite(STDERR,"اطلاعات نامعتبر است؛ مدیر ساخته نشد.\n"); exit(1);
}
$db=db(); $db->beginTransaction();
try {
    $check=$db->prepare('SELECT id FROM users WHERE phone=? LIMIT 1'); $check->execute([$phone]);
    if ($check->fetchColumn()) throw new RuntimeException('این شماره از قبل حساب دارد؛ نقش مدیر به حساب موجود اضافه نشد.');
    $insert=$db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status) VALUES (?,?,?,'public','active')");
    $insert->execute([$phone,$name,password_hash(passwordMaterial($password),PASSWORD_DEFAULT)]);
    $id=(int)$db->lastInsertId();
    $role=$db->prepare("SELECT id FROM roles WHERE code='system_admin'"); $role->execute(); $roleId=(int)$role->fetchColumn();
    if ($roleId<1) throw new RuntimeException('Schema نقش system_admin را ندارد.');
    $grant=$db->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)'); $grant->execute([$id,$roleId]);
    auditLog($id,'admin.bootstrap.create','user',(string)$id);
    $db->commit();
    echo "مدیر سامانه ساخته شد. این حساب را به‌صورت عمومی در اختیار دیگران نگذارید.\n";
} catch (Throwable $e) {
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,"ساخت مدیر انجام نشد: ".$e->getMessage()."\n"); exit(1);
}
