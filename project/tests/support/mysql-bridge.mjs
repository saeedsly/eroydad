/**
 * پل پروتکل MySQL → موتور MariaDB درون‌برنامه‌ای.
 *
 * این پل یک سرور TCP است که پروتکل MySQL/MariaDB (نسخهٔ ۴٫۱) را پیاده‌سازی
 * می‌کند و هر دستور را به یک نمونهٔ واقعی MariaDB 11.8 (اجرا در WASM) می‌فرستد.
 * نتیجه: PHP واقعی (نسخهٔ ۷٫۴) از طریق PDO/MySQL با پایگاه دادهٔ واقعی کار می‌کند.
 */

import net from 'net';
import crypto from 'crypto';

const CLIENT_LONG_PASSWORD = 0x00000001;
const CLIENT_FOUND_ROWS = 0x00000002;
const CLIENT_LONG_FLAG = 0x00000004;
const CLIENT_CONNECT_WITH_DB = 0x00000008;
const CLIENT_LOCAL_FILES = 0x00000080;
const CLIENT_IGNORE_SPACE = 0x00000100;
const CLIENT_PROTOCOL_41 = 0x00000200;
const CLIENT_INTERACTIVE = 0x00000400;
const CLIENT_TRANSACTIONS = 0x00002000;
const CLIENT_SECURE_CONNECTION = 0x00008000;
const CLIENT_MULTI_STATEMENTS = 0x00010000;
const CLIENT_MULTI_RESULTS = 0x00020000;
const CLIENT_PS_MULTI_RESULTS = 0x00040000;
const CLIENT_PLUGIN_AUTH = 0x00080000;
const CLIENT_CONNECT_ATTRS = 0x00100000;
const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
const CLIENT_SESSION_TRACK = 0x00800000;
const CLIENT_DEPRECATE_EOF = 0x01000000;

const SERVER_CAPABILITIES =
  CLIENT_LONG_PASSWORD | CLIENT_FOUND_ROWS | CLIENT_LONG_FLAG | CLIENT_CONNECT_WITH_DB |
  CLIENT_LOCAL_FILES | CLIENT_IGNORE_SPACE | CLIENT_PROTOCOL_41 | CLIENT_INTERACTIVE |
  CLIENT_TRANSACTIONS | CLIENT_SECURE_CONNECTION | CLIENT_MULTI_STATEMENTS |
  CLIENT_MULTI_RESULTS | CLIENT_PS_MULTI_RESULTS | CLIENT_PLUGIN_AUTH |
  CLIENT_CONNECT_ATTRS | CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA | CLIENT_SESSION_TRACK;

const COM_QUIT = 0x01;
const COM_INIT_DB = 0x02;
const COM_QUERY = 0x03;
const COM_FIELD_LIST = 0x04;
const COM_PING = 0x0e;
const COM_STMT_PREPARE = 0x16;
const COM_STMT_EXECUTE = 0x17;
const COM_STMT_CLOSE = 0x19;
const COM_STMT_RESET = 0x1a;

const TYPE_DECIMAL = 0x00;
const TYPE_TINY = 0x01;
const TYPE_SHORT = 0x02;
const TYPE_LONG = 0x03;
const TYPE_FLOAT = 0x04;
const TYPE_DOUBLE = 0x05;
const TYPE_NULL = 0x06;
const TYPE_TIMESTAMP = 0x07;
const TYPE_LONGLONG = 0x08;
const TYPE_INT24 = 0x09;
const TYPE_DATE = 0x0a;
const TYPE_TIME = 0x0b;
const TYPE_DATETIME = 0x0c;
const TYPE_YEAR = 0x0d;
const TYPE_VARCHAR = 0x0f;
const TYPE_BIT = 0x10;
const TYPE_JSON = 0xf5;
const TYPE_NEWDECIMAL = 0xf6;
const TYPE_ENUM = 0xf7;
const TYPE_SET = 0xf8;
const TYPE_TINY_BLOB = 0xf9;
const TYPE_MEDIUM_BLOB = 0xfa;
const TYPE_LONG_BLOB = 0xfb;
const TYPE_BLOB = 0xfc;
const TYPE_VAR_STRING = 0xfd;
const TYPE_STRING = 0xfe;
const TYPE_GEOMETRY = 0xff;

