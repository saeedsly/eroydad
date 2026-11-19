/**
 * آزمون نصب‌کنندهٔ یک‌مرحله‌ای (install.php):
 *   یک ریشهٔ تازهٔ پروژه (بدون .env) می‌سازیم، فرم را GET می‌گیریم، POST می‌کنیم و
 *   می‌سنجیم که دیتابیس + schema + .env + حساب مدیر واقعاً ساخته شده‌اند و همان
 *   مدیر می‌تواند از مسیر واقعی برنامه وارد شود و پنل /admin را ببیند.
 *
 * اجرا:  node tests/installer-test.mjs [مسیر پروژه]
 */
import { PHPRequestHandler } from '@php-wasm/universal';
import { createTestEnvironment } from './support/harness.mjs';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));

const failures = [];
function ok(label, condition, detail = '') {
  if (condition) { console.log('PASS  ' + label); return; }
  failures.push(label + (detail ? ' :: ' + String(detail).slice(0, 220) : ''));
  console.log('FAIL  ' + label + (detail ? ' :: ' + String(detail).slice(0, 220) : ''));
}
function pick(response) {
  if (typeof response.text === 'string') return response.text;
  if (typeof response.stdout === 'string') return response.stdout;
  if (response.bytes instanceof Uint8Array) return new TextDecoder().decode(response.bytes);
  if (response.stdout instanceof Uint8Array) return new TextDecoder().decode(response.stdout);
  if (response.body instanceof Uint8Array) return new TextDecoder().decode(response.body);
  if (typeof response.body === 'string') return response.body;
  return '';
}

const TMP = path.join(os.tmpdir(), 'erooydad-installer-' + process.pid);
fs.rmSync(TMP, { recursive: true, force: true });
fs.cpSync(ROOT, TMP, {
  recursive: true,
  filter: (src) => !src.includes('node_modules') && !src.includes(`${path.sep}.git`) && !src.endsWith('.env'),
});
console.log('ریشهٔ تازهٔ نصب در: ' + TMP);

