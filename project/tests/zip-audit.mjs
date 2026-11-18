/**
 * بازرسی بستهٔ ZIP قابل‌نصب:
 *   • هیچ راز، فایل محیطی یا وابستگی node_modules داخل بسته نباشد.
 *   • فایل‌های لازم نصب (schema، .htaccess، راهنما، front controller) موجود باشند.
 *   • پوشۀ storage/private فقط نگه‌بانندهٔ ساختار باشد نه فایل کاربری.
 *   • پوشۀ tests هر فایل .env یا دادهٔ نشستِ آزمون نداشته باشد.
 *
 * اجرا:  node tests/zip-audit.mjs [مسیر zip]
 * پیش‌فرض: eroydad-xampp-php74-updated.zip در ریشهٔ مخزن (بالای پوشۀ پروژه).
 */
import fs from 'fs';
import path from 'path';
import zlib from 'zlib';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.join(HERE, '..');
const zipPath = path.resolve(process.argv[2] || path.join(ROOT, '..', 'eroydad-xampp-php74-updated.zip'));

const problems = [];
const notes = [];

function ok(label, condition, detail = '') {
  if (condition) { console.log('PASS  ' + label); return; }
  problems.push(label + (detail ? ' :: ' + detail.slice(0, 200) : ''));
  console.log('FAIL  ' + label + (detail ? ' :: ' + detail.slice(0, 200) : ''));
}

/** خوانندهٔ central directory ZIP (بی‌نیاز از وابستگی خارجی). */
function readZip(file) {
  const buf = fs.readFileSync(file);
  // End of Central Directory: 0x06054b50
  let eocd = -1;
  for (let i = buf.length - 22; i >= Math.max(0, buf.length - 66000); i -= 1) {
    if (buf.readUInt32LE(i) === 0x06054b50) { eocd = i; break; }
  }
  if (eocd < 0) throw new Error('پایان central directory پیدا نشد؛ فایل ZIP معتبر نیست.');
  const count = buf.readUInt16LE(eocd + 10);
  let ptr = buf.readUInt32LE(eocd + 16);
  const entries = [];
  for (let n = 0; n < count; n += 1) {
    if (buf.readUInt32LE(ptr) !== 0x02014b50) throw new Error('ساختار central directory معتبر نیست.');
    const method = buf.readUInt16LE(ptr + 10);
    const compressedSize = buf.readUInt32LE(ptr + 20);
    const nameLen = buf.readUInt16LE(ptr + 28);
    const extraLen = buf.readUInt16LE(ptr + 30);
    const commentLen = buf.readUInt16LE(ptr + 32);
    const localOffset = buf.readUInt32LE(ptr + 42);
    const name = buf.subarray(ptr + 46, ptr + 46 + nameLen).toString('utf8');
    entries.push({ name, method, compressedSize, localOffset });
    ptr += 46 + nameLen + extraLen + commentLen;
  }
  return { entries, buf };
}

/** بیرون‌کشیدن محتوای یک فایل از ZIP (stored یا deflate). */
function extract(zip, entry) {
  const { buf } = zip;
  const lh = entry.localOffset;
  if (buf.readUInt32LE(lh) !== 0x04034b50) throw new Error('سرِ فایل محلی معتبر نیست: ' + entry.name);
  const nameLen = buf.readUInt16LE(lh + 26);
  const extraLen = buf.readUInt16LE(lh + 28);
  const start = lh + 30 + nameLen + extraLen;
  const raw = buf.subarray(start, start + entry.compressedSize);
  if (entry.name.endsWith('/')) return Buffer.alloc(0);
  if (entry.method === 0) return raw;
  if (entry.method === 8) return zlib.inflateRawSync(raw);
  throw new Error('روش فشرده‌سازی پشتیبانی‌نشده (' + entry.method + ') در ' + entry.name);
}

if (!fs.existsSync(zipPath)) {
  console.error('بستهٔ ZIP پیدا نشد: ' + zipPath);
  process.exit(1);
}

const zip = readZip(zipPath);
const files = zip.entries.filter((e) => !e.name.endsWith('/'));
const dirs = zip.entries.filter((e) => e.name.endsWith('/')).map((e) => e.name);
console.log('بسته: ' + path.basename(zipPath) + ' — ' + files.length + ' فایل، ' + (zip.buf.length / 1024 / 1024).toFixed(2) + ' مگابایت\n');

// ۱) هیچ فایل مکرری در بسته نباشد (ZIP خراب معمولاً همین را دارد).
const seenNames = new Set();
const duplicates = [];
for (const f of files) {
  if (seenNames.has(f.name)) duplicates.push(f.name);
  seenNames.add(f.name);
}
ok('هیچ فایل تکراری در بسته نیست', duplicates.length === 0, duplicates.join(','));

// ۲) رازها و فایل‌های محیطی بیرون باشند
const SECRET_PATTERNS = [
  /(^|\/)\.env$/,
  /\.env\.local$/,
  /(^|\/)config\.local\.php$/,
  /(^|\/)node_modules\//,
  /(^|\/)\.git\//,
  /\.(pem|key|p12|pfx)$/i,
  /(^|\/)\.DS_Store$/,
  /(^|\/)\.env\.example$/, // این مجاز است؛ از شمارش کنار می‌گذاریم
];
const offenders = files
  .map((f) => f.name)
  .filter((n) => !n.endsWith('.env.example'))
  .filter((n) => SECRET_PATTERNS.slice(0, 7).some((re) => re.test(n)));
ok('فایل راز/محیطی/وابستگی در بسته نیست', offenders.length === 0, offenders.join(','));

