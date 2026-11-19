/**
 * تولیدسند `docs/api/routes.md` از جدول مسیرهای backend/routes/*.php.
 *
 * اجرا:
 *   node tests/gen-routes-doc.mjs          # سند را (باز)نویسی می‌کند
 *   node tests/gen-routes-doc.mjs --check  # اگر سند با کد هم‌خوان نباشد، با خطا بیرون می‌رود
 *
 * سند تولیدشده را دستی ویرایش نکنید؛ خطوط `route(…)` را در فایل‌های route تغییر دهید
 * و دوباره این دستور را بزنید.
 */
import { readFileSync, writeFileSync, existsSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const OUT = path.join(ROOT, 'docs', 'api', 'routes.md');
const FILES = [
  ['web.php', 'مسیرهای عمومی و پنل فراگیر'],
  ['admin.php', 'مسیرهای پنل مدیریت (نقش‌محور)'],
];

/** رشتهٔ تک‌نقلی‌ای PHP را مثل خود PHP decode می‌کند (فقط \\ و \'). */
const phpSingleQuote = (raw) => raw.replace(/\\([\\'])/g, '$1');

function collect() {
  const sections = [];
  for (const [name, title] of FILES) {
    const file = path.join(ROOT, 'backend', 'routes', name);
    const lines = readFileSync(file, 'utf8').split('\n');
    const routes = [];
    let section = '';
    let inBlockComment = false;
    for (const rawLine of lines) {
      const line = rawLine.trim();
      if (inBlockComment) {
        if (line.endsWith('*/')) inBlockComment = false;
        continue;
      }
      if (line.startsWith('/*')) {
        if (!line.endsWith('*/')) inBlockComment = true;
        continue;
      }
      if (line.startsWith('//')) {
        const sec = /^\/\/\s*-+\s*(.+?)\s*-+$/.exec(line);
        if (sec) section = sec[1];
        continue;
      }
      if (line.startsWith('*')) continue;
      const decl = /\broute\(\s*'([A-Z]+)'\s*,\s*'([^']+)'/g;
      let m;
      while ((m = decl.exec(line)) !== null) {
        const pattern = phpSingleQuote(m[2]);
        routes.push({
          method: m[1],
          pattern,
          kind: pattern.startsWith('#') && pattern.endsWith('#') ? 'الگو' : 'دقیق',
          section,
        });
      }
    }
    sections.push({ name, title, routes });
  }
  return sections;
}

function render(sections) {
  const total = sections.reduce((n, s) => n + s.routes.length, 0);
  const out = [];
  out.push('# فهرست مسیرهای سامانه (سند تولیدشده)');
  out.push('');
  out.push('> این سند از خطوط `route(…)` داخل `backend/routes/*.php` تولید می‌شود؛');
  out.push('> به‌روزرسانی: `node tests/gen-routes-doc.mjs` و کنترل هم‌خوانی: `node tests/gen-routes-doc.mjs --check`.');
  out.push('> دستی ویرایش نکنید — ویرایش شما با اجرای بعدی بازنویسی می‌شود.');
  out.push('');
  out.push(`مجموع مسیرها: **${total}** (${sections.map((s) => `${s.title}: ${s.routes.length}`).join('، ')}).`);
  out.push('');
  out.push('قرارداد: متد فقط `GET` یا `POST` است؛ الگوها با جداکنندهٔ `#…#` (regex PHP) نوشته می‌شوند و بقیه مقایسهٔ دقیق‌اند. ترتیبِ اعلان = ترتیبِ بررسی در زمان اجرا؛ اولین تطبیق برنده است.');
  out.push('');
  for (const s of sections) {
    out.push(`## ${s.title} (\`backend/routes/${s.name}\`)`);
    out.push('');
    out.push('| # | متد | مسیر / الگو | نوع | بخش |');
    out.push('|---|------|-------------|------|------|');
    let i = 0;
    let lastSection = null;
    for (const r of s.routes) {
      i += 1;
      const sec = r.section && r.section !== lastSection ? r.section : (r.section ?? '');
      lastSection = r.section ?? lastSection;
      const pattern = r.pattern.replace(/\|/g, '\\|');
      out.push(`| ${i} | \`${r.method}\` | \`${pattern}\` | ${r.kind} | ${sec} |`);
    }
    out.push('');
  }
  return out.join('\n');
}

const sections = collect();
const content = render(sections);
const check = process.argv.includes('--check');

if (check) {
  const current = existsSync(OUT) ? readFileSync(OUT, 'utf8') : '';
  if (current !== content) {
    console.error('docs/api/routes.md با backend/routes هم‌خوان نیست. با `node tests/gen-routes-doc.mjs` بازتولید کنید.');
    process.exit(1);
  }
  const total = sections.reduce((n, s) => n + s.routes.length, 0);
  console.log(`سند مسیرها هم‌خوان است: ${total} مسیر در docs/api/routes.md.`);
} else {
  writeFileSync(OUT, content);
  const total = sections.reduce((n, s) => n + s.routes.length, 0);
  console.log(`سند مسیرها نوشته شد: docs/api/routes.md — ${total} مسیر.`);
}
