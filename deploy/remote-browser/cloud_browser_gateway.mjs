#!/usr/bin/env node
import {
  createCipheriv,
  createDecipheriv,
  createHash,
  randomBytes,
  timingSafeEqual,
} from 'node:crypto';
import {
  createReadStream,
  createWriteStream,
  existsSync,
  readFileSync,
  realpathSync,
} from 'node:fs';
import {
  appendFile,
  chmod,
  mkdir,
  open,
  readFile,
  rm,
  stat,
} from 'node:fs/promises';
import { createServer } from 'node:http';
import { connect as connectTcp } from 'node:net';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { pipeline } from 'node:stream/promises';

const MAGIC = Buffer.from('SUXCBP01', 'ascii');
const HEADER_BYTES = MAGIC.length + 12 + 16;
const MAX_REQUEST_BYTES = 16 * 1024;
const PROFILE_ID_PATTERN = /^cbp_[A-Za-z0-9_-]{16,64}$/;
const SESSION_ID_PATTERN = /^cbls_[A-Za-z0-9_-]{16,64}$/;
const COLLECTION_SESSION_ID_PATTERN = /^cbcs_[A-Za-z0-9_-]{16,64}$/;
const TICKET_PATTERN = /^[A-Za-z0-9_-]{32,96}$/;
const LOGIN_PLATFORM_PATTERN = /^(ctrip|meituan|dingdandao|meituan_cloud_pms)$/;
const OTA_RECEIPT_PLATFORM_PATTERN = /^(ctrip|meituan)$/;
const PMS_PLATFORM_PATTERN = /^(dingdandao|meituan_cloud_pms)$/;
const COLLECTION_PLATFORM_PATTERN = /^(ctrip|meituan|dingdandao|meituan_cloud_pms)$/;
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const DINGDANDAO_SOURCE_URL =
  'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData';
const MEITUAN_CLOUD_PMS_SOURCE_URL = 'https://pms.meituan.com/#qk-workbench';
const MEITUAN_CLOUD_PMS_READ_ONLY_POST_PATHS = new Set([
  '/hotelpms/api/v1/report/home/workbench/businessOverview',
  '/hotelpms/api/v1/report/home/workbench/room',
]);
const OTA_READ_ONLY_POST_HOSTS = {
  ctrip: new Set(['ebooking.ctrip.com']),
  meituan: new Set(['eb.meituan.com', 'me.meituan.com']),
};
// Keep these observed read endpoints aligned with scripts/lib/ota_read_fallback.mjs.
// A same-origin POST is not inherently read-only, so unknown POST paths fail closed.
const OTA_READ_ONLY_POST_PATH_TOKENS = {
  ctrip: [
    'queryhomepagerealtimedata',
    'getdayreportrealtimedate',
    'fetchmarketoverviewv2',
    'getdayreportflowcompete',
    'getdayreportserverquantity',
    'fetchvisitortitlev2',
    'fetchcapacityoverview',
    'queryflowtrans',
    'getcompetehotelreport',
    'getlastweekreport',
    'queryordertrend',
    'queryhotelminprice',
    'queryscanflowdetails',
    'fetchorderoverview',
    'queryflowsource',
    'querycityhotkeywords',
    'querysearchflowdetails',
    'getcommentsscore',
    'gethoteladvice',
    'gethotelpsi',
  ],
  meituan: [
    '/ebooking/home/businessdata',
    '/datacenter/home/traffic',
    '/datacenter/home/peertrends',
  ],
};
const WRITE_LIKE_OTA_PATH =
  /(?:^|[\/_.-])(?:save|update|delete|remove|reply|submit|send|create|modify|edit|cancel|confirm|publish|upload|write)(?:[\/_.-]|$)/i;
const SENSITIVE_KEY_PATTERN =
  /(cookie|password|authorization(?!_status)|(^|_)(token|secret|headers?|raw|html|har)(_|$)|profile[_-]?path|localstorage|sessionstorage)/i;

export function isUnsupportedSnapBrowserExecutable(value) {
  const executable = String(value || '').trim();
  if (executable === '' || executable.startsWith('/snap/')) return executable !== '';
  try {
    if (realpathSync(executable) === '/usr/bin/snap') return true;
  } catch {
    return false;
  }
  try {
    const prefix = readFileSync(executable).subarray(0, 8192).toString('utf8');
    return /(?:^|[\s/])snap(?:\s+run)?\s+chromium\b/m.test(prefix)
      || /\/snap\/bin\/chromium\b/.test(prefix);
  } catch {
    return false;
  }
}

