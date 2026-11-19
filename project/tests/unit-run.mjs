/**
 * اجرای آزمون‌های واحد (tests/Unit/*.php) روی PHP 7.4 واقعی.
 *
 * هر فایل PHP خودش یک‌جا «PASS/FAIL» چاپ می‌کند؛ این runner همه را یکی‌یکی
 * اجرا و جمع می‌زند. دیتابیس لازم نیست (فقط bootstrap + .env آزمایشی harness).
 *
 * اجرا:  node tests/unit-run.mjs [مسیر پروژه]
 */
import { createTestEnvironment } from './support/harness.mjs';
import { readdirSync, readFileSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));
const UNIT_DIR = path.join(HERE, 'Unit');

const files = readdirSync(UNIT_DIR).filter((f) => f.endsWith('.php')).sort();
if (files.length === 0) {
  console.error('هیچ فایل آزمون واحدی در tests/Unit پیدا نشد.');
  process.exit(1);
}

const env = await createTestEnvironment({ root: ROOT, withSchema: false, baseUrl: 'http://localhost/' });

let totalPass = 0;
let totalFail = 0;
const failedFiles = [];

for (const file of files) {
  const code = readFileSync(path.join(UNIT_DIR, file), 'utf8');
  let stdout = '';
  let stderr = '';
  try {
    const result = await env.php.run({ code });
    stdout = typeof result.text === 'string' ? result.text : new TextDecoder().decode(result.stdout ?? new Uint8Array());
    stderr = typeof result.stderr === 'string' ? result.stderr : new TextDecoder().decode(result.stderr ?? new Uint8Array());
  } catch (error) {
    stdout = '';
    stderr = String(error && error.message ? error.message : error);
  }
  const failLines = (stdout.match(/^FAIL .*/gm) || []);
  const done = /^UNIT_DONE total=(\d+) fail=(\d+)/m.exec(stdout);
  const passLines = (stdout.match(/^PASS /gm) || []).length;
  const pass = done ? Math.max(0, Number(done[1]) - Number(done[2])) : passLines;
  const fail = done ? Number(done[2]) : failLines.length;
  totalPass += pass;
  totalFail += fail;
  console.log(`--- ${file}: ${pass} رد شد${fail ? '، ' + fail + ' ناموفق' : ''}${done ? '' : ' (UNIT_DONE ندیدم!)'}`);
  failLines.forEach((l) => console.log('  ' + l));
  if (!done || fail > 0 || stderr.trim() !== '') {
    if (stderr.trim() !== '') console.log('  stderr: ' + stderr.trim().slice(0, 400));
    failedFiles.push(file);
  }
}

console.log(`نتیجه: ${files.length} فایل، ${totalPass} موفق، ${totalFail} ناموفق`);
if (totalFail > 0 || failedFiles.length > 0) {
  console.log('STATUS: FAILED — ' + failedFiles.join(', '));
  process.exit(1);
}
console.log('آزمون واحد: همهٔ منطق خالص بدون خطا.');
console.log('STATUS: PASSED');
process.exit(0);
