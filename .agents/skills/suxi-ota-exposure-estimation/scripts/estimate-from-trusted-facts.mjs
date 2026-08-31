#!/usr/bin/env node

import { createHash } from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { analyzeExposure, InputError } from './estimate-exposure-users.mjs';

export const ADAPTER_CONTRACT_VERSION = 'suxi.ota_exposure_estimation_trusted_fact_adapter.v1';
const STRICT_GATE = 'history_success+validation_verified+readback_verified';
const MIN_VERIFIED_PAIRS = 7;
const WINDOW_DAYS = 14;
const ALLOWED_PLATFORMS = ['ctrip', 'meituan'];

function objectValue(value, name) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new InputError('invalid_input', `${name} must be an object`);
  }
  return value;
}

function stringValue(value, name) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new InputError('invalid_input', `${name} must be a non-empty string`);
  }
  return value.trim();
}

function scopeId(value, name) {
  if ((typeof value !== 'string' && typeof value !== 'number') || String(value).trim() === '') {
    throw new InputError('invalid_input', `${name} must be a non-empty string or number`);
  }
  return String(value).trim();
}

function positiveInteger(value, name) {
  if (!Number.isInteger(value) || value <= 0) {
    throw new InputError('invalid_input', `${name} must be a positive integer`);
  }
  return value;
}

function countValue(value, name, { allowZero = false } = {}) {
  const minimum = allowZero ? 0 : 1;
  if (!Number.isSafeInteger(value) || value < minimum) {
    throw new InputError('invalid_input', `${name} must be an integer greater than or equal to ${minimum}`);
  }
  return value;
}

function isoDate(value, name) {
  const text = stringValue(value, name);
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
  if (!match) throw new InputError('invalid_input', `${name} must use YYYY-MM-DD`);
  const parsed = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  if (parsed.getUTCFullYear() !== Number(match[1])
    || parsed.getUTCMonth() !== Number(match[2]) - 1
    || parsed.getUTCDate() !== Number(match[3])
  ) {
    throw new InputError('invalid_input', `${name} is not a real calendar date`);
  }
  return text;
}

function cutoff(value, name) {
  const text = stringValue(value, name);
  const match = /^(\d{2}):(\d{2})$/.exec(text);
  if (!match || Number(match[1]) > 23 || Number(match[2]) > 59) {
    throw new InputError('invalid_input', `${name} must use a valid HH:mm cutoff`);
  }
  return text;
}

function strictReadback(value, name) {
  if (value !== true && value !== 1) {
    throw new InputError('strict_fact_gate_failed', `${name} must be true or 1`);
  }
  return true;
}

function scopeIdentity(raw) {
  const tenantId = positiveInteger(raw.tenant_id, 'scope.tenant_id');
  const systemHotelId = scopeId(raw.system_hotel_id, 'scope.system_hotel_id');
  const platform = stringValue(raw.platform, 'scope.platform').toLowerCase();
  if (!ALLOWED_PLATFORMS.includes(platform)) {
    throw new InputError('invalid_input', `scope.platform must be one of: ${ALLOWED_PLATFORMS.join(', ')}`);
  }
  const timezone = stringValue(raw.timezone, 'scope.timezone');
  if (timezone !== 'Asia/Shanghai') {
    throw new InputError('strict_fact_scope_mismatch', 'scope.timezone must be Asia/Shanghai');
  }
  const timeBasis = stringValue(raw.time_basis, 'scope.time_basis');
  if (timeBasis !== 'same_day_cumulative') {
    throw new InputError('metric_contract_mismatch', 'scope.time_basis must be same_day_cumulative');
  }
  return {
    tenant_id: tenantId,
    system_hotel_id: systemHotelId,
    platform,
    business_date: isoDate(raw.business_date, 'scope.business_date'),
    timezone,
    source_path: stringValue(raw.source_path, 'scope.source_path'),
    metric_definition: stringValue(raw.metric_definition, 'scope.metric_definition'),
    metric_definition_version: stringValue(
      raw.metric_definition_version,
      'scope.metric_definition_version',
    ),
    time_basis: timeBasis,
    cumulative_cutoff: cutoff(raw.cumulative_cutoff, 'scope.cumulative_cutoff'),
  };
}