function canonical(value) {
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`;
  }
  return JSON.stringify(value);
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

function viewerScopeDigestMatches(value, opaqueId) {
  const digest = typeof value === 'string' ? value.trim().toLowerCase() : '';
  if (!/^[a-f0-9]{64}$/.test(digest)) return false;
  return timingSafeEqual(Buffer.from(digest, 'ascii'), Buffer.from(sha256(opaqueId), 'ascii'));
}

function safeReason(value, fallback = 'gateway_operation_failed') {
  const normalized = String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80);
  return normalized || fallback;
}

function assertOpaque(value, pattern, reason) {
  const normalized = String(value || '').trim();
  if (!pattern.test(normalized)) throw new Error(reason);
  return normalized;
}

function assertNoSensitiveMaterial(value, path = 'payload') {
  if (Array.isArray(value)) {
    value.forEach((entry, index) => assertNoSensitiveMaterial(entry, `${path}.${index}`));
    return;
  }
  if (!value || typeof value !== 'object') return;
  for (const [key, entry] of Object.entries(value)) {
    if (SENSITIVE_KEY_PATTERN.test(key)) {
      throw new Error(`receipt_sensitive_field_rejected:${path}.${key}`);
    }
    assertNoSensitiveMaterial(entry, `${path}.${key}`);
  }
}

export function decodeMasterKey(value) {
  const raw = Buffer.isBuffer(value) ? value : Buffer.from(value);
  if (raw.length === 32) return Buffer.from(raw);
  const text = raw.toString('utf8').trim();
  if (/^[A-Za-z0-9+/]{43}=$/.test(text) || /^[A-Za-z0-9+/]{44}$/.test(text)) {
    const decoded = Buffer.from(text, 'base64');
    if (decoded.length === 32) return decoded;
  }
  throw new Error('profile_master_key_must_be_32_bytes_or_base64');
}

export async function encryptArchive(sourcePath, destinationPath, key, profileId) {
  const iv = randomBytes(12);
  const cipher = createCipheriv('aes-256-gcm', key, iv);
  cipher.setAAD(Buffer.from(profileId, 'utf8'));
  await mkdir(dirname(destinationPath), { recursive: true, mode: 0o700 });

  const handle = await open(destinationPath, 'w', 0o600);
  try {
    await handle.write(Buffer.concat([MAGIC, iv, Buffer.alloc(16)]), 0, HEADER_BYTES, 0);
    const output = createWriteStream(destinationPath, {
      fd: handle.fd,
      start: HEADER_BYTES,
      autoClose: false,
    });
    await pipeline(createReadStream(sourcePath), cipher, output);
    await handle.write(cipher.getAuthTag(), 0, 16, MAGIC.length + iv.length);
    await handle.sync();
  } finally {
    await handle.close();
  }
  await chmod(destinationPath, 0o600);
}

export async function decryptArchive(sourcePath, destinationPath, key, profileId) {
  const handle = await open(sourcePath, 'r');
  try {
    const header = Buffer.alloc(HEADER_BYTES);
    const { bytesRead } = await handle.read(header, 0, HEADER_BYTES, 0);
    if (bytesRead !== HEADER_BYTES || !header.subarray(0, MAGIC.length).equals(MAGIC)) {
      throw new Error('encrypted_profile_format_invalid');
    }
    const iv = header.subarray(MAGIC.length, MAGIC.length + 12);
    const tag = header.subarray(MAGIC.length + 12, HEADER_BYTES);
    const decipher = createDecipheriv('aes-256-gcm', key, iv);
    decipher.setAAD(Buffer.from(profileId, 'utf8'));
    decipher.setAuthTag(tag);
    await pipeline(
      createReadStream(sourcePath, { start: HEADER_BYTES }),
      decipher,
      createWriteStream(destinationPath, { mode: 0o600 }),
    );
  } finally {
    await handle.close();
  }
}

const CHROMIUM_TRANSIENT_PROFILE_FILES = [
  'SingletonCookie',
  'SingletonLock',
  'SingletonSocket',
  'DevToolsActivePort',
];

export async function removeChromiumTransientProfileState(profilePath) {
  const normalizedProfilePath = resolve(profilePath);
  for (const name of CHROMIUM_TRANSIENT_PROFILE_FILES) {
    await rm(join(normalizedProfilePath, name), { recursive: true, force: true });
  }
}

async function runProcess(command, args, options = {}) {
  return await new Promise((resolvePromise, reject) => {
    const child = spawn(command, args, {
      cwd: options.cwd,
      env: options.env || process.env,
      stdio: options.stdio || [options.input !== undefined ? 'pipe' : 'ignore', 'pipe', 'pipe'],
      windowsHide: true,
      shell: false,
      ...(options.signal ? { signal: options.signal } : {}),
    });
    let stdout = '';
    let stderr = '';
    child.stdout?.on('data', (chunk) => {
      stdout = (stdout + chunk).slice(-64 * 1024);
    });
    child.stderr?.on('data', (chunk) => {
      stderr = (stderr + chunk).slice(-64 * 1024);
    });
    child.once('error', reject);
    child.once('close', (code) => {
      if (code !== 0) {
        reject(new Error(safeReason(stderr, `${command}_failed`)));
        return;
      }
      resolvePromise({ stdout, stderr });
    });
    if (options.input !== undefined) {
      child.stdin?.end(options.input);
    }
  });
}

export class EncryptedProfileVault {
  constructor({ encryptedRoot, runtimeRoot, key, tarBinary = 'tar' }) {
    this.encryptedRoot = resolve(encryptedRoot);
    this.runtimeRoot = resolve(runtimeRoot);
    this.key = decodeMasterKey(key);
    this.tarBinary = tarBinary;
  }

  encryptedPath(profileId) {
    return join(this.encryptedRoot, `${assertOpaque(profileId, PROFILE_ID_PATTERN, 'profile_id_invalid')}.tar.gz.enc`);
  }

  runtimePath(profileId) {
    return join(this.runtimeRoot, assertOpaque(profileId, PROFILE_ID_PATTERN, 'profile_id_invalid'));
  }

  async restore(profileId) {
    const encryptedPath = this.encryptedPath(profileId);
    const runtimePath = this.runtimePath(profileId);
    const archivePath = join(this.runtimeRoot, `${profileId}.restore.tar.gz`);
    await rm(runtimePath, { recursive: true, force: true });
    await mkdir(runtimePath, { recursive: true, mode: 0o700 });
    try {
      await stat(encryptedPath);
      await decryptArchive(encryptedPath, archivePath, this.key, profileId);
      await runProcess(this.tarBinary, [
        '--extract',
        '--gzip',
        '--file',
        archivePath,
        '--directory',
        runtimePath,
        '--no-same-owner',
        '--no-same-permissions',
      ]);
    } catch (error) {
      if (error?.code !== 'ENOENT') throw error;
    } finally {
      await rm(archivePath, { force: true });
    }
    await removeChromiumTransientProfileState(runtimePath);
    return runtimePath;
  }

  async seal(profileId) {
    const runtimePath = this.runtimePath(profileId);
    const archivePath = join(this.runtimeRoot, `${profileId}.seal.tar.gz`);
    const encryptedPath = this.encryptedPath(profileId);
    const pendingPath = `${encryptedPath}.pending`;
    try {
      await runProcess(this.tarBinary, [
        '--create',
        '--gzip',
        '--file',
        archivePath,
        '--directory',
        runtimePath,
        '.',
      ]);
      await encryptArchive(archivePath, pendingPath, this.key, profileId);
      const { rename } = await import('node:fs/promises');
      await rename(pendingPath, encryptedPath);
      await chmod(encryptedPath, 0o600);
    } finally {
      await rm(archivePath, { force: true });
      await rm(pendingPath, { force: true });
      await rm(runtimePath, { recursive: true, force: true });
    }
    return encryptedPath;
  }
}

export class ReceiptChain {
  constructor(chainPath) {
    this.chainPath = resolve(chainPath);
    this.appendQueue = Promise.resolve();
  }

  async records() {
    try {
      const content = await readFile(this.chainPath, 'utf8');
      return content.split(/\r?\n/).filter(Boolean).map((line) => JSON.parse(line));
    } catch (error) {
      if (error?.code === 'ENOENT') return [];
      throw error;
    }
  }

  async append(kind, payload) {
    const pending = this.appendQueue.then(() => this.appendNow(kind, payload));
    this.appendQueue = pending.catch(() => undefined);
    return await pending;
  }

  async appendCollectionResult(payload) {
    const pending = this.appendQueue.then(async () => {
      const records = await this.records();
      let previousHash = null;
      for (const record of records) {
        const { receipt_hash: receiptHash, ...unsigned } = record;
        if (unsigned.prev_hash !== previousHash || sha256(canonical(unsigned)) !== receiptHash) {
          throw new Error('receipt_chain_invalid');
        }
        previousHash = receiptHash;
      }

      const closeReceipt = records.find(
        (record) => record.receipt_id === payload.close_receipt_id,
      );
      if (!closeReceipt
        || closeReceipt.kind !== 'collection_profile_closed'
        || closeReceipt.receipt_hash !== payload.close_receipt_hash
      ) {
        throw new Error('collection_close_receipt_invalid');
      }
      if (records.some(
        (record) => record.kind === 'collection_result'
          && record.payload?.close_receipt_id === payload.close_receipt_id,
      )) {
        throw new GatewayError('collection_result_replay_blocked', 409);
      }

      const closePayload = closeReceipt.payload || {};
      const scopedFields = [
        'collection_session_id',
        'profile_id',
        'platform',
        'tenant_id',
        'hotel_id',
        'owner_user_id',
        'target_date',
      ];
      if (scopedFields.some((field) => closePayload[field] !== payload[field])
        || Number(closePayload.data_source_id || 0) !== payload.data_source_id
        || closePayload.profile_sealed !== true
        || (payload.status === 'saved' && closePayload.outcome !== 'completed')
      ) {
        throw new Error('collection_result_scope_mismatch');
      }
      return await this.appendNow('collection_result', payload);
    });
    this.appendQueue = pending.catch(() => undefined);
    return await pending;
  }

  async appendNow(kind, payload) {
    assertNoSensitiveMaterial(payload);
    const records = await this.records();
    const previous = records.at(-1);
    const record = {
      receipt_id: `cbr_${randomBytes(18).toString('base64url')}`,
      kind: assertOpaque(kind, /^[a-z][a-z0-9_-]{2,40}$/, 'receipt_kind_invalid'),
      occurred_at: new Date().toISOString(),
      prev_hash: previous?.receipt_hash || null,
      payload,
    };
    record.receipt_hash = sha256(canonical(record));
    await mkdir(dirname(this.chainPath), { recursive: true, mode: 0o700 });
    await appendFile(this.chainPath, `${JSON.stringify(record)}\n`, { encoding: 'utf8', mode: 0o600 });
    await chmod(this.chainPath, 0o600);
    return record;
  }

  async find(receiptId) {
    return (await this.records()).find((record) => record.receipt_id === receiptId) || null;
  }

  async verify() {
    let previousHash = null;
    for (const record of await this.records()) {
      const { receipt_hash: receiptHash, ...unsigned } = record;
      if (unsigned.prev_hash !== previousHash || sha256(canonical(unsigned)) !== receiptHash) {
        return false;
      }
      previousHash = receiptHash;
    }
    return true;
  }
}

function loadConfig(env = process.env) {
  const moduleDir = dirname(fileURLToPath(import.meta.url));
  const projectRoot = resolve(moduleDir, '..', '..');
  const bindAddress = String(env.SUXIOS_CLOUD_BROWSER_BIND || '127.0.0.1').trim();
  if (bindAddress !== '127.0.0.1') throw new Error('gateway_bind_must_be_loopback');
  const configuredBrowser = String(env.SUXIOS_CLOUD_BROWSER_EXECUTABLE || '').trim();
  const browserCandidates = [
    '/usr/bin/google-chrome-stable',
    '/opt/google/chrome/google-chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/snap/bin/chromium',
  ];
  const detectedBrowser = browserCandidates.find((candidate) => (
    existsSync(candidate) && !isUnsupportedSnapBrowserExecutable(candidate)
  ));
  return {
    projectRoot,
    bindAddress,
    port: Number.parseInt(env.SUXIOS_CLOUD_BROWSER_PORT || '8787', 10),
    encryptedRoot: env.SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT || '/var/lib/suxios-cloud-browser/profiles',
    runtimeRoot: env.SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT || '/run/suxios-cloud-browser/profiles',
    receiptPath: env.SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN || '/var/lib/suxios-cloud-browser/receipts/chain.jsonl',
    keyFile: env.SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE || '/run/credentials/suxios-cloud-browser-gateway.service/profile-master-key',
    controlTokenFile: env.SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE || '/run/credentials/suxios-cloud-browser-gateway.service/control-token',
    phpBinary: env.SUXIOS_CLOUD_BROWSER_PHP_BINARY || '/usr/bin/php',
    bridgeScript: env.SUXIOS_CLOUD_BROWSER_BRIDGE_SCRIPT || join(projectRoot, 'scripts', 'cloud_browser_gateway_bridge.php'),
    browserExecutable: configuredBrowser || detectedBrowser || '/usr/bin/chromium',
    display: env.SUXIOS_CLOUD_BROWSER_DISPLAY || ':99',
    cdpPort: Number.parseInt(env.SUXIOS_CLOUD_BROWSER_CDP_PORT || '9223', 10),
    noVncPort: Number.parseInt(env.SUXIOS_CLOUD_BROWSER_NOVNC_PORT || '6080', 10),
    viewerUrl: env.SUXIOS_CLOUD_BROWSER_VIEWER_URL || 'http://127.0.0.1:6080/vnc.html?autoconnect=true&resize=scale',
    loginTtlSeconds: Math.min(900, Math.max(60, Number.parseInt(env.SUXIOS_CLOUD_BROWSER_LOGIN_TTL_SECONDS || '900', 10))),
    collectionTtlSeconds: Math.min(
      1800,
      // Queue children are capped at 900 seconds; the gateway must never expire first.
      Math.max(900, Number.parseInt(env.SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS || '1200', 10)),
    ),
    profileSessionTtlSeconds: Math.min(
      30 * 86400,
      Math.max(3600, Number.parseInt(env.SUXIOS_CLOUD_BROWSER_PROFILE_SESSION_TTL_SECONDS || '604800', 10)),
    ),
  };
}

async function readSecret(path, reason) {
  const value = (await readFile(path)).toString('utf8').trim();
  if (value.length < 32) throw new Error(reason);
  return value;
}

function jsonResponse(response, status, payload) {
  const body = JSON.stringify(payload);
  response.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
    'content-length': Buffer.byteLength(body),
    'x-content-type-options': 'nosniff',
  });
  response.end(body);
}

async function jsonBody(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > MAX_REQUEST_BYTES) throw new Error('request_body_too_large');
    chunks.push(chunk);
  }
  const parsed = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('request_body_invalid');
  return parsed;
}

function authorized(request, token) {
  const provided = String(request.headers.authorization || '').replace(/^Bearer\s+/i, '');
  const a = Buffer.from(provided);
  const b = Buffer.from(token);
  return a.length === b.length && a.length > 0 && timingSafeEqual(a, b);
}

function validateLoginRequest(body) {
  return {
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    sessionId: assertOpaque(body.session_id, SESSION_ID_PATTERN, 'session_id_invalid'),
    ticket: assertOpaque(body.ticket, TICKET_PATTERN, 'ticket_invalid'),
    platform: assertOpaque(body.platform, LOGIN_PLATFORM_PATTERN, 'platform_invalid'),
  };
}

function validateLoginCompletionRequest(body) {
  return {
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    sessionId: assertOpaque(body.session_id, SESSION_ID_PATTERN, 'session_id_invalid'),
    ticket: body.ticket == null || String(body.ticket).trim() === ''
      ? null
      : assertOpaque(body.ticket, TICKET_PATTERN, 'ticket_invalid'),
    platform: assertOpaque(body.platform, LOGIN_PLATFORM_PATTERN, 'platform_invalid'),
  };
}

function validateLoginCancelRequest(body) {
  if (Object.keys(body).some((key) => !['profile_id', 'session_id', 'platform'].includes(key))) {
    throw new Error('login_cancel_scope_invalid');
  }
  return {
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    sessionId: assertOpaque(body.session_id, SESSION_ID_PATTERN, 'session_id_invalid'),
    platform: assertOpaque(body.platform, LOGIN_PLATFORM_PATTERN, 'platform_invalid'),
  };
}

function positiveInteger(value, reason) {
  const parsed = Number.parseInt(value, 10);
  if (!Number.isInteger(parsed) || parsed <= 0 || String(parsed) !== String(value).trim()) {
    throw new Error(reason);
  }
  return parsed;
}

function validateCollectionOpenRequest(body) {
  const platform = assertOpaque(body.platform, COLLECTION_PLATFORM_PATTERN, 'platform_invalid');
  const collectionKind = assertOpaque(
    body.collection_kind,
    /^(operating_target_today|ota_target_date|ota_channel_profile)$/,
    'collection_kind_invalid',
  );
  const dataSourceId = body.data_source_id == null || String(body.data_source_id).trim() === ''
    ? 0
    : positiveInteger(body.data_source_id, 'data_source_id_invalid');
  const isOta = OTA_RECEIPT_PLATFORM_PATTERN.test(platform);
  if ((isOta && collectionKind === 'ota_channel_profile' && dataSourceId <= 0)
    || (isOta && collectionKind === 'ota_target_date' && dataSourceId !== 0)
    || (isOta && !['ota_target_date', 'ota_channel_profile'].includes(collectionKind))
    || (!isOta && (collectionKind !== 'operating_target_today' || dataSourceId !== 0))
  ) {
    throw new Error('collection_scope_invalid');
  }
  return {
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    platform,
    dataSourceId,
    tenantId: positiveInteger(body.tenant_id, 'tenant_id_invalid'),
    hotelId: positiveInteger(body.hotel_id, 'hotel_id_invalid'),
    ownerUserId: positiveInteger(body.owner_user_id, 'owner_user_id_invalid'),
    targetDate: assertOpaque(body.target_date, DATE_PATTERN, 'target_date_invalid'),
    collectionKind,
    accessMode: assertOpaque(body.access_mode, /^read_only$/, 'access_mode_invalid'),
  };
}

function validateCollectionCloseRequest(body) {
  return {
    collectionSessionId: assertOpaque(
      body.collection_session_id,
      COLLECTION_SESSION_ID_PATTERN,
      'collection_session_id_invalid',
    ),
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    platform: assertOpaque(body.platform, COLLECTION_PLATFORM_PATTERN, 'platform_invalid'),
    outcome: assertOpaque(
      body.outcome,
      /^(completed|cancelled|session_expired|policy_blocked)$/,
      'collection_outcome_invalid',
    ),
  };
}

function validateCollectionAbortRequest(body) {
  if (Object.keys(body).some((key) => !['profile_public_id', 'collection_session_id'].includes(key))) {
    throw new Error('collection_abort_scope_invalid');
  }
  return {
    profilePublicId: assertOpaque(
      body.profile_public_id,
      PROFILE_ID_PATTERN,
      'profile_public_id_invalid',
    ),
    collectionSessionId: body.collection_session_id == null
      ? null
      : assertOpaque(
        body.collection_session_id,
        COLLECTION_SESSION_ID_PATTERN,
        'collection_session_id_invalid',
      ),
  };
}

async function bridge(config, action, payload, options = {}) {
  const input = JSON.stringify({ action, ...payload });
  const result = await runProcess(
    config.phpBinary,
    [config.bridgeScript],
    { cwd: config.projectRoot, input, signal: options.signal },
  );
  const parsed = JSON.parse(result.stdout);
  if (parsed?.status !== 'ok') throw new Error('gateway_state_bridge_failed');
  return parsed.result;
}

function platformStartUrl(platform) {
  const urls = {
    ctrip: 'https://ebooking.ctrip.com/home/mainland',
    meituan: 'https://me.meituan.com/ebooking/',
    dingdandao: DINGDANDAO_SOURCE_URL,
    meituan_cloud_pms: MEITUAN_CLOUD_PMS_SOURCE_URL,
  };
  const url = urls[platform];
  if (!url) throw new Error('platform_start_url_missing');
  return url;
}

function trustedCollectionPageState(value, platform) {
  let location;
  let source;
  try {
    location = new URL(String(value || ''));
    source = new URL(platformStartUrl(platform));
  } catch {
    return 'invalid';
  }
  if (location.protocol !== 'https:'
    || location.origin !== source.origin
    || location.username !== ''
    || location.password !== ''
  ) return 'origin_mismatch';
  const pathMatched = platform === 'ctrip'
    ? location.pathname.startsWith('/home/')
    : (platform === 'meituan'
      ? location.pathname.startsWith('/ebooking/')
      : location.pathname === source.pathname);
  return pathMatched ? 'matched' : 'path_mismatch';
}

function trustedCollectionPageLocation(value, platform) {
  return trustedCollectionPageState(value, platform) === 'matched';
}

async function startBrowser(config, profilePath, platform, startUrl = null) {
  if (isUnsupportedSnapBrowserExecutable(config.browserExecutable)) {
    throw new Error('snap_chromium_runtime_profile_unsupported');
  }
  const loginUrl = startUrl || platformStartUrl(platform);
  const child = spawn(config.browserExecutable, [
    '--disable-dev-shm-usage',
    '--no-first-run',
    '--no-default-browser-check',
    '--lang=zh-CN',
    '--accept-lang=zh-CN,zh;q=0.9',
    '--remote-debugging-address=127.0.0.1',
    `--remote-debugging-port=${config.cdpPort}`,
    '--remote-allow-origins=http://127.0.0.1',
    `--user-data-dir=${profilePath}`,
    '--window-size=1440,960',
    loginUrl,
  ], {
    env: {
      ...process.env,
      DISPLAY: config.display,
      LANG: 'zh_CN.UTF-8',
      LANGUAGE: 'zh_CN:zh',
      LC_ALL: 'zh_CN.UTF-8',
    },
    stdio: 'ignore',
    windowsHide: true,
    shell: false,
  });
  return await new Promise((resolvePromise, reject) => {
    child.once('error', reject);
    child.once('spawn', () => {
      child.unref();
      resolvePromise(child);
    });
  });
}

async function stopBrowser(child) {
  if (!child || child.exitCode !== null) return;
  child.kill('SIGTERM');
  await new Promise((resolvePromise) => {
    const timer = setTimeout(() => {
      if (child.exitCode === null) child.kill('SIGKILL');
      resolvePromise();
    }, 5000);
    child.once('exit', () => {
      clearTimeout(timer);
      resolvePromise();
    });
  });
}

function delay(milliseconds) {
  return new Promise((resolvePromise) => {
    const timer = setTimeout(resolvePromise, milliseconds);
    timer.unref?.();
  });
}

async function waitForBrowserPage(config, child, timeoutMs = 12000) {
  const endpoint = `http://127.0.0.1:${config.cdpPort}/json/list`;
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (child?.exitCode !== null) throw new Error('browser_exited_before_cdp_ready');
    try {
      const response = await fetch(endpoint, { signal: AbortSignal.timeout(1000) });
      const targets = response.ok ? await response.json() : [];
      const pages = Array.isArray(targets)
        ? targets.map(normalizeBrowserPageTarget).filter(Boolean)
        : [];
      if (pages.length > 1) throw new Error('browser_page_count_invalid');
      const [page] = pages;
      if (page) return page;
    } catch {
      // Chromium may need a short startup interval before the loopback CDP port exists.
    }
    await delay(100);
  }
  throw new Error('browser_cdp_not_ready');
}