const CHARSET_UTF8MB4 = 45;
const CHARSET_BINARY = 63;

const FIELD_TYPE_MAP = {
  0: TYPE_DECIMAL, 1: TYPE_TINY, 2: TYPE_SHORT, 3: TYPE_LONG, 4: TYPE_FLOAT,
  5: TYPE_DOUBLE, 6: TYPE_NULL, 7: TYPE_TIMESTAMP, 8: TYPE_LONGLONG, 9: TYPE_INT24,
  10: TYPE_DATE, 11: TYPE_TIME, 12: TYPE_DATETIME, 13: TYPE_YEAR, 15: TYPE_VARCHAR,
  16: TYPE_BIT, 245: TYPE_JSON, 246: TYPE_NEWDECIMAL, 247: TYPE_ENUM, 248: TYPE_SET,
  249: TYPE_TINY_BLOB, 250: TYPE_MEDIUM_BLOB, 251: TYPE_LONG_BLOB, 252: TYPE_BLOB,
  253: TYPE_VAR_STRING, 254: TYPE_STRING, 255: TYPE_GEOMETRY,
};

class Writer {
  constructor() { this.chunks = []; }
  /** رشته‌ها همیشه با UTF-8 نوشته می‌شوند تا طول بایتی و محتوای ارسالی یکی بماند. */
  bytes(buf) { this.chunks.push(Buffer.isBuffer(buf) ? buf : Buffer.from(String(buf), 'utf8')); return this; }
  u8(v) { const b = Buffer.alloc(1); b.writeUInt8(v & 0xff, 0); return this.bytes(b); }
  u16(v) { const b = Buffer.alloc(2); b.writeUInt16LE(v & 0xffff, 0); return this.bytes(b); }
  u24(v) { const b = Buffer.alloc(3); b.writeUIntLE(v, 0, 3); return this.bytes(b); }
  u32(v) { const b = Buffer.alloc(4); b.writeUInt32LE(v >>> 0, 0); return this.bytes(b); }
  u64(v) { const b = Buffer.alloc(8); b.writeBigUInt64LE(BigInt(v), 0); return this.bytes(b); }
  nullTerminated(str) { return this.bytes(Buffer.from(String(str), 'utf8')).u8(0); }
  lenEncInt(v) {
    v = Number(v);
    if (v < 251) return this.u8(v);
    if (v < 0x10000) return this.u8(0xfc).u16(v);
    if (v < 0x1000000) return this.u8(0xfd).u24(v);
    return this.u8(0xfe).u64(BigInt(v));
  }
  lenEncBytes(buf) {
    const b = Buffer.isBuffer(buf) ? buf : Buffer.from(String(buf), 'utf8');
    return this.lenEncInt(b.length).bytes(b);
  }
  toBuffer() { return Buffer.concat(this.chunks); }
}

class Reader {
  constructor(buffer) { this.buf = buffer; this.pos = 0; }
  u8() { return this.buf.readUInt8(this.pos++); }
  u16() { const v = this.buf.readUInt16LE(this.pos); this.pos += 2; return v; }
  u24() { const v = this.buf.readUIntLE(this.pos, 3); this.pos += 3; return v; }
  u32() { const v = this.buf.readUInt32LE(this.pos); this.pos += 4; return v; }
  u64() { const v = this.buf.readBigUInt64LE(this.pos); this.pos += 8; return v; }
  bytes(n) { const v = this.buf.subarray(this.pos, this.pos + n); this.pos += n; return v; }
  skip(n) { this.pos += n; return this; }
  lenEncInt() {
    const first = this.u8();
    if (first < 251) return first;
    if (first === 0xfc) return this.u16();
    if (first === 0xfd) return this.u24();
    if (first === 0xfe) return Number(this.u64());
    return 0;
  }
  lenEncBytes() { const n = this.lenEncInt(); return this.bytes(n); }
  nullTerminated() {
    const end = this.buf.indexOf(0, this.pos);
    const str = this.buf.subarray(this.pos, end === -1 ? this.buf.length : end).toString('utf8');
    this.pos = end === -1 ? this.buf.length : end + 1;
    return str;
  }
  rest() { const v = this.buf.subarray(this.pos); this.pos = this.buf.length; return v; }
}

