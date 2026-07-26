#!/usr/bin/env node
import {
  createCipheriv,
  createDecipheriv,
  createHash,
  randomBytes,
  timingSafeEqual,
} from 'node:crypto';
import { createReadStream, createWriteStream, existsSync } from 'node:fs';
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
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { pipeline } from 'node:stream/promises';

const MAGIC = Buffer.from('SUXCBP01', 'ascii');
const HEADER_BYTES = MAGIC.length + 12 + 16;
const MAX_REQUEST_BYTES = 16 * 1024;
const PROFILE_ID_PATTERN = /^cbp_[A-Za-z0-9_-]{16,64}$/;
const SESSION_ID_PATTERN = /^cbls_[A-Za-z0-9_-]{16,64}$/;
const PROFILE_LEASE_ID_PATTERN = /^cbpl_[A-Za-z0-9_-]{16,64}$/;
const COLLECTION_SESSION_ID_PATTERN = /^cbcs_[A-Za-z0-9_-]{16,64}$/;
const COLLECTION_CLAIM_ID_PATTERN = /^cct_[A-Za-z0-9_-]{16,64}$/;
const TICKET_PATTERN = /^[A-Za-z0-9_-]{32,96}$/;
const LOGIN_PLATFORM_PATTERN = /^(ctrip|meituan|dingdandao)$/;
const OTA_RECEIPT_PLATFORM_PATTERN = /^(ctrip|meituan)$/;
const DINGDANDAO_PLATFORM_PATTERN = /^dingdandao$/;
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const DINGDANDAO_SOURCE_URL =
  'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData';
const DINGDANDAO_IDENTITY_QUERY_PATH = '/v2/ntw/web/ntw/get';
const DINGDANDAO_BUSINESS_QUERY_PATHS = new Map([
  ['/v2/um-b/web/pro/data/businessIndicatorsTotal', 'total'],
  ['/v2/um-b/web/pro/data/businessIndicatorsSumDetail', 'sum_detail'],
  ['/v2/um-b/web/pro/data/businessIndicatorsTrend', 'trend'],
  ['/v2/um-b/web/pro/data/businessIndicatorsDailyDetail', 'daily_detail'],
  ['/v2/um-b/web/pro/data/businessIndicatorsTotal/county', 'county_total'],
  ['/v2/um-b/web/pro/data/businessIndicatorsTrend/county', 'county_trend'],
]);
const DINGDANDAO_DETAIL_QUERY_TYPES = new Set([0, 1, 2, 3]);
const DINGDANDAO_TREND_QUERY_TYPES = new Set([0, 1, 2, 3, 5]);
const SENSITIVE_KEY_PATTERN =
  /(cookie|password|authorization(?!_status)|(^|_)(token|secret|headers?|raw|html|har)(_|$)|profile[_-]?path|localstorage|sessionstorage)/i;

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

