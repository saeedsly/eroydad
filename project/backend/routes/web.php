<?php
declare(strict_types=1);

/**
 * مسیرهای عمومی و پنل فراگیر.
 * این فایل داخل بلوک try در index.php اجرا می‌شود و به متغیرهای $method و $path دسترسی دارد.
 */

use Eroydad\Auth\AuthService;
use Eroydad\Certificates\CertificateService;
use Eroydad\Events\EventContentService;
use Eroydad\Events\EventService;
use Eroydad\Feedback\FeedbackService;
use Eroydad\Media\PosterService;
use Eroydad\Notifications\NotificationService;
use Eroydad\Payments\PaymentService;
use Eroydad\Registration\RegistrationService;
use Eroydad\Security\Ics;
use Eroydad\Security\PasswordResetService;
use Eroydad\Security\TwoFactorService;
use Eroydad\Settings\UserSettingsService;
use Eroydad\Support\SupportDeskService;

// ---------- صفحهٔ اصلی و فهرست دوره‌ها ----------
if ($method === 'GET' && $path === '/') {
    render('home', ['title'=>'رویدادهای پیش‌رو','events'=>$events->featured(8)]);
    exit;
}
if ($method === 'GET' && $path === '/events') {
    $filters = [
        'q' => trim((string)($_GET['q'] ?? '')),
        'category' => trim((string)($_GET['category'] ?? '')),
        'mode' => trim((string)($_GET['mode'] ?? '')),
        'tag' => trim((string)($_GET['tag'] ?? '')),
        'from' => trim((string)($_GET['from'] ?? '')),
        'to' => trim((string)($_GET['to'] ?? '')),
        'price' => trim((string)($_GET['price'] ?? '')),
        'sort' => trim((string)($_GET['sort'] ?? 'soon')),
    ];
    if (mb_strlen($filters['q'], 'UTF-8') > 100) $filters['q'] = mb_substr($filters['q'],0,100,'UTF-8');
    if (mb_strlen($filters['category'], 'UTF-8') > 100) $filters['category'] = '';
    if (mb_strlen($filters['tag'], 'UTF-8') > 60) $filters['tag'] = '';
    $content = new EventContentService(db());
    render('events/index', [
        'title'=>'همه رویدادها',
        'events'=>$events->search($filters),
        'filters'=>$filters,
        'categories'=>$events->categories(),
        'tags'=>$content->popularTags(12),
    ]);
    exit;
}
if ($method === 'GET' && preg_match('#^/events/([a-z0-9-]+)$#', $path, $m)) {
    $event = $events->bySlug($m[1]);
    if (!$event) { http_response_code(404); render('error', ['title'=>'رویداد پیدا نشد','message'=>'این رویداد وجود ندارد یا منتشر نشده است.']); exit; }
    $content = new EventContentService(db());
    render('events/show', [
        'title'=>$event['title'],
        'event'=>$event,
        'sessions'=>$content->sessions((int)$event['id']),
        'tags'=>$content->tags((int)$event['id']),
        'instructor'=>['name'=>$event['instructor_name'] ?? '','bio'=>$event['instructor_bio'] ?? ''],
    ]);
    exit;
}
if ($method === 'POST' && preg_match('#^/events/(\d+)/register$#', $path, $m)) {
    $user = requireAuth();
    $eventId = (int)$m[1];
    $back = '/account';
    try {
        $result = (new RegistrationService(db()))->register($eventId, (int)$user['id'], (string)($_POST['idempotency_key'] ?? ''));
        setFlash('success', $result['message']);
        if ($result['status'] === 'pending_payment' && (int)$result['payment_id'] > 0) {
            redirect('/payments/' . (int)$result['payment_id'] . '/sandbox');
        }
        redirect('/account');
    } catch (DomainException $e) {
        setFlash('error', $e->getMessage());
        $slug = db()->prepare('SELECT slug FROM events WHERE id=?');
        $slug->execute([$eventId]);
        $eventSlug = (string)$slug->fetchColumn();
        redirect($eventSlug !== '' ? '/events/' . $eventSlug : $back);
    }
}
if ($method === 'GET' && preg_match('#^/events/([a-z0-9-]+)/poster$#', $path, $m)) {
    $event = $events->bySlug($m[1]);
    if (!$event || empty($event['poster_path'])) { http_response_code(404); exit; }
    $posters = new PosterService(db());
    $file = $posters->absolutePath((string)$event['poster_path']);
    if ($file === null) { http_response_code(404); exit; }
    header('Content-Type: ' . (string)($event['poster_mime'] ?: 'image/jpeg'));
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: public, max-age=86400');
    header('Content-Disposition: inline; filename="poster-' . (int)$event['id'] . '"');
    readfile($file);
    exit;
}
if ($method === 'GET' && preg_match('#^/preview/([a-f0-9]{16,64})$#', $path, $m)) {
    $content = new EventContentService(db());
    $event = $content->byPreviewToken($m[1]);
    if (!$event) { http_response_code(404); render('error', ['title'=>'پیش‌نمایش پیدا نشد','message'=>'این نشانی پیش‌نمایش معتبر نیست.']); exit; }
    render('events/show', ['title'=>$event['title'],'event'=>$event,'preview'=>true,'sessions'=>$content->sessions((int)$event['id']),'tags'=>$content->tags((int)$event['id'])]);
    exit;
}
if ($method === 'GET' && $path === '/terms') {
    render('terms', ['title'=>'شرایط استفاده و مالکیت']);
    exit;
}