function writePacket(socket, sequenceId, payload) {
  const length = payload.length;
  const header = Buffer.alloc(4);
  header.writeUInt8(length & 0xff, 0);
  header.writeUInt8((length >> 8) & 0xff, 1);
  header.writeUInt8((length >> 16) & 0xff, 2);
  header.writeUInt8(sequenceId & 0xff, 3);
  socket.write(Buffer.concat([header, payload]));
}

/** جریان packet با بافر انباشته. */
class PacketStream {
  constructor(socket, onPacket) {
    this.socket = socket;
    this.buffer = Buffer.alloc(0);
    this.onPacket = onPacket;
    this.queue = Promise.resolve();
    socket.on('data', (chunk) => {
      this.buffer = Buffer.concat([this.buffer, chunk]);
      this.drain();
    });
  }
  drain() {
    while (this.buffer.length >= 4) {
      const length = this.buffer.readUIntLE(0, 3);
      if (this.buffer.length < 4 + length) return;
      const sequenceId = this.buffer.readUInt8(3);
      const payload = this.buffer.subarray(4, 4 + length);
      this.buffer = this.buffer.subarray(4 + length);
      this.queue = this.queue.then(() => this.onPacket(sequenceId, payload)).catch(() => {});
    }
  }
}

/** بستهٔ «تعداد ستون» که فقط یک عدد lenenc است. */
function lenEncIntPacket(value) {
  const w = new Writer();
  w.lenEncInt(value);
  return w.toBuffer();
}

function okPacket({ affectedRows = 0, lastInsertId = 0, status = 0x0002, warnings = 0 } = {}) {
  const w = new Writer();
  w.u8(0x00);
  w.lenEncInt(affectedRows);
  w.lenEncInt(lastInsertId);
  w.u16(status);
  w.u16(warnings);
  return w.toBuffer();
}

function eofPacket(status = 0x0002, warnings = 0) {
  const w = new Writer();
  w.u8(0xfe);
  w.u16(warnings);
  w.u16(status);
  return w.toBuffer();
}

function errPacket(error) {
  const w = new Writer();
  w.u8(0xff);
  w.u16(error.code || 1064);
  w.u8(0x23);
  w.bytes(Buffer.from(error.sqlState || '42000', 'ascii'));
  w.bytes(Buffer.from(String(error.message || 'خطای ناشناخته'), 'utf8'));
  return w.toBuffer();
}

function columnDefinition(field) {
  const w = new Writer();
  w.lenEncBytes(Buffer.from('def', 'utf8'));
  w.lenEncBytes(Buffer.from('', 'utf8'));
  w.lenEncBytes(Buffer.from('', 'utf8'));
  w.lenEncBytes(Buffer.from('', 'utf8'));
  w.lenEncBytes(Buffer.from(field.name, 'utf8'));
  w.lenEncBytes(Buffer.from(field.name, 'utf8'));
  w.lenEncInt(0x0c);
  w.u16(field.charsetnr === CHARSET_BINARY ? CHARSET_BINARY : CHARSET_UTF8MB4);
  w.u32(field.length || 255);
  w.u8(field.type);
  w.u16(field.flags || 0);
  w.u8(field.decimals || 0);
  w.u16(0);
  return w.toBuffer();
}

