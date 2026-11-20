/** ماتریس کیفیت: همه صفحات × اندازه‌های صفحه — overflow، خطای کنسول، درخواست شکست‌خورده. */
import { createRequire } from 'module';
import path from 'path';
import { existsSync } from 'fs';
const require = createRequire('/home/user/eroydad/project/tests/x.js');
const chromium = require('@sparticuz/chromium');
const lambdafs = require('@sparticuz/chromium/build/lambdafs.js');
const helper = require('@sparticuz/chromium/build/helper.js');
const binDir = path.join(path.dirname(require.resolve('@sparticuz/chromium/package.json')), 'bin');
const inflate = lambdafs.default ?? lambdafs;
if (!existsSync('/tmp/al2023/lib')) await inflate.inflate(path.join(binDir, 'al2023.tar.br'));
if (!existsSync('/tmp/fonts')) await inflate.inflate(path.join(binDir, 'fonts.tar.br'));
helper.setupLambdaEnvironment('/tmp/al2023/lib');
const puppeteer = require('puppeteer-core');

const BASE = 'http://127.0.0.1:8642';
const WIDTHS = [1920, 1440, 1024, 768, 390];
let failures = 0;
const lines = [];

const browser = await puppeteer.launch({ executablePath: await chromium.executablePath(), args: chromium.args, headless: true });

async function check(page, url, width, label) {
  await page.setViewport({ width, height: 900 });
  const errors = [];
  const failed = [];
  const onConsole = (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 140)); };
  const onPageErr = (e) => errors.push('pageerror: ' + String(e).slice(0, 140));
  const onFail = (r) => { const u = r.url(); if (!u.startsWith('data:') && !u.includes('favicon')) failed.push(r.failure()?.errorText + ' ' + u.slice(0, 90)); };
  page.on('console', onConsole); page.on('pageerror', onPageErr); page.on('requestfailed', onFail);
  try {
    await page.goto(BASE + url, { waitUntil: 'networkidle2', timeout: 20000 });
    await new Promise((r) => setTimeout(r, 350));
    const m = await page.evaluate(() => ({
      sw: document.documentElement.scrollWidth,
      cw: document.documentElement.clientWidth,
      bsw: document.body.scrollWidth,
      title: document.title.slice(0, 40),
    }));
    const overflow = Math.max(m.sw, m.bsw) - m.cw;
    const ok = overflow <= 1 && errors.length === 0 && failed.length === 0;
    if (!ok) failures++;
    lines.push(`${ok ? 'PASS' : 'FAIL'} [${width}] ${label || url} — overflow=${overflow} console=${errors.length} reqfail=${failed.length}` +
      (errors.length ? ` | ${errors[0]}` : '') + (failed.length ? ` | ${failed[0]}` : ''));
  } catch (e) {
    failures++;
    lines.push(`FAIL [${width}] ${label || url} — ${String(e).slice(0, 160)}`);
  }
  page.off('console', onConsole); page.off('pageerror', onPageErr); page.off('requestfailed', onFail);
}

const guestRoutes = ['/', '/events', '/login', '/register', '/terms', '/privacy', '/support'];
const page = await browser.newPage();

// میهمان: هر مسیر در هر ۵ اندازه
for (const w of WIDTHS) for (const r of guestRoutes) await check(page, r, w);

// لینک اولین رویداد
await page.setViewport({ width: 1440, height: 900 });
await page.goto(BASE + '/events', { waitUntil: 'networkidle2' });
const eventHref = await page.evaluate(() => {
  const a = [...document.querySelectorAll('a[href^="/events/"]')].find((x) => !x.href.includes('?'));
  return a ? a.getAttribute('href') : null;
});
if (eventHref) for (const w of [1440, 390]) await check(page, eventHref, w, eventHref + ' (جزئیات)');

// ورود مدیر
await page.setViewport({ width: 1440, height: 900 });
await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
await page.type('input[name="phone"]', '09120000001');
await page.type('input[name="password"]', 'Admin-1404!Demo');
await Promise.all([page.click('button[type="submit"]'), page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 })]);
const loggedIn = !page.url().includes('/login');
lines.push(loggedIn ? `PASS login → ${page.url().replace(BASE, '')}` : `FAIL login still at ${page.url()}`);
if (!loggedIn) failures++;

// مسیرهای واردشده
const authRoutes = ['/account', '/account/settings', '/account/security', '/notifications', '/admin', '/admin/events', '/admin/reports', '/admin/support', '/admin/check-in', '/admin/users'];
for (const w of [1440, 390]) for (const r of authRoutes) await check(page, r, w);

await browser.close();
console.log(lines.join('\n'));
console.log(`\nQA MATRIX: ${failures === 0 ? 'همه بررسی‌ها سبز' : failures + ' مورد FAIL'}`);
process.exit(failures === 0 ? 0 : 1);
