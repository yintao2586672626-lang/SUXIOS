import { chmod, mkdir, readdir, readFile, stat, unlink, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';
import process from 'node:process';
import { buildOtaMapCollectionPlan } from './lib/ota_field_data_map.mjs';

const COLLECTOR_VERSION = '1.0.0';
const DEFAULT_CONFIG = resolve('storage/local_collector/device.json');
const DEFAULT_POLL_MS = 15_000;
const MAX_UPLOAD_ROWS = 2_000;
// Keep the interactive window within the 15-minute server lease while giving
// an on-site operator enough time for SMS/captcha/account confirmation.
const INTERACTIVE_LOGIN_TIMEOUT_MS = 600_000;
// Failed capture payloads are useful for a short local diagnosis window, but must
// never turn a long-running collector into an unbounded disk consumer.
const LOCAL_RESULT_RETENTION_MS = 7 * 24 * 60 * 60 * 1_000;
const LOCAL_RESULT_MAX_FILES = 100;
const LOCAL_RESULT_MAX_BYTES = 100 * 1024 * 1024;
const LOCAL_RESULT_FILE_PATTERN = /^task_\d+_\d+\.json$/u;
const ORDERED_CORE_SCOPE = 'ota_yesterday_core';
const ORDERED_DEFAULT_SECTIONS = {
  ctrip: ['business_overview', 'traffic_report'],
  meituan: ['traffic', 'orders'],
};
const ORDERED_REQUIRED_FIELDS = {
  ctrip: [
    'order_amount',
    'room_nights',
    'order_count',
    'list_exposure',
    'detail_exposure',
    'flow_rate',
    'order_filling_num',
    'order_submit_num',
  ],
  meituan: [
    'order_amount',
    'room_nights',
    'order_count',
    'list_exposure',
    'detail_exposure',
    'flow_rate',
  ],
};

export function parseArgs(argv) {
  const result = { _: [] };
  for (const value of argv) {
    if (!value.startsWith('--')) {
      result._.push(value);
      continue;
    }
    const [rawKey, ...rest] = value.slice(2).split('=');
    const key = rawKey.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    result[key] = rest.length > 0 ? rest.join('=') : 'true';
  }
  return result;
}

/**
 * Retain only recent, collector-owned failed-result files. This deliberately
 * ignores every other file in the directory so device configuration and manual
 * diagnostic material are never removed by the collector.
 */
export async function pruneLocalResultFiles(
  resultsDir = resolve('storage/local_collector/results'),
  now = Date.now(),
) {
  const directory = resolve(resultsDir);
  let entries;
  try {
    entries = await readdir(directory, { withFileTypes: true });
  } catch (error) {
    if (error?.code === 'ENOENT') return { removed: 0, retained: 0, retained_bytes: 0 };
    throw error;
  }

  const files = [];
  for (const entry of entries) {
    if (!entry.isFile() || !LOCAL_RESULT_FILE_PATTERN.test(entry.name)) continue;
    const path = resolve(directory, entry.name);
    const fileStat = await stat(path);
    files.push({ path, name: entry.name, mtime_ms: fileStat.mtimeMs, size: fileStat.size });
  }

  files.sort((left, right) => right.mtime_ms - left.mtime_ms || right.name.localeCompare(left.name));
  let retained = 0;
  let retainedBytes = 0;
  let removed = 0;
  for (const file of files) {
    const expired = now - file.mtime_ms > LOCAL_RESULT_RETENTION_MS;
    const exceedsCount = retained >= LOCAL_RESULT_MAX_FILES;
    // Keep one newest payload even if it is unusually large so a current
    // failed task remains diagnosable; older payloads then make room for it.
    const exceedsBytes = retained > 0 && retainedBytes + file.size > LOCAL_RESULT_MAX_BYTES;
    if (expired || exceedsCount || exceedsBytes) {
      await unlink(file.path);
      removed += 1;
      continue;
    }
    retained += 1;
    retainedBytes += file.size;
  }
  return { removed, retained, retained_bytes: retainedBytes };
}

export function normalizeServer(value) {
  const text = String(value || '').trim().replace(/\/+$/u, '');
  if (!/^https?:\/\/[^/\s]+(?::\d+)?(?:\/.*)?$/iu.test(text)) {
    throw new Error('服务器地址格式不正确，例如 http://127.0.0.1:8080');
  }
  return text;
}

export function accountProfileDirectoryName(platform, profileKeyHash) {
  const safeHash = String(profileKeyHash || '').toLowerCase().replace(/[^a-f0-9]/gu, '').slice(0, 64);
  if (!/^[a-f0-9]{64}$/u.test(safeHash)) {
    throw new Error('账户 Profile 标识无效，任务已停止。');
  }
  const prefix = platform === 'meituan' ? 'meituan' : 'ctrip';
  return `${prefix}_account_profile_${safeHash}`;
}

export function classifyCaptureFailure(payload = {}, exitCode = 0) {
  const probe = payload?.session_probe && typeof payload.session_probe === 'object'
    ? payload.session_probe
    : {};
  const auth = payload?.auth_status && typeof payload.auth_status === 'object'
    ? payload.auth_status
    : {};
  const raw = String(
    probe.status
      || auth.status
      || payload.error_code
      || payload.status_code
      || (exitCode === 0 ? 'collection_failed' : 'browser_start_failed'),
  ).toLowerCase();
  if (/anti.?bot|captcha|verification|human/u.test(raw)) return 'verification_required';
  if (/login|required|expired|unauthorized|not.?logged/u.test(raw)) return 'login_required';
  if (/permission|forbidden/u.test(raw)) return 'permission_denied';
  if (/identity|mismatch/u.test(raw)) return 'identity_mismatch';
  if (/network|timeout|unavailable/u.test(raw)) return 'network_error';
  return exitCode === 0 ? 'collection_failed' : 'browser_start_failed';
}

export function sessionVerified(payload = {}) {
  const probe = payload?.session_probe && typeof payload.session_probe === 'object'
    ? payload.session_probe
    : {};
  const auth = payload?.auth_status && typeof payload.auth_status === 'object'
    ? payload.auth_status
    : {};
  const identity = payload?.platform_identity_validation && typeof payload.platform_identity_validation === 'object'
    ? payload.platform_identity_validation
    : {};
  const identityStatus = String(identity.status || '').trim().toLowerCase();
  if (['mismatch', 'mismatched', 'hotel_mismatch', 'store_mismatch', 'poi_mismatch'].includes(identityStatus)) {
    return false;
  }
  return probe.collectable === true
    || (
      auth.ok === true
      && ['matched', 'verified', 'hotel_matched', 'store_matched', 'poi_matched'].includes(identityStatus)
    );
}

export function extractSanitizedRows(payload, task) {
  const candidates = [];
  const append = (value, dataType = '') => {
    if (!Array.isArray(value)) return;
    for (const row of value) {
      if (!row || typeof row !== 'object' || Array.isArray(row)) continue;
      candidates.push({ row, dataType });
    }
  };

  if (task.platform === 'ctrip') {
    append(payload.standard_rows);
    append(payload.rows);
    append(payload.business, 'business');
    append(payload.traffic, 'traffic');
  } else {
    append(payload.traffic, 'traffic');
    append(payload.flowAnalysis, 'traffic_analysis');
    append(payload.orders, 'order');
    if (!isOrderedCoreTask(task)) {
      append(payload.order_flow, 'order');
      append(payload.peerRank, 'peer_rank');
      append(payload.searchKeywords, 'search_keyword');
      append(payload.trafficForecast, 'traffic');
      append(payload.ads, 'advertising');
    }
  }

  const rows = [];
  const seen = new Set();
  for (const candidate of candidates) {
    if (isOrderedCoreTask(task) && !orderedCoreRowEligible(candidate.row, candidate.dataType, task.platform)) {
      continue;
    }
    const sanitized = sanitizeBusinessValue(candidate.row);
    if (!sanitized || typeof sanitized !== 'object' || Array.isArray(sanitized)) continue;
    sanitized.platform = task.platform;
    sanitized.source = task.platform;
    sanitized.system_hotel_id = Number(task.system_hotel_id);
    const observedPlatformHotelId = [
      sanitized.platform_hotel_id,
      sanitized.ctrip_hotel_id,
      sanitized.poi_id,
      sanitized.poiId,
      sanitized.store_id,
      sanitized.storeId,
      sanitized.hotel_id,
      sanitized.hotelId,
    ].map(value => String(value ?? '').trim()).find(Boolean);
    if (observedPlatformHotelId) {
      sanitized.platform_hotel_id = observedPlatformHotelId;
    } else {
      delete sanitized.platform_hotel_id;
    }
    sanitized.data_date = String(task.data_date || sanitized.data_date || sanitized.date || '');
    sanitized.data_type = String(sanitized.data_type || candidate.dataType || task.data_type || 'business');
    const fingerprint = JSON.stringify([
      sanitized.data_type,
      sanitized.data_date,
      sanitized.platform_hotel_id,
      sanitized.source_trace_id || sanitized.metric_key || sanitized.id || sanitized,
    ]);
    if (seen.has(fingerprint)) continue;
    seen.add(fingerprint);
    rows.push(sanitized);
    if (rows.length >= MAX_UPLOAD_ROWS) break;
  }
  return rows;
}

export function orderedSectionsForTask(task = {}) {
  const platform = String(task.platform || '').trim().toLowerCase();
  const requested = Array.isArray(task.request?.sections)
    ? task.request.sections.map(value => String(value || '').trim()).filter(Boolean)
    : [];
  if (requested.length > 0) return [...new Set(requested)];
  try {
    const mapped = buildOtaMapCollectionPlan(platform, {
      includeOptional: task.request?.ordered_collection?.include_optional === true,
    }).sections;
    if (mapped.length > 0) return mapped;
  } catch {}
  return [...(ORDERED_DEFAULT_SECTIONS[platform] || [])];
}

export function buildCaptureResultSummary(payload = {}, task = {}, rows = []) {
  const platform = String(task.platform || '').trim().toLowerCase();
  const requestedSections = orderedSectionsForTask(task);
  const expectedInterfaces = Array.isArray(task.request?.ordered_collection?.interface_ids)
    ? task.request.ordered_collection.interface_ids.map(value => String(value || '').trim()).filter(Boolean)
    : [];
  const capturedInterfaces = [];
  const sectionStates = {};
  for (const response of Array.isArray(payload.responses) ? payload.responses : []) {
    if (!response || typeof response !== 'object') continue;
    const interfaceId = String(
      response.endpoint_id
        || response.payload_key
        || response.capture_section
        || response.section
        || '',
    ).trim();
    if (interfaceId) capturedInterfaces.push(interfaceId);
    const section = String(response.capture_section || response.section || '').trim();
    if (section) {
      sectionStates[section] ||= { response_count: 0, row_count: 0 };
      sectionStates[section].response_count += 1;
      sectionStates[section].row_count += Math.max(0, Number(response.row_count || 0));
    }
  }
  const capturedFields = orderedCapturedFieldKeys(platform, rows);
  const requiredFields = ORDERED_REQUIRED_FIELDS[platform] || [];
  const missingFields = requiredFields.filter(field => !capturedFields.includes(field));
  const identityStatus = String(payload?.platform_identity_validation?.status || 'not_checked').trim().toLowerCase();
  const validatedIdentifier = String(
    payload?.platform_identity_validation?.validated_identifier || '',
  ).trim();
  const gateStatus = String(payload?.capture_gate?.status || 'unknown').trim().toLowerCase();

  return {
    contract_version: 'ota_ordered_collection.v1',
    scope: ORDERED_CORE_SCOPE,
    platform,
    target_date: String(task.data_date || ''),
    requested_sections: requestedSections,
    expected_interface_ids: [...new Set(expectedInterfaces)],
    captured_interface_ids: [...new Set(capturedInterfaces)].slice(0, 80),
    missing_interface_ids: [...new Set(expectedInterfaces)].filter(id => !capturedInterfaces.includes(id)),
    required_field_keys: requiredFields,
    captured_field_keys: capturedFields,
    missing_field_keys: missingFields,
    section_states: sectionStates,
    capture_gate_status: gateStatus,
    platform_identity_validation: {
      status: identityStatus,
      source_validation: payload?.platform_identity_validation?.source_validation === true,
      validated_identifier: validatedIdentifier,
    },
    readback_status: 'pending_server_save',
    excluded_example_capabilities: ['comments', 'realtime', 'ads', 'subchannels'],
  };
}

function isOrderedCoreTask(task = {}) {
  const scope = String(task.request?.ordered_collection?.scope || '').trim().toLowerCase();
  return scope === ORDERED_CORE_SCOPE
    || ['collect', 'backfill'].includes(String(task.task_type || '').trim().toLowerCase());
}

function orderedCoreRowEligible(row, fallbackDataType, platform) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) return false;
  const endpointId = String(
    row.endpoint_id
      || row.endpointId
      || row.raw_data?.endpoint_id
      || row.rawData?.endpoint_id
      || '',
  ).trim().toLowerCase();
  if (['homepage_realtime', 'business_realtime'].includes(endpointId)) {
    return false;
  }
  const dataType = String(row.data_type || row.dataType || fallbackDataType || '').trim().toLowerCase();
  if (!['business', 'traffic', 'traffic_analysis', 'order'].includes(dataType)) {
    return false;
  }
  if (platform === 'meituan' && ['peer_rank', 'search_keyword', 'advertising'].includes(dataType)) {
    return false;
  }
  return true;
}

