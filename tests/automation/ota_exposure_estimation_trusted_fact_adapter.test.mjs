import assert from 'node:assert/strict';
import test from 'node:test';

import {
  ADAPTER_CONTRACT_VERSION,
  adaptTrustedFacts,
  estimateExposureFromTrustedFacts,
} from '../../.agents/skills/suxi-ota-exposure-estimation/scripts/estimate-from-trusted-facts.mjs';
import { InputError } from '../../.agents/skills/suxi-ota-exposure-estimation/scripts/estimate-exposure-users.mjs';

const clone = (value) => JSON.parse(JSON.stringify(value));

function strictFact(date, index, overrides = {}) {
  const detailVisitors = 100 + index;
  return {
    tenant_id: 10,
    system_hotel_id: 80,
    platform: 'meituan',
    data_date: date,
    timezone: 'Asia/Shanghai',
    source_path: 'data.myHotel',
    metric_definition_version: 'meituan-traffic-funnel-v1',
    time_basis: 'same_day_cumulative',
    cumulative_cutoff: '23:00',
    history_status: 'success',
    validation_status: 'verified',
    readback_verified: 1,
    exposure_metric: 'exposure_users',
    exposure_unit: 'people',
    exposure_users: detailVisitors * 10,
    browse_metric: 'detail_visitors',
    browse_unit: 'people',
    detail_visitors: detailVisitors,
    source_ref: `online_daily_data#${1000 + index}`,
    ...overrides,
  };
}

function payload(pairCount = 7) {
  const dates = [
    '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04',
    '2026-08-05', '2026-08-06', '2026-08-07',
  ];
  const targetFact = strictFact('2026-08-08', 8, {
    detail_visitors: 125,
    source_ref: 'online_daily_data#2008',
  });
  delete targetFact.exposure_metric;
  delete targetFact.exposure_unit;
  delete targetFact.exposure_users;
  return {
    contract_version: ADAPTER_CONTRACT_VERSION,
    scope: {
      tenant_id: 10,
      system_hotel_id: 80,
      platform: 'meituan',
      business_date: '2026-08-08',
      timezone: 'Asia/Shanghai',
      source_path: 'data.myHotel',
      metric_definition: 'same-day cumulative deduplicated exposure and detail visitors',
      metric_definition_version: 'meituan-traffic-funnel-v1',
      time_basis: 'same_day_cumulative',
      cumulative_cutoff: '23:00',
    },
    calibration_facts: dates.slice(0, pairCount).map((date, index) => strictFact(date, index + 1)),
    target_fact: targetFact,
  };
}

test('strict same-scope facts produce estimate_only output without decision or writeback authority', () => {
  const input = payload();
  const adapted = adaptTrustedFacts(input);
  assert.equal(adapted.receipt.strict_gate, 'history_success+validation_verified+readback_verified');
  assert.equal(adapted.receipt.tenant_id, 10);
  assert.equal(adapted.receipt.accepted_verified_pairs, 7);
  assert.equal(adapted.estimate_input.options.min_verified_pairs, 7);
  assert.equal(adapted.estimate_input.options.window_days, 14);
  assert.match(adapted.estimate_input.scope.scope_key, /^trusted-fact:[a-f0-9]{64}$/);

  const result = estimateExposureFromTrustedFacts(input);
  assert.equal(result.status, 'estimated');
  assert.equal(result.estimate.value, 1250);
  assert.equal(result.evidence_type, 'derived_estimate');
  assert.equal(result.quality_status, 'estimate_only');
  assert.equal(result.decision_eligible, false);
  assert.equal(result.writeback_allowed, false);
  assert.equal(result.platform_fact_status, 'unchanged');
  assert.equal(result.adapter_receipt.accepted_verified_pairs, 7);
});

test('fewer than seven strict prior dates remain insufficient_baseline with no estimate', () => {
  const result = estimateExposureFromTrustedFacts(payload(6));
  assert.equal(result.status, 'insufficient_baseline');
  assert.equal(result.estimate, null);
  assert.equal(result.baseline.eligible_verified_pairs, 6);
  assert.equal(result.adapter_receipt.accepted_verified_pairs, 6);
  assert.equal(result.decision_eligible, false);
  assert.equal(result.writeback_allowed, false);
  assert.equal(result.platform_fact_status, 'unchanged');
});

test('tenant, hotel, platform, source path, date and cutoff mismatches fail closed', () => {
  const mutations = [
    ['tenant', (input) => { input.calibration_facts[0].tenant_id = 11; }, 'strict_fact_scope_mismatch'],
    ['hotel', (input) => { input.calibration_facts[0].system_hotel_id = 81; }, 'strict_fact_scope_mismatch'],
    ['platform', (input) => { input.calibration_facts[0].platform = 'ctrip'; }, 'strict_fact_scope_mismatch'],
    ['source path', (input) => { input.calibration_facts[0].source_path = 'data.otherHotel'; }, 'strict_fact_scope_mismatch'],
    ['target date', (input) => { input.target_fact.data_date = '2026-08-09'; }, 'strict_fact_date_mismatch'],
    ['cutoff', (input) => { input.calibration_facts[0].cumulative_cutoff = '22:00'; }, 'strict_fact_scope_mismatch'],
  ];
  for (const [label, mutate, code] of mutations) {
    const input = clone(payload());
    mutate(input);
    assert.throws(
      () => estimateExposureFromTrustedFacts(input),
      (error) => error instanceof InputError && error.code === code,
      label,
    );
  }
});

test('history, validation, readback and metric gates reject non-strict or wrong-unit facts', () => {
  const mutations = [
    ['history', (input) => { input.calibration_facts[0].history_status = 'partial'; }, 'strict_fact_gate_failed'],
    ['validation', (input) => { input.calibration_facts[0].validation_status = 'unverified'; }, 'strict_fact_gate_failed'],
    ['readback', (input) => { input.calibration_facts[0].readback_verified = 0; }, 'strict_fact_gate_failed'],
    ['exposure metric', (input) => { input.calibration_facts[0].exposure_metric = 'total_exposure'; }, 'metric_contract_mismatch'],
    ['exposure unit', (input) => { input.calibration_facts[0].exposure_unit = 'impressions'; }, 'metric_contract_mismatch'],
    ['browse metric', (input) => { input.target_fact.browse_metric = 'detail_views'; }, 'metric_contract_mismatch'],
  ];
  for (const [label, mutate, code] of mutations) {
    const input = clone(payload());
    mutate(input);
    assert.throws(
      () => estimateExposureFromTrustedFacts(input),
      (error) => error instanceof InputError && error.code === code,
      label,
    );
  }
});

test('target-day calibration is rejected instead of leaking the answer into its baseline', () => {
  const input = clone(payload());
  input.calibration_facts[0].data_date = input.scope.business_date;
  assert.throws(
    () => estimateExposureFromTrustedFacts(input),
    (error) => error instanceof InputError && error.code === 'target_leakage',
  );
});

test('an already observed target exposure is never replaced by an estimate', () => {
  const input = clone(payload());
  input.target_fact.exposure_users = 1300;
  assert.throws(
    () => estimateExposureFromTrustedFacts(input),
    (error) => error instanceof InputError && error.code === 'target_exposure_already_available',
  );
});
