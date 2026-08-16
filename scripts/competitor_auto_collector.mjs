import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { join, resolve } from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';
import {
  launchOtaPersistentContext,
  requireFreshOtaPageNetwork,
} from './lib/cloakbrowser_launcher.mjs';
import {
  buildCompetitorPublicUrl,
  classifyCtripPageUrl,
  classifyCtripRoomResponse,
  ctripRequestScopeMatchesTask,
  extractCtripComparableRate,
  isCtripRoomResponseUrl,
  normalizeCollectorServer,
  normalizeCompetitorPlatform,
  sanitizedCollectorStatus,
  validateCompetitorTask,
} from './lib/competitor_collection_client.mjs';

const DEFAULT_POLL_MS = 60_000;
const DEFAULT_CAPTURE_TIMEOUT_MS = 25_000;
const SERVER_REQUEST_TIMEOUT_MS = 15_000;
const MAX_RESPONSE_BYTES = 2_000_000;

export function parseArgs(argv) {
  const result = {};
  for (const argument of argv) {
    const match = /^--([a-z0-9_-]+)(?:=(.*))?$/iu.exec(String(argument));
    if (!match) continue;
    const key = match[1].replace(/-([a-z])/gu, (_, letter) => letter.toUpperCase());
    result[key] = match[2] === undefined ? 'true' : match[2];
  }
  return result;
}

export async function loadCollectorConfig(args, environment = process.env) {
  const server = normalizeCollectorServer(args.server || environment.SUXIOS_SERVER || 'http://127.0.0.1:8080');
  const deviceId = String(args.deviceId || environment.SUXIOS_COMPETITOR_DEVICE_ID || '').trim();
  const platform = normalizeCompetitorPlatform(args.platform || environment.SUXIOS_COMPETITOR_PLATFORM || '');
  const storeId = Number(args.storeId || environment.SUXIOS_COMPETITOR_STORE_ID || 0);
  const token = await resolveToken(args, environment);
  if (!/^[A-Za-z0-9._:-]{3,120}$/u.test(deviceId)) throw new Error('collector_device_id_invalid');
  if (!['xc', 'mt'].includes(platform)) throw new Error('collector_platform_invalid');
  if (!Number.isInteger(storeId) || storeId <= 0) throw new Error('collector_store_id_invalid');
  if (token.length < 24 || token.length > 500) throw new Error('collector_device_token_invalid');

  const profileHash = createHash('sha256').update(`${deviceId}|${platform}|${storeId}`).digest('hex').slice(0, 20);
  return {
    server,
    deviceId,
    platform,
    storeId,
    token,
    stayDate: String(args.stayDate || environment.SUXIOS_COMPETITOR_STAY_DATE || '').trim(),
    profileDir: resolve(args.profileDir || join('storage', `competitor_profile_${profileHash}`)),
    headless: String(args.headless ?? 'true').toLowerCase() === 'true',
    chromePath: String(args.chromePath || environment.CLOAKBROWSER_BINARY_PATH || '').trim(),
    pollMs: boundedInteger(args.pollMs, DEFAULT_POLL_MS, 15_000, 3_600_000),
    captureTimeoutMs: boundedInteger(args.captureTimeoutMs, DEFAULT_CAPTURE_TIMEOUT_MS, 5_000, 60_000),
  };
}

export async function pollCompetitorTasks(config, fetchImpl = fetch) {
  const form = new URLSearchParams({
    device_id: config.deviceId,
    platform: config.platform,
    store_id: String(config.storeId),
  });
  if (config.stayDate) form.set('stay_date', config.stayDate);
  const payload = await postForm(
    `${config.server}/api/competitor/task`,
    form,
    { 'X-Task-Token': config.token },
    fetchImpl,
  );
  const rows = Array.isArray(payload?.data) ? payload.data : [];
  return rows.map(validateCompetitorTask);
}

export async function reportCompetitorRate(config, report, fetchImpl = fetch) {
  const form = new URLSearchParams();
  for (const [key, value] of Object.entries(report)) {
    if (value === null || value === undefined) continue;
    form.set(key, String(value));
  }
  const payload = await postForm(
    `${config.server}/api/competitor/report`,
    form,
    { 'X-Report-Token': config.token },
    fetchImpl,
  );
  const result = payload?.data && typeof payload.data === 'object' ? payload.data : {};
  if (result.readback_verified !== true) {
    throw new Error('competitor_report_readback_not_verified');
  }
  const expectedCollectionStatus = report.collection_status || 'collected';
  const expectedValidationStatus = report.collection_status ? 'failed' : 'valid';
  if (result.collection_status !== expectedCollectionStatus
    || result.validation_status !== expectedValidationStatus
  ) {
    throw new Error('competitor_report_receipt_status_mismatch');
  }
  return result;
}

