/**
 * اجراکنندهٔ آزمون یکپارچهٔ PHP روی PHP 7.4 + MariaDB 11.8 واقعی (WASM).
 *
 * اجرا:  node tests/integration-run.mjs [مسیر پروژه]
 */
import { createTestEnvironment } from './support/harness.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const ROOT = path.resolve(process.argv[2] || path.join(path.dirname(fileURLToPath(import.meta.url)), '..'));
const scriptPath = path.join(ROOT, 'tests', 'integration', 'app-flows.php');
const script = fs.readFileSync(scriptPath, 'utf8');

const env = await createTestEnvironment({ root: ROOT });
let exitCode = 0;
try {
  const response = await env.php.runStream({ code: script });
  const stdout = await response.stdoutText;
  const stderr = await response.stderrText;
  process.stdout.write(stdout);
  if (stderr && stderr.trim()) {
    process.stdout.write('\n--- STDERR ---\n' + stderr.slice(0, 4000) + '\n');
  }
  if (/STATUS: PASSED/.test(stdout)) exitCode = 0;
  else exitCode = 1;
} catch (error) {
  console.error('اجرای آزمون یکپارچه با خطا متوقف شد:', error.message);
  exitCode = 2;
} finally {
  await env.close();
}
process.exit(exitCode);