/** تبدیل مقدار JS به شکل متنی پروتکل MySQL. */
function textValue(value) {
  if (value === null || value === undefined) return null;
  if (Buffer.isBuffer(value)) return value;
  if (value instanceof Uint8Array) return Buffer.from(value);
  if (typeof value === 'bigint') return Buffer.from(value.toString(), 'utf8');
  if (typeof value === 'boolean') return Buffer.from(value ? '1' : '0', 'utf8');
  if (value instanceof Date) return Buffer.from(value.toISOString().slice(0, 19).replace('T', ' '), 'utf8');
  return Buffer.from(String(value), 'utf8');
}

function textRow(values) {
  const w = new Writer();
  for (const value of values) {
    if (value === null || value === undefined) { w.u8(0xfb); continue; }
    w.lenEncBytes(textValue(value));
  }
  return w.toBuffer();
}

/** نگاشت نوع فیلد mariadb-wasm به نوع پروتکل MySQL. */
function mapType(mariaType) {
  return FIELD_TYPE_MAP[mariaType] ?? TYPE_VAR_STRING;
}

/** طول ثابت هر نوع عددی در پروتکل باینری. */
function binaryValue(value, type) {
  const w = new Writer();
  switch (type) {
    case TYPE_TINY: {
      const b = Buffer.alloc(1);
      b.writeInt8(Number(value), 0);
      return w.bytes(b).toBuffer();
    }
    case TYPE_SHORT: case TYPE_YEAR: {
      const b = Buffer.alloc(2);
      b.writeInt16LE(Number(value), 0);
      return w.bytes(b).toBuffer();
    }
    case TYPE_LONG: case TYPE_INT24: {
      const b = Buffer.alloc(4);
      b.writeInt32LE(Number(value), 0);
      return w.bytes(b).toBuffer();
    }
    case TYPE_LONGLONG: {
      const b = Buffer.alloc(8);
      b.writeBigInt64LE(BigInt(value), 0);
      return w.bytes(b).toBuffer();
    }
    case TYPE_FLOAT: {
      const b = Buffer.alloc(4);
      b.writeFloatLE(Number(value), 0);
      return w.bytes(b).toBuffer();
    }
    case TYPE_DOUBLE: {
      const b = Buffer.alloc(8);
      b.writeDoubleLE(Number(value), 0);
      return w.bytes(b).toBuffer();
    }
    case TYPE_DATE: case TYPE_DATETIME: case TYPE_TIMESTAMP: {
      const text = value instanceof Date
        ? value.toISOString().slice(0, 19).replace('T', ' ')
        : String(value);
      const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/.exec(text);
      if (!m) return w.u8(0).toBuffer();
      const hasTime = m[4] !== undefined;
      const micros = 0;
      const length = hasTime ? 11 : 4;
      const out = new Writer();
      out.u8(length);
      out.u16(Number(m[1]));
      out.u8(Number(m[2]));
      out.u8(Number(m[3]));
      if (hasTime) {
        out.u8(Number(m[4]));
        out.u8(Number(m[5]));
        out.u8(Number(m[6]));
        out.u32(micros);
      }
      return out.toBuffer();
    }
    case TYPE_TIME: {
      const m = /^(-?)(\d{1,2}):(\d{2}):(\d{2})(?:\.(\d+))?$/.exec(String(value));
      if (!m) return w.u8(0).toBuffer();
      const out = new Writer();
      const negative = m[1] === '-';
      const micros = m[5] ? Number(m[5].padEnd(6, '0').slice(0, 6)) : 0;
      out.u8(negative ? 12 : 8);
      out.u32(Math.abs(Number(m[2])));
      out.u8(Number(m[3]));
      out.u8(Number(m[4]));
      out.u32(micros);
      return out.toBuffer();
    }
    default:
      return w.lenEncBytes(textValue(value)).toBuffer();
  }
}

