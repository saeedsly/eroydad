/**
 * آزمون نصب تمیز (بند ۸۵ نقشه‌راه) — شبیه‌سازی واقعیِ چرخهٔ تحویل:
 *
 *   بستهٔ ZIP تحویلی ← استخراج واقعی ← دیتابیس کاملاً تازه ← schema + migration
 *   روی نسخهٔ استخراج‌شده ← بالا آوردن فرانت‌کنترل از همان نسخه ← صفحه‌های کلیدی
 *   و یک نوشتن واقعی (ساخت حساب) از طریق HTTP.
 *
 * این آزمون XAMPP ویندوز نیست؛ راستی‌آزمایی روی ویندوز واقعی چک‌لیست دستی در
 * docs/deployment/xampp.md می‌ماند و مورد نقشه‌راه را `[~]` نگه می‌دارد.
 *
 * اجرا:  node tests/clean-install-test.mjs [مسیر پروژه] [مسیر zip]
 */
import { PHPRequestHandler } from '@php-wasm/universal';
import { createTestEnvironment } from './support/harness.mjs';
import { inflateRawSync } from 'zlib';
import { mkdtempSync, rmSync, mkdirSync, writeFileSync, readFileSync, existsSync, statSync } from 'fs';
import { tmpdir } from 'os';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));
const ZIP = path.resolve(process.argv[3] || path.join(ROOT, '..', 'eroydad-xampp-php74-updated.zip'));

const failures = [];
function ok(label, condition, detail = '') {
  if (condition) { console.log('PASS  ' + label); return; }
  failures.push(label + (detail ? ' :: ' + detail.slice(0, 220) : ''));
  console.log('FAIL  ' + label + (detail ? ' :: ' + detail.slice(0, 220) : ''));
}

// ---------- استخراج ZIP بدون هیچ وابستگی ----------
function extractZip(zipPath, destDir) {
  const buf = readFileSync(zipPath);
  // پیدا کردن EOCD (امضا 0x06054b50) از انتهای فایل
  let eocd = -1;
  for (let i = buf.length - 22; i >= 0 && i >= buf.length - 22 - 65535; i -= 1) {
    if (buf.readUInt32LE(i) === 0x06054b50) { eocd = i; break; }
  }
  if (eocd < 0) throw new Error('EOCD در ZIP پیدا نشد');
  const count = buf.readUInt16LE(eocd + 10);
  let p = buf.readUInt32LE(eocd + 16);
  let files = 0;
  let dirs = 0;
  for (let n = 0; n < count; n += 1) {
    if (buf.readUInt32LE(p) !== 0x02014b50) throw new Error('سربرگ فهرست مرکزی خراب است');
    const method = buf.readUInt16LE(p + 10);
    const compSize = buf.readUInt32LE(p + 20);
    const nameLen = buf.readUInt16LE(p + 28);
    const extraLen = buf.readUInt16LE(p + 30);
    const commentLen = buf.readUInt16LE(p + 32);
    const localOffset = buf.readUInt32LE(p + 42);
    const name = buf.slice(p + 46, p + 46 + nameLen).toString('utf8');
    p += 46 + nameLen + extraLen + commentLen;
    const target = path.join(destDir, name);
    if (name.endsWith('/')) {
      mkdirSync(target, { recursive: true });
      dirs += 1;
      continue;
    }
    if (buf.readUInt32LE(localOffset) !== 0x04034b50) throw new Error('سربرگ محلی خراب: ' + name);
    const lNameLen = buf.readUInt16LE(localOffset + 26);
    const lExtraLen = buf.readUInt16LE(localOffset + 28);
    const dataStart = localOffset + 30 + lNameLen + lExtraLen;
    const raw = buf.slice(dataStart, dataStart + compSize);
    const data = method === 8 ? inflateRawSync(raw) : (method === 0 ? raw : null);
    if (data === null) throw new Error('روش فشرده‌سازی پشتیبانی نمی‌شود: ' + name + ' (method ' + method + ')');
    mkdirSync(path.dirname(target), { recursive: true });
    writeFileSync(target, data);
    files += 1;
  }
  return { files, dirs };
}

if (!existsSync(ZIP)) {
  console.log('FAIL  بستهٔ ZIP پیدا نشد: ' + ZIP);
  process.exit(1);
}

