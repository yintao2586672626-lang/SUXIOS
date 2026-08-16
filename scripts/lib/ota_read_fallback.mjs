import { createHash } from 'node:crypto';

const PRIVATE_STATES = new WeakMap();
const MAX_REQUEST_BODY_BYTES = 64 * 1024;
const MAX_SAFE_HEADER_BYTES = 1024;
const DEFAULT_REPLAY_TIMEOUT_MS = 12000;

const PLATFORM_HOSTS = {
  ctrip: new Set(['ebooking.ctrip.com']),
  meituan: new Set(['eb.meituan.com', 'me.meituan.com']),
};

const READ_ENDPOINTS = {
  ctrip: [
    endpoint('homepage_realtime', 'homepage', 'queryhomepagerealtimedata'),
    endpoint('business_realtime', 'business_overview', 'getdayreportrealtimedate'),
    endpoint('business_market_overview', 'business_overview', 'fetchmarketoverviewv2'),
    endpoint('business_flow_compete', 'business_overview', 'getdayreportflowcompete'),
    endpoint('business_service_quantity', 'business_overview', 'getdayreportserverquantity'),
    endpoint('business_visitor_title', 'business_overview', 'fetchvisitortitlev2'),
    endpoint('business_capacity', 'business_overview', 'fetchcapacityoverview'),
    endpoint('business_flow_transform', 'business_overview', 'queryflowtrans'),
    endpoint('weekly_compete_report', 'business_weekly_overview', 'getcompetehotelreport'),
    endpoint('weekly_report', 'business_weekly_overview', 'getlastweekreport'),
    endpoint('sales_order_trend', 'sales_report', 'queryordertrend'),
    endpoint('sales_min_price', 'sales_report', 'queryhotelminprice'),
    endpoint('traffic_scan_flow', 'traffic_report', 'queryscanflowdetails'),
    endpoint('traffic_order_overview', 'traffic_report', 'fetchorderoverview'),
    endpoint('traffic_flow_source', 'traffic_report', 'queryflowsource'),
    endpoint('traffic_city_keywords', 'traffic_report', 'querycityhotkeywords'),
    endpoint('traffic_search_details', 'traffic_report', 'querysearchflowdetails'),
    endpoint('traffic_comment_score_summary', 'traffic_report', 'getcommentsscore'),
    endpoint('hotel_advice', 'business_overview', 'gethoteladvice'),
    endpoint('hotel_psi', 'quality_service', 'gethotelpsi'),
  ],
  meituan: [
    endpoint('meituan_business_data', 'traffic', '/ebooking/home/businessdata'),
    endpoint('meituan_traffic_home', 'traffic', '/datacenter/home/traffic'),
    endpoint('meituan_peer_trends', 'traffic', '/datacenter/home/peertrends'),
  ],
};

const WRITE_LIKE_PATH = /(?:^|[\/_.-])(?:save|update|delete|remove|reply|submit|send|create|modify|edit|cancel|confirm|publish|upload|write)(?:[\/_.-]|$)/i;
const SAFE_CONTENT_TYPE = /^(?:application\/json|application\/x-www-form-urlencoded|text\/plain)(?:\s*;|$)/i;

export function createOtaReadFallbackState(platform, options = {}) {
  const normalizedPlatform = normalizePlatform(platform);
  const state = {
    schema_version: 1,
    platform: normalizedPlatform,
    captured_template_count: 0,
    attempted_count: 0,
    blocked_count: 0,
    sensitive_values_exposed: false,
  };
  PRIVATE_STATES.set(state, {
    maxTemplates: boundedInteger(options.maxTemplates, 1, 12, 8),
    maxAttempts: boundedInteger(options.maxAttempts, 1, 12, 8),
    replayTimeoutMs: boundedInteger(
      options.replayTimeoutMs || options.replay_timeout_ms,
      1000,
      30000,
      DEFAULT_REPLAY_TIMEOUT_MS,
    ),
    templates: new Map(),
    attemptedFingerprints: new Set(),
    reportedBlocks: new Set(),
    replayInProgress: false,
  });
  return state;
}

