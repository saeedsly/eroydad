<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/backend/bootstrap.php';
require PROJECT_ROOT . '/backend/src/Auth/AuthService.php';
require PROJECT_ROOT . '/backend/src/Events/EventService.php';
require PROJECT_ROOT . '/backend/src/Events/EventContentService.php';
require PROJECT_ROOT . '/backend/src/Registration/RegistrationService.php';
require PROJECT_ROOT . '/backend/src/Registration/TicketService.php';
require PROJECT_ROOT . '/backend/src/Employees/EmployeeImportService.php';
require PROJECT_ROOT . '/backend/src/Support/SupportService.php';
require PROJECT_ROOT . '/backend/src/Support/SupportDeskService.php';
require PROJECT_ROOT . '/backend/src/Admin/AdministrationService.php';
require PROJECT_ROOT . '/backend/src/Admin/RoleService.php';
require PROJECT_ROOT . '/backend/src/Admin/SessionService.php';
require PROJECT_ROOT . '/backend/src/Admin/AuditService.php';
require PROJECT_ROOT . '/backend/src/Admin/HealthService.php';
require PROJECT_ROOT . '/backend/src/Admin/BackupService.php';
require PROJECT_ROOT . '/backend/src/Admin/RetentionService.php';
require PROJECT_ROOT . '/backend/src/Certificates/CertificateService.php';
require PROJECT_ROOT . '/backend/src/Feedback/FeedbackService.php';
require PROJECT_ROOT . '/backend/src/Media/PosterService.php';
require PROJECT_ROOT . '/backend/src/Notifications/NotificationService.php';
require PROJECT_ROOT . '/backend/src/Payments/Money.php';
require PROJECT_ROOT . '/backend/src/Payments/SandboxGateway.php';
require PROJECT_ROOT . '/backend/src/Payments/PaymentService.php';
require PROJECT_ROOT . '/backend/src/Reports/ExportService.php';
require PROJECT_ROOT . '/backend/src/Reports/ImportService.php';
require PROJECT_ROOT . '/backend/src/Reports/ReportService.php';
require PROJECT_ROOT . '/backend/src/Excel/Zip.php';
require PROJECT_ROOT . '/backend/src/Excel/XlsxWriter.php';
require PROJECT_ROOT . '/backend/src/Excel/XlsxReader.php';
require PROJECT_ROOT . '/backend/src/Security/Permissions.php';
require PROJECT_ROOT . '/backend/src/Security/Totp.php';
require PROJECT_ROOT . '/backend/src/Security/Ics.php';
require PROJECT_ROOT . '/backend/src/Security/SafeText.php';
require PROJECT_ROOT . '/backend/src/Security/TwoFactorService.php';
require PROJECT_ROOT . '/backend/src/Security/PasswordResetService.php';

use Eroydad\Auth\AuthService;
use Eroydad\Events\EventService;
use Eroydad\Registration\RegistrationService;
use Eroydad\Registration\TicketService;
use Eroydad\Employees\EmployeeImportService;
use Eroydad\Support\SupportService;
use Eroydad\Admin\AdministrationService;
use Eroydad\Security\RateLimiter;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
if ($path === '//') $path = '/';
$basePath=appBasePath();
if($basePath!==''){
    if($path===$basePath)$path='/';
    elseif(str_starts_with($path,$basePath.'/'))$path=substr($path,strlen($basePath));
    else{http_response_code(404);exit('نشانی برنامه معتبر نیست.');}
}
try {
    $events = new EventService(db());
    $auth = new AuthService(db(), appConfig());
    $tickets = new TicketService(db(), appConfig());
    $support = new SupportService(db());
    if ($method === 'POST') {
        $contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);
        $limit=65536;
        if (in_array($path,['/admin/employees/import','/admin/import/preview'],true)) $limit=6291456;
        if (in_array($path,['/login','/register','/admin/check-in','/admin/check-in/sync'],true)) $limit=16384;
        if ($contentLength>$limit) { http_response_code(413); render('error',['title'=>'درخواست بزرگ است','message'=>'حجم درخواست از حد مجاز بیشتر است.']); exit; }
        verifyCsrf();
    }

    require PROJECT_ROOT . '/backend/routes/web.php';
    require PROJECT_ROOT . '/backend/routes/admin.php';

    http_response_code(404);
    render('error',['title'=>'صفحه پیدا نشد','message'=>'نشانی واردشده وجود ندارد.']);
} catch (Throwable $e) {
    error_log('Unhandled application error; request_id=' . ($GLOBALS['request_id'] ?? 'unknown') . '; type=' . get_class($e));
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    render('error',['title'=>'خطای داخلی','message'=>'در پردازش درخواست مشکلی رخ داد. شناسه پیگیری: ' . ($GLOBALS['request_id'] ?? '')]);
}

function csvSafe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/u', $value) ? "'" . $value : $value;
}
