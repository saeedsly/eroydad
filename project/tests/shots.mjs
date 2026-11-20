import { createRequire } from 'module';
import path from 'path';
import { existsSync, mkdirSync } from 'fs';
const require = createRequire(import.meta.url);
const chromium = require('@sparticuz/chromium');
const lambdafs = require('@sparticuz/chromium/build/lambdafs.js');
const helper = require('@sparticuz/chromium/build/helper.js');
const binDir = path.join(path.dirname(require.resolve('@sparticuz/chromium/package.json')), 'bin');
const inflate = lambdafs.default ?? lambdafs;
if (!existsSync('/tmp/al2023/lib')) await inflate.inflate(path.join(binDir, 'al2023.tar.br'));
if (!existsSync('/tmp/fonts')) await inflate.inflate(path.join(binDir, 'fonts.tar.br'));
helper.setupLambdaEnvironment('/tmp/al2023/lib');
const puppeteer = require('puppeteer-core');

// usage: node shots.mjs <urlPath> <outPng> <width> <height> [dark=1] [scroll=0.55]
const [, , urlPath = '/', out, w = '1320', h = '900', dark = '0', scroll = '0'] = process.argv;
if (!out) { console.error('out required'); process.exit(1); }
const browser = await puppeteer.launch({ executablePath: await chromium.executablePath(), args: chromium.args, headless: true });
const page = await browser.newPage();
await page.setViewport({ width: Number(w), height: Number(h), deviceScaleFactor: 1 });
await page.goto('http://127.0.0.1:8642' + urlPath, { waitUntil: 'networkidle2' });
if (dark === '1') {
  await page.evaluate(() => { localStorage.setItem('erooydad-theme', 'dark'); });
  await page.reload({ waitUntil: 'networkidle2' });
}
if (Number(scroll) > 0) {
  await page.evaluate((f) => window.scrollTo(0, document.body.scrollHeight * f), Number(scroll));
}
await new Promise((r) => setTimeout(r, 900));
mkdirSync(path.dirname(out), { recursive: true });
await page.screenshot({ path: out });
await browser.close();
console.log('shot', out);