function orderedCapturedFieldKeys(platform, rows) {
  const aliases = {
    order_amount: ['order_amount', 'orderAmount', 'amount', 'bookAmount', 'saleAmount', 'totalAmount'],
    room_nights: ['room_nights', 'roomNights', 'quantity', 'bookQuantity', 'nightNum'],
    order_count: ['order_count', 'orderCount', 'orders', 'book_order_num', 'bookOrderNum', 'orderQuantity'],
    list_exposure: ['list_exposure', 'listExposure', 'exposure_count', 'exposureCount'],
    detail_exposure: ['detail_exposure', 'detailExposure', 'detail_visitor', 'detailVisitor'],
    flow_rate: ['flow_rate', 'flowRate', 'conversion_rate', 'conversionRate', 'closeRate'],
    order_filling_num: ['order_filling_num', 'orderFillingNum', 'orderVisitors'],
    order_submit_num: ['order_submit_num', 'orderSubmitNum', 'submitUsers'],
  };
  const captured = new Set();
  for (const row of Array.isArray(rows) ? rows : []) {
    if (!row || typeof row !== 'object' || Array.isArray(row)) continue;
    for (const field of ORDERED_REQUIRED_FIELDS[platform] || []) {
      if ((aliases[field] || [field]).some(alias => (
        Object.prototype.hasOwnProperty.call(row, alias)
        && row[alias] !== null
        && String(row[alias]).trim() !== ''
      ))) {
        captured.add(field);
      }
    }
  }
  return (ORDERED_REQUIRED_FIELDS[platform] || []).filter(field => captured.has(field));
}

