import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

import {
  OTA_FIELD_DATA_MAP,
  buildOtaMapCollectionPlan,
  otaFieldDataMapSummary,
} from '../../scripts/lib/ota_field_data_map.mjs';
import { verifyOtaFieldDataMap } from '../../scripts/verify_ota_field_data_map.mjs';

test('covers every cataloged Ctrip endpoint and metric with storage/readback truth', () => {
  const summary = otaFieldDataMapSummary();
  assert.equal(summary.ctrip.endpoint_count, OTA_FIELD_DATA_MAP.ctrip.catalog_summary.endpoint_count);
  assert.equal(summary.ctrip.unique_metric_count, OTA_FIELD_DATA_MAP.ctrip.catalog_summary.field_count);
  assert.equal(summary.ctrip.module_count, OTA_FIELD_DATA_MAP.ctrip.catalog_summary.section_count);

  const endpoints = OTA_FIELD_DATA_MAP.ctrip.modules.flatMap((module) => module.endpoints);
  const endpointIds = endpoints.map((endpoint) => endpoint.endpoint_id);
  assert.equal(new Set(endpointIds).size, endpointIds.length);

  for (const endpoint of endpoints) {
    assert.ok(endpoint.source_status);
    for (const field of endpoint.fields) {
      assert.ok(field.metric_key);
      assert.ok(field.source_keys.length > 0);
      assert.match(field.source_path_contract, /field_facts\.source_path/);
      assert.equal(field.storage_table, 'online_daily_data');
      assert.match(field.storage_field, /^online_daily_data\./);
      assert.match(field.readback_contract, /system_hotel_id.*platform_hotel_id.*data_date.*data_period.*source_trace_id.*readback_verified=1/);
      assert.ok(field.missing_state);
      assert.ok(field.page_uses.length > 0);
      assert.ok(field.consumer_contracts.length > 0);
      for (const consumer of field.consumer_contracts) {
        assert.ok(['implemented', 'partial', 'not_wired'].includes(consumer.usage_status));
        assert.ok(existsSync(resolve(consumer.source)), consumer.source);
      }
    }
  }
});

test('maps declared product uses to concrete consumers without claiming partial paths are live', () => {
  for (const module of [
    ...OTA_FIELD_DATA_MAP.ctrip.modules,
    ...OTA_FIELD_DATA_MAP.meituan.modules,
  ]) {
    assert.ok(module.consumer_contracts.length > 0, module.module_id);
    for (const consumer of module.consumer_contracts) {
      assert.ok(existsSync(resolve(consumer.source)), consumer.source);
      assert.ok(['implemented', 'partial', 'not_wired'].includes(consumer.usage_status));
    }
  }
  const advertising = OTA_FIELD_DATA_MAP.meituan.modules.find((module) => module.module_id === 'advertising');
  assert.equal(advertising.consumer_contracts[0].usage_status, 'not_wired');
});

test('keeps Meituan time grains and optional module gaps explicit', () => {
  const modules = new Map(
    OTA_FIELD_DATA_MAP.meituan.modules.map((module) => [module.module_id, module]),
  );

  assert.equal(modules.get('business').contract_status, 'contract_closed');
  assert.equal(modules.get('traffic').contract_status, 'contract_closed');
  assert.equal(modules.get('orders').contract_status, 'contract_closed');
  assert.equal(modules.get('advertising').contract_status, 'contract_partial');
  assert.equal(modules.get('advertising').field_fact_contract, 'defined');
  assert.equal(modules.get('order_flow').field_fact_contract, 'defined');
  assert.equal(modules.get('reviews').field_fact_contract, 'defined');
  assert.equal(modules.get('room_types').field_fact_contract, 'defined');
  assert.equal(modules.get('traffic_forecast').time_grain, 'future_signal');
  assert.notEqual(modules.get('traffic_forecast').time_grain, modules.get('orders').time_grain);
  for (const module of modules.values()) {
    assert.ok(module.endpoint_catalog.length > 0, module.module_id);
    for (const endpoint of module.endpoint_catalog) {
      assert.match(endpoint.endpoint_id, new RegExp(`^meituan\\.${module.module_id}\\.`));
      for (const field of endpoint.fields) {
        assert.ok(field.source_field);
        assert.match(field.source_path_contract, /field_facts\.source_path/);
        assert.match(field.readback_contract, /system_hotel_id.*platform_hotel_id.*data_period.*readback_verified=1/);
      }
    }
  }

  for (const section of OTA_FIELD_DATA_MAP.meituan.capture_config.full_sections) {
    assert.ok(
      OTA_FIELD_DATA_MAP.meituan.modules.some((module) => module.capture_section === section),
      section,
    );
  }
});

test('declares OTA-only scope and never promotes missing data to a value', () => {
  assert.match(OTA_FIELD_DATA_MAP.scope, /OTA 渠道/);
  assert.match(OTA_FIELD_DATA_MAP.scope, /不是全酒店经营事实/);
  assert.match(OTA_FIELD_DATA_MAP.storage_contract.missing_policy, /不得用 0/);
  assert.deepEqual(
    OTA_FIELD_DATA_MAP.truth_requirements,
    [
      'source_platform',
      'system_hotel_id',
      'platform_hotel_id',
      'target_date',
      'data_period',
      'capture_trace',
      'persistence',
      'database_readback',
      'field_fact_status',
    ],
  );
});

test('map drives a bounded core collection order instead of collecting every optional module', () => {
  const ctrip = buildOtaMapCollectionPlan('ctrip');
  const meituan = buildOtaMapCollectionPlan('meituan');
  assert.deepEqual(ctrip.sections, ['business_overview', 'traffic_report']);
  assert.deepEqual(meituan.sections, ['orders', 'traffic']);
  assert.match(meituan.stop_condition, /精确日期回读/);
  assert.deepEqual(meituan.modules.map((module) => module.module_id), ['business', 'traffic', 'orders']);
  assert.ok(!meituan.modules.some((module) => module.module_id === 'advertising'));
  assert.equal(
    OTA_FIELD_DATA_MAP.meituan.modules.find((module) => module.module_id === 'peer_rank').collection_plan.priority,
    'optional',
  );
});

test('verifier distinguishes map integrity from unresolved business closure', () => {
  const report = verifyOtaFieldDataMap();
  assert.equal(report.contract_status, 'passed');
  assert.equal(report.live_capture_verified, false);
  assert.equal(report.errors.length, 0);

  const ctripProjection = report.evidence.ctrip_metric_facts_projection;
  assert.equal(ctripProjection.status, 'wired');
  assert.equal(ctripProjection.projection_writes_facts, true);
  assert.equal(ctripProjection.runs_after_primary_readback, true);
  assert.equal(
    report.open_gaps.some(
      (gap) => gap.gap_code === 'ctrip_metric_tables_not_wired_to_profile_capture_persistence',
    ),
    ctripProjection.status !== 'wired',
  );
  assert.equal(
    report.open_gaps.some((gap) => gap.gap_code === 'advertising_resource_definition_missing'),
    !report.evidence.meituan_advertising_resource_defined,
  );
  assert.equal(report.business_closure_status, report.open_gaps.length > 0 ? 'partial' : 'contract_closed');
  assert.equal(report.summary.known_gap_count, report.open_gaps.length);
});

test('field map contains no credential-like literal', () => {
  const serialized = JSON.stringify(OTA_FIELD_DATA_MAP);
  assert.doesNotMatch(
    serialized,
    /(?:cookie|webhook|token|secret|password)\s*[:=]\s*["'][^"']{6,}/i,
  );
});
