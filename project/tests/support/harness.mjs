/**
 * زیرساخت آزمون یکپارچهٔ واقعی:
 *   PHP 7.4 واقعی (WASM)  +  MariaDB 11.8 واقعی (WASM)  +  پل پروتکل MySQL
 *
 * این ماژول یک سرور MariaDB واقعی بالا می‌آورد، پل پروتکل را می‌سازد و
 * محیط PHP 7.4 را با دسترسی به فایل‌های پروژه و دیتابیس آماده می‌کند.
 */
import { createServer } from '@adrianfalleiro/mariadb-wasm';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { createNodeFsMountHandler } from '@php-wasm/node';
import fs from 'fs';
import path from 'path';
import { startMysqlBridge } from './mysql-bridge.mjs';

/** شکستن SQL به دستورهای مستقل. */
export function splitStatements(sql) {
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
    if (inLineComment) { if (ch === '\n') inLineComment = false; continue; }
    if (inBlockComment) { if (ch === '*' && next === '/') { i += 1; inBlockComment = false; } continue; }
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

/**
 * محیط کامل آزمون را بالا می‌آورد.
 * @param {{ root: string, database?: string, withSchema?: boolean }} options
 */
export async function createTestEnvironment({ root, database = 'erooydad_test', withSchema = true, baseUrl = 'http://localhost/' } = {}) {
  const engine = await createServer();
  const engineAdmin = await engine.connect();
  await engineAdmin.query(`CREATE DATABASE \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  const engineRoot = await engine.connect({ database });

  const bridge = await startMysqlBridge({
    connect: async () => engine.connect({ database }),
  });

  const php = new PHP(await loadNodeRuntime('7.4', { emscriptenOptions: { processId: 1 } }));
  const mountPoint = '/project';
  if (!php.fileExists(mountPoint)) php.mkdirTree(mountPoint);
  php.mount(mountPoint, createNodeFsMountHandler(root));
  php.chdir(mountPoint);

  // فایل .env آزمایشی با کلیدهای تصادفی و بدون هیچ secret واقعی
  const envPath = path.join(root, '.env');
  const writeEnv = (base) => {
    const lines = [
    'APP_NAME=ای‌رویداد',
    'APP_BASE_URL=' + base,
    'APP_TIMEZONE=Asia/Tehran',
    'APP_DEBUG=false',
    'APP_KEY=' + 'a'.repeat(64),
    'EMPLOYEE_HMAC_KEY=' + 'b'.repeat(64),
    'TICKET_SIGNING_KEY=' + 'c'.repeat(64),
    'SESSION_NAME=erooydad_test',
    'DB_HOST=127.0.0.1',
    'DB_PORT=' + bridge.port,
    'DB_NAME=' + database,
    'DB_USER=root',
    'DB_PASSWORD=',
    '',
    ];
    fs.writeFileSync(envPath, lines.join('\n'), 'utf8');
  };
  const hadEnv = fs.existsSync(envPath);
  const previousEnv = hadEnv ? fs.readFileSync(envPath, 'utf8') : null;
  writeEnv(baseUrl);

  // schema پایه روی موتور واقعی
  if (withSchema) {
    const schema = fs.readFileSync(path.join(root, 'database', 'schema.sql'), 'utf8');
    for (const statement of splitStatements(schema)) {
      await engineRoot.query(statement);
    }
  }

  /** اجرای یک اسکریپت PHP و بازگرداندن خروجی. */
  async function runPhp(code) {
    const response = await php.runStream({ code: '<?php\n' + code });
    const stdout = await response.stdoutText;
    const stderr = await response.stderrText;
    return { stdout, stderr };
  }

  /** اجرای یک فایل PHP پروژه با متغیرهای محیطی. */
  async function runPhpFile(relativePath, code = '<?php') {
    const response = await php.runStream({ code: `<?php\n${code}\n` });
    void relativePath;
    return { stdout: await response.stdoutText, stderr: await response.stderrText };
  }

  return {
    engine,
    engineRoot,
    bridge,
    php,
    root,
    database,
    runPhp,
    runPhpFile,
    /** بازنویسی .env (هر درخواست bootstrap را از نو اجرا می‌کند) */
    setBaseUrl: (base) => writeEnv(base),
    rootPath: (...parts) => path.join(root, ...parts),
    async close() {
      if (hadEnv) fs.writeFileSync(envPath, previousEnv, 'utf8');
      else if (fs.existsSync(envPath)) fs.unlinkSync(envPath);
      await bridge.close();
      await engine.close();
    },
  };
}