export function normalizeBrowserPageTarget(target) {
  const targetId = String(target?.targetId || target?.id || '').trim();
  if (target?.type !== 'page'
    || !targetId
    || typeof target.webSocketDebuggerUrl !== 'string'
  ) {
    return null;
  }
  return { ...target, targetId };
}

export function isDingdandaoReadOnlyRequestAllowed({ url, method, resourceType }) {
  return isPmsReadOnlyRequestAllowed(
    { url, method, resourceType },
    'dingdandao',
  );
}

export function isPmsReadOnlyRequestAllowed({ url, method, resourceType }, platform) {
  let parsed;
  try {
    parsed = new URL(String(url || ''));
  } catch {
    return false;
  }
  const normalizedMethod = String(method || '').toUpperCase();
  if (parsed.protocol !== 'https:') {
    return false;
  }
  if (platform === 'meituan_cloud_pms'
    && normalizedMethod === 'POST'
    && parsed.origin === 'https://pms.meituan.com'
  ) {
    return MEITUAN_CLOUD_PMS_READ_ONLY_POST_PATHS.has(parsed.pathname);
  }
  if (!['GET', 'HEAD', 'OPTIONS'].includes(normalizedMethod)) return false;
  if (resourceType !== 'Document') {
    return true;
  }
  const source = new URL(platformStartUrl(platform));
  return parsed.origin === source.origin && parsed.pathname === source.pathname;
}

