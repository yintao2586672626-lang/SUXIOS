#!/usr/bin/env node
import {
  createCipheriv,
  createDecipheriv,
  createHash,
  randomBytes,
  timingSafeEqual,
} from 'node:crypto';
import { createReadStream, createWriteStream } from 'node:fs';
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
const TICKET_PATTERN = /^[A-Za-z0-9_-]{32,96}$/;
const PLATFORM_PATTERN = /^(ctrip|meituan)$/;
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
    browserExecutable: env.SUXIOS_CLOUD_BROWSER_EXECUTABLE || '/usr/bin/chromium',
    display: env.SUXIOS_CLOUD_BROWSER_DISPLAY || ':99',
    cdpPort: Number.parseInt(env.SUXIOS_CLOUD_BROWSER_CDP_PORT || '9223', 10),
    viewerUrl: env.SUXIOS_CLOUD_BROWSER_VIEWER_URL || 'http://127.0.0.1:6080/vnc.html?autoconnect=true&resize=scale',
    loginTtlSeconds: Math.min(900, Math.max(60, Number.parseInt(env.SUXIOS_CLOUD_BROWSER_LOGIN_TTL_SECONDS || '900', 10))),
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
    platform: assertOpaque(body.platform, PLATFORM_PATTERN, 'platform_invalid'),
  };
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

async function startBrowser(config, profilePath, platform) {
  const loginUrl = platform === 'meituan'
    ? 'https://me.meituan.com/ebooking/'
    : 'https://ebooking.ctrip.com/home/mainland';
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

function publicError(error) {
  return safeReason(error?.message || error);
}

export async function createGateway(env = process.env) {
  const config = loadConfig(env);
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

  async function closeSession(session, { seal = true } = {}) {
    clearTimeout(session.timeout);
    await stopBrowser(session.browser);
    if (seal) await vault.seal(session.profileId);
    sessions.delete(session.sessionId);
  }

  const server = createServer(async (request, response) => {
    const url = new URL(request.url || '/', `http://${config.bindAddress}:${config.port}`);
    try {
      if (request.method === 'GET' && url.pathname === '/health') {
        jsonResponse(response, 200, {
          status: 'ok',
          bind: config.bindAddress,
          encrypted_profile_store: true,
          receipt_chain_valid: await receipts.verify(),
          active_login_sessions: sessions.size,
          browser_autostart: false,
        });
        return;
      }

      if (request.method === 'POST' && url.pathname === '/v1/login/open') {
        const login = validateLoginRequest(await jsonBody(request));
        if (sessions.size > 0) {
          throw new Error('gateway_login_capacity_busy');
        }
        const validated = await bridge(config, 'validate_login', {
          profile_id: login.profileId,
          session_id: login.sessionId,
          ticket: login.ticket,
        });
        if (validated?.profile?.platform !== login.platform || validated?.login_entry?.validated !== true) {
          throw new Error('login_entry_scope_mismatch');
        }
        const profilePath = await vault.restore(login.profileId);
        const browser = await startBrowser(config, profilePath, login.platform);
        const session = {
          ...login,
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
            sessions.delete(session.sessionId);
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

      if (request.method === 'POST' && url.pathname === '/v1/login/complete') {
        if (!authorized(request, controlToken)) {
          jsonResponse(response, 401, { status: 'failed', reason: 'gateway_control_auth_required' });
          return;
        }
        const login = validateLoginRequest(await jsonBody(request));
        const session = sessions.get(login.sessionId);
        if (!session
          || session.profileId !== login.profileId
          || session.platform !== login.platform
          || session.ticketHash !== sha256(login.ticket)) {
          throw new Error('active_login_session_not_found');
        }
        await closeSession(session);
        const sessionExpiresAt = new Date(Date.now() + config.profileSessionTtlSeconds * 1000)
          .toISOString()
          .slice(0, 19)
          .replace('T', ' ');
        const profile = await bridge(config, 'complete_login', {
          profile_id: login.profileId,
          session_id: login.sessionId,
          ticket: login.ticket,
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
          platform: assertOpaque(body.platform, PLATFORM_PATTERN, 'platform_invalid'),
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
      jsonResponse(response, 422, { status: 'failed', reason: publicError(error) });
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
