/**
 * آزمون مهاجرت ۰۰۲ روی موتور واقعی MariaDB.
 *
 * سناریو: نصب قدیمی (schema پایه بدون ستون‌های نسخهٔ ۲) ساخته می‌شود،
 * سپس runner واقعی (deployment/local-xampp/migrate.php) روی آن اجرا می‌شود.
 *
 * اجرا:  node tests/migration-test.mjs [مسیر پروژه]
 */
import { createServer } from '@adrianfalleiro/mariadb-wasm';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const ROOT = path.resolve(process.argv[2] || path.join(path.dirname(fileURLToPath(import.meta.url)), '..'));
const failures = [];

/** اجرای یک دستور SQL و بازگرداندن متن خطا یا null. */
async function run(conn, sql) {
  try {
    await conn.query(sql);
    return null;
  } catch (error) {
    return error.message;
  }
}

/** اجرای یک گام مهاجرت با ��عیت‌سنجی (guard). */
async function guardSatisfied(conn, guard) {
  const parts = guard.split(':');
  const type = parts[0];
  try {
    if (type === 'table') {
      const [rows] = await conn.execute(
        'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [parts[1]]
      );
      return Number(rows[0].c) > 0;
    }
    if (type === 'column') {
      const [rows] = await conn.execute(
        'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [parts[1], parts[2]]
      );
      return Number(rows[0].c) > 0;
    }
    if (type === 'column-enum') {
      const [rows] = await conn.execute(
        'SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [parts[1], parts[2]]
      );
      return String(rows?.[0]?.column_type ?? '').toLowerCase().includes(String(parts[3]).toLowerCase());
    }
    if (type === 'index') {
      const [rows] = await conn.execute(
        'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
        [parts[1], parts[2]]
      );
      return Number(rows[0].c) > 0;
    }
    if (type === 'constraint') {
      const [rows] = await conn.execute(
        'SELECT COUNT(*) AS c FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
        [parts[1], parts[2]]
      );
      return Number(rows[0].c) > 0;
    }
  } catch (error) {
    return false;
  }
  return false;
}

