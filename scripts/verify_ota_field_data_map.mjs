import { strict as assert } from 'node:assert';
import {
  existsSync,
  readFileSync,
  readdirSync,
  statSync,
} from 'node:fs';
import { dirname, extname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import * as meituanNormalizers from './lib/meituan_browser_capture_normalize.mjs';
import {
  CTRIP_CAPTURE_ENDPOINTS,
  CTRIP_CAPTURE_SECTIONS,
} from './lib/ctrip_capture_catalog.mjs';
import {
  OTA_FIELD_DATA_MAP,
  otaFieldDataMapSummary,
} from './lib/ota_field_data_map.mjs';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const DEFAULT_PROJECT_ROOT = resolve(SCRIPT_DIR, '..');

function readRequired(rootDir, path) {
  const target = join(rootDir, path);
  assert.equal(existsSync(target), true, `required file missing: ${path}`);
  return readFileSync(target, 'utf8');
}

function walkCodeFiles(rootDir, relativeDir) {
  const start = join(rootDir, relativeDir);
  const rows = [];
  const visit = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      if (entry.name.startsWith('.') || ['vendor', 'node_modules', 'runtime', 'public'].includes(entry.name)) {
        continue;
      }
      const absolute = join(dir, entry.name);
      if (entry.isDirectory()) {
        visit(absolute);
        continue;
      }
      if (!entry.isFile() || !['.php', '.mjs', '.js'].includes(extname(entry.name))) {
        continue;
      }
      if (statSync(absolute).size > 8 * 1024 * 1024) {
        continue;
      }
      rows.push({
        absolute,
        relative: relative(rootDir, absolute).replaceAll('\\', '/'),
        text: readFileSync(absolute, 'utf8'),
      });
    }
  };
  visit(start);
  return rows;
}

function phpArraySlice(source, startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.notEqual(start, -1, `missing PHP marker: ${startMarker}`);
  assert.notEqual(end, -1, `missing PHP marker: ${endMarker}`);
  return source.slice(start, end);
}

function containsPhpKey(source, key, value) {
  const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const escapedValue = value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`['"]${escapedKey}['"]\\s*=>\\s*['"]${escapedValue}['"]`).test(source);
}

function findCtripDedicatedTableWriters(rootDir) {
  const productionFiles = walkCodeFiles(rootDir, 'app');
  const tables = [
    'ota_ctrip_capture_runs',
    'ota_ctrip_metric_catalog',
    'ota_ctrip_metric_facts',
    'ota_ctrip_capture_gaps',
  ];
  const result = {};
  for (const table of tables) {
    const escaped = table.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const dbChain = new RegExp(
      `Db::name\\(\\s*['"]${escaped}['"]\\s*\\)[\\s\\S]{0,700}?->(?:insert|insertGetId|update|save)\\s*\\(`,
      'i',
    );
    const rawSql = new RegExp(`INSERT\\s+INTO\\s+\`?${escaped}\`?`, 'i');
    result[table] = productionFiles
      .filter((file) => dbChain.test(file.text) || rawSql.test(file.text))
      .map((file) => file.relative);
  }
  return result;
}