// ۳) محتوای بسته: هیچ کلید واقعی در فایل‌ها نباشد
const SECRET_KEYS = ['APP_KEY=', 'EMPLOYEE_HMAC_KEY=', 'TICKET_SIGNING_KEY=', 'DB_PASSWORD='];
const leaked = [];
let scanned = 0;
for (const entry of files) {
  if (entry.name.endsWith('.env.example')) continue; // الگو، مقدار خالی
  if (!/\.(php|env|json|ya?ml|ini|conf|txt|md)$/i.test(entry.name)) continue;
  let text = '';
  try { text = extract(zip, entry).toString('utf8'); } catch (e) { problems.push('خواندن ' + entry.name + ' ممکن نشد: ' + e.message); continue; }
  scanned += 1;
  for (const key of SECRET_KEYS) {
    const m = new RegExp(key + '\\s*[^\\s"\'\\n]{8,}').exec(text);
    if (m && !/\{\{\s*generate|<generate>|your[-_]/i.test(m[0])) leaked.push(entry.name + ' → ' + m[0].slice(0, 40));
  }
}
ok('کلید یا رمز واقعی در محتوای بسته نیست (' + scanned + ' فایل متنی بازرسی شد)', leaked.length === 0, leaked.join(' | '));

// ۴) فایل‌های لازم نصب
const REQUIRED = [
  'INSTALL-XAMPP-FA.txt',
  'README.md',
  'ROADMAP-FA.md',
  'THIRD_PARTY_NOTICES.md',
  '.htaccess',
  '.env.example',
  'backend/bootstrap.php',
  'backend/routes/web.php',
  'backend/routes/admin.php',
  'database/schema.sql',
  'database/migrations/002_feature_set.sql',
  'database/migrations/002_feature_set.php',
  'deployment/local-xampp/setup-local-test.php',
  'deployment/local-xampp/migrate.php',
  'deployment/local-xampp/generate-secrets.php',
  'frontend/public/index.php',
  'frontend/public/manifest.webmanifest',
  'frontend/public/sw.js',
  'frontend/public/assets/css/app.css',
  'frontend/public/assets/js/menu.js',
  'frontend/public/assets/fonts/vazirmatn-variable.woff2',
  'frontend/views/layout.php',
  'tests/smoke.php',
];
const baseNames = new Set(files.map((f) => f.name.replace(/^[^/]+\//, '')));
for (const rel of REQUIRED) ok('فایل نصب لازم در بسته است: ' + rel, baseNames.has(rel));

// ۵) storage/private فقط ساختار باشد، نه داده
const storageData = files.filter((f) => /storage\/private\/.+/.test(f.name) && !f.name.endsWith('.gitkeep'));
ok('دادهٔ کاربری در storage/private بسته نیست', storageData.length === 0, storageData.map((f) => f.name).join(','));
ok('پوشهٔ storage/private در بسته ساخته می‌شود', dirs.some((d) => /storage\/private\/$/.test(d)) || baseNames.has('storage/private/.gitkeep'));

// ۶) وابستگی بیرونی در CSS/JS نباشد (فقط نشانی‌هایی که واقعاً بارگذاری می‌شوند؛ شرح‌ها نادیده گرفته می‌شوند)
const remoteRefs = [];
const stripComments = (text) => text
  .replace(/\/\*[\s\S]*?\*\//g, ' ')
  .replace(/<!--[\s\S]*?-->/g, ' ')
  .replace(/(^|[^:"'\\])\/\/[^\n]*/g, '$1 ');
for (const entry of files) {
  if (!/\.(css|js|html?)$/.test(entry.name)) continue;
  const text = stripComments(extract(zip, entry).toString('utf8'));
  const patterns = [
    /url\(\s*['"]?(https?:\/\/[^)'"]+)/gi,
    /@import\s+(?:url\()?['"]?(https?:\/\/[^)'"]+)/gi,
    /(?:src|href)\s*=\s*['"]?(https?:\/\/[^'"\s>]+)/gi,
    /(?:fetch|importScripts)\s*\(\s*['"](https?:\/\/[^'"]+)/gi,
    /\.open\s*\(\s*['"][A-Z]+['"]\s*,\s*['"](https?:\/\/[^'"]+)/gi,
  ];
  for (const re of patterns) {
    for (const m of text.matchAll(re)) {
      if (/^(https?:)?\/\/(localhost|127\.0\.0\.1)/i.test(m[1])) continue;
      remoteRefs.push(entry.name.replace(/^[^/]+\//, '') + ' → ' + m[1].slice(0, 80));
    }
  }
}
ok('هیچ دارایی رابط از بیرون بارگذاری نمی‌شود (CDN-free)', remoteRefs.length === 0, remoteRefs.slice(0, 5).join(' | '));

// ۷) آف‌لاین بودن: فایل‌های tests بزرگ/وابسته نباشد
const heavy = files.filter((f) => /\.(wasm|zip|gz|tgz)$/i.test(f.name) || f.name.includes('package-lock'));
ok('بسته سنگین‌کردهٔ غیرضروری (wasm/lockfile) ندارد', heavy.length === 0, heavy.map((f) => f.name).join(','));

notes.push('بررسی انجام‌شده سطحی/ساختاری است؛ اجرای واقعی روی XAMPP مقصد را جایگزین نمی‌کند.');
notes.push('برای اطمینان از باز شدن بسته در ویندوز، با «Extract All» ویندوز هم یک بار استخراج را امتحان کنید.');

console.log('\n' + '='.repeat(60));
if (notes.length) { console.log('یادداشت‌ها:'); notes.forEach((n) => console.log('  • ' + n)); }
if (problems.length) {
  console.log('\nبازرسی بسته: ' + problems.length + ' ایراد');
  problems.forEach((p) => console.log('  ✗ ' + p));
  process.exit(1);
}
console.log('\nبازرسی بسته: بسته با شرایط «قابل‌نصب، بدون راز» هم‌خوان است.');