function binaryRow(values, fields) {
  const w = new Writer();
  w.u8(0x00);
  const nullBitmap = Buffer.alloc(Math.floor((fields.length + 7 + 2) / 8));
  fields.forEach((field, index) => {
    if (values[index] === null || values[index] === undefined) {
      const bit = index + 2;
      nullBitmap[bit >> 3] |= 1 << (bit & 7);
    }
  });
  w.bytes(nullBitmap);
  values.forEach((value, index) => {
    if (value === null || value === undefined) return;
    w.bytes(binaryValue(value, fields[index].type));
  });
  return w.toBuffer();
}

/**
 * سرور پروتکل MySQL که به یک موتور MariaDB واقعی پشتیبان می‌شود.
 *
 * @param {{ connect: () => Promise<any>, port?: number, host?: string, onQuery?: (sql:string)=>void }} options
 */
export async function startMysqlBridge({ connect, host = '127.0.0.1', port = 0, onQuery = () => {} }) {
  const server = net.createServer();
  const prepared = new Map();
  let nextStatementId = 1;

  server.on('connection', (socket) => {
    socket.setNoDelay(true);
    const scramble = crypto.randomBytes(20);
    let sequenceId = 0;
    let clientCapabilities = 0;
    let authenticated = false;
    let connection = null;

    const send = (payload) => {
      writePacket(socket, sequenceId, payload);
      sequenceId += 1;
    };

    const ensureConnection = async () => {
      if (!connection) connection = await connect();
      return connection;
    };

    // ---------- دست‌دادن اولیه ----------
    const greeting = new Writer();
    greeting.u8(10);
    greeting.nullTerminated('11.8.9-erooydad-bridge');
    greeting.u32(1);
    greeting.bytes(scramble.subarray(0, 8));
    greeting.u8(0);
    greeting.u16(SERVER_CAPABILITIES & 0xffff);
    greeting.u8(CHARSET_UTF8MB4);
    greeting.u16(0x0002);
    greeting.u16((SERVER_CAPABILITIES >>> 16) & 0xffff);
    greeting.u8(21);
    greeting.bytes(Buffer.alloc(10));
    greeting.bytes(scramble.subarray(8));
    greeting.u8(0);
    greeting.nullTerminated('mysql_native_password');
    writePacket(socket, 0, greeting.toBuffer());
    sequenceId = 1;

    const stream = new PacketStream(socket, async (packetSequence, payload) => {
      sequenceId = packetSequence + 1;
      if (payload.length === 0) return;

      // نخستین packet پس از greeting، پاسخ احراز هویت است (نه فرمان).
      if (!authenticated) {
        authenticated = true;
        clientCapabilities = payload.readUInt32LE(0);
        send(okPacket());
        return;
      }

      const command = payload.readUInt8(0);
      const body = payload.subarray(1);

      if (command === COM_QUIT) { socket.end(); return; }

      if (command === COM_INIT_DB) {
        try {
          const conn = await ensureConnection();
          await conn.query(`USE \`${body.toString('utf8').replace(/`/g, '``')}\``);
          send(okPacket());
        } catch (error) {
          send(errPacket({ message: error.message, code: 1049, sqlState: '42000' }));
        }
        return;
      }

      if (command === COM_PING) { send(okPacket()); return; }

      if (command === COM_FIELD_LIST) { send(eofPacket()); return; }

      if (command === COM_STMT_CLOSE) { prepared.delete(body.readUInt32LE(0)); return; }

      if (command === COM_STMT_RESET) { send(okPacket()); return; }

      if (command === COM_STMT_PREPARE) {
        const sql = body.toString('utf8');
        const statementId = nextStatementId++;
        const paramCount = countPlaceholders(sql);
        prepared.set(statementId, { sql, paramCount });
        const w = new Writer();
        w.u8(0x00);
        w.u32(statementId);
        w.u16(0);
        w.u16(paramCount);
        w.u8(0);
        w.u16(0);
        send(w.toBuffer());
        for (let i = 0; i < paramCount; i += 1) {
          writePacket(socket, sequenceId, columnDefinition({
            name: '?', charsetnr: CHARSET_BINARY, length: 0, type: TYPE_VAR_STRING, flags: 0, decimals: 0,
          }));
          sequenceId += 1;
        }
        if (paramCount > 0) { writePacket(socket, sequenceId, eofPacket()); sequenceId += 1; }
        return;
      }

      if (command === COM_STMT_EXECUTE) {
        const statementId = body.readUInt32LE(0);
        const statement = prepared.get(statementId);
        if (!statement) {
          send(errPacket({ message: 'unknown prepared statement', code: 1243 }));
          return;
        }
        const params = parseExecuteParams(body.subarray(9), statement.paramCount);
        try {
          const conn = await ensureConnection();
          onQuery(statement.sql);
          const result = await conn.run(statement.sql, params);
          const rows = result.rows || [];
          const fields = result.fields || [];
          const mapped = fields.map((field) => ({
            name: field.name,
            type: mapType(field.type),
            flags: field.flags,
            decimals: field.decimals,
            charsetnr: field.charsetnr,
            length: 255,
          }));
          if (mapped.length === 0) {
            send(okPacket({
              affectedRows: result.affectedRows ?? 0,
              lastInsertId: Number(result.insertId ?? 0),
            }));
            return;
          }
          send(lenEncIntPacket(mapped.length));
          for (const field of mapped) {
            writePacket(socket, sequenceId, columnDefinition(field));
            sequenceId += 1;
          }
          writePacket(socket, sequenceId, eofPacket());
          sequenceId += 1;
          for (const row of rows) {
            const values = mapped.map((field) => row[field.name]);
            writePacket(socket, sequenceId, binaryRow(values, mapped));
            sequenceId += 1;
          }
          writePacket(socket, sequenceId, eofPacket());
          sequenceId += 1;
        } catch (error) {
          send(errPacket({ message: error.message, code: error.errno || 1064, sqlState: error.sqlState || '42000' }));
        }
        return;
      }

      if (command === COM_QUERY) {
        const sql = body.toString('utf8');
        try {
          const conn = await ensureConnection();
          onQuery(sql);
          const result = await conn.run(sql);
          const rows = result.rows || [];
          const fields = result.fields || [];
          if (!fields || fields.length === 0) {
            send(okPacket({
              affectedRows: result.affectedRows ?? 0,
              lastInsertId: Number(result.insertId ?? 0),
            }));
            return;
          }
          const mapped = fields.map((field) => ({
            name: field.name,
            type: mapType(field.type),
            flags: field.flags,
            decimals: field.decimals,
            charsetnr: field.charsetnr,
            length: 255,
          }));
          send(lenEncIntPacket(mapped.length));
          for (const field of mapped) {
            writePacket(socket, sequenceId, columnDefinition(field));
            sequenceId += 1;
          }
          writePacket(socket, sequenceId, eofPacket());
          sequenceId += 1;
          for (const row of rows) {
            const values = mapped.map((field) => row[field.name]);
            writePacket(socket, sequenceId, textRow(values));
            sequenceId += 1;
          }
          writePacket(socket, sequenceId, eofPacket());
          sequenceId += 1;
        } catch (error) {
          send(errPacket({ message: error.message, code: error.errno || 1064, sqlState: error.sqlState || '42000' }));
        }
        return;
      }

      send(errPacket({ message: `command 0x${command.toString(16)} پشتیبانی نمی‌شود`, code: 1047 }));
    });

    socket.on('error', () => { /* اتصال بسته شد */ });
  });

  await new Promise((resolve) => server.listen(port, host, resolve));
  const address = server.address();

  return {
    port: address.port,
    host,
    close: () => new Promise((resolve) => server.close(resolve)),
    preparedCount: () => prepared.size,
  };
}