function ctripMetricFactsProjectionEvidence(rootDir) {
  const projectionPath = 'app/service/CtripMetricFactProjectionService.php';
  const persistencePath = 'app/service/PlatformDataSyncService.php';
  const projection = readRequired(rootDir, projectionPath);
  const persistence = readRequired(rootDir, persistencePath);

  const projectionWritesFacts = /Db::name\(\s*['"]ota_ctrip_metric_facts['"]\s*\)[\s\S]{0,700}?->(?:insert|update|save)\s*\(/i
    .test(projection);
  const runsAfterPrimaryReadback = /CtripMetricFactProjectionService\(\)\)->project\(\$readbackRows\)/
    .test(persistence);

  return {
    projection_path: projectionPath,
    primary_persistence_path: persistencePath,
    projection_writes_facts: projectionWritesFacts,
    runs_after_primary_readback: runsAfterPrimaryReadback,
    status: projectionWritesFacts && runsAfterPrimaryReadback ? 'wired' : 'missing',
  };
}

function validateConsumerContracts(rootDir, errors) {
  const modules = [
    ...OTA_FIELD_DATA_MAP.ctrip.modules,
    ...OTA_FIELD_DATA_MAP.meituan.modules,
  ];
  const allowedStatuses = new Set(['implemented', 'partial', 'not_wired']);
  for (const module of modules) {
    for (const consumer of module.consumer_contracts || []) {
      if (!consumer.source || !existsSync(join(rootDir, consumer.source))) {
        errors.push(`consumer_contract: ${module.module_id} -> missing ${consumer.source || 'source'}`);
      }
      if (!allowedStatuses.has(consumer.usage_status)) {
        errors.push(`consumer_contract: ${module.module_id} -> invalid status ${consumer.usage_status || ''}`);
      }
    }
  }
}

function validateCtripMap(errors) {
  const modules = OTA_FIELD_DATA_MAP.ctrip.modules;
  const mappedEndpoints = modules.flatMap((module) => module.endpoints);
  const endpointIds = mappedEndpoints.map((endpoint) => endpoint.endpoint_id);

  try {
    assert.equal(modules.length, Object.keys(CTRIP_CAPTURE_SECTIONS).length);
    assert.equal(mappedEndpoints.length, CTRIP_CAPTURE_ENDPOINTS.length);
    assert.equal(new Set(endpointIds).size, endpointIds.length, 'duplicate Ctrip endpoint_id');
    assert.deepEqual(
      new Set(endpointIds),
      new Set(CTRIP_CAPTURE_ENDPOINTS.map((endpoint) => endpoint.id)),
      'Ctrip endpoint map drift',
    );
    for (const module of modules) {
      assert.ok(module.module_id);
      assert.ok(module.data_type);
      assert.ok(module.storage?.primary_table === 'online_daily_data');
      assert.ok(module.consumer_contracts?.length > 0);
      for (const endpoint of module.endpoints) {
        assert.ok(endpoint.endpoint_id);
        assert.ok(endpoint.source_status);
        for (const field of endpoint.fields) {
          assert.ok(field.metric_key);
          assert.ok(field.source_keys.length > 0, `missing source keys: ${endpoint.endpoint_id}/${field.metric_key}`);
          assert.ok(field.source_path_contract);
          assert.ok(field.storage_table);
          assert.ok(field.storage_field);
          assert.ok(field.readback_contract);
          assert.ok(field.missing_state);
          assert.ok(field.page_uses.length > 0);
          assert.ok(field.consumer_contracts?.length > 0);
        }
      }
    }
  } catch (error) {
    errors.push(`ctrip_map: ${error.message}`);
  }
}

function validateMeituanMap(platformServiceSource, errors) {
  const modules = OTA_FIELD_DATA_MAP.meituan.modules;
  const moduleIds = modules.map((module) => module.module_id);
  const captureSections = new Set(modules.map((module) => module.capture_section));
  const allowedSections = new Set(OTA_FIELD_DATA_MAP.meituan.capture_config.allowed_sections);

  try {
    assert.equal(new Set(moduleIds).size, moduleIds.length, 'duplicate Meituan module_id');
    for (const section of captureSections) {
      assert.ok(allowedSections.has(section), `unrecognized Meituan capture section: ${section}`);
    }
    for (const section of OTA_FIELD_DATA_MAP.meituan.capture_config.full_sections) {
      assert.ok(captureSections.has(section), `full capture section is missing from map: ${section}`);
    }
    for (const module of modules) {
      assert.ok(module.source_match_keywords.length > 0, `missing source matcher: ${module.module_id}`);
      assert.ok(module.source_fields.length > 0, `missing source fields: ${module.module_id}`);
      assert.ok(module.storage?.primary_table === 'online_daily_data');
      assert.ok(module.readback_contract);
      assert.ok(module.page_uses.length > 0);
      assert.ok(module.consumer_contracts?.length > 0);
      assert.ok(module.endpoint_catalog?.length > 0, `missing endpoint catalog: ${module.module_id}`);
      for (const endpoint of module.endpoint_catalog) {
        assert.ok(endpoint.endpoint_id);
        assert.ok(endpoint.source_match_keywords.length > 0);
        assert.ok(endpoint.source_status);
        assert.ok(endpoint.fields.length > 0);
        for (const field of endpoint.fields) {
          assert.ok(field.metric_key);
          assert.ok(field.source_field);
          assert.ok(field.source_path_contract);
          assert.ok(field.storage_field);
          assert.ok(field.readback_contract);
          assert.ok(field.field_fact_status);
        }
      }
      for (const normalizer of module.normalizers) {
        assert.equal(
          typeof meituanNormalizers[normalizer],
          'function',
          `missing Meituan normalizer export: ${normalizer}`,
        );
      }
    }
  } catch (error) {
    errors.push(`meituan_map: ${error.message}`);
  }

  const resourceSlice = phpArraySlice(
    platformServiceSource,
    'private const COLLECTION_RESOURCE_DEFINITIONS = [',
    'private const NORMALIZED_FIELD_FACT_DEFINITIONS = [',
  );
  const fieldFactSlice = phpArraySlice(
    platformServiceSource,
    'private const NORMALIZED_FIELD_FACT_DEFINITIONS = [',
    'public function __construct(',
  );

  const resourceBindings = {
    business: 'businessData',
    traffic: 'flowData',
    peer_rank: 'peerRank',
    search_keyword: 'searchKeywords',
    traffic_forecast: 'trafficForecast',
    traffic_analysis: 'flowAnalysis',
    orders: 'orderData',
    reviews: 'reviewData',
    room_types: 'roomTypes',
    platform_identity: 'platformIdentity',
  };
  const normalizedDataTypes = [
    'business',
    'order',
    'advertising',
    'order_flow',
    'peer_rank',
    'quality',
    'traffic',
    'search_keyword',
    'traffic_forecast',
    'traffic_analysis',
    'review',
    'room_type',
    'platform_identity',
  ];

  for (const [moduleId, resource] of Object.entries(resourceBindings)) {
    if (!containsPhpKey(resourceSlice, 'resource', resource)) {
      errors.push(`meituan_resource: ${moduleId} -> ${resource} missing`);
    }
  }
  for (const dataType of normalizedDataTypes) {
    if (!new RegExp(`['"]${dataType}['"]\\s*=>\\s*\\[`).test(fieldFactSlice)) {
      errors.push(`field_fact_contract: ${dataType} missing`);
    }
  }

  return {
    advertising_resource_defined: containsPhpKey(resourceSlice, 'data_type', 'advertising'),
    normalized_field_fact_types: normalizedDataTypes.filter(
      (dataType) => new RegExp(`['"]${dataType}['"]\\s*=>\\s*\\[`).test(fieldFactSlice),
    ),
  };
}

function validateStorageContract(rootDir, errors) {
  const migration = readRequired(
    rootDir,
    'database/migrations/20260602_create_ctrip_ota_metric_tables.sql',
  );
  const requiredTables = [
    'ota_ctrip_capture_runs',
    'ota_ctrip_metric_catalog',
    'ota_ctrip_metric_facts',
    'ota_ctrip_entity_snapshots',
    'ota_ctrip_capture_gaps',
  ];
  for (const table of requiredTables) {
    if (!migration.includes(`\`${table}\``)) {
      errors.push(`migration_contract: ${table} missing`);
    }
  }
  for (const anchor of OTA_FIELD_DATA_MAP.truth_requirements) {
    if (!anchor) {
      errors.push('truth_contract: empty truth requirement');
    }
  }
}

function validateNoSecrets(errors) {
  const serialized = JSON.stringify(OTA_FIELD_DATA_MAP);
  const secretAssignment = /(?:cookie|webhook|token|secret|password)\s*[:=]\s*["'][^"']{6,}/i;
  if (secretAssignment.test(serialized)) {
    errors.push('secret_contract: field map contains a credential-like assignment');
  }
}

export function verifyOtaFieldDataMap({ projectRoot = DEFAULT_PROJECT_ROOT } = {}) {
  const rootDir = resolve(projectRoot);
  const errors = [];
  const platformServiceSource = readRequired(rootDir, 'app/service/PlatformDataSyncService.php');

  validateCtripMap(errors);
  const meituanEvidence = validateMeituanMap(platformServiceSource, errors);
  validateConsumerContracts(rootDir, errors);
  validateStorageContract(rootDir, errors);
  validateNoSecrets(errors);

  const dedicatedTableWriters = findCtripDedicatedTableWriters(rootDir);
  const ctripMetricFactsProjection = ctripMetricFactsProjectionEvidence(rootDir);
  const ctripDedicatedTablesWired = ctripMetricFactsProjection.status === 'wired';
  const summary = otaFieldDataMapSummary();
  const openGaps = OTA_FIELD_DATA_MAP.known_gap_rules.filter((gap) => {
    if (gap.gap_code === 'ctrip_metric_tables_not_wired_to_profile_capture_persistence') {
      return !ctripDedicatedTablesWired;
    }
    if (gap.gap_code === 'advertising_resource_definition_missing') {
      return !meituanEvidence.advertising_resource_defined;
    }
    return true;
  });

  return {
    schema_version: OTA_FIELD_DATA_MAP.schema_version,
    contract_status: errors.length === 0 ? 'passed' : 'failed',
    business_closure_status: openGaps.length === 0 ? 'contract_closed' : 'partial',
    live_capture_verified: false,
    summary: {
      ...summary,
      known_gap_rule_count: OTA_FIELD_DATA_MAP.known_gap_rules.length,
      known_gap_count: openGaps.length,
    },
    evidence: {
      ctrip_dedicated_table_writers: dedicatedTableWriters,
      ctrip_metric_facts_projection: ctripMetricFactsProjection,
      meituan_advertising_resource_defined: meituanEvidence.advertising_resource_defined,
      normalized_field_fact_types: meituanEvidence.normalized_field_fact_types,
    },
    open_gaps: openGaps,
    errors,
  };
}

function isDirectRun() {
  const invoked = process.argv[1] ? resolve(process.argv[1]) : '';
  return invoked === fileURLToPath(import.meta.url);
}

if (isDirectRun()) {
  const report = verifyOtaFieldDataMap();
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  if (report.contract_status !== 'passed') {
    process.exitCode = 1;
  }
}
