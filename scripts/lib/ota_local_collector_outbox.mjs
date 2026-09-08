import { createHash, randomUUID } from 'node:crypto';
import { mkdir, open, readdir, readFile, rename, unlink } from 'node:fs/promises';
import { resolve } from 'node:path';

export const DEFAULT_OUTBOX_DIR = resolve('storage/local_collector/outbox');
export const MAX_RESULT_ENVELOPE_BYTES = 3 * 1024 * 1024;
export const DEFAULT_REQUEST_TIMEOUT_MS = 30_000;
const OUTBOX_SCHEMA = 'ota_local_collector_outbox.v1';
const RESULT_ID_PATTERN = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/u;
const OUTBOX_FILE_PATTERN = /^task_\d+_[a-f0-9-]{36}\.json(?:\.tmp)?$/u;

export function collectorError(code, message, { retryable = true, status } = {}) {
  const error = new Error(message);
  error.code = code;
  error.retryable = retryable;
  if (status !== undefined) error.status = status;
  return error;
}

export function hashResultJson(resultJson) {
  return createHash('sha256').update(resultJson, 'utf8').digest('hex');
}

/** A timeout includes reading the response body, not just receiving headers. */
export async function collectorApiRequest(server, path, {
  method = 'GET', body, device, timeoutMs = DEFAULT_REQUEST_TIMEOUT_MS, fetchFn = fetch,
} = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (device) {
    headers['X-Collector-Device-Id'] = device.device_public_id;
    headers.Authorization = `Collector ${device.device_token}`;
  }
  const controller = new AbortController();
  const boundedTimeout = Math.max(1, Math.min(120_000, Number(timeoutMs) || DEFAULT_REQUEST_TIMEOUT_MS));
  const timeout = setTimeout(() => controller.abort(), boundedTimeout);
  try {
    const response = await fetchFn(`${server}${path}`, {
      method, headers, signal: controller.signal,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const responseText = await response.text();
    if (!response.ok) {
      const status = response.status;
      throw collectorError(`http_${status}`, `服务器拒绝本机采集请求（HTTP ${status}）。`, {
        status, retryable: status >= 500 || [408, 425, 429].includes(status),
      });
    }
    let decoded;
    try {
      decoded = JSON.parse(responseText);
    } catch {
      throw collectorError('invalid_response', '服务器未返回有效 JSON，未确认本机结果已接收。', { status: response.status });
    }
    if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) {
      throw collectorError('invalid_response', '服务器响应格式无效，未确认本机结果已接收。', { status: response.status });
    }
    const status = Number(decoded.code) >= 400 ? Number(decoded.code) : response.status;
    if (!response.ok || status >= 400) {
      // Do not surface arbitrary response bodies or messages: they may echo credentials.
      throw collectorError(`http_${status}`, `服务器拒绝本机采集请求（HTTP ${status}）。`, {
        status, retryable: status >= 500 || [408, 425, 429].includes(status),
      });
    }
    return decoded.data ?? decoded;
  } catch (error) {
    if (controller.signal.aborted) {
      throw collectorError('request_timeout', '本机采集请求超时，结果仍保留在本机待回传。');
    }
    if (error?.code && typeof error.retryable === 'boolean') throw error;
    throw collectorError('network_error', '本机采集请求连接失败，结果仍保留在本机待回传。');
  } finally {
    clearTimeout(timeout);
  }
}

export function sanitizeBusinessValue(value, key = '') {
  const normalizedKey = String(key)
    .replace(/([a-z0-9])([A-Z])/gu, '$1_$2')
    .replace(/([A-Z]+)([A-Z][a-z])/gu, '$1_$2')
    .toLowerCase().replace(/[^a-z0-9]+/gu, '_').replace(/^_+|_+$/gu, '');
  if (/(^|_)(?:cookies?|tokens?|authorization|password|secret|api_key|headers?|profile_dir|profile_path|local_storage|session_storage|webhook|raw_(?:response|request|data|session))($|_)/u.test(normalizedKey)
    || /(^|_)(?:cookie|token|auth|session|profile)_(?:value|token|cookie|cookies|header|headers|path|dir|data|storage|raw)($|_)/u.test(normalizedKey)
    || /^(?:cookie|token|auth|session|profile)(?:value|token|cookie|cookies|header|headers|path|dir|data|storage|raw)$/u.test(normalizedKey)) {
    return undefined;
  }
  if (Array.isArray(value)) return value.map(item => sanitizeBusinessValue(item)).filter(item => item !== undefined);
  if (value && typeof value === 'object') {
    const result = {};
    for (const [childKey, childValue] of Object.entries(value)) {
      const sanitized = sanitizeBusinessValue(childValue, childKey);
      if (sanitized !== undefined) result[childKey] = sanitized;
    }
    return result;
  }
  if (typeof value === 'string') {
    if (/\b(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key)\s*[:=]/iu.test(value)
      || /\bbearer\s+[A-Za-z0-9._~+/=:-]{8,}/iu.test(value)) return '[敏感内容已在本机移除]';
    // Size limits apply to the complete envelope; business text must not be truncated.
    return value;
  }
  return value;
}

function assertPendingResult(entry) {
  if (entry?.schema_version !== OUTBOX_SCHEMA
    || !Number.isSafeInteger(entry.task_id) || entry.task_id < 1
    || !Number.isSafeInteger(entry.attempt) || entry.attempt < 1
    || !RESULT_ID_PATTERN.test(entry.result_id)
    || typeof entry.result_json !== 'string'
    || !/^[a-f0-9]{64}$/u.test(entry.server_hash)
    || typeof entry.device_public_id !== 'string' || !entry.device_public_id
    || typeof entry.created_at !== 'string' || !entry.created_at
    || entry.result_hash !== hashResultJson(entry.result_json)) {
    throw collectorError('outbox_invalid', '本机待回传记录校验失败，已保留证据并停止新采集。', { retryable: false });
  }
  try {
    const result = JSON.parse(entry.result_json);
    if (!result || typeof result !== 'object' || Array.isArray(result) || typeof result.success !== 'boolean') throw new Error();
  } catch {
    throw collectorError('outbox_invalid', '本机待回传业务结果无效，已停止新采集。', { retryable: false });
  }
}

function entryPath(directory, entry) {
  return resolve(directory, `task_${entry.task_id}_${entry.result_id}.json`);
}

async function writeEntry(path, entry) {
  const temporaryPath = `${path}.tmp`;
  const file = await open(temporaryPath, 'w', 0o600);
  try {
    await file.writeFile(`${JSON.stringify(entry)}\n`, 'utf8');
    await file.sync();
  } finally {
    await file.close();
  }
  await rename(temporaryPath, path);
}

export async function persistPendingResult(device, task, result, {
  directory = DEFAULT_OUTBOX_DIR, resultId = randomUUID(), now = new Date().toISOString(), blocked = null,
} = {}) {
  const resultJson = JSON.stringify(sanitizeBusinessValue(result));
  const entry = {
    schema_version: OUTBOX_SCHEMA,
    task_id: Number(task.id),
    attempt: Number(task.attempt),
    device_public_id: String(device.device_public_id || ''),
    server_hash: hashResultJson(String(device.server || '').replace(/\/+$/u, '')),
    scope: {
      account_id: Number(task.account_id) || null,
      system_hotel_id: Number(task.system_hotel_id),
      platform: String(task.platform || ''),
      platform_hotel_id: String(task.platform_hotel_id || ''),
      data_date: String(task.data_date || ''),
    },
    result_id: resultId,
    result_hash: hashResultJson(resultJson),
    result_json: resultJson,
    created_at: now,
    ...(blocked ? { blocked: { code: blocked.code } } : {}),
  };
  assertPendingResult(entry);
  await mkdir(directory, { recursive: true });
  await writeEntry(entryPath(directory, entry), entry);
  return entry;
}

export async function readPendingResults(directory = DEFAULT_OUTBOX_DIR) {
  let files;
  try {
    files = await readdir(directory, { withFileTypes: true });
  } catch (error) {
    if (error?.code === 'ENOENT') return [];
    throw error;
  }
  const entries = new Map();
  // A crash between sync and rename must not silently cause another capture.
  for (const file of files.filter(item => item.isFile() && OUTBOX_FILE_PATTERN.test(item.name)).sort((a, b) => a.name.localeCompare(b.name))) {
    const path = resolve(directory, file.name);
    let entry;
    try {
      entry = JSON.parse(await readFile(path, 'utf8'));
    } catch {
      throw collectorError('outbox_invalid', '本机待回传文件损坏或未写完，已保留证据并停止新采集。', { retryable: false });
    }
    assertPendingResult(entry);
    if (path !== entryPath(directory, entry) && path !== `${entryPath(directory, entry)}.tmp`) {
      throw collectorError('outbox_invalid', '本机待回传文件与任务不一致，已停止新采集。', { retryable: false });
    }
    entries.set(entry.result_id, entry);
    if (file.name.endsWith('.tmp')) await rename(path, entryPath(directory, entry));
  }
  return [...entries.values()].sort((left, right) => left.created_at.localeCompare(right.created_at));
}

export function acceptedDelivery(response, entry) {
  const receipt = response?.delivery;
  const scope = entry.scope || {};
  const scopeMatches = [['system_hotel_id', scope.system_hotel_id], ['platform', scope.platform],
    ['business_date', scope.data_date], ['platform_hotel_id', scope.platform_hotel_id]]
    .every(([key, expected]) => receipt?.[key] === undefined || String(receipt[key]) === String(expected));
  return receipt?.status === 'accepted'
    && scopeMatches
    && response?.reconciliation?.status !== 'unknown'
    && response?.status !== 'result_unknown'
    && Number(receipt.task_id) === entry.task_id
    && Number(receipt.attempt) === entry.attempt
    && receipt.result_id === entry.result_id
    && receipt.result_hash === entry.result_hash;
}

function blockedError(entry) {
  const code = entry.blocked?.code || 'outbox_blocked';
  return collectorError(code, `本机任务 #${entry.task_id} 的结果仍保留，回传已阻塞（${code}）；修复权限或范围后可执行 retry-result --task-id=${entry.task_id} 核对原结果，当前不会重新采集。`, { retryable: false });
}

/** Explicit operator recovery revalidates one immutable result with the server. */
export async function retryBlockedResult(device, taskId, options = {}) {
  if (!Number.isSafeInteger(taskId) || taskId < 1) throw collectorError('task_id_invalid', '必须指定原任务编号。', { retryable: false });
  const matches = (await readPendingResults(options.directory)).filter(entry => entry.task_id === taskId
    && (!options.resultId || entry.result_id === options.resultId));
  if (matches.length !== 1) throw collectorError('outbox_selection_ambiguous', '请指定唯一原任务与结果编号，未修改任何待回传记录。', { retryable: false });
  const entry = { ...matches[0] };
  if (entry.blocked && !['http_401', 'http_403', 'http_409'].includes(entry.blocked.code)) throw blockedError(entry);
  // Keep the on-disk blocked entry until a matching receipt is accepted.
  delete entry.blocked;
  return deliverPendingResult(device, entry, options);
}

export async function deliverPendingResult(device, entry, {
  directory = DEFAULT_OUTBOX_DIR, request = collectorApiRequest,
} = {}) {
  assertPendingResult(entry);
  if (entry.device_public_id !== device.device_public_id
    || entry.server_hash !== hashResultJson(String(device.server || '').replace(/\/+$/u, ''))) {
    throw collectorError('outbox_device_mismatch', '本机待回传记录属于其他设备或服务器，已保留证据并停止新采集。', { retryable: false });
  }
  if (entry.blocked) throw blockedError(entry);
  try {
    const identity = { result_id: entry.result_id, result_hash: entry.result_hash, attempt: entry.attempt };
    const resume = await request(device.server, `/api/ota-local-collector/tasks/${entry.task_id}/resume-upload`, {
      method: 'POST', device, body: identity,
    });
    let response = resume;
    if (!acceptedDelivery(resume, entry)) {
      if (resume?.status !== 'upload_ready' || Number(resume.task_id) !== entry.task_id
        || resume.result_id !== entry.result_id || resume.result_hash !== entry.result_hash || Number(resume.attempt) !== entry.attempt
        || typeof resume.lease_token !== 'string' || !resume.lease_token
        || typeof resume.lease_expires_at !== 'string' || !resume.lease_expires_at) {
        throw collectorError('result_ack_invalid', '服务器未返回匹配的上传许可，结果仍保留在本机。');
      }
      const body = { ...identity, result_json: entry.result_json, lease_token: resume.lease_token };
      if (Buffer.byteLength(JSON.stringify(body), 'utf8') > MAX_RESULT_ENVELOPE_BYTES) {
        throw collectorError('result_envelope_too_large', '回传请求超过 3 MB，完整脱敏业务结果已保存在本机，未截断上传。', { retryable: false, status: 413 });
      }
      response = await request(device.server, `/api/ota-local-collector/tasks/${entry.task_id}/result`, {
        method: 'POST', device, body,
      });
    }
    if (!acceptedDelivery(response, entry)) {
      throw collectorError('result_ack_invalid', '服务器未确认接收同一任务和同一结果，结果仍保留在本机。');
    }
    await unlink(entryPath(directory, entry));
    return response;
  } catch (error) {
    if (error?.retryable === false || [400, 401, 403, 404, 409, 410, 413, 422].includes(Number(error?.status))) {
      const blocked = { ...entry, blocked: { code: /^\w+$/u.test(error.code || '') ? error.code : `http_${Number(error.status) || 'rejected'}` } };
      await writeEntry(entryPath(directory, entry), blocked);
      throw blockedError(blocked);
    }
    throw error;
  }
}

/** Drain one item per cycle, before heartbeat can expire/requeue its task. */
export async function flushPendingResults(device, options = {}) {
  const entries = await readPendingResults(options.directory);
  if (entries.length === 0) return { processed: false };
  const response = await deliverPendingResult(device, entries[0], options);
  return { processed: true, remaining: entries.length - 1, response };
}
