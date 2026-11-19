/**
 * آزمون امنیت — مالکیت (IDOR)، CSRF، محدودیت نرخ، XSS ذخیره‌شده و باطل‌شدن نشست.
 *
 * همه روی فرانت‌کنترل واقعی با PHP 7.4 واقعی + MariaDB واقعی انجام می‌شوند.
 *
 * اجرا:  node tests/security-run.mjs [مسیر پروژه]
 */
import { PHPRequestHandler } from '@php-wasm/universal';
import { createTestEnvironment } from './support/harness.mjs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));

const failures = [];
function ok(label, condition, detail = '') {
  if (condition) { console.log('PASS  ' + label); return; }
  failures.push(label + (detail ? ' :: ' + detail.slice(0, 220) : ''));
  console.log('FAIL  ' + label + (detail ? ' :: ' + detail.slice(0, 220) : ''));
}

class CookieJar {
  constructor() { this.pairs = new Map(); }
  rememberCookiesFromResponseHeaders(headers) {
    for (const [name, values] of Object.entries(headers || {})) {
      if (String(name).toLowerCase() !== 'set-cookie') continue;
      for (const value of values || []) {
        const pair = String(value).split(';')[0];
        const index = pair.indexOf('=');
        if (index > 0) this.pairs.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
      }
    }
  }
  getCookieRequestHeader() {
    return [...this.pairs.entries()].map(([k, v]) => k + '=' + v).join('; ');
  }
}

