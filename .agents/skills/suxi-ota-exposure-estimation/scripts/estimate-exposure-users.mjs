#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const SCHEMA_VERSION = 'suxi.ota_exposure_estimation.v1';
const FORMULA_VERSIONS = {
  anchored_inverse: 'same_day_anchored_inverse_rate.v1',
  rolling_median: 'same_scope_rolling_median_multiplier.v1',
};
const DEFAULT_MIN_PAIRS = 7;
const DEFAULT_WINDOW_DAYS = 14;
const DEFAULT_SELF_CHECK_TOLERANCE = 0.05;
const MANUAL_EXCLUSION_REASONS = [
  'verified_source_definition_change',
  'verified_cumulative_cutoff_mismatch',
  'verified_capture_corruption',
  'verified_duplicate_batch',
];

export class InputError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'InputError';
    this.code = code;
  }
}

function assertObject(value, name) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new InputError('invalid_input', `${name} must be an object`);
  }
  return value;
}

function requiredString(value, name) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new InputError('invalid_input', `${name} must be a non-empty string`);
  }
  return value.trim();
}

function provenanceRef(value, name) {
  const text = requiredString(value, name);
  if (text.length > 500 || /[\u0000-\u001F\u007F]/.test(text)) {
    throw new InputError('unsafe_source_ref', `${name} must be a short single-line non-sensitive reference`);
  }
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(text) || /^[a-z]:[\\/]/i.test(text) || /^(?:\\\\|\/Users\/)/i.test(text)) {
    throw new InputError('unsafe_source_ref', `${name} must not contain a URL or absolute local path`);
  }
  if (/(?:authorization|bearer\s|cookie|access[_-]?token|refresh[_-]?token|session(?:id)?|password|passwd|api[_-]?key|secret)\s*[:=]/i.test(text)) {
    throw new InputError('unsafe_source_ref', `${name} appears to contain credential material`);
  }
  if (/(?:\\|\/)(?:User Data|AppData)(?:\\|\/)/i.test(text)) {
    throw new InputError('unsafe_source_ref', `${name} must not contain a browser profile path`);
  }
  return text;
}

function enumValue(value, name, allowed) {
  const text = requiredString(value, name);
  if (!allowed.includes(text)) {
    throw new InputError('invalid_input', `${name} must be one of: ${allowed.join(', ')}`);
  }
  return text;
}

function scopeId(value, name) {
  if ((typeof value !== 'string' && typeof value !== 'number') || String(value).trim() === '') {
    throw new InputError('invalid_input', `${name} must be a non-empty string or number`);
  }
  return String(value).trim();
}

function finiteNumber(value, name, { min = -Infinity, max = Infinity, exclusiveMin = false } = {}) {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new InputError('invalid_input', `${name} must be a finite number`);
  }
  if ((exclusiveMin ? value <= min : value < min) || value > max) {
    const left = exclusiveMin ? `greater than ${min}` : `at least ${min}`;
    throw new InputError('invalid_input', `${name} must be ${left} and at most ${max}`);
  }
  return value;
}

function integer(value, name, { min = 0, max = Number.MAX_SAFE_INTEGER } = {}) {
  if (!Number.isInteger(value) || value < min || value > max) {
    throw new InputError('invalid_input', `${name} must be an integer between ${min} and ${max}`);
  }
  return value;
}

function isoDate(value, name) {
  const text = requiredString(value, name);
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
  if (!match) {
    throw new InputError('invalid_input', `${name} must use YYYY-MM-DD`);
  }
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const parsed = new Date(Date.UTC(year, month - 1, day));
  if (parsed.getUTCFullYear() !== year || parsed.getUTCMonth() !== month - 1 || parsed.getUTCDate() !== day) {
    throw new InputError('invalid_input', `${name} is not a real calendar date`);
  }
  return text;
}

function timeMinutes(value, name) {
  const text = requiredString(value, name);
  const match = /^(\d{2}):(\d{2})$/.exec(text);
  if (!match) throw new InputError('invalid_input', `${name} must use HH:mm`);
  const hour = Number(match[1]);
  const minute = Number(match[2]);
  if (hour > 23 || minute > 59) throw new InputError('invalid_input', `${name} is not a valid time`);
  return { text, minutes: hour * 60 + minute };
}

function dayNumber(date) {
  return Date.parse(`${date}T00:00:00Z`) / 86_400_000;
}