// ---------- احراز هویت ----------
if ($method === 'GET' && $path === '/register') { render('auth/register',['title'=>'ساخت حساب']); exit; }
if ($method === 'POST' && $path === '/register') {
    $phone = normalizePhone((string)($_POST['phone'] ?? ''));
    $limiter = new \Eroydad\Security\RateLimiter(db(), appConfig()['app']['app_key']);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'local');
    try {
        $limiter->assertRegistrationAllowed($phone, $ip);
        $limiter->recordRegistration($phone, $ip);
        $id = $auth->register((string)($_POST['full_name'] ?? ''), $phone, (string)($_POST['password'] ?? ''), (string)($_POST['account_type'] ?? 'public'), (string)($_POST['national_id'] ?? ''), null);
        session_regenerate_id(true);
        $_SESSION['_created_at'] = time();
        $_SESSION['_last_activity'] = time();
        unset($_SESSION['_csrf']);
        $_SESSION['user_id'] = $id;
        $_SESSION['session_version'] = 1;
        setFlash('success','حساب ساخته شد. شماره موبایل تا زمان فعال‌شدن OTP تاییدنشده است.');
        redirect('/account');
    } catch (DomainException $e) {
        setFlash('error',$e->getMessage());
        redirect('/register');
    }
}
if ($method === 'GET' && $path === '/login') {
    $next=(string)($_GET['next']??'');
    if (!preg_match('#^/(events|account)(/[a-z0-9-]{1,180})?$#',$next)) $next='';
    render('auth/login',['title'=>'ورود','next'=>$next]); exit;
}
if ($method === 'POST' && $path === '/login') {
    try {
        $auth->login((string)($_POST['phone'] ?? ''),(string)($_POST['password'] ?? ''),(string)($_SERVER['REMOTE_ADDR'] ?? 'local'),(string)($_POST['two_factor_code'] ?? ''));
        setFlash('success','با موفقیت وارد شدید.');
        $next=(string)($_POST['next']??'');
        if (preg_match('#^/(events|account)(/[a-z0-9-]{1,180})?$#',$next)) redirect($next);
        redirect('/account');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); redirect('/login'); }
}
if ($method === 'POST' && $path === '/logout') { $auth->logout(); redirect('/'); }

