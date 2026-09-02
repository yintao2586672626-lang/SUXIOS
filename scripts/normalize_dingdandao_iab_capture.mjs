#!/usr/bin/env node
import { pathToFileURL } from 'node:url';
import {
  buildCaptureFromDingdandaoResponses,
  classifyDingdandaoResponseRequest,
  dingdandaoEndpointRecipes,
  dingdandaoSourceScopeForTargetDate,
  DINGDANDAO_COLLECTION_MODES,
  isTrustedDingdandaoCaptureComplete,
  SOURCE_URL,
} from './dingdandao_cloud_capture.mjs';

const DINGDANDAO_ORIGIN = new URL(SOURCE_URL).origin;
const MAX_STDIN_BYTES = 2_000_000;
const MAX_STDOUT_BYTES = 2_000_000;
const BROWSER_CAPTURE_SOURCE = 'operator_supplied_browser_response';
const INPUT_KEYS = Object.freeze(['captured_at', 'records']);
const RECORD_KEYS = Object.freeze([
  'method',
  'request_body',
  'response_json',
  'status',
  'url',
]);
const SENSITIVE_KEY_PATTERN = /cookie|authorization|bearer|token|password|passwd|secret|signature|api[_-]?key|headers?|localstorage|session[_-]?material|raw[_-]?(?:response|payload|account)/i;
const FORBIDDEN_CAPTURE_KEYS = new Set([
  'request_body',
  'requestbody',
  'response_json',
  'responsejson',
  'payload',
  'ntwnum',
  'header',
  'headers',
  'cookie',
  'cookies',
  'token',
  'authorization',
  'raw_response',
  'raw_payload',
]);
const SAFE_REASONS = new Set([
  'dingdandao_iab_argument_invalid',
  'dingdandao_iab_target_date_invalid',
  'dingdandao_iab_expected_hotel_name_invalid',
  'dingdandao_iab_expected_provider_hotel_id_invalid',
  'dingdandao_iab_collection_mode_invalid',
  'dingdandao_iab_input_empty',
  'dingdandao_iab_input_too_large',
  'dingdandao_iab_input_json_invalid',
  'dingdandao_iab_input_shape_invalid',
  'dingdandao_iab_captured_at_invalid',
  'dingdandao_iab_record_count_invalid',
  'dingdandao_iab_record_shape_invalid',
  'dingdandao_iab_sensitive_key_forbidden',
  'dingdandao_iab_url_invalid',
  'dingdandao_iab_origin_invalid',
  'dingdandao_iab_path_invalid',
  'dingdandao_iab_transport_invalid',
  'dingdandao_iab_response_invalid',
  'dingdandao_iab_request_scope_invalid',
  'dingdandao_iab_network_context_mismatch',
  'dingdandao_iab_recipe_plan_invalid',
  'dingdandao_iab_hotel_identity_mismatch',
  'dingdandao_iab_capture_incomplete',
  'dingdandao_iab_revenue_overview_incomplete',
  'dingdandao_iab_provenance_invalid',
  'dingdandao_iab_capture_not_sanitized',
  'dingdandao_iab_output_too_large',
  'dingdandao_iab_normalization_failed',
]);

function blocked(reason) {
  throw new Error(reason);
}
function isPlainObject(value) {
  return value !== null
    && typeof value === 'object'
    && !Array.isArray(value)
    && Object.getPrototypeOf(value) === Object.prototype;
}

function hasExactKeys(value, keys) {
  return isPlainObject(value)
    && Object.keys(value).sort().join(',') === [...keys].sort().join(',');
}

function normalizedText(value) {
  return String(value ?? '').replace(/\s+/g, ' ').trim();
}

function normalizedCapturedAt(value) {
  const text = String(value || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/.test(text)) {
    blocked('dingdandao_iab_captured_at_invalid');
  }
  const parsed = new Date(text);
  if (!Number.isFinite(parsed.getTime())) {
    blocked('dingdandao_iab_captured_at_invalid');
  }
  return parsed.toISOString();
}

function assertNoSensitiveInputKeys(value) {
  const pending = [value];
  let visited = 0;
  while (pending.length > 0) {
    const current = pending.pop();
    if (!current || typeof current !== 'object') continue;
    visited += 1;
    if (visited > 100_000) blocked('dingdandao_iab_input_shape_invalid');
    if (Array.isArray(current)) {
      pending.push(...current);
      continue;
    }
    for (const [key, item] of Object.entries(current)) {
      if (key !== 'ntwNum' && SENSITIVE_KEY_PATTERN.test(key)) {
        blocked('dingdandao_iab_sensitive_key_forbidden');
      }
      if (item && typeof item === 'object') pending.push(item);
    }
  }
}

