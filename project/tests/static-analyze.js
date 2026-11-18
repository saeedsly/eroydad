/**
 * تحلیل‌گر ایستای سبک برای سامانهٔ ای‌رویداد.
 *
 * هدف: بدون نیاز به PHP/MySQL، سازگاری routeها با متدهای واقعی سرویس‌ها و
 * سازگاری متغیرهای renderشده با viewها را بررسی کند.
 *
 * اجرا:  node tests/static-analyze.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SRC = path.join(ROOT, 'backend', 'src');
const ROUTES = path.join(ROOT, 'backend', 'routes');
const VIEWS = path.join(ROOT, 'frontend', 'views');

const problems = [];
const notes = [];

function walk(dir, ext, out) {
  out = out || [];
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, ext, out);
    else if (entry.name.endsWith(ext)) out.push(full);
  }
  return out;
}

/** نگاشت نام کلاس ⇒ نام متدهای public و static. */
function collectClasses() {
  const map = new Map();
  for (const file of walk(SRC, '.php')) {
    const code = fs.readFileSync(file, 'utf8');
    const nsMatch = code.match(/^namespace\s+([^;]+);/m);
    const ns = nsMatch ? nsMatch[1].trim() : '';
    const classRe = /^(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/gm;
    let m;
    while ((m = classRe.exec(code))) {
      const cls = m[1];
      const body = code.slice(m.index);
      const methods = new Set();
      const staticMethods = new Set();
      const methodRe = /(public|protected|private)\s+(?:static\s+)?function\s+([A-Za-z0-9_]+)\s*\(/g;
      let mm;
      while ((mm = methodRe.exec(body))) {
        if (mm[1] === 'public') {
          methods.add(mm[2]);
          if (/public\s+static\s+function/.test(mm[0])) staticMethods.add(mm[2]);
        }
      }
      map.set(cls, {
        ns: ns,
        file: path.relative(ROOT, file),
        methods: methods,
        staticMethods: staticMethods,
      });
    }
  }
  return map;
}

const classes = collectClasses();

/** متدهایی که از طریق trait یا کلاس پایه در دسترس‌اند. */
function hasMethod(cls, method) {
  const info = classes.get(cls);
  if (!info) return false;
  if (info.methods.has(method)) return true;
  // متدهایی که از PDO ارث می‌برند یا متدهای شناخته‌شدهٔ عمومی
  return ['__construct', '__toString'].includes(method);
}

/**
 * نام کلاس‌های استفاده‌شده در یک فایل route و متدهای صدا زده‌شده روی آن‌ها.
 * تحلیل به ترتیب خطوط انجام می‌شود تا نگاشت «متغیر ⇒ کلاس» با آخرین
 * انتساب در همان نقطه هم‌خوان بماند (نام متغیرها در شاخه‌های مختلف تکرار شده است).
 */
function analyzeRouteFile(file) {
  const code = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  const varToClass = new Map();

  const assignRe = /\$(\w+)\s*=\s*new\s+\\?([A-Za-z0-9_\\]+)\s*\(/g;
  const inlineRe = /\(new\s+\\?([A-Za-z0-9_\\]+)\s*\([^()]*\)\)\s*->\s*([A-Za-z0-9_]+)\s*\(/g;
  const callRe = /\$(\w+)\s*->\s*([A-Za-z0-9_]+)\s*\(/g;
  const staticRe = /\\?([A-Za-z0-9_\\]+)::([A-Za-z0-9_]+)\s*\(/g;

  const events = [];
  let m;
  while ((m = assignRe.exec(code))) events.push({ at: m.index, kind: 'assign', m });
  while ((m = inlineRe.exec(code))) events.push({ at: m.index, kind: 'inline', m });
  while ((m = callRe.exec(code))) events.push({ at: m.index, kind: 'call', m });
  while ((m = staticRe.exec(code))) events.push({ at: m.index, kind: 'static', m });
  events.sort((a, b) => a.at - b.at);

  for (const ev of events) {
    const mm = ev.m;
    if (ev.kind === 'assign') {
      varToClass.set(mm[1], mm[2].split('\\').pop());
    } else if (ev.kind === 'inline') {
      const short = mm[1].split('\\').pop();
      if (!classes.has(short)) {
        problems.push(`${rel}: کلاس «${mm[1]}» یافت نشد (خط ${lineOf(code, mm.index)}).`);
        continue;
      }
      if (!hasMethod(short, mm[2])) {
        problems.push(`${rel}: متد «${short}::${mm[2]}» وجود ندارد (خط ${lineOf(code, mm.index)}).`);
      }
    } else if (ev.kind === 'call') {
      const cls = varToClass.get(mm[1]);
      if (!cls || !classes.has(cls)) continue;
      if (!hasMethod(cls, mm[2])) {
        problems.push(`${rel}: متد «${cls}::${mm[2]}» روی $${mm[1]} وجود ندارد (خط ${lineOf(code, mm.index)}).`);
      }
    } else {
      const short = mm[1].split('\\').pop();
      if (!classes.has(short)) continue;
      const info = classes.get(short);
      if (!info.methods.has(mm[2])) {
        problems.push(`${rel}: متد ایستای «${short}::${mm[2]}» وجود ندارد (خط ${lineOf(code, mm.index)}).`);
      }
    }
  }
}

function lineOf(code, index) {
  return code.slice(0, index).split('\n').length;
}

/** متغیرهای مورد انتظار هر view و متغیرهای renderشده در routeها. */
function collectViewVars() {
  const viewVars = new Map();
  for (const file of walk(VIEWS, '.php')) {
    const code = fs.readFileSync(file, 'utf8');
    const vars = new Set();
    const re = /\$([A-Za-z_][A-Za-z0-9_]*)/g;
    let m;
    const globals = new Set(['this', 'GLOBALS', '_SERVER', '_POST', '_GET', '_FILES', '_SESSION', '_COOKIE', 'argv', 'argc']);
    while ((m = re.exec(code))) {
      if (!globals.has(m[1])) vars.add(m[1]);
    }
    // متغیرهایی که خودِ view تعریف می‌کند (حلقه، انتساب محلی یا include) نیازی به route ندارند.
    const local = new Set();
    const localRe = /\bas\s+\$([A-Za-z_][A-Za-z0-9_]*)/g;
    while ((m = localRe.exec(code))) local.add(m[1]);
    const assignRe = /(?:^|[^\w$])\$([A-Za-z_][A-Za-z0-9_]*)\s*=(?!=)/gm;
    while ((m = assignRe.exec(code))) local.add(m[1]);
    const foreachRe = /foreach\s*\([^)]*\bas\s*&?\$([A-Za-z_][A-Za-z0-9_]*)\s*(?:=>\s*\$([A-Za-z_][A-Za-z0-9_]*))?/g;
    while ((m = foreachRe.exec(code))) {
      local.add(m[1]);
      if (m[2]) local.add(m[2]);
    }
    // الگوی تجزیه: foreach ($x as [$a, $b, ...])
    const destructRe = /\bas\s*\[([^\]]*)\]/g;
    while ((m = destructRe.exec(code))) {
      const inner = /\$([A-Za-z_][A-Za-z0-9_]*)/g;
      let im;
      while ((im = inner.exec(m[1]))) local.add(im[1]);
    }
    // پارامترهای arrow function
    const fnRe = /\bfn\s*\(\s*&?\$([A-Za-z_][A-Za-z0-9_]*)/g;
    while ((m = fnRe.exec(code))) local.add(m[1]);
    // پارامترهای closure/تابع
    const closureRe = /function\s*\([^)]*\)/g;
    while ((m = closureRe.exec(code))) {
      const inner = /\$([A-Za-z_][A-Za-z0-9_]*)/g;
      let im;
      while ((im = inner.exec(m[0]))) local.add(im[1]);
    }
    // متغیرهایی که فقط با isset/?? خوانده می‌شوند، اختیاری‌اند.
    const issetRe = /isset\s*\(\s*&?\$([A-Za-z_][A-Za-z0-9_]*)/g;
    while ((m = issetRe.exec(code))) local.add(m[1]);
    local.forEach((v) => vars.delete(v));
    viewVars.set(path.relative(ROOT, file), vars);
  }
  return viewVars;
}

const viewVars = collectViewVars();

/** بررسی اینکه هر render() متغیرهای لازم view را فراهم می‌کند. */
function analyzeRenderCalls(file) {
  const code = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  const renderRe = /render\(\s*'([^']+)'\s*(?:,\s*\[)?/g;
  let m;
  while ((m = renderRe.exec(code))) {
    const viewName = m[1].replace(/\.php$/, '');
    const candidates = [];
    const direct = path.join(VIEWS, viewName + '.php');
    if (fs.existsSync(direct)) candidates.push(direct);
    else {
      const under = walk(VIEWS, '.php').filter((f) => path.basename(f, '.php') === path.basename(viewName));
      candidates.push(...under);
    }
    if (!candidates.length) {
      problems.push(`${rel}: view «${m[1]}» در frontend/views پیدا نشد.`);
      continue;
    }
    // استخراج کلیدهای آرایهٔ دوم render
    const after = code.slice(m.index, m.index + 1200);
    const arrStart = after.indexOf('[');
    if (arrStart === -1) continue;
    let depth = 0;
    let end = -1;
    for (let i = arrStart; i < after.length; i += 1) {
      if (after[i] === '[') depth += 1;
      else if (after[i] === ']') {
        depth -= 1;
        if (depth === 0) { end = i; break; }
      }
    }
    if (end === -1) continue;
    const arr = after.slice(arrStart, end + 1);
    const provided = new Set();
    const keyRe = /['"]([A-Za-z0-9_]+)['"]\s*=>/g;
    let km;
    while ((km = keyRe.exec(arr))) provided.add(km[1]);
    for (const candidate of candidates) {
      const needed = viewVars.get(path.relative(ROOT, candidate));
      const missing = [];
      for (const v of needed) {
        if (provided.has(v)) continue;
        if (['title', 'csrf', 'user', 'flash', 'basePath', 'layout', 'pageTitle', 'error', 'message'].includes(v)) continue;
        missing.push(v);
      }
      if (missing.length) {
        notes.push(`${rel} → ${path.relative(ROOT, candidate)}: متغیرهای احتمالیِ تأمین‌نشده: ${missing.join(', ')}`);
      }
    }
  }
}

for (const file of walk(ROUTES, '.php')) {
  analyzeRouteFile(file);
  analyzeRenderCalls(file);
}

// بررسی وجود view برای هر render در bootstrap
const bootstrap = fs.readFileSync(path.join(ROOT, 'backend', 'bootstrap.php'), 'utf8');
const bootRenders = bootstrap.match(/render\(\s*'([^']+)'/g) || [];
for (const r of bootRenders) {
  const name = r.replace(/render\(\s*'/, '').replace(/'$/, '');
  const direct = path.join(VIEWS, name + '.php');
  if (!fs.existsSync(direct)) {
    const under = walk(VIEWS, '.php').filter((f) => path.basename(f, '.php') === path.basename(name));
    if (!under.length) problems.push(`backend/bootstrap.php: view «${name}» پیدا نشد.`);
  }
}

// بررسی اینکه هر require در bootstrap و index.php به فایل موجود اشاره کند
function checkRequires(file) {
  const code = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  const re = /require(?:_once)?\s+([^;]+);/g;
  let m;
  while ((m = re.exec(code))) {
    const expr = m[1].trim();
    if (!expr.startsWith("'") && !expr.startsWith('"')) continue;
    const target = expr.slice(1, -1);
    if (target.indexOf('__DIR__') !== -1) {
      const dir = path.dirname(file);
      const resolved = path.resolve(dir, target.replace('__DIR__', '').replace(/^\//, ''));
      if (!fs.existsSync(resolved)) {
        problems.push(`${rel}: require به فایل ناموجود «${target}» اشاره دارد.`);
      }
    }
  }
}
for (const file of walk(path.join(ROOT, 'backend'), '.php')) checkRequires(file);
checkRequires(path.join(ROOT, 'frontend', 'public', 'index.php'));

/**
 * پوشش فرم‌ها: هر action در viewها باید با یک route واقعی تطبیق کند.
 * این بررسی جلوی ایرادهایی مثل «دکمهٔ ثبت‌نام به مسیر تعریف‌نشده پست می‌کند» را
 * بدون اجرای برنامه می‌گیرد.
 */

/** الگوی مسیر regex را به اسکلت بخش‌بندی‌شده تبدیل می‌کند؛ هر گروه/کلاس «*». */
function regexSkeleton(source) {
  const body = source.replace(/^\^/, '').replace(/\$\d*$/, '').replace(/\\\//g, '/');
  return body.split('/').map((segment) => {
    let s = segment.replace(/\((?:[^()]*)\)/g, '*');
    s = s.replace(/\[(?:[^\]]*)\]/g, '*');
    s = s.replace(/[+?]|\{\d+(?:,\d*)?\}|\*/g, (t) => (t === '*' ? '*' : ''));
    s = s.replace(/\\/, '');
    return s === '' ? '' : s;
  });
}

/** مسیر یک فرم (با جای‌نماهای <?= ?>) به اسکلت بخش‌بندی‌شده. */
function actionSkeleton(action) {
  // نخست جای‌نماها را نشانه‌گذاری کن، وگرنه «?» در «?>» مسیر را خراب می‌کند
  const withSentinel = action.replace(/<\?[\s\S]*?\?>/g, '\u0000').replace(/^\/erooydad(?=\/|$)/, '').replace(/\?.*$/, '');
  const head = withSentinel.startsWith('/') ? '' : '';
  const parts = withSentinel.replace(/^\//, '').split('/');
  const segments = parts.map((part) => (part.includes('\u0000') ? '*' : part));
  return [head, ...segments];
}

function segmentsMatch(a, b) {
  if (a.length !== b.length) return false;
  return a.every((part, index) => part === '*' || b[index] === '*' || part === b[index]);
}

function collectRouteMatchers() {
  const matchers = [];
  for (const file of walk(ROUTES, '.php')) {
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    let inBlockComment = false;
    for (const rawLine of lines) {
      const line = rawLine.trim();
      // خط‌های کامنت‌شده route محسوب نمی‌شوند
      if (inBlockComment) { if (line.endsWith('*/')) inBlockComment = false; continue; }
      if (line.startsWith('/*')) { if (!line.endsWith('*/')) inBlockComment = true; continue; }
      if (line.startsWith('//') || line.startsWith('#') || line.startsWith('*')) continue;
      const methods = [...line.matchAll(/\$method\s*===\s*'([A-Z]+)'/g)].map((x) => x[1]);
      for (const x of line.matchAll(/\$path\s*===\s*'([^']+)'/g)) {
        matchers.push({ segments: x[1].split('/'), methods });
      }
      // جداکنندهٔ الگوی PCRE در PHP «#...#» است؛ نخست رشتهٔ کامل را می‌گیریم
      for (const x of line.matchAll(/preg_match\w*\(\s*(['"])(.*?)\1\s*,/g)) {
        const pattern = x[2].replace(/^#/, '').replace(/#$/, '').replace(/\/$/, '').replace(/^\//, '');
        matchers.push({ segments: regexSkeleton(pattern), methods });
      }
    }
  }
  return matchers;
}

const routeMatchers = collectRouteMatchers();

function checkFormActions(file) {
  const code = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  // برچسب <form> می‌تواند داخل خود <?= ?> داشته باشد؛ آن را هم می‌خوانیم.
  for (const match of code.matchAll(/<form\b((?:<\?=[\s\S]*?\?>|"[^"]*"|'[^']*'|[^>])*)>/gi)) {
    const attrs = match[1];
    const actionMatch = /action\s*=\s*"([^"]*)"/i.exec(attrs);
    if (!actionMatch) continue;
    const raw = actionMatch[1].trim();
    if (raw === '' || /^(https?:|mailto:|tel:|#|javascript:)/i.test(raw)) continue;
    const methodMatch = /method\s*=\s*"([a-z]+)"/i.exec(attrs);
    const method = (methodMatch ? methodMatch[1] : 'get').toUpperCase();
    const skeleton = actionSkeleton(raw);
    if (skeleton.length < 2) continue;
    const matched = routeMatchers.some(
      (m) => (m.methods.length === 0 || m.methods.includes(method)) && segmentsMatch(skeleton, m.segments),
    );
    if (!matched) {
      problems.push(`${rel}: فرم با متد ${method} به «${raw}» پست می‌کند ولی route متناظری در backend/routes پیدا نشد.`);
    }
  }
}

for (const file of walk(VIEWS, '.php')) checkFormActions(file);

// ---------------------------------------------------------------------------
// برچسب‌های HTML را با تحمل <?= ?> و نقل‌قول‌ها می‌خوانیم (regex ساده کافی نیست)
// ---------------------------------------------------------------------------
function tagEnd(code, start) {
  let i = start;
  let inPhp = false;
  let quote = '';
  while (i < code.length) {
    if (inPhp) {
      if (code.startsWith('?>', i)) { inPhp = false; i += 2; continue; }
    } else if (quote) {
      if (code[i] === quote) quote = '';
    } else if (code.startsWith('<?', i)) {
      inPhp = true; i += 2; continue;
    } else if (code[i] === '"' || code[i] === "'") {
      quote = code[i];
    } else if (code[i] === '>') {
      return i + 1;
    }
    i += 1;
  }
  return -1;
}

function htmlTags(code, name) {
  const found = [];
  const low = code.toLowerCase();
  const needle = '<' + name.toLowerCase();
  let i = 0;
  for (;;) {
    const j = low.indexOf(needle, i);
    if (j < 0) break;
    const next = code[j + needle.length];
    if (next && /[a-z0-9_-]/i.test(next)) { i = j + needle.length; continue; }
    const end = tagEnd(code, j);
    if (end < 0) break;
    found.push({ attrs: code.slice(j + needle.length, end - 1), pos: j });
    i = end;
  }
  return found;
}

function attrOf(attrs, name) {
  let m = new RegExp('(?:^|\\s)' + name + '\\s*=\\s*"([^"]*)"', 'i').exec(attrs);
  if (m) return m[1];
  m = new RegExp("(?:^|\\s)" + name + "\\s*=\\s*'([^']*)'", 'i').exec(attrs);
  if (m) return m[1];
  m = new RegExp('(?:^|\\s)' + name + '\\s*=\\s*<\\?=', 'i').exec(attrs);
  if (m) return '<?=dynamic?>';
  return null;
}

const lineNumberOf = (code, index) => code.slice(0, index).split('\n').length;

// ۱) دسترس‌پذیری و تأیید عملیات حساس در قالب‌ها
const DESTRUCTIVE = /(cancel|delete|revoke|suspend|purge|reissue|disable|logout-all|remove)/i;

function checkAccessibility(file) {
  const code = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);

  if (/(?:^|\/)layout\.php$/.test(rel)) {
    const html = htmlTags(code, 'html')[0];
    if (!html) {
      problems.push(`${rel}: برچسب <html> پیدا نشد.`);
    } else {
      if (!attrOf(html.attrs, 'lang')) problems.push(`${rel}: <html> ویژگی lang ندارد.`);
      if (attrOf(html.attrs, 'dir') !== 'rtl') problems.push(`${rel}: <html> برای راست‌به‌چپ بودن dir="rtl" ندارد.`);
    }
  }

  for (const img of htmlTags(code, 'img')) {
    if (attrOf(img.attrs, 'alt') === null) {
      problems.push(`${rel}: تصویر بدون متن جایگزین (alt) در خط ${lineNumberOf(code, img.pos)}.`);
    }
  }

  const ranges = [];
  for (const label of htmlTags(code, 'label')) {
    const close = code.toLowerCase().indexOf('</label>', label.pos);
    ranges.push([label.pos, close < 0 ? code.length : close]);
  }
  const labelFor = new Set();
  for (const label of htmlTags(code, 'label')) {
    const forValue = attrOf(label.attrs, 'for');
    if (forValue) labelFor.add(forValue);
  }
  for (const tag of ['input', 'select', 'textarea']) {
    for (const control of htmlTags(code, tag)) {
      const type = (attrOf(control.attrs, 'type') || '').toLowerCase();
      if (tag === 'input' && ['hidden', 'submit', 'button', 'reset', 'image'].includes(type)) continue;
      if (attrOf(control.attrs, 'name') === null && attrOf(control.attrs, 'id') === null) continue;
      if (attrOf(control.attrs, 'aria-label') || attrOf(control.attrs, 'aria-labelledby') || attrOf(control.attrs, 'title')) continue;
      const id = attrOf(control.attrs, 'id');
      if (id && labelFor.has(id)) continue;
      if (control.attrs.includes('<?=') && id && id.startsWith('<?=')) continue;
      const insideLabel = ranges.some(([start, end]) => control.pos > start && control.pos < end);
      if (insideLabel) continue;
      problems.push(`${rel}: ورودی «${id || attrOf(control.attrs, 'name') || tag}» در خط ${lineNumberOf(code, control.pos)} به هیچ برچسب <label> وصل نیست.`);
    }
  }

  for (const form of htmlTags(code, 'form')) {
    const method = (attrOf(form.attrs, 'method') || 'get').toLowerCase();
    const action = attrOf(form.attrs, 'action') || '';
    if (method === 'post' && DESTRUCTIVE.test(action) && !form.attrs.includes('data-confirm')) {
      problems.push(`${rel}: فرم عملیات حساس «${action}» در خط ${lineNumberOf(code, form.pos)} تأییدیهٔ data-confirm ندارد.`);
    }
  }
}

for (const file of walk(VIEWS, '.php')) checkAccessibility(file);

// ۲) شمارندهٔ آماده‌سازی باید اجرا شود: prepare(...) بدون execute() نتیجهٔ خالی می‌دهد
const PREPARE_ASSIGN = /\$(\w+)\s*=\s*[^;]*->prepare\s*\(/;
const STATEMENT_READ = /\$(\w+)\s*->\s*(fetch\w*|errorInfo|columnCount)\s*\(/;
const STATEMENT_EXEC = /\$(\w+)\s*->\s*execute\s*\(/;

function checkStatementExecution(file) {
  const code = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  const lines = code.split('\n');
  const pending = new Map();
  lines.forEach((line, index) => {
    const lineNo = index + 1;
    const prepared = PREPARE_ASSIGN.exec(line);
    if (prepared) {
      const varName = '$' + prepared[1];
      const escaped = '\\' + varName;
      const after = line.slice(prepared.index + prepared[0].length);
      const executedHere = new RegExp(escaped + '\\s*->\\s*execute\\s*\\(').test(after);
      const readHere = new RegExp(escaped + '\\s*->\\s*fetch').test(after);
      if (!executedHere && readHere) {
        problems.push(`${rel}: خط ${lineNo}: ${varName} پیش از execute() خوانده شد؛ نتیجه خالی برمی‌گردد.`);
      } else if (!executedHere) {
        pending.set(prepared[1], lineNo);
      }
      return;
    }
    const read = STATEMENT_READ.exec(line);
    if (read && pending.has(read[1])) {
      const execMatch = STATEMENT_EXEC.exec(line);
      const readIndex = line.indexOf(read[0]);
      const execIndex = execMatch ? line.indexOf(execMatch[0]) : -1;
      if (execIndex < 0 || execIndex > readIndex) {
        problems.push(`${rel}: خط ${lineNo}: $${read[1]} (آماده‌سازی در خط ${pending.get(read[1])}) پیش از execute() خوانده شد؛ نتیجه خالی برمی‌گردد.`);
        pending.delete(read[1]);
      }
    }
    const exec = STATEMENT_EXEC.exec(line);
    if (exec) pending.delete(exec[1]);
  });
}

for (const dir of [path.join(ROOT, 'backend'), path.join(ROOT, 'frontend'), path.join(ROOT, 'deployment')]) {
  for (const file of walk(dir, '.php')) checkStatementExecution(file);
}

// ۳) کلاس‌های دارای namespace باید با use وارد فایل شوند؛ در غیر این صورت «Class not found» می‌گیریم
const PHP_INTERNAL = new Set([
  'PDO', 'PDOStatement', 'PDOException', 'Exception', 'Error', 'TypeError', 'Throwable', 'stdClass',
  'DomainException', 'RuntimeException', 'InvalidArgumentException', 'LogicException', 'LengthException',
  'OutOfBoundsException', 'OutOfRangeException', 'BadFunctionCallException',
  'DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'IntlDateFormatter', 'Intlgregorian',
  'Closure', 'Generator', 'SplFileObject', 'SplPriorityQueue', 'SplQueue', 'SessionHandler', 'ZipArchive',
  'JsonSerializable', 'ArrayAccess', 'Countable', 'IteratorAggregate', 'Serializable',
]);

function collectDeclaredClasses() {
  const declared = new Map();
  for (const file of walk(path.join(ROOT, 'backend', 'src'), '.php')) {
    const code = fs.readFileSync(file, 'utf8');
    const ns = /^namespace\s+([\w\\]+)\s*;/m.exec(code);
    for (const m of code.matchAll(/^(?:final\s+|abstract\s+)?class\s+(\w+)/gm)) {
      if (!declared.has(m[1])) declared.set(m[1], { namespaced: Boolean(ns), file: path.relative(ROOT, file) });
    }
  }
  return declared;
}

const declaredClasses = collectDeclaredClasses();

function checkClassImports(file) {
  const code = fs.readFileSync(file, 'utf8');
  if (/^\s*namespace\s+/m.test(code)) return; // فایل‌های namespaced خودشان resolve می‌شوند
  const rel = path.relative(ROOT, file);
  const imported = new Set();
  for (const m of code.matchAll(/^use\s+([\w\\]+?)(?:\s+as\s+(\w+))?\s*;/gm)) {
    imported.add(m[2] || m[1].split('\\').pop());
  }
  const body = code.replace(/^use\s+.*$/gm, '');
  const refs = new Set();
  for (const m of body.matchAll(/\bnew\s+([A-Z]\w*)\s*\(/g)) refs.add(m[1]);
  for (const m of body.matchAll(/\bcatch\s*\(\s*([A-Z]\w*)/g)) refs.add(m[1]);
  for (const m of body.matchAll(/(?<![\w\\$:>])([A-Z]\w*)::/g)) refs.add(m[1]);
  for (const name of refs) {
    if (PHP_INTERNAL.has(name) || imported.has(name)) continue;
    const info = declaredClasses.get(name);
    if (info && info.namespaced) {
      problems.push(`${rel}: «${name}» بدون import استفاده شده (کلاس در ${info.file} داخل namespace است)؛ با «use Eroydad\\...\\${name};» واردش کنید.`);
    } else if (!info) {
      problems.push(`${rel}: کلاس «${name}» در پروژه تعریف نشده است.`);
    }
  }
}

for (const dir of [path.join(ROOT, 'backend', 'routes'), path.join(ROOT, 'frontend'), path.join(ROOT, 'deployment')]) {
  for (const file of walk(dir, '.php')) checkClassImports(file);
}

// ۴) رابط باید کاملاً محلی باشد: هیچ دارایی از بیرون بارگذاری نشود (شرح‌ها نادیده گرفته می‌شوند)
const stripComments = (text) => text
  .replace(/\/\*[\s\S]*?\*\//g, ' ')
  .replace(/<!--[\s\S]*?-->/g, ' ')
  .replace(/(^|[^:"'\\])\/\/[^\n]*/g, '$1 ');
const REMOTE_LOAD_PATTERNS = [
  /url\(\s*['"]?(https?:\/\/[^)'"]+)/gi,
  /@import\s+(?:url\()?['"]?(https?:\/\/[^)'"]+)/gi,
  /(?:src|href|action)\s*=\s*['"]?(https?:\/\/[^'"\s>]+)/gi,
  /(?:fetch|importScripts|XMLHttpRequest)\s*\([^)]*['"](https?:\/\/[^'"]+)/gi,
];

function checkLocalOnlyAssets(file) {
  const code = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  const body = stripComments(code);
  for (const re of REMOTE_LOAD_PATTERNS) {
    for (const m of body.matchAll(re)) {
      problems.push(`${rel}: دارایی بیرونی بارگذاری می‌شود (${m[1].slice(0, 90)})؛ بسته باید آفلاین و محلی باشد.`);
    }
  }
}

for (const dir of [path.join(ROOT, 'frontend', 'public'), path.join(ROOT, 'frontend', 'views')]) {
  for (const ext of ['.css', '.js', '.php', '.html']) {
    for (const file of walk(dir, ext)) checkLocalOnlyAssets(file);
  }
}

// یک ایراد می‌تواند توسط دو الگوی مختلف گزارش شود؛ تکراری‌ها را حذف می‌کنیم.
const uniqueProblems = [...new Set(problems)];

if (notes.length) {
  console.log('--- یادداشت‌ها (هشدار، نه خطا) ---');
  notes.forEach((n) => console.log('  • ' + n));
  console.log('');
}
if (uniqueProblems.length) {
  console.log('--- خطاها ---');
  uniqueProblems.forEach((p) => console.log('  ✗ ' + p));
  console.log(`\n${uniqueProblems.length} مشکل یافت شد.`);
  process.exit(1);
}
console.log('تحلیل ایستا: بدون خطا.');