function isWithinPriorDays(date, targetDate, windowDays) {
  const difference = dayNumber(targetDate) - dayNumber(date);
  return difference >= 1 && difference <= windowDays;
}

function median(values) {
  if (values.length === 0) return null;
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 1
    ? sorted[middle]
    : (sorted[middle - 1] + sorted[middle]) / 2;
}

function quantile(values, q) {
  if (values.length === 0) return null;
  const sorted = [...values].sort((a, b) => a - b);
  if (sorted.length === 1) return sorted[0];
  const index = (sorted.length - 1) * q;
  const lower = Math.floor(index);
  const upper = Math.ceil(index);
  const weight = index - lower;
  return sorted[lower] * (1 - weight) + sorted[upper] * weight;
}

function round(value, digits = 6) {
  const factor = 10 ** digits;
  return Math.round((value + Number.EPSILON) * factor) / factor;
}

function roundCount(value, name = 'count result') {
  if (!Number.isFinite(value) || value < 0) {
    throw new InputError('unsafe_numeric_result', `${name} must be a finite non-negative number`);
  }
  const rounded = Math.round(value);
  if (!Number.isSafeInteger(rounded)) {
    throw new InputError('unsafe_numeric_result', `${name} exceeds the safe integer range`);
  }
  return rounded;
}

function sameScope(row, scope, prefix) {
  const platform = requiredString(row.platform, `${prefix}.platform`).toLowerCase();
  const hotelId = scopeId(row.system_hotel_id, `${prefix}.system_hotel_id`);
  const scopeKey = requiredString(row.scope_key, `${prefix}.scope_key`);
  if (platform !== scope.platform || hotelId !== scope.system_hotel_id || scopeKey !== scope.scope_key) {
    throw new InputError(
      'scope_mismatch',
      `${prefix} does not match the declared platform, hotel, or scope_key`,
    );
  }
  const exactFields = [
    'source_path',
    'metric_definition_version',
    'target_metric',
    'target_unit',
    'browse_metric',
    'browse_unit',
    'timezone',
    'time_basis',
    'cumulative_cutoff',
  ];
  for (const field of exactFields) {
    if (row[field] !== undefined && String(row[field]) !== String(scope[field])) {
      throw new InputError('scope_mismatch', `${prefix}.${field} conflicts with the declared scope`);
    }
  }
}

function parseScope(payload) {
  const raw = assertObject(payload.scope, 'scope');
  const cutoff = timeMinutes(raw.cumulative_cutoff, 'scope.cumulative_cutoff');
  const sourceFileSha256 = raw.source_file_sha256 === undefined
    ? null
    : requiredString(raw.source_file_sha256, 'scope.source_file_sha256').toUpperCase();
  if (sourceFileSha256 !== null && !/^[A-F0-9]{64}$/.test(sourceFileSha256)) {
    throw new InputError('invalid_input', 'scope.source_file_sha256 must be a 64-character SHA-256 hex digest');
  }
  const scope = {
    platform: requiredString(raw.platform, 'scope.platform').toLowerCase(),
    system_hotel_id: scopeId(raw.system_hotel_id, 'scope.system_hotel_id'),
    business_date: isoDate(raw.business_date, 'scope.business_date'),
    timezone: raw.timezone ? requiredString(raw.timezone, 'scope.timezone') : 'Asia/Shanghai',
    scope_key: provenanceRef(raw.scope_key, 'scope.scope_key'),
    source_path: provenanceRef(raw.source_path, 'scope.source_path'),
    source_file_sha256: sourceFileSha256,
    metric_definition: provenanceRef(raw.metric_definition, 'scope.metric_definition'),
    metric_definition_version: provenanceRef(
      raw.metric_definition_version,
      'scope.metric_definition_version',
    ),
    time_basis: provenanceRef(raw.time_basis, 'scope.time_basis'),
    cumulative_cutoff: cutoff.text,
    target_metric: requiredString(raw.target_metric, 'scope.target_metric'),
    target_unit: requiredString(raw.target_unit, 'scope.target_unit'),
    browse_metric: requiredString(raw.browse_metric, 'scope.browse_metric'),
    browse_unit: requiredString(raw.browse_unit, 'scope.browse_unit'),
  };
  return scope;
}

function commonResult(method, scope) {
  return {
    schema_version: SCHEMA_VERSION,
    formula_version: FORMULA_VERSIONS[method] ?? null,
    method,
    scope,
    target_metric: scope.target_metric,
    target_unit: scope.target_unit,
    decision_eligible: false,
    writeback_allowed: false,
    platform_fact_status: 'unchanged',
  };
}

