/**
 * اعتبارسنجی نحوی واقعی با PHP 7.4 (اجرا در WASM).
 *
 * از آن‌جا که PHP در محیط ساخت موجود نیست، از موتور @php-wasm/node نسخهٔ ۷٫۴
 * استفاده می‌شود. این موتور همان parser نسخهٔ ۷٫۴ را دارد؛ بنابراین خطاهای
 * نحوی دقیقاً همان چیزی است که روی XAMPP/PHP 7.4 رخ می‌دهد.
 *
 * اجرا:  node tests/php-lint-wasm.mjs [مسیر پروژه]
 */
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));
const php = new PHP(await loadNodeRuntime('7.4', { emscriptenOptions: { processId: 1 } }));

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === '.git' || entry.name === 'node_modules' || entry.name === 'storage') continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (entry.name.endsWith('.php')) out.push(full);
  }
  return out;
}

const files = walk(ROOT).sort();
const failures = [];
const BATCH = 12;

for (let i = 0; i < files.length; i += BATCH) {
  const batch = files.slice(i, i + BATCH);
  const payload = batch.map((f) => ({
    name: path.relative(ROOT, f),
    code: fs.readFileSync(f, 'utf8'),
  }));
  const script = `<?php
$items = json_decode(base64_decode('${Buffer.from(JSON.stringify(payload)).toString('base64')}'), true);
$out = [];
foreach ($items as $item) {
    $src = $item['code'];
    try {
        token_get_all($src, TOKEN_PARSE);
        $out[] = ['n' => $item['name'], 'e' => null];
    } catch (\\ParseError $err) {
        $out[] = ['n' => $item['name'], 'e' => $err->getMessage()];
    } catch (\\Throwable $err) {
        $out[] = ['n' => $item['name'], 'e' => get_class($err) . ': ' . $err->getMessage()];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
`;
  const response = await php.runStream({ code: script });
  const text = await response.stdoutText;
  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch (e) {
    console.error('پاسخ ناشناخته از موتور PHP:', text.slice(0, 500));
    process.exit(2);
  }
  for (const item of parsed) {
    if (item.e) failures.push(`${item.n}: ${item.e}`);
  }
}

if (failures.length) {
  console.log('--- خطاهای نحوی PHP 7.4 ---');
  failures.forEach((f) => console.log('  ✗ ' + f));
  console.log(`\n${failures.length} فایل نامعتبر از ${files.length} فایل.`);
  process.exit(1);
}
console.log(`بررسی نحوی PHP 7.4: ${files.length} فایل، همه معتبر.`);
process.exit(0);
