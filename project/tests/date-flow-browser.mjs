/**
 * آزمون جریان تاریخ در مرورگر واقعی — پوشش باگ «تاریخ/ساعت معتبر نیست».
 *
 * سه مسیر واقعی را علیه فرانت‌کنترل واقعی (PHP 7.4 روی WASM + MariaDB) می‌سنجد:
 *   ۱) پرکردن فرم ساخت رویداد در Chromium و ارسال با جاوااسکریپت (مسیر تاریخ‌شمار).
 *   ۲) POST بدون جاوااسکریپت فقط با فیلدهای تاریخ شمسی + ساعت (مسیر جایگزین سرور).
 *   ۳) ساعت خالی → پیام فارسی روشن (نه سکوت و نه خطای مبهم).
 * همچنین از صفحهٔ اصلی و فرم، اسکرین‌شات موضوع می‌گیرد.
 *
 * اجرا:  node tests/date-flow-browser.mjs [مسیر پروژه]
 */
import { PHPRequestHandler } from '@php-wasm/universal';
import { createTestEnvironment } from './support/harness.mjs';
import { createServer } from 'http';
import { createRequire } from 'module';
import { existsSync, mkdirSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));
const SHOT_DIR = path.join(ROOT, 'tests', 'artifacts');

const failures = [];
function ok(label, condition, detail = '') {
  if (condition) { console.log('PASS  ' + label); return; }
  failures.push(label + (detail ? ' :: ' + String(detail).slice(0, 300) : ''));
  console.log('FAIL  ' + label + (detail ? ' :: ' + String(detail).slice(0, 300) : ''));
}