function notApplicable(method, scope, reason) {
  return {
    ...commonResult(method, scope),
    status: 'not_applicable',
    evidence_type: 'none',
    quality_status: 'not_applicable',
    estimate: null,
    alerts: [reason],
  };
}

function verifyMetricPair(method, scope) {
  if (
    scope.target_metric !== 'exposure_users'
    || scope.target_unit !== 'people'
    || scope.browse_metric !== 'detail_visitors'
    || scope.browse_unit !== 'people'
    || scope.time_basis !== 'same_day_cumulative'
  ) {
    return notApplicable(
      method,
      scope,
      'Only exposure_users/people estimated from detail_visitors/people is supported; exposure volume or impression counts require platform facts.',
    );
  }
  return null;
}

function parseTarget(payload, scope) {
  const target = assertObject(payload.target, 'target');
  const date = isoDate(target.date, 'target.date');
  if (date !== scope.business_date) {
    throw new InputError('scope_mismatch', 'target.date must equal scope.business_date');
  }
  sameScope(target, scope, 'target');
  return {
    ...target,
    date,
    detail_visitors: integer(target.detail_visitors, 'target.detail_visitors', { min: 0 }),
    detail_visitors_quality: enumValue(
      target.detail_visitors_quality,
      'target.detail_visitors_quality',
      ['verified_actual', 'observed_actual', 'imputed', 'derived', 'unverified'],
    ),
    source_ref: provenanceRef(target.source_ref, 'target.source_ref'),
  };
}

function targetIsObserved(target) {
  return target.detail_visitors_quality === 'observed_actual'
    || target.detail_visitors_quality === 'verified_actual';
}

function selfCheck(target, toleranceRatio = DEFAULT_SELF_CHECK_TOLERANCE) {
  const hasRate = target.payment_conversion_rate_pct !== undefined
    && target.payment_conversion_rate_pct !== null;
  const hasOrders = target.paid_orders !== undefined && target.paid_orders !== null;

  if (!hasRate && !hasOrders) {
    return { status: 'not_run', reason: 'payment conversion rate and paid orders were not provided' };
  }
  if (!hasRate || !hasOrders) {
    throw new InputError(
      'incomplete_self_check_pair',
      'payment_conversion_rate_pct and paid_orders must be supplied together',
    );
  }

  const rate = finiteNumber(
    target.payment_conversion_rate_pct,
    'target.payment_conversion_rate_pct',
    { min: 0, max: 100 },
  );
  const orders = integer(target.paid_orders, 'target.paid_orders', { min: 0 });
  const expectedOrders = target.detail_visitors * rate / 100;
  const absoluteDifference = Math.abs(expectedOrders - orders);
  const toleranceOrders = Math.max(1, orders * toleranceRatio);
  const zeroVisitorContradiction = target.detail_visitors === 0 && orders > 0;
  return {
    status: !zeroVisitorContradiction && absoluteDifference <= toleranceOrders ? 'pass' : 'fail',
    reason: zeroVisitorContradiction ? 'zero_detail_visitors_with_positive_paid_orders' : null,
    formula: 'detail_visitors * payment_conversion_rate_pct / 100',
    expected_orders: round(expectedOrders),
    observed_orders: orders,
    absolute_difference: round(absoluteDifference),
    tolerance_orders: round(toleranceOrders),
    tolerance_ratio: toleranceRatio,
  };
}

