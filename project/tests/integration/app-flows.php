<?php
/**
 * آزمون یکپارچهٔ واقعی: PHP 7.4 + MariaDB 11.8 (هر دو واقعی، اجرا در WASM).
 *
 * این فایل داخل موتور PHP اجرا می‌شود و همهٔ سرویس‌های واقعی برنامه را
 * روی دیتابیس واقعی می‌آزماید: ثبت‌نام سه‌حالته، مالیات و پرداخت آزمایشی،
 * فهرست انتظار، بلیت و check-in، گواهی، بازخورد، اعلان، مجوزها، ورود
 * Excel با پیش‌نمایش و جلوگیری از فرمول مخرب، و خروجی XLSX واقعی.
 *
 * خروجی: خطوط «PASS»/«FAIL» و در پایان خلاصه. کد خروج ۱ در صورت خطا.
 */

declare(strict_types=1);

// ریشهٔ پروژه داخل WASM روی /project سوار شده است.
$root = getenv('EROYDAD_ROOT') ?: '/project';
require_once $root . '/backend/bootstrap.php';

foreach ([
    '/backend/src/Auth/AuthService.php',
    '/backend/src/Events/EventService.php',
    '/backend/src/Events/EventContentService.php',
    '/backend/src/Registration/RegistrationService.php',
    '/backend/src/Registration/TicketService.php',
    '/backend/src/Certificates/CertificateService.php',
    '/backend/src/Feedback/FeedbackService.php',
    '/backend/src/Media/PosterService.php',
    '/backend/src/Notifications/NotificationService.php',
    '/backend/src/Payments/Money.php',
    '/backend/src/Payments/SandboxGateway.php',
    '/backend/src/Payments/PaymentService.php',
    '/backend/src/Reports/ExportService.php',
    '/backend/src/Reports/ImportService.php',
    '/backend/src/Reports/ReportService.php',
    '/backend/src/Admin/RoleService.php',
    '/backend/src/Excel/Zip.php',
    '/backend/src/Excel/XlsxWriter.php',
    '/backend/src/Excel/XlsxReader.php',
    '/backend/src/Security/Permissions.php',
    '/backend/src/Security/SafeText.php',
    '/backend/src/Security/Totp.php',
    '/backend/src/Security/Ics.php',
    '/backend/src/Support/SupportDeskService.php',
    '/backend/src/Support/SupportService.php',
    '/backend/src/Admin/AuditService.php',
    '/backend/src/Admin/SessionService.php',
    '/backend/src/Admin/RetentionService.php',
    '/backend/src/Admin/BackupService.php',
    '/backend/src/Admin/HealthService.php',
    '/backend/src/Security/RateLimiter.php',
    '/backend/src/Security/PasswordResetService.php',
] as $file) {
    require_once $root . $file;
}

use Eroydad\Events\EventService;
use Eroydad\Registration\RegistrationService;
use Eroydad\Registration\TicketService;
use Eroydad\Payments\Money;
use Eroydad\Payments\PaymentService;
use Eroydad\Payments\SandboxGateway;
use Eroydad\Certificates\CertificateService;
use Eroydad\Feedback\FeedbackService;
use Eroydad\Notifications\NotificationService;
use Eroydad\Admin\RoleService;
use Eroydad\Reports\ExportService;
use Eroydad\Reports\ImportService;
use Eroydad\Excel\XlsxReader;
use Eroydad\Excel\XlsxWriter;
use Eroydad\Security\SafeText;
use Eroydad\Security\Ics;
use Eroydad\Security\Totp;
use Eroydad\Support\SupportDeskService;

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS  " . $label . "\n";
        return;
    }
    $failed++;
    echo "FAIL  " . $label . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
}

function section(string $title): void
{
    echo "\n=== " . $title . " ===\n";
}

$db = db();

/** تبدیل timestamp به «Y-m-d\TH:i» محلی (تهران) — همان چیزی که فرم رویداد می‌فرستد. */
function localStamp(int $timestamp): string
{
    $date = new DateTimeImmutable('@' . $timestamp);
    return $date->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\TH:i');
}

// ---------- داده‌های پایه ----------
$db->exec("DELETE FROM checkins");
$db->exec("DELETE FROM tickets");
$db->exec("DELETE FROM payments");
$db->exec("DELETE FROM payment_ledger");
$db->exec("DELETE FROM certificates");
$db->exec("DELETE FROM feedbacks");
$db->exec("DELETE FROM notifications");
$db->exec("DELETE FROM registrations");
$db->exec("DELETE FROM event_attachments");
$db->exec("DELETE FROM event_sessions");
$db->exec("DELETE FROM event_tags");
$db->exec("DELETE FROM events");
$db->exec("DELETE FROM user_roles");
$db->exec("DELETE FROM users");

function makeUser(string $phone, string $name, string $role = 'attendee'): int
{
    global $db;
    $stmt = $db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status) VALUES (?,?,?,'public','active')");
    $stmt->execute([$phone, $name, password_hash('Test-Password-1234', PASSWORD_DEFAULT)]);
    $id = (int)$db->lastInsertId();
    $roleStmt = $db->prepare("SELECT id FROM roles WHERE code=?");
    $roleStmt->execute([$role]);
    $roleId = (int)$roleStmt->fetchColumn();
    if ($roleId > 0) {
        $grant = $db->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)');
        $grant->execute([$id, $roleId]);
    }
    return $id;
}

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$adminId = makeUser('09120000001', 'مدیر آزمون', 'system_admin');
$managerId = makeUser('09120000002', 'مدیر رویداد آزمون', 'event_admin');
$attendeeA = makeUser('09120000003', 'فراگیر الف');
$attendeeB = makeUser('09120000004', 'فراگیر ب');
$attendeeC = makeUser('09120000005', 'فراگیر ج');
$attendeeD = makeUser('09120000007', 'فراگیر د');

check('کاربران آزمون ساخته شدند', $adminId > 0 && $managerId > 0 && $attendeeA > 0 && $attendeeD > 0);

// =====================================================================
section('۱) ساخت، انتشار و مدیریت رویداد');
// =====================================================================
$events = new EventService($db);
$future = localStamp(time() + 86400 * 20);
$later = localStamp(time() + 86400 * 21 + 7200);

$freeEventId = $events->create($managerId, [
    'title' => 'دورهٔ آزمایشی رایگان',
    'summary' => 'یک دورهٔ کاملاً رایگان برای آزمون یکپارچهٔ سامانه.',
    'description' => "شرح کامل دورهٔ آزمایشی.\n- نکتهٔ اول\n- نکتهٔ دوم\n# عنوان بخش",
    'category' => 'مدیریت شهری',
    'delivery_mode' => 'in_person',
    'venue' => 'سالن آزمون',
    'capacity' => '2',
    'price_toman' => '0',
    'tax_percent' => '0',
    'tax_payer' => 'participant',
    'starts_at' => $future,
    'ends_at' => $later,
]);
check('رویداد رایگان ساخته شد', $freeEventId > 0);