function assertBoundedText(value, maxLength, reason) {
  const normalized = String(value || '').trim();
  if (normalized === ''
    || normalized.length > maxLength
    || /[\u0000-\u001f\u007f]/.test(normalized)
  ) throw new Error(reason);
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

async function runProcess(command, args, options = {}) {
  return await new Promise((resolvePromise, reject) => {
    const child = spawn(command, args, {
      cwd: options.cwd,
      env: options.env || process.env,
      stdio: options.stdio || [options.input !== undefined ? 'pipe' : 'ignore', 'pipe', 'pipe'],
      windowsHide: true,
      shell: false,
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
    try {
      await stat(runtimePath);
      throw new Error('profile_runtime_quarantine_present');
    } catch (error) {
      if (error?.code !== 'ENOENT') throw error;
    }
    let encryptedArchiveExists = true;
    try {
      await stat(encryptedPath);
    } catch (error) {
      if (error?.code !== 'ENOENT') throw error;
      encryptedArchiveExists = false;
    }
    await rm(archivePath, { force: true });
    await mkdir(runtimePath, { recursive: true, mode: 0o700 });
    if (!encryptedArchiveExists) return runtimePath;
    try {
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
    } finally {
      await rm(archivePath, { force: true });
    }
    return runtimePath;
  }

  async seal(profileId) {
    const runtimePath = this.runtimePath(profileId);
    const archivePath = join(this.runtimeRoot, `${profileId}.seal.tar.gz`);
    const encryptedPath = this.encryptedPath(profileId);
    const pendingPath = `${encryptedPath}.pending`;
    let sealed = false;
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
      sealed = true;
    } finally {
      await rm(archivePath, { force: true });
      await rm(pendingPath, { force: true });
      if (sealed) {
        await rm(runtimePath, { recursive: true, force: true });
      }
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
    browserExecutable: env.SUXIOS_CLOUD_BROWSER_EXECUTABLE
      || ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium'].find(existsSync)
      || '/usr/bin/chromium',
    display: env.SUXIOS_CLOUD_BROWSER_DISPLAY || ':99',
    cdpPort: Number.parseInt(env.SUXIOS_CLOUD_BROWSER_CDP_PORT || '9223', 10),
    viewerUrl: env.SUXIOS_CLOUD_BROWSER_VIEWER_URL || 'http://127.0.0.1:6080/vnc.html?autoconnect=true&resize=scale',
    loginTtlSeconds: Math.min(900, Math.max(60, Number.parseInt(env.SUXIOS_CLOUD_BROWSER_LOGIN_TTL_SECONDS || '900', 10))),
    collectionTtlSeconds: Math.min(
      600,
      Math.max(60, Number.parseInt(env.SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS || '300', 10)),
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

function positiveInteger(value, reason) {
  const parsed = Number.parseInt(value, 10);
  if (!Number.isInteger(parsed) || parsed <= 0 || String(parsed) !== String(value).trim()) {
    throw new Error(reason);
  }
  return parsed;
}

function validateCollectionOpenRequest(body) {
  return {
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    platform: assertOpaque(body.platform, DINGDANDAO_PLATFORM_PATTERN, 'platform_invalid'),
    tenantId: positiveInteger(body.tenant_id, 'tenant_id_invalid'),
    hotelId: positiveInteger(body.hotel_id, 'hotel_id_invalid'),
    ownerUserId: positiveInteger(body.owner_user_id, 'owner_user_id_invalid'),
    targetDate: assertOpaque(body.target_date, DATE_PATTERN, 'target_date_invalid'),
    collectionKind: assertOpaque(
      body.collection_kind,
      /^operating_target_today$/,
      'collection_kind_invalid',
    ),
    accessMode: assertOpaque(body.access_mode, /^read_only$/, 'access_mode_invalid'),
  };
}

function validateProfileLeaseOpenRequest(body) {
  return {
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    platform: assertOpaque(body.platform, DINGDANDAO_PLATFORM_PATTERN, 'platform_invalid'),
    tenantId: positiveInteger(body.tenant_id, 'tenant_id_invalid'),
    hotelId: positiveInteger(body.hotel_id, 'hotel_id_invalid'),
    ownerUserId: positiveInteger(body.owner_user_id, 'owner_user_id_invalid'),
    targetDate: assertOpaque(body.target_date, DATE_PATTERN, 'target_date_invalid'),
    leaseKind: assertOpaque(
      body.lease_kind,
      /^(binding_identity|daily_collection)$/,
      'profile_lease_kind_invalid',
    ),
    accessMode: assertOpaque(body.access_mode, /^read_only$/, 'access_mode_invalid'),
  };
}

function validateProfileLeaseCloseRequest(body) {
  return {
    profileLeaseId: assertOpaque(
      body.profile_lease_id,
      PROFILE_LEASE_ID_PATTERN,
      'profile_lease_id_invalid',
    ),
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    platform: assertOpaque(body.platform, DINGDANDAO_PLATFORM_PATTERN, 'platform_invalid'),
    outcome: assertOpaque(
      body.outcome,
      /^(completed|cancelled|failed|session_expired|policy_blocked|window_expired)$/,
      'profile_lease_outcome_invalid',
    ),
  };
}

function validateProfileLeaseScope(result, lease) {
  const commonScopeMatches = result?.tenant_id === lease.tenantId
    && result?.hotel_id === lease.hotelId
    && result?.owner_user_id === lease.ownerUserId;
  if (!commonScopeMatches) throw new Error('profile_lease_scope_mismatch');
  if (lease.leaseKind === 'binding_identity') {
    if (result?.status !== 'ready_for_identity_probe'
      || result?.profile_id !== lease.profileId
      || result?.provider !== lease.platform
      || result?.binding_persisted !== false
    ) {
      throw new Error('profile_lease_scope_mismatch');
    }
    return result;
  }
  if (result?.validated !== true
    || result?.target_date !== lease.targetDate
    || result?.profile?.profile_id !== lease.profileId
    || result?.profile?.platform !== lease.platform
    || result?.access_mode !== lease.accessMode
  ) {
    throw new Error('profile_lease_scope_mismatch');
  }
  return result;
}

function validateCollectionCloseRequest(body) {
  return {
    collectionSessionId: assertOpaque(
      body.collection_session_id,
      COLLECTION_SESSION_ID_PATTERN,
      'collection_session_id_invalid',
    ),
    profileId: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
    platform: assertOpaque(body.platform, DINGDANDAO_PLATFORM_PATTERN, 'platform_invalid'),
    outcome: assertOpaque(
      body.outcome,
      /^(completed|cancelled|failed|session_expired|policy_blocked|report_blocked|window_expired)$/,
      'collection_outcome_invalid',
    ),
  };
}

function validateCollectionClaim(result, collection, collectionSessionId, windowExpiresAt) {
  const claimId = assertOpaque(
    result?.claim_id,
    COLLECTION_CLAIM_ID_PATTERN,
    'collection_claim_id_invalid',
  );
  const providerHotelName = assertBoundedText(
    result?.provider_hotel_name,
    160,
    'collection_provider_hotel_name_invalid',
  );
  if (result?.claimed !== true
    || result?.collection_session_id !== collectionSessionId
    || result?.profile_id !== collection.profileId
    || result?.platform !== collection.platform
    || result?.tenant_id !== collection.tenantId
    || result?.hotel_id !== collection.hotelId
    || result?.owner_user_id !== collection.ownerUserId
    || result?.target_date !== collection.targetDate
    || result?.collection_kind !== collection.collectionKind
    || result?.access_mode !== collection.accessMode
    || result?.source_scope !== 'today_only'
    || result?.window_expires_at !== windowExpiresAt
    || result?.lifecycle_status !== 'open'
    || result?.data_status !== 'unverified'
  ) {
    throw new Error('collection_claim_scope_mismatch');
  }
  return { claimId, providerHotelName };
}

function validateCollectionCompletion(result, session, outcome) {
  if (result?.completed !== true
    || result?.claim_id !== session.claimId
    || result?.collection_session_id !== session.collectionSessionId
    || result?.profile_id !== session.profileId
    || result?.outcome !== outcome
    || result?.lifecycle_status !== 'closed'
    || result?.data_status !== 'unverified'
  ) {
    throw new Error('collection_completion_scope_mismatch');
  }
  return result;
}

async function bridge(config, action, payload) {
  const input = JSON.stringify({ action, ...payload });
  const result = await runProcess(
    config.phpBinary,
    [config.bridgeScript],
    { cwd: config.projectRoot, input },
  );
  const parsed = JSON.parse(result.stdout);
  if (parsed?.status !== 'ok') throw new Error('gateway_state_bridge_failed');
  return parsed.result;
}

export function platformStartUrl(platform) {
  const urls = {
    ctrip: 'https://ebooking.ctrip.com/home/mainland',
    meituan: 'https://me.meituan.com/ebooking/',
    dingdandao: DINGDANDAO_SOURCE_URL,
  };
  const url = urls[platform];
  if (!url) throw new Error('platform_start_url_missing');
  return url;
}

async function startBrowser(config, profilePath, platform, startUrl = null) {
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

async function waitForChildExit(child, timeoutMs) {
  if (!child || child.exitCode !== null) return true;
  return await new Promise((resolvePromise) => {
    const timer = setTimeout(() => {
      child.removeListener?.('exit', onExit);
      resolvePromise(child.exitCode !== null);
    }, timeoutMs);
    const onExit = () => {
      clearTimeout(timer);
      resolvePromise(true);
    };
    child.once('exit', onExit);
  });
}

export async function stopBrowser(child) {
  if (!child || child.exitCode !== null) return;
  child.kill('SIGTERM');
  if (await waitForChildExit(child, 5000)) return;
  child.kill('SIGKILL');
  if (await waitForChildExit(child, 2000)) return;
  throw new Error('browser_stop_unconfirmed');
}

function delay(milliseconds) {
  return new Promise((resolvePromise) => {
    const timer = setTimeout(resolvePromise, milliseconds);
    timer.unref?.();
  });
}

async function waitForBrowserPage(
  config,
  child,
  expectedTargetUrl = null,
  timeoutMs = 12000,
) {
  const endpoint = `http://127.0.0.1:${config.cdpPort}/json/list`;
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (child?.exitCode !== null) throw new Error('browser_exited_before_cdp_ready');
    try {
      const response = await fetch(endpoint, { signal: AbortSignal.timeout(1000) });
      const targets = response.ok ? await response.json() : [];
      const page = Array.isArray(targets)
        ? targets.find((target) => target?.type === 'page'
          && typeof target.webSocketDebuggerUrl === 'string'
          && (expectedTargetUrl === null || target.url === expectedTargetUrl))
        : null;
      if (page) return page;
    } catch {
      // Chromium may need a short startup interval before the loopback CDP port exists.
    }
    await delay(100);
  }
  throw new Error('browser_cdp_not_ready');
}

async function assertCdpPortAvailable(config) {
  try {
    const response = await fetch(
      `http://127.0.0.1:${config.cdpPort}/json/version`,
      { signal: AbortSignal.timeout(500) },
    );
    if (!response.ok) return;
    const version = await response.json().catch(() => null);
    if (typeof version?.webSocketDebuggerUrl === 'string') {
      throw new Error('browser_cdp_port_busy');
    }
  } catch (error) {
    if (error?.message === 'browser_cdp_port_busy') throw error;
  }
}

export function shanghaiToday(now = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now);
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
}

function dingdandaoReadOnlyPostBodyAllowed(path, postData, today) {
  let body;
  try {
    body = JSON.parse(String(postData || ''));
  } catch {
    return false;
  }
  if (!body || Array.isArray(body) || typeof body !== 'object') return false;
  if (body.TIMEZONEOFFSET !== -480
    || typeof body.ntwNum !== 'string'
    || !/^[A-Za-z0-9_-]{1,120}$/.test(body.ntwNum)
  ) return false;
  if (path === DINGDANDAO_IDENTITY_QUERY_PATH) {
    return Object.keys(body).sort().join(',') === 'TIMEZONEOFFSET,ntwNum';
  }
  const queryKind = DINGDANDAO_BUSINESS_QUERY_PATHS.get(path);
  if (!queryKind
    || body.startDate !== today
    || body.endDate !== today
    || !DATE_PATTERN.test(body.startDate)
  ) return false;
  const keys = Object.keys(body).sort().join(',');
  if (['total', 'county_total'].includes(queryKind)) {
    return keys === 'TIMEZONEOFFSET,endDate,festivalType,ntwNum,startDate'
      && body.festivalType === -1200;
  }
  if (keys !== 'TIMEZONEOFFSET,endDate,ntwNum,startDate,type') return false;
  if (queryKind === 'county_trend') {
    return body.type === 5;
  }
  if (queryKind === 'trend') {
    return DINGDANDAO_TREND_QUERY_TYPES.has(body.type);
  }
  return DINGDANDAO_DETAIL_QUERY_TYPES.has(body.type);
}

export function isDingdandaoReadOnlyRequestAllowed({
  url,
  method,
  resourceType,
  postData = null,
  today = shanghaiToday(),
}) {
  let parsed;
  try {
    parsed = new URL(String(url || ''));
  } catch {
    return false;
  }
  const normalizedMethod = String(method || '').toUpperCase();
  if (parsed.protocol !== 'https:') return false;
  const source = new URL(DINGDANDAO_SOURCE_URL);
  if (normalizedMethod === 'POST') {
    return parsed.origin === source.origin
      && ['XHR', 'Fetch'].includes(String(resourceType || ''))
      && dingdandaoReadOnlyPostBodyAllowed(parsed.pathname, postData, today);
  }
  if (!['GET', 'HEAD', 'OPTIONS'].includes(normalizedMethod)) return false;
  if (resourceType !== 'Document') return true;
  return parsed.origin === source.origin && parsed.pathname === source.pathname;
}

async function installDingdandaoReadOnlyPolicy(
  config,
  child,
  expectedTargetUrl = null,
) {
  if (typeof WebSocket !== 'function') {
    throw new Error('read_only_policy_websocket_unavailable');
  }
  const target = await waitForBrowserPage(
    config,
    child,
    expectedTargetUrl,
  );
  const socket = new WebSocket(target.webSocketDebuggerUrl);
  const pending = new Map();
  let nextId = 1;
  let closed = false;

  await new Promise((resolvePromise, reject) => {
    const timer = setTimeout(() => reject(new Error('read_only_policy_connect_timeout')), 5000);
    socket.addEventListener('open', () => {
      clearTimeout(timer);
      resolvePromise();
    }, { once: true });
    socket.addEventListener('error', () => {
      clearTimeout(timer);
      reject(new Error('read_only_policy_connect_failed'));
    }, { once: true });
  });

  const send = (method, params = {}) => new Promise((resolvePromise, reject) => {
    if (closed || socket.readyState !== WebSocket.OPEN) {
      reject(new Error('read_only_policy_connection_closed'));
      return;
    }
    const id = nextId++;
    pending.set(id, { resolvePromise, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });

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
    if (message.method !== 'Fetch.requestPaused') return;
    const paused = message.params || {};
    const allowed = isDingdandaoReadOnlyRequestAllowed({
      url: paused.request?.url,
      method: paused.request?.method,
      resourceType: paused.resourceType,
      postData: paused.request?.postData,
    });
    const command = allowed ? 'Fetch.continueRequest' : 'Fetch.failRequest';
    const params = allowed
      ? { requestId: paused.requestId }
      : { requestId: paused.requestId, errorReason: 'BlockedByClient' };
    send(command, params).catch(() => undefined);
  });

  socket.addEventListener('close', () => {
    closed = true;
    for (const request of pending.values()) {
      request.reject(new Error('read_only_policy_connection_closed'));
    }
    pending.clear();
  });

  try {
    await send('Network.enable');
    await send('Network.setCacheDisabled', { cacheDisabled: true });
    await send('Page.enable');
    await send('Fetch.enable', {
      patterns: [{ urlPattern: '*', requestStage: 'Request' }],
    });
    await send('Browser.setDownloadBehavior', { behavior: 'deny' });
    const navigation = await send('Page.navigate', { url: DINGDANDAO_SOURCE_URL });
    if (navigation.errorText) throw new Error('read_only_navigation_failed');
  } catch (error) {
    closed = true;
    socket.close();
    throw error;
  }

  return {
    close() {
      if (closed) return;
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
    || ((action, payload) => bridge(config, action, payload));
  const startBrowserCall = dependencies.startBrowser || startBrowser;
  const stopBrowserCall = dependencies.stopBrowser || stopBrowser;
  const installReadOnlyPolicyCall = dependencies.installReadOnlyPolicy
    || installDingdandaoReadOnlyPolicy;
  const assertCdpPortAvailableCall = dependencies.assertCdpPortAvailable
    || assertCdpPortAvailable;
  const nowCall = dependencies.now || (() => new Date());
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
  const profileLeases = new Map();

  async function closeSession(session, { seal = true } = {}) {
    clearTimeout(session.timeout);
    session.guard?.close();
    await stopBrowserCall(session.browser);
    if (seal) await vault.seal(session.profileId);
    sessions.delete(session.key);
  }

  async function closeCollectionLease(session) {
    clearTimeout(session.timeout);
    if (session.leaseClosed === true) return;
    session.leaseClosed = true;
    session.state = 'lease_closed';
  }

  async function closeProfileLease(
    session,
    outcome,
    receiptKind = 'profile_lease_closed',
  ) {
    clearTimeout(session.timeout);
    session.state = 'closing';
    try {
      session.guard?.close();
      await stopBrowserCall(session.browser);
      await vault.seal(session.profileId);
      session.state = 'closed';
    } catch (error) {
      session.state = 'quarantined';
      throw error;
    }
    try {
      return await receipts.append(receiptKind, {
        profile_lease_id: session.profileLeaseId,
        profile_id: session.profileId,
        platform: session.platform,
        tenant_id: session.tenantId,
        hotel_id: session.hotelId,
        owner_user_id: session.ownerUserId,
        target_date: session.targetDate,
        lease_kind: session.leaseKind,
        access_mode: session.accessMode,
        outcome,
        session_owner: 'gateway_profile_lease',
        owned_browser_closed: true,
        user_browser_closed: false,
        profile_encrypted_at_rest: true,
      });
    } finally {
      profileLeases.delete(session.profileLeaseId);
    }
  }

  async function expireProfileLease(session) {
    const nestedCollections = [...sessions.values()]
      .filter((candidate) => candidate.kind === 'dingdandao_collection'
        && candidate.profileLeaseId === session.profileLeaseId);
    for (const collection of nestedCollections) {
      try {
        await finalizeCollectionSession(
          collection,
          'window_expired',
          'collection_window_timeout',
        );
      } catch {
        sessions.delete(collection.key);
      }
    }
    await closeProfileLease(session, 'window_expired', 'profile_lease_timeout');
  }

  async function completeCollectionLifecycle(session, outcome, receiptKind = 'collection_profile_closed') {
    const completed = validateCollectionCompletion(
      await bridgeCall('complete_dingdandao_collection', {
        claim_id: session.claimId,
        collection_session_id: session.collectionSessionId,
        profile_id: session.profileId,
        outcome,
      }),
      session,
      outcome,
    );
    session.state = 'server_completed';
    const receipt = await receipts.append(receiptKind, {
      claim_id: session.claimId,
      collection_session_id: session.collectionSessionId,
      profile_id: session.profileId,
      platform: session.platform,
      tenant_id: session.tenantId,
      hotel_id: session.hotelId,
      owner_user_id: session.ownerUserId,
      target_date: session.targetDate,
      access_mode: session.accessMode,
      outcome,
      existing_browser_required: true,
      existing_browser_closed: false,
      profile_mutated: false,
      data_status: completed.data_status,
    });
    sessions.delete(session.key);
    return { completed, receipt };
  }

  async function finalizeCollectionSession(
    session,
    outcome,
    receiptKind = 'collection_profile_closed',
  ) {
    await closeCollectionLease(session);
    return await completeCollectionLifecycle(session, outcome, receiptKind);
  }

  const server = createServer(async (request, response) => {
    const url = new URL(request.url || '/', `http://${config.bindAddress}:${config.port}`);
    try {
      if (request.method === 'GET' && url.pathname === '/health') {
        const activeLoginSessions = [...sessions.values()]
          .filter((session) => session.kind === 'login').length;
        const activeCollectionSessions = [...sessions.values()]
          .filter((session) => session.kind === 'dingdandao_collection').length;
        const activeBrowserSessions = [...sessions.values()]
          .filter((session) => session.kind === 'login' && session.browser).length
          + [...profileLeases.values()]
            .filter((session) => session.browser).length;
        jsonResponse(response, 200, {
          status: 'ok',
          bind: config.bindAddress,
          encrypted_profile_store: true,
          receipt_chain_valid: await receipts.verify(),
          active_login_sessions: activeLoginSessions,
          active_collection_sessions: activeCollectionSessions,
          active_profile_leases: profileLeases.size,
          active_browser_sessions: activeBrowserSessions,
          profile_lease_contract: 'dingdandao_profile_lease.v1',
          browser_autostart: false,
          read_only_policy_runtime: typeof WebSocket === 'function',
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/login/open') {
        const login = validateLoginRequest(await jsonBody(request));
        if (sessions.size > 0 || profileLeases.size > 0) {
          throw new GatewayError('gateway_login_capacity_busy', 409);
        }
        const validated = await bridgeCall('validate_login', {
          profile_id: login.profileId,
          session_id: login.sessionId,
          ticket: login.ticket,
        });
        if (validated?.profile?.platform !== login.platform || validated?.login_entry?.validated !== true) {
          throw new Error('login_entry_scope_mismatch');
        }
        const profilePath = await vault.restore(login.profileId);
        const browser = await startBrowserCall(config, profilePath, login.platform);
        const session = {
          ...login,
          kind: 'login',
          key: login.sessionId,
          ticketHash: sha256(login.ticket),
          browser,
          openedAt: new Date().toISOString(),
          expiresAt: new Date(Date.now() + config.loginTtlSeconds * 1000).toISOString(),
        };
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
            // Keep capacity fail-closed if the Profile could not be sealed.
          }
        }, config.loginTtlSeconds * 1000);
        session.timeout.unref();
        sessions.set(login.sessionId, session);
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

      if (request.method === 'POST' && url.pathname === '/v1/profile-lease/open') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const body = await jsonBody(request);
        assertNoSensitiveMaterial(body);
        const lease = validateProfileLeaseOpenRequest(body);
        const now = nowCall();
        if (!(now instanceof Date)
          || !Number.isFinite(now.getTime())
          || lease.targetDate !== shanghaiToday(now)
        ) {
          throw new GatewayError('profile_lease_target_date_not_today', 422);
        }
        if (sessions.size > 0 || profileLeases.size > 0) {
          throw new GatewayError('gateway_profile_lease_capacity_busy', 409);
        }
        const action = lease.leaseKind === 'binding_identity'
          ? 'validate_dingdandao_binding_lease'
          : 'validate_dingdandao_collection';
        validateProfileLeaseScope(
          await bridgeCall(action, {
            profile_id: lease.profileId,
            tenant_id: lease.tenantId,
            hotel_id: lease.hotelId,
            owner_user_id: lease.ownerUserId,
            target_date: lease.targetDate,
          }),
          lease,
        );

        const profileLeaseId = `cbpl_${randomBytes(18).toString('base64url')}`;
        const ownershipTargetUrl = `about:blank#suxios-${profileLeaseId}`;
        const expiresAt = new Date(
          now.getTime() + config.collectionTtlSeconds * 1000,
        ).toISOString();
        let browser = null;
        let guard = null;
        let restored = false;
        try {
          await assertCdpPortAvailableCall(config);
          const profilePath = await vault.restore(lease.profileId);
          restored = true;
          browser = await startBrowserCall(
            config,
            profilePath,
            lease.platform,
            ownershipTargetUrl,
          );
          guard = await installReadOnlyPolicyCall(
            config,
            browser,
            ownershipTargetUrl,
          );
        } catch (error) {
          guard?.close();
          let cleanupError = null;
          let browserStopped = browser === null;
          if (browser) {
            try {
              await stopBrowserCall(browser);
              browserStopped = true;
            } catch (stopError) {
              cleanupError = stopError;
            }
          }
          if (restored && browserStopped) {
            try {
              await vault.seal(lease.profileId);
            } catch (sealError) {
              cleanupError = cleanupError || sealError;
            }
          }
          if (cleanupError !== null) {
            profileLeases.set(profileLeaseId, {
              ...lease,
              kind: 'dingdandao_profile_lease',
              profileLeaseId,
              browser,
              guard,
              state: 'quarantined',
              openedAt: now.toISOString(),
              expiresAt,
              timeout: null,
            });
            throw new GatewayError(
              'profile_lease_start_cleanup_failed',
              500,
            );
          }
          throw new GatewayError(publicError(error), 422);
        }
        const session = {
          ...lease,
          kind: 'dingdandao_profile_lease',
          profileLeaseId,
          browser,
          guard,
          state: 'open',
          openedAt: now.toISOString(),
          expiresAt,
          timeout: null,
        };
        session.timeout = setTimeout(async () => {
          try {
            await expireProfileLease(session);
          } catch {
            // Keep the lease fail-closed when its owned Profile cannot be resealed.
          }
        }, config.collectionTtlSeconds * 1000);
        session.timeout.unref();
        profileLeases.set(profileLeaseId, session);
        try {
          await receipts.append('profile_lease_opened', {
            profile_lease_id: profileLeaseId,
            profile_id: lease.profileId,
            platform: lease.platform,
            tenant_id: lease.tenantId,
            hotel_id: lease.hotelId,
            owner_user_id: lease.ownerUserId,
            target_date: lease.targetDate,
            lease_kind: lease.leaseKind,
            access_mode: lease.accessMode,
            status: 'open',
            session_owner: 'gateway_profile_lease',
            external_browser_required: false,
            user_browser_closed: false,
          });
        } catch {
          try {
            await closeProfileLease(
              session,
              'failed',
              'profile_lease_open_receipt_failed',
            );
          } catch {
            // A stop or seal failure keeps the lease in capacity fail-closed.
          }
          throw new GatewayError('profile_lease_open_receipt_failed', 500);
        }
        jsonResponse(response, 201, {
          status: 'profile_lease_open',
          profile_lease_id: profileLeaseId,
          profile_id: lease.profileId,
          platform: lease.platform,
          tenant_id: lease.tenantId,
          hotel_id: lease.hotelId,
          owner_user_id: lease.ownerUserId,
          target_date: lease.targetDate,
          lease_kind: lease.leaseKind,
          access_mode: lease.accessMode,
          expires_at: expiresAt,
          session_owner: 'gateway_profile_lease',
          browser_started: true,
          profile_restored: true,
          read_only_enforced: true,
          external_browser_required: false,
          user_browser_closed: false,
          sensitive_values_exposed: false,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/profile-lease/close') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const body = await jsonBody(request);
        assertNoSensitiveMaterial(body);
        const lease = validateProfileLeaseCloseRequest(body);
        const session = profileLeases.get(lease.profileLeaseId);
        if (!session
          || session.profileId !== lease.profileId
          || session.platform !== lease.platform
        ) {
          throw new GatewayError('active_profile_lease_not_found', 404);
        }
        const nestedCollectionActive = [...sessions.values()]
          .some((candidate) => candidate.kind === 'dingdandao_collection'
            && candidate.profileLeaseId === lease.profileLeaseId);
        if (nestedCollectionActive) {
          throw new GatewayError('profile_lease_collection_active', 409);
        }
        let receipt;
        try {
          receipt = await closeProfileLease(session, lease.outcome);
        } catch {
          throw new GatewayError('profile_lease_close_failed', 500);
        }
        jsonResponse(response, 200, {
          status: 'profile_lease_closed',
          profile_lease_id: lease.profileLeaseId,
          profile_id: lease.profileId,
          platform: lease.platform,
          owned_browser_closed: true,
          profile_encrypted_at_rest: true,
          user_browser_closed: false,
          sensitive_values_exposed: false,
          receipt_id: receipt.receipt_id,
          receipt_hash: receipt.receipt_hash,
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
          || session.profileId !== login.profileId
          || session.platform !== login.platform
          || (login.ticket !== null && session.ticketHash !== sha256(login.ticket))) {
          throw new GatewayError('active_login_session_not_found', 404);
        }
        await closeSession(session);
        const sessionExpiresAt = new Date(Date.now() + config.profileSessionTtlSeconds * 1000)
          .toISOString()
          .slice(0, 19)
          .replace('T', ' ');
        const profile = await bridgeCall('complete_login', {
          profile_id: login.profileId,
          session_id: login.sessionId,
          ticket: session.ticket,
          session_expires_at: sessionExpiresAt,
        });
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

      if (request.method === 'POST' && url.pathname === '/v1/collection/open') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const collectionBody = await jsonBody(request);
        assertNoSensitiveMaterial(collectionBody);
        const collection = validateCollectionOpenRequest(collectionBody);
        const now = nowCall();
        if (!(now instanceof Date)
          || !Number.isFinite(now.getTime())
          || collection.targetDate !== shanghaiToday(now)
        ) {
          throw new GatewayError('collection_target_date_not_today', 422);
        }
        if (sessions.size > 0) {
          throw new GatewayError('gateway_collection_capacity_busy', 409);
        }
        const profileLease = [...profileLeases.values()]
          .find((candidate) => candidate.state === 'open'
            && candidate.leaseKind === 'daily_collection'
            && candidate.profileId === collection.profileId
            && candidate.platform === collection.platform
            && candidate.tenantId === collection.tenantId
            && candidate.hotelId === collection.hotelId
            && candidate.ownerUserId === collection.ownerUserId
            && candidate.targetDate === collection.targetDate);
        if (!profileLease) {
          throw new GatewayError('gateway_profile_lease_required', 409);
        }
        const collectionSessionId = `cbcs_${randomBytes(18).toString('base64url')}`;
        const expiresAt = new Date(
          now.getTime() + config.collectionTtlSeconds * 1000,
        ).toISOString();
        const session = {
          ...collection,
          kind: 'dingdandao_collection',
          key: collectionSessionId,
          collectionSessionId,
          profileLeaseId: profileLease.profileLeaseId,
          claimId: null,
          providerHotelName: null,
          leaseClosed: false,
          state: 'claiming',
          openedAt: now.toISOString(),
          expiresAt,
          timeout: null,
        };
        sessions.set(collectionSessionId, session);

        try {
          const claimed = await bridgeCall('claim_dingdandao_collection', {
            profile_id: collection.profileId,
            collection_session_id: collectionSessionId,
            tenant_id: collection.tenantId,
            hotel_id: collection.hotelId,
            owner_user_id: collection.ownerUserId,
            target_date: collection.targetDate,
            collection_kind: collection.collectionKind,
            access_mode: collection.accessMode,
            window_expires_at: expiresAt,
          });
          const validatedClaim = validateCollectionClaim(
            claimed,
            collection,
            collectionSessionId,
            expiresAt,
          );
          session.claimId = validatedClaim.claimId;
          session.providerHotelName = validatedClaim.providerHotelName;
          session.state = 'claimed';
        } catch (error) {
          sessions.delete(collectionSessionId);
          throw new GatewayError(publicError(error), 422);
        }

        try {
          session.state = 'open';
          await receipts.append('collection_window_opened', {
            claim_id: session.claimId,
            collection_session_id: collectionSessionId,
            profile_id: collection.profileId,
            platform: collection.platform,
            tenant_id: collection.tenantId,
            hotel_id: collection.hotelId,
            owner_user_id: collection.ownerUserId,
            target_date: collection.targetDate,
            access_mode: collection.accessMode,
            status: 'open',
            collection_transport: 'existing_session_direct_post',
            browser_started: false,
            profile_mutated: false,
            profile_lease_id: profileLease.profileLeaseId,
            session_owner: 'gateway_profile_lease',
            external_browser_required: false,
          });
        } catch {
          try {
            await finalizeCollectionSession(
              session,
              'cancelled',
              'collection_open_failed',
            );
          } catch {
            throw new GatewayError('collection_claim_completion_failed', 500);
          }
          throw new GatewayError('collection_open_receipt_failed', 500);
        }

        session.timeout = setTimeout(async () => {
          try {
            await finalizeCollectionSession(
              session,
              'window_expired',
              'collection_window_timeout',
            );
          } catch {
            // Keep the claimed session fail-closed for an idempotent completion retry.
          }
        }, config.collectionTtlSeconds * 1000);
        session.timeout.unref();
        jsonResponse(response, 201, {
          status: 'collection_open',
          claim_id: session.claimId,
          collection_session_id: collectionSessionId,
          profile_id: collection.profileId,
          platform: collection.platform,
          tenant_id: collection.tenantId,
          hotel_id: collection.hotelId,
          owner_user_id: collection.ownerUserId,
          target_date: collection.targetDate,
          collection_kind: collection.collectionKind,
          source_url: DINGDANDAO_SOURCE_URL,
          source_scope: 'today_only',
          access_mode: 'read_only',
          read_only_enforced: true,
          collection_transport: 'existing_session_direct_post',
          existing_session_required: true,
          expires_at: expiresAt,
          browser_started: false,
          profile_mutated: false,
          profile_lease_id: profileLease.profileLeaseId,
          session_owner: 'gateway_profile_lease',
          external_browser_required: false,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/collection/close') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const collectionBody = await jsonBody(request);
        assertNoSensitiveMaterial(collectionBody);
        const collection = validateCollectionCloseRequest(collectionBody);
        const session = sessions.get(collection.collectionSessionId);
        if (!session
          || session.kind !== 'dingdandao_collection'
          || session.profileId !== collection.profileId
          || session.platform !== collection.platform
        ) {
          throw new GatewayError('active_collection_session_not_found', 404);
        }
        let finalized;
        try {
          finalized = await finalizeCollectionSession(session, collection.outcome);
        } catch {
          throw new GatewayError('collection_claim_completion_failed', 500);
        }
        jsonResponse(response, 200, {
          status: 'collection_closed',
          collection_session_id: collection.collectionSessionId,
          browser_started: false,
          existing_browser_closed: false,
          profile_mutated: false,
          data_status: finalized.completed.data_status,
          receipt_id: finalized.receipt.receipt_id,
          receipt_hash: finalized.receipt.receipt_hash,
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
          profile_id: assertOpaque(body.profile_id, PROFILE_ID_PATTERN, 'profile_id_invalid'),
          platform: assertOpaque(body.platform, OTA_RECEIPT_PLATFORM_PATTERN, 'platform_invalid'),
          tenant_id: Number.parseInt(body.tenant_id, 10),
          hotel_id: Number.parseInt(body.hotel_id, 10),
          target_date: assertOpaque(body.target_date, /^\d{4}-\d{2}-\d{2}$/, 'target_date_invalid'),
          source_method: assertOpaque(body.source_method, /^cloud_browser_profile$/, 'source_method_invalid'),
          status: assertOpaque(body.status, /^(saved|partial|failed|blocked)$/, 'receipt_status_invalid'),
          identity_verified: body.identity_verified === true,
          saved_count: Number.parseInt(body.saved_count, 10),
          readback_count: Number.parseInt(body.readback_count, 10),
          field_facts_sha256: assertOpaque(body.field_facts_sha256, /^[a-f0-9]{64}$/, 'field_facts_hash_invalid'),
          failure_stage: body.failure_stage ? safeReason(body.failure_stage, 'collection_failed') : null,
        };
        if (!Number.isInteger(payload.tenant_id) || !Number.isInteger(payload.hotel_id)
          || !Number.isInteger(payload.saved_count) || !Number.isInteger(payload.readback_count)
          || payload.tenant_id <= 0 || payload.hotel_id <= 0
          || payload.saved_count < 0 || payload.readback_count < 0) {
          throw new Error('receipt_numeric_scope_invalid');
        }
        if (payload.status === 'saved'
          && (!payload.identity_verified || payload.saved_count !== payload.readback_count)) {
          throw new Error('receipt_truth_gate_failed');
        }
        const receipt = await receipts.append('collection_result', payload);
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