function assertIdentity(row, scope, prefix) {
  const rowIdentity = {
    tenant_id: positiveInteger(row.tenant_id, `${prefix}.tenant_id`),
    system_hotel_id: scopeId(row.system_hotel_id, `${prefix}.system_hotel_id`),
    platform: stringValue(row.platform, `${prefix}.platform`).toLowerCase(),
    timezone: stringValue(row.timezone, `${prefix}.timezone`),
    source_path: stringValue(row.source_path, `${prefix}.source_path`),
    metric_definition_version: stringValue(
      row.metric_definition_version,
      `${prefix}.metric_definition_version`,
    ),
    time_basis: stringValue(row.time_basis, `${prefix}.time_basis`),
    cumulative_cutoff: cutoff(row.cumulative_cutoff, `${prefix}.cumulative_cutoff`),
  };
  for (const [field, expected] of Object.entries(scope)) {
    if (!Object.hasOwn(rowIdentity, field)) continue;
    if (String(rowIdentity[field]) !== String(expected)) {
      throw new InputError(
        'strict_fact_scope_mismatch',
        `${prefix}.${field} does not match the declared strict-fact scope`,
      );
    }
  }
}

function assertStrictGate(row, prefix) {
  if (stringValue(row.history_status, `${prefix}.history_status`).toLowerCase() !== 'success') {
    throw new InputError('strict_fact_gate_failed', `${prefix}.history_status must be success`);
  }
  if (stringValue(row.validation_status, `${prefix}.validation_status`).toLowerCase() !== 'verified') {
    throw new InputError('strict_fact_gate_failed', `${prefix}.validation_status must be verified`);
  }
  strictReadback(row.readback_verified, `${prefix}.readback_verified`);
}

function assertMetric(row, prefix, metricField, unitField, metric, unit) {
  if (stringValue(row[metricField], `${prefix}.${metricField}`) !== metric
    || stringValue(row[unitField], `${prefix}.${unitField}`) !== unit
  ) {
    throw new InputError(
      'metric_contract_mismatch',
      `${prefix} must use ${metric}/${unit}`,
    );
  }
}

function normalizeFact(raw, scope, prefix, { target = false } = {}) {
  const row = objectValue(raw, prefix);
  assertIdentity(row, scope, prefix);
  assertStrictGate(row, prefix);
  assertMetric(row, prefix, 'browse_metric', 'browse_unit', 'detail_visitors', 'people');
  if (target && row.exposure_users !== undefined && row.exposure_users !== null) {
    throw new InputError(
      'target_exposure_already_available',
      `${prefix}.exposure_users is already available; an estimate must not replace an observed fact`,
    );
  }
  const date = isoDate(row.data_date, `${prefix}.data_date`);
  if (target && date !== scope.business_date) {
    throw new InputError(
      'strict_fact_date_mismatch',
      `${prefix}.data_date must equal scope.business_date`,
    );
  }
  const normalized = {
    date,
    detail_visitors: countValue(
      row.detail_visitors,
      `${prefix}.detail_visitors`,
      { allowZero: target },
    ),
    source_ref: stringValue(row.source_ref, `${prefix}.source_ref`),
  };
  if (!target) {
    assertMetric(row, prefix, 'exposure_metric', 'exposure_unit', 'exposure_users', 'people');
    normalized.exposure_users = countValue(
      row.exposure_users,
      `${prefix}.exposure_users`,
    );
  }
  return normalized;
}

function scopeKey(scope) {
  const identity = [
    ADAPTER_CONTRACT_VERSION,
    scope.tenant_id,
    scope.system_hotel_id,
    scope.platform,
    scope.source_path,
    scope.metric_definition_version,
    scope.time_basis,
    scope.cumulative_cutoff,
    scope.timezone,
  ];
  return `trusted-fact:${createHash('sha256').update(JSON.stringify(identity)).digest('hex')}`;
}

