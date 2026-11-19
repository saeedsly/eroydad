/**
 * آزمون مرورگر و اندازه‌های مختلف صفحه (بند ۸۶ نقشه‌راه).
 *
 * Chromium واقعی (بستهٔ npm «@sparticuz/chromium» — بدون دانلود از CDN بیرونی)
 * روی یک سرور HTTP محلی که به فرانت‌کنترل واقعی وصل است:
 *   • ۳۶۰×۷۴۰ (موبایل) ← ۷۶۸×۱۰۲۴ (تبلت) ← ۱۲۸۰×۸۰۰ (دسکتاپ)
 *   • صفحه‌های کلیدی: خانه، ورود، فهرست رویدادها، جزئیات رویداد
 *   • بدون اسکرول افقی، بدون خطای جاوااسکریپت صفحه، بدون درخواست شکسته
 *   • منوی موبایل باز و بسته می‌شود؛ فرم ورود در دیدرس است؛ فونت محلی اعمال می‌شود
 *
 * تک‌موتوره‌بودن (Chromium 131 headless) صادقانه گزارش می‌شود؛ ماتریس مرورگرهای
 * واقعی/سیستم‌عامل‌های مختلف همچنان چک‌لیست دستی docs/deployment/xampp.md است.
 *
 * اجرا:  node tests/browser-smoke.mjs [مسیر پروژه]
 */
import { PHPRequestHandler } from '@php-wasm/universal';
import { createTestEnvironment } from './support/harness.mjs';
import { createServer } from 'http';
import { createRequire } from 'module';
import { existsSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));

const failures = [];
function ok(label, condition, detail = '') {
  if (condition) { console.log('PASS  ' + label); return; }
  failures.push(label + (detail ? ' :: ' + String(detail).slice(0, 220) : ''));
  console.log('FAIL  ' + label + (detail ? ' :: ' + String(detail).slice(0, 220) : ''));
}