export function sanitizeBusinessValue(value, key = '') {
  const normalizedKey = normalizeSensitiveKey(key);
  if (isSensitiveBusinessKey(normalizedKey)) {
    return undefined;
  }
  if (Array.isArray(value)) {
    return value.map(item => sanitizeBusinessValue(item)).filter(item => item !== undefined);
  }
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
      || /\bbearer\s+[A-Za-z0-9._~+/=:-]{8,}/iu.test(value)) {
      return '[敏感内容已在本机移除]';
    }
    return value.length > 20_000 ? value.slice(0, 20_000) : value;
  }
  return value;
}

// Browser scripts often return camelCase keys.  Normalize before checking so
// cookieValue/sessionToken/rawSession cannot bypass the local-data boundary.
function normalizeSensitiveKey(key) {
  return String(key)
    .replace(/([a-z0-9])([A-Z])/gu, '$1_$2')
    .replace(/([A-Z]+)([A-Z][a-z])/gu, '$1_$2')
    .toLowerCase()
    .replace(/[^a-z0-9]+/gu, '_')
    .replace(/^_+|_+$/gu, '');
}

function isSensitiveBusinessKey(normalizedKey) {
  if (/(^|_)(?:cookies?|tokens?|authorization|password|secret|api_key|headers?|profile_dir|profile_path|local_storage|session_storage|webhook|raw_(?:response|request|data|session))($|_)/u.test(normalizedKey)) {
    return true;
  }
  return /(^|_)(?:cookie|token|auth|session|profile)_(?:value|token|cookie|cookies|header|headers|path|dir|data|storage|raw)($|_)/u.test(normalizedKey)
    || /^(?:cookie|token|auth|session|profile)(?:value|token|cookie|cookies|header|headers|path|dir|data|storage|raw)$/u.test(normalizedKey);
}