export function isCloudProfileReadOnlyRequestAllowed({
  platform,
  url,
  method,
  resourceType,
}) {
  const normalizedPlatform = String(platform || '').toLowerCase();
  if (PMS_PLATFORM_PATTERN.test(normalizedPlatform)) {
    return isPmsReadOnlyRequestAllowed({ url, method, resourceType }, normalizedPlatform);
  }
  if (!OTA_RECEIPT_PLATFORM_PATTERN.test(normalizedPlatform)) return false;

  let parsed;
  try {
    parsed = new URL(String(url || ''));
  } catch {
    return false;
  }
  if (parsed.protocol !== 'https:') return false;

  const normalizedMethod = String(method || '').toUpperCase();
  const normalizedResourceType = String(resourceType || '');
  const approvedHost = normalizedPlatform === 'ctrip'
    ? ['ctrip.com', 'ctripbiz.com', 'ctripbiz.cn']
      .some((suffix) => parsed.hostname === suffix || parsed.hostname.endsWith(`.${suffix}`))
    : ['meituan.com', 'dianping.com']
      .some((suffix) => parsed.hostname === suffix || parsed.hostname.endsWith(`.${suffix}`));

  if (['GET', 'HEAD', 'OPTIONS'].includes(normalizedMethod)) {
    return normalizedResourceType !== 'Document' || approvedHost;
  }
  if (normalizedMethod !== 'POST' || !['XHR', 'Fetch'].includes(normalizedResourceType)) {
    return false;
  }

  const normalizedPath = parsed.pathname.toLowerCase();
  if (WRITE_LIKE_OTA_PATH.test(normalizedPath)) return false;
  return OTA_READ_ONLY_POST_HOSTS[normalizedPlatform].has(parsed.hostname.toLowerCase())
    && OTA_READ_ONLY_POST_PATH_TOKENS[normalizedPlatform]
      .some((token) => normalizedPath.includes(token));
}