/** شمارش placeholder‌های `?` در SQL (با نادیده‌گرفتن رشته‌ها و شناسه‌ها). */
function countPlaceholders(sql) {
  let count = 0;
  let inSingle = false;
  let inDouble = false;
  let inBacktick = false;
  let inLineComment = false;
  for (let i = 0; i < sql.length; i += 1) {
    const ch = sql[i];
    if (inLineComment) { if (ch === '\n') inLineComment = false; continue; }
    if (inSingle) { if (ch === '\\') { i += 1; continue; } if (ch === "'") inSingle = false; continue; }
    if (inDouble) { if (ch === '\\') { i += 1; continue; } if (ch === '"') inDouble = false; continue; }
    if (inBacktick) { if (ch === '`') inBacktick = false; continue; }
    if (ch === "'") { inSingle = true; continue; }
    if (ch === '"') { inDouble = true; continue; }
    if (ch === '`') { inBacktick = true; continue; }
    if (ch === '-' && sql[i + 1] === '-') { inLineComment = true; i += 1; continue; }
    if (ch === '#') { inLineComment = true; continue; }
    if (ch === '?') count += 1;
  }
  return count;
}

/** تجزیهٔ پارامترهای binary در packet اجرای prepared statement. */
function parseExecuteParams(payload, paramCount) {
  if (paramCount === 0) return [];
  const reader = new Reader(payload);
  const nullBitmap = reader.bytes(Math.floor((paramCount + 7) / 8));
  const newParamsBound = reader.u8();
  const types = [];
  if (newParamsBound === 1) {
    for (let i = 0; i < paramCount; i += 1) types.push(reader.u16());
  }
  const params = [];
  for (let i = 0; i < paramCount; i += 1) {
    const isNull = (nullBitmap[i >> 3] & (1 << (i & 7))) !== 0;
    if (isNull) { params.push(null); continue; }
    const typeInfo = types[i] ?? (TYPE_VAR_STRING << 8);
    const type = typeInfo & 0xff;
    const unsigned = (typeInfo & 0x8000) !== 0;
    switch (type) {
      case TYPE_NULL: params.push(null); break;
      case TYPE_TINY: params.push(reader.u8()); break;
      case TYPE_SHORT: case TYPE_YEAR: params.push(reader.u16()); break;
      case TYPE_LONG: case TYPE_INT24: params.push(reader.u32()); break;
      case TYPE_LONGLONG: {
        const v = reader.u64();
        params.push(unsigned ? v : BigInt.asIntN(64, v));
        break;
      }
      case TYPE_FLOAT: {
        const b = Buffer.alloc(4); reader.bytes(4).copy(b); params.push(b.readFloatLE(0)); break;
      }
      case TYPE_DOUBLE: {
        const b = Buffer.alloc(8); reader.bytes(8).copy(b); params.push(b.readDoubleLE(0)); break;
      }
      case TYPE_DATE: case TYPE_DATETIME: case TYPE_TIMESTAMP: {
        const length = reader.u8();
        const parts = reader.bytes(length);
        const year = parts.readUInt16LE(0);
        const month = parts.readUInt8(2);
        const day = parts.readUInt8(3);
        const hasTime = length >= 7;
        const hour = hasTime ? parts.readUInt8(4) : 0;
        const minute = hasTime ? parts.readUInt8(5) : 0;
        const second = hasTime ? parts.readUInt8(6) : 0;
        const text = `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')} ${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}:${String(second).padStart(2, '0')}`;
        params.push(text);
        break;
      }
      case TYPE_TIME: {
        const length = reader.u8();
        const parts = reader.bytes(length);
        const negative = (parts.readUInt8(0) & 0x01) !== 0;
        const days = parts.readUInt32LE(1);
        const hours = parts.readUInt8(5);
        const minutes = parts.readUInt8(6);
        const seconds = parts.readUInt8(7);
        const total = (days * 24 + hours) * 3600 + minutes * 60 + seconds;
        params.push(`${negative ? '-' : ''}${String(Math.floor(total / 3600)).padStart(2, '0')}:${String(Math.floor((total % 3600) / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`);
        break;
      }
      default: {
        const bytes = reader.lenEncBytes();
        params.push(Buffer.from(bytes));
      }
    }
  }
  return params;
}
