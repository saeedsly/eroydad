<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/backend/bootstrap.php';
function readPassword(string $label): string
{
    echo $label;
    if(PHP_OS_FAMILY==='Windows') return trim((string)fgets(STDIN));
    system('stty -echo');
    try{$value=trim((string)fgets(STDIN));}finally{system('stty echo');echo PHP_EOL;}
    return $value;
}
echo 'شماره موبایل حساب: ';$phone=normalizePhone(trim((string)fgets(STDIN)));
if(!validIranMobile($phone)){fwrite(STDERR,"شماره موبایل معتبر نیست.\n");exit(1);}
$password=readPassword('رمز تازه (حداقل ۱۲ نویسه؛ در Windows هنگام تایپ دیده می‌شود): ');
$confirm=readPassword('تکرار رمز: ');
if(mb_strlen($password,'UTF-8')<12||mb_strlen($password,'UTF-8')>200||$password!==$confirm){fwrite(STDERR,"رمز نامعتبر است؛ تغییری انجام نشد.\n");exit(1);}
$db=db();$db->beginTransaction();
try{
    $find=$db->prepare('SELECT id FROM users WHERE phone=? FOR UPDATE');$find->execute([$phone]);$id=(int)$find->fetchColumn();
    if($id<1)throw new RuntimeException('حسابی با این شماره پیدا نشد.');
    $update=$db->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?');
    $update->execute([password_hash(passwordMaterial($password),PASSWORD_DEFAULT),$id]);
    auditLog(null,'account.password_reset.manual_cli','user',(string)$id,['method'=>'local-cli']);
    $db->commit();
    echo "رمز تغییر کرد و نشست‌های فعال کاربر باطل شدند.\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,"بازنشانی انجام نشد: ".$e->getMessage()."\n");exit(1);}