export function adaptTrustedFacts(payload) {
  const input = objectValue(payload, 'payload');
  if (stringValue(input.contract_version, 'contract_version') !== ADAPTER_CONTRACT_VERSION) {
    throw new InputError('invalid_input', `contract_version must be ${ADAPTER_CONTRACT_VERSION}`);
  }
  const scope = scopeIdentity(objectValue(input.scope, 'scope'));
  const target = normalizeFact(input.target_fact, scope, 'target_fact', { target: true });
  if (!Array.isArray(input.calibration_facts)) {
    throw new InputError('invalid_input', 'calibration_facts must be an array');
  }
  const calibration = input.calibration_facts.map((row, index) => normalizeFact(
    row,
    scope,
    `calibration_facts[${index}]`,
  ));
  const key = scopeKey(scope);
  const sharedRowScope = {
    platform: scope.platform,
    system_hotel_id: scope.system_hotel_id,
    scope_key: key,
    source_path: scope.source_path,
    metric_definition_version: scope.metric_definition_version,
    target_metric: 'exposure_users',
    target_unit: 'people',
    browse_metric: 'detail_visitors',
    browse_unit: 'people',
    timezone: scope.timezone,
    time_basis: scope.time_basis,
    cumulative_cutoff: scope.cumulative_cutoff,
  };
  return {
    estimate_input: {
      method: 'rolling_median',
      scope: {
        ...sharedRowScope,
        business_date: scope.business_date,
        metric_definition: scope.metric_definition,
      },
      options: {
        min_verified_pairs: MIN_VERIFIED_PAIRS,
        window_days: WINDOW_DAYS,
      },
      calibration_rows: calibration.map((row) => ({
        ...sharedRowScope,
        date: row.date,
        detail_visitors_actual: row.detail_visitors,
        exposure_users_actual: row.exposure_users,
        quality: 'verified_actual',
        source_ref: row.source_ref,
      })),
      target: {
        ...sharedRowScope,
        date: target.date,
        detail_visitors: target.detail_visitors,
        detail_visitors_quality: 'observed_actual',
        source_ref: target.source_ref,
      },
    },
    receipt: {
      contract_version: ADAPTER_CONTRACT_VERSION,
      status: 'strict_facts_accepted',
      strict_gate: STRICT_GATE,
      tenant_id: scope.tenant_id,
      system_hotel_id: scope.system_hotel_id,
      platform: scope.platform,
      business_date: scope.business_date,
      source_path: scope.source_path,
      cumulative_cutoff: scope.cumulative_cutoff,
      accepted_verified_pairs: calibration.length,
      min_verified_pairs: MIN_VERIFIED_PAIRS,
      window_days: WINDOW_DAYS,
      source_refs: [target.source_ref, ...calibration.map((row) => row.source_ref)],
      output_policy: {
        success_quality_status: 'estimate_only',
        decision_eligible: false,
        writeback_allowed: false,
        platform_fact_status: 'unchanged',
      },
    },
  };
}

export function estimateExposureFromTrustedFacts(payload) {
  const adapted = adaptTrustedFacts(payload);
  const result = analyzeExposure(adapted.estimate_input);
  if (result.decision_eligible !== false
    || result.writeback_allowed !== false
    || result.platform_fact_status !== 'unchanged'
    || (result.status === 'estimated' && result.quality_status !== 'estimate_only')
  ) {
    throw new InputError('adapter_output_invariant_failed', 'estimator violated the trusted-fact output boundary');
  }
  return {
    ...result,
    adapter_contract_version: ADAPTER_CONTRACT_VERSION,
    adapter_receipt: adapted.receipt,
  };
}

function parseArgs(argv) {
  const args = { input: null, pretty: false };
  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];
    if (token === '--input') {
      args.input = argv[index + 1] ?? null;
      index += 1;
    } else if (token === '--pretty') {
      args.pretty = true;
    } else if (token === '--help' || token === '-h') {
      args.help = true;
    } else {
      throw new InputError('invalid_arguments', `unknown argument: ${token}`);
    }
  }
  return args;
}

async function main() {
  let args;
  try {
    args = parseArgs(process.argv.slice(2));
    if (args.help) {
      process.stdout.write('Usage: node estimate-from-trusted-facts.mjs --input <input.json> [--pretty]\n');
      return;
    }
    if (!args.input) throw new InputError('invalid_arguments', '--input is required');
    const payload = JSON.parse(await fs.readFile(path.resolve(args.input), 'utf8'));
    const result = estimateExposureFromTrustedFacts(payload);
    process.stdout.write(`${JSON.stringify(result, null, args.pretty ? 2 : 0)}\n`);
  } catch (error) {
    const result = {
      adapter_contract_version: ADAPTER_CONTRACT_VERSION,
      status: 'invalid_input',
      error_code: error instanceof InputError ? error.code : 'unexpected_error',
      message: error instanceof Error ? error.message : String(error),
      evidence_type: 'none',
      quality_status: 'invalid_input',
      decision_eligible: false,
      writeback_allowed: false,
      platform_fact_status: 'unchanged',
    };
    process.stderr.write(`${JSON.stringify(result, null, args?.pretty ? 2 : 0)}\n`);
    process.exitCode = 2;
  }
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : null;
if (invokedPath && invokedPath === path.resolve(fileURLToPath(import.meta.url))) {
  await main();
}
