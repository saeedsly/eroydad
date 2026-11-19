/**
 * پیش‌نمایش زندهٔ برنامه روی یک پورت:
 *   فرانت‌کنترل واقعی روی PHP 7.4 (WASM) + MariaDB (WASM) از همان زیرساخت آزمون‌ها،
 *   با دانه‌گذاری دادهٔ نمایشی — بدون هیچ وابستگی به XAMPP یا MySQL بیرونی.
 *
 * اجرا:  node tests/preview-server.mjs [پورت] [مسیر پروژه]
 */
import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { PHPRequestHandler } from '@php-wasm/universal';
import { createTestEnvironment } from './support/harness.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[3] || path.join(HERE, '..'));
const PORT = Number(process.argv[2] || process.env.PORT || 8642);
const SANDBOX = process.env.E2B_SANDBOX_ID || '';
const BASE_URL = process.env.PREVIEW_BASE_URL
  || (SANDBOX ? 'https://' + PORT + '-' + SANDBOX + '.e2b.app/' : 'http://localhost/');

/** صندوق کوکی هر بازدیدکننده (نشست جداسازی‌شده برای هر IP). */
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

console.log('[preview] booting PHP 7.4 + MariaDB (wasm) ...');
const env = await createTestEnvironment({ root: ROOT, withSchema: true, baseUrl: BASE_URL });

// ---------- دانه‌گذاری دادهٔ نمایشی با سرویس‌های واقعی ----------
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
$paidId = $events->create($userId, [
  'title' => 'کارگاه پولی دود',
  'summary' => 'رویداد پولی برای آزمودن نمایش ریز مبلغ و مالیات.',
  'description' => 'شرح کامل رویداد پولی برای آزمودن رندر ریز مبلغ و مالیات در صفحهٔ عمومی.',
  'category' => 'آزمون',
  'delivery_mode' => 'online',
  'venue' => 'آنلاین',
  'capacity' => '10',
  'price_toman' => '250000',
  'tax_percent' => '10',
  'tax_payer' => 'participant',
  'starts_at' => $future,
  'ends_at' => $later,
]);
$events->publish($paidId, $userId, false);
$paidSlug = (string)$db->query('SELECT slug FROM events WHERE id=' . (int)$paidId)->fetchColumn();
$sysHash = password_hash(passwordMaterial('Root-Admin-91-OK'), PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO users (phone,full_name,password_hash,account_type,status,phone_verified_at) VALUES (?,?,?,'municipal','active',UTC_TIMESTAMP())");
$stmt->execute(['09129990003', 'مدیر سامانهٔ آزمون', $sysHash]);
$sysId = (int)$db->lastInsertId();
$sysRole = $db->prepare("SELECT id FROM roles WHERE code='system_admin'");
$sysRole->execute();
$db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$sysId, (int)$sysRole->fetchColumn()]);
$slug = (string)$db->query('SELECT slug FROM events WHERE id=' . (int)$eventId)->fetchColumn();
echo 'SEED ' . json_encode(['user_id' => $userId, 'system_admin_id' => $sysId, 'event_id' => $eventId, 'slug' => $slug, 'paid_id' => $paidId, 'paid_slug' => $paidSlug]) . "\\n";
`,
});
const seedText = typeof seed.text === 'string' ? seed.text : new TextDecoder().decode(seed.stdout ?? new Uint8Array());
if (!/SEED \{/.test(seedText)) {
  console.error('[preview] seed failed:', seedText.slice(0, 500));
  process.exit(1);
}
console.log('[preview] data seeded');

const handler = new PHPRequestHandler({
  php: env.php,
  documentRoot: '/project/frontend/public',
  absoluteUrl: BASE_URL,
  cookieStore: new CookieJar(),
  getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
});

/** نوبت‌دهی به درخواست‌ها: موتور WASM تک‌نخاعی است و درخواست‌های هم‌زمان باید صف شوند. */
let queue = Promise.resolve();
function serialized(fn) {
  const next = queue.then(fn, fn);
  queue = next.catch(() => {});
  return next;
}

const jars = new Map();
function jarFor(ip) {
  if (!jars.has(ip)) jars.set(ip, new CookieJar());
  return jars.get(ip);
}

function pickBody(response) {
  if (response.bytes instanceof Uint8Array) return Buffer.from(response.bytes);
  if (typeof response.text === 'string') return Buffer.from(response.text, 'utf8');
  if (typeof response.stdout === 'string') return Buffer.from(response.stdout, 'utf8');
  if (response.stdout instanceof Uint8Array) return Buffer.from(response.stdout);
  if (typeof response.body === 'string') return Buffer.from(response.body, 'utf8');
  if (response.body instanceof Uint8Array) return Buffer.from(response.body);
  return Buffer.alloc(0);
}

const ZIP = path.join(path.dirname(ROOT), 'eroydad-xampp-php74-updated.zip');
const LANDING = path.join(path.dirname(ROOT), 'index.html');
const LANDING_CSS = path.join(path.dirname(ROOT), 'styles.css');
const LANDING_JS = path.join(path.dirname(ROOT), 'app.js');

function serveFile(res, filePath, type, disposition) {
  if (!fs.existsSync(filePath)) { res.writeHead(404); res.end('not found'); return; }
  const data = fs.readFileSync(filePath);
  res.writeHead(200, {
    'Content-Type': type,
    'Content-Length': data.length,
    ...(disposition ? { 'Content-Disposition': disposition } : {}),
  });
  res.end(data);
}

const server = http.createServer((req, res) => {
  const ip = req.socket.remoteAddress || 'unknown';

  // دانلود بستهٔ تحویلی + صفحهٔ فرود ایستا (بدون دخالت PHP)
  if (req.url === '/eroydad-xampp-php74-updated.zip') {
    serveFile(res, ZIP, 'application/zip', 'attachment; filename="eroydad-xampp-php74-updated.zip"');
    return;
  }
  if (req.url === '/landing' || req.url === '/landing/') { serveFile(res, LANDING, 'text/html; charset=utf-8'); return; }
  if (req.url === '/styles.css') { serveFile(res, LANDING_CSS, 'text/css; charset=utf-8'); return; }
  if (req.url === '/app.js') { serveFile(res, LANDING_JS, 'application/javascript; charset=utf-8'); return; }

  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    serialized(async () => {
      try {
        const body = chunks.length ? Buffer.concat(chunks) : undefined;
        const jar = jarFor(ip);
        const headers = {};
        for (const name of ['content-type', 'accept', 'accept-language', 'x-requested-with']) {
          if (req.headers[name]) headers[name] = req.headers[name];
        }
        const cookie = jar.getCookieRequestHeader();
        if (cookie) headers.cookie = cookie;
        const response = await handler.request({ url: req.url, method: req.method, body, headers });
        jar.rememberCookiesFromResponseHeaders(response.headers);
        const out = {};
        for (const [name, value] of Object.entries(response.headers || {})) {
          const n = String(name).toLowerCase();
          if (n === 'set-cookie' || n === 'content-length' || n === 'transfer-encoding' || n === 'content-encoding') continue;
          out[name] = value;
        }
        const payload = pickBody(response);
        out['Content-Length'] = payload.length;
        res.writeHead(response.httpStatusCode || 200, out);
        res.end(payload);
      } catch (err) {
        console.error('[preview] request error:', err && err.message ? err.message : err);
        if (!res.headersSent) { res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' }); }
        res.end('preview error');
      }
    });
  });
});

server.listen(PORT, '0.0.0.0', () => {
  console.log('[preview] PREVIEW READY on 0.0.0.0:' + PORT + ' base=' + BASE_URL);
});
