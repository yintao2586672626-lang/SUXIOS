#!/usr/bin/env node

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { analyzeExposure, InputError } from './estimate-exposure-users.mjs';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const fixturesDirectory = path.resolve(scriptDirectory, '..', 'evals', 'fixtures');

async function loadFixture(name) {
  const text = await fs.readFile(path.join(fixturesDirectory, name), 'utf8');
  return JSON.parse(text);
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

const anchoredInput = await loadFixture('workbook-anchored.json');
const anchored = analyzeExposure(anchoredInput);
assert.equal(anchored.status, 'estimated');
assert.equal(anchored.formula_version, 'same_day_anchored_inverse_rate.v1');
assert.equal(anchored.baseline.rate_used, 0.0877);
assert.equal(anchored.estimate.value, 1437);
assert.equal(anchored.hourly_cumulative_estimates[0].time, '20:00');
assert.equal(anchored.hourly_cumulative_estimates[0].exposure_users_estimate, 1220);
assert.equal(anchored.hourly_cumulative_estimates[1].exposure_users_estimate, 1437);
assert.equal(anchored.estimate.interval.method, 'rate_rounding_bounds');
assert.ok(anchored.estimate.interval.low <= 1437 && anchored.estimate.interval.high >= 1437);
assert.equal(anchored.evidence_type, 'derived_estimate');
assert.equal(anchored.quality_status, 'estimate_only');
assert.equal(anchored.decision_eligible, false);
assert.equal(anchored.writeback_allowed, false);

const rollingInput = await loadFixture('rolling-golden.json');
const rolling = analyzeExposure(rollingInput);
assert.equal(rolling.status, 'estimated');
assert.equal(rolling.formula_version, 'same_scope_rolling_median_multiplier.v1');
assert.equal(rolling.baseline.multiplier, 11.5);
assert.equal(rolling.baseline.verified_pair_count, 10);
assert.equal(rolling.baseline.drift_ratio, 0.15);
assert.equal(rolling.baseline.drift_policy_ref, 'fixture:unvalidated-heuristic-15pct');
assert.equal(rolling.baseline.drift_policy_status, 'unvalidated_configured_heuristic');
assert.equal(rolling.estimate.value, 1449);
assert.ok(rolling.baseline.exclusions.some((row) => row.reason === 'verified_event_outlier'));
assert.equal(rolling.validation.method, 'rolling_origin_preceding_rows_only');
assert.equal(rolling.validation.error_observation_count, 4);
assert.ok(rolling.validation.p90_absolute_percentage_error_pct > 0);
const rollingError = rolling.validation.p90_absolute_percentage_error_pct / 100;
assert.equal(rolling.estimate.interval.low, Math.round(rolling.estimate.value / (1 + rollingError)));
assert.equal(rolling.estimate.interval.high, Math.round(rolling.estimate.value / (1 - rollingError)));
assert.equal(rolling.self_check.status, 'pass');
assert.equal(rolling.evidence_type, 'derived_estimate');
assert.equal(rolling.quality_status, 'estimate_only');
assert.equal(rolling.decision_eligible, false);
assert.equal(rolling.writeback_allowed, false);

const sparseInput = await loadFixture('source-sparse-baseline.json');
const sparse = analyzeExposure(sparseInput);
assert.equal(sparse.status, 'insufficient_baseline');
assert.equal(sparse.evidence_type, 'none');
assert.equal(sparse.baseline.eligible_verified_pairs, 1);
assert.equal(sparse.estimate, null);
assert.equal(sparse.decision_eligible, false);
assert.equal(sparse.writeback_allowed, false);

const metricMismatchInput = clone(rollingInput);
metricMismatchInput.scope.target_metric = 'total_exposure';
metricMismatchInput.scope.target_unit = 'impressions';
const metricMismatch = analyzeExposure(metricMismatchInput);
assert.equal(metricMismatch.status, 'not_applicable');
assert.equal(metricMismatch.estimate, null);
assert.equal(metricMismatch.evidence_type, 'none');

const imputedTargetInput = clone(rollingInput);
imputedTargetInput.target.detail_visitors_quality = 'imputed';
const imputedTarget = analyzeExposure(imputedTargetInput);
assert.equal(imputedTarget.status, 'reference_only');
assert.equal(imputedTarget.estimate, null);

const failedSelfCheckInput = clone(rollingInput);
failedSelfCheckInput.target.payment_conversion_rate_pct = 60;
failedSelfCheckInput.target.paid_orders = 1;
const failedSelfCheck = analyzeExposure(failedSelfCheckInput);
assert.equal(failedSelfCheck.status, 'data_error');
assert.equal(failedSelfCheck.estimate, null);
assert.equal(failedSelfCheck.evidence_type, 'validation_failure');
assert.equal(failedSelfCheck.quality_status, 'data_error');

const earlyReplayInput = clone(rollingInput);
earlyReplayInput.scope.business_date = '2026-08-10';
earlyReplayInput.calibration_rows = earlyReplayInput.calibration_rows
  .filter((row) => row.date <= '2026-08-09');
earlyReplayInput.target.date = '2026-08-10';
earlyReplayInput.target.source_ref = 'fixture:early-target-2026-08-10';
const earlyReplay = analyzeExposure(earlyReplayInput);
const earlyResidual = earlyReplay.validation.rows.find((row) => row.date === '2026-08-09');
const fullResidual = rolling.validation.rows.find((row) => row.date === '2026-08-09');
assert.deepEqual(earlyResidual, fullResidual);

const driftValidationInput = clone(rollingInput);
const driftRowInput = driftValidationInput.calibration_rows.find((row) => row.date === '2026-08-09');
driftRowInput.exposure_users_actual = 3000;
const driftValidation = analyzeExposure(driftValidationInput);
const driftResidual = driftValidation.validation.rows.find((row) => row.date === '2026-08-09');
assert.equal(driftResidual.excluded_from_future_baseline, true);
assert.equal(driftResidual.exclusion_reason, 'multiplier_drift_outlier');
assert.ok(driftResidual.absolute_percentage_error_pct > 40);
assert.ok(driftValidation.validation.p90_absolute_percentage_error_pct > 20);
assert.ok(driftValidation.baseline.exclusions.some(
  (row) => row.date === '2026-08-09' && row.reason === 'multiplier_drift_outlier',
));

const observedZeroInput = clone(rollingInput);
observedZeroInput.target.detail_visitors = 0;
observedZeroInput.target.payment_conversion_rate_pct = 0;
observedZeroInput.target.paid_orders = 0;
observedZeroInput.target.hourly_cumulative_detail_visitors = [];
const observedZero = analyzeExposure(observedZeroInput);
assert.equal(observedZero.status, 'estimated');
assert.equal(observedZero.estimate.value, 0);

const contradictoryZeroInput = clone(observedZeroInput);
contradictoryZeroInput.target.paid_orders = 1;
const contradictoryZero = analyzeExposure(contradictoryZeroInput);
assert.equal(contradictoryZero.status, 'data_error');
assert.equal(contradictoryZero.estimate, null);

const partialSelfCheckInput = clone(rollingInput);
delete partialSelfCheckInput.target.paid_orders;
assert.throws(
  () => analyzeExposure(partialSelfCheckInput),
  (error) => error instanceof InputError && error.code === 'incomplete_self_check_pair',
);

const zeroRoundedRateInput = clone(anchoredInput);
zeroRoundedRateInput.anchor.detail_visitors_actual = 1;
zeroRoundedRateInput.anchor.exposure_users_actual = 1000;
zeroRoundedRateInput.rate_precision = 1;
assert.throws(
  () => analyzeExposure(zeroRoundedRateInput),
  (error) => error instanceof InputError && error.code === 'rounded_rate_zero',
);

const unsafeReferenceInput = clone(anchoredInput);
unsafeReferenceInput.target.source_ref = 'https://example.invalid/path?token=secret';
assert.throws(
  () => analyzeExposure(unsafeReferenceInput),
  (error) => error instanceof InputError && error.code === 'unsafe_source_ref',
);

const conflictingDefinitionInput = clone(rollingInput);
conflictingDefinitionInput.calibration_rows[0].target_unit = 'impressions';
assert.throws(
  () => analyzeExposure(conflictingDefinitionInput),
  (error) => error instanceof InputError && error.code === 'scope_mismatch',
);

const oversizedHourlyInput = clone(rollingInput);
oversizedHourlyInput.target.hourly_cumulative_detail_visitors = [
  { time: '23:00', detail_visitors: 999 }
];
assert.throws(
  () => analyzeExposure(oversizedHourlyInput),
  (error) => error instanceof InputError && error.code === 'snapshot_mismatch',
);

const unverifiedHoldoutInput = clone(rollingInput);
unverifiedHoldoutInput.target.exposure_users_actual = 1500;
unverifiedHoldoutInput.target.exposure_users_quality = 'unverified';
unverifiedHoldoutInput.target.exposure_users_source_ref = 'fixture:unverified-holdout';
assert.throws(
  () => analyzeExposure(unverifiedHoldoutInput),
  (error) => error instanceof InputError && error.code === 'unverified_holdout',
);

const unexplainedExclusionInput = clone(rollingInput);
unexplainedExclusionInput.calibration_rows[0].baseline_eligible = false;
assert.throws(
  () => analyzeExposure(unexplainedExclusionInput),
  (error) => error instanceof InputError && error.code === 'invalid_input',
);

const arbitraryExclusionInput = clone(rollingInput);
arbitraryExclusionInput.calibration_rows[0].baseline_eligible = false;
arbitraryExclusionInput.calibration_rows[0].exclusion_reason = 'improves_model_accuracy';
arbitraryExclusionInput.calibration_rows[0].exclusion_source_ref = 'fixture:arbitrary-exclusion';
assert.throws(
  () => analyzeExposure(arbitraryExclusionInput),
  (error) => error instanceof InputError && error.code === 'invalid_input',
);

const evidencedExclusionInput = clone(rollingInput);
evidencedExclusionInput.calibration_rows[0].baseline_eligible = false;
evidencedExclusionInput.calibration_rows[0].exclusion_reason = 'verified_capture_corruption';
evidencedExclusionInput.calibration_rows[0].exclusion_source_ref = 'fixture:verified-corrupt-batch';
const evidencedExclusion = analyzeExposure(evidencedExclusionInput);
assert.ok(evidencedExclusion.baseline.exclusions.some(
  (row) => row.reason === 'baseline_ineligible_verified_capture_corruption'
    && row.source_ref === 'fixture:verified-corrupt-batch',
));

const missingDriftPolicyRefInput = clone(rollingInput);
delete missingDriftPolicyRefInput.options.drift_policy_ref;
assert.throws(
  () => analyzeExposure(missingDriftPolicyRefInput),
  (error) => error instanceof InputError && error.code === 'invalid_input',
);

const crossPlatformInput = clone(rollingInput);
crossPlatformInput.calibration_rows[0].platform = 'ctrip';
assert.throws(
  () => analyzeExposure(crossPlatformInput),
  (error) => error instanceof InputError && error.code === 'scope_mismatch',
);

const leakageInput = clone(rollingInput);
leakageInput.calibration_rows[0].date = leakageInput.target.date;
assert.throws(
  () => analyzeExposure(leakageInput),
  (error) => error instanceof InputError && error.code === 'target_leakage',
);

const nonMonotonicInput = clone(anchoredInput);
nonMonotonicInput.target.hourly_cumulative_detail_visitors = [
  { time: '20:00', detail_visitors: 107 },
  { time: '21:00', detail_visitors: 100 }
];
assert.throws(
  () => analyzeExposure(nonMonotonicInput),
  (error) => error instanceof InputError && error.code === 'non_monotonic_cumulative_series',
);

process.stdout.write(`${JSON.stringify({
  status: 'pass',
  checks: 24,
  workbook_replay: {
    rate_used: anchored.baseline.rate_used,
    at_20_00: anchored.hourly_cumulative_estimates[0].exposure_users_estimate,
    at_23_00: anchored.hourly_cumulative_estimates[1].exposure_users_estimate,
  },
  rolling_golden: {
    multiplier: rolling.baseline.multiplier,
    estimate: rolling.estimate.value,
    verified_pairs: rolling.baseline.verified_pair_count,
    rolling_error_observations: rolling.validation.error_observation_count,
  },
  source_sparse_baseline: {
    status: sparse.status,
    eligible_verified_pairs: sparse.baseline.eligible_verified_pairs,
  },
}, null, 2)}\n`);