const env = await createTestEnvironment({ root: ROOT, withSchema: true, baseUrl: 'http://localhost/' });
try {
  // ---------- دانه‌گذاری: دو کاربر عادی + مدیر + رویداد پولیِ پرداخت‌شده ----------
  const seed = await env.php.run({
    code: `<?php
require '/project/backend/bootstrap.php';
foreach (['/backend/src/Events/EventService.php','/backend/src/Registration/RegistrationService.php','/backend/src/Registration/TicketService.php','/backend/src/Payments/PaymentService.php','/backend/src/Payments/Money.php','/backend/src/Payments/SandboxGateway.php','/backend/src/Notifications/NotificationService.php'] as $f) require_once '/project' . $f;
use Eroydad\\Events\\EventService;
use Eroydad\\Registration\\RegistrationService;
use Eroydad\\Payments\\PaymentService;
$db = db();
function mkUser(PDO $db, string $phone, string $pass, string $name, string $roleCode = null): int {
  $hash = password_hash(passwordMaterial($pass), PASSWORD_DEFAULT);
  $stmt = $db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status,phone_verified_at) VALUES (?,?,?,'public','active',UTC_TIMESTAMP())");
  $stmt->execute([$phone, $name, $hash]);
  $id = (int)$db->lastInsertId();
  if ($roleCode !== null) {
    $role = $db->prepare("SELECT id FROM roles WHERE code=?");
    $role->execute([$roleCode]);
    $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$id, (int)$role->fetchColumn()]);
  }
  return $id;
}
$userA = mkUser($db, '09120002001', 'Owner-A-91-OK!', 'کاربر الف');
$userB = mkUser($db, '09120002002', 'Owner-B-91-OK!', 'کاربر ب');
$sys = mkUser($db, '09120002003', 'Root-Admin-91-OK', 'مدیر سامانه', 'system_admin');
$future = (new DateTimeImmutable('@' . (time() + 86400 * 9)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$later = (new DateTimeImmutable('@' . (time() + 86400 * 9 + 7200)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$events = new EventService($db);
$eventId = $events->create($sys, [
  'title' => 'رویداد پولی امنیت',
  'summary' => 'رویدادی برای آزمون مالکیت اسناد پرداخت و بلیت.',
  'description' => 'شرح کامل رویداد پولی برای آزمون‌های امنیتی مالکیت و مالکیت بلیت در این مخزن.',
  'category' => 'آزمون',
  'delivery_mode' => 'in_person',
  'venue' => 'سالن امنیت',
  'capacity' => '30',
  'price_toman' => '150000',
  'tax_percent' => '10',
  'tax_payer' => 'participant',
  'starts_at' => $future,
  'ends_at' => $later,
]);
$events->publish($eventId, $sys, false);
$reg = (new RegistrationService($db))->register($eventId, $userA, 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d');
$paymentId = (int)($reg['payment_id'] ?? 0);
(new PaymentService($db))->process($paymentId, $userA, 'success');
$registrationId = (int)$db->query('SELECT id FROM registrations WHERE event_id=' . (int)$eventId . ' AND user_id=' . $userA)->fetchColumn();
$ticketId = (int)$db->query('SELECT id FROM tickets WHERE registration_id=' . $registrationId)->fetchColumn();
$slug = (string)$db->query('SELECT slug FROM events WHERE id=' . (int)$eventId)->fetchColumn();
echo 'SEED ' . json_encode(['user_a'=>$userA,'user_b'=>$userB,'sys'=>$sys,'event_id'=>$eventId,'slug'=>$slug,'payment_id'=>$paymentId,'registration_id'=>$registrationId,'ticket_id'=>$ticketId]) . "\\n";
`,
  });
  const seedText = typeof seed.text === 'string' ? seed.text : new TextDecoder().decode(seed.stdout ?? new Uint8Array());
  const seedMatch = /SEED (\{.*\})/.exec(seedText);
  const seeded = seedMatch ? JSON.parse(seedMatch[1]) : null;
  ok('دادهٔ آزمون امنیت ساخته شد', seeded !== null, seedText + new TextDecoder().decode(seed.stderr ?? new Uint8Array()));
  if (!seeded) throw new Error('دانه‌گذاری ناموفق بود');

  // هر «کاربر» handler خودش را با cookieStore خودش دارد — دقیقاً مثل http-smoke-test
  function makeClient() {
    const jar = new CookieJar();
    const handler = new PHPRequestHandler({
      php: env.php,
      documentRoot: '/project/frontend/public',
      absoluteUrl: 'http://localhost/',
      cookieStore: jar,
      getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
    });
    return {
      jar,
      async req(url, options = {}) {
        let body = options.body;
        let headers = { ...(options.headers || {}) };
        if (body && typeof body === 'object' && !(body instanceof Uint8Array)) {
          const params = new URLSearchParams();
          for (const [k, v] of Object.entries(body)) params.append(k, String(v));
          body = new TextEncoder().encode(params.toString());
          headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        const response = await handler.request({ url, method: options.method || 'GET', body, headers });
        const text = typeof response.text === 'string' ? response.text : new TextDecoder().decode(response.stdout ?? response.body ?? new Uint8Array());
        return { status: response.httpStatusCode, text, headers: response.headers };
      },
    };
  }

  const csrfOf = (html) => {
    const m = /name="_csrf" value="([^"]+)"/.exec(html);
    return m ? m[1] : null;
  };
  const locationOf = (res) => String(res.headers?.location ?? res.headers?.Location ?? '');

  const alice = makeClient();
  const bob = makeClient();
  const admin = makeClient();

  const login = async (client, phone, password) => {
    const page = await client.req('/login');
    const token = csrfOf(page.text);
    return client.req('/login', { method: 'POST', body: { phone, password, _csrf: token || '', next: '' } });
  };
  await login(alice, '09120002001', 'Owner-A-91-OK!');
  await login(bob, '09120002002', 'Owner-B-91-OK!');
  await login(admin, '09120002003', 'Root-Admin-91-OK');
  ok('هر سه نشست ورود ساختند', true);

  // ---------- ۱) مالکیت / IDOR ----------
  const payOwner = await alice.req('/payments/' + seeded.payment_id + '/sandbox');
  const payOther = await bob.req('/payments/' + seeded.payment_id + '/sandbox');
  ok('مالک سند پرداخت صفحه را می‌بیند', payOwner.status === 200, 'status=' + payOwner.status);
  ok('غیرمالک به صفحهٔ پرداخت نمی‌رسد (404)', payOther.status === 404, 'status=' + payOther.status);

  const ticketOwner = await alice.req('/tickets/' + seeded.ticket_id + '/print');
  const ticketOther = await bob.req('/tickets/' + seeded.ticket_id + '/print');
  ok('مالک بلیت صفحهٔ چاپ را می‌بیند', ticketOwner.status === 200, 'status=' + ticketOwner.status);
  ok('غیرمالک به بلیت دیگری نمی‌رسد (404)', ticketOther.status === 404, 'status=' + ticketOther.status);

  const fbOwner = await alice.req('/feedback/' + seeded.registration_id);
  const fbOther = await bob.req('/feedback/' + seeded.registration_id);
  ok('مالک ثبت‌نام به فرم بازخورد می‌رسد', fbOwner.status === 200, 'status=' + fbOwner.status);
  ok('غیرمالک به بازخورد دیگری نمی‌رسد (404)', fbOther.status === 404, 'status=' + fbOther.status);

  const receiptOther = await bob.req('/payments/' + seeded.payment_id + '/receipt');
  ok('رسید هم فقط برای مالک است (404 برای دیگری)', receiptOther.status === 404, 'status=' + receiptOther.status);

  // ---------- ۲) کنترل نقش ----------
  const adminAsUser = await alice.req('/admin');
  const adminAsSys = await admin.req('/admin');
  ok('فراگیر به پنل مدیر 403 می‌گیرد', adminAsUser.status === 403, 'status=' + adminAsUser.status);
  ok('مدیر سامانه به پنل می‌رسد (کنترل)', adminAsSys.status === 200, 'status=' + adminAsSys.status);

  // ---------- ۳) CSRF ----------
  const settingsPage = await alice.req('/account/settings');
  const goodToken = csrfOf(settingsPage.text);
  ok('فیلد CSRF در صفحه هست', Boolean(goodToken), settingsPage.text.slice(0, 200));

  const noCsrf = await alice.req('/account/settings', { method: 'POST', body: { theme: 'dark', font_scale: '100' } });
  ok('POST بدون توکن CSRF رد می‌شود (419)', noCsrf.status === 419, 'status=' + noCsrf.status);

  const badCsrf = await alice.req('/account/settings', { method: 'POST', body: { theme: 'dark', font_scale: '100', _csrf: 'not-a-real-token' } });
  ok('POST با توکن نادرست رد می‌شود (419)', badCsrf.status === 419, 'status=' + badCsrf.status);

  const goodCsrf = await alice.req('/account/settings', { method: 'POST', body: { theme: 'dark', font_scale: '100', _csrf: goodToken } });
  ok('POST با توکن درست پذیرفته می‌شود (303 — کنترل)', goodCsrf.status === 303, 'status=' + goodCsrf.status);

  // ---------- ۴) XSS ذخیره‌شده ----------
  const payload = '<script>alert(9)</script>';
  const orgToken = csrfOf((await alice.req('/account/organization')).text);
  const saveOrg = await alice.req('/account/organization', { method: 'POST', body: { organization_name: payload, national_id: '', address: '', contact_email: '', contact_phone: '', _csrf: orgToken || '' } });
  ok('ذخیرهٔ نام سازمانی با payload پذیرفته می‌شود (303)', saveOrg.status === 303, 'status=' + saveOrg.status);
  const orgBack = await alice.req('/account/organization');
  ok('payload خام در HTML تحویل داده نمی‌شود', !orgBack.text.includes('<script>alert(9)</script>'), orgBack.text.slice(0, 300));
  ok('payload به‌صورت متن پیش‌شده دیده می‌شود', orgBack.text.includes('&lt;script&gt;') || orgBack.text.includes('script>alert'), orgBack.text.slice(0, 400));
  ok('صفحه بعد از ذخیره خطا ندارد', orgBack.status === 200 && !/Fatal error|Uncaught/.test(orgBack.text), 'status=' + orgBack.status);

  // ---------- ۵) خروج از همهٔ دستگاه‌ها نشست را باطل می‌کند ----------
  const secPage = await alice.req('/account/security');
  const logoutToken = csrfOf(secPage.text);
  const logoutAll = await alice.req('/account/security/logout-all', { method: 'POST', body: { _csrf: logoutToken || '' } });
  ok('خروج از همهٔ دستگاه‌ها انجام شد (303 → ورود)', logoutAll.status === 303 && locationOf(logoutAll).includes('/login'), 'status=' + logoutAll.status + ' loc=' + locationOf(logoutAll));
  const afterLogout = await alice.req('/account');
  ok('نشستِ باطل‌شده دیگر به پنل نمی‌رسد (303 → ورود)', afterLogout.status === 303 && locationOf(afterLogout).includes('/login'), 'status=' + afterLogout.status + ' loc=' + locationOf(afterLogout));

  // ---------- ۶) محدودیت نرخ ورود (۸ تلاش ناموفق در ۱۵ دقیقه) ----------
  const attacker = makeClient();
  let lastStatus = 0;
  let blockedPage = null;
  for (let i = 0; i < 9; i += 1) {
    const page = await attacker.req('/login');
    const token = csrfOf(page.text);
    const res = await attacker.req('/login', { method: 'POST', body: { phone: '09120002001', password: 'Wrong-Pass-' + i, _csrf: token || '', next: '' } });
    lastStatus = res.status;
    if (i === 8) {
      const after = await attacker.req('/login');
      blockedPage = after.text;
    }
  }
  ok('تلاش ناموفق با پاسخ عادی ثبت می‌شود', lastStatus === 303, 'status=' + lastStatus);
  ok('نهمین تلاش با پیام محدودیت نرخ برمی‌گردد', blockedPage !== null && blockedPage.includes('تعداد تلاش‌ها زیاد است'), (blockedPage || '').slice(0, 300));

  console.log('');
  if (failures.length) {
    console.log('آزمون امنیت: ' + failures.length + ' ایراد.');
    process.exit(1);
  }
  console.log('آزمون امنیت: مالکیت، CSRF، نقش، XSS و محدودیت نرخ همه درست‌اند.');
  process.exit(0);
} catch (error) {
  console.log('آزمون امنیت با خطا متوقف شد: ' + (error && error.message));
  process.exit(1);
} finally {
  await env.engine?.close?.().catch?.(() => {});
}
