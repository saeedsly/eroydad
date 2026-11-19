/**
 * آزمون پذیرش (Acceptance) — سفرهای کامل از دید کاربر، روی فرانت‌کنترل واقعی.
 *
 * سفرها:
 *   A) فراگیر: ساخت حساب ← ورود ← ثبت‌نام رایگان ← پنل ← بلیت ← بازخورد ← تیکت پشتیبانی ← لغو
 *   B) پولی: ثبت‌نام ← پرداخت ناموفق ← ادامه ← پرداخت موفق ← رسید SBX ← قطعی‌شدن
 *   C) مدیر: داشبورد ← خروجی XLSX واقعی (بازکردن zip و درستی XML داخل آن) ← گزارش‌ها
 *   D) مدیر: ساخت دوره فقط با فیلدهای تاریخ شمسی (بدون JS) ← انتشار ← ثبت‌نام فراگیر روی همان دوره
 *   E) مهمان: لینک رویداد ← فرم ثبت‌نام سریع بدون ورود ← حساب تازه ← پنل (و رد شمارهٔ دارای حساب)
 *
 * اجرا:  node tests/acceptance-run.mjs [مسیر پروژه]
 */
import { PHPRequestHandler } from '@php-wasm/universal';
import { createTestEnvironment } from './support/harness.mjs';
import { inflateRawSync } from 'zlib';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));