export function classifyOtaReadFallbackRequest(platform, candidate = {}, context = {}) {
  const normalizedPlatform = normalizePlatform(platform);
  const method = String(candidate.method || 'GET').trim().toUpperCase();
  const resourceType = String(candidate.resourceType || candidate.resource_type || '').trim().toLowerCase();
  if (!['GET', 'POST'].includes(method)) {
    return rejected(normalizedPlatform, 'unsupported_method');
  }
  if (resourceType && !['xhr', 'fetch'].includes(resourceType)) {
    return rejected(normalizedPlatform, 'unsupported_resource_type');
  }

  let parsedUrl;
  try {
    parsedUrl = new URL(String(candidate.url || ''));
  } catch {
    return rejected(normalizedPlatform, 'invalid_url');
  }
  if (parsedUrl.protocol !== 'https:') {
    return rejected(normalizedPlatform, 'https_required');
  }
  if (!PLATFORM_HOSTS[normalizedPlatform].has(parsedUrl.hostname.toLowerCase())) {
    return rejected(normalizedPlatform, 'untrusted_host');
  }

  const pathname = parsedUrl.pathname.toLowerCase();
  if (WRITE_LIKE_PATH.test(pathname)) {
    return rejected(normalizedPlatform, 'write_like_path');
  }
  const definition = READ_ENDPOINTS[normalizedPlatform].find(item => pathname.includes(item.pathToken));
  if (!definition) {
    return rejected(normalizedPlatform, 'endpoint_not_allowlisted');
  }

  const section = safeIdentifier(context.section) || definition.section;
  const endpointId = safeIdentifier(context.endpointId || context.endpoint_id) || definition.endpointId;
  return {
    accepted: true,
    platform: normalizedPlatform,
    method,
    section,
    endpoint_id: endpointId,
    safe_route: definition.safeRoute,
    reason: 'allowlisted_observed_read',
    sensitive_values_exposed: false,
  };
}

export function observeOtaReadFallbackRequest(state, request, context = {}) {
  const privateState = requirePrivateState(state);
  if (privateState.replayInProgress) {
    return {
      captured: false,
      reason: 'replay_request_ignored',
      sensitive_values_exposed: false,
    };
  }

  const url = requestValue(request, 'url');
  const method = requestValue(request, 'method') || 'GET';
  const resourceType = requestValue(request, 'resourceType');
  const classified = classifyOtaReadFallbackRequest(state.platform, {
    url,
    method,
    resourceType,
  }, context);
  if (!classified.accepted) {
    return {
      captured: false,
      reason: classified.reason,
      sensitive_values_exposed: false,
    };
  }

  const headers = requestObjectValue(request, 'headers');
  const contentType = String(headerValue(headers, 'content-type') || '').trim();
  const body = classified.method === 'GET' ? '' : String(requestValue(request, 'postData') || '');
  if (Buffer.byteLength(body, 'utf8') > MAX_REQUEST_BODY_BYTES) {
    return {
      captured: false,
      reason: 'request_body_too_large',
      sensitive_values_exposed: false,
    };
  }
  if (classified.method === 'POST' && contentType && !SAFE_CONTENT_TYPE.test(contentType)) {
    return {
      captured: false,
      reason: 'unsupported_content_type',
      sensitive_values_exposed: false,
    };
  }

  const fingerprint = requestFingerprint(state.platform, classified.method, url, body);
  const frame = requestObjectValue(request, 'frame');
  const requestDateEvidence = sanitizeRequestDateEvidence(context.requestDateEvidence || context.request_date_evidence);
  const dateContext = sanitizeDateContext(context.dateContext || context.date_context);
  const template = {
    platform: state.platform,
    endpointId: classified.endpoint_id,
    safeRoute: classified.safe_route,
    section: classified.section,
    method: classified.method,
    fingerprint,
    requestDateEvidence,
    dateContext,
    url: String(url),
    body,
    headers: safeReplayHeaders(headers),
    frame,
  };

  const existing = privateState.templates.get(fingerprint);
  if (existing) {
    privateState.templates.set(fingerprint, mergeObservedTemplate(existing, template));
    return {
      captured: false,
      reason: 'duplicate_template_refreshed',
      template: publicTemplate(privateState.templates.get(fingerprint)),
      sensitive_values_exposed: false,
    };
  }
  if (privateState.templates.size >= privateState.maxTemplates) {
    return {
      captured: false,
      reason: 'template_limit_reached',
      sensitive_values_exposed: false,
    };
  }

  privateState.templates.set(fingerprint, template);
  state.captured_template_count = privateState.templates.size;
  return {
    captured: true,
    reason: 'observed_read_template_captured',
    template: publicTemplate(template),
    sensitive_values_exposed: false,
  };
}

