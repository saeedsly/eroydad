<?php
declare(strict_types=1);

/**
 * مسیرهای مدیریتی (نقش‌محور). این فایل داخل بلوک try در index.php اجرا می‌شود.
 */


use Eroydad\Admin\AdministrationService;
use Eroydad\Admin\AuditService;
use Eroydad\Admin\BackupService;
use Eroydad\Admin\HealthService;
use Eroydad\Admin\RetentionService;
use Eroydad\Admin\RoleService;
use Eroydad\Admin\SessionService;
use Eroydad\Certificates\CertificateService;
use Eroydad\Employees\EmployeeImportService;
use Eroydad\Events\EventContentService;
use Eroydad\Feedback\FeedbackService;
use Eroydad\Payments\PaymentService;
use Eroydad\Registration\RegistrationService;
use Eroydad\Reports\ExportService;
use Eroydad\Reports\ImportService;
use Eroydad\Reports\ReportService;
use Eroydad\Security\PasswordResetService;
use Eroydad\Support\SupportDeskService;

$isSystemAdmin = static function (array $user): bool { return hasRole($user, 'system_admin'); };

// ---------- نمای کلی ----------
if ($method === 'GET' && $path === '/admin') {
    $user = requireRole('event_admin','system_admin');
    $reports = new ReportService(db());
    $trend = $reports->registrationTrend(0, 0);
    $stats = [
        'events'=>(int)db()->query("SELECT COUNT(*) FROM events WHERE status='published'")->fetchColumn(),
        'attendees'=>(int)db()->query("SELECT COUNT(*) FROM registrations WHERE status='confirmed'")->fetchColumn(),
        'support'=>(int)db()->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")->fetchColumn(),
        'waitlisted'=>(int)db()->query("SELECT COUNT(*) FROM registrations WHERE status='waitlisted'")->fetchColumn(),
        'pending_payments'=>(int)db()->query("SELECT COUNT(*) FROM registrations WHERE status='pending_payment'")->fetchColumn(),
    ];
    $daily = [];
    for ($i = 13; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
        $count = (int)db()->query("SELECT COUNT(*) FROM registrations WHERE DATE(registered_at)='" . $day . "'")->fetchColumn();
        $daily[] = ['date'=>$day,'label'=>jalaliDate($day.' 00:00:00','Y/m/d'),'count'=>$count];
    }
    render('admin/dashboard',['title'=>'مدیریت سامانه','stats'=>$stats,'user'=>$user,'daily'=>$daily,'support_stats'=>$reports->supportStats()]);
    exit;
}