const failures = [];
let exitCode = 0;
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
  // ---------- دانه‌گذاری: مدیر + رویداد رایگان + رویداد پولی ----------
  const seed = await env.php.run({
    code: `<?php
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Events/EventService.php';
use Eroydad\\Events\\EventService;
$db = db();
$hash = password_hash(passwordMaterial('Root-Admin-91-OK'), PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status,phone_verified_at) VALUES ('09120004003','مدیر پذیرش',?,'municipal','active',UTC_TIMESTAMP())")->execute([$hash]);
$sys = (int)$db->lastInsertId();
$role = $db->prepare("SELECT id FROM roles WHERE code='system_admin'");
$role->execute();
$db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$sys, (int)$role->fetchColumn()]);
$future = (new DateTimeImmutable('@' . (time() + 86400 * 7)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$later = (new DateTimeImmutable('@' . (time() + 86400 * 7 + 7200)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$events = new EventService($db);
$freeId = $events->create($sys, [
  'title' => 'دورهٔ رایگان پذیرش',
  'summary' => 'رویداد رایگان برای سفر کامل ثبت‌نام و بلیت و بازخورد.',
  'description' => 'شرح کامل دورهٔ رایگان پذیرش برای آزمون سفر کامل فراگیر در این مخزن آزمون.',
  'category' => 'آزمون',
  'delivery_mode' => 'in_person',
  'venue' => 'سالن پذیرش',
  'capacity' => '20',
  'price_toman' => '0',
  'starts_at' => $future,
  'ends_at' => $later,
]);
$events->publish($freeId, $sys, false);
$paidId = $events->create($sys, [
  'title' => 'دورهٔ پولی پذیرش',
  'summary' => 'رویداد پولی برای سفر پرداخت آزمایشی و رسید.',
  'description' => 'شرح کامل دورهٔ پولی پذیرش برای آزمون سفر پرداخت آزمایشی و صدور رسید در این مخزن.',
  'category' => 'آزمون',
  'delivery_mode' => 'online',
  'venue' => 'آنلاین',
  'capacity' => '20',
  'price_toman' => '120000',
  'tax_percent' => '10',
  'tax_payer' => 'participant',
  'starts_at' => $future,
  'ends_at' => $later,
]);
$events->publish($paidId, $sys, false);
$freeSlug = (string)$db->query('SELECT slug FROM events WHERE id=' . (int)$freeId)->fetchColumn();
$paidSlug = (string)$db->query('SELECT slug FROM events WHERE id=' . (int)$paidId)->fetchColumn();
echo 'SEED ' . json_encode(['sys'=>$sys,'free_id'=>$freeId,'free_slug'=>$freeSlug,'paid_id'=>$paidId,'paid_slug'=>$paidSlug]) . "\\n";
`,
  });
  const seedText = typeof seed.text === 'string' ? seed.text : new TextDecoder().decode(seed.stdout ?? new Uint8Array());
  const seedMatch = /SEED (\{.*\})/.exec(seedText);
  const seeded = seedMatch ? JSON.parse(seedMatch[1]) : null;
  ok('دادهٔ آزمون پذیرش ساخته شد', seeded !== null, seedText + new TextDecoder().decode(seed.stderr ?? new Uint8Array()));
  if (!seeded) throw new Error('دانه‌گذاری ناموفق بود');

  const jar = new CookieJar();
  const handler = new PHPRequestHandler({
    php: env.php,
    documentRoot: '/project/frontend/public',
    absoluteUrl: 'http://localhost/',
    cookieStore: jar,
    getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
  });

  async function req(url, options = {}) {
    let body = options.body;
    let headers = { ...(options.headers || {}) };
    if (body && typeof body === 'object' && !(body instanceof Uint8Array)) {
      const params = new URLSearchParams();
      for (const [k, v] of Object.entries(body)) params.append(k, String(v));
      body = new TextEncoder().encode(params.toString());
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }
    const response = await handler.request({ url, method: options.method || 'GET', body, headers });
    const pick = (v) => (v instanceof Uint8Array ? v : (v instanceof ArrayBuffer ? new Uint8Array(v) : null));
    const bytes = pick(response.bytes) || pick(response.stdout) || pick(response.body);
    const text = typeof response.text === 'string' ? response.text : (bytes ? new TextDecoder().decode(bytes) : '');
    return {
      status: response.httpStatusCode,
      text,
      headers: response.headers,
      bytes,
      location: String(response.headers?.location ?? response.headers?.Location ?? ''),
    };
  }

  const csrfOf = (html) => {
    const m = /name="_csrf" value="([^"]+)"/.exec(html);
    return m ? m[1] : null;
  };
  const phpError = (text) => /Fatal error|Parse error|Uncaught|Warning:<\/b>|Notice:<\/b>/i.test(text);

  // ================= سفر A: فراگیر =================
  console.log('--- سفر A: فراگیر (ثبت‌نام رایگان تا بازخورد و لغو) ---');
  const regPage = await req('/register');
  const regToken = csrfOf(regPage.text);
  const doRegister = await req('/register', {
    method: 'POST',
    body: { phone: '09120004001', full_name: 'فراگیر پذیرش', password: 'Journey-Pass-91!', account_type: 'public', national_id: '', _csrf: regToken || '' },
  });
  ok('ساخت حساب، کاربر را وارد پنل می‌کند', doRegister.status === 303 && doRegister.location.includes('/account'), 'status=' + doRegister.status + ' loc=' + doRegister.location);

  const freeEvent = await req('/events/' + seeded.free_slug);
  ok('صفحهٔ رویداد رایگان باز می‌شود', freeEvent.status === 200 && !phpError(freeEvent.text), 'status=' + freeEvent.status);
  const joinToken = csrfOf(freeEvent.text);
  const idemMatch = /name="idempotency_key" value="([^"]+)"/.exec(freeEvent.text);
  ok('فرم ثبت‌نام رویداد فعال است', Boolean(joinToken && idemMatch), freeEvent.text.slice(0, 300));
  const join = await req('/events/' + seeded.free_id + '/register', {
    method: 'POST',
    body: { idempotency_key: idemMatch ? idemMatch[1] : '', _csrf: joinToken || '' },
  });
  ok('ثبت‌نام رایگان انجام می‌شود (303 → پنل)', join.status === 303 && join.location.includes('/account'), 'status=' + join.status + ' loc=' + join.location);

  const account = await req('/account');
  ok('پنل فراگیر ثبت‌نام را نشان می‌دهد', account.status === 200 && account.text.includes('دورهٔ رایگان پذیرش'), 'status=' + account.status);
  const cancelMatch = /action="\/account\/registrations\/(\d+)\/cancel"/.exec(account.text);
  ok('شناسهٔ ثبت‌نام از پنل استخراج می‌شود', Boolean(cancelMatch));
  const registrationId = cancelMatch ? Number(cancelMatch[1]) : 0;

  const eventAfterJoin = await req('/events/' + seeded.free_slug);
  ok('صفحهٔ رویداد وضعیت قطعی را نشان می‌دهد', eventAfterJoin.text.includes('قطعی شده است'), eventAfterJoin.text.slice(0, 400));
  const ticketMatch = /href="\/tickets\/(\d+)\/print"/.exec(eventAfterJoin.text);
  ok('پیوند بلیت ظاهر می‌شود', Boolean(ticketMatch));
  const ticketId = ticketMatch ? Number(ticketMatch[1]) : 0;

  const ticketPrint = await req('/tickets/' + ticketId + '/print');
  ok('صفحهٔ چاپ بلیت سالم باز می‌شود', ticketPrint.status === 200 && !phpError(ticketPrint.text) && /بلیت/.test(ticketPrint.text), 'status=' + ticketPrint.status);

  const fbForm = await req('/feedback/' + registrationId);
  ok('فرم بازخورد برای مالک باز می‌شود', fbForm.status === 200 && !phpError(fbForm.text), 'status=' + fbForm.status);
  const fbToken = csrfOf(fbForm.text);
  const fbSubmit = await req('/feedback/' + registrationId, {
    method: 'POST',
    body: { rating: '5', comment: 'دوره عالی بود، سپاس.', _csrf: fbToken || '' },
  });
  ok('ثبت بازخورد انجام می‌شود (303 → پنل)', fbSubmit.status === 303 && fbSubmit.location.includes('/account'), 'status=' + fbSubmit.status);
  const fbAgain = await req('/feedback/' + registrationId);
  ok('بازخورد ثبت‌شده در فرم بازتاب دارد', fbAgain.status === 200 && /selected/.test(fbAgain.text) && fbAgain.text.includes('دوره عالی بود'), fbAgain.text.slice(0, 300));

  const supportToken = csrfOf((await req('/support')).text);
  const supportCreate = await req('/support', {
    method: 'POST',
    body: { subject: 'سوال دربارهٔ بلیت', body: 'سلام، بلیت من برای چاپ آماده است؟', event_id: '', _csrf: supportToken || '' },
  });
  ok('تیکت پشتیبانی ساخته می‌شود (303 → جزئیات)', supportCreate.status === 303 && /\/support\/\d+/.test(supportCreate.location), 'status=' + supportCreate.status + ' loc=' + supportCreate.location);
  const ticketUrl = supportCreate.location;
  const supportDetail = await req(ticketUrl);
  ok('جزئیات تیکت با موضوع درست نمایش داده می‌شود', supportDetail.status === 200 && supportDetail.text.includes('سوال دربارهٔ بلیت'), 'status=' + supportDetail.status);

  const cancelToken = csrfOf(account.text);
  const cancel = await req('/account/registrations/' + registrationId + '/cancel', {
    method: 'POST',
    body: { _csrf: cancelToken || '' },
  });
  ok('لغو ثبت‌نام انجام می‌شود (303)', cancel.status === 303, 'status=' + cancel.status);
  const afterCancel = await req('/events/' + seeded.free_slug);
  ok('بعد از لغو، جایگاه آزاد و دکمهٔ ثبت‌نام برگشته است', !afterCancel.text.includes('قطعی شده است') && /name="idempotency_key"/.test(afterCancel.text), afterCancel.text.slice(0, 400));

  // ================= سفر B: پولی =================
  console.log('--- سفر B: پرداخت آزمایشی (شکست ← ادامه ← موفق) ---');
  const paidEvent = await req('/events/' + seeded.paid_slug);
  const payToken = csrfOf(paidEvent.text);
  const payIdem = /name="idempotency_key" value="([^"]+)"/.exec(paidEvent.text);
  const paidJoin = await req('/events/' + seeded.paid_id + '/register', {
    method: 'POST',
    body: { idempotency_key: payIdem ? payIdem[1] : '', _csrf: payToken || '' },
  });
  const payLoc = paidJoin.location;
  ok('ثبت‌نام پولی به صفحهٔ پرداخت می‌رود', paidJoin.status === 303 && /\/payments\/\d+\/sandbox/.test(payLoc), 'status=' + paidJoin.status + ' loc=' + payLoc);
  const paymentId = Number((/\/payments\/(\d+)\/sandbox/.exec(payLoc) || [])[1] || 0);

  const sandboxPage = await req('/payments/' + paymentId + '/sandbox');
  ok('صفحهٔ درگاه آزمایشی باز می‌شود', sandboxPage.status === 200 && sandboxPage.text.includes('آزمایشی'), 'status=' + sandboxPage.status);
  const sandboxToken = csrfOf(sandboxPage.text);
  const failPay = await req('/payments/' + paymentId + '/sandbox', {
    method: 'POST',
    body: { scenario: 'failure', _csrf: sandboxToken || '' },
  });
  ok('سناریوی ناموفق پذیرفته می‌شود (303)', failPay.status === 303, 'status=' + failPay.status);
  const pendingPage = await req('/events/' + seeded.paid_slug);
  ok('بعد از شکست، هشدار «هنوز پرداخت نشده» می‌بینیم', pendingPage.text.includes('هنوز پرداخت نشده است'), pendingPage.text.slice(0, 400));
  ok('دکمهٔ ادامهٔ پرداخت آزمایشی هست', pendingPage.text.includes('ادامهٔ پرداخت آزمایشی'));

  const retryToken = csrfOf((await req('/payments/' + paymentId + '/sandbox')).text);
  const successPay = await req('/payments/' + paymentId + '/sandbox', {
    method: 'POST',
    body: { scenario: 'success', _csrf: retryToken || '' },
  });
  ok('پرداخت موفق انجام می‌شود (303)', successPay.status === 303, 'status=' + successPay.status);
  const confirmedPage = await req('/events/' + seeded.paid_slug);
  ok('بعد از موفقیت، ثبت‌نام قطعی می‌شود', confirmedPage.text.includes('قطعی شده است'), confirmedPage.text.slice(0, 400));
  ok('کد پیگیری SBX در صفحه هست', /SBX-[0-9A-F]{12}/.test(confirmedPage.text), confirmedPage.text.slice(0, 400));

  const receipt = await req('/payments/' + paymentId + '/receipt');
  ok('رسید آزمایشی باز می‌شود', receipt.status === 200 && !phpError(receipt.text), 'status=' + receipt.status);
  ok('رسید ریز مبلغ و مالیات دارد', receipt.text.includes('120,000') && receipt.text.includes('12,000') && receipt.text.includes('132,000'), receipt.text.slice(0, 600));

  const paymentsList = await req('/account/payments');
  ok('فهرست پرداخت‌ها سند را نشان می‌دهد', paymentsList.status === 200 && paymentsList.text.includes('SBX-'), 'status=' + paymentsList.status);

  // ================= سفر C: مدیر =================
  console.log('--- سفر C: مدیر (داشبورد و خروجی XLSX) ---');
  const loginPage = await req('/login');
  const loginToken = csrfOf(loginPage.text);
  const adminLogin = await req('/login', {
    method: 'POST',
    body: { phone: '09120004003', password: 'Root-Admin-91-OK', _csrf: loginToken || '', next: '' },
  });
  ok('ورود مدیر انجام می‌شود (303)', adminLogin.status === 303, 'status=' + adminLogin.status);

  const dashboard = await req('/admin');
  ok('داشبورد مدیر باز می‌شود', dashboard.status === 200 && !phpError(dashboard.text), 'status=' + dashboard.status);

  const xlsx = await req('/admin/exports/events.xlsx');
  ok('خروجی XLSX دانلود می‌شود', xlsx.status === 200 && /spreadsheetml/.test(String(xlsx.headers?.['content-type'] ?? '')), 'status=' + xlsx.status + ' ct=' + String(xlsx.headers?.['content-content-type'] ?? xlsx.headers?.['content-type']));

  // بازکردن واقعی zip در حافظه و درستی XMLها
  let zipOk = false;
  let zipDetail = '';
  try {
    const buf = Buffer.from(xlsx.bytes || []);
    zipDetail = 'size=' + buf.length + ' head=' + buf.slice(0, 2).toString('latin1');
    const entries = new Map();
    let p = 0;
    while (p + 30 <= buf.length && buf.readUInt32LE(p) === 0x04034b50) {
      const method = buf.readUInt16LE(p + 8);
      const compSize = buf.readUInt32LE(p + 18);
      const nameLen = buf.readUInt16LE(p + 26);
      const extraLen = buf.readUInt16LE(p + 28);
      const name = buf.slice(p + 30, p + 30 + nameLen).toString('utf8');
      const dataStart = p + 30 + nameLen + extraLen;
      const raw = buf.slice(dataStart, dataStart + compSize);
      const data = method === 8 ? inflateRawSync(raw) : raw;
      entries.set(name, data.toString('utf8'));
      p = dataStart + compSize;
    }
    const hasTypes = entries.has('[Content_Types].xml');
    const workbook = entries.get('xl/workbook.xml') || '';
    const sheet = entries.get('xl/worksheets/sheet1.xml') || '';
    zipOk = hasTypes && /<sheet\b/.test(workbook) && /<worksheet\b/.test(sheet) && /<Relationships\b/.test(entries.get('xl/_rels/workbook.xml.rels') || '');
    zipDetail += ' entries=' + entries.size + ' types=' + hasTypes + ' workbookSheet=' + /<sheet\b/.test(workbook) + ' worksheet=' + /<worksheet\b/.test(sheet);
  } catch (error) {
    zipDetail += ' error=' + (error && error.message);
  }
  ok('XLSX واقعی است: ساختار zip و XML داخل آن درست است', zipOk, zipDetail);

  const reports = await req('/admin/reports');
  ok('صفحهٔ گزارش‌ها باز می‌شود', reports.status === 200 && !phpError(reports.text), 'status=' + reports.status);

  // ================= سفر D: ساخت دوره با فیلد تاریخ شمسی (بدون JS) و ثبت‌نام فراگیر =================
  console.log('--- سفر D: ساخت دوره با تاریخ شمسی ← انتشار ← ثبت‌نام فراگیر ---');
  const newForm = await req('/admin/events/new');
  ok('فرم ساخت رویداد برای مدیر باز می‌شود', newForm.status === 200 && !phpError(newForm.text), 'status=' + newForm.status);
  // عمداً بدون فیلد مخفی starts_at/ends_at: فقط تاریخ شمسیِ قابل‌مشاهده + ساعت (شبیه مرورگری که JS ندارد)
  const createEvent = await req('/admin/events/new', {
    method: 'POST',
    body: {
      title: 'دورهٔ ساخته‌شده در آزمون پذیرش',
      summary: 'خلاصهٔ کافی برای قبولی اعتبارسنجی سمت سرور در مسیر ساخت دوره.',
      description: 'شرح کامل این دوره برای پوشش مسیر ساخت با فیلدهای تاریخ شمسی بدون جاوااسکریپت است.',
      category: 'آزمون',
      delivery_mode: 'online',
      venue: '',
      capacity: '20',
      price_toman: '0',
      tax_percent: '0',
      tax_payer: 'participant',
      starts_at: '',
      ends_at: '',
      starts_at_date: '۱۴۰۵/۱۲/۱۵',
      starts_at_time: '10:00',
      ends_at_date: '۱۴۰۵/۱۲/۱۵',
      ends_at_time: '12:00',
      _csrf: csrfOf(newForm.text),
    },
  });
  ok('ساخت دوره فقط با تاریخ شمسی + ساعت (بدون فیلد مخفی JS) موفق است',
    createEvent.status === 303 && /\/admin\/events\/\d+/.test(createEvent.location || ''),
    'status=' + createEvent.status + ' loc=' + createEvent.location);
  const newEventId = /\/admin\/events\/(\d+)/.exec(createEvent.location || '');
  ok('شناسهٔ دورهٔ تازه از هدایت پاسخ خوانده می‌شود', !!newEventId, createEvent.location || '');
  const detailNew = await req('/admin/events/' + (newEventId ? newEventId[1] : '0'));
  ok('صفحهٔ جزئیات دورهٔ تازه باز می‌شود و عنوانش را دارد',
    detailNew.status === 200 && detailNew.text.includes('دورهٔ ساخته‌شده در آزمون پذیرش') && !phpError(detailNew.text),
    'status=' + detailNew.status);
  const publish = await req('/admin/events/' + (newEventId ? newEventId[1] : '0') + '/publish', {
    method: 'POST',
    body: { _csrf: csrfOf(detailNew.text) },
  });
  ok('انتشار دورهٔ تازه انجام می‌شود (303)', publish.status === 303, 'status=' + publish.status);
  const newSlugRow = await env.php.run({
    code: `<?php require '/project/backend/bootstrap.php'; echo 'SLUG ' . db()->query('SELECT slug FROM events WHERE id=' . (int)${newEventId ? newEventId[1] : 0})->fetchColumn();`,
  });
  const newSlugText = typeof newSlugRow.text === 'string' ? newSlugRow.text : new TextDecoder().decode(newSlugRow.stdout ?? new Uint8Array());
  const newSlug = /SLUG ([a-z0-9-]+)/.exec(newSlugText);
  ok('slug دورهٔ تازه خوانده شد', !!newSlug, newSlugText.slice(0, 200));

  // حالا از دید فراگیر: خروج مدیر، ورود فراگیر، ثبت‌نام روی همان دوره
  const logout = await req('/logout', { method: 'POST', body: { _csrf: csrfOf(detailNew.text) } });
  ok('خروج مدیر انجام می‌شود (303)', logout.status === 303, 'status=' + logout.status);
  const attendeeLoginPage = await req('/login');
  const attendeeLogin = await req('/login', {
    method: 'POST',
    body: { phone: '09120004001', password: 'Journey-Pass-91!', _csrf: csrfOf(attendeeLoginPage.text), next: '' },
  });
  ok('ورود فراگیر برای ثبت‌نام روی دورهٔ تازه (303)', attendeeLogin.status === 303 && (attendeeLogin.location || '').includes('/account'),
    'status=' + attendeeLogin.status + ' loc=' + attendeeLogin.location);
  const newPublic = await req('/events/' + (newSlug ? newSlug[1] : 'x'));
  ok('صفحهٔ عمومی دورهٔ تازه برای فراگیر ۲۰۰ می‌دهد',
    newPublic.status === 200 && newPublic.text.includes('دورهٔ ساخته‌شده در آزمون پذیرش') && !phpError(newPublic.text),
    'status=' + newPublic.status);
  const newJoinToken = csrfOf(newPublic.text);
  const newIdem = /name="idempotency_key" value="([^"]+)"/.exec(newPublic.text);
  const newJoin = await req('/events/' + (newEventId ? newEventId[1] : '0') + '/register', {
    method: 'POST',
    body: { idempotency_key: newIdem ? newIdem[1] : '', _csrf: newJoinToken || '' },
  });
  ok('فراگیر می‌تواند روی دورهٔ تازه ثبت‌نام کند (303 → پنل)',
    newJoin.status === 303 && (newJoin.location || '').includes('/account'),
    'status=' + newJoin.status + ' loc=' + newJoin.location);
  const newAccount = await req('/account');
  ok('نام دورهٔ تازه در پنل فراگیر دیده می‌شود',
    newAccount.status === 200 && newAccount.text.includes('دورهٔ ساخته‌شده در آزمون پذیرش'),
    'status=' + newAccount.status);

  // ================= سفر E: ثبت‌نام سریع بدون ورود از لینک رویداد =================
  console.log('--- سفر E: مهمان ← فرم ثبت‌نام سریع بدون ورود ← تأیید در پنل ---');
  const logout2 = await req('/logout', { method: 'POST', body: { _csrf: csrfOf(newAccount.text) } });
  ok('خروج فراگیر برای تست مهمان (303)', logout2.status === 303, 'status=' + logout2.status);
  const guestEvent = await req('/events/' + (newSlug ? newSlug[1] : 'x'));
  ok('مهمان فرم ثبت‌نام سریع را در لینک رویداد می‌بیند',
    guestEvent.status === 200 && guestEvent.text.includes('/quick-register') && guestEvent.text.includes('ثبت‌نام سریع'),
    'status=' + guestEvent.status);
  const quickCsrf = csrfOf(guestEvent.text);
  const quickJoin = await req('/events/' + (newSlug ? newSlug[1] : 'x') + '/quick-register', {
    method: 'POST',
    body: { full_name: 'فراگیر سریع', phone: '09120007777', password: 'Quick-Register-91!', _csrf: quickCsrf || '' },
  });
  ok('ثبت‌نام سریع مهمان بدون ورود قبلی انجام می‌شود (303 → پنل)',
    quickJoin.status === 303 && (quickJoin.location || '').includes('/account'),
    'status=' + quickJoin.status + ' loc=' + quickJoin.location);
  const quickAccount = await req('/account');
  ok('پس از ثبت‌نام سریع، دوره در پنل کاربر تازه دیده می‌شود',
    quickAccount.status === 200 && quickAccount.text.includes('دورهٔ ساخته‌شده در آزمون پذیرش') && quickAccount.text.includes('فراگیر سریع'),
    'status=' + quickAccount.status);

  // شمارهٔ دارای حساب، با رمز اشتباه پذیرفته نمی‌شود
  const logout3 = await req('/logout', { method: 'POST', body: { _csrf: csrfOf(quickAccount.text) } });
  ok('خروج کاربر تازه (303)', logout3.status === 303, 'status=' + logout3.status);
  const guestAgain = await req('/events/' + (newSlug ? newSlug[1] : 'x'));
  const dupJoin = await req('/events/' + (newSlug ? newSlug[1] : 'x') + '/quick-register', {
    method: 'POST',
    body: { full_name: 'تلاش تکراری', phone: '09120004001', password: 'Wrong-Password-91!', _csrf: csrfOf(guestAgain.text) || '' },
  });
  ok('شمارهٔ دارای حساب با رمز اشتباه رد می‌شود و به صفحهٔ رویداد برمی‌گردد',
    dupJoin.status === 303 && (dupJoin.location || '').includes('/events/'),
    'status=' + dupJoin.status + ' loc=' + dupJoin.location);
  const dupBack = await req('/events/' + (newSlug ? newSlug[1] : 'x'));
  ok('پیام «قبلاً حساب دارد» به کاربر نمایش داده می‌شود',
    dupBack.text.includes('قبلاً حساب دارد'), dupBack.text.slice(0, 400));

  console.log('');
  if (failures.length) {
    console.log('آزمون پذیرش: ' + failures.length + ' ایراد.');
    exitCode = 1;
  } else {
    console.log('آزمون پذیرش: هر پنج سفر کامل بدون خطا اجرا شد.');
  }
} catch (error) {
  console.log('آزمون پذیرش با خطا متوقف شد: ' + (error && error.message));
  exitCode = 1;
} finally {
  await env.close().catch(() => {});
}
process.exit(exitCode);