function recipeIdForRecord(recipes, path, requestBody) {
  const matches = recipes.filter((recipe) => (
    recipe.path === path
    && (
      !Object.hasOwn(recipe.body_template, 'type')
      || recipe.body_template.type === requestBody.type
    )
  ));
  if (matches.length !== 1) blocked('dingdandao_iab_recipe_plan_invalid');
  return matches[0].id;
}

function parsedRecord(record, targetDate, recipes, expectedPaths, networkContext) {
  if (!hasExactKeys(record, RECORD_KEYS)) {
    blocked('dingdandao_iab_record_shape_invalid');
  }
  let url;
  try {
    url = new URL(String(record.url || ''));
  } catch {
    blocked('dingdandao_iab_url_invalid');
  }
  if (url.origin !== DINGDANDAO_ORIGIN
    || url.username !== ''
    || url.password !== ''
  ) blocked('dingdandao_iab_origin_invalid');
  if (url.search !== '' || url.hash !== '' || !expectedPaths.has(url.pathname)) {
    blocked('dingdandao_iab_path_invalid');
  }
  if (String(record.method || '').trim().toUpperCase() !== 'POST'
    || record.status !== 200
  ) blocked('dingdandao_iab_transport_invalid');
  if (!isPlainObject(record.request_body)) {
    blocked('dingdandao_iab_request_scope_invalid');
  }
  const classification = classifyDingdandaoResponseRequest({
    path: url.pathname,
    requestBody: record.request_body,
    targetDate,
  });
  if (classification.allowed !== true) {
    blocked('dingdandao_iab_request_scope_invalid');
  }
  const ntwNum = String(record.request_body.ntwNum || '');
  if (networkContext.value === null) networkContext.value = ntwNum;
  else if (networkContext.value !== ntwNum) {
    blocked('dingdandao_iab_network_context_mismatch');
  }
  if (!isPlainObject(record.response_json)
    || String(record.response_json.code) !== '1'
    || record.response_json.errorDetail != null
    || !isPlainObject(record.response_json.data)
  ) blocked('dingdandao_iab_response_invalid');

  return {
    recipeId: recipeIdForRecord(recipes, url.pathname, record.request_body),
    normalized: {
      method: 'POST',
      path: url.pathname,
      status: 200,
      query_type: classification.query_type,
      fact_kind: classification.fact_kind,
      scope_status: classification.scope_status,
      payload: record.response_json,
    },
  };
}

function assertCaptureSanitized(capture, ntwNum) {
  const pending = [capture];
  let visited = 0;
  while (pending.length > 0) {
    const current = pending.pop();
    if (!current || typeof current !== 'object') continue;
    visited += 1;
    if (visited > 100_000) blocked('dingdandao_iab_capture_not_sanitized');
    if (Array.isArray(current)) {
      pending.push(...current);
      continue;
    }
    for (const [key, item] of Object.entries(current)) {
      if (FORBIDDEN_CAPTURE_KEYS.has(key.toLowerCase())) {
        blocked('dingdandao_iab_capture_not_sanitized');
      }
      if (typeof item === 'string' && item === ntwNum) {
        blocked('dingdandao_iab_capture_not_sanitized');
      }
      if (item && typeof item === 'object') pending.push(item);
    }
  }
}