const env = await createTestEnvironment({ root: ROOT, withSchema: true, baseUrl: 'http://localhost/' });
let server;
let browser;
try {
  // ---------- دانه‌گذاری: مدیر سامانه + رویداد آینده ----------
  const seed = await env.php.run({
    code: `<?php
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Events/EventService.php';
use Eroydad\\Events\\EventService;
$db = db();
$hash = password_hash(passwordMaterial('Root-Admin-91-OK'), PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status,phone_verified_at) VALUES (?,?,?,'municipal','active',UTC_TIMESTAMP())");
$stmt->execute(['09120004003', 'مدیر جریان تاریخ', $hash]);
$uid = (int)$db->lastInsertId();
$role = $db->prepare("SELECT id FROM roles WHERE code='system_admin'");
$role->execute();
$db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$uid, (int)$role->fetchColumn()]);
echo 'SEED ok';
`,
  });
  const seedText = typeof seed.text === 'string' ? seed.text : new TextDecoder().decode(seed.stdout ?? new Uint8Array());
  ok('دانه‌گذاری مدیر انجام شد', /SEED ok/.test(seedText), seedText.slice(0, 300));

  // ---------- سرور HTTP محلی ↔ فرانت‌کنترل واقعی ----------
  const jar = {
    pairs: new Map(),
    rememberCookiesFromResponseHeaders(h) {
      for (const [n, vs] of Object.entries(h || {})) {
        if (String(n).toLowerCase() !== 'set-cookie') continue;
        for (const v of vs || []) {
          const pair = String(v).split(';')[0];
          const i = pair.indexOf('=');
          if (i > 0) this.pairs.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
        }
      }
    },
    getCookieRequestHeader() { return [...this.pairs.entries()].map(([k, v]) => k + '=' + v).join('; '); },
  };
  const handler = new PHPRequestHandler({
    php: env.php,
    documentRoot: '/project/frontend/public',
    absoluteUrl: 'http://localhost/',
    cookieStore: jar,
    getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
  });
  server = createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', async () => {
      try {
        const body = Buffer.concat(chunks);
        const response = await handler.request({
          url: req.url,
          method: req.method,
          body: body.length ? new Uint8Array(body) : undefined,
          headers: Object.fromEntries(Object.entries(req.headers).filter(([k]) => k !== 'host' && k !== 'connection')),
        });
        const bytes = response.bytes instanceof Uint8Array ? response.bytes
          : (response.stdout instanceof Uint8Array ? response.stdout
          : (response.body instanceof Uint8Array ? response.body : new Uint8Array()));
        res.statusCode = response.httpStatusCode;
        for (const [name, value] of Object.entries(response.headers || {})) {
          if (name === 'content-length' || name === 'transfer-encoding' || name === 'content-encoding') continue;
          if (Array.isArray(value)) res.setHeader(name, value);
          else res.setHeader(name, String(value));
        }
        res.end(Buffer.from(bytes));
      } catch (error) {
        res.statusCode = 500;
        res.end('proxy error: ' + (error && error.message));
      }
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const baseUrl = 'http://127.0.0.1:' + server.address().port;
  console.log('سرور محلی: ' + baseUrl);

  // ---------- Chromium ----------
  const chromium = require('@sparticuz/chromium');
  const lambdafs = require('@sparticuz/chromium/build/lambdafs.js');
  const helper = require('@sparticuz/chromium/build/helper.js');
  const binDir = path.join(path.dirname(require.resolve('@sparticuz/chromium/package.json')), 'bin');
  const inflate = lambdafs.default ?? lambdafs;
  if (!existsSync('/tmp/al2023/lib')) await inflate.inflate(path.join(binDir, 'al2023.tar.br'));
  if (!existsSync('/tmp/fonts')) await inflate.inflate(path.join(binDir, 'fonts.tar.br'));
  helper.setupLambdaEnvironment('/tmp/al2023/lib');
  const puppeteer = require('puppeteer-core');
  browser = await puppeteer.launch({ executablePath: await chromium.executablePath(), args: chromium.args, headless: true });

  const page = await browser.newPage();
  await page.setViewport({ width: 1320, height: 900 });
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(String(e)));

  // ---------- مسیر ۱: ورود ----------
  const trace = [];
  page.on('response', (res) => {
    const u = res.url();
    if (u.includes('/login') || res.status() >= 400) trace.push(res.request().method() + ' ' + u.replace(baseUrl, '') + ' → ' + res.status());
  });
  await page.goto(baseUrl + '/login', { waitUntil: 'networkidle2' });
  await page.type('input[name="phone"]', '09120004003');
  await page.type('input[name="password"]', 'Root-Admin-91-OK');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => null),
    page.click('form button[type="submit"]'),
  ]);
  const loginState = { url: page.url(), trace, body: await page.evaluate(() => document.body.innerText.slice(0, 200)) };
  ok('ورود مدیر در مرورگر واقعی', /\/(admin|account)/.test(page.url()), JSON.stringify(loginState));

  // ---------- مسیر ۱: فرم ساخت با جاوااسکریپت ----------
  await page.goto(baseUrl + '/admin/events/new', { waitUntil: 'networkidle2' });
  const title = 'جریان تاریخ ' + Date.now();
  await page.type('input[name="title"]', title);
  await page.type('input[name="summary"]', 'خلاصهٔ کافی برای اعتبارسنجی سمت سرور در آزمون جریان تاریخ.');
  await page.type('textarea[name="description"]', 'شرح کامل آزمون جریان تاریخ در مرورگر واقعی برای پوشش باگ گزارش‌شدهٔ کاربر.');
  await page.type('input[name="category"]', 'آزمون');
  await page.type('input[name="venue"]', 'سالن جریان تاریخ');

  // تاریخ شمسیِ هفت روز آینده
  function g2j(gy, gm, gd) {
    const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    let jy = gy <= 1600 ? 0 : 979;
    gy -= gy <= 1600 ? 621 : 1600;
    const gy2 = gm > 2 ? gy + 1 : gy;
    let days = 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
      + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
    jy += 33 * Math.floor(days / 12053);
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    const jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
    const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
    return [jy, jm, jd];
  }
  const fut = new Date(Date.now() + 7 * 86400000);
  const [jy, jm, jd] = g2j(fut.getFullYear(), fut.getMonth() + 1, fut.getDate());
  const dateStr = `${jy}/${String(jm).padStart(2, '0')}/${String(jd).padStart(2, '0')}`;
  await page.click('input[name="starts_at_date"]', { clickCount: 3 });
  await page.type('input[name="starts_at_date"]', dateStr);
  await page.click('input[name="ends_at_date"]', { clickCount: 3 });
  await page.type('input[name="ends_at_date"]', dateStr);
  await page.evaluate(() => {
    const set = (sel, v) => {
      const el = document.querySelector(sel);
      el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    set('input[name="starts_at_time"]', '10:00');
    set('input[name="ends_at_time"]', '12:00');
  });

  const hiddenState = await page.evaluate(() => ({
    s: document.querySelector('input[name="starts_at"]')?.value ?? null,
    e: document.querySelector('input[name="ends_at"]')?.value ?? null,
  }));
  ok('فیلد مخفی با فرمت صحیح YYYY-MM-DDTHH:MM ساخته می‌شود',
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(hiddenState.s || '') && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(hiddenState.e || ''),
    JSON.stringify(hiddenState));

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => null),
    page.click('form[data-jalali-form] button[type="submit"]'),
  ]);
  const jsUrl = page.url();
  const jsBody = await page.evaluate(() => document.body.innerText);
  ok('ارسال فرم با جاوااسکریپت به صفحهٔ رویداد می‌رسد (بدون خطای تاریخ)',
    /\/admin\/events\/\d+/.test(jsUrl) && !/تاریخ\/ساعت معتبر نیست|تاریخ شمسی و ساعت/.test(jsBody),
    'url=' + jsUrl + ' flash=' + (jsBody.match(/پیش‌نویس[^\n]*|تاریخ[^\n]*/) || [''])[0]);

  // اسکرین‌شات فرم (شواهد بصری)
  try {
    mkdirSync(SHOT_DIR, { recursive: true });
    await page.goto(baseUrl + '/admin/events/new', { waitUntil: 'networkidle2' });
    await page.screenshot({ path: path.join(SHOT_DIR, 'event-form-theme.png') });
  } catch (e) { console.log('  (اسکرین‌شات فرم نشد: ' + e.message + ')'); }

  // ---------- مسیر ۲: بدون جاوااسکریپت ----------
  const rawPost = await page.evaluate(async (dateStr) => {
    const csrf = document.querySelector('input[name="_csrf"]')?.value || '';
    const body = new URLSearchParams({
      title: 'بدون جاوااسکریپت ' + Date.now(),
      summary: 'خلاصهٔ کافی برای اعتبارسنجی سمت سرور در مسیر بدون جاوااسکریپت.',
      description: 'شرح کامل آزمون ارسال خام بدون جاوااسکریپت برای بررسی مسیر جایگزین سمت سرور.',
      category: 'آزمون', delivery_mode: 'online', venue: '', capacity: '20',
      price_toman: '0', tax_percent: '0', tax_payer: 'participant',
      starts_at: '', ends_at: '',
      starts_at_date: dateStr, starts_at_time: '10:00',
      ends_at_date: dateStr, ends_at_time: '12:00',
      _csrf: csrf,
    });
    const r = await fetch('/admin/events/new', {
      method: 'POST', body,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      redirect: 'follow',
    });
    const t = await r.text();
    return { url: r.url, ok: /\/admin\/events\/\d+/.test(r.url), hasErr: /تاریخ\/(ساعت معتبر|شمسی)/.test(t) };
  }, dateStr);
  ok('POST بدون جاوااسکریپت فقط با تاریخ شمسی + ساعت ساخته می‌شود',
    rawPost.ok && !rawPost.hasErr, JSON.stringify(rawPost));

  // ---------- مسیر ۳: ساعت خالی → پیام روشن ----------
  const noTime = await page.evaluate(async (dateStr) => {
    const csrf = document.querySelector('input[name="_csrf"]')?.value || '';
    const body = new URLSearchParams({
      title: 'بدون ساعت آزمون', summary: 'خلاصهٔ کافی برای اعتبارسنجی سمت سرور در مسیر خطای ساعت.',
      description: 'شرح کامل آزمون مسیر خطای ساعت خالی برای بررسی پیام خطای فارسی سمت سرور.',
      category: 'آزمون', delivery_mode: 'online', venue: '', capacity: '20',
      price_toman: '0', tax_percent: '0', tax_payer: 'participant',
      starts_at: '', ends_at: '',
      starts_at_date: dateStr, starts_at_time: '',
      ends_at_date: dateStr, ends_at_time: '12:00',
      _csrf: csrf,
    });
    const r = await fetch('/admin/events/new', {
      method: 'POST', body,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      redirect: 'follow',
    });
    const t = await r.text();
    return (t.match(/تاریخ شمسی و ساعت را بررسی کنید\.|تاریخ\/ساعت معتبر نیست\./) || [''])[0];
  }, dateStr);
  ok('ساعت خالی → پیام فارسی روشن دارد', noTime.includes('بررسی کنید') || noTime.includes('معتبر'), noTime);

  ok('هیچ خطای جاوااسکریپتی رخ نداد', pageErrors.length === 0, pageErrors.join(' | '));
} finally {
  if (browser) await browser.close().catch(() => {});
  if (server) await new Promise((r) => server.close(r)).catch(() => {});
  await env.close().catch(() => {});
}

if (failures.length) {
  console.log(`\nجریان تاریخ: ${failures.length} ایراد`);
  process.exit(1);
}
console.log('\nجریان تاریخ: هر سه مسیر در مرورگر واقعی درست است.');
process.exit(0);