function parseHourly(target, scope) {
  if (target.hourly_cumulative_detail_visitors === undefined) return [];
  if (!Array.isArray(target.hourly_cumulative_detail_visitors)) {
    throw new InputError('invalid_input', 'target.hourly_cumulative_detail_visitors must be an array');
  }
  let priorVisitors = -Infinity;
  let priorMinutes = -Infinity;
  const cutoff = timeMinutes(scope.cumulative_cutoff, 'scope.cumulative_cutoff').minutes;
  return target.hourly_cumulative_detail_visitors.map((entry, index) => {
    const row = assertObject(entry, `target.hourly_cumulative_detail_visitors[${index}]`);
    const parsedTime = timeMinutes(
      row.time,
      `target.hourly_cumulative_detail_visitors[${index}].time`,
    );
    const visitors = integer(
      row.detail_visitors,
      `target.hourly_cumulative_detail_visitors[${index}].detail_visitors`,
      { min: 0 },
    );
    if (parsedTime.minutes <= priorMinutes) {
      throw new InputError(
        'invalid_cumulative_time_series',
        `hourly cumulative timestamps must be strictly increasing; conflict at ${parsedTime.text}`,
      );
    }
    if (parsedTime.minutes > cutoff) {
      throw new InputError(
        'snapshot_mismatch',
        `hourly timestamp ${parsedTime.text} is after the declared cutoff ${scope.cumulative_cutoff}`,
      );
    }
    if (visitors > target.detail_visitors) {
      throw new InputError(
        'snapshot_mismatch',
        `hourly cumulative detail visitors exceed the target snapshot at ${parsedTime.text}`,
      );
    }
    if (visitors < priorVisitors) {
      throw new InputError(
        'non_monotonic_cumulative_series',
        `hourly cumulative detail visitors decrease at ${parsedTime.text}`,
      );
    }
    if (parsedTime.minutes === cutoff && visitors !== target.detail_visitors) {
      throw new InputError(
        'snapshot_mismatch',
        `the cumulative value at ${scope.cumulative_cutoff} must equal target.detail_visitors`,
      );
    }
    priorMinutes = parsedTime.minutes;
    priorVisitors = visitors;
    return { time: parsedTime.text, detail_visitors: visitors };
  });
}

function estimateHourly(hourly, multiplier) {
  return hourly.map((row) => ({
    time: row.time,
    detail_visitors: row.detail_visitors,
    exposure_users_estimate: roundCount(row.detail_visitors * multiplier),
    cumulative_only: true,
  }));
}

function roundedRateInterval(detailVisitors, rateUsed, precision) {
  const halfStep = 0.5 * (10 ** (-precision));
  const lowRate = Math.max(Number.EPSILON, rateUsed - halfStep);
  const highRate = Math.min(1, rateUsed + halfStep);
  return {
    low: roundCount(detailVisitors / highRate, 'rounded-rate interval low'),
    high: roundCount(detailVisitors / lowRate, 'rounded-rate interval high'),
    unit: 'people',
    method: 'rate_rounding_bounds',
    rate_bounds: [round(lowRate, precision + 2), round(highRate, precision + 2)],
    coverage_claim: false,
  };
}

