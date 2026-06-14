import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const importer = path.join(root, 'scripts', 'import_p0_ota_traffic_payload.php');
const p0Verifier = path.join(root, 'scripts', 'verify_p0_ota_field_loop_closure.php');
const date = '2026-06-14';
const systemHotelId = '7';
const checks = [];

const hash = (char) => char.repeat(64);

const cases = [
  {
    name: 'meituan_top_level_traffic_ready',
    platform: 'meituan',
    payload: {
      traffic: [
        trafficRow({
          poiId: 'demo',
          date,
          trace: 'meituan:top-level-traffic',
          hash: hash('a'),
        }),
      ],
    },
    expect: { exitCode: 0, status: 'ready_to_import', targetRows: 1, evidenceRows: 1, issuesAbsent: ['sensitive_payload_keys_detected', 'desensitized_capture_evidence_missing'] },
  },
  {
    name: 'ctrip_top_level_traffic_ready',
    platform: 'ctrip',
    payload: {
      traffic: [
        trafficRow({
          hotelId: 'demo',
          date,
          trace: 'ctrip:top-level-traffic',
          hash: hash('b'),
        }),
      ],
    },
    expect: { exitCode: 0, status: 'ready_to_import', targetRows: 1, evidenceRows: 1, issuesAbsent: ['sensitive_payload_keys_detected', 'desensitized_capture_evidence_missing'] },
  },
  {
    name: 'ctrip_standard_rows_ready',
    platform: 'ctrip',
    payload: {
      standard_rows: [
        {
          source: 'ctrip',
          data_type: 'traffic',
          hotel_id: 'demo',
          data_date: date,
          _source_path: 'standard_rows.0',
          capture_evidence: {
            source_trace_id: 'ctrip:standard-row-traffic',
            source_url_hash: hash('c'),
          },
          list_exposure: 30,
          detail_exposure: 15,
          flow_rate: 50,
          order_filling_num: 6,
          order_submit_num: 3,
        },
      ],
    },
    expect: { exitCode: 0, status: 'ready_to_import', targetRows: 1, evidenceRows: 1, issuesAbsent: ['required_traffic_metric_keys_missing'] },
  },
  {
    name: 'meituan_browser_capture_envelope_ready',
    platform: 'meituan',
    payload: {
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('d'),
          source_trace_id: 'meituan:response-envelope',
        },
      ],
      traffic: [
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          trace: 'meituan:browser-capture-row',
          hash: hash('e'),
        }),
      ],
    },
    expect: { exitCode: 0, status: 'ready_to_import', targetRows: 1, evidenceRows: 1, issuesAbsent: ['desensitized_capture_evidence_missing'] },
  },
  {
    name: 'payload_level_evidence_propagates_to_rows',
    platform: 'meituan',
    payload: {
      capture_evidence: {
        source_trace_id: 'meituan:payload-level-evidence',
        source_url_hash: hash('f'),
      },
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            listExposure: 50,
            detailExposure: 25,
            flowRate: 50,
            orderFillingNum: 10,
            orderSubmitNum: 5,
          },
        ],
      },
    },
    expect: { exitCode: 0, status: 'ready_to_import', targetRows: 1, evidenceRows: 1, issuesAbsent: ['desensitized_capture_evidence_missing'] },
  },
  {
    name: 'cross_row_metric_coverage_blocked',
    platform: 'meituan',
    payload: {
      capture_evidence: {
        source_trace_id: 'meituan:cross-row-metric-coverage',
        source_url_hash: hash('9'),
      },
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            listExposure: 80,
            detailExposure: 40,
            flowRate: 50,
          },
          {
            poiId: 'demo',
            date,
            _source_path: 'data.flowData.1',
            orderFillingNum: 16,
            orderSubmitNum: 8,
          },
        ],
      },
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 2,
      evidenceRows: 2,
      issuesPresent: ['traffic_field_fact_preview_rows_incomplete'],
      issuesAbsent: ['required_traffic_metric_keys_missing', 'desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'field_name_source_path_blocked',
    platform: 'meituan',
    payload: {
      capture_evidence: {
        source_trace_id: 'meituan:field-name-source-path',
        source_url_hash: hash('4'),
      },
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
            _source_path: 'listExposure',
            listExposure: 60,
            detailExposure: 30,
            flowRate: 50,
            orderFillingNum: 12,
            orderSubmitNum: 6,
          },
        ],
      },
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      missingSourcePathRows: 1,
      issuesPresent: ['target_date_source_path_missing'],
      issuesAbsent: ['required_traffic_metric_keys_missing', 'desensitized_capture_evidence_missing', 'target_date_explicit_row_date_missing'],
    },
  },
  {
    name: 'missing_explicit_row_date_blocked',
    platform: 'meituan',
    payload: {
      capture_evidence: {
        source_trace_id: 'meituan:missing-explicit-row-date',
        source_url_hash: hash('5'),
      },
      data: {
        flowData: [
          {
            poiId: 'demo',
            _source_path: 'data.flowData.0',
            listExposure: 60,
            detailExposure: 30,
            flowRate: 50,
            orderFillingNum: 12,
            orderSubmitNum: 6,
          },
        ],
      },
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      defaultedDateRows: 1,
      issuesPresent: ['target_date_explicit_row_date_missing'],
      issuesAbsent: ['required_traffic_metric_keys_missing', 'desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'missing_desensitized_evidence_blocked',
    platform: 'meituan',
    payload: {
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            listExposure: 60,
            detailExposure: 30,
            flowRate: 50,
            orderFillingNum: 12,
            orderSubmitNum: 6,
          },
        ],
      },
    },
    expect: { exitCode: 1, status: 'blocked', targetRows: 1, evidenceRows: 0, issuesPresent: ['desensitized_capture_evidence_missing'] },
  },
  {
    name: 'raw_source_url_blocked',
    platform: 'ctrip',
    payload: {
      data: {
        flowData: [
          {
            hotelId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            _source_url: 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData?spiderToken=secret',
            listExposure: 70,
            detailExposure: 35,
            flowRate: 50,
            orderFillingNum: 14,
            orderSubmitNum: 7,
          },
        ],
      },
    },
    expect: { exitCode: 1, status: 'blocked', targetRows: 1, evidenceRows: 0, issuesPresent: ['sensitive_payload_keys_detected', 'desensitized_capture_evidence_missing'] },
  },
  {
    name: 'raw_request_url_blocked_even_with_hash_evidence',
    platform: 'meituan',
    payload: {
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            request_url: 'https://ebooking.example.invalid/traffic/query?token=redacted',
            capture_evidence: {
              source_trace_id: 'meituan:raw-request-url',
              source_url_hash: hash('8'),
            },
            listExposure: 90,
            detailExposure: 45,
            flowRate: 50,
            orderFillingNum: 18,
            orderSubmitNum: 9,
          },
        ],
      },
    },
    expect: { exitCode: 1, status: 'blocked', targetRows: 1, evidenceRows: 1, issuesPresent: ['sensitive_payload_keys_detected'], issuesAbsent: ['desensitized_capture_evidence_missing'] },
  },
  {
    name: 'raw_endpoint_blocked_even_with_hash_evidence',
    platform: 'ctrip',
    payload: {
      data: {
        flowData: [
          {
            hotelId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            endpoint: 'https://ebooking.example.invalid/traffic/query?token=redacted',
            capture_evidence: {
              source_trace_id: 'ctrip:raw-endpoint',
              source_url_hash: hash('7'),
            },
            listExposure: 95,
            detailExposure: 40,
            flowRate: 42.11,
            orderFillingNum: 12,
            orderSubmitNum: 6,
          },
        ],
      },
    },
    expect: { exitCode: 1, status: 'blocked', targetRows: 1, evidenceRows: 1, issuesPresent: ['sensitive_payload_keys_detected'], issuesAbsent: ['desensitized_capture_evidence_missing'] },
  },
  {
    name: 'raw_url_value_blocked_even_under_generic_key',
    platform: 'meituan',
    payload: {
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            capture_note: 'captured from https://ebooking.example.invalid/traffic/query?token=redacted',
            capture_evidence: {
              source_trace_id: 'meituan:raw-url-value',
              source_url_hash: hash('6'),
            },
            listExposure: 88,
            detailExposure: 44,
            flowRate: 50,
            orderFillingNum: 10,
            orderSubmitNum: 5,
          },
        ],
      },
    },
    expect: { exitCode: 1, status: 'blocked', targetRows: 1, evidenceRows: 1, issuesPresent: ['sensitive_payload_keys_detected'], issuesAbsent: ['desensitized_capture_evidence_missing'] },
  },
];