export async function reportCompetitorFailure(config, task, failure, fetchImpl = fetch) {
  const sourceRef = buildCompetitorPublicUrl(task);
  return reportCompetitorRate(config, {
    task_id: task.task_id,
    device_id: config.deviceId,
    store_id: task.store_id,
    hotel_id: task.hotel_id,
    platform: task.platform,
    city: task.city || '未标注',
    ota_hotel_id: task.ota_hotel_id,
    collection_status: normalizeFailureStatus(failure?.status),
    failure_reason: safeReason(failure?.reason || 'collection_failed'),
    collected_at: new Date().toISOString(),
    source_method: 'local_browser_profile_response_json',
    source_ref: sourceRef,
    check_in_date: task.capture_scope.check_in_date,
    check_out_date: task.capture_scope.check_out_date,
    adults: task.capture_scope.adults,
    children: task.capture_scope.children,
    currency: task.capture_scope.currency,
    price_basis: task.capture_scope.price_basis,
  }, fetchImpl);
}

export async function collectCtripTask(context, config, task) {
  const sourceRef = buildCompetitorPublicUrl(task);
  const page = await context.newPage();
  let resolveRoomResponse;
  const roomResponsePromise = new Promise(resolvePromise => { resolveRoomResponse = resolvePromise; });
  let settled = false;
  let requestScopeMismatchObserved = false;

  page.on('response', async response => {
    if (settled || !isCtripRoomResponseUrl(response.url())) return;
    const requestScopeMatched = ctripRequestScopeMatchesTask(response.request().postData(), task);
    if (!requestScopeMatched) {
      requestScopeMismatchObserved = true;
      return;
    }
    try {
      const text = await response.text();
      if (Buffer.byteLength(text, 'utf8') > MAX_RESPONSE_BYTES) {
        settled = true;
        resolveRoomResponse({
          payload: null,
          responseBytes: Buffer.byteLength(text, 'utf8'),
          requestScopeMatched: false,
          status: 'collection_failed',
          reason: 'room_response_too_large',
        });
        return;
      }
      const payload = JSON.parse(text);
      settled = true;
      resolveRoomResponse({
        payload,
        responseBytes: Buffer.byteLength(text, 'utf8'),
        requestScopeMatched: true,
      });
    } catch {
      settled = true;
      resolveRoomResponse({ payload: null, status: 'collection_failed', reason: 'room_response_invalid' });
    }
  });

  try {
    await requireFreshOtaPageNetwork(context, page);
    await page.goto(sourceRef, { waitUntil: 'domcontentloaded', timeout: config.captureTimeoutMs });
    const accessState = classifyCtripPageUrl(page.url());
    if (accessState.status !== 'ready') return accessState;
    const pageIdentityMatched = await verifyCtripPageIdentity(page, task.ota_hotel_id);
    await page.locator('#roomlist-baseroom-fit').scrollIntoViewIfNeeded().catch(() => null);
    const observed = await Promise.race([
      roomResponsePromise,
      delay(config.captureTimeoutMs).then(() => requestScopeMismatchObserved
        ? {
            payload: null,
            status: 'identity_mismatch',
            reason: 'room_request_scope_mismatch',
          }
        : {
            payload: null,
            status: 'collection_failed',
            reason: 'room_response_timeout',
          }),
    ]);
    if (observed.status) return observed;
    const classification = classifyCtripRoomResponse(observed.payload);
    if (classification.status !== 'ready') return classification;
    return extractCtripComparableRate(observed.payload, task, {
      responseBytes: observed.responseBytes,
      requestScopeMatched: observed.requestScopeMatched,
      pageIdentityMatched,
      sourceRef,
      collectedAt: new Date().toISOString(),
      deviceId: config.deviceId,
    });
  } catch (error) {
    const message = String(error?.message || error || 'collection_failed').toLowerCase();
    if (/captcha|verify|verification|challenge|spider|login/u.test(message)) {
      return { status: 'verification_required', reason: 'browser_verification_required' };
    }
    return { status: 'collection_failed', reason: safeReason(message) };
  } finally {
    await page.close().catch(() => null);
  }
}

