/**
 * آزمون یکپارچهٔ «runner واقعی مهاجرت»:
 *   deployment/local-xampp/migrate.php روی PHP 7.4 واقعی + MariaDB 11.8 واقعی
 *
 * سناریو: نصب کامل schema، سپس شبیه‌سازی یک نصب قدیمی (حذف چند جدول/ستون
 * مربوط به ۰۰۲ و حذف رکورد دفترچه) و اجرای خودِ runner. انتظار: runner گام‌های
 * جاافتاده را پیدا می‌کند، آن‌ها را اعمال می‌کند، دفترچه را به‌روز می‌کند و
 * اجرای دوباره بی‌خطر (no-op) است.
 *
 * اجرا:  node tests/migrate-run-test.mjs [مسیر پروژه]
 */
import { createTestEnvironment } from './support/harness.mjs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(process.argv[2] || path.join(HERE, '..'));
const failures = [];

function ok(label, condition, detail = '') {
  if (condition) {
    console.log('PASS  ' + label);
    return;
  }
  failures.push(label + (detail ? ' :: ' + detail : ''));
  console.log('FAIL  ' + label + (detail ? ' :: ' + detail : ''));
}

const DROPPED_TABLES = ['canned_responses', 'support_ticket_labels', 'support_labels', 'feedbacks', 'certificates', 'notifications'];
const DROPPED_COLUMNS = [
  ['events', 'poster_path'],
  ['events', 'tax_percent'],
  ['registrations', 'waitlist_position'],
  ['registrations', 'amount_total_toman'],
];

/**
 * فراخوانی همان مسیری که migrate.php می‌رود (بدون نگهبان خط فرمان، چون
 * SAPI در محیط آزمون «cli» نیست). خودِ فایل migrate.php نیز در آزمون نحوی
 * بررسی می‌شود و فقط یک پوستهٔ نازک روی همین کلاس است.
 */
const RUNNER_CODE = `
require '/project/backend/bootstrap.php';
require '/project/backend/src/Database/MigrationRunner.php';
$db = db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$runner = new \\Eroydad\\Database\\MigrationRunner($db, '/project/database/migrations');
$result = $runner->run(static function (string $text): void { echo $text . "\n"; });
echo "SUMMARY " . json_encode([
    'applied' => $result['applied'],
    'skipped' => $result['skipped'],
    'failures' => $result['failures'],
]) . "\n";
`;

async function runMigrations() {
  const response = await env.php.run({ code: '<?php\n' + RUNNER_CODE });
  const out = typeof response.text === 'string' ? response.text : new TextDecoder().decode(response.stdout ?? new Uint8Array());
  const err = new TextDecoder().decode(response.stderr ?? new Uint8Array());
  let summary = null;
  const match = /SUMMARY (\{.*\})/.exec(out);
  if (match) {
    try { summary = JSON.parse(match[1]); } catch (e) { summary = null; }
  }
  return { out, err, summary };
}