async function installPmsReadOnlyPolicy(config, child, platform = 'dingdandao') {
  if (typeof WebSocket !== 'function') {
    throw new Error('read_only_policy_websocket_unavailable');
  }
  const target = await waitForBrowserPage(config, child);
  const socket = new WebSocket(target.webSocketDebuggerUrl);
  const pending = new Map();
  let nextId = 1;
  let closed = false;
  let intentionalClose = false;
  let policyViolation = false;
  const autoAttachPolicy = {
    autoAttach: true,
    waitForDebuggerOnStart: true,
    flatten: true,
  };
  const markPolicyViolation = (reason = 'unknown') => {
    policyViolation = true;
    console.error(`SUXIOS_GATEWAY_POLICY_VIOLATION ${platform} ${safeReason(reason, 'unknown')}`);
    child?.kill?.('SIGTERM');
  };
  const requestPolicyEnforced = COLLECTION_PLATFORM_PATTERN.test(
    String(platform || '').toLowerCase(),
  );

  await new Promise((resolvePromise, reject) => {
    const timer = setTimeout(() => reject(new Error('read_only_policy_connect_timeout')), 5000);
    socket.addEventListener('open', () => {
      clearTimeout(timer);
      resolvePromise();
    }, { once: true });
    socket.addEventListener('error', () => {
      clearTimeout(timer);
      if (!intentionalClose) markPolicyViolation('policy_socket_error');
      reject(new Error('read_only_policy_connect_failed'));
    }, { once: true });
  });

  const send = (method, params = {}, sessionId = '') => new Promise((resolvePromise, reject) => {
    if (closed || socket.readyState !== WebSocket.OPEN) {
      reject(new Error('read_only_policy_connection_closed'));
      return;
    }
    const id = nextId++;
    pending.set(id, { resolvePromise, reject });
    socket.send(JSON.stringify({
      id,
      method,
      params,
      ...(sessionId ? { sessionId } : {}),
    }));
  });

  const failClosedForTarget = (targetId = '') => {
    markPolicyViolation('attached_target_unprotected');
    if (targetId) send('Target.closeTarget', { targetId }).catch(() => undefined);
  };

  const closeUnexpectedPageTarget = (targetId = '') => {
    if (!targetId) {
      markPolicyViolation('unexpected_target_id_missing');
      return;
    }
    // Ctrip may transiently create a target=_blank popup while the collector
    // clicks a read-only report control. Close that extra page immediately;
    // keep the guarded page and browser alive because no request escapes the
    // existing Fetch policy and the extra target is never exposed to callers.
    // The paused target may disappear by itself before this command is
    // processed. Treat that race as an idempotent close: the target never ran
    // and no unguarded page is exposed to the collector.
    send('Target.closeTarget', { targetId }).catch(() => undefined);
  };

  const protectAttachedTarget = async ({ sessionId = '', targetInfo = {} } = {}) => {
    if (!sessionId) {
      failClosedForTarget(targetInfo.targetId);
      return;
    }
    if (targetInfo.targetId && targetInfo.targetId !== target.targetId) {
      closeUnexpectedPageTarget(targetInfo.targetId);
      return;
    }
    try {
      await send('Network.enable', {}, sessionId);
      await send('Network.setCacheDisabled', { cacheDisabled: true }, sessionId);
      await send('Network.setBypassServiceWorker', { bypass: true }, sessionId);
      await send('Fetch.enable', {
        patterns: [{ urlPattern: '*', requestStage: 'Request' }],
      }, sessionId);
      await send('Target.setAutoAttach', autoAttachPolicy, sessionId);
      await send('Runtime.runIfWaitingForDebugger', {}, sessionId);
    } catch {
      failClosedForTarget(targetInfo.targetId);
    }
  };

  socket.addEventListener('message', (event) => {
    let message;
    try {
      message = JSON.parse(String(event.data));
    } catch {
      return;
    }
    if (Number.isInteger(message.id) && pending.has(message.id)) {
      const request = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) {
        request.reject(new Error(safeReason(message.error.message, 'cdp_command_failed')));
      } else {
        request.resolvePromise(message.result || {});
      }
      return;
    }
    if (message.method === 'Target.attachedToTarget') {
      void protectAttachedTarget(message.params);
      return;
    }
    if (message.method === 'Target.targetCreated') {
      const targetInfo = message.params?.targetInfo || {};
      if (targetInfo.type === 'page' && targetInfo.targetId !== target.targetId) {
        closeUnexpectedPageTarget(targetInfo.targetId);
      }
      return;
    }
    if (!requestPolicyEnforced || message.method !== 'Fetch.requestPaused') return;
    const paused = message.params || {};
    const allowed = isCloudProfileReadOnlyRequestAllowed({
      platform,
      url: paused.request?.url,
      method: paused.request?.method,
      resourceType: paused.resourceType,
    });
    const command = allowed ? 'Fetch.continueRequest' : 'Fetch.failRequest';
    const params = allowed
      ? { requestId: paused.requestId }
      : { requestId: paused.requestId, errorReason: 'BlockedByClient' };
    send(command, params, message.sessionId || '').catch(() => undefined);
  });

  socket.addEventListener('close', () => {
    if (!intentionalClose) {
      markPolicyViolation('policy_socket_closed');
    }
    closed = true;
    for (const request of pending.values()) {
      request.reject(new Error('read_only_policy_connection_closed'));
    }
    pending.clear();
  });

  try {
    await send('Network.enable');
    await send('Network.setCacheDisabled', { cacheDisabled: true });
    await send('Network.setBypassServiceWorker', { bypass: true });
    await send('Page.enable');
    await send('Target.setAutoAttach', autoAttachPolicy);
    await send('Target.setDiscoverTargets', { discover: true });
    if (requestPolicyEnforced) {
      await send('Fetch.enable', {
        patterns: [{ urlPattern: '*', requestStage: 'Request' }],
      });
    }
    await send('Browser.setDownloadBehavior', { behavior: 'deny' });
    const navigation = await send('Page.navigate', { url: platformStartUrl(platform) });
    if (navigation.errorText) throw new Error('read_only_navigation_failed');
    await send('Runtime.enable');
    // Ctrip's authenticated home shell can remain in `loading` while its
    // bundled read-only resources initialize. Keep the origin/path/request
    // gates unchanged, but allow the real production page a bounded window
    // before declaring the navigation unverified.
    const navigationDeadline = Date.now() + (platform === 'ctrip' ? 30000 : 12000);
    let sourcePageReady = false;
    let navigationFailure = 'read_only_navigation_document_not_ready';
    while (Date.now() < navigationDeadline) {
      try {
        const evaluated = await send('Runtime.evaluate', {
          expression: '({ href: location.href, readyState: document.readyState })',
          returnByValue: true,
        });
        const value = evaluated?.result?.value || {};
        const locationState = trustedCollectionPageState(value.href, platform);
        navigationFailure = locationState === 'matched'
          ? 'read_only_navigation_document_not_ready'
          : `read_only_navigation_${locationState}`;
        // The gateway owns location and read-only policy trust. Ctrip's SPA
        // can legitimately stay in the browser-standard `loading` state while
        // the collector performs its stricter login, hotel, date and field
        // readiness checks over the already guarded CDP session.
        const acceptedReadyStates = platform === 'ctrip'
          ? ['loading', 'interactive', 'complete']
          : ['interactive', 'complete'];
        sourcePageReady = trustedCollectionPageLocation(value.href, platform)
          && acceptedReadyStates.includes(String(value.readyState || ''));
        if (sourcePageReady) {
          // The Ctrip SPA may replace its execution context while still in
          // `loading`. Only accept readiness once the lease marker succeeds
          // in that same stable context; otherwise retry within the deadline.
          await send('Runtime.evaluate', {
            expression: "window.name='suxios_profile_lease_guarded'",
            returnByValue: true,
          });
          break;
        }
      } catch (error) {
        sourcePageReady = false;
        navigationFailure = error?.message === 'read_only_policy_connection_closed'
          ? 'read_only_policy_connection_closed'
          : 'read_only_navigation_evaluation_unavailable';
      }
      await delay(100);
    }
    if (!sourcePageReady) throw new Error(navigationFailure);
  } catch (error) {
    closed = true;
    socket.close();
    throw error;
  }

  return {
    get requestPolicyEnforced() {
      return requestPolicyEnforced
        && !policyViolation
        && !closed
        && socket.readyState === WebSocket.OPEN;
    },
    httpCacheDisabled: true,
    serviceWorkerBypassed: true,
    close() {
      if (closed) return;
      intentionalClose = true;
      closed = true;
      socket.close();
    },
  };
}

function publicError(error) {
  return safeReason(error?.message || error);
}

class GatewayError extends Error {
  constructor(message, statusCode = 422) {
    super(message);
    this.statusCode = statusCode;
  }
}

