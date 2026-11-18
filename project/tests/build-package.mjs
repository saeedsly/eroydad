#!/usr/bin/env node
/**
 * بستهٔ قابل‌تحویل را می‌سازد: پوشهٔ project/ به ZIP قابل‌استخراج در htdocs/ با پیش‌پوشهٔ erooydad.
 * بدون وابستگی بیرونی است و خروجی‌اش برای اجرای دوباره یکسان می‌ماند (مرتب‌شده، برچسب زمانی ثابت).
 *
 *   node tests/build-package.mjs [مسیر خروجی]
 *
 * پس از ساخت، `node tests/zip-audit.mjs <همان مسیر>` شرایط «قابل‌نصب، بدون راز» را بررسی می‌کند.
 */
import { deflateRawSync } from 'node:zlib';
import { readFileSync, readdirSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(here, '..');
const topFolder = 'erooydad';
const defaultOut = path.join(path.dirname(projectRoot), 'eroydad-xampp-php74-updated.zip');

// فایل‌هایی که هرگز داخل بسته نمی‌روند (رازها، ابزار توسعه، دادهٔ نصب).
const EXCLUDED_NAMES = new Set(['.env', 'config.local.php', 'package-lock.json', 'package-lock.yml', '.DS_Store']);
const EXCLUDED_DIRS = new Set(['node_modules', '.git', '.idea', '.vscode']);
const EXCLUDED_SUFFIX = ['.log', '.tmp', '.pem', '.key', '.zip', '.sql.local'];

const isExcluded = (rel) => {
  const parts = rel.split('/');
  const base = parts[parts.length - 1];
  if (parts.some((p) => EXCLUDED_DIRS.has(p))) return true;
  if (EXCLUDED_NAMES.has(base)) return true;
  if (EXCLUDED_SUFFIX.some((s) => base.endsWith(s))) return true;
  // storage/private فقط ساختار را می‌برد، نه محتوای کاربری.
  if (rel.startsWith('storage/private/') && base !== '.gitkeep') return true;
  return false;
};

const walk = (dir, acc) => {
  for (const entry of readdirSync(path.join(projectRoot, dir), { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1))) {
    const rel = dir === '' ? entry.name : `${dir}/${entry.name}`;
    if (isExcluded(rel)) continue;
    if (entry.isDirectory()) { acc.push({ dir: rel }); walk(rel, acc); }
    else if (entry.isFile()) acc.push({ file: rel });
  }
  return acc;
};

// ---------- ZIP writer ----------
const CRC_TABLE = (() => {
  const t = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();
const crc32 = (buf) => {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
};

// برچسب زمانی ثابت (۱۳۹۹/۰۱/۰۱ به بعد از ۲۰۲۶-۰۱-۰۱ UTC) تا خروجی تکرارپذیر بماند.
const DOS_TIME = 0;
const DOS_DATE = (((2026 - 1980) << 9) | (1 << 5) | 1) & 0xffff;

const u16 = (n) => Buffer.from([n & 0xff, (n >>> 8) & 0xff]);
const u32 = (n) => Buffer.from([n & 0xff, (n >>> 8) & 0xff, (n >>> 16) & 0xff, (n >>> 24) & 0xff]);

const build = () => {
  const items = walk('', []);
  const names = new Set();
  const chunks = [];
  const central = [];
  let offset = 0;

  const push = (name, { isDir, data }) => {
    if (names.has(name)) throw new Error(`ورودی تکراری: ${name}`);
    names.add(name);
    const nameBuf = Buffer.from(name, 'utf8');
    const flags = 0x0800; // نام‌ها UTF-8 هستند
    let method = 0;
    let stored = Buffer.alloc(0);
    let crc = 0;
    if (!isDir) {
      stored = data;
      crc = crc32(stored);
      if (stored.length > 0) {
        const deflated = deflateRawSync(stored, { level: 9 });
        if (deflated.length < stored.length) { method = 8; data = deflated; }
      }
    }
    const comp = isDir ? Buffer.alloc(0) : data;
    const local = Buffer.concat([
      Buffer.from('PK\x03\x04', 'binary'), u16(20), u16(flags), u16(method), u16(DOS_TIME), u16(DOS_DATE),
      u32(crc), u32(comp.length), u32(isDir ? 0 : stored.length), u16(nameBuf.length), u16(0), nameBuf, comp,
    ]);
    chunks.push(local);
    central.push(Buffer.concat([
      Buffer.from('PK\x01\x02', 'binary'), u16(20), u16(20), u16(flags), u16(method), u16(DOS_TIME), u16(DOS_DATE),
      u32(crc), u32(comp.length), u32(isDir ? 0 : stored.length), u16(nameBuf.length), u16(0), u16(0),
      u16(0), u16(0), u32(isDir ? 0x10 | ((0o775 << 16) >>> 0) : (0o644 << 16) >>> 0), u32(offset), nameBuf,
    ]));
    offset += local.length;
  };

  // پوشهٔ ریشه و سپس همهٔ ورودی‌ها به ترتیب الفبایی، تا بسته نردبان‌به‌نردبان درست باز شود.
  push(`${topFolder}/`, { isDir: true });
  const dirs = items.filter((x) => x.dir).map((x) => x.dir).sort();
  for (const rel of dirs) push(`${topFolder}/${rel}/`, { isDir: true });
  const files = items.filter((x) => x.file).map((x) => x.file).sort();
  for (const rel of files) push(`${topFolder}/${rel}`, { isDir: false, data: readFileSync(path.join(projectRoot, rel)) });

  const centralBuf = Buffer.concat(central);
  const eocd = Buffer.concat([
    Buffer.from('PK\x05\x06', 'binary'), u16(0), u16(0), u16(central.length), u16(central.length),
    u32(centralBuf.length), u32(offset), u16(0),
  ]);
  return { zip: Buffer.concat([...chunks, centralBuf, eocd]), fileCount: files.length, dirCount: dirs.length + 1 };
};

const out = process.argv[2] ? path.resolve(process.argv[2]) : defaultOut;
mkdirSync(path.dirname(out), { recursive: true });
const { zip, fileCount, dirCount } = build();
writeFileSync(out, zip);
console.log(`بسته ساخته شد: ${out}`);
console.log(`${fileCount} فایل و ${dirCount} پوشه، ${(zip.length / 1048576).toFixed(2)} مگابایت.`);