export function listOtaReadFallbackTemplates(state) {
  const privateState = requirePrivateState(state);
  return Array.from(privateState.templates.values(), publicTemplate);
}

export function evaluateOtaReadFallbackEligibility(template, context = {}) {
  const targetDate = normalizeDate(context.targetDate || context.target_date);
  const requestDate = normalizeDate(template?.request_date);
  const dataPeriod = String(context.dataPeriod || context.data_period || '').trim().toLowerCase();
  const isHistoricalMeituanBusiness = template?.platform === 'meituan'
    && template?.endpoint_id === 'meituan_business_data'
    && dataPeriod === 'historical_daily';
  if (targetDate && requestDate && requestDate !== targetDate) {
    return { eligible: false, reason: 'target_date_mismatch' };
  }
  if (targetDate && !requestDate && !isHistoricalMeituanBusiness) {
    return { eligible: false, reason: 'target_date_unverified' };
  }
  if (!isHistoricalMeituanBusiness) {
    return { eligible: true, reason: 'observed_read_template' };
  }

  const evidence = template?.date_context || {};
  const expectedRelativeRange = String(
    context.expectedRelativeRange
      || context.expected_relative_range
      || '\u6628\u65e5',
  ).trim();
  const requiredCaptureEpoch = Number(context.requiredCaptureEpoch || context.required_capture_epoch || 0);
  const evidenceValid = /^\d{4}-\d{2}-\d{2}$/.test(targetDate)
    && evidence.selected === true
    && normalizeDate(evidence.target_date) === targetDate
    && String(evidence.relative_range || '').trim() === expectedRelativeRange
    && String(evidence.evidence_source || '').trim() === 'page.business_period_selection.readback'
    && (requiredCaptureEpoch <= 0 || Number(evidence.business_capture_epoch || 0) === requiredCaptureEpoch);
  return evidenceValid
    ? { eligible: true, reason: 'verified_historical_page_selection' }
    : { eligible: false, reason: 'target_date_unverified' };
}