export async function createGateway(env = process.env, dependencies = {}) {
  const config = loadConfig(env);
  const bridgeCall = dependencies.bridge
    || ((action, payload, options) => bridge(config, action, payload, options));
  const startBrowserCall = dependencies.startBrowser || startBrowser;
  const stopBrowserCall = dependencies.stopBrowser || stopBrowser;
  const waitForBrowserPageCall = dependencies.waitForBrowserPage || waitForBrowserPage;
  const installReadOnlyPolicyCall = dependencies.installReadOnlyPolicy
    || installPmsReadOnlyPolicy;
  const [key, controlToken] = await Promise.all([
    readFile(config.keyFile).then(decodeMasterKey),
    readSecret(config.controlTokenFile, 'gateway_control_token_invalid'),
  ]);
  const vault = new EncryptedProfileVault({
    encryptedRoot: config.encryptedRoot,
    runtimeRoot: config.runtimeRoot,
    key,
  });
  const receipts = new ReceiptChain(config.receiptPath);
  if (!(await receipts.verify())) throw new Error('receipt_chain_integrity_failed');
  const sessions = new Map();
  let capacitySlot = null;

  function claimCapacity(session, busyReason) {
    if (capacitySlot !== null) {
      throw new GatewayError(busyReason, 409);
    }
    capacitySlot = session;
  }

  function releaseCapacity(session) {
    if (capacitySlot === session) capacitySlot = null;
  }

  function throwIfCollectionAbortRequested(session) {
    if (session.abortRequested === true) {
      throw new GatewayError('collection_open_aborted', 409);
    }
  }

  function disconnectViewerConnections(session) {
    for (const connection of session.viewerConnections || []) {
      connection.client?.destroy();
      connection.upstream?.destroy();
    }
    session.viewerConnections?.clear();
  }

  async function closeSession(session, { seal = true } = {}) {
    if (session.closePromise) return await session.closePromise;
    session.state = 'closing';
    clearTimeout(session.timeout);
    disconnectViewerConnections(session);
    session.closePromise = (async () => {
      let failure = null;
      try {
        try {
          session.guard?.close();
        } catch (error) {
          failure = error;
        }
        try {
          await stopBrowserCall(session.browser);
        } catch (error) {
          failure ||= error;
        }
        if (seal && session.profileRestored) {
          try {
            await vault.seal(session.profileId);
          } catch (error) {
            failure ||= new GatewayError('profile_seal_failed', 500);
            await rm(vault.runtimePath(session.profileId), { recursive: true, force: true });
          }
        } else if (session.runtimeTouched) {
          await rm(vault.runtimePath(session.profileId), { recursive: true, force: true });
        }
      } finally {
        sessions.delete(session.key);
        releaseCapacity(session);
        session.state = 'closed';
      }
      if (failure) throw failure;
    })();
    return await session.closePromise;
  }

  const server = createServer(async (request, response) => {
    const url = new URL(request.url || '/', `http://${config.bindAddress}:${config.port}`);
    try {
      if (request.method === 'GET' && url.pathname === '/health') {
        const activeLoginSessions = capacitySlot?.kind === 'login' ? 1 : 0;
        const activeCollectionSessions = capacitySlot?.kind === 'collection' ? 1 : 0;
        jsonResponse(response, 200, {
          status: 'ok',
          bind: config.bindAddress,
          encrypted_profile_store: true,
          receipt_chain_valid: await receipts.verify(),
          active_login_sessions: activeLoginSessions,
          active_collection_sessions: activeCollectionSessions,
          active_browser_sessions: capacitySlot === null ? 0 : 1,
          browser_autostart: false,
          read_only_policy_runtime: typeof WebSocket === 'function',
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/login/open') {
        const login = validateLoginRequest(await jsonBody(request));
        const session = {
          ...login,
          kind: 'login',
          key: login.sessionId,
          ticketHash: sha256(login.ticket),
          state: 'opening',
          browser: null,
          guard: null,
          runtimeTouched: false,
          profileRestored: false,
          viewerConnections: new Set(),
          cancelRequested: false,
          abortController: new AbortController(),
          openedAt: new Date().toISOString(),
          expiresAt: new Date(Date.now() + config.loginTtlSeconds * 1000).toISOString(),
        };
        let settleOpening;
        session.openingSettled = new Promise((resolvePromise) => {
          settleOpening = resolvePromise;
        });
        claimCapacity(session, 'gateway_login_capacity_busy');
        try {
          const validated = await bridgeCall('validate_login', {
            profile_id: login.profileId,
            session_id: login.sessionId,
            ticket: login.ticket,
          }, { signal: session.abortController.signal });
          if (session.cancelRequested) throw new GatewayError('login_open_cancelled', 409);
          if (validated?.profile?.platform !== login.platform || validated?.login_entry?.validated !== true) {
            throw new Error('login_entry_scope_mismatch');
          }
          session.runtimeTouched = true;
          const profilePath = await vault.restore(login.profileId);
          session.profileRestored = true;
          if (session.cancelRequested) throw new GatewayError('login_open_cancelled', 409);
          session.browser = await startBrowserCall(config, profilePath, login.platform);
          if (session.cancelRequested) throw new GatewayError('login_open_cancelled', 409);
          await waitForBrowserPageCall(config, session.browser);
          if (session.cancelRequested) throw new GatewayError('login_open_cancelled', 409);
          session.state = 'active';
          sessions.set(login.sessionId, session);
        } catch (error) {
          try {
            await closeSession(session, { seal: session.profileRestored });
          } catch (cleanupError) {
            throw cleanupError;
          }
          if (session.cancelRequested) throw new GatewayError('login_open_cancelled', 409);
          throw error;
        } finally {
          settleOpening();
        }
        if (session.cancelRequested || session.state !== 'active' || capacitySlot !== session) {
          throw new GatewayError('login_open_cancelled', 409);
        }
        session.timeout = setTimeout(async () => {
          try {
            await closeSession(session);
            await receipts.append('login_timeout', {
              profile_id: session.profileId,
              session_id: session.sessionId,
              platform: session.platform,
              status: 'expired',
            });
          } catch {
            // The close path has already removed runtime material and released capacity.
          }
        }, config.loginTtlSeconds * 1000);
        session.timeout.unref();
        jsonResponse(response, 201, {
          status: 'awaiting_login',
          profile_id: login.profileId,
          session_id: login.sessionId,
          expires_at: session.expiresAt,
          viewer_url: config.viewerUrl,
          browser_started: true,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/login/complete') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const login = validateLoginCompletionRequest(await jsonBody(request));
        const session = sessions.get(login.sessionId);
        if (!session
          || session.kind !== 'login'
          || session.state !== 'active'
          || session.profileId !== login.profileId
          || session.platform !== login.platform
          || (login.ticket !== null && session.ticketHash !== sha256(login.ticket))) {
          throw new GatewayError('active_login_session_not_found', 404);
        }
        session.state = 'completing';
        await closeSession(session);
        const sessionExpiresAt = new Date(Date.now() + config.profileSessionTtlSeconds * 1000)
          .toISOString();
        let profile;
        try {
          profile = await bridgeCall('complete_login', {
            profile_id: login.profileId,
            session_id: login.sessionId,
            ticket: session.ticket,
            session_expires_at: sessionExpiresAt,
          });
        } catch (error) {
          try {
            await bridgeCall('cancel_login_entry', {
              profile_id: login.profileId,
              session_id: login.sessionId,
              reason: 'gateway_complete_failed',
            });
          } catch {
            throw new GatewayError('login_complete_compensation_failed', 500);
          }
          throw error;
        }
        const receipt = await receipts.append('login_profile_ready', {
          profile_id: login.profileId,
          session_id: login.sessionId,
          platform: login.platform,
          authorization_status: profile.authorization_status,
          encrypted_at_rest: true,
          session_expires_at: profile.session_expires_at,
        });
        jsonResponse(response, 200, {
          status: profile.authorization_status,
          profile_id: login.profileId,
          receipt_id: receipt.receipt_id,
          receipt_hash: receipt.receipt_hash,
          browser_started: false,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/login/cancel') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const login = validateLoginCancelRequest(await jsonBody(request));
        const session = capacitySlot;
        if (!session
          || session.kind !== 'login'
          || !['opening', 'active'].includes(session.state)
          || session.profileId !== login.profileId
          || session.sessionId !== login.sessionId
          || session.platform !== login.platform
        ) {
          jsonResponse(response, 200, {
            status: 'no_active_login',
            profile_id: login.profileId,
            session_id: login.sessionId,
            platform: login.platform,
            cancelled: false,
            cleanup_verified: true,
          });
          return;
        }
        session.cancelRequested = true;
        session.abortController.abort();
        await session.openingSettled;
        await closeSession(session);
        const receipt = await receipts.append('login_cancelled', {
          profile_id: login.profileId,
          session_id: login.sessionId,
          platform: login.platform,
          status: 'cancelled',
        });
        jsonResponse(response, 200, {
          status: 'cancelled',
          profile_id: login.profileId,
          session_id: login.sessionId,
          platform: login.platform,
          cancelled: true,
          cleanup_verified: capacitySlot !== session && session.state === 'closed',
          browser_started: false,
          profile_sealed: true,
          receipt_id: receipt.receipt_id,
          receipt_hash: receipt.receipt_hash,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/collection/open') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const collection = validateCollectionOpenRequest(await jsonBody(request));
        const collectionSessionId = `cbcs_${randomBytes(18).toString('base64url')}`;
        const session = {
          ...collection,
          kind: 'collection',
          key: collectionSessionId,
          collectionSessionId,
          state: 'opening',
          browser: null,
          guard: null,
          runtimeTouched: false,
          profileRestored: false,
          viewerConnections: new Set(),
          abortRequested: false,
          abortController: new AbortController(),
          openedAt: new Date().toISOString(),
          expiresAt: new Date(Date.now() + config.collectionTtlSeconds * 1000).toISOString(),
        };
        let settleOpening;
        session.openingSettled = new Promise((resolvePromise) => {
          settleOpening = resolvePromise;
        });
        claimCapacity(session, 'gateway_collection_capacity_busy');
        let validated;
        try {
          const validationAction = OTA_RECEIPT_PLATFORM_PATTERN.test(collection.platform)
            ? 'validate_ota_collection'
            : (collection.platform === 'dingdandao'
              ? 'validate_dingdandao_collection'
              : 'validate_pms_collection');
          validated = await bridgeCall(validationAction, {
            profile_id: collection.profileId,
            platform: collection.platform,
            ...(collection.dataSourceId > 0 ? { data_source_id: collection.dataSourceId } : {}),
            tenant_id: collection.tenantId,
            hotel_id: collection.hotelId,
            owner_user_id: collection.ownerUserId,
            target_date: collection.targetDate,
          }, { signal: session.abortController.signal });
          throwIfCollectionAbortRequested(session);
          if (validated?.validated !== true
            || validated?.profile?.profile_id !== collection.profileId
            || validated?.profile?.platform !== collection.platform
            || (validated?.platform != null && validated.platform !== collection.platform)
            || validated?.tenant_id !== collection.tenantId
            || validated?.hotel_id !== collection.hotelId
            || validated?.owner_user_id !== collection.ownerUserId
            || validated?.target_date !== collection.targetDate
            || validated?.collection_kind !== collection.collectionKind
            || validated?.access_mode !== collection.accessMode
            || (collection.dataSourceId > 0
              && validated?.data_source_id !== collection.dataSourceId)
          ) {
            throw new Error('collection_scope_mismatch');
          }
          session.runtimeTouched = true;
          const profilePath = await vault.restore(collection.profileId);
          session.profileRestored = true;
          throwIfCollectionAbortRequested(session);
          session.browser = await startBrowserCall(config, profilePath, collection.platform, 'about:blank');
          throwIfCollectionAbortRequested(session);
          await waitForBrowserPageCall(config, session.browser);
          throwIfCollectionAbortRequested(session);
          session.guard = await installReadOnlyPolicyCall(config, session.browser, collection.platform);
          throwIfCollectionAbortRequested(session);
          if (session.guard?.httpCacheDisabled !== true
            || session.guard?.serviceWorkerBypassed !== true
            || session.guard?.requestPolicyEnforced !== true
          ) {
            throw new Error('collection_browser_policy_unverified');
          }
          session.state = 'active';
          sessions.set(collectionSessionId, session);
        } catch (error) {
          try {
            await closeSession(session, { seal: session.profileRestored });
          } catch (cleanupError) {
            throw cleanupError;
          }
          if (session.abortRequested === true) {
            throw new GatewayError('collection_open_aborted', 409);
          }
          const reason = publicError(error);
          if (reason.startsWith('collection_')
            || reason.startsWith('profile_')
            || reason.startsWith('gateway_')
            || reason.startsWith('browser_')
            || reason.startsWith('read_only_')
            || reason.startsWith('snap_chromium_')) {
            throw error;
          }
          throw new GatewayError('read_only_policy_setup_failed', 500);
        } finally {
          settleOpening();
        }
        if (session.abortRequested || session.state !== 'active' || capacitySlot !== session) {
          throw new GatewayError('collection_open_aborted', 409);
        }
        session.timeout = setTimeout(async () => {
          try {
            await closeSession(session);
            await receipts.append('collection_window_timeout', {
              collection_session_id: session.collectionSessionId,
              profile_id: session.profileId,
              platform: session.platform,
              tenant_id: session.tenantId,
              hotel_id: session.hotelId,
              owner_user_id: session.ownerUserId,
              ...(session.dataSourceId > 0 ? { data_source_id: session.dataSourceId } : {}),
              target_date: session.targetDate,
              access_mode: session.accessMode,
              status: 'expired',
            });
          } catch {
            // The close path has already removed runtime material and released capacity.
          }
        }, config.collectionTtlSeconds * 1000);
        session.timeout.unref();
        jsonResponse(response, 201, {
          status: 'collection_open',
          collection_session_id: collectionSessionId,
          profile_id: collection.profileId,
          platform: collection.platform,
          tenant_id: collection.tenantId,
          hotel_id: collection.hotelId,
          owner_user_id: collection.ownerUserId,
          ...(collection.dataSourceId > 0 ? { data_source_id: collection.dataSourceId } : {}),
          target_date: collection.targetDate,
          collection_kind: collection.collectionKind,
          source_url: validated.source_url || platformStartUrl(collection.platform),
          source_scope: validated.source_scope || (
            collection.platform === 'dingdandao'
              ? 'today_only'
              : 'today_realtime_accommodation'
          ),
          access_mode: 'read_only',
          read_only_enforced: session.guard.requestPolicyEnforced === true,
          profile_restored: true,
          session_owner: 'gateway_collection',
          external_browser_required: false,
          user_browser_closed: false,
          collector_read_only_contract: OTA_RECEIPT_PLATFORM_PATTERN.test(collection.platform),
          network_freshness_control: {
            http_cache_disabled: session.guard.httpCacheDisabled === true,
            service_worker_bypassed: session.guard.serviceWorkerBypassed === true,
          },
          expires_at: session.expiresAt,
          browser_started: true,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/collection/abort') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const abort = validateCollectionAbortRequest(await jsonBody(request));
        const session = capacitySlot;
        if (!session
          || session.kind !== 'collection'
          || session.profileId !== abort.profilePublicId
          || (abort.collectionSessionId !== null
            && session.collectionSessionId !== abort.collectionSessionId)
        ) {
          jsonResponse(response, 200, {
            status: 'no_active_collection',
            profile_public_id: abort.profilePublicId,
            aborted: false,
            collection_session_id: null,
            cleanup_verified: true,
          });
          return;
        }
        session.abortRequested = true;
        session.abortController.abort();
        await session.openingSettled;
        await closeSession(session);
        const receipt = await receipts.append('collection_profile_aborted', {
          collection_session_id: session.collectionSessionId,
          profile_id: session.profileId,
          platform: session.platform,
          tenant_id: session.tenantId,
          hotel_id: session.hotelId,
          owner_user_id: session.ownerUserId,
          ...(session.dataSourceId > 0 ? { data_source_id: session.dataSourceId } : {}),
          target_date: session.targetDate,
          access_mode: session.accessMode,
          outcome: 'aborted_by_supervisor',
          profile_sealed: true,
          data_status: 'unverified',
        });
        jsonResponse(response, 200, {
          status: 'aborted',
          profile_public_id: abort.profilePublicId,
          aborted: true,
          collection_session_id: session.collectionSessionId,
          cleanup_verified: capacitySlot !== session && session.state === 'closed',
          browser_started: false,
          profile_sealed: true,
          data_status: 'unverified',
          receipt_id: receipt.receipt_id,
          receipt_hash: receipt.receipt_hash,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/collection/close') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const collection = validateCollectionCloseRequest(await jsonBody(request));
        const session = sessions.get(collection.collectionSessionId);
        if (!session
          || session.kind !== 'collection'
          || session.profileId !== collection.profileId
          || session.platform !== collection.platform
        ) {
          throw new GatewayError('active_collection_session_not_found', 404);
        }
        const collectionPolicyStillEnforced = session.guard?.requestPolicyEnforced === true;
        try {
          await closeSession(session);
        } catch {
          throw new GatewayError('profile_seal_failed', 500);
        }
        if (!collectionPolicyStillEnforced) {
          throw new GatewayError('collection_browser_policy_breached', 409);
        }
        if (collection.outcome === 'session_expired') {
          await bridgeCall('expire_profile', {
            profile_id: collection.profileId,
            reason: `${collection.platform}_session_expired`,
          });
        }
        const receipt = await receipts.append('collection_profile_closed', {
          collection_session_id: collection.collectionSessionId,
          profile_id: collection.profileId,
          platform: collection.platform,
          tenant_id: session.tenantId,
          hotel_id: session.hotelId,
          owner_user_id: session.ownerUserId,
          ...(session.dataSourceId > 0 ? { data_source_id: session.dataSourceId } : {}),
          target_date: session.targetDate,
          access_mode: session.accessMode,
          outcome: collection.outcome,
          profile_sealed: true,
          data_status: 'unverified',
        });
        jsonResponse(response, 200, {
          status: 'collection_closed',
          collection_session_id: collection.collectionSessionId,
          browser_started: false,
          profile_sealed: true,
          user_browser_closed: false,
          sensitive_values_exposed: false,
          data_status: 'unverified',
          receipt_id: receipt.receipt_id,
          receipt_hash: receipt.receipt_hash,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/collection/receipt') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const body = await jsonBody(request);
        assertNoSensitiveMaterial(body);
        const payload = {
          task_id: assertOpaque(body.task_id, /^cct_[A-Za-z0-9_-]{8,96}$/, 'task_id_invalid'),
          collection_session_id: assertOpaque(
            body.collection_session_id,
            COLLECTION_SESSION_ID_PATTERN,
            'collection_session_id_invalid',
          ),
          profile_id: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
          platform: assertOpaque(body.platform, OTA_RECEIPT_PLATFORM_PATTERN, 'platform_invalid'),
          tenant_id: Number.parseInt(body.tenant_id, 10),
          hotel_id: Number.parseInt(body.hotel_id, 10),
          owner_user_id: Number.parseInt(body.owner_user_id, 10),
          data_source_id: body.data_source_id == null || String(body.data_source_id).trim() === ''
            ? 0
            : Number.parseInt(body.data_source_id, 10),
          target_date: assertOpaque(body.target_date, /^\d{4}-\d{2}-\d{2}$/, 'target_date_invalid'),
          close_receipt_id: assertOpaque(
            body.close_receipt_id,
            /^cbr_[A-Za-z0-9_-]{16,64}$/,
            'close_receipt_id_invalid',
          ),
          close_receipt_hash: assertOpaque(
            body.close_receipt_hash,
            /^[a-f0-9]{64}$/,
            'close_receipt_hash_invalid',
          ),
          source_method: assertOpaque(body.source_method, /^cloud_browser_profile$/, 'source_method_invalid'),
          status: assertOpaque(body.status, /^(saved|partial|failed|blocked)$/, 'receipt_status_invalid'),
          identity_verified: body.identity_verified === true,
          saved_count: Number.parseInt(body.saved_count, 10),
          readback_count: Number.parseInt(body.readback_count, 10),
          field_facts_sha256: assertOpaque(body.field_facts_sha256, /^[a-f0-9]{64}$/, 'field_facts_hash_invalid'),
          failure_stage: body.failure_stage ? safeReason(body.failure_stage, 'collection_failed') : null,
        };
        if (!Number.isInteger(payload.tenant_id) || !Number.isInteger(payload.hotel_id)
          || !Number.isInteger(payload.owner_user_id) || !Number.isInteger(payload.data_source_id)
          || !Number.isInteger(payload.saved_count) || !Number.isInteger(payload.readback_count)
          || payload.tenant_id <= 0 || payload.hotel_id <= 0 || payload.owner_user_id <= 0
          || payload.data_source_id <= 0
          || payload.saved_count < 0 || payload.readback_count < 0) {
          throw new Error('receipt_numeric_scope_invalid');
        }
        if (payload.status === 'saved'
          && (!payload.identity_verified
            || payload.saved_count <= 0
            || payload.saved_count !== payload.readback_count)) {
          throw new Error('receipt_truth_gate_failed');
        }
        const receipt = await receipts.appendCollectionResult(payload);
        jsonResponse(response, 201, {
          status: 'accepted',
          receipt_id: receipt.receipt_id,
          receipt_hash: receipt.receipt_hash,
          prev_hash: receipt.prev_hash,
        });
        return;
      }

      const receiptMatch = url.pathname.match(/^\/v1\/receipts\/(cbr_[A-Za-z0-9_-]{16,64})$/);
      if (request.method === 'GET' && receiptMatch) {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const receipt = await receipts.find(receiptMatch[1]);
        jsonResponse(response, receipt ? 200 : 404, receipt || { status: 'failed', reason: 'receipt_not_found' });
        return;
      }

      jsonResponse(response, 404, { status: 'failed', reason: 'gateway_route_not_found' });
    } catch (error) {
      jsonResponse(
        response,
        Number.isInteger(error?.statusCode) ? error.statusCode : 422,
        { status: 'failed', reason: publicError(error) },
      );
    }
  });

  server.on('upgrade', (request, clientSocket, head) => {
    const url = new URL(request.url || '/', `http://${config.bindAddress}:${config.port}`);
    const session = capacitySlot;
    if (url.pathname !== '/v1/viewer/websockify') {
      clientSocket.end('HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n');
      return;
    }
    if (!session
      || session.kind !== 'login'
      || session.state !== 'active'
    ) {
      clientSocket.end('HTTP/1.1 409 Conflict\r\nConnection: close\r\n\r\n');
      return;
    }
    if (!viewerScopeDigestMatches(
      request.headers['x-suxios-viewer-profile-scope'],
      session.profileId,
    ) || !viewerScopeDigestMatches(
      request.headers['x-suxios-viewer-session-scope'],
      session.sessionId,
    )) {
      clientSocket.end('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
      return;
    }

    const connection = { client: clientSocket, upstream: null };
    session.viewerConnections.add(connection);
    const upstreamSocket = connectTcp({ host: '127.0.0.1', port: config.noVncPort });
    connection.upstream = upstreamSocket;
    const detach = () => session.viewerConnections.delete(connection);
    clientSocket.once('close', detach);
    upstreamSocket.once('close', detach);
    clientSocket.once('error', () => upstreamSocket.destroy());
    upstreamSocket.once('error', () => clientSocket.destroy());
    upstreamSocket.once('connect', () => {
      if (session.state !== 'active' || capacitySlot !== session) {
        clientSocket.destroy();
        upstreamSocket.destroy();
        return;
      }
      const forwarded = [
        `GET /websockify${url.search} HTTP/1.1`,
        `Host: 127.0.0.1:${config.noVncPort}`,
      ];
      for (const name of [
        'upgrade',
        'connection',
        'sec-websocket-key',
        'sec-websocket-version',
        'sec-websocket-protocol',
        'sec-websocket-extensions',
        'origin',
      ]) {
        const value = request.headers[name];
        if (typeof value === 'string' && value !== '') forwarded.push(`${name}: ${value}`);
      }
      upstreamSocket.write(`${forwarded.join('\r\n')}\r\n\r\n`);
      if (head.length > 0) upstreamSocket.write(head);
      clientSocket.pipe(upstreamSocket);
      upstreamSocket.pipe(clientSocket);
    });
  });

  return { server, config, vault, receipts };
}

async function main() {
  const { server, config } = await createGateway();
  server.listen(config.port, config.bindAddress, () => {
    process.stdout.write(`SUXIOS cloud browser gateway listening on ${config.bindAddress}:${config.port}\n`);
  });
}

const isDirect = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isDirect) {
  main().catch((error) => {
    process.stderr.write(`${publicError(error)}\n`);
    process.exit(1);
  });
}
