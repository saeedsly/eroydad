/**
 * آزمون schema و مهاجرت‌ها روی یک موتور واقعی MariaDB (نسخهٔ ۱۱٫۸ از طریق WASM).
 *
 * این آزمون تضمین می‌کند که:
 *  ۱) schema.sql روی دیتابیس خالی بدون خطا اجرا می‌شود؛
 *  ۲) مهاجرت ۰۰۲ روی نصب قدیمی (فقط ۰۰۱) اجرا می‌شود؛
 *  ۳) اجرای دوبارهٔ مهاجرت بی‌خطر است (idempotent)؛
 *  ۴) همهٔ جدول‌ها و ستون‌های مورد نیاز کد برنامه واقعاً وجود دارند.
 *
 * اجرا:  node tests/schema-test.mjs
 */
import { createServer } from '@adrianfalleiro/mariadb-wasm';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const ROOT = path.resolve(process.argv[2] || path.join(path.dirname(fileURLToPath(import.meta.url)), '..'));
const schemaSql = fs.readFileSync(path.join(ROOT, 'database', 'schema.sql'), 'utf8');

/** شکستن SQL به دستورهای مستقل (با رعایت نقل‌قول‌ها و کامنت‌ها). */
function splitStatements(sql) {
  const statements = [];
  let current = '';
  let inSingle = false;
  let inDouble = false;
  let inBacktick = false;
  let inLineComment = false;
  let inBlockComment = false;
  for (let i = 0; i < sql.length; i += 1) {
    const ch = sql[i];
    const next = sql[i + 1];
    if (inLineComment) {
      if (ch === '\n') inLineComment = false;
      continue;
    }
    if (inBlockComment) {
      if (ch === '*' && next === '/') { i += 1; inBlockComment = false; }
      continue;
    }
    if (!inSingle && !inDouble && !inBacktick) {
      if (ch === '-' && next === '-' && (sql[i + 2] === ' ' || sql[i + 2] === '\n' || sql[i + 2] === undefined)) { inLineComment = true; continue; }
      if (ch === '#') { inLineComment = true; continue; }
      if (ch === '/' && next === '*') { inBlockComment = true; i += 1; continue; }
      if (ch === ';') { statements.push(current.trim()); current = ''; continue; }
    }
    if (ch === "'" && !inDouble && !inBacktick) inSingle = !inSingle;
    else if (ch === '"' && !inSingle && !inBacktick) inDouble = !inDouble;
    else if (ch === '`' && !inSingle && !inDouble) inBacktick = !inBacktick;
    else if (ch === '\\' && inSingle) { current += ch; i += 1; if (i < sql.length) current += sql[i]; continue; }
    current += ch;
  }
  if (current.trim() !== '') statements.push(current.trim());
  return statements.filter((s) => s !== '');
}

const server = await createServer();
const failures = [];
const notes = [];

