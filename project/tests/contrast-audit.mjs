import { createRequire } from 'module';
import path from 'path'; import { existsSync } from 'fs';
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

const audit = async (page, url, theme) => page.evaluate((url, theme) => {
  const lum = (c) => {
    const [r, g, b] = c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const parse = (s) => { const m = s.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map(Number); if (p.length > 3 && p[3] === 0) return null; return [p[0], p[1], p[2]]; };
  const bgOf = (el) => {
    let n = el;
    while (n && n !== document.documentElement) {
      const cs2 = getComputedStyle(n);
      if (cs2.backgroundImage !== 'none') return 'gradient'; // گرادیان اجدادی → خارج از دامنه
      const c = parse(cs2.backgroundColor);
      if (c) return c;
      n = n.parentElement;
    }
    return theme === 'dark' ? [16, 16, 20] : [250, 250, 247];
  };
  const out = [];
  const els = [...document.querySelectorAll('p, span, a, b, small, h1, h2, h3, label, li, td, th, summary, button, div')];
  for (const el of els) {
    if (!el.textContent.trim() || el.children.length > 3) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || Number(cs.opacity) < 0.5) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 4 || r.height < 4) continue;
    if (cs.backgroundImage !== 'none') continue; // گرادیان → خارج از دامنهٔ رنگ جامد
    const deco = (el.getAttribute('aria-hidden') === 'true') || /^(✦|✳|◎|⌂|▲|●|✓|×|←|↗|‹|›|[\u2500-\u257F])$/.test(el.textContent.trim());
    if (deco) continue; // نشانه‌های تزئینی
    const fg = parse(cs.color); if (!fg) continue;
    const bg = bgOf(el);
    if (bg === 'gradient') continue;
    if (cs.backgroundImage !== 'none') continue;
    const L1 = lum(fg), L2 = lum(bg);
    const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const size = parseFloat(cs.fontSize);
    const weight = Number(cs.fontWeight) || 400;
    const large = size >= 24 || (size >= 18.66 && weight >= 700);
    const need = large ? 3 : 4.5;
    if (ratio < need) out.push({ sel: el.tagName + '.' + (el.className || '').toString().split(' ')[0], text: el.textContent.trim().slice(0, 30), ratio: Math.round(ratio * 100) / 100, need, size, color: cs.color });
  }
  // dedupe by sel+ratio
  const seen = new Set();
  return out.filter((o) => { const k = o.sel + o.ratio; if (seen.has(k)) return false; seen.add(k); return true; });
}, url, theme);

const browser = await puppeteer.launch({ executablePath: await chromium.executablePath(), args: chromium.args, headless: true });
const page = await browser.newPage();
await page.setViewport({ width: 1440, height: 900 });
let total = 0;
for (const [url, theme] of [['/', 'light'], ['/', 'dark'], ['/events', 'light'], ['/login', 'light'], ['/support', 'light'], ['/events', 'dark']]) {
  await page.evaluateOnNewDocument((t) => { try { localStorage.setItem('erooydad-theme', t); } catch (e) {} }, theme);
  await page.goto('http://127.0.0.1:8642' + url, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 400));
  const fails = await audit(page, url, theme);
  total += fails.length;
  console.log(`${fails.length === 0 ? 'PASS' : 'FAIL'} ${url} [${theme}] — ${fails.length} نقض`);
  fails.slice(0, 8).forEach((f) => console.log(`   · ${f.ratio} < ${f.need} | ${f.size}px | ${f.color} | ${f.sel} | «${f.text}»`));
}
await browser.close();
console.log(total === 0 ? 'CONTRAST AUDIT: همه سبز' : `CONTRAST AUDIT: ${total} مورد`);
process.exit(total === 0 ? 0 : 1);
