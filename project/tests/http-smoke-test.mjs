/**
 * آزمون دود (smoke) در سطح HTTP:
 *   فرانت‌کنترل واقعی برنامه (frontend/public/index.php) روی PHP 7.4 واقعی
 *   + MariaDB 11.8 واقعی، با کوکی نشست و CSRF.
 *
 * آنچه بررسی می‌شود:
 *   • صفحه‌های عمومی و پنل مدیری کد ۲۰۰ می‌دهند و خطای PHP ندارند.
 *   * ورود با نام‌کاربری/رمز، نشست و CSRF از مسیر واقعی صفحه انجام می‌شود.
 *   * مسیرهای نسبی PWA (manifest/serviceworker/آیکن) درست سرو می‌شوند.
 *   * همگام‌سازی صف آفلاین با سربرگ Accept: application/json پاسخ JSON می‌دهد.
 *
 * اجرا:  node tests/http-smoke-test.mjs [مسیر پروژه]
 */
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
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

/** صندوق کوکی ساده برای نگه‌داشتن نشست میان درخواست‌ها. */
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
  // ---------- دانه‌گذاری داده با سرویس‌های واقعی ----------
  const seed = await env.php.run({
    code: `<?php
require '/project/backend/bootstrap.php';
foreach (['/backend/src/Events/EventService.php','/backend/src/Registration/RegistrationService.php'] as $f) require_once '/project' . $f;
use Eroydad\\Events\\EventService;
$db = db();
$hash = password_hash(passwordMaterial('Smoke-Test-91!'), PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status,phone_verified_at) VALUES (?,?,?,'public','active',UTC_TIMESTAMP())");
$stmt->execute(['09129990001', 'مدیر آزمون دود', $hash]);
$userId = (int)$db->lastInsertId();
$role = $db->prepare("SELECT id FROM roles WHERE code='event_admin'");
$role->execute();
$db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$userId, (int)$role->fetchColumn()]);
$future = (new DateTimeImmutable('@' . (time() + 86400 * 12)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$later = (new DateTimeImmutable('@' . (time() + 86400 * 12 + 7200)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$events = new EventService($db);
$eventId = $events->create($userId, [
  'title' => 'رویداد آزمون دود',
  'summary' => 'رویدادی برای آزمودن صفحهٔ عمومی و پنل مدیریتی.',
  'description' => 'توضیح کامل رویداد آزمون دود برای بررسی رندر صفحهٔ جزئیات.',
  'category' => 'آزمون',
  'delivery_mode' => 'in_person',
  'venue' => 'سالن نمایش',
  'capacity' => '50',
  'price_toman' => '0',
  'starts_at' => $future,
  'ends_at' => $later,
]);
$events->publish($eventId, $userId, false);
$sysHash = password_hash(passwordMaterial('Root-Admin-91-OK'), PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status,phone_verified_at) VALUES (?,?,?,'municipal','active',UTC_TIMESTAMP())");
$stmt->execute(['09129990003', 'مدیر سامانهٔ آزمون', $sysHash]);
$sysId = (int)$db->lastInsertId();
$sysRole = $db->prepare("SELECT id FROM roles WHERE code='system_admin'");
$sysRole->execute();
$db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$sysId, (int)$sysRole->fetchColumn()]);
$slug = (string)$db->query('SELECT slug FROM events WHERE id=' . (int)$eventId)->fetchColumn();
echo 'SEED ' . json_encode(['user_id' => $userId, 'system_admin_id' => $sysId, 'event_id' => $eventId, 'slug' => $slug]) . "\\n";
`,
  });
  const seedText = typeof seed.text === 'string' ? seed.text : new TextDecoder().decode(seed.stdout ?? new Uint8Array());
  const seedMatch = /SEED (\{.*\})/.exec(seedText);
  const seeded = seedMatch ? JSON.parse(seedMatch[1]) : null;
  ok('دادهٔ آزمون با سرویس‌های واقعی ساخته شد', seeded !== null, seedText + new TextDecoder().decode(seed.stderr ?? new Uint8Array()));
  if (!seeded) throw new Error('دانه‌گذاری ناموفق بود');

  // ---------- سرور HTTP درون‌فرایندی ----------
  const jar = new CookieJar();
  const handler = new PHPRequestHandler({
    php: env.php,
    documentRoot: '/project/frontend/public',
    absoluteUrl: 'http://localhost/',
    cookieStore: jar,
    getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
  });

  const seen = new Map();
  /**
   * بدنهٔ فرم را خودمان به octet/stream تبدیل می‌کنیم: اگر شیء را مستقیم به
   * PHPRequestHandler بدهیم، مقدارهای چندبایتی (متن فارسی) کوتاه می‌شوند و
   * آزمون به‌جای رفتار واقعی برنامه، خطای encoding پرونده را می‌بیند.
   */
  function encodeBody(body) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(body)) params.append(key, String(value));
    return new TextEncoder().encode(params.toString());
  }
  async function req(url, options = {}) {
    let body = options.body;
    let headers = options.headers;
    if (body && typeof body === 'object' && !(body instanceof Uint8Array)) {
      body = encodeBody(body);
      headers = { 'Content-Type': 'application/x-www-form-urlencoded', ...(headers || {}) };
    }
    const response = await handler.request({ url, method: options.method || 'GET', body, headers });
    const text = typeof response.text === 'string' ? response.text : new TextDecoder().decode(response.stdout ?? response.body ?? new Uint8Array());
    seen.set(url, { status: response.httpStatusCode, text });
    return { status: response.httpStatusCode, text, headers: response.headers };
  }

  const phpError = (text) => /Fatal error|Parse error|Uncaught|Warning:<\/b>|Notice:<\/b>/i.test(text);

  // ---------- صفحه‌های عمومی ----------
  const home = await req('/');
  ok('صفحهٔ اصلی کد ۲۰۰ می‌دهد', home.status === 200, 'status=' + home.status);
  ok('صفحهٔ اصلی خطای PHP ندارد', !phpError(home.text), home.text.slice(0, 200));
  ok('صفحهٔ اصلی عنوان برند را دارد', home.text.includes('ای‌رویداد'));
  ok('صفحهٔ اصلی لینک manifest نسبی دارد', /href="\/manifest\.webmanifest"/.test(home.text), home.text.slice(0, 400));
  ok('صفحهٔ اصلی favicon را ارجاع می‌دهد', home.text.includes('assets/icons/app-icon.svg'));

  const listing = await req('/events');
  ok('فهرست رویدادها کد ۲۰۰ می‌دهد', listing.status === 200 && !phpError(listing.text), 'status=' + listing.status);
  ok('فهرست رویدادها، رویداد منتشرشده را نشان می‌دهد', listing.text.includes('رویداد آزمون دود'));

  const detail = await req('/events/' + seeded.slug);
  ok('صفحهٔ جزئیات رویداد کد ۲۰۰ می‌دهد', detail.status === 200 && !phpError(detail.text), 'status=' + detail.status);
  ok('صفحهٔ جزئیات، توضیح رویداد را نشان می‌دهد', detail.text.includes('توضیح کامل رویداد'));

  const notFound = await req('/this-page-does-not-exist');
  ok('صفحهٔ ناموجود کد ۴۰۴ می‌دهد', notFound.status === 404, 'status=' + notFound.status);

  // ---------- دارایی‌های PWA ----------
  const manifest = await req('/manifest.webmanifest');
  ok('manifest سرو می‌شود', manifest.status === 200, 'status=' + manifest.status);
  let manifestJson = null;
  try { manifestJson = JSON.parse(manifest.text); } catch (e) { /* invalid */ }
  ok('manifest JSON معتبر است', manifestJson !== null, manifest.text.slice(0, 120));
  if (manifestJson) {
    ok('start_url نسبی است (نصب در زیرپوشه)', manifestJson.start_url === './' && manifestJson.scope === './', JSON.stringify({ s: manifestJson.start_url, sc: manifestJson.scope }));
    ok('آیکن‌های manifest نسبی‌اند', (manifestJson.icons || []).every((i) => !String(i.src).startsWith('/')), JSON.stringify(manifestJson.icons));
  }
  const sw = await req('/sw.js');
  ok('سرویس‌ورکر سرو می‌شود', sw.status === 200 && sw.text.includes('erooydad-static-v1'), 'status=' + sw.status);
  ok('سرویس‌ورکر مسیر ریشه‌مطلق ندارد', !/'\/assets\//.test(sw.text), sw.text.slice(0, 200));
  const icon = await req('/assets/icons/app-icon.svg');
  ok('آیکن SVG وجود دارد', icon.status === 200 && icon.text.includes('<svg'), 'status=' + icon.status);
  const css = await req('/assets/css/app.css');
  ok('فایل CSS سرو می‌شود', css.status === 200 && css.text.length > 1000, 'status=' + css.status);
  const tokens = await req('/assets/css/design-system.css');
  ok('CSS توکن‌ها سرو می‌شود', tokens.status === 200 && tokens.text.length > 500, 'status=' + tokens.status);
  ok('توکن‌های طراحی در CSS اصلی تعریف و در سراسر رابط مصرف می‌شوند', /--ds-[a-z-]+:\s*#/.test(css.text) && /var\(--ds-[a-z-]+\)/.test(tokens.text), 'tokens');
  ok('CSS اصلی، فایل افزونۀ رابط را هم فراخوانی می‌کند', /assets\/css\/design-system\.css/.test(home.text), home.text.slice(0, 600));
  const font = await req('/assets/fonts/vazirmatn-variable.woff2');
  ok('فونت فارسی محلی سرو می‌شود', font.status === 200 && font.text.length > 5000, 'status=' + font.status);
  ok('صفحه، همان فایل فونت محلی را preload می‌کند', /rel="preload"[^>]*assets\/fonts\/vazirmatn-variable\.woff2/.test(home.text), (home.text.match(/<link rel="preload"[^>]*>/)||[])[0]);
  ok('هیچ دارایی‌ای از CDN بیرونی گرفته نمی‌شود', !/(fonts\.googleapis|gstatic|cdn\.|unpkg|jsdelivr)/i.test(home.text + css.text + tokens.text));
  ok('پاورقی متن مالکیت مادی و معنوی را دارد', /حقوق مادی و معنوی/.test(home.text), home.text.slice(-600));
  ok('دارایی‌ها با نسخهٔ cache-busting ارجاع می‌شوند', /assets\/css\/app\.css\?v=\d+/.test(home.text), (home.text.match(/assets\/css\/app\.css[^"]*/)||[])[0]);
  ok('پاسخ سرور شناسهٔ پیگیری دارد', /^(1|0)$/ !== '' && String(home.headers?.['x-request-id'] ?? home.headers?.['X-Request-ID'] ?? '').length >= 12, JSON.stringify(home.headers || {}).slice(0, 240));

  const filtered = await req('/events?q=' + encodeURIComponent('رویداد آزمون'));
  ok('فیلتر جست‌وجو، رویداد را نشان می‌دهد', filtered.status === 200 && filtered.text.includes('رویداد آزمون دود'), 'status=' + filtered.status);
  const noMatch = await req('/events?q=' + encodeURIComponent('چنین متنی وجود ندارد'));
  ok('فیلتر جست‌وجو، نتیجهٔ بی‌مورد را حذف می‌کند', noMatch.status === 200 && !noMatch.text.includes('رویداد آزمون دود'), 'status=' + noMatch.status);

  // ---------- ورود ----------
  const loginPage = await req('/login');
  ok('صفحهٔ ورود کد ۲۰۰ می‌دهد', loginPage.status === 200 && !phpError(loginPage.text), 'status=' + loginPage.status);
  const csrf = /name="_csrf" value="([a-f0-9]{32,})"/.exec(loginPage.text);
  ok('توکن CSRF در فرم ورود هست', csrf !== null, loginPage.text.slice(0, 300));
  if (!csrf) throw new Error('توکن CSRF پیدا نشد');

  const badLogin = await req('/login', {
    method: 'POST',
    body: { _csrf: csrf[1], phone: '09129990001', password: 'Wrong-Password-1' },
  });
  ok('رمز نادرست به پنل کاربری نمی‌رود', !String(badLogin.headers?.location ?? '').includes('/account'), 'location=' + String(badLogin.headers?.location));
  const badLoginBack = await req('/login');
  ok('رمز نادرست پیام روشن می‌دهد', /نامعتبر|شماره|رمز/.test(badLoginBack.text), badLoginBack.text.slice(0, 200));
  ok('پس از پیام یک‌بارمصرف، flash دوباره نشان داده نمی‌شود', !/نامعتبر/.test((await req('/login')).text));
  const gated = await req('/account');
  ok('بیرون از نشست، پنل کاربری باز نمی‌شود', gated.status >= 300 && gated.status < 400, 'status=' + gated.status);

  const login = await req('/login', {
    method: 'POST',
    body: { _csrf: csrf[1], phone: '09129990001', password: 'Smoke-Test-91!' },
  });
  ok('ورود موفق به پنل کاربری می‌رود', login.status >= 300 && login.status < 400, 'status=' + login.status + ' jar=' + jar.getCookieRequestHeader().slice(0, 60));

  const account = await req('/account');
  ok('پنل کاربری باز می‌شود', account.status === 200 && !phpError(account.text), 'status=' + account.status);
  ok('پنل کاربری نام کاربر را نشان می‌دهد', account.text.includes('مدیر آزمون دود'));

  // ---------- پنل مدیریتی ----------
  const admin = await req('/admin');
  ok('داشبورد مدیریتی باز می‌شود', admin.status === 200 && !phpError(admin.text), 'status=' + admin.status);

  ok('منوی مدیر بر اساس نقش فیلتر می‌شود (مدیر رویداد)، پیوند کاربران را ندارد', admin.text.includes('/admin/events') && !admin.text.includes('/admin/users'), 'nav');

  const adminEvents = await req('/admin/events');
  ok('فهرست رویدادهای مدیریتی باز می‌شود', adminEvents.status === 200 && !phpError(adminEvents.text), 'status=' + adminEvents.status);
  ok('فهرست مدیریتی رویداد را دارد', adminEvents.text.includes('رویداد آزمون دود'));

  for (const url of ['/admin/reports', '/admin/reports/checkin', '/admin/support', '/admin/import', '/admin/events/' + seeded.event_id]) {
    const res = await req(url);
    ok('مسیر «' + url + '» بدون خطا باز می‌شود', res.status === 200 && !phpError(res.text), 'status=' + res.status + ' ' + res.text.slice(0, 160));
  }
  for (const url of ['/admin/sessions', '/admin/password-resets', '/admin/rate-limits']) {
    const res = await req(url);
    ok('مدیر رویداد از «' + url + '» باز می‌گردد', res.status === 403, 'status=' + res.status);
  }

  const settingsPage = await req('/account/settings');
  ok('تنظیمات نمایش باز می‌شود', settingsPage.status === 200 && !phpError(settingsPage.text), 'status=' + settingsPage.status);
  ok('گزینۀ حالت تیره در تنظیمات هست', /name="theme"[^>]*value="dark"/.test(settingsPage.text), settingsPage.text.slice(0, 400));
  ok('گزینۀ اندازهٔ قلم در تنظیمات هست', settingsPage.text.includes('name="font_scale"'), settingsPage.text.slice(0, 400));
  ok('صحنه‌پرداز، حالت تیره و اندازهٔ قلم را روی <html> می‌نشاند', /data-theme="[a-z]+" style="--font-scale:\d+"/.test(settingsPage.text), (settingsPage.text.match(/<html[^>]*>/)||[])[0]);
  const orgPage = await req('/account/organization');
  ok('فرم اطلاعات سازمانی باز می‌شود', orgPage.status === 200 && orgPage.text.includes('name="organization_name"'), 'status=' + orgPage.status);
  const notifPage = await req('/notifications');
  ok('مرکز اعلان‌ها باز می‌شود', notifPage.status === 200 && !phpError(notifPage.text), 'status=' + notifPage.status);
  const liveCount = await req('/admin/events/' + seeded.event_id + '/live-count.json');
  ok('شمارندهٔ زنده JSON می‌دهد', liveCount.status === 200 && /application\/json/.test(String(liveCount.headers?.['content-type'] ?? '')), 'status=' + liveCount.status);
  let liveJson = null;
  try { liveJson = JSON.parse(liveCount.text); } catch (e) { /* not json */ }
  ok('شمارندهٔ زنده، ثبت‌نام قطعی و فهرست انتظار را جدا می‌شمارد', liveJson !== null && 'confirmed' in liveJson && 'waitlisted' in liveJson, liveCount.text.slice(0, 200));
  const bulkPrint = await req('/admin/events/' + seeded.event_id + '/certificates/print');
  ok('چاپ گروهی گواهی باز می‌شود', bulkPrint.status === 200 && !phpError(bulkPrint.text), 'status=' + bulkPrint.status);
  const xlsx = await req('/admin/exports/registrations.xlsx');
  ok('خروجی XLSX ثبت‌نام‌ها دانلود می‌شود', xlsx.status === 200 && String(xlsx.text).slice(0, 2) === 'PK' && /spreadsheetml/.test(String(xlsx.headers?.['content-type'] ?? '')), 'status=' + xlsx.status + ' head=' + String(xlsx.text).slice(0, 8));

  // ---------- کنترل ورود + همگام‌سازی صف (پاسخ JSON) ----------
  const checkinPage = await req('/admin/check-in');
  ok('صفحهٔ کنترل ورود باز می‌شود', checkinPage.status === 200 && checkinPage.text.includes('scannerVideo'), 'status=' + checkinPage.status);
  const queueForm = /<form[^>]*action="([^"]+)"[^>]*id="queueForm"/.exec(checkinPage.text);
  const csrfInForm = /name="_csrf" value="([a-f0-9]{32,})"/.exec(checkinPage.text);
  ok('فرم صف آفلاین آدرس و توکن دارد', queueForm !== null && csrfInForm !== null);

  if (queueForm && csrfInForm) {
    const sync = await req(new URL(queueForm[1], 'http://localhost/').pathname, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: { _csrf: csrfInForm[1], queue: JSON.stringify([{ token: 'bogus.token', ref: 'smoke-1' }]) },
    });
    ok('همگام‌سازی صف پاسخ JSON می‌دهد', sync.status === 200 && /application\/json/.test(String(sync.headers?.['content-type'] ?? '')), 'status=' + sync.status);
    let syncJson = null;
    try { syncJson = JSON.parse(sync.text); } catch (e) { /* not json */ }
    ok('پاسخ JSON ساختار درست دارد', syncJson !== null && typeof syncJson.synced === 'number' && Array.isArray(syncJson.results), sync.text.slice(0, 200));
    ok('توکن نامعتبر به‌عنوان نتیجهٔ پردازش‌شده برمی‌گردد', syncJson !== null && syncJson.results.length === 1 && syncJson.results[0].ok === false, JSON.stringify(syncJson));
  }

  // ---------- نصب در زیرپوشه (سناریوی پیش‌فرض XAMPP) ----------
  env.setBaseUrl('http://localhost/erooydad');
  const subHome = await req('/erooydad/');
  ok('نصب زیرپوشه: صفحهٔ اصلی کد ۲۰۰ می‌دهد', subHome.status === 200 && !phpError(subHome.text), 'status=' + subHome.status + ' ' + subHome.text.slice(0, 160));
  ok('نصب زیرپوشه: پیوندها پیشوند پوشه دارند', subHome.text.includes('href="/erooydad/manifest.webmanifest"'), subHome.text.slice(0, 500));
  ok('نصب زیرپوشه: stylesheet پیشوند دارد', subHome.text.includes('/erooydad/assets/css/app.css'), subHome.text.slice(0, 500));
  // در آپاچی/زامپ، زیرپوشه از ریشهٔ وب سرو می‌شود؛ اینجا با pathAliases همان را می‌سازیم.
  const subHandler = new PHPRequestHandler({
    php: env.php,
    documentRoot: '/project/frontend/public',
    absoluteUrl: 'http://localhost/erooydad/',
    cookieStore: jar,
    pathAliases: [{ urlPrefix: '/erooydad', fsPath: '/project/frontend/public' }],
    getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/erooydad/index.php' }),
  });
  const subManifest = await subHandler.request({ url: '/erooydad/manifest.webmanifest', method: 'GET' });
  const subManifestText = typeof subManifest.text === 'string' ? subManifest.text : new TextDecoder().decode(subManifest.stdout ?? new Uint8Array());
  ok('نصب زیرپوشه: manifest سرو می‌شود', subManifest.httpStatusCode === 200 && /start_url/.test(subManifestText), 'status=' + subManifest.httpStatusCode);
  const subSw = await subHandler.request({ url: '/erooydad/sw.js', method: 'GET' });
  const subSwText = typeof subSw.text === 'string' ? subSw.text : new TextDecoder().decode(subSw.stdout ?? new Uint8Array());
  ok('نصب زیرپوشه: سرویس‌ورکر سرو می‌شود', subSw.httpStatusCode === 200 && subSwText.includes('BASE_URL'), 'status=' + subSw.httpStatusCode);
  const subPwa = await subHandler.request({ url: '/erooydad/assets/js/pwa.js', method: 'GET' });
  const subPwaText = typeof subPwa.text === 'string' ? subPwa.text : new TextDecoder().decode(subPwa.stdout ?? new Uint8Array());
  ok('نصب زیرپوشه: اسکریپت ثبت سرویس‌ورکر مسیر ریشه‌مطلق ندارد', subPwa.httpStatusCode === 200 && !/'\/sw\.js'/.test(subPwaText) && subPwaText.includes('link[rel="manifest"]'), subPwaText.slice(0, 200));
  env.setBaseUrl('http://localhost/');

  // ---------- ساخت حساب فراگیر و ثبت‌نام در رویداد از مسیر واقعی ----------
  const registerPage = await req('/register');
  ok('صفحهٔ ساخت حساب باز می‌شود', registerPage.status === 200 && !phpError(registerPage.text), 'status=' + registerPage.status);
  const registerCsrf = /name="_csrf" value="([a-f0-9]{32,})"/.exec(registerPage.text);
  ok('فرم ساخت حساب توکن CSRF دارد', registerCsrf !== null);
  if (registerCsrf) {
    const created = await req('/register', {
      method: 'POST',
      body: { _csrf: registerCsrf[1], full_name: 'فراگیر دود', phone: '09127770002', password: 'Attendee-91!', account_type: 'public' },
    });
    ok('ساخت حساب خطای PHP نمی‌دهد', !phpError(created.text), created.text.slice(0, 200));
    ok('ساخت حساب موفق است', created.status >= 300 && created.status < 400, 'status=' + created.status);
    const eventPage = await req('/events/' + seeded.slug);
    const signupForm = /<form method="post" action="(\/events\/(\d+)\/register)"/.exec(eventPage.text);
    const signupCsrf = /name="_csrf" value="([a-f0-9]{32,})"/.exec(eventPage.text);
    const idem = /name="idempotency_key" value="([^"]+)"/.exec(eventPage.text);
    ok('صفحهٔ رویداد فرم ثبت‌نام دارد', signupForm !== null && signupCsrf !== null && idem !== null);
    if (signupForm && signupCsrf && idem) {
      const joined = await req(signupForm[1], {
        method: 'POST',
        body: { _csrf: signupCsrf[1], idempotency_key: idem[1] },
      });
      ok('ثبت‌نام از مسیر واقعی خطای PHP نمی‌دهد', !phpError(joined.text), joined.text.slice(0, 200));
      ok('ثبت‌نام بازنشست صفحهٔ بعد از عملیات است', joined.status >= 300 && joined.status < 400, 'status=' + joined.status);
      const accountAfter = await req('/account');
      ok('پنل فراگیر، ثبت‌نام تازه را نشان می‌دهد', accountAfter.text.includes('رویداد آزمون دود'), accountAfter.text.slice(0, 200));
      const duplicate = await req(signupForm[1], { method: 'POST', body: { _csrf: signupCsrf[1], idempotency_key: idem[1] } });
      ok('ثبت‌نام دوباره با پیام به صفحهٔ رویداد برمی‌گردد', duplicate.status >= 300 && duplicate.status < 400, 'status=' + duplicate.status);
      const backPage = await req('/events/' + seeded.slug);
      ok('پیام تکراری بودن ثبت‌نام نشان داده می‌شود', /قبلاً/.test(backPage.text), backPage.text.slice(0, 220));
      const ics = await req('/account/calendar.ics');
      ok('تقویم شخصی با فرمت ICS دانلود می‌شود', ics.status === 200 && ics.text.includes('BEGIN:VCALENDAR') && /text\/calendar/.test(String(ics.headers?.['content-type'] ?? '')), 'status=' + ics.status + ' ' + ics.text.slice(0, 80));
      ok('رویداد ثبت‌نام‌شده در تقویم شخصی هست', ics.text.includes('SUMMARY') && /رویداد/.test(ics.text), ics.text.slice(0, 400));
      const orgPage = await req('/account/organization');
      const orgCsrf = /name="_csrf" value="([a-f0-9]{32,})"/.exec(orgPage.text);
      if (orgCsrf) {
        const orgSaved = await req('/account/organization', {
          method: 'POST',
          body: { _csrf: orgCsrf[1], organization_name: 'سازمان منطقه ۳', national_id: '', address: 'تهران، خیابان نمونه', contact_email: '', contact_phone: '02199999999' },
        });
        ok('ذخیرۀ اطلاعات سازمانی خطا نمی‌دهد', !phpError(orgSaved.text), orgSaved.text.slice(0, 200));
        const orgBack = await req('/account/organization');
            ok('اطلاعات سازمانی ذخیره و دوباره نمایش داده می‌شود', orgBack.text.includes('سازمان منطقه ۳'), orgBack.text.slice(0, 400));
      }
      const attendeeNotifications = await req('/notifications');
      ok('اعلان ثبت‌نام در مرکز اعلان‌های فراگیر دیده می‌شود', attendeeNotifications.status === 200 && /ثبت‌نام/.test(attendeeNotifications.text), attendeeNotifications.text.slice(0, 300));
    }
  }

  // ---------- مدیر سامانه: مسیرهای سراسری ----------
  const adminLoginCsrf = /name="_csrf" value="([a-f0-9]{32,})"/.exec((await req('/login')).text);
  ok('برای ورود مدیر سامانه، توکن تازهٔ CSRF گرفته می‌شود', adminLoginCsrf !== null);
  if (adminLoginCsrf) {
    const adminLogin = await req('/login', {
      method: 'POST',
      body: { _csrf: adminLoginCsrf[1], phone: '09129990003', password: 'Root-Admin-91-OK' },
    });
    ok('ورود مدیر سامانه موفق است', adminLogin.status >= 300 && adminLogin.status < 400, 'status=' + adminLogin.status);
    for (const url of ['/admin/users', '/admin/roles', '/admin/sessions', '/admin/password-resets', '/admin/audit', '/admin/rate-limits', '/admin/employees', '/admin/system']) {
      const res = await req(url);
      ok('مدیر سامانه «' + url + '» را می‌بیند', res.status === 200 && !phpError(res.text), 'status=' + res.status + ' ' + res.text.slice(0, 140));
      if (/\/admin\/users$/.test(url)) {
        ok('فرم تعلیق حساب، تأییدیهٔ دو مرحله‌ای دارد', /suspend[^>]*data-confirm=|data-confirm="[^"]*تعلیق/.test(res.text), (res.text.match(/<form[^>]*suspend[^>]*>/g) || []).join(' ').slice(0, 300));
      }
    }
    const systemPage = await req('/admin/system');
    ok('صفحهٔ سلامت، بررسی‌های مستقل را فهرست می‌کند', /دیتابیس|افزونه/.test(systemPage.text), systemPage.text.slice(0, 300));
    ok('صفحهٔ سلامت، تاریخچۀ اجرای مهاجرت را نشان می‌دهد', /مهاجرت|migration/i.test(systemPage.text), systemPage.text.slice(0, 300));
    const sessionsPage = await req('/admin/sessions');
    ok('ابطال نشست از مدیر سامانه تأییدیهٔ دو مرحله‌ای می‌خواهد', /data-confirm=/.test(sessionsPage.text), sessionsPage.text.slice(0, 300));
    const auditFiltered = await req('/admin/audit?action=login');
    ok('گزارش ممیزی با فیلتر عملیات باز می‌شود', auditFiltered.status === 200 && !phpError(auditFiltered.text), 'status=' + auditFiltered.status);
    for (const type of ['events', 'registrations', 'users', 'support', 'checkin', 'employees', 'finance']) {
      const file = await req('/admin/exports/' + type + '.xlsx');
      ok('خروجی XLSX «' + type + '» دانلود می‌شود', file.status === 200 && String(file.text).slice(0, 2) === 'PK' && /spreadsheetml/.test(String(file.headers?.['content-type'] ?? '')), 'status=' + file.status);
    }
    const supportFiltered = await req('/admin/support?status=open');
    ok('فهرست تیکت‌ها با فیلتر وضعیت باز می‌شود', supportFiltered.status === 200 && !phpError(supportFiltered.text), 'status=' + supportFiltered.status);
    ok('منوی مدیر برای مدیر سامانه، بخش کاربران را نشان می‌دهد', (await req('/admin')).text.includes('/admin/users'), 'nav');
  }
} catch (error) {
  failures.push('آزمون با خطا متوقف شد: ' + (error && error.message));
  console.error(error);
} finally {
  await env.close();
}

console.log('\n' + '='.repeat(60));
if (failures.length) {
  console.log('آزمون HTTP: ' + failures.length + ' ایراد');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
console.log('آزمون HTTP: همهٔ صفحه‌ها و مسیرهای PWA درست سرو شدند.');
process.exit(0);