function analyzeAnchored(payload, scope) {
  const target = parseTarget(payload, scope);
  const anchor = assertObject(payload.anchor, 'anchor');
  sameScope(anchor, scope, 'anchor');
  const anchorDate = isoDate(anchor.date, 'anchor.date');
  if (anchorDate !== target.date) {
    return {
      ...commonResult('anchored_inverse', scope),
      status: 'insufficient_baseline',
      evidence_type: 'none',
      quality_status: 'insufficient_baseline',
      estimate: null,
      alerts: ['A single anchor may only replay the same date and cumulative scope; use rolling_median for another date.'],
    };
  }
  const anchorQuality = enumValue(
    anchor.quality,
    'anchor.quality',
    ['verified_actual', 'observed_actual', 'imputed', 'derived', 'unverified'],
  );
  if (anchorQuality !== 'verified_actual') {
    return {
      ...commonResult('anchored_inverse', scope),
      status: 'reference_only',
      evidence_type: 'input_assessment',
      quality_status: 'reference_only',
      estimate: null,
      alerts: ['The anchor is not a verified actual exposure/detail-visitors pair.'],
    };
  }

  const anchorVisitors = integer(anchor.detail_visitors_actual, 'anchor.detail_visitors_actual', { min: 1 });
  const anchorExposure = integer(anchor.exposure_users_actual, 'anchor.exposure_users_actual', { min: 1 });
  const rawRate = anchorVisitors / anchorExposure;
  if (rawRate <= 0 || rawRate > 1) {
    throw new InputError(
      'metric_definition_conflict',
      'The anchor browse/exposure rate must be greater than 0 and at most 1 for this metric pair.',
    );
  }

  const ratePolicy = payload.rate_policy ?? 'exact';
  if (ratePolicy !== 'exact' && ratePolicy !== 'rounded') {
    throw new InputError('invalid_input', 'rate_policy must be exact or rounded');
  }
  const precision = ratePolicy === 'rounded'
    ? integer(payload.rate_precision ?? 4, 'rate_precision', { min: 1, max: 8 })
    : null;
  const rateUsed = ratePolicy === 'rounded' ? Number(rawRate.toFixed(precision)) : rawRate;
  if (!Number.isFinite(rateUsed) || rateUsed <= 0) {
    throw new InputError(
      'rounded_rate_zero',
      'The selected rate precision rounds the positive anchor rate to zero; use exact mode or higher precision.',
    );
  }
  const multiplier = 1 / rateUsed;
  if (!Number.isFinite(multiplier) || multiplier <= 0) {
    throw new InputError('unsafe_numeric_result', 'The anchored multiplier is not finite and positive');
  }
  const hourly = parseHourly(target, scope);
  const check = selfCheck(target);
  const baseline = {
    kind: 'same_day_verified_anchor',
    anchor_date: anchorDate,
    raw_browse_per_exposure_rate: round(rawRate, 12),
    rate_policy: ratePolicy,
    rate_precision: precision,
    rate_used: round(rateUsed, 12),
    exposure_per_browse_multiplier: round(multiplier, 12),
    source_ref: provenanceRef(anchor.source_ref, 'anchor.source_ref'),
    reusable_for_other_dates: false,
  };

  if (!targetIsObserved(target)) {
    return {
      ...commonResult('anchored_inverse', scope),
      status: 'reference_only',
      evidence_type: 'input_assessment',
      quality_status: 'reference_only',
      baseline,
      self_check: check,
      estimate: null,
      alerts: ['Target detail visitors are imputed, derived, or unverified; no exposure estimate was released.'],
    };
  }
  if (check.status === 'fail') {
    return {
      ...commonResult('anchored_inverse', scope),
      status: 'data_error',
      evidence_type: 'validation_failure',
      quality_status: 'data_error',
      baseline,
      self_check: check,
      estimate: null,
      alerts: ['Browse, payment-conversion, and paid-order inputs fail the same-snapshot self-check.'],
    };
  }

  const value = roundCount(target.detail_visitors * multiplier, 'anchored exposure estimate');
  return {
    ...commonResult('anchored_inverse', scope),
    status: 'estimated',
    evidence_type: 'derived_estimate',
    quality_status: 'estimate_only',
    baseline,
    self_check: check,
    estimate: {
      value,
      unit: 'people',
      kind: 'algebraic_inverse',
      formula: 'round(detail_visitors / rate_used)',
      interval: ratePolicy === 'rounded'
        ? roundedRateInterval(target.detail_visitors, rateUsed, precision)
        : null,
      input_lineage: [target.source_ref, baseline.source_ref],
    },
    hourly_cumulative_estimates: estimateHourly(hourly, multiplier),
    assumptions: [
      'same hotel, platform, date, source module, and cumulative time basis',
      'the anchor pair is a verified actual pair',
      'the output is an estimate and does not change the platform fact state',
    ],
    alerts: ratePolicy === 'rounded'
      ? ['The estimate interval reflects rate rounding only; it is not a statistical confidence interval.']
      : [],
  };
}

function parseCalibrationRows(payload, scope, targetDate) {
  if (!Array.isArray(payload.calibration_rows)) {
    throw new InputError('invalid_input', 'calibration_rows must be an array');
  }
  const seenDates = new Set();
  return payload.calibration_rows.map((raw, index) => {
    const row = assertObject(raw, `calibration_rows[${index}]`);
    sameScope(row, scope, `calibration_rows[${index}]`);
    const date = isoDate(row.date, `calibration_rows[${index}].date`);
    if (date >= targetDate) {
      throw new InputError(
        'target_leakage',
        `calibration row ${date} is not before target date ${targetDate}`,
      );
    }
    if (seenDates.has(date)) {
      throw new InputError('duplicate_calibration_date', `duplicate calibration date ${date}`);
    }
    seenDates.add(date);
    const quality = enumValue(
      row.quality,
      `calibration_rows[${index}].quality`,
      ['verified_actual', 'observed_actual', 'derived_from_exposure', 'imputed', 'unverified'],
    );
    const sourceRef = provenanceRef(row.source_ref, `calibration_rows[${index}].source_ref`);
    const detailVisitors = integer(
      row.detail_visitors_actual,
      `calibration_rows[${index}].detail_visitors_actual`,
      { min: 1 },
    );
    const exposureUsers = integer(
      row.exposure_users_actual,
      `calibration_rows[${index}].exposure_users_actual`,
      { min: 1 },
    );
    const rate = detailVisitors / exposureUsers;
    if (rate <= 0 || rate > 1) {
      throw new InputError(
        'metric_definition_conflict',
        `calibration row ${date} has browse/exposure rate outside (0, 1]`,
      );
    }
    const eventStatus = row.event_status === undefined || row.event_status === null
      ? null
      : enumValue(
          row.event_status,
          `calibration_rows[${index}].event_status`,
          ['verified_event_outlier'],
        );
    const baselineEligible = row.baseline_eligible !== false;
    let exclusionReason = null;
    if (!baselineEligible && eventStatus !== 'verified_event_outlier') {
      if (row.exclusion_reason === undefined) {
        throw new InputError(
          'invalid_input',
          `calibration_rows[${index}].exclusion_reason is required when baseline_eligible is false`,
        );
      }
      exclusionReason = enumValue(
        row.exclusion_reason,
        `calibration_rows[${index}].exclusion_reason`,
        MANUAL_EXCLUSION_REASONS,
      );
    }
    const exclusionSourceRef = !baselineEligible && eventStatus !== 'verified_event_outlier'
      ? provenanceRef(
          row.exclusion_source_ref,
          `calibration_rows[${index}].exclusion_source_ref`,
        )
      : null;
    return {
      date,
      quality,
      source_ref: sourceRef,
      detail_visitors_actual: detailVisitors,
      exposure_users_actual: exposureUsers,
      multiplier: exposureUsers / detailVisitors,
      event_status: eventStatus,
      baseline_eligible: baselineEligible,
      exclusion_reason: exclusionReason,
      exclusion_source_ref: exclusionSourceRef,
    };
  }).sort((a, b) => a.date.localeCompare(b.date));
}