for (const item of cases) {
  const result = runImporterCase(item);
  const issueCodes = new Set((result.issues || []).map((issue) => issue.code));
  const summary = result.summary || {};
  check(item.name, `${item.name} status`, result.status === item.expect.status, `expected ${item.expect.status}, got ${result.status}`);
  check(item.name, `${item.name} exit code`, result.exitCode === item.expect.exitCode, `expected ${item.expect.exitCode}, got ${result.exitCode}`);
  check(item.name, `${item.name} target rows`, Number(summary.target_date_rows || 0) === item.expect.targetRows, JSON.stringify(summary));
  check(item.name, `${item.name} evidence rows`, Number(summary.rows_with_desensitized_capture_evidence || 0) === item.expect.evidenceRows, JSON.stringify(summary));
  if (Number.isFinite(Number(item.expect.defaultedDateRows))) {
    check(item.name, `${item.name} defaulted date rows`, Number(summary.defaulted_date_rows || 0) === item.expect.defaultedDateRows, JSON.stringify(summary));
  }
  if (Number.isFinite(Number(item.expect.missingSourcePathRows))) {
    check(item.name, `${item.name} missing source path rows`, Number(summary.missing_source_path_rows || 0) === item.expect.missingSourcePathRows, JSON.stringify(summary));
  }
  const trafficEvidence = Array.isArray(result.traffic_evidence) ? result.traffic_evidence : [];
  if (item.expect.status === 'ready_to_import') {
    const uiStatus = trafficEvidence[0]?.ui_status || {};
    check(item.name, `${item.name} evidence UI status ready`, uiStatus.field_fact_status === 'ready', JSON.stringify(uiStatus));
    check(item.name, `${item.name} evidence UI status does not expose raw data`, uiStatus.raw_data_exposed === false, JSON.stringify(uiStatus));
    check(item.name, `${item.name} evidence UI status covers traffic metrics`, Number(uiStatus.metric_key_count || 0) >= 5 && Number(uiStatus.stored_value_present_count || 0) >= 5, JSON.stringify(uiStatus));
  }
  for (const code of item.expect.issuesPresent || []) {
    check(item.name, `${item.name} issue ${code} present`, issueCodes.has(code), [...issueCodes].join(', '));
  }
  for (const code of item.expect.issuesAbsent || []) {
    check(item.name, `${item.name} issue ${code} absent`, !issueCodes.has(code), [...issueCodes].join(', '));
  }
}