async function apiRequest(server, path, { method = 'GET', body, device } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (device) {
    headers['X-Collector-Device-Id'] = device.device_public_id;
    headers.Authorization = `Collector ${device.device_token}`;
  }
  const response = await fetch(`${server}${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  let decoded = {};
  try {
    decoded = text ? JSON.parse(text) : {};
  } catch {
    decoded = {};
  }
  if (!response.ok || Number(decoded.code || response.status) >= 400) {
    const error = new Error(String(decoded.message || `HTTP ${response.status}`));
    error.status = response.status;
    error.response = decoded;
    throw error;
  }
  return decoded.data ?? decoded;
}

async function saveDeviceConfig(configPath, device) {
  await mkdir(dirname(configPath), { recursive: true });
  await writeFile(configPath, `${JSON.stringify(device, null, 2)}\n`, { encoding: 'utf8', mode: 0o600 });
  try {
    await chmod(configPath, 0o600);
  } catch {
    // Windows ACLs are managed by the current user profile; do not fail pairing.
  }
}

async function loadDeviceConfig(configPath) {
  const decoded = JSON.parse(await readFile(configPath, 'utf8'));
  if (!decoded.device_public_id || !decoded.device_token || !decoded.server) {
    throw new Error('本机采集器配置不完整，请重新配对。');
  }
  decoded.server = normalizeServer(decoded.server);
  return decoded;
}

async function pairDevice(args, configPath) {
  const server = normalizeServer(args.server);
  const pairCode = String(args.code || args.pairCode || '').trim();
  if (!pairCode) throw new Error('缺少 --code 配对码。');
  const data = await apiRequest(server, '/api/ota-local-collector/pair', {
    method: 'POST',
    body: {
      pair_code: pairCode,
      device_name: String(args.name || '我的 Windows 采集电脑'),
      device_platform: 'windows',
      collector_version: COLLECTOR_VERSION,
      capabilities: ['ctrip', 'meituan', 'account_profile', 'local_sync'],
    },
  });
  await saveDeviceConfig(configPath, {
    server,
    device_public_id: data.device_public_id,
    device_token: data.device_token,
    device_name: data.device_name,
    paired_at: new Date().toISOString(),
  });
  console.log(`配对成功：${data.device_name || 'Windows 本机采集器'}`);
  console.log(`设备令牌只保存在本机：${configPath}`);
  console.log('下一步：node scripts/ota_local_collector.mjs run');
}

export function isTrustedLocalConnectOrigin(origin, server) {
  try {
    return new URL(String(origin || '')).origin === new URL(normalizeServer(server)).origin;
  } catch {
    return false;
  }
}

async function readLocalConnectBody(request) {
  const chunks = [];
  let bytes = 0;
  for await (const chunk of request) {
    bytes += chunk.length;
    if (bytes > 16 * 1024) throw new Error('Local connection request is too large.');
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new Error('Local connection request is invalid.');
  }
}

/**
 * Loopback-only bridge for the authenticated website. The short-lived pairing
 * proof stays inside the browser request and is never rendered in the UI.
 */
export function createLocalConnectServer({
  server,
  configPath = DEFAULT_CONFIG,
  pairDeviceFn = pairDevice,
  onPaired = null,
} = {}) {
  const trustedServer = normalizeServer(server);
  const trustedOrigin = new URL(trustedServer).origin;
  const writeJson = (response, status, body, allowOrigin = '') => {
    const headers = {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
    };
    if (allowOrigin) {
      headers['Access-Control-Allow-Origin'] = allowOrigin;
      headers.Vary = 'Origin';
      headers['Access-Control-Allow-Methods'] = 'POST, OPTIONS';
      headers['Access-Control-Allow-Headers'] = 'Content-Type';
      headers['Access-Control-Allow-Private-Network'] = 'true';
    }
    response.writeHead(status, headers);
    response.end(JSON.stringify(body));
  };

  return createServer(async (request, response) => {
    const origin = String(request.headers.origin || '');
    const trustedRequest = isTrustedLocalConnectOrigin(origin, trustedServer);
    if (request.method === 'OPTIONS') {
      writeJson(response, trustedRequest ? 204 : 403, {}, trustedRequest ? trustedOrigin : '');
      return;
    }
    if (request.method !== 'POST' || request.url !== '/connect' || !trustedRequest) {
      writeJson(response, 403, { status: 'forbidden' });
      return;
    }
    try {
      const input = await readLocalConnectBody(request);
      if (!isTrustedLocalConnectOrigin(input.server, trustedServer)) {
        writeJson(response, 403, { status: 'server_mismatch' }, trustedOrigin);
        return;
      }
      const paired = await pairDeviceFn({
        server: trustedServer,
        code: String(input.pair_code || ''),
        name: String(input.device_name || 'Windows local collector'),
      }, configPath);
      await Promise.resolve(onPaired?.(paired));
      writeJson(response, 200, {
        status: 'paired',
        device_name: String(paired.device_name || ''),
        device_public_id: String(paired.device_public_id || ''),
      }, trustedOrigin);
    } catch (error) {
      writeJson(response, 422, {
        status: 'pair_failed',
        message: String(error?.message || 'Local collector connection failed.'),
      }, trustedOrigin);
    }
  });
}

async function postProgress(device, task, status, message) {
  return apiRequest(device.server, `/api/ota-local-collector/tasks/${task.id}/progress`, {
    method: 'POST',
    device,
    body: {
      lease_token: task.lease_token,
      status,
      message,
    },
  });
}

async function postResult(device, task, result) {
  return apiRequest(device.server, `/api/ota-local-collector/tasks/${task.id}/result`, {
    method: 'POST',
    device,
    body: {
      lease_token: task.lease_token,
      ...result,
    },
  });
}

async function runCapture(task, outputPath) {
  const script = task.platform === 'meituan'
    ? resolve('scripts/meituan_browser_capture.mjs')
    : resolve('scripts/ctrip_browser_capture.mjs');
  const captureArgs = [script];
  if (task.platform === 'meituan') {
    captureArgs.push(`--store-id=${task.platform_hotel_id}`);
    captureArgs.push(`--poi-id=${task.platform_hotel_id}`);
  } else {
    captureArgs.push(`--profile-id=${task.platform_hotel_id}`);
    captureArgs.push(`--hotel-id=${task.platform_hotel_id}`);
  }
  captureArgs.push(`--account-profile-key=${task.profile_key_hash}`);
  captureArgs.push(`--system-hotel-id=${task.system_hotel_id}`);
  captureArgs.push(`--platform-hotel-name=${task.platform_hotel_name || ''}`);
  captureArgs.push(`--output=${outputPath}`);
  if (task.data_date) captureArgs.push(`--data-date=${task.data_date}`);
  const sections = orderedSectionsForTask(task);
  if (sections.length > 0) captureArgs.push(`--sections=${sections.join(',')}`);
  if (task.platform === 'ctrip' && ['collect', 'backfill'].includes(task.task_type)) {
    captureArgs.push('--sequential-sections=true');
    captureArgs.push('--section-concurrency=1');
  }
  if (task.task_type === 'login') {
    captureArgs.push('--login-only=true');
    captureArgs.push(`--login-timeout-ms=${INTERACTIVE_LOGIN_TIMEOUT_MS}`);
  } else if (task.task_type === 'session_probe') {
    captureArgs.push('--session-probe-only=true');
  }

  return new Promise(resolveProcess => {
    const child = spawn(process.execPath, captureArgs, {
      cwd: process.cwd(),
      stdio: 'inherit',
      shell: false,
      windowsHide: false,
    });
    child.once('error', error => resolveProcess({ exitCode: -1, error }));
    child.once('exit', code => resolveProcess({ exitCode: Number(code ?? -1), error: null }));
  });
}

async function executeTask(device, task) {
  const outputPath = resolve(`storage/local_collector/results/task_${task.id}_${Date.now()}.json`);
  await mkdir(dirname(outputPath), { recursive: true });
  console.log(`开始任务 #${task.id}：${task.account_alias} / ${task.platform} / ${task.task_type}`);
  const progressStatus = task.task_type === 'login' ? 'waiting_user_login' : 'running';
  const progressMessage = task.task_type === 'login'
    ? '等待运营人员在原设备、原账号和原酒店 Profile 完成登录。'
    : '本机浏览器任务已启动。';
  await postProgress(device, task, progressStatus, progressMessage);
  const capture = await runCapture(task, outputPath);
  let payload = {};
  if (existsSync(outputPath)) {
    try {
      payload = JSON.parse(await readFile(outputPath, 'utf8'));
    } catch {
      payload = {};
    }
  }

  if (task.task_type === 'login' || task.task_type === 'session_probe') {
    if (capture.exitCode === 0 && sessionVerified(payload)) {
      const response = await postResult(device, task, {
        success: true,
        session_status: 'current_session_verified',
        session_verified_at: new Date().toISOString(),
        message: '平台登录状态已在账户使用者电脑验证。',
      });
      if (existsSync(outputPath)) await unlink(outputPath);
      console.log(`任务 #${task.id} 登录验证成功。`);
      return response;
    }
    const errorCode = classifyCaptureFailure(payload, capture.exitCode);
    const response = await postResult(device, task, {
      success: false,
      error_code: errorCode,
      error_summary: localFailureSummary(errorCode),
    });
    console.log(`任务 #${task.id} 需要处理：${response.recovery?.next_action || localFailureSummary(errorCode)}`);
    return response;
  }

  if (capture.exitCode !== 0) {
    const errorCode = classifyCaptureFailure(payload, capture.exitCode);
    const response = await postResult(device, task, {
      success: false,
      error_code: errorCode,
      error_summary: localFailureSummary(errorCode),
    });
    console.log(`任务 #${task.id} 失败：${response.recovery?.next_action || localFailureSummary(errorCode)}`);
    return response;
  }

  const rows = extractSanitizedRows(payload, task);
  const captureSummary = buildCaptureResultSummary(payload, task, rows);
  const response = await postResult(device, task, rows.length > 0
    ? { success: true, rows, capture_summary: captureSummary }
    : {
        success: false,
        error_code: 'zero_rows',
        capture_summary: captureSummary,
        error_summary: '目标日期未采集到可验证的业务行，未使用 0 或旧数据冒充成功。',
      });
  if (response.status === 'success' && existsSync(outputPath)) {
    await unlink(outputPath);
  }
  console.log(response.status === 'success'
    ? `任务 #${task.id} 已保存并通过服务端读回：${response.summary?.saved_count || 0} 行。`
    : `任务 #${task.id} 未完成：${response.recovery?.next_action || '请查看宿析OS本机采集页面。'}`);
  return response;
}

function localFailureSummary(errorCode) {
  return {
    login_required: '平台登录状态已失效，请在当前电脑重新登录。',
    verification_required: '平台要求验证码或人工验证，请在当前电脑完成。',
    permission_denied: '当前账户或门店权限不足，请检查映射后联系管理员。',
    identity_mismatch: '平台门店身份与宿析OS映射不一致，已停止上传。',
    network_error: '本机网络或平台暂时不可用，系统将按有限次数自动重试。',
    browser_start_failed: '本机浏览器未能正常启动，请关闭占用进程后重试。',
  }[errorCode] || '本机采集失败，请查看宿析OS中的脱敏错误摘要。';
}

async function heartbeat(device) {
  return apiRequest(device.server, '/api/ota-local-collector/heartbeat', {
    method: 'POST',
    device,
    body: {
      collector_version: COLLECTOR_VERSION,
      capabilities: ['ctrip', 'meituan', 'account_profile', 'local_sync'],
    },
  });
}

async function runOneCycle(device) {
  await pruneLocalResultFiles();
  await heartbeat(device);
  const next = await apiRequest(device.server, '/api/ota-local-collector/tasks/next', { device });
  if (next.status !== 'leased' || !next.task) return { idle: true, pollAfter: next.poll_after_seconds || 15 };
  await executeTask(device, next.task);
  return { idle: false, pollAfter: 1 };
}

async function runCollectorLoop(configPath, pollMs) {
  const device = await loadDeviceConfig(configPath);
  console.log(`Local collector started: ${device.device_name || device.device_public_id}`);
  while (true) {
    try {
      const cycle = await runOneCycle(device);
      await new Promise(resolveWait => setTimeout(resolveWait, cycle.pollAfter * 1_000 || pollMs));
    } catch (error) {
      console.error(`Local collector retry: ${error.message}`);
      await new Promise(resolveWait => setTimeout(resolveWait, pollMs));
    }
  }
}

async function serveLocalConnect(args, configPath) {
  const server = normalizeServer(args.server);
  const port = Math.max(1024, Math.min(65535, Number(args.port || 48761)));
  const pollMs = Math.max(3_000, Number(args.pollMs || DEFAULT_POLL_MS));
  let collectorLoop = null;
  const startCollector = () => {
    if (collectorLoop || !existsSync(configPath)) return;
    collectorLoop = runCollectorLoop(configPath, pollMs)
      .catch(error => console.error(`Local collector stopped: ${error.message}`))
      .finally(() => { collectorLoop = null; });
  };
  const listener = createLocalConnectServer({ server, configPath, onPaired: startCollector });
  await new Promise((resolveListen, rejectListen) => {
    listener.once('error', rejectListen);
    listener.listen(port, '127.0.0.1', resolveListen);
  });
  console.log(`Local collector connect endpoint ready: http://127.0.0.1:${port}/connect`);
  startCollector();
  await new Promise(() => {});
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const command = String(args._[0] || 'run').toLowerCase();
  const configPath = resolve(String(args.config || DEFAULT_CONFIG));
  if (command === 'serve') {
    await serveLocalConnect(args, configPath);
    return;
  }
  if (command === 'pair') {
    await pairDevice(args, configPath);
    return;
  }
  if (!existsSync(configPath)) {
    throw new Error(`尚未配对本机采集器。请先在宿析OS生成配对码，再执行 pair 命令。配置位置：${configPath}`);
  }
  const device = await loadDeviceConfig(configPath);
  const once = command === 'once';
  const pollMs = Math.max(3_000, Number(args.pollMs || DEFAULT_POLL_MS));
  console.log(`本机采集器已启动：${device.device_name || device.device_public_id}`);
  console.log('登录态、Cookie 与 Profile 保留在本机；仅同步结构化业务结果和脱敏状态。');
  do {
    try {
      const cycle = await runOneCycle(device);
      if (once) return;
      await new Promise(resolveWait => setTimeout(resolveWait, cycle.pollAfter * 1_000 || pollMs));
    } catch (error) {
      console.error(`本机采集器暂时无法继续：${error.message}`);
      console.error('将自动重试；若设备被撤销或持续失败，请在宿析OS重新配对并联系管理员。');
      if (once) throw error;
      await new Promise(resolveWait => setTimeout(resolveWait, pollMs));
    }
  } while (true);
}

const invokedAsScript = process.argv[1]
  && pathToFileURL(resolve(process.argv[1])).href === import.meta.url;
if (invokedAsScript) {
  main().catch(error => {
    console.error(`本机采集器停止：${error.message}`);
    process.exitCode = 1;
  });
}
