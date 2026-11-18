/**
 * پل پروتکل MySQL برای MariaDB درون‌برنامه‌ای (mariadb-wasm).
 *
 * هدف: یک سرور TCP که پروتکل MySQL/MariaDB را پیاده‌سازی کند و هر دستور را
 * به موتور واقعی MariaDB 11.8 (اجرا در WASM) forwards می‌کند. این کار امکان
 * می‌دهد PHP واقعی (نسخهٔ ۷٫۴) از طریق PDO/MySQL به یک پایگاه دادهٔ واقعی
 * متصل شود و آزمون یکپارچهٔ انجام شود.
 *
 * پیاده‌سازی بر پایهٔ مستندات رسمی پروتکل MySQL (packet 4.1).
 */

const CLIENT_LONG_PASSWORD = 0x00000001;
const CLIENT_FOUND_ROWS = 0x00000002;
const CLIENT_LONG_FLAG = 0x00000004;
const CLIENT_CONNECT_WITH_DB = 0x00000008;
const CLIENT_NO_SCHEMA = 0x00000010;
const CLIENT_COMPRESS = 0x00000020;
const CLIENT_LOCAL_FILES = 0x00000080;
const CLIENT_IGNORE_SPACE = 0x00000100;
const CLIENT_PROTOCOL_41 = 0x00000200;
const CLIENT_INTERACTIVE = 0x00000400;
const CLIENT_SSL = 0x00000800;
const CLIENT_TRANSACTIONS = 0x00002000;
const CLIENT_SECURE_CONNECTION = 0x00008000;
const CLIENT_MULTI_STATEMENTS = 0x00010000;
const CLIENT_MULTI_RESULTS = 0x00020000;
const CLIENT_PS_MULTI_RESULTS = 0x00040000;
const CLIENT_PLUGIN_AUTH = 0x00080000;
const CLIENT_CONNECT_ATTRS = 0x00100000;
const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
const CLIENT_SESSION_TRACK = 0x00800000;

const SERVER_CAPABILITIES =
  CLIENT_LONG_PASSWORD | CLIENT_FOUND_ROWS | CLIENT_LONG_FLAG | CLIENT_CONNECT_WITH_DB |
  CLIENT_NO_SCHEMA | CLIENT_LOCAL_FILES | CLIENT_IGNORE_SPACE | CLIENT_PROTOCOL_41 |
  CLIENT_INTERACTIVE | CLIENT_TRANSACTIONS | CLIENT_SECURE_CONNECTION |
  CLIENT_MULTI_STATEMENTS | CLIENT_MULTI_RESULTS | CLIENT_PS_MULTI_RESULTS |
  CLIENT_PLUGIN_AUTH | CLIENT_CONNECT_ATTRS | CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA |
  CLIENT_SESSION_TRACK;

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

const CHARSET_UTF8MB4 = 45; // utf8mb4_general_ci
const CHARSET_BINARY = 63;

const DECIMAL_NOT_FIXED = 0x00;
const DECIMAL_FIXED = 0x01;

/** نوع MySQL متناظر با نوع MariaDB. */
function mysqlType(mariaType) {
  switch (mariaType) {
    case 0: return TYPE_DECIMAL;
    case 1: return TYPE_TINY;
    case 2: return TYPE_SHORT;
    case 3: return TYPE_LONG;
    case 4: return TYPE_FLOAT;
    case 5: return TYPE_DOUBLE;
    case 6: return TYPE_NULL;
    case 7: return TYPE_TIMESTAMP;
    case 8: return TYPE_LONGLONG;
    case 9: return TYPE_INT24;
    case 10: return TYPE_DATE;
    case 11: return TYPE_TIME;
    case 12: return TYPE_DATETIME;
    case 13: return TYPE_YEAR;
    case 15: return TYPE_VARCHAR;
    case 16: return TYPE_BIT;
    case 245: return TYPE_JSON;
    case 246: return TYPE_NEWDECIMAL;
    case 247: return TYPE_ENUM;
    case 248: return TYPE_SET;
    case 249: return TYPE_TINY_BLOB;
    case 250: return TYPE_MEDIUM_BLOB;
    case 251: return TYPE_LONG_BLOB;
    case 252: return TYPE_BLOB;
    case 253: return TYPE_VAR_STRING;
    case 254: return TYPE_STRING;
    case 255: return TYPE_GEOMETRY;
    default: return TYPE_VAR_STRING;
  }
}