export async function replayObservedOtaReadRequests(page, state, context = {}) {
  const privateState = requirePrivateState(state);
  const diagnostics = [];
  const section = safeIdentifier(context.section);
  const maxAttempts = boundedInteger(
    context.maxAttempts || context.max_attempts,
    1,
    privateState.maxAttempts,
    Math.min(3, privateState.maxAttempts),
  );
  let attempts = 0;

  for (const template of privateState.templates.values()) {
    if (attempts >= maxAttempts || state.attempted_count >= privateState.maxAttempts) {
      break;
    }
    const safeTemplate = publicTemplate(template);
    if (section && safeTemplate.section !== section) {
      continue;
    }
    if (privateState.attemptedFingerprints.has(template.fingerprint)) {
      continue;
    }
    if (typeof context.shouldReplay === 'function' && context.shouldReplay(safeTemplate) !== true) {
      continue;
    }

    const eligibility = evaluateOtaReadFallbackEligibility(safeTemplate, context);
    if (!eligibility.eligible) {
      const diagnostic = blockedDiagnosticOnce(state, privateState, template, eligibility.reason, context);
      if (diagnostic) {
        diagnostics.push(diagnostic);
      }
      continue;
    }

    const executionContext = findSameOriginExecutionContext(page, template);
    if (!executionContext) {
      const diagnostic = blockedDiagnosticOnce(
        state,
        privateState,
        template,
        'same_origin_context_unavailable',
        context,
      );
      if (diagnostic) {
        diagnostics.push(diagnostic);
      }
      continue;
    }

    attempts += 1;
    privateState.attemptedFingerprints.add(template.fingerprint);
    state.attempted_count += 1;
    privateState.replayInProgress = true;
    let result;
    try {
      result = await executionContext.evaluate(async input => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), input.timeoutMs);
        try {
          const init = {
            method: input.method,
            credentials: 'include',
            headers: input.headers,
            redirect: 'follow',
            signal: controller.signal,
          };
          if (input.method === 'GET') {
            init.cache = 'no-store';
          } else if (input.body) {
            init.body = input.body;
          }
          const response = await fetch(input.url, init);
          await response.arrayBuffer();
          return {
            transport_ok: true,
            ok: response.ok,
            status: response.status,
          };
        } catch (error) {
          return {
            transport_ok: false,
            ok: false,
            status: 0,
            timed_out: String(error?.name || '') === 'AbortError',
            error_name: String(error?.name || 'Error').replace(/[^A-Za-z]/g, '').slice(0, 40),
          };
        } finally {
          clearTimeout(timeout);
        }
      }, {
        url: template.url,
        method: template.method,
        body: template.body,
        headers: template.headers,
        timeoutMs: privateState.replayTimeoutMs,
      });
    } catch {
      result = { transport_ok: false, ok: false, status: 0 };
    } finally {
      privateState.replayInProgress = false;
    }

    const httpStatus = Number(result?.status || 0);
    const responseObserved = result?.transport_ok === true && httpStatus >= 200 && httpStatus < 300;
    diagnostics.push(buildDiagnostic(template, {
      status: responseObserved ? 'response_observed' : 'failed',
      reason: responseObserved
        ? 'same_origin_read_replay'
        : (result?.timed_out === true ? 'timeout' : (httpStatus > 0 ? 'http_status' : 'fetch_failed')),
      httpStatus,
    }));
  }

  return diagnostics;
}

function endpoint(endpointId, section, pathToken) {
  return {
    endpointId,
    section,
    pathToken: String(pathToken).toLowerCase(),
    safeRoute: `observed-read:${String(pathToken).replace(/[^a-z0-9/_-]/gi, '').slice(0, 80)}`,
  };
}

function normalizePlatform(platform) {
  const value = String(platform || '').trim().toLowerCase();
  if (!Object.prototype.hasOwnProperty.call(PLATFORM_HOSTS, value)) {
    throw new Error(`Unsupported OTA read fallback platform: ${platform}`);
  }
  return value;
}

function rejected(platform, reason) {
  return {
    accepted: false,
    platform,
    reason,
    sensitive_values_exposed: false,
  };
}

function requirePrivateState(state) {
  const privateState = PRIVATE_STATES.get(state);
  if (!privateState) {
    throw new Error('Invalid OTA read fallback state.');
  }
  return privateState;
}

function requestValue(request, name) {
  try {
    const value = request?.[name];
    return typeof value === 'function' ? value.call(request) : value;
  } catch {
    return '';
  }
}

function requestObjectValue(request, name) {
  try {
    const value = request?.[name];
    return typeof value === 'function' ? value.call(request) : value;
  } catch {
    return null;
  }
}

function headerValue(headers, name) {
  if (!headers || typeof headers !== 'object') {
    return '';
  }
  const target = String(name).toLowerCase();
  for (const [key, value] of Object.entries(headers)) {
    if (String(key).toLowerCase() === target) {
      return String(value || '');
    }
  }
  return '';
}

function safeReplayHeaders(headers) {
  const result = {};
  for (const name of ['accept', 'content-type', 'x-requested-with']) {
    const value = headerValue(headers, name).trim();
    if (value && Buffer.byteLength(value, 'utf8') <= MAX_SAFE_HEADER_BYTES) {
      result[name] = value;
    }
  }
  return result;
}

function requestFingerprint(platform, method, url, body) {
  return createHash('sha256')
    .update([platform, method, String(url), String(body)].join('\n'))
    .digest('hex')
    .slice(0, 24);
}

function sanitizeRequestDateEvidence(value) {
  const date = normalizeDate(value?.date || value?.data_date || value?.dataDate);
  const source = safeEvidenceSource(value?.date_source || value?.dateSource);
  return {
    date,
    date_source: source,
  };
}