export function normalizeDingdandaoIabCapture(
  input,
  {
    targetDate,
    expectedHotelName,
    expectedProviderHotelId,
    collectionMode = DINGDANDAO_COLLECTION_MODES.operatingIndicators,
    now = new Date(),
  },
) {
  if (collectionMode !== DINGDANDAO_COLLECTION_MODES.operatingIndicators) {
    blocked('dingdandao_iab_collection_mode_invalid');
  }
  const normalizedHotelName = normalizedText(expectedHotelName);
  const normalizedHotelId = String(expectedProviderHotelId || '').trim();
  if (!normalizedHotelName || normalizedHotelName.length > 160) {
    blocked('dingdandao_iab_expected_hotel_name_invalid');
  }
  if (!/^[A-Za-z0-9_-]{1,120}$/.test(normalizedHotelId)) {
    blocked('dingdandao_iab_expected_provider_hotel_id_invalid');
  }
  let sourceScope;
  try {
    sourceScope = dingdandaoSourceScopeForTargetDate(targetDate, now);
  } catch {
    blocked('dingdandao_iab_target_date_invalid');
  }
  assertNoSensitiveInputKeys(input);
  if (!hasExactKeys(input, INPUT_KEYS)) blocked('dingdandao_iab_input_shape_invalid');
  const capturedAt = normalizedCapturedAt(input.captured_at);
  const recipes = dingdandaoEndpointRecipes(collectionMode);
  if (!Array.isArray(input.records) || input.records.length !== recipes.length) {
    blocked('dingdandao_iab_record_count_invalid');
  }
  const expectedRecipeIds = recipes.map((recipe) => recipe.id);
  const expectedPaths = new Set(recipes.map((recipe) => recipe.path));
  const networkContext = { value: null };
  const normalizedRecords = [];
  const observedRecipeIds = [];
  for (const record of input.records) {
    const parsed = parsedRecord(
      record,
      targetDate,
      recipes,
      expectedPaths,
      networkContext,
    );
    normalizedRecords.push(parsed.normalized);
    observedRecipeIds.push(parsed.recipeId);
  }
  if (new Set(observedRecipeIds).size !== observedRecipeIds.length
    || [...observedRecipeIds].sort().join(',') !== [...expectedRecipeIds].sort().join(',')
  ) blocked('dingdandao_iab_recipe_plan_invalid');

  let capture;
  try {
    capture = buildCaptureFromDingdandaoResponses(normalizedRecords, {
      targetDate,
      capturedAt,
      collectionMode,
      sourceScope,
      captureSource: BROWSER_CAPTURE_SOURCE,
    });
  } catch {
    blocked('dingdandao_iab_capture_incomplete');
  }
  if (capture.provider_hotel_id !== normalizedHotelId
    || normalizedText(capture.provider_hotel_name) !== normalizedHotelName
  ) blocked('dingdandao_iab_hotel_identity_mismatch');
  if (!isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName: normalizedHotelName,
    expectedSourceScope: sourceScope,
  })) blocked('dingdandao_iab_capture_incomplete');
  if (capture.revenue_overview?.data_status !== 'verified') {
    blocked('dingdandao_iab_revenue_overview_incomplete');
  }
  if (capture.capture_evidence?.capture_source !== BROWSER_CAPTURE_SOURCE
    || capture.capture_evidence?.capture_strategy !== 'browser_response_supplement'
    || capture.capture_evidence?.response_evidence_type !== 'structured_json'
    || capture.capture_evidence?.recipe_count !== expectedRecipeIds.length
    || !/^[a-f0-9]{64}$/.test(capture.capture_evidence?.recipe_plan_hash || '')
  ) blocked('dingdandao_iab_provenance_invalid');
  assertCaptureSanitized(capture, networkContext.value);

  return {
    status: 'normalized_browser_response_supplement',
    capture,
    record_count: expectedRecipeIds.length,
    raw_response_exposed: false,
    session_material_exposed: false,
    sensitive_values_exposed: false,
  };
}

export function parseDingdandaoIabArguments(argv) {
  const allowed = new Set([
    'target-date',
    'expected-hotel-name',
    'expected-provider-hotel-id',
    'collection-mode',
  ]);
  const values = {};
  for (const argument of argv) {
    const match = String(argument).match(/^--([a-z-]+)=(.*)$/s);
    if (!match || !allowed.has(match[1]) || Object.hasOwn(values, match[1])) {
      blocked('dingdandao_iab_argument_invalid');
    }
    values[match[1]] = match[2];
  }
  if (Object.keys(values).length !== allowed.size) {
    blocked('dingdandao_iab_argument_invalid');
  }
  return {
    targetDate: values['target-date'],
    expectedHotelName: values['expected-hotel-name'],
    expectedProviderHotelId: values['expected-provider-hotel-id'],
    collectionMode: values['collection-mode'],
  };
}

async function readStdinJson() {
  const chunks = [];
  let size = 0;
  for await (const chunk of process.stdin) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    size += buffer.length;
    if (size > MAX_STDIN_BYTES) blocked('dingdandao_iab_input_too_large');
    chunks.push(buffer);
  }
  if (size === 0) blocked('dingdandao_iab_input_empty');
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    blocked('dingdandao_iab_input_json_invalid');
  }
}

function safeReason(error) {
  const reason = String(error?.message || '');
  return SAFE_REASONS.has(reason) ? reason : 'dingdandao_iab_normalization_failed';
}

async function main() {
  const options = parseDingdandaoIabArguments(process.argv.slice(2));
  const input = await readStdinJson();
  const result = normalizeDingdandaoIabCapture(input, options);
  const serialized = `${JSON.stringify(result)}\n`;
  if (Buffer.byteLength(serialized, 'utf8') > MAX_STDOUT_BYTES) {
    blocked('dingdandao_iab_output_too_large');
  }
  process.stdout.write(serialized);
}

const direct = process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url;
if (direct) {
  main().catch((error) => {
    process.stderr.write(`${JSON.stringify({
      status: 'blocked',
      reason: safeReason(error),
    })}\n`);
    process.exitCode = 1;
  });
}