export async function runCollectorCycle(config, dependencies = {}) {
  const fetchImpl = dependencies.fetchImpl || fetch;
  if (config.platform !== 'xc') {
    return {
      status: 'partial',
      reason: 'competitor_platform_adapter_unavailable',
      task_count: 0,
      reported_count: 0,
      failed_count: 0,
    };
  }
  const tasks = await pollCompetitorTasks(config, fetchImpl);
  if (tasks.length === 0) {
    return { status: 'idle', task_count: 0, reported_count: 0, failed_count: 0 };
  }

  const launch = dependencies.launchContext || launchOtaPersistentContext;
  const context = await launch(config.profileDir, {
    headless: config.headless ? 'true' : 'false',
    chromePath: config.chromePath,
  });
  let reportedCount = 0;
  const failures = [];
  try {
    for (const task of tasks) {
      const collection = dependencies.collectTask
        ? await dependencies.collectTask(context, config, task)
        : await collectCtripTask(context, config, task);
      if (collection.status !== 'collected' || !collection.report) {
        const failure = { status: collection.status, reason: collection.reason || 'collection_failed' };
        try {
          await reportCompetitorFailure(config, task, failure, fetchImpl);
        } catch {
          failure.reason = 'failure_receipt_not_saved';
        }
        failures.push(failure);
        continue;
      }
      await reportCompetitorRate(config, collection.report, fetchImpl);
      reportedCount += 1;
    }
  } finally {
    await context.close().catch(() => null);
  }
  return {
    status: failures.length === 0 ? 'success' : (reportedCount > 0 ? 'partial' : failures[0].status || 'failed'),
    reason: failures.length > 0 ? failures[0].reason : '',
    task_count: tasks.length,
    reported_count: reportedCount,
    failed_count: failures.length,
  };
}

async function main() {
  const [command = 'once', ...rawArgs] = process.argv.slice(2);
  if (!['once', 'run'].includes(command)) throw new Error('collector_command_invalid');
  const config = await loadCollectorConfig(parseArgs(rawArgs));
  do {
    try {
      const result = await runCollectorCycle(config);
      console.log(JSON.stringify(sanitizedCollectorStatus(result.status, result)));
    } catch (error) {
      console.error(JSON.stringify(sanitizedCollectorStatus('failed', {
        reason: safeReason(error?.message || error),
      })));
      if (command === 'once') throw error;
    }
    if (command === 'run') await delay(config.pollMs);
  } while (command === 'run');
}

async function postForm(url, form, headers, fetchImpl) {
  const response = await fetchImpl(url, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      ...headers,
    },
    body: form.toString(),
    redirect: 'error',
    signal: AbortSignal.timeout(SERVER_REQUEST_TIMEOUT_MS),
  });
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    throw new Error(`collector_server_response_invalid_${response.status}`);
  }
  if (!response.ok || Number(payload?.code || response.status) !== 200) {
    throw new Error(safeReason(payload?.data?.reason || payload?.message || `collector_server_http_${response.status}`));
  }
  return payload;
}

async function resolveToken(args, environment) {
  const environmentToken = String(environment.SUXIOS_COMPETITOR_DEVICE_TOKEN || '').trim();
  if (environmentToken) return environmentToken;
  const tokenFile = String(args.tokenFile || '').trim();
  if (!tokenFile) throw new Error('collector_device_token_missing');
  return String(await readFile(resolve(tokenFile), 'utf8')).replace(/^\uFEFF/u, '').trim();
}

async function verifyCtripPageIdentity(page, otaHotelId) {
  try {
    const canonical = await page.locator('link[rel="canonical"]').first().getAttribute('href');
    const canonicalUrl = new URL(String(canonical || ''), page.url());
    return canonicalUrl.hostname.toLowerCase() === 'hotels.ctrip.com'
      && new RegExp(`^/hotels/${otaHotelId}\\.html/?$`, 'u').test(canonicalUrl.pathname);
  } catch {
    return false;
  }
}

function boundedInteger(value, fallback, minimum, maximum) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed >= minimum && parsed <= maximum ? parsed : fallback;
}

function safeReason(value) {
  const text = String(value || 'collection_failed')
    .replace(/(authorization|bearer|cookie|token|password|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/giu, '$1=****')
    .replace(/\s+/gu, '_')
    .replace(/[^a-z0-9_:-]/giu, '')
    .slice(0, 120)
    .toLowerCase();
  return text || 'collection_failed';
}

function normalizeFailureStatus(value) {
  const status = String(value || '').trim().toLowerCase();
  return ['login_required', 'verification_required', 'identity_mismatch', 'zero_rows', 'collection_failed']
    .includes(status)
    ? status
    : 'collection_failed';
}

function delay(milliseconds) {
  return new Promise(resolvePromise => setTimeout(resolvePromise, milliseconds));
}

const invokedAsScript = process.argv[1]
  && pathToFileURL(resolve(process.argv[1])).href === import.meta.url;
if (invokedAsScript) {
  main().catch(() => { process.exitCode = 1; });
}