class Writer {
  constructor() { this.chunks = []; this.length = 0; }
  /** رشته‌ها حتماً باید با طول بایتی (UTF-8) نوشته شوند، نه طول نویسه‌ای. */
  bytes(buf) {
    const b = Buffer.isBuffer(buf) ? buf : Buffer.from(String(buf), 'utf8');
    this.chunks.push(b);
    this.length += b.length;
    return this;
  }
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

/** خوانندهٔ packet stream با پشتیبانی از پکت‌های چندبخشی. */
class Reader {
  constructor(buffer) { this.buf = buffer; this.pos = 0; }
  remaining() { return this.buf.length - this.pos; }
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
    this.pos = (end === -1 ? this.buf.length : end + 1);
    return str;
  }
  rest() { const v = this.buf.subarray(this.pos); this.pos = this.buf.length; return v; }
}

/** packet را با طول ۳ بایتی و sequence id بفرست. */
function writePacket(socket, sequenceId, payload) {
  const length = payload.length;
  const header = Buffer.alloc(4);
  header.writeUInt8(length & 0xff, 0);
  header.writeUInt8((length >> 8) & 0xff, 1);
  header.writeUInt8((length >> 16) & 0xff, 2);
  header.writeUInt8(sequenceId & 0xff, 3);
  socket.write(Buffer.concat([header, payload]));
}

/** خواندن یک packet کامل از socket. */
function readPacket(socket) {
  return new Promise((resolve, reject) => {
    const onData = (chunk) => {
      socket.removeListener('data', onData);
      socket.removeListener('error', onError);
      socket.removeListener('end', onEnd);
      socket.pause();
      resolve(chunk);
    };
    const onError = (error) => {
      socket.removeListener('data', onData);
      socket.removeListener('error', onError);
      socket.removeListener('end', onEnd);
      reject(error);
    };
    const onEnd = () => {
      socket.removeListener('data', onData);
      socket.removeListener('error', onError);
      socket.removeListener('end', onEnd);
      reject(new Error('اتصال در میانهٔ خواندن packet بسته شد.'));
    };
    socket.on('data', onData);
    socket.on('error', onError);
    socket.on('end', onEnd);
  });
}

/** خواندن یک packet با سرآیند کامل (۴ بایت). */
async function readFullPacket(socket) {
  const head = await readPacket(socket);
  if (head.length < 4) throw new Error('packet کوتاه است.');
  const length = head.readUIntLE(0, 3);
  const sequenceId = head.readUInt8(3);
  if (length === 0) return { sequenceId, payload: Buffer.alloc(0) };
  const rest = await readPacket(socket);
  return { sequenceId, payload: rest.subarray(0, length) };
}

/** نگاشت مقدار JS به بایت‌های پروتکل (برای prepared statement). */
function encodeParam(value) {
  if (value === null || value === undefined) return null;
  if (typeof value === 'number') {
    if (Number.isInteger(value)) {
      if (value >= -2147483648 && value <= 2147483647) {
        const b = Buffer.alloc(4); b.writeInt32LE(value, 0); return { type: TYPE_LONG, value: b };
      }
      const b = Buffer.alloc(8); b.writeBigInt64LE(BigInt(value), 0); return { type: TYPE_LONGLONG, value: b };
    }
    const b = Buffer.alloc(8); b.writeDoubleLE(value, 0); return { type: TYPE_DOUBLE, value: b };
  }
  if (typeof value === 'bigint') {
    const b = Buffer.alloc(8); b.writeBigInt64LE(value, 0); return { type: TYPE_LONGLONG, value: b };
  }
  if (typeof value === 'boolean') return { type: TYPE_TINY, value: Buffer.from([value ? 1 : 0]) };
  if (value instanceof Date) {
    const iso = value.toISOString().slice(0, 19).replace('T', ' ');
    return { type: TYPE_DATETIME, value: Buffer.from(iso, 'utf8') };
  }
  if (Buffer.isBuffer(value)) return { type: TYPE_BLOB, value };
  return { type: TYPE_VAR_STRING, value: Buffer.from(String(value), 'utf8') };
}

function encodeBinaryParam(writer, param) {
  if (param === null) return writer.u8(0xfb);
  writer.u8(param.type);
  writer.bytes(param.value);
  return writer;
}

export { Writer, Reader, encodeParam, encodeBinaryParam, writePacket, readFullPacket, mysqlType, CHARSET_UTF8MB4, CHARSET_BINARY, SERVER_CAPABILITIES, DECIMAL_FIXED, DECIMAL_NOT_FIXED, COM_QUIT, COM_INIT_DB, COM_QUERY, COM_FIELD_LIST, COM_PING, COM_STMT_PREPARE, COM_STMT_EXECUTE, COM_STMT_CLOSE, COM_STMT_RESET, CLIENT_CONNECT_WITH_DB, CLIENT_PLUGIN_AUTH, CLIENT_PROTOCOL_41, CLIENT_SECURE_CONNECTION, CLIENT_SSL, CLIENT_CONNECT_ATTRS, CLIENT_DEPRECATE_EOF };