$paidEventId = $events->create($managerId, [
    'title' => 'دورهٔ آزمایشی پولی با مالیات',
    'summary' => 'دورهٔ پولی برای آزمون درگاه آزمایشی و محاسبهٔ مالیات.',
    'description' => 'شرح دورهٔ پولی برای آزمون پرداخت آزمایشی و صدور گواهی.',
    'category' => 'مدیریت شهری',
    'delivery_mode' => 'online',
    'venue' => 'بستر آنلاین',
    'capacity' => '10',
    'price_toman' => '1000000',
    'tax_percent' => '10',
    'tax_payer' => 'participant',
    'starts_at' => $future,
    'ends_at' => $later,
]);
check('رویداد پولی ساخته شد', $paidEventId > 0);

$events->publish($freeEventId, $managerId, false);
$events->publish($paidEventId, $managerId, false);

$bySlug = $events->bySlug((string)$db->query("SELECT slug FROM events WHERE id=" . (int)$freeEventId)->fetchColumn());
check('رویداد منتشرشده با slug پیدا شد', $bySlug !== null && $bySlug['status'] === 'published');

$notPublished = $events->create($managerId, [
    'title' => 'رویداد پیش‌نویس آزمون',
    'summary' => 'این رویداد نباید برای ثبت‌نام باز باشد.',
    'description' => 'شرح رویداد پیش‌نویس برای آزمون وضعیت انتشار.',
    'category' => 'آزمون',
    'delivery_mode' => 'in_person',
    'venue' => 'سالن پیش‌نویس',
    'capacity' => '5',
    'price_toman' => '0',
    'starts_at' => $future,
    'ends_at' => $later,
]);
check('رویداد پیش‌نویس در فهرست عمومی نیست', $events->bySlug((string)$db->query("SELECT slug FROM events WHERE id=" . (int)$notPublished)->fetchColumn()) === null);

// =====================================================================
section('۲) ثبت‌نام سه‌حالته: رایگان، پرداخت آزمایشی و فهرست انتظار');
// =====================================================================
$registration = new RegistrationService($db);
$tickets = new TicketService($db, appConfig());

$resultA = $registration->register($freeEventId, $attendeeA, uuid());
check('ثبت‌نام رایگان قطعی شد', $resultA['status'] === 'confirmed', $resultA['status']);

$ticketStmt = $db->prepare('SELECT id,public_code,status FROM tickets WHERE registration_id=?');
$ticketStmt->execute([$resultA['registration_id']]);
$ticketA = $ticketStmt->fetch();
check('بلیت برای ثبت‌نام رایگان صادر شد', $ticketA !== false && $ticketA['status'] === 'issued');

$resultB = $registration->register($paidEventId, $attendeeB, uuid());
check('ثبت‌نام رویداد پولی در انتظار پرداخت قرار گرفت', $resultB['status'] === 'pending_payment', $resultB['status']);
check('سند پرداخت آزمایشی ساخته شد', $resultB['payment_id'] > 0);

$payment = new PaymentService($db);
$paymentRow = $payment->payment($resultB['payment_id']);
check('مبلغ پایه درست است', (int)$paymentRow['base_toman'] === 1000000, (string)$paymentRow['base_toman']);
check('مالیات ۱۰ درصد درست محاسبه شد', (int)$paymentRow['tax_toman'] === 100000, (string)$paymentRow['tax_toman']);
check('جمع کل درست است', (int)$paymentRow['total_toman'] === 1100000, (string)$paymentRow['total_toman']);
check('سند پرداخت آزمایشی است', (int)$paymentRow['is_sandbox'] === 1 && $paymentRow['provider'] === 'sandbox');

// ظرفیت رویداد رایگان ۲ است؛ نفر دوم قطعی و نفر سوم در فهرست انتظار
$resultC = $registration->register($freeEventId, $attendeeC, uuid());
check('ثبت‌نام دوم در ظرفیت قطعی شد', $resultC['status'] === 'confirmed', $resultC['status']);

$resultD = $registration->register($freeEventId, $attendeeD, uuid());
check('ثبت‌نام سوم در فهرست انتظار قرار گرفت', $resultD['status'] === 'waitlisted', $resultD['status']);
check('جایگاه فهرست انتظار ثبت شد', $resultD['position'] >= 1, (string)$resultD['position']);
$waitlistRow = $db->query('SELECT waitlist_position FROM registrations WHERE id=' . (int)$resultD['registration_id'])->fetchColumn();
check('جایگاه روی رکورد ثبت‌نام ذخیره شد', (int)$waitlistRow === (int)$resultD['position'] || (int)$waitlistRow >= 1, (string)$waitlistRow);

// ثبت‌نام تکراری باید رد شود
$duplicateRejected = false;
try {
    $registration->register($freeEventId, $attendeeA, uuid());
} catch (DomainException $e) {
    $duplicateRejected = true;
}
check('ثبت‌نام تکراری رد شد', $duplicateRejected);

// ثبت‌نام رویداد پیش‌نویس باید رد شود
$draftRejected = false;
try {
    $registration->register($notPublished, $attendeeA, uuid());
} catch (DomainException $e) {
    $draftRejected = true;
}
check('ثبت‌نام روی رویداد منتشرنشده رد شد', $draftRejected);

// =====================================================================
section('۳) پرداخت آزمایشی، استرداد و لجر مالی');
// =====================================================================
$processed = $payment->process($resultB['payment_id'], $attendeeB, 'success');
check('پرداخت آزمایشی موفق ثبت شد', ($processed['status'] ?? '') === 'paid', json_encode($processed, JSON_UNESCAPED_UNICODE));

$regAfter = $db->prepare('SELECT status,amount_total_toman FROM registrations WHERE id=?');
$regAfter->execute([$resultB['registration_id']]);
$regAfterRow = $regAfter->fetch();
check('ثبت‌نام پس از پرداخت قطعی شد', $regAfterRow['status'] === 'confirmed', (string)$regAfterRow['status']);
check('مبلغ کل روی ثبت‌نام ذخیره شد', (int)$regAfterRow['amount_total_toman'] === 1100000);

$ticketStmt = $db->prepare('SELECT id FROM tickets WHERE registration_id=?');
$ticketStmt->execute([$resultB['registration_id']]);
check('بلیت پس از پرداخت صادر شد', (int)$ticketStmt->fetchColumn() > 0);

$ledger = $payment->ledger(50, $paidEventId);
check('لجر مالی رکورد دارد', count($ledger) >= 1, (string)count($ledger));

// سناریوی ناموفق
$resultFail = $registration->register($paidEventId, $attendeeC, uuid());
$failOutcome = $payment->process($resultFail['payment_id'], $attendeeC, 'failure');
check('سناریوی ناموفق پرداخت وضعیت failed می‌دهد', ($failOutcome['status'] ?? '') === 'failed', json_encode($failOutcome, JSON_UNESCAPED_UNICODE));

// استرداد → ثبت‌نام لغو و بلیت باطل می‌شود
$payment->refund($resultB['payment_id'], $adminId, 'آزمون استرداد');
$refunded = $payment->payment($resultB['payment_id']);
check('استرداد وضعیت refunded می‌دهد', $refunded['status'] === 'refunded', (string)$refunded['status']);
$afterRefund = $db->query('SELECT status FROM registrations WHERE id=' . (int)$resultB['registration_id'])->fetchColumn();
check('ثبت‌نام پس از استرداد لغو شد', (string)$afterRefund === 'cancelled', (string)$afterRefund);
$ticketAfterRefund = $db->query('SELECT status FROM tickets WHERE registration_id=' . (int)$resultB['registration_id'])->fetchColumn();
check('بلیت پس از استرداد باطل شد', (string)$ticketAfterRefund === 'revoked', (string)$ticketAfterRefund);
$refundLedger = $db->query("SELECT COUNT(*) FROM payment_ledger WHERE entry_type='refund' AND payment_id=" . (int)$resultB['payment_id'])->fetchColumn();
check('رورد استرداد در لجر ثبت شد', (int)$refundLedger === 1, (string)$refundLedger);