const env = await createTestEnvironment({
  root: TMP,
  withSchema: false,
  database: 'erooydad_installer',
  baseUrl: 'http://localhost/',
});
try {
  // هراساز، فایل .env می‌سازد؛ برای شبیه‌سازی نصب تازه آن را حذف می‌کنیم.
  fs.rmSync(path.join(TMP, '.env'), { force: true });

  const rootHandler = new PHPRequestHandler({
    php: env.php,
    documentRoot: '/project',
    absoluteUrl: 'http://localhost/',
    getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
  });
  const appHandler = new PHPRequestHandler({
    php: env.php,
    documentRoot: '/project/frontend/public',
    absoluteUrl: 'http://localhost/',
    getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
  });

  // ---------- ۱) فرم نصب بدون .env ----------
  const formPage = await rootHandler.request({ url: '/install.php', method: 'GET' });
  const formText = pick(formPage);
  ok('قبل از نصب، فرم نصب کد ۲۰۰ می‌دهد', formPage.httpStatusCode === 200, 'status=' + formPage.httpStatusCode);
  ok('فرم نصب فیلدهای دیتابیس و مدیر را دارد', formText.includes('db_name') && formText.includes('admin_password'), formText.slice(0, 200));
  ok('فرم نصب قفل «نصب کامل» نیست', !formText.includes('نصب کامل است'), formText.slice(0, 200));
  const token = /name="token" value="([a-f0-9]{32,})"/.exec(formText);
  ok('توکن فرم نصب صادر شده است', !!token, formText.slice(0, 300));

  // ---------- ۲) ارسال فرم و اجرای کامل نصب ----------
  const params = new URLSearchParams();
  params.append('token', token ? token[1] : '');
  params.append('db_host', '127.0.0.1');
  params.append('db_port', String(env.bridge.port));
  params.append('db_name', 'erooydad_installer');
  params.append('db_user', 'root');
  params.append('db_pass', '');
  params.append('base_url', 'http://localhost/');
  params.append('phone', '09127000001');
  params.append('admin_name', 'مدیر نصب آزمون');
  params.append('admin_password', 'Installer-1404!Pass');
  params.append('admin_password2', 'Installer-1404!Pass');
  const postBody = new TextEncoder().encode(params.toString());
  const installPost = await rootHandler.request({
    url: '/install.php',
    method: 'POST',
    body: postBody,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const postText = pick(installPost);
  ok('ارسال فرم نصب کد ۲۰۰ می‌دهد و صفحهٔ موفقیت نشان می‌دهد',
    installPost.httpStatusCode === 200 && postText.includes('نصب کامل است'),
    'status=' + installPost.httpStatusCode + ' :: ' + postText.slice(0, 300));
  ok('گام‌های نصب (دیتابیس، schema، .env، مدیر) گزارش شده‌اند',
    postText.includes('دیتابیس') && postText.includes('schema') && postText.includes('.env') && postText.includes('مدیر'),
    postText.slice(0, 500));

  // ---------- ۳) فایل .env نوشته شده و معتبر است ----------
  const envPath = path.join(TMP, '.env');
  ok('فایل .env پس از نصب نوشته شده است', fs.existsSync(envPath));
  const envText = fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : '';
  const readKey = (name) => {
    const m = new RegExp('^' + name + '=(.*)$', 'm').exec(envText);
    return m ? m[1].trim() : '';
  };
  ok('سه راز .env حداقل ۳۲ نویسه‌اند و CHANGE_ME ندارند',
    ['APP_KEY', 'EMPLOYEE_HMAC_KEY', 'TICKET_SIGNING_KEY'].every((k) => readKey(k).length >= 32 && !readKey(k).startsWith('CHANGE_ME')),
    envText.slice(0, 400));
  ok('.env به دیتابیس و نشانی درست اشاره می‌کند',
    readKey('DB_NAME') === 'erooydad_installer' && readKey('APP_BASE_URL') === 'http://localhost',
    envText.slice(0, 400));

  // ---------- ۴) نصب‌کننده پس از نصب قفل می‌شود ----------
  const afterInstall = await rootHandler.request({ url: '/install.php', method: 'GET' });
  const afterText = pick(afterInstall);
  ok('پس از نصب، install.php قفل است و فرم نشان نمی‌دهد',
    afterInstall.httpStatusCode === 200 && afterText.includes('نصب کامل است') && !afterText.includes('admin_password'),
    afterText.slice(0, 200));

  // ---------- ۵) ورود مدیر از مسیر واقعی برنامه ----------
  const loginPage = await appHandler.request({ url: '/login', method: 'GET' });
  const loginText = pick(loginPage);
  ok('صفحهٔ ورود برنامه با .env تازه بالا می‌آید', loginPage.httpStatusCode === 200 && !/Fatal error|Parse error/.test(loginText), 'status=' + loginPage.httpStatusCode);
  const loginCsrf = /name="_csrf" value="([a-f0-9]{32,})"/.exec(loginText);
  ok('توکن CSRF صفحهٔ ورود صادر شده است', !!loginCsrf, loginText.slice(0, 300));
  const loginBody = new TextEncoder().encode(new URLSearchParams({
    phone: '09127000001',
    password: 'Installer-1404!Pass',
    _csrf: loginCsrf ? loginCsrf[1] : '',
    next: '',
  }).toString());
  const loginPost = await appHandler.request({
    url: '/login',
    method: 'POST',
    body: loginBody,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const location = (loginPost.headers && (loginPost.headers.location || loginPost.headers.Location)) || '';
  ok('ورود مدیرِ تازه‌ساخته‌شده به /account هدایت می‌شود',
    loginPost.httpStatusCode === 303 && String(location).includes('/account'),
    'status=' + loginPost.httpStatusCode + ' location=' + location + ' :: ' + pick(loginPost).slice(0, 200));

  // ---------- ۶) نقش مدیر: پنل /admin باز می‌شود ----------
  const adminPage = await appHandler.request({ url: '/admin', method: 'GET' });
  const adminText = pick(adminPage);
  ok('پنل /admin برای مدیر تازه ساخته‌شده کد ۲۰۰ می‌دهد',
    adminPage.httpStatusCode === 200 && !/Fatal error/.test(adminText),
    'status=' + adminPage.httpStatusCode + ' :: ' + adminText.slice(0, 200));
} finally {
  await env.close().catch(() => {});
  fs.rmSync(TMP, { recursive: true, force: true });
}

if (failures.length) {
  console.log('\nآزمون نصب‌کننده: ' + failures.length + ' ایراد.');
  console.log(failures.map((f) => ' - ' + f).join('\n'));
  process.exit(1);
}
console.log('\nآزمون نصب‌کننده: همهٔ بررسی‌ها درست بودند.');
process.exit(0);