function analyzeRolling(payload, scope) {
  const target = parseTarget(payload, scope);
  const options = payload.options === undefined ? {} : assertObject(payload.options, 'options');
  const minPairs = integer(options.min_verified_pairs ?? DEFAULT_MIN_PAIRS, 'options.min_verified_pairs', {
    min: DEFAULT_MIN_PAIRS,
    max: 60,
  });
  const windowDays = integer(options.window_days ?? DEFAULT_WINDOW_DAYS, 'options.window_days', {
    min: minPairs,
    max: 90,
  });
  const driftRatio = options.drift_ratio === undefined || options.drift_ratio === null
    ? null
    : finiteNumber(options.drift_ratio, 'options.drift_ratio', {
        min: 0,
        exclusiveMin: true,
        max: 1,
      });
  const driftPolicyRef = driftRatio === null
    ? null
    : provenanceRef(options.drift_policy_ref, 'options.drift_policy_ref');
  if (driftRatio === null && options.drift_policy_ref !== undefined) {
    throw new InputError(
      'invalid_input',
      'options.drift_policy_ref requires options.drift_ratio',
    );
  }
  const rows = parseCalibrationRows(payload, scope, target.date);
  const exclusions = [];
  const verifiedRows = [];

  for (const row of rows) {
    if (row.quality !== 'verified_actual') {
      exclusions.push({ date: row.date, reason: `quality_${row.quality}` });
    } else {
      verifiedRows.push(row);
    }
  }

  const baselineRows = [];
  const validationObservations = [];
  for (const row of verifiedRows) {
    const priorWindow = baselineRows.filter((prior) => isWithinPriorDays(prior.date, row.date, windowDays));
    const priorMedian = priorWindow.length >= minPairs
      ? median(priorWindow.map((prior) => prior.multiplier))
      : null;
    const validationObservation = priorMedian === null
      ? null
      : (() => {
          const predicted = roundCount(
            row.detail_visitors_actual * priorMedian,
            `rolling replay estimate for ${row.date}`,
          );
          return {
            date: row.date,
            history_count: priorWindow.length,
            predicted,
            actual: row.exposure_users_actual,
            absolute_percentage_error: Math.abs(predicted - row.exposure_users_actual)
              / row.exposure_users_actual,
          };
        })();

    let exclusion = null;
    if (row.event_status === 'verified_event_outlier') {
      exclusion = { date: row.date, reason: 'verified_event_outlier' };
    } else if (!row.baseline_eligible) {
      exclusion = {
        date: row.date,
        reason: `baseline_ineligible_${row.exclusion_reason}`,
        source_ref: row.exclusion_source_ref,
      };
    } else if (priorMedian !== null && driftRatio !== null) {
      const relativeDrift = Math.abs(row.multiplier - priorMedian) / priorMedian;
      if (relativeDrift > driftRatio) {
        exclusion = {
          date: row.date,
          reason: 'multiplier_drift_outlier',
          prior_pair_count: priorWindow.length,
          relative_drift: round(relativeDrift),
        };
      }
    }

    if (validationObservation !== null) {
      validationObservations.push({
        ...validationObservation,
        excluded_from_future_baseline: exclusion !== null,
        exclusion_reason: exclusion?.reason ?? null,
      });
    }
    if (exclusion !== null) {
      exclusions.push(exclusion);
    } else {
      baselineRows.push(row);
    }
  }

  const targetRows = baselineRows.filter((row) => isWithinPriorDays(row.date, target.date, windowDays));
  const targetPreDriftRows = verifiedRows.filter((row) => isWithinPriorDays(row.date, target.date, windowDays));

  if (targetRows.length < minPairs) {
    return {
      ...commonResult('rolling_median', scope),
      status: 'insufficient_baseline',
      evidence_type: 'none',
      quality_status: 'insufficient_baseline',
      baseline: {
        required_verified_pairs: minPairs,
        eligible_verified_pairs: targetRows.length,
        pre_drift_verified_pairs: targetPreDriftRows.length,
        window_days: windowDays,
        drift_ratio: driftRatio,
        drift_policy_ref: driftPolicyRef,
        drift_policy_status: driftRatio === null ? 'not_configured' : 'unvalidated_configured_heuristic',
        exclusions,
      },
      estimate: null,
      alerts: [driftRatio === null
        ? 'Too few verified pairs remain after declared exclusions; no multiplier-drift threshold was configured.'
        : 'Too few verified pairs remain after declared-event and configured multiplier-drift exclusions.'],
    };
  }

  const multiplier = median(targetRows.map((row) => row.multiplier));
  const errors = validationObservations
    .filter((row) => isWithinPriorDays(row.date, target.date, windowDays));
  const errorValues = errors.map((row) => row.absolute_percentage_error);
  const p90Error = errorValues.length >= 3 ? quantile(errorValues, 0.9) : null;
  const medianError = errorValues.length > 0 ? median(errorValues) : null;
  const check = selfCheck(target, DEFAULT_SELF_CHECK_TOLERANCE);
  const baseline = {
    kind: 'rolling_median_verified_pairs',
    target_excluded: true,
    multiplier: round(multiplier, 12),
    inverse_browse_per_exposure_rate: round(1 / multiplier, 12),
    verified_pair_count: targetRows.length,
    first_date: targetRows[0].date,
    last_date: targetRows[targetRows.length - 1].date,
    window_days: windowDays,
    drift_ratio: driftRatio,
    drift_policy_ref: driftPolicyRef,
    drift_policy_status: driftRatio === null ? 'not_configured' : 'unvalidated_configured_heuristic',
    drift_detection: driftRatio === null
      ? 'not_configured'
      : `prior_window_median_after_${minPairs}_history_pairs`,
    exclusions,
    input_lineage: targetRows.map((row) => row.source_ref),
  };
  const validation = {
    method: 'rolling_origin_preceding_rows_only',
    error_observation_count: errors.length,
    median_absolute_percentage_error_pct: medianError === null ? null : round(medianError * 100, 4),
    p90_absolute_percentage_error_pct: p90Error === null ? null : round(p90Error * 100, 4),
    rows: errors.map((row) => ({
      ...row,
      absolute_percentage_error_pct: round(row.absolute_percentage_error * 100, 4),
      absolute_percentage_error: undefined,
    })),
    coverage_claim: false,
  };

  if (!targetIsObserved(target)) {
    return {
      ...commonResult('rolling_median', scope),
      status: 'reference_only',
      evidence_type: 'input_assessment',
      quality_status: 'reference_only',
      baseline,
      validation,
      self_check: check,
      estimate: null,
      alerts: ['Target detail visitors are imputed, derived, or unverified; no exposure estimate was released.'],
    };
  }
  if (check.status === 'fail') {
    return {
      ...commonResult('rolling_median', scope),
      status: 'data_error',
      evidence_type: 'validation_failure',
      quality_status: 'data_error',
      baseline,
      validation,
      self_check: check,
      estimate: null,
      alerts: ['Browse, payment-conversion, and paid-order inputs fail the same-snapshot self-check.'],
    };
  }

  const value = roundCount(target.detail_visitors * multiplier, 'rolling exposure estimate');
  const interval = p90Error === null || p90Error >= 1 ? null : {
    low: roundCount(value / (1 + p90Error), 'rolling interval low'),
    high: roundCount(value / (1 - p90Error), 'rolling interval high'),
    unit: 'people',
    method: 'rolling_origin_p90_actual_denominator_inverse_band',
    error_band_pct: round(p90Error * 100, 4),
    coverage_claim: false,
  };
  const hourly = parseHourly(target, scope);
  const hasHoldoutActual = target.exposure_users_actual !== undefined;
  const hasHoldoutQuality = target.exposure_users_quality !== undefined;
  const hasHoldoutRef = target.exposure_users_source_ref !== undefined;
  if (hasHoldoutActual || hasHoldoutQuality || hasHoldoutRef) {
    if (!hasHoldoutActual || !hasHoldoutQuality || !hasHoldoutRef) {
      throw new InputError(
        'incomplete_holdout_evidence',
        'holdout actual, verified quality, and exposure-specific source_ref must be supplied together',
      );
    }
    if (target.exposure_users_quality !== 'verified_actual') {
      throw new InputError('unverified_holdout', 'holdout exposure users must have verified_actual quality');
    }
  }
  const holdout = !hasHoldoutActual
    ? null
    : (() => {
        const actual = integer(target.exposure_users_actual, 'target.exposure_users_actual', { min: 1 });
        const sourceRef = provenanceRef(
          target.exposure_users_source_ref,
          'target.exposure_users_source_ref',
        );
        return {
          actual,
          estimated: value,
          source_ref: sourceRef,
          quality: 'verified_actual',
          absolute_percentage_error_pct: round(Math.abs(value - actual) / actual * 100, 4),
          target_was_not_added_to_baseline: true,
        };
      })();

  return {
    ...commonResult('rolling_median', scope),
    status: 'estimated',
    evidence_type: 'derived_estimate',
    quality_status: 'estimate_only',
    baseline,
    validation,
    self_check: check,
    estimate: {
      value,
      unit: 'people',
      kind: 'model_estimate',
      formula: 'round(detail_visitors * median(exposure_users_actual / detail_visitors_actual))',
      interval,
      input_lineage: [target.source_ref, ...baseline.input_lineage],
    },
    holdout_validation: holdout,
    hourly_cumulative_estimates: estimateHourly(hourly, multiplier),
    assumptions: [
      'all calibration rows and the target share hotel, platform, source definition, unit, and time basis',
      'only verified actual pre-target pairs update the baseline',
      'the empirical error band is descriptive and is not a confidence guarantee',
      'the output is an estimate and does not change the platform fact state',
    ],
    alerts: [
      ...(driftRatio === null
        ? ['No multiplier-drift threshold was applied; configure one with an explicit policy reference before excluding drift rows.']
        : ['The configured multiplier-drift threshold is an unvalidated heuristic, not a proven business or statistical cutoff.']),
      ...(interval === null
        ? [p90Error !== null && p90Error >= 1
            ? 'Rolling-origin P90 error is at least 100%, so no finite numeric interval was released.'
            : 'Too few rolling-origin residuals exist for an empirical interval; the point estimate is still estimate_only.']
        : []),
    ],
  };
}