const env = await createTestEnvironment({ root: ROOT, withSchema: true, baseUrl: 'http://localhost/' });
let server = null;
let browser = null;
try {
  // ---------- دانه‌گذاری: یک رویداد منتشرشده ----------
  const seed = await env.php.run({
    code: `<?php
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Events/EventService.php';
use Eroydad\\Events\\EventService;
$db = db();
$hash = password_hash(passwordMaterial('Root-Admin-91-OK'), PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status,phone_verified_at) VALUES ('09120005003','مدیر مرورگر',?,'municipal','active',UTC_TIMESTAMP())")->execute([$hash]);
$uid = (int)$db->lastInsertId();
$role = $db->prepare("SELECT id FROM roles WHERE code='system_admin'"); $role->execute();
$db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$uid, (int)$role->fetchColumn()]);
$future = (new DateTimeImmutable('@' . (time() + 86400 * 6)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$later = (new DateTimeImmutable('@' . (time() + 86400 * 6 + 7200)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d\\TH:i');
$events = new EventService($db);
$id = $events->create($uid, [
  'title' => 'رویداد آزمون مرورگر',
  'summary' => 'رویدادی برای سنجش چیدمان در اندازه‌های مختلف صفحه.',
  'description' => 'شرح کامل رویداد آزمون مرورگر برای بررسی چیدمان واکنش‌گرا در سه اندازهٔ صفحه در این مخزن.',
  'category' => 'آزمون',
  'delivery_mode' => 'in_person',
  'venue' => 'سالن مرورگر',
  'capacity' => '15',
  'price_toman' => '0',
  'starts_at' => $future,
  'ends_at' => $later,
]);
$events->publish($id, $uid, false);
$slug = (string)$db->query('SELECT slug FROM events WHERE id=' . (int)$id)->fetchColumn();
echo 'SEED ' . json_encode(['slug'=>$slug]) . "\\n";
`,
  });
  const seedText = typeof seed.text === 'string' ? seed.text : new TextDecoder().decode(seed.stdout ?? new Uint8Array());
  const seeded = /SEED (\{.*\})/.exec(seedText);
  ok('دادهٔ آزمون مرورگر ساخته شد', seeded !== null, seedText);
  if (!seeded) throw new Error('دانه‌گذاری ناموفق بود');
  const slug = JSON.parse(seeded[1]).slug;

  // ---------- سرور HTTP محلی ↔ فرانت‌کنترل واقعی ----------
  const jar = { pairs: new Map(),
    rememberCookiesFromResponseHeaders(h) { for (const [n, vs] of Object.entries(h || {})) { if (String(n).toLowerCase() !== 'set-cookie') continue; for (const v of vs || []) { const pair = String(v).split(';')[0]; const i = pair.indexOf('='); if (i > 0) this.pairs.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim()); } } },
    getCookieRequestHeader() { return [...this.pairs.entries()].map(([k, v]) => k + '=' + v).join('; '); } };
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
  console.log('سرور محلی مرورگر: ' + baseUrl);

  // ---------- Chromium (کتابخانه‌های Amazon Linux فقط وقتی لازم‌اند استخراج می‌شوند) ----------
  const chromium = require('@sparticuz/chromium');
  const lambdafs = require('@sparticuz/chromium/build/lambdafs.js');
  const helper = require('@sparticuz/chromium/build/helper.js');
  const binDir = path.join(path.dirname(require.resolve('@sparticuz/chromium/package.json')), 'bin');
  const inflate = lambdafs.default ?? lambdafs;
  if (!existsSync('/tmp/al2023/lib')) await inflate.inflate(path.join(binDir, 'al2023.tar.br'));
  if (!existsSync('/tmp/fonts')) await inflate.inflate(path.join(binDir, 'fonts.tar.br'));
  helper.setupLambdaEnvironment('/tmp/al2023/lib');
  const puppeteer = require('puppeteer-core');
  browser = await puppeteer.launch({
    executablePath: await chromium.executablePath(),
    args: chromium.args,
    headless: true,
  });
  ok('Chromium واقعی بالا آمد', (await browser.version()).includes('Chrome'), await browser.version());

  const pageErrors = [];
  const failedRequests = [];
  const page = await browser.newPage();
  page.on('pageerror', (err) => pageErrors.push(String(err && err.message ? err.message : err)));
  page.on('requestfailed', (req) => failedRequests.push(req.url() + ' ← ' + (req.failure() && req.failure().errorText)));

  const viewports = [
    { name: 'موبایل ۳۶۰×۷۴۰', width: 360, height: 740 },
    { name: 'تبلت ۷۶۸×۱۰۲۴', width: 768, height: 1024 },
    { name: 'دسکتاپ ۱۲۸۰×۸۰۰', width: 1280, height: 800 },
  ];
  const pages = [
    { path: '/', label: 'خانه' },
    { path: '/login', label: 'ورود' },
    { path: '/events', label: 'فهرست رویدادها' },
    { path: '/events/' + slug, label: 'جزئیات رویداد' },
  ];

  for (const vp of viewports) {
    await page.setViewport({ width: vp.width, height: vp.height });
    let worstOverflow = 0;
    let allStatusOk = true;
    for (const target of pages) {
      const response = await page.goto(baseUrl + target.path, { waitUntil: 'load', timeout: 30000 });
      if (!response || response.status() !== 200) allStatusOk = false;
      const overflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0) - window.innerWidth);
      worstOverflow = Math.max(worstOverflow, overflow);
    }
    ok(vp.name + ': هر چهار صفحه ۲۰۰ می‌دهند', allStatusOk);
    ok(vp.name + ': هیچ اسکرول افقی نیست (محتوا از عرض صفحه بیرون نمی‌زند)', worstOverflow <= 1, 'overflow=' + worstOverflow + 'px');
  }

  // ---------- منوی موبایل ----------
  await page.setViewport({ width: 360, height: 740 });
  await page.goto(baseUrl + '/', { waitUntil: 'load' });
  const toggleVisible = await page.evaluate(() => {
    const b = document.querySelector('.menu-toggle');
    return !!b && b.offsetParent !== null;
  });
  ok('در موبایل دکمهٔ منو دیده می‌شود', toggleVisible);
  await page.click('.menu-toggle');
  await page.waitForFunction(() => {
    const nav = document.querySelector('.nav-links.open');
    if (!nav || getComputedStyle(nav).visibility !== 'visible') return false;
    const t = getComputedStyle(nav).transform;
    return t === 'none' || t === 'matrix(1, 0, 0, 1, 0, 0)';
  }, { timeout: 5000 }).catch(() => {});
  const menuOpen = await page.evaluate(() => document.querySelector('.nav-links') && document.querySelector('.nav-links').classList.contains('open'));
  ok('کلیک روی دکمه، منو را باز می‌کند', menuOpen);
  const linkReachable = await page.evaluate(() => {
    const a = document.querySelector('.nav-links a[href="/events"]');
    if (!a) return false;
    const r = a.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  });
  ok('پیوندهای منو بعد از بازشدن قابل کلیک‌اند', linkReachable);
  await page.click('[data-menu-close]').catch(() => {});
  await page.waitForFunction(() => {
    const el = document.querySelector('.nav-links');
    return !el || !el.classList.contains('open');
  }, { timeout: 5000 }).catch(() => {});
  const menuClosed = await page.evaluate(() => {
    const el = document.querySelector('.nav-links');
    return !el || !el.classList.contains('open') || getComputedStyle(el).visibility === 'hidden';
  });
  ok('دکمهٔ بستن، منو را می‌بندد', menuClosed);

  // ---------- در دسکتاپ هم دکمهٔ منو هست (مدل طراحی: کشوی واحد) و کشو بسته است ----------
  await page.setViewport({ width: 1280, height: 800 });
  await page.goto(baseUrl + '/', { waitUntil: 'load' });
  const desktopState = await page.evaluate(() => {
    const toggle = document.querySelector('.menu-toggle');
    const nav = document.querySelector('.nav-links');
    return {
      innerWidth: window.innerWidth,
      toggleVisible: !!toggle && toggle.offsetParent !== null && getComputedStyle(toggle).display !== 'none',
      navClosed: !!nav && !nav.classList.contains('open') && (getComputedStyle(nav).visibility === 'hidden' || getComputedStyle(nav).transform !== 'none'),
    };
  });
  ok('در دسکتاپ دکمهٔ منو دیده می‌شود (مدل کشوی واحد)', desktopState.toggleVisible, JSON.stringify(desktopState));
  ok('در دسکتاپ کشوی ناوبری بسته است', desktopState.navClosed, JSON.stringify(desktopState));
  await page.click('.menu-toggle');
  await page.waitForFunction(() => {
    const nav = document.querySelector('.nav-links.open');
    if (!nav || getComputedStyle(nav).visibility !== 'visible') return false;
    const t = getComputedStyle(nav).transform;
    return t === 'none' || t === 'matrix(1, 0, 0, 1, 0, 0)';
  }, { timeout: 5000 }).catch(() => {});
  await new Promise((r) => setTimeout(r, 150));
  const desktopOpen = await page.evaluate(() => {
    const nav = document.querySelector('.nav-links');
    const toggle = document.querySelector('.menu-toggle');
    const r = nav ? nav.getBoundingClientRect() : null;
    return {
      hasOpen: !!nav && nav.classList.contains('open'),
      aria: toggle ? toggle.getAttribute('aria-expanded') : null,
      visibility: nav ? getComputedStyle(nav).visibility : null,
      width: r ? Math.round(r.width) : 0,
      left: r ? Math.round(r.left) : null,
      bodyMenuOpen: document.body.classList.contains('menu-open'),
    };
  });
  ok('در دسکتاپ کلیک روی منو، کشو را باز می‌کند', desktopOpen.hasOpen && desktopOpen.visibility === 'visible' && desktopOpen.width > 100, JSON.stringify(desktopOpen));
  await page.click('[data-menu-close]').catch(() => {});
  await page.waitForFunction(() => {
    const el = document.querySelector('.nav-links');
    return !el || !el.classList.contains('open');
  }, { timeout: 5000 }).catch(() => {});

  // ---------- فرم ورود در موبایل در دیدرس است ----------
  await page.setViewport({ width: 360, height: 740 });
  await page.goto(baseUrl + '/login', { waitUntil: 'load' });
  const formFit = await page.evaluate(() => {
    const inputs = [...document.querySelectorAll('form input, form button')];
    if (inputs.length === 0) return { ok: false, reason: 'no inputs' };
    for (const el of inputs) {
      const r = el.getBoundingClientRect();
      if (r.right > window.innerWidth + 1 || r.left < -1) return { ok: false, reason: el.name || el.tagName };
    }
    return { ok: true };
  });
  ok('فرم ورود در موبایل بدون بیرون‌زدگی است', formFit.ok, formFit.reason || '');

  // ---------- فونت فارسی محلی اعمال می‌شود ----------
  await page.goto(baseUrl + '/', { waitUntil: 'load' });
  const fontUsed = await page.evaluate(() => getComputedStyle(document.body).fontFamily);
  ok('فونت محلی (Vazirmatn) در محاسبهٔ سبک هست', /Vazirmatn|Tahoma/i.test(fontUsed), fontUsed);

  // ---------- سلامت جاوااسکریپت و دارایی‌ها ----------
  ok('هیچ خطای جاوااسکریپتی در صفحه‌ها رخ نداد', pageErrors.length === 0, pageErrors.join(' | '));
  ok('هیچ درخواست دارایی‌ای شکست نخورد', failedRequests.length === 0, failedRequests.join(' | '));

  console.log('');
  if (failures.length) {
    console.log('آزمون مرورگر: ' + failures.length + ' ایراد.');
    process.exit(1);
  }
  console.log('آزمون مرورگر: سه اندازهٔ صفحه، منو، فرم و فونت همه درست‌اند.');
  console.log('یادآوری: این اجرا تک‌موتوره (Chromium headless) است؛ ماتریس مرورگرهای واقعی چک‌لیست دستی است.');
  process.exit(0);
} catch (error) {
  console.log('آزمون مرورگر با خطا متوقف شد: ' + (error && error.stack ? error.stack : error));
  process.exit(1);
} finally {
  try { if (browser) await browser.close(); } catch (e) { /* بستن مرورگر */ }
  try { if (server) server.close(); } catch (e) { /* بستن سرور */ }
}