const env = await createTestEnvironment({ root: ROOT, withSchema: true });
let exitCode = 0;
try {
  // ---------- ۱) شبیه‌سازی نصب قدیمی ----------
  await env.engineRoot.query('SET FOREIGN_KEY_CHECKS=0');
  for (const table of DROPPED_TABLES) {
    await env.engineRoot.query(`DROP TABLE IF EXISTS \`${table}\``);
  }
  for (const [table, column] of DROPPED_COLUMNS) {
    await env.engineRoot.query(`ALTER TABLE \`${table}\` DROP COLUMN \`${column}\``);
  }
  await env.engineRoot.query("DELETE FROM schema_migrations WHERE version='002_feature_set'");
  await env.engineRoot.query('SET FOREIGN_KEY_CHECKS=1');

  const missingBefore = [];
  for (const table of DROPPED_TABLES) {
    const [rows] = await env.engineRoot.execute(
      'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=? AND table_name=?',
      [env.database, table],
    );
    if (Number(rows[0].c) > 0) missingBefore.push(table);
  }
  ok('حالت نصب قدیمی ساخته شد (جدول‌های ۰۰۲ حذف شدند)', missingBefore.length === 0, missingBefore.join(','));

  // ---------- ۲) اجرای خودِ runner ----------
  const first = await runMigrations();
  ok('runner بدون خطای PHP تمام شد', first.err.trim() === '', String(first.err).slice(0, 300));
  ok('runner مهاجرت ۰۰۲ را اعمال کرد', first.summary !== null && first.summary.applied >= 1, first.out.slice(0, 400));
  ok('پیام runner نسخهٔ ۰۰۲ را گزارش می‌کند', /002_feature_set/.test(first.out) && /اجرا شد/.test(first.out), first.out.slice(0, 300));
  ok('هیچ گامی ناموفق نبود', first.summary !== null && first.summary.failures.length === 0, JSON.stringify(first.summary));

  // ---------- ۳) بازگشت جدول‌ها و ستون‌ها ----------
  for (const table of DROPPED_TABLES) {
    const [rows] = await env.engineRoot.execute(
      'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=? AND table_name=?',
      [env.database, table],
    );
    ok('جدول «' + table + '» بازسازی شد', Number(rows[0].c) > 0);
  }
  for (const [table, column] of DROPPED_COLUMNS) {
    const [rows] = await env.engineRoot.execute(
      'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?',
      [env.database, table, column],
    );
    ok(`ستون «${table}.${column}» بازسازی شد`, Number(rows[0].c) > 0);
  }

  // ---------- ۴) دفترچهٔ مهاجرت ----------
  const [ledgerRows] = await env.engineRoot.query(
    "SELECT version, status, steps_applied, steps_skipped FROM migration_runs WHERE version='002_feature_set' ORDER BY id DESC LIMIT 1",
  );
  ok('اجرا در migration_runs ثبت شد', ledgerRows.length === 1, JSON.stringify(ledgerRows));
  if (ledgerRows.length) {
    ok('وضعیت اجرا success است', ledgerRows[0].status === 'success', String(ledgerRows[0].status));
    ok('گام‌های اعمال‌شده شمرده شدند', Number(ledgerRows[0].steps_applied) > 0, String(ledgerRows[0].steps_applied));
    ok('گام‌های تکراری ردشده شمرده شدند', Number(ledgerRows[0].steps_skipped) > 0, String(ledgerRows[0].steps_skipped));
  }
  const [marked] = await env.engineRoot.query("SELECT COUNT(*) AS c FROM schema_migrations WHERE version='002_feature_set'");
  ok('نسخه در schema_migrations علامت خورد', Number(marked[0].c) === 1);

  // ---------- ۵) اجرای دوباره باید بی‌خطر باشد ----------
  const second = await runMigrations();
  ok('اجرای دوباره بدون خطا بود', second.err.trim() === '' && second.summary !== null, String(second.err).slice(0, 200));
  ok('اجرای دوباره چیزی اعمال نکرد', /قبلاً اجرا شده/.test(second.out), second.out.slice(0, 200));
  ok('اجرای دوباره مهاجرت تازه‌ای پیدا نکرد', second.summary !== null && second.summary.applied === 0, JSON.stringify(second.summary));
  const [runsCount] = await env.engineRoot.query("SELECT COUNT(*) AS c FROM migration_runs WHERE version='002_feature_set'");
  ok('اجرای دوباره رکورد تازهٔ اجرا نساخت', Number(runsCount[0].c) === 1, String(runsCount[0].c));

  // ---------- ۶) داده‌های قبلی سالم ماندند ----------
  const [keptRoles] = await env.engineRoot.query('SELECT COUNT(*) AS c FROM roles');
  ok('دادهٔ seed (نقش‌ها) پس از مهاجرت سالم است', Number(keptRoles[0].c) >= 4, String(keptRoles[0].c));
  const [keptPerms] = await env.engineRoot.query('SELECT COUNT(*) AS c FROM permissions');
  ok('مجوزها پس از مهاجرت کامل‌اند', Number(keptPerms[0].c) >= 20, String(keptPerms[0].c));
} catch (error) {
  failures.push('اجرای آزمون با خطا متوقف شد: ' + error.message);
  console.error(error);
  exitCode = 2;
} finally {
  await env.close();
}

console.log('\n' + '='.repeat(60));
if (failures.length) {
  console.log('آزمون runner مهاجرت: ' + failures.length + ' ایراد');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
console.log('آزمون runner مهاجرت: مهاجرت افزایشی روی نصب موجود درست کار می‌کند.');
process.exit(exitCode);