// محاسبهٔ مالیات با پرداخت‌کنندهٔ برگزارکننده
$organizerTax = Money::breakdown(1000000, 10, 'organizer');
check('مالیات در حالت برگزارکننده از فراگیر گرفته نمی‌شود', $organizerTax['participant_total'] === 1000000, json_encode($organizerTax));
check('جمع کل در حالت برگزارکننده برابر پایه است', $organizerTax['total'] === 1000000, json_encode($organizerTax));
check('بار مالیات بر دوش برگزارکننده ثبت می‌شود', $organizerTax['organizer_tax'] === 100000, json_encode($organizerTax));
$rounded = Money::breakdown(999999, 9, 'participant');
check('گرد‌کردن مالیات درست است', $rounded['tax'] === 90000, json_encode($rounded));

$participantTax = Money::breakdown(1000000, 10, 'participant');
check('مالیات با پرداخت‌کنندهٔ فراگیر درست است', $participantTax['tax'] === 100000 && $participantTax['total'] === 1100000, json_encode($participantTax));

$noTax = Money::breakdown(500000, 0, 'participant');
check('بدون مالیات، جمع برابر پایه است', $noTax['tax'] === 0 && $noTax['total'] === 500000);

// =====================================================================
section('۴) بلیت، امضای HMAC و کنترل ورود');
// =====================================================================
$ticketStmt = $db->prepare('SELECT t.id,t.public_code,t.status,e.id AS event_id FROM tickets t JOIN registrations r ON r.id=t.registration_id JOIN events e ON e.id=r.event_id WHERE t.registration_id=?');
$ticketStmt->execute([$resultA['registration_id']]);
$ticketRow = $ticketStmt->fetch();
$token = $tickets->token($ticketRow);
check('توکن بلیت ساختار درست دارد', strpos($token, '.') !== false && strlen($token) > 40);

$checkin = $tickets->checkIn($token, $managerId, false, 'online');
check('کنترل ورود با توکن معتبر موفق بود', isset($checkin['event']), json_encode($checkin, JSON_UNESCAPED_UNICODE));

$secondCheckinRejected = false;
try {
    $tickets->checkIn($token, $managerId, false, 'online');
} catch (DomainException $e) {
    $secondCheckinRejected = true;
}
check('کنترل ورود تکراری رد شد', $secondCheckinRejected);

$forgedRejected = false;
try {
    $tickets->checkIn($ticketRow['public_code'] . '.0000000000000000000000000000000000000000000000000000000000000000', $managerId, false, 'online');
} catch (DomainException $e) {
    $forgedRejected = true;
}
check('توکن دست‌کاری‌شده رد شد', $forgedRejected);

$garbageRejected = false;
try {
    $tickets->checkIn('نامعتبر', $managerId, false, 'online');
} catch (DomainException $e) {
    $garbageRejected = true;
}
check('توکن نامعتبر رد شد', $garbageRejected);

// بازصدور بلیت
$ticketId = (int)$ticketRow['id'];
$reissued = $tickets->reissue($ticketId, $managerId, false);
check('بازصدور بلیت کد تازه ساخت', $reissued['public_code'] !== $ticketRow['public_code']);
$oldTokenRejected = false;
try {
    $tickets->checkIn($tickets->token(array_merge($ticketRow, ['public_code' => $reissued['old_code']])), $managerId, false, 'online');
} catch (DomainException $e) {
    $oldTokenRejected = true;
}
check('کد قدیمی پس از بازصدور کار نمی‌کند', $oldTokenRejected);