const evidenceCase = cases.find((item) => item.name === 'payload_level_evidence_propagates_to_rows');
if (evidenceCase) {
  const importerResult = runImporterCase(evidenceCase);
  const verifierResult = runP0VerifierWithTrafficEvidence(importerResult, evidenceCase.platform);
  const scopedVerifierResult = runP0VerifierWithTrafficEvidence(importerResult, evidenceCase.platform, systemHotelId);
  const mismatchedScopedVerifierResult = runP0VerifierWithTrafficEvidence(importerResult, evidenceCase.platform, '8');
  check('external_evidence_contract', 'P0 verifier keeps DB closure incomplete with external evidence only', verifierResult.status === 'incomplete', JSON.stringify(verifierResult.summary || {}));
  check('external_evidence_contract', 'P0 verifier accepts importer traffic_evidence as valid', verifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'valid', JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier keeps importer UI status ready', verifierResult.external_traffic_evidence?.platforms?.meituan?.ui_statuses?.includes('ready'), JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier counts ready UI evidence rows', Number(verifierResult.external_traffic_evidence?.platforms?.meituan?.ui_status_ready_rows || 0) > 0, JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier accepts matching system hotel scoped evidence', scopedVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'valid', JSON.stringify(scopedVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier rejects mismatched system hotel scoped evidence', mismatchedScopedVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(mismatchedScopedVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier policy says external evidence is not DB closure', String(verifierResult.external_traffic_evidence?.completion_policy || '').includes('P0 still requires ingested target-date traffic rows'), String(verifierResult.external_traffic_evidence?.completion_policy || ''));
  const rawUrlEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  rawUrlEvidenceResult.traffic_evidence[0].capture_evidence.request_url = 'https://ebooking.example.invalid/traffic/query?token=redacted';
  const rawUrlVerifierResult = runP0VerifierWithTrafficEvidence(rawUrlEvidenceResult, evidenceCase.platform, systemHotelId);
  check('external_evidence_contract', 'P0 verifier rejects raw request_url in external evidence', rawUrlVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(rawUrlVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier marks raw request_url evidence as sensitive', rawUrlVerifierResult.external_traffic_evidence?.platforms?.meituan?.sensitive_values_exposed === true, JSON.stringify(rawUrlVerifierResult.external_traffic_evidence || {}));
  const rawEndpointEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  rawEndpointEvidenceResult.traffic_evidence[0].capture_evidence.endpoint = 'https://ebooking.example.invalid/traffic/query?token=redacted';
  const rawEndpointVerifierResult = runP0VerifierWithTrafficEvidence(rawEndpointEvidenceResult, evidenceCase.platform, systemHotelId);
  check('external_evidence_contract', 'P0 verifier rejects raw endpoint in external evidence', rawEndpointVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(rawEndpointVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier marks raw endpoint evidence as sensitive', rawEndpointVerifierResult.external_traffic_evidence?.platforms?.meituan?.sensitive_values_exposed === true, JSON.stringify(rawEndpointVerifierResult.external_traffic_evidence || {}));
  const rawUrlValueEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  rawUrlValueEvidenceResult.traffic_evidence[0].capture_evidence.note = 'captured from https://ebooking.example.invalid/traffic/query?token=redacted';
  const rawUrlValueVerifierResult = runP0VerifierWithTrafficEvidence(rawUrlValueEvidenceResult, evidenceCase.platform, systemHotelId);
  check('external_evidence_contract', 'P0 verifier rejects raw URL string values in external evidence', rawUrlValueVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(rawUrlValueVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier marks raw URL string values as sensitive', rawUrlValueVerifierResult.external_traffic_evidence?.platforms?.meituan?.sensitive_values_exposed === true, JSON.stringify(rawUrlValueVerifierResult.external_traffic_evidence || {}));
  const missingFactCaptureEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingFactCaptureEvidenceResult.traffic_evidence[0].field_facts[0].capture_evidence;
  const missingFactCaptureVerifierResult = runP0VerifierWithTrafficEvidence(missingFactCaptureEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingFactCaptureIssues = (missingFactCaptureVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field facts without metric-level capture evidence', missingFactCaptureVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingFactCaptureVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports missing metric-level capture evidence', missingFactCaptureIssues.includes('field_fact_capture_evidence_missing'), missingFactCaptureIssues.join(', '));
  const storedValueFalseEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  storedValueFalseEvidenceResult.traffic_evidence[0].field_facts[0].stored_value_present = false;
  const storedValueFalseVerifierResult = runP0VerifierWithTrafficEvidence(storedValueFalseEvidenceResult, evidenceCase.platform, systemHotelId);
  const storedValueFalseIssues = (storedValueFalseVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field facts without stored value proof', storedValueFalseVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(storedValueFalseVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports stored value proof missing', storedValueFalseIssues.includes('stored_value_present_not_true'), storedValueFalseIssues.join(', '));
  const weakSourcePathEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  weakSourcePathEvidenceResult.traffic_evidence[0].field_facts[0].source_path = 'listExposure';
  const weakSourcePathVerifierResult = runP0VerifierWithTrafficEvidence(weakSourcePathEvidenceResult, evidenceCase.platform, systemHotelId);
  const weakSourcePathIssues = (weakSourcePathVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field-name-only source paths', weakSourcePathVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(weakSourcePathVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports unstructured source path evidence', weakSourcePathIssues.includes('source_path_not_structured'), weakSourcePathIssues.join(', '));
}

const importerSource = readFileSync(importer, 'utf8');
const p0VerifierSource = readFileSync(p0Verifier, 'utf8');
check('execute_contract', 'execute uses prepared payload', importerSource.includes('p0_import_execute((array)$preparedExecute[\'payload\']'));
check('execute_contract', 'execute does not use raw payload', !importerSource.includes('p0_import_execute($payload'));
check('execute_contract', 'data.flowData execute payload shape is explicit', importerSource.includes("'payload_shape' => 'data.flowData'"));
check('execute_contract', 'validated_target_date_rows execute input source is explicit', importerSource.includes('validated_target_date_rows'));
check('execute_contract', 'execute performs DB readback verification', importerSource.includes('p0_import_post_execute_verification') && importerSource.includes("'post_execute_verification' => p0_import_post_execute_verification($options)"));
check('execute_contract', 'imported status requires post execute ready', importerSource.includes("($postExecuteVerification['status'] ?? '') === 'ready' ? 'imported' : 'blocked'"));
check('execute_contract', 'post execute incomplete stays explicit', importerSource.includes('post_execute_verification_incomplete'));
check('execute_contract', 'post execute readback tracks incomplete UI rows', importerSource.includes("'ui_status_incomplete_rows' => 0") && importerSource.includes("'sample_ui_statuses' => []"));
check('execute_contract', 'post execute ready requires zero incomplete UI rows', importerSource.includes("&& (int)$base['ui_status_incomplete_rows'] === 0"));
check('execute_contract', 'post execute exposes UI status incomplete state', importerSource.includes("'ui_status_incomplete'"));
check('execute_contract', 'importer blocks raw URL keys before evidence or execute', importerSource.includes('p0_import_is_raw_url_key') && importerSource.includes("str_ends_with($normalized, '_url')") && importerSource.includes("$normalized === 'endpoint'"));
check('execute_contract', 'importer blocks raw URL string values before evidence or execute', importerSource.includes('p0_import_is_raw_url_value') && importerSource.includes("preg_match('#https?://#i'"));
check('execute_contract', 'importer blocks rows without explicit source dates', importerSource.includes('p0_import_explicit_row_date') && importerSource.includes('target_date_explicit_row_date_missing') && importerSource.includes('command --date cannot be used as row-date evidence'));
check('execute_contract', 'importer blocks weak source paths', importerSource.includes('p0_import_explicit_source_path') && importerSource.includes('target_date_source_path_missing') && importerSource.includes('field names alone are not accepted as source-path evidence'));
check('traffic_evidence_contract', 'importer emits traffic_evidence', importerSource.includes("'traffic_evidence' => $trafficEvidence"));
check('traffic_evidence_contract', 'importer emits traffic evidence UI status with structured source path counts', importerSource.includes("'ui_status' => p0_import_external_ui_status") && importerSource.includes('structured_source_path_count'));
check('traffic_evidence_contract', 'importer keeps external evidence non-completion policy', importerSource.includes('External traffic_evidence validates desensitized source proof only'));
check('traffic_evidence_contract', 'importer blocks cross-row metric coverage', importerSource.includes('traffic_field_fact_preview_rows_incomplete') && importerSource.includes('cross-row metric coverage is not accepted'));
check('traffic_evidence_contract', 'importer blocks traffic evidence and execute row count mismatches', importerSource.includes('traffic_evidence_execute_row_count_mismatch') && importerSource.includes('Traffic evidence rows, target-date rows, and execute payload rows must match before import.') && importerSource.includes('$trafficEvidenceRowCount') && importerSource.includes('$executeRowCount'));
check('traffic_evidence_contract', 'importer requires exact P0 traffic storage fields in preview', importerSource.includes('$requiredStorageFields = p0_import_required_traffic_storage_fields()') && importerSource.includes("trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]"));
check('traffic_evidence_contract', 'P0 verifier requires external evidence UI status', p0VerifierSource.includes('ui_status_missing') && p0VerifierSource.includes('ui_status_not_ready'));
check('traffic_evidence_contract', 'P0 verifier requires metric-level capture evidence, structured source path, and stored value proof', p0VerifierSource.includes('p0_external_desensitized_capture_evidence') && p0VerifierSource.includes('field_fact_capture_evidence_missing') && p0VerifierSource.includes('source_path_not_structured') && p0VerifierSource.includes('structured_source_path_count') && p0VerifierSource.includes('stored_value_present_not_true'));
check('traffic_evidence_contract', 'P0 verifier blocks raw URL keys in external evidence', p0VerifierSource.includes('p0_external_is_raw_url_key') && p0VerifierSource.includes("str_ends_with($normalized, '_url')") && p0VerifierSource.includes("$normalized === 'endpoint'"));
check('traffic_evidence_contract', 'P0 verifier blocks raw URL string values in external evidence', p0VerifierSource.includes('p0_external_is_raw_url_value') && p0VerifierSource.includes("preg_match('#https?://#i'"));
check('traffic_evidence_contract', 'P0 verifier requires stored traffic row UI status and structured source paths', p0VerifierSource.includes('p0_traffic_row_ui_status') && p0VerifierSource.includes('ui_status_incomplete_rows') && p0VerifierSource.includes('p0_source_path_is_structured') && p0VerifierSource.includes('source_path_structured'));
check('traffic_evidence_contract', 'P0 verifier supports system hotel scoped closure', p0VerifierSource.includes('system-hotel-id') && p0VerifierSource.includes('system_hotel_id_mismatch'));
check('traffic_evidence_contract', 'P0 verifier exposes hotel scoped traffic sources and commands', p0VerifierSource.includes('hotel_scoped_sources') && p0VerifierSource.includes('hotel_scoped_commands') && p0VerifierSource.includes('payload_import_execute_command') && p0VerifierSource.includes('--system-hotel-id=') && p0VerifierSource.includes('Hotel Scoped Traffic Sources') && p0VerifierSource.includes('Hotel Scoped Traffic Commands'));
check('traffic_evidence_contract', 'P0 verifier exposes hotel scoped payload contracts without importable fake data', p0VerifierSource.includes('hotel_scoped_payload_contracts') && p0VerifierSource.includes('p0_hotel_scoped_traffic_payload_contract') && p0VerifierSource.includes('contract_only_not_importable') && p0VerifierSource.includes('requires_real_ota_payload') && p0VerifierSource.includes('Hotel Scoped Payload Contracts') && p0VerifierSource.includes('dry_run_acceptance'));
check('traffic_evidence_contract', 'P0 verifier exposes hotel scoped capture bridges without sensitive profile details', p0VerifierSource.includes('hotel_scoped_capture_bridges') && p0VerifierSource.includes('p0_hotel_scoped_capture_bridge_contract') && p0VerifierSource.includes('Hotel Scoped Capture Bridges') && p0VerifierSource.includes('browser_login_prepare_command') && p0VerifierSource.includes('browser_capture_command') && p0VerifierSource.includes('bridge_to_importer_command') && p0VerifierSource.includes('authorized_profile_matches_selected_hotel') && p0VerifierSource.includes('raw_profile_path_in_report'));
check('traffic_evidence_contract', 'P0 verifier does not treat profile directory presence as verified login', p0VerifierSource.includes('traffic_profile_login_verified_count') && p0VerifierSource.includes('manual_login_state_verified') && p0VerifierSource.includes('login state has been manually verified'));

const failed = checks.filter((item) => !item.ok);
if (failed.length > 0) {
  console.error('P0 OTA traffic payload importer verification failed:');
  for (const failure of failed) {
    console.error(`- ${failure.caseName}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`[verify:p0-ota-traffic-importer] ${checks.length} checks passed`);

function trafficRow({ hotelId = '', poiId = '', date: rowDate = '', dataDate = '', trace, hash: sourceHash, sourcePath = 'data.flowData.0' }) {
  return {
    ...(hotelId ? { hotelId } : {}),
    ...(poiId ? { poiId } : {}),
    ...(rowDate ? { date: rowDate } : {}),
    ...(dataDate ? { dataDate } : {}),
    _source_path: sourcePath,
    capture_evidence: {
      source_trace_id: trace,
      source_url_hash: sourceHash,
    },
    listExposure: 20,
    detailExposure: 10,
    flowRate: 50,
    orderFillingNum: 4,
    orderSubmitNum: 2,
  };
}

function runImporterCase(item) {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-ota-importer-'));
  const payloadPath = path.join(dir, `${item.name}.json`);
  try {
    writeFileSync(payloadPath, JSON.stringify(item.payload, null, 2), 'utf8');
    const child = spawnSync(phpBinary, [
      importer,
      `--platform=${item.platform}`,
      `--date=${date}`,
      `--system-hotel-id=${systemHotelId}`,
      `--payload=${payloadPath}`,
      '--format=json',
    ], {
      cwd: root,
      encoding: 'utf8',
    });
    const stdout = String(child.stdout || '').trim();
    let parsed = {};
    try {
      parsed = JSON.parse(stdout);
    } catch (error) {
      throw new Error(`${item.name} returned invalid JSON: ${error.message}; stdout=${stdout}; stderr=${child.stderr || ''}`);
    }
    parsed.exitCode = Number(child.status ?? 0);
    return parsed;
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function runP0VerifierWithTrafficEvidence(importerResult, platform, scopedSystemHotelId = '') {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-ota-evidence-'));
  const evidencePath = path.join(dir, 'traffic-evidence.json');
  try {
    writeFileSync(evidencePath, JSON.stringify(importerResult, null, 2), 'utf8');
    const args = [
      p0Verifier,
      `--platform=${platform}`,
      `--date=${date}`,
      `--traffic-evidence=${evidencePath}`,
      '--format=json',
    ];
    if (scopedSystemHotelId) {
      args.splice(3, 0, `--system-hotel-id=${scopedSystemHotelId}`);
    }
    const child = spawnSync(phpBinary, args, {
      cwd: root,
      encoding: 'utf8',
    });
    const stdout = String(child.stdout || '').trim();
    try {
      return JSON.parse(stdout);
    } catch (error) {
      throw new Error(`P0 verifier returned invalid JSON: ${error.message}; stdout=${stdout}; stderr=${child.stderr || ''}`);
    }
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function check(caseName, label, ok, detail = '') {
  checks.push({ caseName, label, ok: Boolean(ok), detail });
}