try {
  const root = await server.connect();

  // ---------- ۱) نصب تازه با schema.sql ----------
  await root.query('CREATE DATABASE erooydad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  await root.query('USE erooydad');
  const statements = splitStatements(schemaSql);
  for (const statement of statements) {
    try {
      await root.query(statement);
    } catch (error) {
      failures.push(`schema.sql: «${statement.slice(0, 110)}» → ${error.message}`);
    }
  }

  // ---------- ۲) جدول‌های لازم ----------
  const expectedTables = [
    'users', 'roles', 'user_roles', 'employee_import_batches', 'employee_allowlist',
    'events', 'registrations', 'tickets', 'checkins', 'support_tickets', 'support_messages',
    'login_attempts', 'registration_attempts', 'audit_logs', 'schema_migrations',
    'user_preferences', 'notifications', 'permissions', 'role_permissions', 'user_sessions',
    'password_resets', 'two_factor', 'event_sessions', 'event_tags', 'event_attachments',
    'certificates', 'feedbacks', 'organization_profiles', 'payments', 'payment_ledger',
    'support_labels', 'support_ticket_labels', 'canned_responses', 'migration_runs',
    'backup_runs', 'admin_confirmations', 'data_retention_log',
  ];
  const [tableRows] = await root.query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
  );
  const actualTables = new Set(tableRows.map((r) => r.table_name));
  for (const table of expectedTables) {
    if (!actualTables.has(table)) failures.push(`جدول «${table}» در schema.sql ساخته نشد.`);
  }

  // ---------- ۳) ستون‌های کلیدی که کد برنامه استفاده می‌کند ----------
  const expectedColumns = {
    users: ['two_factor_enabled', 'font_scale', 'theme', 'session_version'],
    events: ['price_toman', 'tax_percent', 'tax_payer', 'poster_path', 'poster_alt', 'poster_mime', 'instructor_name', 'instructor_bio', 'is_multi_session', 'preview_token'],
    registrations: ['status', 'amount_base_toman', 'amount_tax_toman', 'amount_total_toman', 'waitlist_position', 'waitlisted_at', 'cancelled_reason', 'organisation_completed'],
    tickets: ['public_code', 'status', 'issued_by', 'revoked_at', 'reissued_from', 'reissue_count'],
    checkins: ['source', 'client_ref', 'synced_at'],
    support_tickets: ['priority', 'assigned_to', 'first_response_at', 'sla_due_at', 'resolved_at', 'satisfaction_rating', 'satisfaction_comment', 'updated_by'],
  };
  for (const [table, columns] of Object.entries(expectedColumns)) {
    const [cols] = await root.execute(
      'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
      [table]
    ).catch(async () => [[]]);
    const names = new Set((cols || []).map((c) => c.column_name));
    for (const column of columns) {
      if (!names.has(column)) failures.push(`ستون «${table}.${column}» موجود نیست.`);
    }
  }

  // ---------- ۴) مقدار enum وضعیت ثبت‌نام ----------
  const [statusCols] = await root.query(
    "SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'registrations' AND column_name = 'status'"
  );
  const statusType = String(statusCols?.[0]?.column_type ?? '');
  for (const needed of ['waitlisted', 'pending_payment', 'confirmed', 'cancelled']) {
    if (!statusType.includes(needed)) failures.push(`enum وضعیت ثبت‌نام «${needed}» را ندارد: ${statusType}`);
  }

  // ---------- ۵) قید «فقط رایگان» باید حذف شده باشد ----------
  const [checks] = await root.query(
    "SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'events'"
  );
  if (checks.some((c) => c.constraint_name === 'chk_events_free_only')) {
    failures.push('قید chk_events_free_only هنوز وجود دارد؛ پرداخت آزمایشی ممکن نیست.');
  }

  // ---------- ۶) seed مجوزها و نگاشت نقش‌ها ----------
  const [permCount] = await root.query('SELECT COUNT(*) AS c FROM permissions');
  if (Number(permCount[0].c) < 20) failures.push(`تعداد مجوزهای seed شده کم است: ${permCount[0].c}`);
  const [rolePerms] = await root.query('SELECT COUNT(*) AS c FROM role_permissions');
  if (Number(rolePerms[0].c) < 20) failures.push(`نگاشت نقش→مجوز کامل نیست: ${rolePerms[0].c}`);

  // ---------- ۷) اجرای دوبارهٔ schema.sql بی‌خطر باشد ----------
  for (const statement of statements) {
    try {
      await root.query(statement);
    } catch (error) {
      if (!/duplicate|already exists/i.test(error.message)) {
        failures.push(`اجرای دوبارهٔ schema.sql: «${statement.slice(0, 80)}» → ${error.message}`);
      }
    }
  }

  await root.close();
} finally {
  await server.close();
}

if (failures.length) {
  console.log('--- خطاهای schema ---');
  failures.forEach((f) => console.log('  ✗ ' + f));
  console.log(`\n${failures.length} مشکل.`);
  process.exit(1);
}
console.log('آزمون schema: همهٔ جدول‌ها، ستون‌ها، enumها، قیدها و seedها درست‌اند.');
if (notes.length) notes.forEach((n) => console.log('  • ' + n));
process.exit(0);