function sanitizeDateContext(value) {
  return {
    selected: value?.selected === true,
    target_date: normalizeDate(value?.target_date || value?.targetDate),
    relative_range: String(value?.relative_range || value?.relativeRange || '').trim().slice(0, 24),
    evidence_source: safeEvidenceSource(value?.evidence_source || value?.evidenceSource),
    marker: safeIdentifier(value?.marker),
    business_capture_epoch: Math.max(0, Number(value?.business_capture_epoch || value?.businessCaptureEpoch || 0)),
  };
}

function safeEvidenceSource(value) {
  return String(value || '').trim().replace(/[^a-z0-9._-]/gi, '').slice(0, 100);
}

function safeIdentifier(value) {
  return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 100);
}

function normalizeDate(value) {
  const text = String(value || '').trim();
  const match = text.match(/^(\d{4}-\d{2}-\d{2})/);
  return match ? match[1] : '';
}

function publicTemplate(template) {
  return {
    schema_version: 1,
    platform: template.platform,
    section: template.section,
    endpoint_id: template.endpointId,
    safe_route: template.safeRoute,
    method: template.method,
    request_fingerprint: template.fingerprint,
    request_date: template.requestDateEvidence.date,
    request_date_source: template.requestDateEvidence.date_source,
    date_context: { ...template.dateContext },
    sensitive_values_exposed: false,
  };
}

function mergeObservedTemplate(existing, incoming) {
  const existingScore = dateContextScore(existing.dateContext);
  const incomingScore = dateContextScore(incoming.dateContext);
  return {
    ...existing,
    frame: incoming.frame || existing.frame,
    requestDateEvidence: incoming.requestDateEvidence.date
      ? incoming.requestDateEvidence
      : existing.requestDateEvidence,
    dateContext: incomingScore >= existingScore ? incoming.dateContext : existing.dateContext,
  };
}

function dateContextScore(value) {
  return Number(value?.selected === true) * 4
    + Number(Boolean(value?.target_date)) * 2
    + Number(Boolean(value?.evidence_source));
}

function findSameOriginExecutionContext(page, template) {
  let requestOrigin = '';
  try {
    requestOrigin = new URL(template.url).origin;
  } catch {
    return null;
  }

  const candidates = [];
  if (template.frame) {
    candidates.push(template.frame);
  }
  if (page && typeof page.frames === 'function') {
    try {
      candidates.push(...page.frames());
    } catch {
      // Ignore detached browser contexts.
    }
  }
  if (page) {
    candidates.push(page);
  }

  const seen = new Set();
  for (const candidate of candidates) {
    if (!candidate || seen.has(candidate) || typeof candidate.evaluate !== 'function') {
      continue;
    }
    seen.add(candidate);
    let candidateUrl = '';
    try {
      candidateUrl = typeof candidate.url === 'function' ? candidate.url() : '';
    } catch {
      continue;
    }
    try {
      if (new URL(candidateUrl).origin === requestOrigin) {
        return candidate;
      }
    } catch {
      // Ignore about:blank and detached frames.
    }
  }
  return null;
}

function blockedDiagnosticOnce(state, privateState, template, reason, context) {
  const key = [
    template.fingerprint,
    reason,
    normalizeDate(context.targetDate || context.target_date),
  ].join('|');
  if (privateState.reportedBlocks.has(key)) {
    return null;
  }
  privateState.reportedBlocks.add(key);
  state.blocked_count += 1;
  return buildDiagnostic(template, {
    status: 'blocked',
    reason,
    httpStatus: 0,
  });
}

function buildDiagnostic(template, result) {
  return {
    schema_version: 1,
    platform: template.platform,
    section: template.section,
    endpoint_id: template.endpointId,
    safe_route: template.safeRoute,
    request_fingerprint: template.fingerprint,
    request_date: template.requestDateEvidence.date,
    request_date_source: template.requestDateEvidence.date_source,
    status: result.status,
    reason: result.reason,
    ...(result.httpStatus > 0 ? { http_status: result.httpStatus } : {}),
    replay_source: 'observed_request_same_origin',
    sensitive_values_exposed: false,
  };
}

function boundedInteger(value, min, max, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, Math.trunc(number)));
}