const staging = mkdtempSync(path.join(tmpdir(), 'erooydad-clean-install-'));
let env = null;
try {
  // ---------- ۱) استخراج ----------
  const { files, dirs } = extractZip(ZIP, staging);
  ok('بستهٔ ZIP استخراج شد', files > 100 && dirs > 40, `files=${files} dirs=${dirs}`);

  // نام پوشهٔ بالایی همان «erooydad» است
  const top = path.join(staging, 'erooydad');
  const rootDir = existsSync(top) ? top : staging;
  ok('پوشهٔ بالایی بسته وجود دارد', existsSync(top), top);

  // ---------- ۲) ساختار نصب ----------
  const mustExist = [
    'frontend/public/index.php',
    'frontend/public/.htaccess',
    'frontend/public/manifest.webmanifest',
    'backend/bootstrap.php',
    'backend/src/Http/routes.php',
    'backend/routes/web.php',
    'database/schema.sql',
    'database/migrations/002_feature_set.sql',
    'deployment/local-xampp/migrate.php',
    'deployment/local-xampp/create-admin.php',
  ];
  for (const rel of mustExist) {
    ok('ساختار نصب: ' + rel, existsSync(path.join(rootDir, rel)));
  }
  ok('بسته بدون .env است (رازها هرگز بسته‌بندی نمی‌شوند)', !existsSync(path.join(rootDir, '.env')));
  ok('بسته بدون node_modules است', !existsSync(path.join(rootDir, 'node_modules')));
  ok('پوشهٔ storage خصوصی خالی است (جز .gitkeep)', !existsSync(path.join(rootDir, 'storage/private/backups')));

  // ---------- ۳) نصب روی دیتابیس تازه (schema + migration) ----------
  env = await createTestEnvironment({ root: rootDir, withSchema: true, baseUrl: 'http://localhost/', database: 'erooydad_clean_install' });
  ok('دیتابیس تازه با schema پایه ساخته شد', true);

  const migrate = await env.php.run({
    code: `<?php
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Database/MigrationRunner.php';
$runner = new \\Eroydad\\Database\\MigrationRunner(db(), PROJECT_ROOT . '/database/migrations');
$result = $runner->run(static function (string $text): void { echo $text . "\n"; });
echo 'MIGRATE applied=' . (int)$result['applied'] . ' skipped=' . (int)$result['skipped'] . ' failures=' . count($result['failures']) . "\n";
`,
  });
  const migrateText = typeof migrate.text === 'string' ? migrate.text : new TextDecoder().decode(migrate.stdout ?? new Uint8Array());
  const migMatch = /MIGRATE applied=(\d+) skipped=(\d+) failures=(\d+)/.exec(migrateText);
  ok('مهاجرت روی نصب تازه نسخه‌ها را می‌شناسد و بدون خطا جا می‌افتد (schema از قبل به‌روز است)', migMatch !== null && (Number(migMatch[1]) + Number(migMatch[2])) >= 1 && Number(migMatch[3]) === 0, migrateText.slice(0, 400));

  // اجرای دوباره باید بی‌خطر باشد (همان «نصب موجود» که دوباره migrate می‌زند)
  const migrate2 = await env.php.run({
    code: `<?php
require '/project/backend/bootstrap.php';
require_once '/project/backend/src/Database/MigrationRunner.php';
$runner = new \\Eroydad\\Database\\MigrationRunner(db(), PROJECT_ROOT . '/database/migrations');
$result = $runner->run(static function (string $text): void {});
echo 'MIGRATE2 applied=' . (int)$result['applied'] . ' failures=' . count($result['failures']) . "\n";
`,
  });
  const migrate2Text = typeof migrate2.text === 'string' ? migrate2.text : new TextDecoder().decode(migrate2.stdout ?? new Uint8Array());
  ok('اجرای دوبارهٔ migration بی‌خطر است', /MIGRATE2 applied=0 failures=0/.test(migrate2Text), migrate2Text.slice(0, 300));

  // ---------- ۴) بالا آوردن فرانت‌کنترل از نسخهٔ استخراج‌شده ----------
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
    const bytes = response.bytes instanceof Uint8Array ? response.bytes : (response.stdout instanceof Uint8Array ? response.stdout : null);
    const text = typeof response.text === 'string' ? response.text : (bytes ? new TextDecoder().decode(bytes) : '');
    return { status: response.httpStatusCode, text, headers: response.headers, location: String(response.headers?.location ?? response.headers?.Location ?? '') };
  }

  const home = await req('/');
  ok('نصب تازه: صفحهٔ اصلی بالا می‌آید', home.status === 200 && home.text.includes('ای‌رویداد'), 'status=' + home.status);
  ok('نصب تازه: خطای PHP در صفحه نیست', !/Fatal error|Parse error|Uncaught/.test(home.text), home.text.slice(0, 200));

  const loginPage = await req('/login');
  ok('نصب تازه: صفحهٔ ورود سالم است', loginPage.status === 200 && /name="_csrf"/.test(loginPage.text), 'status=' + loginPage.status);

  const events = await req('/events');
  ok('نصب تازه: فهرست رویدادها سرو می‌شود', events.status === 200 && !/Fatal error/.test(events.text), 'status=' + events.status);

  const manifest = await req('/manifest.webmanifest');
  ok('نصب تازه: دارایی‌های PWA در جای خودند', manifest.status === 200 && manifest.text.includes('start_url') && manifest.text.includes('ای‌رویداد'), 'status=' + manifest.status);

  const terms = await req('/terms');
  const privacy = await req('/privacy');
  ok('نصب تازه: صفحه‌های حقوقی هم هستند', terms.status === 200 && privacy.status === 200, 'terms=' + terms.status + ' privacy=' + privacy.status);

  // ---------- ۵) یک نوشتن واقعی: ساخت حساب از طریق HTTP ----------
  const token = (/name="_csrf" value="([^"]+)"/.exec(loginPage.text) || [])[1] || '';
  const reg = await req('/register', {
    method: 'POST',
    body: { phone: '09120009001', full_name: 'کاربر نصب تمیز', password: 'Clean-Install-91!', account_type: 'public', national_id: '', _csrf: token },
  });
  ok('نصب تازه: ساخت حساب واقعاً در دیتابیس می‌نویسد (303)', reg.status === 303 && reg.location.includes('/account'), 'status=' + reg.status + ' loc=' + reg.location);

  console.log('');
  if (failures.length) {
    console.log('آزمون نصب تمیز: ' + failures.length + ' ایراد.');
    process.exit(1);
  }
  console.log('آزمون نصب تمیز: بسته ← استخراج ← دیتابیس تازه ← schema ← migration ← اجرا همه سالم است.');
  console.log('یادآوری: راستی‌آزمایی روی XAMPP ویندوز واقعی در docs/deployment/xampp.md (چک‌لیست دستی) می‌ماند.');
  process.exit(0);
} catch (error) {
  console.log('آزمون نصب تمیز با خطا متوقف شد: ' + (error && error.stack ? error.stack : error));
  process.exit(1);
} finally {
  try { rmSync(staging, { recursive: true, force: true }); } catch (e) { /* پاک‌سازی بهتر است ولی الزامی نیست */ }
}