export function analyzeExposure(payload) {
  assertObject(payload, 'payload');
  const method = requiredString(payload.method, 'method');
  const scope = parseScope(payload);
  const metricBoundary = verifyMetricPair(method, scope);
  if (metricBoundary) return metricBoundary;

  if (method === 'anchored_inverse') return analyzeAnchored(payload, scope);
  if (method === 'rolling_median') return analyzeRolling(payload, scope);
  throw new InputError('invalid_input', 'method must be anchored_inverse or rolling_median');
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
      process.stdout.write('Usage: node estimate-exposure-users.mjs --input <input.json> [--pretty]\n');
      return;
    }
    if (!args.input) throw new InputError('invalid_arguments', '--input is required');
    const source = await fs.readFile(path.resolve(args.input), 'utf8');
    const payload = JSON.parse(source);
    const result = analyzeExposure(payload);
    process.stdout.write(`${JSON.stringify(result, null, args.pretty ? 2 : 0)}\n`);
  } catch (error) {
    const result = {
      schema_version: SCHEMA_VERSION,
      status: 'invalid_input',
      error_code: error instanceof InputError ? error.code : 'unexpected_error',
      message: error instanceof Error ? error.message : String(error),
      decision_eligible: false,
      writeback_allowed: false,
    };
    process.stderr.write(`${JSON.stringify(result, null, args?.pretty ? 2 : 0)}\n`);
    process.exitCode = 2;
  }
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : null;
if (invokedPath && invokedPath === path.resolve(fileURLToPath(import.meta.url))) {
  await main();
}