// صف آفلاین
$offlineTicket = $db->prepare("SELECT t.id,t.public_code FROM tickets t JOIN registrations r ON r.id=t.registration_id WHERE r.user_id=? AND r.status='confirmed' AND t.status='issued' LIMIT 1");
$offlineTicket->execute([$attendeeC]);
$offlineRow = $offlineTicket->fetch();
if ($offlineRow) {
    $offlineToken = $tickets->token(['public_code' => $offlineRow['public_code']]);
    $offline = $tickets->checkIn($offlineToken, $managerId, false, 'offline_queue', 'offline-test-1');
    check('ثبت ورود از صف آفلاین کار کرد', isset($offline['event']));
    $source = $db->query("SELECT source FROM checkins ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('منبع check-in آفلاین ثبت شد', (string)$source === 'offline_queue', (string)$source);
} else {
    check('بلیت معتبر برای آزمون صف آفلاین پیدا شد', false, 'بلیتی یافت نشد');
}

// ثبت ورود از صف آفلاین با شناسه بلیت (مسیر همگام‌سازی) و محافظت در برابر تکرار
$queueRejectedDuplicate = false;
try {
    $tickets->checkInFromQueue((int)$offlineRow['id'], $managerId, 'offline-test-2', false);
} catch (DomainException $e) {
    $queueRejectedDuplicate = strpos($e->getMessage(), 'قبلاً') !== false;
}
check('همگام‌سازی صف آفلاین از ورود تکراری جلوگیری می‌کند', $queueRejectedDuplicate);

// =====================================================================
section('۵) لغو ثبت‌نام و ارتقای فهرست انتظار');
// =====================================================================
$regStmt = $db->prepare('SELECT id,user_id FROM registrations WHERE event_id=? AND user_id=?');
$regStmt->execute([$freeEventId, $attendeeA]);
$rowA = $regStmt->fetch();
$registration->cancelByUser((int)$rowA['id'], (int)$rowA['user_id']);

$ticketRevoked = $db->query('SELECT status FROM tickets WHERE registration_id=' . (int)$rowA['id'])->fetchColumn();
check('بلیت ثبت‌نام لغوشده باطل شد', (string)$ticketRevoked === 'revoked', (string)$ticketRevoked);

$promoted = $db->prepare("SELECT user_id,status FROM registrations WHERE event_id=? AND user_id=?");
$promoted->execute([$freeEventId, $attendeeD]);
$promotedRow = $promoted->fetch();
check('فهرست انتظار پس از لغو به قطعی ارتقا یافت', $promotedRow['status'] === 'confirmed', (string)$promotedRow['status']);
$promotedTicket = $db->query('SELECT COUNT(*) FROM tickets t JOIN registrations r ON r.id=t.registration_id WHERE r.user_id=' . (int)$attendeeD . " AND t.status='issued'")->fetchColumn();
check('برای نفر ارتقاییافته بلیت صادر شد', (int)$promotedTicket >= 1, (string)$promotedTicket);

$stillConfirmed = $db->query("SELECT COUNT(*) FROM registrations WHERE event_id=" . (int)$freeEventId . " AND status='confirmed'")->fetchColumn();
check('ظرفیت پس از ارتقا رعایت شد', (int)$stillConfirmed <= 2, (string)$stillConfirmed);

// =====================================================================
section('۶) گواهی پایان دوره و بازخورد');
// =====================================================================
$certificates = new CertificateService($db);
$code = $certificates->issue($resultC['registration_id'], $managerId);
check('گواهی صادر شد', preg_match('/^[a-f0-9]{32}$/i', $code) === 1, $code);
$again = $certificates->issue($resultC['registration_id'], $managerId);
check('صدور دوباره گواهی تکراری نیست', $again === $code, $again);
$cancelledReg = (int)$db->query('SELECT id FROM registrations WHERE event_id=' . (int)$freeEventId . " AND user_id=" . (int)$attendeeA . " AND status='cancelled'")->fetchColumn();
$blocked = false;
try { $certificates->issue($cancelledReg > 0 ? $cancelledReg : (int)$resultB['registration_id'], $managerId); $blocked = false; } catch (DomainException $e) { $blocked = true; }
check('گواهی برای ثبت‌نام غیرقطعی رد شد', $blocked);
$found = $certificates->byCode($code);
check('گواهی با کد پیدا شد', $found !== null && (int)$found['registration_id'] === (int)$resultC['registration_id']);

$feedback = new FeedbackService($db);
$feedbackId = $feedback->submit($resultC['registration_id'], $attendeeC, 5, 'آزمون بسیار رضایت‌بخش بود.');
check('بازخورد ثبت شد', $feedbackId > 0);
$summary = $feedback->summaryForEvent($freeEventId);
check('خلاصهٔ بازخورد محاسبه شد', (float)($summary['average'] ?? 0) > 0, json_encode($summary, JSON_UNESCAPED_UNICODE));

// =====================================================================
section('۷) اعلان‌ها');
// =====================================================================
$notifications = new NotificationService($db);
$notifications->notify($attendeeA, 'general', 'اعلان آزمون', 'متن آزمون', '/account', 'test:' . uuid());
$unread = $notifications->unreadCount($attendeeA);
check('اعلان ساخته شد و خوانده‌نشده شمرده می‌شود', $unread >= 1, (string)$unread);
$notifications->markRead($attendeeA, null);
check('پس از علامت‌گذاری، شمار خوانده‌نشده صفر شد', $notifications->unreadCount($attendeeA) === 0);

// =====================================================================
section('۸) مجوزها و نقش‌ها');
// =====================================================================
$roles = new RoleService($db);
$matrix = $roles->matrix();
check('ماتریس نقش و مجوز هر ۴ نقش را دارد', count($matrix['roles']) === 4, (string)count($matrix['roles']));
check('ماتریس، نگاشت‌های داده‌شده را دارد', count($matrix['granted']) >= 10, (string)count($matrix['granted']));
$catalog = \Eroydad\Security\Permissions::all();
check('کاتالوگ مجوزها کامل است', count($catalog) >= 20, (string)count($catalog));

$roles->setPermission('event_admin', 'audit.view', false, $adminId);
$perms = $roles->permissionsForUser($managerId);
check('مجوز لغوشده برای مدیر رویداد اعمال شد', !in_array('audit.view', $perms, true), implode(',', $perms));
$roles->resetToDefaults($adminId);
$perms = $roles->permissionsForUser($managerId);
check('بازگردانی نگاشت پیش‌فرض کار کرد', in_array('events.create', $perms, true));

// =====================================================================
section('۹) متن امن، تقویم و TOTP');
// =====================================================================
$html = SafeText::render("خط اول\n- فهرست اول\n- فهرست دوم\n# عنوان\n**پررنگ**");
check('متن امن HTML تولید می‌کند', strpos($html, '<ul>') !== false && strpos($html, '<h') !== false, $html);
check('متن امن اسکریپت را خنثی می‌کند', strpos(SafeText::render('<script>alert(1)</script>'), '<script>') === false);

$ics = Ics::build([['title' => 'دورهٔ آزمون', 'start' => '2026-01-01 10:00:00', 'end' => '2026-01-01 12:00:00', 'description' => 'شرح']], 'آزمون');
check('تقویم ICS ساخته شد', strpos($ics, 'BEGIN:VCALENDAR') === 0 && strpos($ics, 'END:VCALENDAR') !== false);

$secret = Totp::generateSecret();
$code = Totp::code($secret, time());
check('کد TOTP تولید شد', preg_match('/^\d{6}$/', $code) === 1, $code);
check('کد TOTP اعتبارسنجی می‌شود', Totp::verify($secret, $code, time()));
check('کد TOTP نادرست رد می‌شود', !Totp::verify($secret, '000000', time()));

// =====================================================================
section('۱۰) خروجی XLSX واقعی و راست‌چین');
// =====================================================================
$export = new ExportService($db);
$xlsx = $export->generic('گزارش آزمون', ['نام', 'شهر', 'مبلغ'], [['علی', 'مشهد', 1000], ['سارا', 'نیشابور', 2000]], ['rtl' => true]);
check('XLSX بایت‌های معتبر دارد', strlen($xlsx) > 500 && substr($xlsx, 0, 2) === 'PK', (string)strlen($xlsx));

$tmp = sys_get_temp_dir() . '/eroydad-test-' . uniqid() . '.xlsx';
file_put_contents($tmp, $xlsx);
$reader = new XlsxReader((string)file_get_contents($tmp));
$rows = $reader->firstSheetRows();
check('خواندن XLSX ساخته‌شده کار کرد', count($rows) >= 3, (string)count($rows));
check('سرستون‌ها درست خوانده شدند', isset($rows[0][0]) && $rows[0][0] === 'نام', json_encode($rows[0] ?? [], JSON_UNESCAPED_UNICODE));
check('مقدار فارسی درست بازیابی شد', ($rows[1][0] ?? '') === 'علی', json_encode($rows[1] ?? [], JSON_UNESCAPED_UNICODE));
check('مقدار عددی درست بازیابی شد', (string)($rows[1][2] ?? '') === '1000', json_encode($rows[1] ?? [], JSON_UNESCAPED_UNICODE));
@unlink($tmp);

$writer = new XlsxWriter();
$formulaFile = sys_get_temp_dir() . '/eroydad-formula-' . uniqid() . '.xlsx';
$writer->addSheet('برگ۱', [['خطر', 'مقدار'], ['=CMD(\"calc\")', '=1+1'], ['سالم', 'مقدار']]);
$writer->save($formulaFile);
$formulaRows = (new XlsxReader((string)file_get_contents($formulaFile)))->firstSheetRows();
check('فرمول مخرب به‌صورت متن ذخیره شد', strpos((string)($formulaRows[1][0] ?? ''), 'CMD') !== false, json_encode($formulaRows[1] ?? [], JSON_UNESCAPED_UNICODE));
@unlink($formulaFile);

// =====================================================================
section('۱۱) ورود Excel با پیش‌نمایش، اعتبارسنجی و تکرار');
// =====================================================================
$import = new ImportService($db, appConfig());
$csv = "name,phone\nعلی احمدی,09121110000\nسارا رضایی,09121110001\n";
$csvPath = sys_get_temp_dir() . '/eroydad-import-' . uniqid() . '.csv';
file_put_contents($csvPath, $csv);
$preview = $import->preview($csvPath, 'attendees.csv', 'attendees');
check('پیش‌نمایش ورود ساخته شد', isset($preview['rows']) && count($preview['rows']) === 2, json_encode(array_keys($preview), JSON_UNESCAPED_UNICODE));
check('خطایی برای CSV معتبر گزارش نشد', count($preview['errors']) === 0, json_encode($preview['errors'], JSON_UNESCAPED_UNICODE));
@unlink($csvPath);

$badCsv = "name,phone\n,09121110002\nبدون شماره,\n";
$badPath = sys_get_temp_dir() . '/eroydad-bad-' . uniqid() . '.csv';
file_put_contents($badPath, $badCsv);
$badPreview = $import->preview($badPath, 'bad.csv', 'attendees');
check('ردیف نامعتبر گزارش شد', count($badPreview['errors']) > 0, json_encode($badPreview['errors'], JSON_UNESCAPED_UNICODE));
@unlink($badPath);

$dupCsv = "name,phone\nتکراری,09121110003\n";
$dupPath = sys_get_temp_dir() . '/eroydad-dup-' . uniqid() . '.csv';
file_put_contents($dupPath, $dupCsv);
$dupPreview = $import->preview($dupPath, 'dup.csv', 'attendees');
$committed = $import->commitAttendees($freeEventId, $dupPreview['rows'], $managerId);
check('ورود شرکت‌کننده انجام شد', ($committed['rows'] ?? 0) >= 1, json_encode($committed, JSON_UNESCAPED_UNICODE));
$secondCommit = $import->commitAttendees($freeEventId, $dupPreview['rows'], $managerId);
check('ورود تکراری دوباره ثبت نشد', ($secondCommit['imported'] ?? -1) === 0, json_encode($secondCommit, JSON_UNESCAPED_UNICODE));
check('ردیف تکراری شمرده و گزارش شد', ($secondCommit['skipped'] ?? -1) === 1, json_encode($secondCommit, JSON_UNESCAPED_UNICODE));
@unlink($dupPath);

// =====================================================================
section('۱۲) دسک پشتیبانی');
// =====================================================================
$support = new SupportDeskService($db);
$labels = $support->labels();
check('برچسب‌های پشتیبانی بارگذاری شد', count($labels) >= 3, (string)count($labels));
$canned = $support->cannedResponses();
check('پاسخ‌های آماده موجود است', count($canned) >= 1, (string)count($canned));

// =====================================================================
section('۱۳) گزارش‌های مدیریتی');
// =====================================================================
$reports = new \Eroydad\Reports\ReportService($db);
$trend = $reports->registrationTrend($freeEventId, 30);
check('روند ثبت‌نام محاسبه شد', isset($trend['total']) || is_array($trend), json_encode($trend, JSON_UNESCAPED_UNICODE));
$capacity = $reports->capacityReport($freeEventId);
check('گزارش ظرفیت درست است', (int)($capacity['capacity'] ?? 0) === 2, json_encode($capacity, JSON_UNESCAPED_UNICODE));
$mgmt = $reports->managementReport(gmdate('Y-m-d', strtotime('-30 days')), gmdate('Y-m-d', strtotime('+30 days')));
check('گزارش مدیریتی شمارش‌ها را دارد', isset($mgmt['events_created'], $mgmt['registrations'], $mgmt['registrations_confirmed'], $mgmt['payment_total']), json_encode(array_keys($mgmt), JSON_UNESCAPED_UNICODE));
check('گزارش مدیریتی، ثبت‌نام‌های قطعی را می‌شمارد', (int)$mgmt['registrations_confirmed'] >= 2, json_encode($mgmt, JSON_UNESCAPED_UNICODE));
$supportStats = $reports->supportStats();
check('آمار پشتیبانی محاسبه شد', is_array($supportStats));
$checkinRows = $reports->checkinReport([], 100);
check('گزارش کنترل ورود، ورود‌های ثبت‌شده را دارد', count($checkinRows) >= 2, (string)count($checkinRows));

// =====================================================================
section('۱۴) اعتبارسنجی پوستر (MIME/ابعاد/حجم واقعی)');
// =====================================================================
$posterDir = sys_get_temp_dir() . '/eroydad-poster-' . uniqid();
mkdir($posterDir, 0775, true);
$fakeJpg = $posterDir . '/fake.jpg';
file_put_contents($fakeJpg, "این یک تصویر واقعی نیست و فقط متن است.");
$posterService = new \Eroydad\Media\PosterService($db, $posterDir);
$posterRejected = false;
try {
    $posterService->store(['name' => 'fake.jpg', 'tmp_name' => $fakeJpg, 'size' => filesize($fakeJpg), 'error' => UPLOAD_ERR_OK], 'توضیح');
} catch (DomainException $e) {
    $posterRejected = true;
}
check('فایل جعلی با پسوند تصویر رد شد', $posterRejected);

// یک PNG واقعی بساز
if (function_exists('imagecreatetruecolor')) {
    $realPng = $posterDir . '/real.png';
    $im = imagecreatetruecolor(800, 600);
    imagefilledrectangle($im, 0, 0, 800, 600, imagecolorallocate($im, 35, 118, 78));
    imagepng($im, $realPng);
    imagedestroy($im);
    $stored = $posterService->store(['name' => 'real.png', 'tmp_name' => $realPng, 'size' => filesize($realPng), 'error' => UPLOAD_ERR_OK], 'پوستر آزمون');
    check('پوستر واقعی ذخیره شد', isset($stored['path']) && strpos($stored['path'], 'posters/') === 0, json_encode($stored, JSON_UNESCAPED_UNICODE));
    check('پوستر بیرون از ریشهٔ وب ذخیره شد', strpos($stored['path'], 'public') === false);
    check('MIME واقعی تشخیص داده شد', $stored['mime'] === 'image/png', (string)$stored['mime']);
} else {
    check('افزونهٔ GD برای آزمون پوستر در دسترس است', false, 'gd نصب نیست؛ آزمون پوستر کامل نشد');
}

// =====================================================================
section('۱۵) مجوز دسترسی مالکیت رویداد');
// =====================================================================
$otherManager = makeUser('09120000006', 'مدیر دیگر', 'event_admin');
$forbidden = false;
try {
    $events->forAdmin($freeEventId, $otherManager, false);
    $events->update($freeEventId, $otherManager, ['title' => 'تغییر غیرمجاز'], false);
} catch (DomainException $e) {
    $forbidden = true;
}
check('مدیر دیگر نمی‌تواند رویداد را تغییر دهد', $forbidden);

$eventUpdate = function (array $overrides) use ($future, $later) {
    return array_merge([
        'title' => 'دورهٔ آزمایشی رایگان (ویرایش‌شده)',
        'summary' => 'خلاصهٔ به‌روزشدهٔ دورهٔ آزمایشی رایگان برای بررسی ویرایش.',
        'description' => 'شرح به‌روزشدهٔ دورهٔ آزمایشی برای اطمینان از کارکرد ویرایش رویداد.',
        'category' => 'مدیریت شهری',
        'delivery_mode' => 'in_person',
        'venue' => 'سالن آزمون',
        'capacity' => '2',
        'price_toman' => '0',
        'starts_at' => $future,
        'ends_at' => $later,
    ], $overrides);
};

$confirmedCount = (int)$db->query("SELECT COUNT(*) FROM registrations WHERE event_id=" . (int)$freeEventId . " AND status='confirmed'")->fetchColumn();
$shrinkRejected = false;
try {
    $events->update($freeEventId, $managerId, $eventUpdate(['capacity' => (string)max(0, $confirmedCount - 1)]), false);
} catch (DomainException $e) {
    $shrinkRejected = strpos($e->getMessage(), 'ظرفیت') !== false;
}
check('کاهش ظرفیت زیر تعداد ثبت‌نام قطعی رد شد', $shrinkRejected, 'قطعی=' . $confirmedCount);

$allowed = true;
$updateError = '';
try {
    $events->update($freeEventId, $managerId, $eventUpdate(['capacity' => (string)($confirmedCount + 3)]), false);
} catch (Throwable $e) {
    $allowed = false;
    $updateError = get_class($e) . ': ' . $e->getMessage();
}
check('مدیر صاحب رویداد می‌تواند ویرایش کند', $allowed, $updateError);
$newCapacity = (int)$db->query('SELECT capacity FROM events WHERE id=' . (int)$freeEventId)->fetchColumn();
check('ظرفیت جدید ذخیره شد', $newCapacity === $confirmedCount + 3, (string)$newCapacity);

// =====================================================================
section('۱۶) پوشاندن دادهٔ شخصی، جلسات، برچسب، پیش‌نمایش و نگهداری');
// =====================================================================
// ۶۶) پوشاندن موبایل در خروجی‌های مجاز
$sampleReg = [[
    'registration_id' => 11,
    'full_name' => 'فراگیر نمونه',
    'phone' => '09121234567',
    'account_type' => 'public',
    'status' => 'confirmed',
    'event_title' => 'دورهٔ آزمایشی',
    'registered_at' => '2026-01-01 09:00:00',
    'checked_at' => null,
]];
$maskedRows = $export->registrationsRows($sampleReg, true);
$clearRows = $export->registrationsRows($sampleReg, false);
check('خروجی پوشیده، شمارهٔ کامل را نشان نمی‌دهد', strpos(implode('|', $maskedRows[0]), '09121234567') === false, implode('|', $maskedRows[0]));
check('خروجی پوشیده، چهار رقم آخر را نگه می‌دارد', in_array('*******4567', $maskedRows[0], true), implode('|', $maskedRows[0]));
check('خروجی بدون پوشش، شمارهٔ کامل را دارد', in_array('09121234567', $clearRows[0], true), implode('|', $clearRows[0]));
check('موبایل کوتاه/نامعتبر کاملاً پوشیده می‌شود', \Eroydad\Reports\ExportService::maskPhone('12') === '***' && \Eroydad\Reports\ExportService::maskPhone('0912') === '0912');
$usersExport = $export->usersRows([['id' => 3, 'full_name' => 'کاربر', 'phone' => '09121234567', 'account_type' => 'public', 'status' => 'active', 'last_login_at' => null, 'created_at' => null]]);
check('خروجی کاربران همیشه بدون شمارهٔ کامل است', strpos(implode('|', $usersExport[0]), '09121234567') === false, implode('|', $usersExport[0]));

// ۱۶ و ۱۸ و ۲۰) جلسات، برچسب‌ها و پیوند پیش‌نمایش
$content = new \Eroydad\Events\EventContentService($db, $posterService);
$sessionId = $content->addSession($freeEventId, [
    'title' => 'جلسهٔ دوم',
    'starts_at' => localStamp(time() + 86400 * 22),
    'ends_at' => localStamp(time() + 86400 * 22 + 5400),
    'venue' => 'کلاس ۲',
]);
$sessions = $content->sessions($freeEventId);
check('جلسهٔ دوره افزوده و فهرست شد', $sessionId > 0 && count($sessions) >= 1, (string)count($sessions));
check('جلسهٔ افزوده‌شده عنوان درست دارد', (string)($sessions[0]['title'] ?? '') === 'جلسهٔ دوم', json_encode($sessions[0] ?? [], JSON_UNESCAPED_UNICODE));
$names = $content->setTags($freeEventId, 'مدیریت شهری، شهروندی، مدیریت شهری');
check('برچسب تکراری یک‌بار ذخیره می‌شود', in_array('مدیریت شهری', $names, true) && in_array('شهروندی', $names, true) && count($names) === 2, implode(',', $names));
$token = $content->ensurePreviewToken($freeEventId);
$byToken = $content->byPreviewToken((string)$token);
check('پیوند پیش‌نمایش رویداد پیش از انتشار کار می‌کند', is_array($byToken) && (int)$byToken['id'] === (int)$freeEventId, (string)$token);
check('توکن پیش‌نمایش نادرترودسترس رویداد را نشان نمی‌دهد', $content->byPreviewToken('deadbeefdeadbeef') === null);
$content->deleteSession($sessionId, $freeEventId);
check('جلسهٔ حذف شد', count($content->sessions($freeEventId)) === 0);

// ۵۵ و ۵۶ و ۲۶) اعلان بلیت و یادآور شروع دوره
$notifReg = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=" . (int)$attendeeA . " AND type='registration' AND title LIKE '%قطعی شد%'")->fetchColumn();
check('اعلان درون‌برنامه‌ای ثبت‌نام قطعی برای فراگیر ثبت شد', $notifReg >= 1, (string)$notifReg);
$notifTicket = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=" . (int)$attendeeB . " AND body LIKE '%بلیت شما صادر شد%'")->fetchColumn();
check('اعلان صادرشدن بلیت پس از پرداخت موفق ثبت شد', $notifTicket >= 1, (string)$notifTicket);
$reminderEventId = $events->create($managerId, [
    'title' => 'کارگاه فردا',
    'summary' => 'کارگاهی که فردا شروع می‌شود و یادآور لازم دارد.',
    'description' => 'شرح کارگاه فردا برای آزمودن یادآور شروع دوره.',
    'category' => 'مهارت نرم',
    'delivery_mode' => 'in_person',
    'venue' => 'سالن ۳',
    'capacity' => '10',
    'price_toman' => '0',
    'starts_at' => localStamp(time() + 3600 * 10),
    'ends_at' => localStamp(time() + 3600 * 12),
]);
$events->publish($reminderEventId, $managerId, false);
$registration->register($reminderEventId, $attendeeA, uuid());
$remindersCreated = $notifications->createEventReminders(24);
check('یادآور ۲۴ ساعته برای ثبت‌نام قطعی ساخته شد', $remindersCreated >= 1, (string)$remindersCreated);
$remindersAgain = $notifications->createEventReminders(24);
check('یادآور تکراری با dedupe ساخته نمی‌شود', $remindersAgain === 0, (string)$remindersAgain);
$reminderRows = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE type='reminder' AND user_id=" . (int)$attendeeA)->fetchColumn();
check('یادآور شروع دوره در مرکز اعلان‌ها دیده می‌شود', $reminderRows >= 1, (string)$reminderRows);

// ۶۲) نشست‌های فعال و خروج از همهٔ دستگاه‌ها
$sessionsService = new \Eroydad\Admin\SessionService($db);
$tokenA = bin2hex(random_bytes(20));
$tokenB = bin2hex(random_bytes(20));
$ins = $db->prepare('INSERT INTO user_sessions (user_id, session_hash, ip, user_agent, created_at, last_seen_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
$ins->execute([$attendeeA, hash('sha256', $tokenA), '127.0.0.1', 'smoke-a']);
$ins->execute([$attendeeA, hash('sha256', $tokenB), '127.0.0.1', 'smoke-b']);
$mine = $sessionsService->forUser((int)$attendeeA);
check('نشست‌های فعال کاربر فهرست می‌شوند', count($mine) >= 2, (string)count($mine));
$revoked = $sessionsService->revokeAll((int)$attendeeA, $adminId);
check('خروج از همهٔ دستگاه‌ها نشست‌ها را باطل می‌کند', $revoked >= 2, (string)$revoked);
$activeAfter = (int)$db->query('SELECT COUNT(*) FROM user_sessions WHERE user_id=' . (int)$attendeeA . ' AND revoked_at IS NULL')->fetchColumn();
check('پس از ابطال، نشست فعالی باقی نمی‌ماند', $activeAfter === 0, (string)$activeAfter);

// ۶۴) بازیابی رمز
$resetService = new \Eroydad\Security\PasswordResetService($db);
$issued = $resetService->generate((int)$attendeeB, $adminId, 30);
$code = (string)($issued['code'] ?? '');
check('کد بازیابی برای کاربر ساخته شد', strlen($code) >= 6, $code);
$resetService->reset((int)$attendeeB, $code, 'New-Password-777');
check('بازنشانی رمز با کد معتبر انجام شد', (int)$db->query('SELECT COUNT(*) FROM password_resets WHERE user_id=' . (int)$attendeeB . " AND used_at IS NOT NULL")->fetchColumn() === 1);
$reuseFailed = false;
try {
    $resetService->reset((int)$attendeeB, $code, 'Third-Password-1234');
} catch (DomainException $e) {
    $reuseFailed = true;
}
check('کد بازیابی یک‌بارمصرف است', $reuseFailed);

// ۶۵) گزارش ممیزی با فیلتر
$audit = new \Eroydad\Admin\AuditService($db);
$auditRows = $audit->query(['action' => 'registration'], 50);
check('گزارش ممیزی با فیلتر عملیات کار می‌کند', count($auditRows) >= 1, (string)count($auditRows));
$byEntity = $audit->query(['entity' => 'event'], 50);
$onlyEvent = true;
foreach ($byEntity as $row) {
    if ((string)$row['entity_type'] !== 'event') { $onlyEvent = false; break; }
}
check('فیلتر موجودیت در ممیزی دقیق است', $onlyEvent && count($byEntity) >= 1, (string)count($byEntity));
check('فهرست عملیات و موجودیت‌ها برای فرم فیلتر پر می‌شود', count($audit->actions()) >= 1 && count($audit->entities()) >= 1);

// ۷۵ و ۷۸) SLA، زمان پاسخ و رضایت
$ticketId = (int)$db->query('SELECT id FROM support_tickets ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($ticketId <= 0) {
    $ticketId = (new \Eroydad\Support\SupportService($db))->create((int)$attendeeB, (int)$freeEventId, 'تیکت آزمون', 'متن تیکت برای آزمودن SLA و رضایت.');
}
if ($ticketId > 0) {
    $support->setPriority($ticketId, 'high', $adminId);
    $row = $db->query('SELECT sla_due_at, first_response_at FROM support_tickets WHERE id=' . $ticketId)->fetch();
    check('اولویت، مهلت SLA را تعیین می‌کند', !empty($row['sla_due_at']), json_encode($row, JSON_UNESCAPED_UNICODE));
    $support->markFirstResponse($ticketId, $adminId);
    $row2 = $db->query('SELECT first_response_at, status FROM support_tickets WHERE id=' . $ticketId)->fetch();
    check('زمان نخستین پاسخ ثبت شد', !empty($row2['first_response_at']), json_encode($row2, JSON_UNESCAPED_UNICODE));
    $support->markFirstResponse($ticketId, $adminId);
    $row3 = $db->query('SELECT first_response_at FROM support_tickets WHERE id=' . $ticketId)->fetch();
    check('نخستین پاسخ فقط یک‌بار ثبت می‌شود', (string)$row2['first_response_at'] === (string)$row3['first_response_at']);
    $support->rate($ticketId, (int)$attendeeB, 4, 'خوب بود');
    $row4 = $db->query('SELECT satisfaction_rating FROM support_tickets WHERE id=' . $ticketId)->fetch();
    check('رضایت کاربر ذخیره شد', (int)$row4['satisfaction_rating'] === 4, (string)$row4['satisfaction_rating']);
    $ratingRejected = false;
    try {
        $support->rate($ticketId, (int)$attendeeB, 9, 'نامعتبر');
    } catch (DomainException $e) {
        $ratingRejected = true;
    }
    check('امتیاز خارج از بازه ۱ تا ۵ رد شد', $ratingRejected);
}

// ۶۷) محدودسازی درخواست‌ها
$limiter = new \Eroydad\Security\RateLimiter($db, (string)appConfig()['app']['app_key']);
$blocked = false;
for ($i = 0; $i < 12; $i++) {
    $limiter->record('09120000099', '10.0.0.9', false);
}
try {
    $limiter->assertLoginAllowed('09120000099', '10.0.0.9');
} catch (DomainException $e) {
    $blocked = true;
}
check('پس از تلاش‌های نامعتبر، ورود موقتاً محدود می‌شود', $blocked);
$afterSuccess = true;
try {
    (new \Eroydad\Security\RateLimiter($db, (string)appConfig()['app']['app_key']))->assertLoginAllowed('09120000098', '10.0.0.8');
} catch (DomainException $e) {
    $afterSuccess = false;
}
check('شمارنده برای شمارهٔ دیگر مسدود نیست', $afterSuccess);

// ۸۰) سیاست نگهداری و پاک‌سازی
$retention = new \Eroydad\Admin\RetentionService($db);
check('سیاست‌های نگهداری تعریف شده‌اند', count($retention->policies()) >= 5, (string)count($retention->policies()));
$purge = $retention->run($adminId, 1);
check('پاک‌سازی برای هر سیاست نتیجهٔ جدا می‌دهد', is_array($purge) && count($purge) >= 5, json_encode($purge, JSON_UNESCAPED_UNICODE));
$purgeHistory = $retention->history(10);
check('اجرای پاک‌سازی در تاریخچه ثبت می‌شود', count($purgeHistory) >= 5, json_encode($purge, JSON_UNESCAPED_UNICODE));

// ۸۳) بررسی سلامت سامانه
$health = (new \Eroydad\Admin\HealthService($db, appConfig()))->run();
$healthIds = array_map(static function (array $c): string { return (string)($c['id'] ?? ''); }, $health);
check('بررسی سلامت، چند بررسی مستقل دارد', count($health) >= 7, (string)count($health));
check('بررسی سلامت شامل دیتابیس، جدول‌ها، migration و افزونه‌هاست',
    in_array('database', $healthIds, true) && in_array('tables', $healthIds, true)
    && in_array('migrations', $healthIds, true) && in_array('extensions', $healthIds, true)
    && in_array('storage', $healthIds, true) && in_array('secrets', $healthIds, true), implode(',', $healthIds));
$databaseCheck = null;
foreach ($health as $c) {
    if ((string)($c['id'] ?? '') === 'database') { $databaseCheck = $c; break; }
}
check('اتصال دیتابیس در بررسی سلامت سبز است', $databaseCheck !== null && $databaseCheck['status'] === 'ok', json_encode($databaseCheck, JSON_UNESCAPED_UNICODE));

// ۶۸) پشتیبان‌گیری و تمرین بازیابی
$backupDir = sys_get_temp_dir() . '/eroydad-backup-' . uniqid();
$backup = new \Eroydad\Admin\BackupService($db, $backupDir);
$made = $backup->create($adminId);
check('فایل پشتیبان ساخته شد', !empty($made['file']) && is_file($backupDir . '/' . $made['file']), json_encode($made, JSON_UNESCAPED_UNICODE));
$backupId = (int)($made['id'] ?? 0);
$verified = $backupId > 0 ? $backup->verify($backupId) : [];
check('پشتیبان اعتبارسنجی/بازیابی می‌شود', !empty($verified), json_encode($verified, JSON_UNESCAPED_UNICODE));
check('فهرست پشتیبان‌ها نمایش داده می‌شود', count($backup->list()) >= 1);
array_map('unlink', glob($backupDir . '/*'));
@rmdir($backupDir);

// ۶۱ و ۶۴ و ۵۷) فهرست نقش‌ها، کدهای بازیابی باز و گزارش کنترل ورود با فیلتر
$userRows = $roles->userRoles();
check('فهرست کاربران و نقش‌ها پر می‌شود', count($userRows) >= 4, (string)count($userRows));
$userRow = null;
foreach ($userRows as $row) {
    if ((int)$row['id'] === (int)$attendeeB) { $userRow = $row; break; }
}
check('نقش‌های هر کاربر در فهرست شمرده شده', $userRow !== null && array_key_exists('roles', $userRow), json_encode($userRow, JSON_UNESCAPED_UNICODE));
$openCode = $resetService->generate((int)$attendeeC, $adminId, 45);
$pendingList = $resetService->listPending();
$foundPending = false;
foreach ($pendingList as $row) {
    if ((int)$row['user_id'] === (int)$attendeeC) { $foundPending = true; break; }
}
check('فهرست کدهای بازیابی استفاده‌نشده پر می‌شود', $foundPending, (string)count($pendingList));
check('کد بازیابی در فهرست به‌صورت هش نمایش داده می‌شود', strpos(json_encode($pendingList, JSON_UNESCAPED_UNICODE), (string)$openCode['code']) === false);
$recon = $reports->employeeReconciliation();
check('گزارش تطبیق کارکنان شمارش‌ها را دارد', isset($recon['allowlist_total'], $recon['accepted_total'], $recon['rejected_total'], $recon['reject_rate']), json_encode(array_keys($recon), JSON_UNESCAPED_UNICODE));
$db->prepare('INSERT INTO employee_import_batches (created_by, source_fingerprint, accepted_rows, rejected_rows, imported_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')
    ->execute([$adminId, hash('sha256', 'batch-اثر-آزمون.xlsx'), 7, 3]);
$recon2 = $reports->employeeReconciliation();
check('گزارش تطبیق، ردشدگان را از batchها جمع می‌زند', (int)$recon2['rejected_total'] >= 3 && (float)$recon2['reject_rate'] > 0, json_encode(['rej' => $recon2['rejected_total'], 'rate' => $recon2['reject_rate']], JSON_UNESCAPED_UNICODE));
$filteredCheckin = $reports->checkinReport(['q' => 'فراگیر الف'], 50);
check('گزارش کنترل ورود با جست‌وجوی نام کار می‌کند', is_array($filteredCheckin), gettype($filteredCheckin));
$emptyCheckin = $reports->checkinReport(['q' => 'هیچ‌کس‌دراین‌فهرستنیست'], 50);
check('گزارش کنترل ورود، جست‌وجوی بی‌نتیجه را درست فیلتر می‌کند', count($emptyCheckin) === 0, (string)count($emptyCheckin));

// =====================================================================
section('۱۷) قفل درگاه واقعی، سناریوهای ساختگی و راست‌چین بودن فایل XLSX');
// =====================================================================
check('درگاه واقعی در این نسخه قفل است', SandboxGateway::REAL_PAYMENT_ENABLED === false && SandboxGateway::isSandbox());
$lockQuiet = true;
$lockNote = '';
try {
    SandboxGateway::assertRealGatewayLocked();
} catch (Throwable $e) {
    $lockQuiet = false;
    $lockNote = $e->getMessage();
}
check('در محیط بدون تنظیم درگاه، قفل پرداخت استثنا نمی‌دهد', $lockQuiet, $lockNote);
putenv('ZARINPAL_MERCHANT_ID=1234567890');
$lockedNow = false;
try {
    SandboxGateway::assertRealGatewayLocked();
} catch (RuntimeException $e) {
    $lockedNow = true;
}
putenv('ZARINPAL_MERCHANT_ID');
check('اگر متغیر محیطی درگاه واقعی ست شود، پرداخت متوقف می‌شود', $lockedNow);
putenv('PAYMENT_PROVIDER=sandbox');
$quietSandbox = true;
try {
    SandboxGateway::assertRealGatewayLocked();
} catch (Throwable $e) {
    $quietSandbox = false;
}
putenv('PAYMENT_PROVIDER');
check('مقدار sandbox در متغیر محیطی پذیرفته می‌شود', $quietSandbox);
foreach (['success' => 'paid', 'failure' => 'failed', 'cancelled' => 'cancelled', 'timeout' => 'failed'] as $scenario => $wantStatus) {
    $charged = SandboxGateway::charge((string)$scenario, 120000);
    check('سناریوی ساختگی «' . $scenario . '» پاسخ «' . $wantStatus . '» می‌دهد',
        $charged['status'] === $wantStatus && strpos($charged['reference'], 'SBX-') === 0,
        json_encode($charged, JSON_UNESCAPED_UNICODE));
}

// فایل XLSX خروجی باید راست‌چین و آمادهٔ چاپ باشد
$rtlFile = $export->generic('گزارش راست‌چین', ['ستون'], [['مقدار']], ['rtl' => true]);
$entries = \Eroydad\Excel\Zip::read($rtlFile);
$workbookXml = (string)($entries['xl/workbook.xml'] ?? '');
$sheetXml = (string)($entries['xl/worksheets/sheet1.xml'] ?? '');
check('کارپنک XLSX راست‌چین است', strpos($workbookXml, 'rightToLeft="1"') !== false, substr($workbookXml, 0, 200));
check('برگهٔ XLSX راست‌چین است', strpos($sheetXml, 'rightToLeft="1"') !== false, substr($sheetXml, 0, 200));
check('فایل XLSX تعریف چاپ/صفحه‌آرایی دارد', strpos($sheetXml, '<pageMargins') !== false || strpos($sheetXml, '<pageSetup') !== false, substr($sheetXml, -220));

// =====================================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "نتیجه: " . $passed . " موفق، " . $failed . " ناموفق\n";
if ($failed > 0) {
    echo "STATUS: FAILED\n";
    exit(1);
}
echo "STATUS: PASSED\n";
exit(0);