// ---------- رویدادها ----------
if ($method === 'GET' && $path === '/admin/events') {
    $user=requireRole('event_admin','system_admin');
    render('admin/events',['title'=>'مدیریت رویدادها','events'=>$events->allForAdmin((int)$user['id'],hasRole($user,'system_admin'))]); exit;
}
if ($method === 'GET' && $path === '/admin/events/new') {
    requireRole('event_admin','system_admin'); render('admin/event-form',['title'=>'ساخت رویداد']); exit;
}
if ($method === 'POST' && $path === '/admin/events/new') {
    $user = requireRole('event_admin','system_admin');
    try {
        $id=$events->create((int)$user['id'],$_POST);
        $content = new EventContentService(db());
        if (isset($_POST['tags'])) $content->setTags($id, (string)$_POST['tags']);
        setFlash('success','پیش‌نویس رویداد ساخته شد؛ برای نمایش عمومی آن را منتشر کنید.');
        redirect('/admin/events/' . $id);
    }
    catch (DomainException $e) { setFlash('error',$e->getMessage()); redirect('/admin/events/new'); }
}
if ($method === 'GET' && preg_match('#^/admin/events/(\d+)/edit$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
    if (!$event || !in_array($event['status'],['draft','published'],true)) { http_response_code(404); render('error',['title'=>'قابل ویرایش نیست','message'=>'رویداد پیدا نشد یا قابل ویرایش نیست.']); exit; }
    $content = new EventContentService(db());
    render('admin/event-form',['title'=>'ویرایش رویداد','event'=>$event,'tags'=>$content->tags((int)$event['id'])]); exit;
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/edit$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try {
        $events->update((int)$m[1],(int)$user['id'],$_POST,hasRole($user,'system_admin'));
        $content = new EventContentService(db());
        if (isset($_POST['tags'])) $content->setTags((int)$m[1], (string)$_POST['tags']);
        setFlash('success','تغییرات رویداد ذخیره شد.');
    }
    catch (DomainException $e) { setFlash('error',$e->getMessage()); redirect('/admin/events/' . (int)$m[1] . '/edit'); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'GET' && preg_match('#^/admin/events/(\d+)$#',$path,$m)) {
    $id=(int)$m[1];
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin($id,(int)$user['id'],hasRole($user,'system_admin'));
    if (!$event) { http_response_code(404); render('error',['title'=>'رویداد پیدا نشد','message'=>'رویداد پیدا نشد.']); exit; }
    $content = new EventContentService(db());
    $payments = new PaymentService(db());
    $feedback = new FeedbackService(db());
    $certificates = new CertificateService(db());
    render('admin/event-detail',[
        'title'=>'مدیریت رویداد','event'=>$event,
        'attendees'=>$events->attendees($id,(int)$user['id'],hasRole($user,'system_admin')),
        'sessions'=>$content->sessions($id),
        'tags'=>$content->tags($id),
        'attachments'=>$content->attachments($id),
        'finance'=>$payments->eventSummary($id),
        'payments'=>$payments->forEvent($id),
        'feedback'=>$feedback->summaryForEvent($id),
        'certificate_pending'=>$certificates->pendingForEvent($id),
        'preview_token'=>$content->ensurePreviewToken($id),
        'live'=>$events->liveCount($id),
    ]);
    exit;
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/publish$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try { $events->publish((int)$m[1],(int)$user['id'],hasRole($user,'system_admin')); setFlash('success','رویداد منتشر شد.'); }
    catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/cancel$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try { $events->cancel((int)$m[1],(int)$user['id'],hasRole($user,'system_admin')); setFlash('success','رویداد لغو شد.'); }
    catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/poster$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try {
        $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
        if (!$event) throw new DomainException('رویداد پیدا نشد.');
        if (($_FILES['poster']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('فایل پوستر انتخاب نشد.');
        (new EventContentService(db()))->attachPoster((int)$m[1], $_FILES['poster'], (string)($_POST['poster_alt'] ?? ''), (int)$user['id']);
        setFlash('success','پوستر دوره ذخیره شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    catch (Throwable $e) { setFlash('error','ذخیرهٔ پوستر ناموفق بود.'); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/poster/delete$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try {
        $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
        if (!$event) throw new DomainException('رویداد پیدا نشد.');
        (new EventContentService(db()))->removePoster((int)$m[1],(int)$user['id']);
        setFlash('success','پوستر حذف شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/sessions$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try {
        $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
        if (!$event) throw new DomainException('رویداد پیدا نشد.');
        (new EventContentService(db()))->addSession((int)$m[1], $_POST);
        setFlash('success','جلسه افزوده شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/sessions/(\d+)/delete$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
    if ($event) { (new EventContentService(db()))->deleteSession((int)$m[2],(int)$m[1]); setFlash('success','جلسه حذف شد.'); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/attachments$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try {
        $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
        if (!$event) throw new DomainException('رویداد پیدا نشد.');
        (new EventContentService(db()))->addAttachment((int)$m[1], $_FILES['attachment'] ?? [], (string)($_POST['title'] ?? ''), (int)$user['id']);
        setFlash('success','پیوست افزوده شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    catch (Throwable $e) { setFlash('error','افزودن پیوست ناموفق بود.'); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/attachments/(\d+)/delete$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
    if ($event) { (new EventContentService(db()))->deleteAttachment((int)$m[2],(int)$m[1]); setFlash('success','پیوست حذف شد.'); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/attendees/(\d+)/cancel$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
    if (!$event) { http_response_code(404); render('error',['title'=>'رویداد پیدا نشد','message'=>'رویداد پیدا نشد.']); exit; }
    try {
        $stmt = db()->prepare('SELECT user_id FROM registrations WHERE id=? AND event_id=?');
        $stmt->execute([(int)$m[2],(int)$m[1]]);
        $owner = (int)$stmt->fetchColumn();
        if ($owner < 1) throw new DomainException('ثبت‌نام پیدا نشد.');
        (new RegistrationService(db()))->cancelByUser((int)$m[2], $owner);
        auditLog((int)$user['id'],'event.attendee.cancelled','registration',(string)(int)$m[2],['event_id'=>(int)$m[1]]);
        setFlash('success','ثبت‌نام لغو و ظرفیت آزاد شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/events/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/events/(\d+)/certificates$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
    if (!$event) { http_response_code(404); render('error',['title'=>'رویداد پیدا نشد','message'=>'رویداد پیدا نشد.']); exit; }
    $count = (new CertificateService(db()))->issueForEvent((int)$m[1], (int)$user['id']);
    setFlash('success',$count . ' گواهی صادر شد.');
    redirect('/admin/events/' . (int)$m[1] . '/certificates/print');
}
if ($method === 'GET' && preg_match('#^/admin/events/(\d+)/certificates/print$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
    if (!$event) { http_response_code(404); render('error',['title'=>'رویداد پیدا نشد','message'=>'رویداد پیدا نشد.']); exit; }
    $stmt = db()->prepare('SELECT c.*, u.full_name FROM certificates c JOIN users u ON u.id=c.user_id WHERE c.event_id=? ORDER BY c.id ASC');
    $stmt->execute([(int)$m[1]]);
    render('admin/certificates-print',['title'=>'چاپ گروهی گواهی','event'=>$event,'certificates'=>$stmt->fetchAll()]);
    exit;
}
if ($method === 'GET' && preg_match('#^/admin/events/(\d+)/live-count\.json$#',$path,$m)) {
    requireRole('event_admin','system_admin');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($events->liveCount((int)$m[1]), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method === 'GET' && preg_match('#^/admin/events/(\d+)/attendees\.csv$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin'); $isSystemAdmin=hasRole($user,'system_admin');
    if(!$events->forAdmin((int)$m[1],(int)$user['id'],$isSystemAdmin)){http_response_code(404);render('error',['title'=>'رویداد پیدا نشد','message'=>'رویداد پیدا نشد.']);exit;}
    $rows=$events->attendees((int)$m[1],(int)$user['id'],$isSystemAdmin);
    auditLog((int)$user['id'],'event.attendees.export','event',(string)(int)$m[1],['rows'=>count($rows)]);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="event-' . (int)$m[1] . '-attendees.csv"');
    $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['نام','موبایل','نوع ثبت‌نام','زمان ثبت‌نام UTC','زمان ورود UTC']);
    foreach($rows as $r) fputcsv($out,[csvSafe($r['full_name']),csvSafe($r['phone']),$r['account_type']==='municipal'?'کارمند شهرداری':'عمومی',$r['registered_at'],$r['checked_at']]);
    fclose($out); exit;
}
if ($method === 'GET' && preg_match('#^/admin/events/(\d+)/finance$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $event=$events->forAdmin((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
    if (!$event) { http_response_code(404); render('error',['title'=>'رویداد پیدا نشد','message'=>'رویداد پیدا نشد.']); exit; }
    $payments = new PaymentService(db());
    render('admin/finance',['title'=>'گزارش مالی آزمایشی','event'=>$event,'summary'=>$payments->eventSummary((int)$m[1]),'payments'=>$payments->forEvent((int)$m[1]),'ledger'=>$payments->ledger(300,(int)$m[1])]);
    exit;
}

// ---------- خروجی‌های Excel ----------
if ($method === 'GET' && preg_match('#^/admin/exports/([a-z]+)\.xlsx$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    $type=$m[1];
    $export=new ExportService(db());
    $mask = !$isSystemAdmin($user);
    $rows=[]; $headers=[]; $sheet='گزارش';
    switch ($type) {
        case 'events':
            $list=$events->allForAdmin((int)$user['id'],$isSystemAdmin($user));
            $headers=$export->eventsHeaders(); $rows=$export->eventsRows($list,$mask); $sheet='رویدادها'; break;
        case 'registrations':
            $stmt=db()->prepare("SELECT r.id AS registration_id,u.full_name,u.phone,r.account_type,r.status,r.registered_at,ci.checked_at,e.title AS event_title FROM registrations r JOIN users u ON u.id=r.user_id JOIN events e ON e.id=r.event_id LEFT JOIN tickets t ON t.registration_id=r.id LEFT JOIN checkins ci ON ci.ticket_id=t.id WHERE e.created_by=? OR ?=1 ORDER BY r.id DESC LIMIT 5000");
            $stmt->execute([(int)$user['id'],$isSystemAdmin($user)?1:0]);
            $headers=$export->registrationsHeaders(); $rows=$export->registrationsRows($stmt->fetchAll(),$mask); $sheet='ثبت‌نام‌ها'; break;
        case 'users':
            requireRole('system_admin');
            $headers=$export->usersHeaders(); $rows=$export->usersRows((new RoleService(db()))->userRoles()); $sheet='کاربران'; break;
        case 'support':
            requireRole('support','system_admin');
            $tickets=(new SupportDeskService(db()))->listTickets([],1000);
            $headers=$export->supportHeaders(); $rows=$export->supportRows($tickets); $sheet='پشتیبانی'; break;
        case 'checkin':
            $reports=new ReportService(db());
            $headers=$export->checkinHeaders(); $rows=$export->checkinRows($reports->checkinReport([],2000)); $sheet='کنترل ورود'; break;
        case 'employees':
            requireRole('system_admin');
            $stmt=db()->query('SELECT a.municipality, a.created_at, a.import_batch_id AS batch_id FROM employee_allowlist a ORDER BY a.id DESC LIMIT 5000');
            $headers=$export->employeesHeaders(); $rows=$export->employeesRows($stmt->fetchAll()); $sheet='کارکنان'; break;
        case 'finance':
            $payments=(new PaymentService(db()))->ledger(2000);
            $stmt=db()->query('SELECT p.*, e.title AS event_title, u.full_name FROM payments p JOIN events e ON e.id=p.event_id JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 5000');
            $headers=$export->financeHeaders(); $rows=$export->financeRows($stmt->fetchAll()); $sheet='مالی آزمایشی'; break;
        default:
            http_response_code(404); render('error',['title'=>'خروجی پیدا نشد','message'=>'نوع خروجی معتبر نیست.']); exit;
    }
    $binary=$export->generic($sheet,$headers,$rows);
    auditLog((int)$user['id'],'report.export.excel','report',$type,['rows'=>count($rows),'masked'=>$mask]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="erooydad-'.$type.'-'.gmdate('Ymd-His').'.xlsx"');
    header('Content-Length: '.strlen($binary));
    echo $binary;
    exit;
}

// ---------- ورود اطلاعات ----------
if ($method === 'GET' && $path === '/admin/import') {
    requireRole('event_admin','system_admin');
    render('admin/import',['title'=>'ورود اطلاعات','events'=>$events->allForAdmin((int)requireAuth()['id'],$isSystemAdmin(requireAuth()))]);
    exit;
}
if ($method === 'POST' && $path === '/admin/import/preview') {
    $user=requireRole('event_admin','system_admin');
    try {
        $target=(string)($_POST['target'] ?? 'attendees');
        if ($target==='employees') requireRole('system_admin');
        if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new DomainException('فایل انتخاب نشد.');
        $service=new ImportService(db(),appConfig());
        $preview=$service->preview((string)$_FILES['file']['tmp_name'],(string)($_FILES['file']['name'] ?? 'file.csv'),$target);
        $preview['tmp']=(string)$_FILES['file']['tmp_name'];
        $preview['original']=(string)($_FILES['file']['name'] ?? 'file.csv');
        render('admin/import-preview',['title'=>'پیش‌نمایش ورود اطلاعات','preview'=>$preview,'target'=>$target]);
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); redirect('/admin/import'); }
    exit;
}
if ($method === 'POST' && $path === '/admin/import/commit') {
    $user=requireRole('event_admin','system_admin');
    try {
        $target=(string)($_POST['target'] ?? 'attendees');
        if ($target==='employees') requireRole('system_admin');
        $tmp=(string)($_POST['tmp'] ?? '');
        if ($tmp==='' || !is_file($tmp)) throw new DomainException('فایل پیش‌نمایش در دسترس نیست؛ دوباره بارگذاری کنید.');
        $service=new ImportService(db(),appConfig());
        $preview=$service->preview($tmp,(string)($_POST['original'] ?? 'file.csv'),$target);
        if ($preview['errors']!==[]) throw new DomainException(count(array_unique(array_column($preview['errors'],'row'))).' ردیف نامعتبر است؛ هیچ داده‌ای وارد نشد.');
        if ($target==='employees') {
            $result=$service->commitEmployees($preview['rows'],(int)$user['id']);
            setFlash('success','ورود فهرست کارکنان انجام شد. معتبر: '.$result['accepted'].'، ردشده: '.$result['rejected']);
        } else {
            $eventId=(int)($_POST['event_id'] ?? 0);
            $event=$events->forAdmin($eventId,(int)$user['id'],$isSystemAdmin($user));
            if (!$event) throw new DomainException('دوره انتخاب‌شده معتبر نیست.');
            $result=$service->commitAttendees($eventId,$preview['rows'],(int)$user['id']);
            $note='ورود انجام شد: '.$result['imported'].' ردیف ثبت‌نام شد ('.$result['created_users'].' حساب تازه).';
            if ((int)($result['skipped'] ?? 0) > 0) $note .= '، '.$result['skipped'].' ردیف تکراری رد شد.';
            setFlash('success',$note);
        }
        @unlink($tmp);
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    catch (Throwable $e) { setFlash('error','ورود اطلاعات ناموفق بود و تراکنش بازگردانده شد.'); }
    redirect('/admin/import');
}

// ---------- گزارش‌ها ----------
if ($method === 'GET' && $path === '/admin/reports') {
    $user=requireRole('event_admin','system_admin');
    $reports=new ReportService(db());
    $from=trim((string)($_GET['from'] ?? gmdate('Y-m-d', strtotime('-30 days'))));
    $to=trim((string)($_GET['to'] ?? gmdate('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from=gmdate('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=gmdate('Y-m-d');
    render('admin/reports',[
        'title'=>'گزارش‌های مدیریتی','user'=>$user,
        'summary'=>$reports->managementReport($from,$to),
        'from'=>$from,'to'=>$to,
        'support_stats'=>$reports->supportStats(),
        'employees'=>$reports->employeeReconciliation(),
        'event_options'=>$reports->eventOptions(),
    ]);
    exit;
}
if ($method === 'GET' && $path === '/admin/reports/checkin') {
    requireRole('event_admin','system_admin');
    $reports=new ReportService(db());
    render('admin/checkin-report',['title'=>'گزارش کنترل ورود','rows'=>$reports->checkinReport(['event_id'=>(int)($_GET['event_id']??0),'q'=>(string)($_GET['q']??'')],500),'event_options'=>$reports->eventOptions(),'filters'=>['event_id'=>(int)($_GET['event_id']??0),'q'=>(string)($_GET['q']??'')]]);
    exit;
}
if ($method === 'GET' && $path === '/admin/rate-limits') {
    requireRole('system_admin');
    $login=(int)db()->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)")->fetchColumn();
    $failed=(int)db()->query("SELECT COUNT(*) FROM login_attempts WHERE was_successful=0 AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)")->fetchColumn();
    $reg=(int)db()->query("SELECT COUNT(*) FROM registration_attempts WHERE attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)")->fetchColumn();
    $top=db()->query("SELECT phone_hmac, COUNT(*) AS total, SUM(CASE WHEN was_successful=0 THEN 1 ELSE 0 END) AS failed FROM login_attempts WHERE attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) GROUP BY phone_hmac ORDER BY total DESC LIMIT 10")->fetchAll();
    render('admin/rate-limits',['title'=>'محدودسازی درخواست‌ها','login'=>$login,'failed'=>$failed,'registration'=>$reg,'top'=>$top]);
    exit;
}

// ---------- نقش‌ها، مجوزها و کاربران ----------
if ($method === 'GET' && $path === '/admin/roles') {
    requireRole('system_admin');
    render('admin/roles',['title'=>'ماتریس نقش و مجوز','matrix'=>(new RoleService(db()))->matrix()]);
    exit;
}
if ($method === 'POST' && $path === '/admin/roles') {
    $user=requireRole('system_admin');
    try {
        $service=new RoleService(db());
        $service->setPermission((string)($_POST['role'] ?? ''),(string)($_POST['permission'] ?? ''),!empty($_POST['enabled']),(int)$user['id']);
        setFlash('success','مجوز به‌روزرسانی شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/roles');
}
if ($method === 'POST' && $path === '/admin/roles/reset') {
    $user=requireRole('system_admin');
    $count=(new RoleService(db()))->resetToDefaults((int)$user['id']);
    setFlash('success','نگاشت پیش‌فرض مجوزها بازیابی شد ('.$count.' مورد).');
    redirect('/admin/roles');
}
if ($method === 'GET' && $path === '/admin/users') {
    $user=requireRole('system_admin');
    $administration=new AdministrationService(db());
    render('admin/users',['title'=>'مدیریت کاربران','users'=>$administration->users(),'user'=>$user,'roles'=>(new RoleService(db()))->roles(),'role_users'=>(new RoleService(db()))->userRoles()]);
    exit;
}
if ($method === 'POST' && preg_match('#^/admin/users/(\d+)/(suspend|activate)$#',$path,$m)) {
    $user=requireRole('system_admin');
    $administration=new AdministrationService(db());
    try {
        $administration->changeStatus((int)$m[1],(int)$user['id'],$m[2]==='suspend'?'suspended':'active');
        setFlash('success',$m[2]==='suspend'?'حساب تعلیق شد و نشست‌های فعال آن باطل شدند.':'حساب دوباره فعال شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/users');
}
if ($method === 'POST' && preg_match('#^/admin/users/(\d+)/role$#',$path,$m)) {
    $user=requireRole('system_admin');
    try {
        (new RoleService(db()))->assignRole((int)$m[1],(string)($_POST['role'] ?? ''),!empty($_POST['grant']),(int)$user['id']);
        setFlash('success','نقش کاربر به‌روزرسانی شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/users');
}
if ($method === 'GET' && $path === '/admin/sessions') {
    requireRole('system_admin');
    $stmt=db()->query('SELECT s.id, s.user_id, u.full_name, u.phone, s.ip, s.user_agent, s.created_at, s.last_seen_at, s.revoked_at FROM user_sessions s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 200');
    render('admin/sessions',['title'=>'نشست‌های فعال','sessions'=>$stmt->fetchAll()]);
    exit;
}
if ($method === 'POST' && preg_match('#^/admin/sessions/(\d+)/revoke$#',$path,$m)) {
    $user=requireRole('system_admin');
    $sessions=new SessionService(db());
    $stmt=db()->prepare('SELECT user_id FROM user_sessions WHERE id=?');
    $stmt->execute([(int)$m[1]]);
    $owner=(int)$stmt->fetchColumn();
    if ($owner>0) { $sessions->revoke((int)$m[1],$owner); setFlash('success','نشست باطل شد.'); }
    redirect('/admin/sessions');
}
if ($method === 'GET' && $path === '/admin/password-resets') {
    requireRole('system_admin');
    render('admin/password-resets',['title'=>'کدهای بازیابی رمز','pending'=>(new PasswordResetService(db()))->listPending()]);
    exit;
}
if ($method === 'POST' && $path === '/admin/password-resets') {
    $user=requireRole('system_admin');
    try {
        $result=(new PasswordResetService(db()))->generate((int)($_POST['user_id'] ?? 0),(int)$user['id'],(int)($_POST['minutes'] ?? 60));
        setFlash('success','کد بازیابی ساخته شد: '.$result['code'].' (اعتبار تا '.$result['expires_at'].' UTC)');
    } catch (Throwable $e) { setFlash('error','ساخت کد بازیابی ناموفق بود.'); }
    redirect('/admin/password-resets');
}

// ---------- گزارش فعالیت ----------
if ($method === 'GET' && $path === '/admin/audit') {
    requireRole('system_admin');
    $audit=new AuditService(db());
    $filters=['action'=>trim((string)($_GET['action']??'')),'entity'=>trim((string)($_GET['entity']??'')),'q'=>trim((string)($_GET['q']??'')),'from'=>trim((string)($_GET['from']??'')),'to'=>trim((string)($_GET['to']??''))];
    render('admin/audit',['title'=>'گزارش رویدادهای سامانه','logs'=>$audit->query($filters,300),'filters'=>$filters,'entities'=>$audit->entities(),'actions'=>$audit->actions(),'summary'=>$audit->summary(7)]);
    exit;
}

// ---------- کنترل ورود ----------
if ($method === 'GET' && $path === '/admin/check-in') {
    $user=requireRole('event_admin','system_admin');
    $options=(new ReportService(db()))->eventOptions();
    render('admin/checkin',['title'=>'کنترل ورود','events'=>$options,'tickets'=>$tickets->forEvent((int)($_GET['event_id']??0))]);
    exit;
}
if ($method === 'POST' && $path === '/admin/check-in') {
    $user=requireRole('event_admin','system_admin');
    try { if(isset($_SERVER['CONTENT_LENGTH'])&&(int)$_SERVER['CONTENT_LENGTH']>16384) throw new DomainException('درخواست بیش از اندازه بزرگ است.'); $result=$tickets->checkIn((string)($_POST['ticket_token'] ?? ''),(int)$user['id'],hasRole($user,'system_admin'),'online'); setFlash('success','ورود مجاز — ' . $result['event']); }
    catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/check-in' . (isset($_POST['event_id']) && $_POST['event_id']!=='' ? '?event_id=' . (int)$_POST['event_id'] : ''));
}
if ($method === 'POST' && $path === '/admin/check-in/sync') {
    $user=requireRole('event_admin','system_admin');
    $items=json_decode((string)($_POST['queue'] ?? '[]'),true);
    $ok=0; $failed=[]; $results=[];
    if (is_array($items)) {
        foreach ($items as $item) {
            $token=trim((string)($item['token'] ?? ''));
            $ref=(string)($item['ref'] ?? '');
            if ($token==='') continue;
            try {
                $result=$tickets->checkIn($token,(int)$user['id'],hasRole($user,'system_admin'),'offline_queue',$ref);
                $ok++;
                $results[]=['ref'=>$ref,'ok'=>true,'message'=>'ورود ثبت شد — '.$result['event']];
            } catch (Throwable $e) {
                $failed[]=$e->getMessage();
                $results[]=['ref'=>$ref,'ok'=>false,'message'=>$e->getMessage()];
            }
        }
    }
    auditLog((int)$user['id'],'checkin.queue.synced','checkin',null,['synced'=>$ok,'failed'=>count($failed)]);
    // پاسخ JSON برای درخواست‌های Ajax تا صف آفلاین تنها بر پایهٔ نتیجهٔ واقعی پاک شود.
    if (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['synced'=>$ok,'failed'=>count($failed),'results'=>$results], JSON_UNESCAPED_UNICODE);
        exit;
    }
    setFlash($failed===[]?'success':'error','همگام‌سازی صف آزمایشی آفلاین: '.$ok.' مورد ثبت شد' . ($failed===[]?'':'، '.count($failed).' مورد ناموفق بود.'));
    redirect('/admin/check-in');
}
if ($method === 'POST' && preg_match('#^/admin/tickets/(\d+)/reissue$#',$path,$m)) {
    $user=requireRole('event_admin','system_admin');
    try {
        $result=$tickets->reissue((int)$m[1],(int)$user['id'],hasRole($user,'system_admin'));
        setFlash('success','بلیت بازصدور شد. کد تازه: '.$result['public_code']);
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/events/' . (int)($_POST['event_id'] ?? 0));
}

// ---------- پشتیبانی ----------
if ($method === 'GET' && $path === '/admin/support') {
    requireRole('support','event_admin','system_admin');
    $desk=new SupportDeskService(db());
    $filters=['status'=>trim((string)($_GET['status']??'')),'priority'=>trim((string)($_GET['priority']??'')),'q'=>trim((string)($_GET['q']??'')),'assigned'=>trim((string)($_GET['assigned']??''))];
    render('admin/support',['title'=>'تیکت‌های پشتیبانی','tickets'=>$desk->listTickets($filters),'filters'=>$filters,'labels'=>$desk->labels(),'assignees'=>$desk->assignees()]); exit;
}
if ($method === 'GET' && preg_match('#^/admin/support/(\d+)$#',$path,$m)) {
    $user=requireRole('support','event_admin','system_admin');
    try { $messages=$support->messages((int)$m[1],(int)$user['id'],true); }
    catch (DomainException $e) { http_response_code(404); render('error',['title'=>'تیکت پیدا نشد','message'=>$e->getMessage()]); exit; }
    $desk=new SupportDeskService(db());
    $stmt=db()->prepare('SELECT st.*, u.full_name, u.phone, e.title AS event_title, a.full_name AS assigned_name FROM support_tickets st JOIN users u ON u.id=st.user_id LEFT JOIN events e ON e.id=st.event_id LEFT JOIN users a ON a.id=st.assigned_to WHERE st.id=?');
    $stmt->execute([(int)$m[1]]);
    render('admin/support-detail',['title'=>'پاسخ‌گویی به تیکت','ticketId'=>(int)$m[1],'messages'=>$messages,'ticket'=>$stmt->fetch() ?: [],'labels'=>$desk->labels(),'ticket_labels'=>$desk->ticketLabels((int)$m[1]),'canned'=>$desk->cannedResponses(),'assignees'=>$desk->assignees()]); exit;
}
if ($method === 'POST' && preg_match('#^/admin/support/(\d+)/reply$#',$path,$m)) {
    $user=requireRole('support','event_admin','system_admin');
    try {
        $support->reply((int)$m[1],(int)$user['id'],(string)($_POST['body'] ?? ''),true);
        (new SupportDeskService(db()))->markFirstResponse((int)$m[1],(int)$user['id']);
        setFlash('success','پاسخ ثبت شد.');
    }
    catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/support/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/admin/support/(\d+)/(priority|assign|status|label|canned)$#',$path,$m)) {
    $user=requireRole('support','event_admin','system_admin');
    $desk=new SupportDeskService(db());
    try {
        switch ($m[2]) {
            case 'priority': $desk->setPriority((int)$m[1],(string)($_POST['priority']??'normal'),(int)$user['id']); break;
            case 'assign': $desk->assign((int)$m[1],isset($_POST['assignee'])&&$_POST['assignee']!==''?(int)$_POST['assignee']:null,(int)$user['id']); break;
            case 'status': $desk->setStatus((int)$m[1],(string)($_POST['status']??'open'),(int)$user['id']); break;
            case 'label': $desk->toggleLabel((int)$m[1],(int)($_POST['label_id']??0),(int)$user['id']); break;
            case 'canned': $desk->addCannedResponse((string)($_POST['title']??''),(string)($_POST['body']??''),(int)$user['id']); break;
        }
        setFlash('success','تغییر اعمال شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/support/' . (int)$m[1]);
}

// ---------- کارکنان ----------
if ($method === 'GET' && $path === '/admin/employees') {
    requireRole('system_admin');
    render('admin/employees',['title'=>'فهرست کارکنان']); exit;
}
if ($method === 'POST' && $path === '/admin/employees/import') {
    $user=requireRole('system_admin');
    try {
        if (($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new DomainException('فایل CSV بارگذاری نشد.');
        $service=new EmployeeImportService(db(),appConfig()['app']['employee_hmac_key']);
        $result=$service->import((string)$_FILES['csv']['tmp_name'],(int)$user['id']);
        setFlash('success','فهرست پردازش شد؛ ردیف معتبر: ' . $result['accepted'] . '، ردشده: ' . $result['rejected']);
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/admin/employees');
}

// ---------- سامانه: سلامت، پشتیبان، مهاجرت، نگهداری داده ----------
if ($method === 'GET' && $path === '/admin/system') {
    requireRole('system_admin');
    $health=(new HealthService(db(),appConfig()))->run();
    $backup=new BackupService(db());
    $migrationRuns=db()->query('SELECT * FROM migration_runs ORDER BY id DESC LIMIT 30')->fetchAll();
    $applied=db()->query('SELECT version, applied_at FROM schema_migrations ORDER BY version ASC')->fetchAll();
    render('admin/system',['title'=>'سلامت و نگهداری سامانه','health'=>$health,'backups'=>$backup->list(),'migration_runs'=>$migrationRuns,'applied'=>$applied,'policies'=>(new RetentionService(db()))->policies(),'retention_history'=>(new RetentionService(db()))->history(20)]);
    exit;
}
if ($method === 'POST' && $path === '/admin/system/backup') {
    $user=requireRole('system_admin');
    try {
        $result=(new BackupService(db()))->create((int)$user['id']);
        setFlash('success','پشتیبان ساخته شد: '.$result['file'].' ('.round($result['size']/1024).' کیلوبایت).');
    } catch (Throwable $e) { setFlash('error','ساخت پشتیبان ناموفق بود: '.$e->getMessage()); }
    redirect('/admin/system');
}
if ($method === 'POST' && preg_match('#^/admin/system/backup/(\d+)/verify$#',$path,$m)) {
    $user=requireRole('system_admin');
    try {
        $result=(new BackupService(db()))->verify((int)$m[1]);
        setFlash($result['ok']?'success':'error','آزمون بازیابی: '.($result['ok']?'موفق':'ناموفق').' — '.$result['rehearsal']['detail'].' — هش: '.($result['checksum_ok']?'مطابق':'متفاوت'));
    } catch (Throwable $e) { setFlash('error','آزمون بازیابی ناموفق بود.'); }
    redirect('/admin/system');
}
if ($method === 'POST' && $path === '/admin/system/retention') {
    $user=requireRole('system_admin');
    $results=(new RetentionService(db()))->run((int)$user['id'], isset($_POST['days']) ? (int)$_POST['days'] : null);
    $total=0; foreach ($results as $result) { $total += (int)$result['deleted']; }
    setFlash('success','پاک‌سازی داده انجام شد؛ '.$total.' رکورد حذف شد.');
    redirect('/admin/system');
}