// ---------- پنل فراگیر ----------
if ($method === 'GET' && $path === '/account') {
    $user = requireAuth();
    $stmt = db()->prepare("SELECT r.id,r.status,r.registered_at,r.waitlist_position,e.title,e.slug,e.starts_at_utc,e.ends_at_utc,e.venue,e.price_toman,e.tax_percent,e.tax_payer,t.id AS ticket_id,t.status AS ticket_status FROM registrations r JOIN events e ON e.id=r.event_id LEFT JOIN tickets t ON t.registration_id=r.id WHERE r.user_id=? ORDER BY r.registered_at DESC LIMIT 100");
    $stmt->execute([(int)$user['id']]);
    $notifications = new NotificationService(db());
    $reminders = new NotificationService(db());
    $reminders->createEventReminders(24);
    render('account/index',[
        'title'=>'پنل فراگیر','user'=>$user,'registrations'=>$stmt->fetchAll(),
        'unread'=>$notifications->unreadCount((int)$user['id']),
        'certificates'=>(new CertificateService(db()))->forUser((int)$user['id']),
    ]);
    exit;
}
if ($method === 'POST' && preg_match('#^/account/registrations/(\d+)/cancel$#',$path,$m)) {
    $user = requireAuth();
    try {
        (new RegistrationService(db()))->cancelByUser((int)$m[1],(int)$user['id']);
        setFlash('success','ثبت‌نام لغو شد و ظرفیت آزاد گردید.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/account');
}
if ($method === 'GET' && $path === '/account/calendar.ics') {
    $user = requireAuth();
    $stmt = db()->prepare("SELECT e.title,e.description,e.venue,e.starts_at_utc,e.ends_at_utc,e.slug FROM registrations r JOIN events e ON e.id=r.event_id WHERE r.user_id=? AND r.status='confirmed' AND e.ends_at_utc >= UTC_TIMESTAMP() ORDER BY e.starts_at_utc ASC LIMIT 100");
    $stmt->execute([(int)$user['id']]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'uid'=>'event-'.$row['slug'], 'title'=>$row['title'], 'description'=>mb_substr((string)$row['description'],0,300,'UTF-8'),
            'location'=>$row['venue'], 'start'=>$row['starts_at_utc'], 'end'=>$row['ends_at_utc'],
            'url'=>appConfig()['app']['base_url'] . '/events/' . $row['slug'],
        ];
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="erooydad-calendar.ics"');
    echo Ics::build($items, 'دوره‌های ای‌رویداد');
    exit;
}
if ($method === 'GET' && $path === '/notifications') {
    $user = requireAuth();
    $service = new NotificationService(db());
    render('notifications/index',['title'=>'اعلان‌ها','notifications'=>$service->forUser((int)$user['id'],80),'unread'=>$service->unreadCount((int)$user['id'])]);
    exit;
}
if ($method === 'POST' && $path === '/notifications/read') {
    $user = requireAuth();
    (new NotificationService(db()))->markRead((int)$user['id'], isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : null);
    setFlash('success','اعلان‌ها خوانده‌شده علامت خورد.');
    redirect('/notifications');
}
if ($method === 'GET' && $path === '/account/settings') {
    $user = requireAuth();
    $settings = new UserSettingsService(db());
    render('account/settings',['title'=>'تنظیمات نمایش','user'=>$user,'settings'=>$settings->get((int)$user['id']),'scales'=>UserSettingsService::SCALES]);
    exit;
}
if ($method === 'POST' && $path === '/account/settings') {
    $user = requireAuth();
    try {
        (new UserSettingsService(db()))->save((int)$user['id'], (string)($_POST['theme'] ?? 'auto'), (int)($_POST['font_scale'] ?? 100));
        setFlash('success','تنظیمات نمایش ذخیره شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/account/settings');
}
if ($method === 'GET' && $path === '/account/organization') {
    $user = requireAuth();
    $stmt = db()->prepare('SELECT * FROM organization_profiles WHERE user_id=? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    render('account/organization',['title'=>'اطلاعات سازمانی','user'=>$user,'profile'=>$stmt->fetch() ?: []]);
    exit;
}
if ($method === 'POST' && $path === '/account/organization') {
    $user = requireAuth();
    $name = trim((string)($_POST['organization_name'] ?? ''));
    if (mb_strlen($name,'UTF-8') < 2 || mb_strlen($name,'UTF-8') > 190) {
        setFlash('error','نام سازمان باید بین ۲ تا ۱۹۰ نویسه باشد.'); redirect('/account/organization');
    }
    $stmt = db()->prepare('INSERT INTO organization_profiles (user_id, organization_name, national_id, address, contact_email, contact_phone) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE organization_name=VALUES(organization_name), national_id=VALUES(national_id), address=VALUES(address), contact_email=VALUES(contact_email), contact_phone=VALUES(contact_phone)');
    $stmt->execute([
        (int)$user['id'], $name,
        trim((string)($_POST['national_id'] ?? '')),
        mb_substr(trim((string)($_POST['address'] ?? '')),0,240,'UTF-8'),
        mb_substr(trim((string)($_POST['contact_email'] ?? '')),0,160,'UTF-8'),
        normalizePhone((string)($_POST['contact_phone'] ?? '')),
    ]);
    auditLog((int)$user['id'],'user.organization.updated','user',(string)$user['id']);
    setFlash('success','اطلاعات سازمانی ذخیره شد.');
    redirect('/account/organization');
}
if ($method === 'GET' && $path === '/account/security') {
    $user = requireAuth();
    $twoFactor = new TwoFactorService(db());
    $sessions = new \Eroydad\Admin\SessionService(db());
    render('account/security',[
        'title'=>'امنیت حساب','user'=>$user,
        'two_factor'=>$twoFactor->status((int)$user['id']),
        'sessions'=>$sessions->forUser((int)$user['id']),
    ]);
    exit;
}
if ($method === 'POST' && $path === '/account/security/2fa/setup') {
    $user = requireAuth();
    $setup = (new TwoFactorService(db()))->beginSetup((int)$user['id']);
    $_SESSION['_2fa_secret'] = $setup['secret'];
    render('account/security-2fa',['title'=>'فعال‌سازی ورود دومرحله‌ای','user'=>$user,'setup'=>$setup,'two_factor'=>(new TwoFactorService(db()))->status((int)$user['id'])]);
    exit;
}
if ($method === 'POST' && $path === '/account/security/2fa/enable') {
    $user = requireAuth();
    try {
        $result = (new TwoFactorService(db()))->enable((int)$user['id'], (string)($_POST['code'] ?? ''));
        setFlash('success','ورود دومرحله‌ای فعال شد. کدهای بازیابی را در جای امن نگه دارید.');
        render('account/security-2fa-recovery',['title'=>'کدهای بازیابی','user'=>$user,'codes'=>$result['recovery_codes']]);
    } catch (DomainException $e) {
        setFlash('error',$e->getMessage());
        redirect('/account/security');
    }
    exit;
}
if ($method === 'POST' && $path === '/account/security/2fa/disable') {
    $user = requireAuth();
    try {
        (new TwoFactorService(db()))->disable((int)$user['id'], (string)($_POST['code'] ?? ''));
        setFlash('success','ورود دومرحله‌ای غیرفعال شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/account/security');
}
if ($method === 'POST' && $path === '/account/security/password') {
    $user = requireAuth();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
    $stmt->execute([(int)$user['id']]);
    $hash = (string)$stmt->fetchColumn();
    if (!password_verify(passwordMaterial($current), $hash)) {
        setFlash('error','رمز عبور فعلی صحیح نیست.'); redirect('/account/security');
    }
    if (mb_strlen($new,'UTF-8') < 12 || mb_strlen($new,'UTF-8') > 200) {
        setFlash('error','رمز عبور جدید باید بین ۱۲ تا ۲۰۰ نویسه باشد.'); redirect('/account/security');
    }
    $update = db()->prepare('UPDATE users SET password_hash=?, session_version=session_version+1 WHERE id=?');
    $update->execute([password_hash(passwordMaterial($new), PASSWORD_DEFAULT), (int)$user['id']]);
    auditLog((int)$user['id'],'security.password.changed','user',(string)$user['id']);
    setFlash('success','رمز عبور تغییر کرد و نشست‌های قبلی باطل شدند.');
    redirect('/account/security');
}
if ($method === 'POST' && $path === '/account/security/logout-all') {
    $user = requireAuth();
    $count = (new \Eroydad\Admin\SessionService(db()))->revokeAll((int)$user['id'], (int)$user['id']);
    setFlash('success','از ' . $count . ' نشست خارج شدید. دوباره وارد شوید.');
    $auth->logout();
    redirect('/login');
}
if ($method === 'GET' && $path === '/password/reset') {
    render('auth/password-reset',['title'=>'بازیابی رمز عبور']);
    exit;
}
if ($method === 'POST' && $path === '/password/reset') {
    $phone = normalizePhone((string)($_POST['phone'] ?? ''));
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $password = (string)($_POST['new_password'] ?? '');
    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE phone=? AND status=\'active\'');
        $stmt->execute([$phone]);
        $userId = (int)$stmt->fetchColumn();
        if ($userId < 1) throw new \DomainException('حسابی با این شماره پیدا نشد.');
        (new PasswordResetService(db()))->reset($userId, $code, $password);
        setFlash('success','رمز عبور تغییر کرد. اکنون وارد شوید.');
        redirect('/login');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); redirect('/password/reset'); }
}

// ---------- پرداخت آزمایشی ----------
if ($method === 'GET' && $path === '/account/payments') {
    $user = requireAuth();
    $stmt = db()->prepare('SELECT p.*, e.title AS event_title, e.slug FROM payments p JOIN events e ON e.id=p.event_id WHERE p.user_id=? ORDER BY p.id DESC LIMIT 100');
    $stmt->execute([(int)$user['id']]);
    render('payments/index',['title'=>'پرداخت‌های آزمایشی','user'=>$user,'payments'=>$stmt->fetchAll()]);
    exit;
}
if ($method === 'GET' && preg_match('#^/payments/(\d+)/sandbox$#',$path,$m)) {
    $user = requireAuth();
    $service = new PaymentService(db());
    $payment = $service->payment((int)$m[1]);
    if (!$payment || (int)$payment['user_id'] !== (int)$user['id']) { http_response_code(404); render('error',['title'=>'سند پیدا نشد','message'=>'سند پرداخت موردنظر در حساب شما نیست.']); exit; }
    render('payments/sandbox',['title'=>'درگاه آزمایشی','user'=>$user,'payment'=>$payment,'scenarios'=>\Eroydad\Payments\SandboxGateway::SCENARIOS]);
    exit;
}
if ($method === 'POST' && preg_match('#^/payments/(\d+)/sandbox$#',$path,$m)) {
    $user = requireAuth();
    try {
        $result = (new PaymentService(db()))->process((int)$m[1], (int)$user['id'], (string)($_POST['scenario'] ?? 'success'));
        setFlash('success',$result['message']);
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/payments/' . (int)$m[1] . '/sandbox');
}
if ($method === 'GET' && preg_match('#^/payments/(\d+)/receipt$#',$path,$m)) {
    $user = requireAuth();
    $receipt = (new PaymentService(db()))->receipt((int)$m[1], (int)$user['id']);
    if (!$receipt) { http_response_code(404); render('error',['title'=>'رسید پیدا نشد','message'=>'رسید موردنظر در دسترس نیست.']); exit; }
    render('payments/receipt',['title'=>'رسید آزمایشی','user'=>$user,'receipt'=>$receipt]);
    exit;
}

// ---------- گواهی و بازخورد ----------
if ($method === 'GET' && preg_match('#^/certificate/([a-f0-9]{16,64})$#',$path,$m)) {
    $certificate = (new CertificateService(db()))->byCode($m[1]);
    if (!$certificate) { http_response_code(404); render('error',['title'=>'گواهی پیدا نشد','message'=>'کد گواهی معتبر نیست یا گواهی صادر نشده است.']); exit; }
    render('certificates/verify',['title'=>'استعلام گواهی','certificate'=>$certificate]);
    exit;
}
if ($method === 'GET' && preg_match('#^/feedback/(\d+)$#',$path,$m)) {
    $user = requireAuth();
    $stmt = db()->prepare('SELECT r.id, r.status, r.rating AS existing_rating, e.title, e.starts_at_utc FROM registrations r JOIN events e ON e.id=r.event_id WHERE r.id=? AND r.user_id=?');
    $stmt->execute([(int)$m[1],(int)$user['id']]);
    $registration = $stmt->fetch();
    if (!$registration) { http_response_code(404); render('error',['title'=>'ثبت‌نام پیدا نشد','message'=>'ثبت‌نام موردنظر در حساب شما نیست.']); exit; }
    $existing = (new FeedbackService(db()))->forRegistration((int)$m[1]);
    render('feedback/form',['title'=>'بازخورد دوره','registration'=>$registration,'feedback'=>$existing ?: []]);
    exit;
}
if ($method === 'POST' && preg_match('#^/feedback/(\d+)$#',$path,$m)) {
    $user = requireAuth();
    try {
        (new FeedbackService(db()))->submit((int)$m[1], (int)$user['id'], (int)($_POST['rating'] ?? 0), (string)($_POST['comment'] ?? ''));
        setFlash('success','بازخورد شما ثبت شد. سپاس از همراهی شما.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/account');
}

// ---------- پشتیبانی ----------
if ($method === 'GET' && $path === '/support') {
    $user = requireAuth();
    render('support/index',['title'=>'پشتیبانی','tickets'=>$support->forUser((int)$user['id'])]);
    exit;
}
if ($method === 'POST' && $path === '/support') {
    $user = requireAuth();
    try {
        $eventId = isset($_POST['event_id']) && $_POST['event_id'] !== '' ? (int)$_POST['event_id'] : null;
        $id = $support->create((int)$user['id'],$eventId,(string)($_POST['subject'] ?? ''),(string)($_POST['body'] ?? ''));
        setFlash('success','درخواست پشتیبانی ثبت شد.'); redirect('/support/' . $id);
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); redirect('/support'); }
}
if ($method === 'GET' && preg_match('#^/support/(\d+)$#',$path,$m)) {
    $user = requireAuth();
    try { $messages=$support->messages((int)$m[1],(int)$user['id'],false); }
    catch (DomainException $e) { http_response_code(404); render('error',['title'=>'تیکت پیدا نشد','message'=>$e->getMessage()]); exit; }
    $stmt = db()->prepare('SELECT st.*, e.title AS event_title FROM support_tickets st LEFT JOIN events e ON e.id=st.event_id WHERE st.id=?');
    $stmt->execute([(int)$m[1]]);
    render('support/detail',['title'=>'پیگیری تیکت','ticketId'=>(int)$m[1],'messages'=>$messages,'ticket'=>$stmt->fetch() ?: []]); exit;
}
if ($method === 'POST' && preg_match('#^/support/(\d+)/reply$#',$path,$m)) {
    $user = requireAuth();
    try { $support->reply((int)$m[1],(int)$user['id'],(string)($_POST['body'] ?? ''),false); setFlash('success','پیام شما ثبت شد.'); }
    catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/support/' . (int)$m[1]);
}
if ($method === 'POST' && preg_match('#^/support/(\d+)/rate$#',$path,$m)) {
    $user = requireAuth();
    try {
        (new SupportDeskService(db()))->rate((int)$m[1],(int)$user['id'],(int)($_POST['rating'] ?? 0),(string)($_POST['comment'] ?? ''));
        setFlash('success','امتیاز رضایت شما ثبت شد.');
    } catch (DomainException $e) { setFlash('error',$e->getMessage()); }
    redirect('/support/' . (int)$m[1]);
}

// ---------- بلیت ----------
if ($method === 'GET' && preg_match('#^/tickets/(\d+)/print$#',$path,$m)) {
    $user = requireAuth();
    $ticket = $tickets->forUser((int)$m[1],(int)$user['id']);
    if (!$ticket) { http_response_code(404); render('error',['title'=>'بلیت پیدا نشد','message'=>'بلیت در حساب شما پیدا نشد.']); exit; }
    $token = $tickets->token($ticket);
    render('tickets/print',['title'=>'بلیت رویداد','ticket'=>$ticket,'token'=>$token,'printMode'=>true]);
    exit;
}