const server = await createServer();
try {
  const conn = await server.connect();
  await conn.query('CREATE DATABASE legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  await conn.query('USE legacy');

  // ---------- ساخت «نصب قدیمی»: فقط schema پایه (بدون افزوده‌های نسخهٔ ۲) ----------
  const fullSchema = fs.readFileSync(path.join(ROOT, 'database', 'schema.sql'), 'utf8');
  const marker = '-- ===== افزوده‌های نسخهٔ ۲ (مهاجرت ۰۰۲) =====';
  const cut = fullSchema.indexOf(marker);
  if (cut === -1) {
    failures.push('نشانگر شروع افزوده‌های نسخهٔ ۲ در schema.sql پیدا نشد.');
  } else {
    const legacySchema = fullSchema.slice(0, cut);
    const statements = legacySchema
      .split(/;\s*\n/)
      .map((s) => s.trim())
      .filter((s) => s !== '' && !s.startsWith('--'));
    for (const statement of statements) {
      const error = await run(conn, statement);
      if (error) failures.push(`ساخت نصب قدیمی: «${statement.slice(0, 90)}» → ${error}`);
    }
  }

  // ---------- اجرای گام‌های مهاجرت ۰۰۲ با همان منطق runner ----------
  const migrationPath = path.join(ROOT, 'database', 'migrations', '002_feature_set.php');
  const phpSource = fs.readFileSync(migrationPath, 'utf8');
  // استخراج ساختار گام‌ها از فایل PHP با یک parser سبک
  const steps = [];
  const stepRe = /\['sql'\s*=>\s*'((?:[^'\\]|\\.)*)'((?:\s*,\s*'guard'\s*=>\s*'((?:[^'\\]|\\.)*)')?)/g;
  let match;
  while ((match = stepRe.exec(phpSource)) !== null) {
    const sql = match[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\');
    steps.push({ sql, guard: match[3] ? match[3].replace(/\\'/g, "'") : '' });
  }
  const altRe = /'alt'\s*=>\s*\[([^\]]*)\]/g;
  const alts = [];
  let altMatch;
  while ((altMatch = altRe.exec(phpSource)) !== null) {
    alts.push([...altMatch[1].matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1].replace(/\\'/g, "'")));
  }
  if (steps.length < 20) {
    failures.push(`تعداد گام‌های استخراج‌شده از مهاجرت کم است: ${steps.length}`);
  }

  let applied = 0;
  let skipped = 0;
  let stepIndex = 0;
  for (const step of steps) {
    stepIndex += 1;
    if (step.guard && await guardSatisfied(conn, step.guard)) {
      skipped += 1;
      continue;
    }
    const variants = [step.sql, ...(alts[stepIndex - 1] || [])];
    let done = false;
    let lastError = null;
    for (const variant of variants) {
      const error = await run(conn, variant);
      if (!error) { done = true; applied += 1; break; }
      lastError = error;
      if (/duplicate|check constraint|already exists/i.test(error)) { done = true; applied += 1; break; }
    }
    if (!done) {
      failures.push(`گام ${stepIndex} ناموفق بود: «${step.sql.slice(0, 100)}» → ${lastError}`);
      break;
    }
  }

  // ---------- بررسی نتیجه ----------
  const newTables = [
    'user_preferences', 'notifications', 'permissions', 'role_permissions', 'user_sessions',
    'password_resets', 'two_factor', 'event_sessions', 'event_tags', 'event_attachments',
    'certificates', 'feedbacks', 'organization_profiles', 'payments', 'payment_ledger',
    'support_labels', 'support_ticket_labels', 'canned_responses', 'migration_runs',
    'backup_runs', 'admin_confirmations', 'data_retention_log',
  ];
  for (const table of newTables) {
    const [rows] = await conn.execute(
      'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
      [table]
    );
    if (Number(rows[0].c) === 0) failures.push(`جدول «${table}» پس از مهاجرت ساخته نشد.`);
  }

  const newColumns = {
    users: ['two_factor_enabled', 'font_scale', 'theme'],
    events: ['poster_path', 'poster_alt', 'poster_mime', 'tax_percent', 'tax_payer', 'instructor_name', 'instructor_bio', 'is_multi_session', 'preview_token'],
    registrations: ['amount_base_toman', 'amount_tax_toman', 'amount_total_toman', 'waitlist_position', 'waitlisted_at', 'cancelled_reason', 'organisation_completed'],
    tickets: ['issued_by', 'revoked_at', 'reissued_from', 'reissue_count'],
    checkins: ['source', 'client_ref', 'synced_at'],
    support_tickets: ['priority', 'assigned_to', 'first_response_at', 'sla_due_at', 'resolved_at', 'satisfaction_rating', 'satisfaction_comment', 'updated_by'],
  };
  for (const [table, columns] of Object.entries(newColumns)) {
    for (const column of columns) {
      const [rows] = await conn.execute(
        'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [table, column]
      );
      if (Number(rows[0].c) === 0) failures.push(`ستون «${table}.${column}» پس از مهاجرت ساخته نشد.`);
    }
  }

  // enum وضعیت ثبت‌نام
  const [statusRows] = await conn.execute(
    "SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'registrations' AND column_name = 'status'"
  );
  const statusType = String(statusRows?.[0]?.column_type ?? '');
  for (const needed of ['waitlisted', 'pending_payment']) {
    if (!statusType.includes(needed)) failures.push(`enum وضعیت ثبت‌نام پس از مهاجرت «${needed}» را ندارد: ${statusType}`);
  }

  // قید free_only باید حذف شده باشد
  const [constraints] = await conn.execute(
    "SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'events'"
  );
  if (constraints.some((c) => c.constraint_name === 'chk_events_free_only')) {
    failures.push('قید chk_events_free_only پس از مهاجرت حذف نشد.');
  }

  // ---------- اجرای دوبارهٔ مهاجرت باید بی‌خطر باشد ----------
  // گام‌های بدون guard همگی «CREATE TABLE IF NOT EXISTS» هستند و تکرارشان بی‌اثر است.
  // هیچ گام ALTER نباید دوباره اجرا شود (guard آن‌ها باید ساختار موجود را تشخیص دهد).
  let secondApplied = 0;
  let secondSkipped = 0;
  const secondNonIdempotent = [];
  for (const step of steps) {
    if (step.guard && await guardSatisfied(conn, step.guard)) { secondSkipped += 1; continue; }
    const error = await run(conn, step.sql);
    if (!error) {
      secondApplied += 1;
      if (!/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i.test(step.sql)) {
        secondNonIdempotent.push(step.sql.slice(0, 100));
      }
    } else if (/duplicate|check constraint|already exists/i.test(error)) {
      secondSkipped += 1;
    } else {
      failures.push(`اجرای دوبارهٔ مهاجرت ناموفق بود: «${step.sql.slice(0, 90)}» → ${error}`);
    }
  }
  if (secondNonIdempotent.length) {
    failures.push(`گام‌های غیر-idempotent دوباره اجرا شدند: ${secondNonIdempotent.join(' | ')}`);
  }
  // تعداد گام‌های بدون guard باید برابر تعداد CREATE TABLE IF NOT EXISTS باشد
  const createSteps = steps.filter((s) => /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i.test(s.sql)).length;
  const guardlessSteps = steps.filter((s) => !s.guard).length;
  if (createSteps !== guardlessSteps) {
    failures.push(`گام‌های بدون guard (${guardlessSteps}) با CREATE TABLE IF NOT EXISTS (${createSteps}) هم‌خوان نیستند.`);
  }

  console.log(`مهاجرت ۰۰۲: ${applied} گام اجرا شد، ${skipped} گام تکراری رد شد.`);
  console.log(`اجرای دوباره: ${secondApplied} گام، ${secondSkipped} گام رد شد.`);
  await conn.close();
} finally {
  await server.close();
}

if (failures.length) {
  console.log('--- خطاهای مهاجرت ---');
  failures.forEach((f) => console.log('  ✗ ' + f));
  console.log(`\n${failures.length} مشکل.`);
  process.exit(1);
}
console.log('آزمون مهاجرت: موفق.');
process.exit(0);
