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
const runtimeExecuteDate = '2099-12-31';
const runtimeExecuteSystemHotelId = systemHotelId;
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
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      store_id: 'demo',
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('e'),
          source_trace_id: 'meituan:browser-capture-row',
        },
      ],
      traffic: [
        trafficRow({
          storeId: 'demo',
          dataDate: date,
          dateSource: 'row',
          trace: 'meituan:browser-capture-row',
          hash: hash('e'),
        }),
      ],
    },
    expect: { exitCode: 0, status: 'ready_to_import', targetRows: 1, evidenceRows: 1, browserResponseEvidenceRows: 1, issuesAbsent: ['desensitized_capture_evidence_missing', 'browser_capture_response_evidence_missing'] },
  },
  {
    name: 'browser_capture_raw_metadata_projection_ready',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      hotel_id: 'demo',
      default_data_date: date,
      auth_status: {
        ok: true,
        status: 'logged_in',
        url: 'https://ebooking.ctrip.com/login/index',
        authorizationHeader: 'Bearer: redacted',
        profilePath: 'D:\\sensitive\\browser-profile',
      },
      capture_gate: {
        status: 'pass',
        failed_check_ids: [],
        mode: 'capture',
        rawCookie: 'Cookie: redacted',
        profileDir: 'D:\\sensitive\\browser-profile',
      },
      pages: [{ name: 'traffic', url: 'https://ebooking.ctrip.com/traffic?token=redacted', ok: true }],
      screenshots: [{ name: 'traffic', path: 'D:\\sensitive\\path\\shot.png' }],
      responses: [
        {
          url: 'https://ebooking.ctrip.com/restapi/soa2/traffic?spiderToken=redacted',
          spiderToken: 'redacted',
          section: 'traffic',
          row_count: 1,
          status: 200,
          url_hash: hash('browser-projection'),
          source_trace_id: 'ctrip:browser-projection',
          request_date_source: 'request.query.date',
        },
      ],
      traffic: [
        trafficRow({
          hotelId: 'demo',
          date,
          dateSource: 'row',
          trace: 'ctrip:browser-projection',
          hash: hash('browser-projection'),
          sourcePath: 'traffic.0',
        }),
      ].map((row) => ({
        ...row,
        _source_url: 'https://ebooking.ctrip.com/restapi/soa2/traffic?spiderToken=redacted',
        capture_note: 'source https://ebooking.ctrip.com/raw?token=redacted',
        accessToken: 'redacted',
        rawProfilePath: 'D:\\sensitive\\browser-profile',
      })),
    },
    expect: {
      exitCode: 0,
      status: 'ready_to_import',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 1,
      projectionApplied: true,
      projectionRemovedMin: 11,
      projectionRemovedPaths: [
        'auth_status.authorizationHeader',
        'auth_status.profilePath',
        'capture_gate.rawCookie',
        'capture_gate.profileDir',
        'responses.0.spiderToken',
        'traffic.0.accessToken',
        'traffic.0.rawProfilePath',
      ],
      projectionDroppedTopLevelMin: 2,
      issuesAbsent: [
        'sensitive_payload_keys_detected',
        'desensitized_capture_evidence_missing',
        'browser_capture_response_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
      ],
    },
  },
  {
    name: 'browser_capture_request_date_source_ready',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      hotel_id: 'demo',
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('request-date'),
          source_trace_id: 'ctrip:request-date-row',
          request_date_source: 'request.payload.dataDate',
        },
      ],
      traffic: [
        trafficRow({
          hotelId: 'demo',
          date,
          dateSource: 'request.payload.dataDate',
          trace: 'ctrip:request-date-row',
          hash: hash('request-date'),
          sourcePath: 'data.traffic.0',
        }),
      ],
    },
    expect: {
      exitCode: 0,
      status: 'ready_to_import',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 1,
      defaultedDateRows: 0,
      issuesAbsent: [
        'target_date_explicit_row_date_missing',
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_response_evidence_missing',
      ],
    },
  },
  {
    name: 'browser_capture_standard_row_count_ready',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      hotel_id: 'demo',
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 0,
          standard_row_count: 1,
          url_hash: hash('standard-row-count'),
          source_trace_id: 'ctrip:standard-row-count',
          request_date_source: 'request.query.date',
        },
      ],
      standard_rows: [
        {
          source: 'ctrip',
          data_type: 'traffic',
          hotel_id: 'demo',
          data_date: date,
          date_source: 'request.query.date',
          _source_path: 'standard_rows.0',
          capture_evidence: {
            source_trace_id: 'ctrip:standard-row-count',
            source_url_hash: hash('standard-row-count'),
          },
          list_exposure: 30,
          detail_exposure: 15,
          flow_rate: 50,
          order_filling_num: 6,
          order_submit_num: 3,
        },
      ],
    },
    expect: {
      exitCode: 0,
      status: 'ready_to_import',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 1,
      issuesAbsent: [
        'target_date_explicit_row_date_missing',
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_row_date_source_missing',
        'browser_capture_response_evidence_missing',
      ],
    },
  },
  {
    name: 'ctrip_browser_capture_top_level_hotel_id_missing_blocked',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('missing-browser-hotel-id'),
          source_trace_id: 'ctrip:missing-browser-hotel-id',
          request_date_source: 'request.query.date',
        },
      ],
      traffic: [
        trafficRow({
          hotelId: 'demo',
          date,
          dateSource: 'row',
          trace: 'ctrip:missing-browser-hotel-id',
          hash: hash('missing-browser-hotel-id'),
          sourcePath: 'traffic.0',
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 1,
      issuesPresent: ['browser_capture_platform_hotel_identifier_missing'],
      issuesAbsent: [
        'target_date_platform_hotel_identifier_missing',
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_row_date_source_missing',
        'browser_capture_response_evidence_missing',
      ],
    },
  },
  {
    name: 'browser_capture_standard_rows_missing_date_source_blocked',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('standard-date'),
          source_trace_id: 'ctrip:standard-row-date',
        },
      ],
      standard_rows: [
        {
          source: 'ctrip',
          data_type: 'traffic',
          hotel_id: 'demo',
          data_date: date,
          _source_path: 'standard_rows.0',
          capture_evidence: {
            source_trace_id: 'ctrip:standard-row-date',
            source_url_hash: hash('standard-date'),
          },
          list_exposure: 30,
          detail_exposure: 15,
          flow_rate: 50,
          order_filling_num: 6,
          order_submit_num: 3,
        },
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 1,
      issuesPresent: ['browser_capture_row_date_source_missing'],
      issuesAbsent: [
        'target_date_explicit_row_date_missing',
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_response_evidence_missing',
      ],
    },
  },
  {
    name: 'browser_capture_non_traffic_response_evidence_blocked',
    platform: 'meituan',
    payload: {
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'orders',
          row_count: 1,
          status: 200,
          url_hash: hash('non-traffic-response'),
          source_trace_id: 'meituan:non-traffic-response',
        },
      ],
      traffic: [
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          dateSource: 'row',
          trace: 'meituan:non-traffic-response',
          hash: hash('non-traffic-response'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 0,
      issuesPresent: ['browser_capture_response_evidence_missing'],
      issuesAbsent: [
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_row_date_source_missing',
      ],
    },
  },
  {
    name: 'browser_capture_response_row_count_missing_blocked',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          status: 200,
          url_hash: hash('traffic-response-no-row-count'),
          source_trace_id: 'ctrip:traffic-response-no-row-count',
        },
      ],
      traffic: [
        trafficRow({
          hotelId: 'demo',
          date,
          dateSource: 'row',
          trace: 'ctrip:traffic-response-no-row-count',
          hash: hash('traffic-response-no-row-count'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 0,
      issuesPresent: ['browser_capture_response_evidence_missing'],
      issuesAbsent: [
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_row_date_source_missing',
      ],
    },
  },
  {
    name: 'browser_capture_response_row_count_underflow_blocked',
    platform: 'meituan',
    payload: {
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          status: 200,
          url_hash: hash('traffic-response-underflow'),
          source_trace_id: 'meituan:traffic-response-underflow',
        },
      ],
      traffic: [
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          dateSource: 'row',
          trace: 'meituan:traffic-response-underflow',
          hash: hash('traffic-response-underflow'),
          sourcePath: 'traffic.0',
        }),
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          dateSource: 'row',
          trace: 'meituan:traffic-response-underflow',
          hash: hash('traffic-response-underflow'),
          sourcePath: 'traffic.1',
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 2,
      evidenceRows: 2,
      rowLevelEvidenceRows: 2,
      browserResponseEvidenceRows: 1,
      issuesPresent: ['browser_capture_response_evidence_missing'],
      issuesAbsent: [
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_row_date_source_missing',
      ],
    },
  },
  {
    name: 'browser_capture_auth_failed_blocked',
    platform: 'meituan',
    payload: {
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: false, status: 'login_required' },
      capture_gate: { status: 'fail', failed_check_ids: ['auth_login_required'], mode: 'capture' },
      traffic: [
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          trace: 'meituan:auth-failed-row',
          hash: hash('0'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      issuesPresent: ['browser_capture_auth_not_verified', 'browser_capture_gate_not_pass'],
      issuesAbsent: ['desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'browser_capture_login_only_blocked',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'login_only',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'login_only' },
      traffic: [
        trafficRow({
          hotelId: 'demo',
          date,
          trace: 'ctrip:login-only-row',
          hash: hash('1'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      issuesPresent: ['browser_capture_login_only_not_importable'],
      issuesAbsent: ['desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'payload_system_hotel_mismatch_blocked',
    platform: 'meituan',
    payload: {
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: 8,
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      traffic: [
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          trace: 'meituan:hotel-mismatch-row',
          hash: hash('2'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      issuesPresent: ['system_hotel_id_mismatch'],
      issuesAbsent: ['desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'browser_capture_scope_missing_blocked',
    platform: 'meituan',
    payload: {
      mode: 'capture',
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      traffic: [
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          trace: 'meituan:scope-missing-row',
          hash: hash('3'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      issuesPresent: ['browser_capture_source_missing', 'browser_capture_system_hotel_id_missing'],
      issuesAbsent: ['desensitized_capture_evidence_missing'],
    },
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
    name: 'single_row_missing_required_metric_blocked',
    platform: 'meituan',
    payload: {
      capture_evidence: {
        source_trace_id: 'meituan:missing-required-metric',
        source_url_hash: hash('missingmetric'),
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
          },
        ],
      },
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      completePreviewRows: 0,
      missingMetricKeys: ['order_submit_num'],
      issuesPresent: ['required_traffic_metric_keys_missing', 'traffic_field_fact_preview_rows_incomplete'],
      issuesAbsent: ['desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'metric_level_evidence_requires_trace_and_source_hash',
    platform: 'meituan',
    payload: {
      capture_evidence: {
        source_trace_id: 'meituan:payload-level-trace-only',
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
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      completeEvidenceRows: 0,
      issuesPresent: ['required_traffic_metric_keys_missing', 'traffic_field_fact_preview_rows_incomplete', 'desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'metric_level_evidence_must_match_row_trace_and_hash',
    platform: 'meituan',
    payload: {
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
            _source_path: 'data.flowData.0',
            source_trace_id: 'meituan:row-level-response',
            source_url_hash: hash('6'),
            capture_evidence: {
              source_trace_id: 'meituan:other-response',
              source_url_hash: hash('7'),
            },
            listExposure: 50,
            detailExposure: 25,
            flowRate: 50,
            orderFillingNum: 10,
            orderSubmitNum: 5,
          },
        ],
      },
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      issuesPresent: ['required_traffic_metric_keys_missing', 'traffic_field_fact_preview_rows_incomplete'],
      issuesAbsent: ['desensitized_capture_evidence_missing'],
    },
  },
  {
    name: 'browser_capture_payload_level_evidence_blocked',
    platform: 'meituan',
    payload: {
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      capture_evidence: {
        source_trace_id: 'meituan:payload-level-browser-evidence',
        source_url_hash: hash('8'),
      },
      traffic: [
        {
          poiId: 'demo',
          dataDate: date,
          _source_path: 'traffic.0',
          listExposure: 50,
          detailExposure: 25,
          flowRate: 50,
          orderFillingNum: 10,
          orderSubmitNum: 5,
        },
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 0,
      rowLevelEvidenceRows: 0,
      issuesPresent: ['desensitized_capture_evidence_missing', 'browser_capture_row_capture_evidence_missing'],
    },
  },
  {
    name: 'browser_capture_response_mismatch_blocked',
    platform: 'meituan',
    payload: {
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('x'),
          source_trace_id: 'meituan:mismatched-response',
        },
      ],
      traffic: [
        trafficRow({
          poiId: 'demo',
          dataDate: date,
          trace: 'meituan:unmatched-row',
          hash: hash('y'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 0,
      issuesPresent: ['browser_capture_response_evidence_missing'],
      issuesAbsent: ['desensitized_capture_evidence_missing', 'browser_capture_row_capture_evidence_missing'],
    },
  },
  {
    name: 'browser_capture_default_data_date_not_row_evidence_blocked',
    platform: 'meituan',
    payload: {
      source: 'meituan_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      default_data_date: date,
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('default-date'),
          source_trace_id: 'meituan:default-date-row',
        },
      ],
      traffic: [
        trafficRow({
          poiId: 'demo',
          trace: 'meituan:default-date-row',
          hash: hash('default-date'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 1,
      defaultedDateRows: 1,
      issuesPresent: ['target_date_explicit_row_date_missing'],
      issuesAbsent: [
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_response_evidence_missing',
      ],
    },
  },
  {
    name: 'browser_capture_context_date_source_not_row_evidence_blocked',
    platform: 'ctrip',
    payload: {
      source: 'ctrip_browser_profile',
      mode: 'capture',
      system_hotel_id: Number(systemHotelId),
      default_data_date: date,
      auth_status: { ok: true, status: 'logged_in' },
      capture_gate: { status: 'pass', failed_check_ids: [], mode: 'capture' },
      responses: [
        {
          section: 'traffic',
          row_count: 1,
          url_hash: hash('context-date'),
          source_trace_id: 'ctrip:context-date-row',
        },
      ],
      traffic: [
        trafficRow({
          hotelId: 'demo',
          date,
          dateSource: 'capture_context.default_data_date',
          trace: 'ctrip:context-date-row',
          hash: hash('context-date'),
          sourcePath: 'data.traffic.0',
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      rowLevelEvidenceRows: 1,
      browserResponseEvidenceRows: 1,
      defaultedDateRows: 1,
      issuesPresent: ['target_date_explicit_row_date_missing'],
      issuesAbsent: [
        'desensitized_capture_evidence_missing',
        'browser_capture_row_capture_evidence_missing',
        'browser_capture_response_evidence_missing',
      ],
    },
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
    name: 'missing_platform_hotel_identifier_blocked',
    platform: 'ctrip',
    payload: {
      traffic: [
        trafficRow({
          date,
          trace: 'ctrip:missing-platform-hotel-id',
          hash: hash('h'),
        }),
      ],
    },
    expect: {
      exitCode: 1,
      status: 'blocked',
      targetRows: 1,
      evidenceRows: 1,
      missingPlatformHotelIdentifierRows: 1,
      issuesPresent: ['target_date_platform_hotel_identifier_missing'],
      issuesAbsent: ['required_traffic_metric_keys_missing', 'desensitized_capture_evidence_missing', 'target_date_source_path_missing', 'target_date_explicit_row_date_missing'],
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
  if (Number.isFinite(Number(item.expect.completeEvidenceRows))) {
    check(item.name, `${item.name} complete evidence rows`, Number(summary.rows_with_complete_desensitized_capture_evidence || 0) === item.expect.completeEvidenceRows, JSON.stringify(summary));
  }
  if (Number.isFinite(Number(item.expect.rowLevelEvidenceRows))) {
    check(item.name, `${item.name} row-level evidence rows`, Number(summary.row_level_desensitized_capture_evidence_rows || 0) === item.expect.rowLevelEvidenceRows, JSON.stringify(summary));
  }
  if (Number.isFinite(Number(item.expect.rowLevelCompleteEvidenceRows))) {
    check(item.name, `${item.name} row-level complete evidence rows`, Number(summary.row_level_complete_desensitized_capture_evidence_rows || 0) === item.expect.rowLevelCompleteEvidenceRows, JSON.stringify(summary));
  }
  if (Number.isFinite(Number(item.expect.browserResponseEvidenceRows))) {
    check(item.name, `${item.name} browser response evidence rows`, Number(summary.browser_response_evidence_rows || 0) === item.expect.browserResponseEvidenceRows, JSON.stringify(summary));
  }
  if (typeof item.expect.projectionApplied === 'boolean') {
    check(item.name, `${item.name} payload import projection applied`, result.payload_import_projection?.applied === item.expect.projectionApplied, JSON.stringify(result.payload_import_projection || {}));
  }
  if (Number.isFinite(Number(item.expect.projectionRemovedMin))) {
    check(item.name, `${item.name} payload import projection removed sensitive metadata`, Number(result.payload_import_projection?.removed_sensitive_metadata_count || 0) >= item.expect.projectionRemovedMin, JSON.stringify(result.payload_import_projection || {}));
  }
  for (const removedPath of item.expect.projectionRemovedPaths || []) {
    const removedPaths = result.payload_import_projection?.removed_sensitive_metadata_paths || [];
    check(item.name, `${item.name} payload import projection removed ${removedPath}`, removedPaths.includes(removedPath), JSON.stringify(removedPaths));
  }
  if (Number.isFinite(Number(item.expect.projectionDroppedTopLevelMin))) {
    check(item.name, `${item.name} payload import projection dropped top-level capture metadata`, Number(result.payload_import_projection?.dropped_top_level_key_count || 0) >= item.expect.projectionDroppedTopLevelMin, JSON.stringify(result.payload_import_projection || {}));
  }
  if (Number.isFinite(Number(item.expect.defaultedDateRows))) {
    check(item.name, `${item.name} defaulted date rows`, Number(summary.defaulted_date_rows || 0) === item.expect.defaultedDateRows, JSON.stringify(summary));
  }
  if (Number.isFinite(Number(item.expect.missingSourcePathRows))) {
    check(item.name, `${item.name} missing source path rows`, Number(summary.missing_source_path_rows || 0) === item.expect.missingSourcePathRows, JSON.stringify(summary));
  }
  if (Number.isFinite(Number(item.expect.missingPlatformHotelIdentifierRows))) {
    check(item.name, `${item.name} missing platform hotel identifier rows`, Number(summary.missing_platform_hotel_identifier_rows || 0) === item.expect.missingPlatformHotelIdentifierRows, JSON.stringify(summary));
  }
  if (Number.isFinite(Number(item.expect.completePreviewRows))) {
    check(item.name, `${item.name} complete field fact preview rows`, Number(summary.complete_field_fact_preview_rows || 0) === item.expect.completePreviewRows, JSON.stringify(summary));
  }
  if (Array.isArray(item.expect.missingMetricKeys)) {
    const actualMissingMetricKeys = (summary.missing_metric_keys || []).map(String).sort();
    const expectedMissingMetricKeys = item.expect.missingMetricKeys.map(String).sort();
    check(item.name, `${item.name} missing metric keys`, JSON.stringify(actualMissingMetricKeys) === JSON.stringify(expectedMissingMetricKeys), JSON.stringify(summary));
  }
  const trafficEvidence = Array.isArray(result.traffic_evidence) ? result.traffic_evidence : [];
  if (item.expect.status === 'ready_to_import') {
    check(item.name, `${item.name} dry-run is not P0 complete`, result.p0_completion_status === 'pre_import_ready_not_p0_complete', JSON.stringify({ p0_completion_status: result.p0_completion_status, p0_completion_gate: result.p0_completion_gate }));
    const uiStatus = trafficEvidence[0]?.ui_status || {};
    check(item.name, `${item.name} evidence UI status ready`, uiStatus.field_fact_status === 'ready', JSON.stringify(uiStatus));
    check(item.name, `${item.name} evidence UI status does not expose raw data`, uiStatus.raw_data_exposed === false, JSON.stringify(uiStatus));
    check(item.name, `${item.name} evidence UI status covers traffic metrics`, Number(uiStatus.metric_key_count || 0) >= 5 && Number(uiStatus.stored_value_present_count || 0) >= 5, JSON.stringify(uiStatus));
    check(item.name, `${item.name} evidence UI status exposes desensitized capture evidence`, Number(uiStatus.desensitized_capture_evidence_count || 0) >= 5, JSON.stringify(uiStatus));
    check(item.name, `${item.name} evidence proves platform hotel identity without raw id`, trafficEvidence[0]?.platform_hotel_identifier_present === true && ['poi_id_family', 'hotel_id_family'].includes(trafficEvidence[0]?.platform_hotel_identifier_source), JSON.stringify(trafficEvidence[0] || {}));
    const closureChain = trafficEvidence[0]?.traffic_closure_chain || {};
    const closureChainKeys = ['capture_evidence', 'source_path', 'metric_key', 'storage_field', 'stored_value', 'ui_status', 'platform_hotel_identifier', 'verifier'];
    check(item.name, `${item.name} evidence exposes full pre-import closure chain`, closureChainKeys.every((key) => closureChain[key]), JSON.stringify(closureChain));
    check(item.name, `${item.name} evidence closure chain source stages are ready`, closureChainKeys.filter((key) => key !== 'verifier').every((key) => closureChain[key]?.status === 'ready'), JSON.stringify(closureChain));
    check(item.name, `${item.name} evidence closure chain keeps verifier as execute-required`, closureChain.verifier?.status === 'requires_execute_and_p0_verifier', JSON.stringify(closureChain.verifier || {}));
    check(item.name, `${item.name} evidence closure chain policy stays pre-import only`, String(trafficEvidence[0]?.traffic_closure_chain_policy || '').includes('pre-import source proof only'), String(trafficEvidence[0]?.traffic_closure_chain_policy || ''));
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
  const verifierPlatform = (verifierResult.platforms || []).find((platform) => platform.platform === evidenceCase.platform) || {};
  const verifierTrafficGate = verifierPlatform.p0_traffic_gate || {};
  check('external_evidence_contract', 'P0 verifier keeps DB closure incomplete with external evidence only', verifierResult.status === 'incomplete', JSON.stringify(verifierResult.summary || {}));
  check('external_evidence_contract', 'P0 traffic gate marks valid external evidence as not ingested', verifierTrafficGate.pre_import_evidence_status === 'valid_external_evidence_not_ingested' && verifierTrafficGate.status === 'missing_target_date_traffic_rows', JSON.stringify(verifierTrafficGate));
  check('external_evidence_contract', 'P0 traffic gate keeps external evidence policy separate from completion', String(verifierTrafficGate.pre_import_evidence_policy || '').includes('not P0 complete until target-date traffic rows are ingested'), String(verifierTrafficGate.pre_import_evidence_policy || ''));
  check('external_evidence_contract', 'P0 verifier accepts importer traffic_evidence as valid', verifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'valid', JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier counts platform hotel identity evidence rows', Number(verifierResult.external_traffic_evidence?.platforms?.meituan?.platform_hotel_identifier_rows || 0) > 0, JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier keeps importer UI status ready', verifierResult.external_traffic_evidence?.platforms?.meituan?.ui_statuses?.includes('ready'), JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier counts ready UI evidence rows', Number(verifierResult.external_traffic_evidence?.platforms?.meituan?.ui_status_ready_rows || 0) > 0, JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier counts ready pre-import closure chain rows', Number(verifierResult.external_traffic_evidence?.platforms?.meituan?.traffic_closure_chain_ready_rows || 0) > 0, JSON.stringify(verifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier accepts matching system hotel scoped evidence', scopedVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'valid', JSON.stringify(scopedVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier rejects mismatched system hotel scoped evidence', mismatchedScopedVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(mismatchedScopedVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier policy says external evidence is not DB closure', String(verifierResult.external_traffic_evidence?.completion_policy || '').includes('P0 still requires ingested target-date traffic rows'), String(verifierResult.external_traffic_evidence?.completion_policy || ''));
  const urlHashAliasEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  for (const row of urlHashAliasEvidenceResult.traffic_evidence || []) {
    if (row.capture_evidence?.source_url_hash) {
      row.capture_evidence.url_hash = row.capture_evidence.source_url_hash;
      delete row.capture_evidence.source_url_hash;
    }
    for (const fact of row.field_facts || []) {
      if (fact.capture_evidence?.source_url_hash) {
        fact.capture_evidence.url_hash = fact.capture_evidence.source_url_hash;
        delete fact.capture_evidence.source_url_hash;
      }
    }
  }
  const urlHashAliasVerifierResult = runP0VerifierWithTrafficEvidence(urlHashAliasEvidenceResult, evidenceCase.platform, systemHotelId);
  check('external_evidence_contract', 'P0 verifier accepts desensitized url_hash aliases in external evidence', urlHashAliasVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'valid', JSON.stringify(urlHashAliasVerifierResult.external_traffic_evidence || {}));
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
  const camelCaseSensitiveEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  camelCaseSensitiveEvidenceResult.traffic_evidence[0].profilePath = 'D:\\sensitive\\browser-profile';
  camelCaseSensitiveEvidenceResult.traffic_evidence[0].capture_evidence.rawCookie = 'redacted';
  camelCaseSensitiveEvidenceResult.traffic_evidence[0].field_facts[0].capture_evidence.accessToken = 'redacted';
  const camelCaseSensitiveVerifierResult = runP0VerifierWithTrafficEvidence(camelCaseSensitiveEvidenceResult, evidenceCase.platform, systemHotelId);
  const camelCaseSensitiveIssues = (camelCaseSensitiveVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects camelCase sensitive metadata keys in external evidence', camelCaseSensitiveVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(camelCaseSensitiveVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier marks camelCase sensitive metadata as exposed', camelCaseSensitiveVerifierResult.external_traffic_evidence?.platforms?.meituan?.sensitive_values_exposed === true, JSON.stringify(camelCaseSensitiveVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports sensitive material for camelCase metadata keys', camelCaseSensitiveIssues.includes('sensitive_material_present'), camelCaseSensitiveIssues.join(', '));
  const missingFactCaptureEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingFactCaptureEvidenceResult.traffic_evidence[0].field_facts[0].capture_evidence;
  const missingFactCaptureVerifierResult = runP0VerifierWithTrafficEvidence(missingFactCaptureEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingFactCaptureIssues = (missingFactCaptureVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field facts without metric-level capture evidence', missingFactCaptureVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingFactCaptureVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports missing metric-level capture evidence', missingFactCaptureIssues.includes('field_fact_capture_evidence_missing'), missingFactCaptureIssues.join(', '));
  const missingFactTraceEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingFactTraceEvidenceResult.traffic_evidence[0].field_facts[0].capture_evidence.source_trace_id;
  const missingFactTraceVerifierResult = runP0VerifierWithTrafficEvidence(missingFactTraceEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingFactTraceIssues = (missingFactTraceVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field facts without metric-level source trace id', missingFactTraceVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingFactTraceVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports missing metric-level source trace id', missingFactTraceIssues.includes('field_fact_source_trace_id_missing'), missingFactTraceIssues.join(', '));
  const mismatchedFactTraceEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  mismatchedFactTraceEvidenceResult.traffic_evidence[0].field_facts[0].capture_evidence.source_trace_id = 'meituan:other-response';
  const mismatchedFactTraceVerifierResult = runP0VerifierWithTrafficEvidence(mismatchedFactTraceEvidenceResult, evidenceCase.platform, systemHotelId);
  const mismatchedFactTraceIssues = (mismatchedFactTraceVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects cross-trace field fact evidence', mismatchedFactTraceVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(mismatchedFactTraceVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports cross-trace field fact evidence mismatch', mismatchedFactTraceIssues.includes('field_fact_source_trace_id_mismatch'), mismatchedFactTraceIssues.join(', '));
  const missingFactHashEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingFactHashEvidenceResult.traffic_evidence[0].field_facts[0].capture_evidence.source_url_hash;
  const missingFactHashVerifierResult = runP0VerifierWithTrafficEvidence(missingFactHashEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingFactHashIssues = (missingFactHashVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field facts without metric-level source URL hash', missingFactHashVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingFactHashVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports missing metric-level source URL hash', missingFactHashIssues.includes('field_fact_source_url_hash_missing'), missingFactHashIssues.join(', '));
  const mismatchedFactHashEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  mismatchedFactHashEvidenceResult.traffic_evidence[0].field_facts[0].capture_evidence.source_url_hash = hash('d');
  const mismatchedFactHashVerifierResult = runP0VerifierWithTrafficEvidence(mismatchedFactHashEvidenceResult, evidenceCase.platform, systemHotelId);
  const mismatchedFactHashIssues = (mismatchedFactHashVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects cross-source field fact evidence', mismatchedFactHashVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(mismatchedFactHashVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports cross-source field fact evidence mismatch', mismatchedFactHashIssues.includes('field_fact_source_url_hash_mismatch'), mismatchedFactHashIssues.join(', '));
  const storedValueFalseEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  storedValueFalseEvidenceResult.traffic_evidence[0].field_facts[0].stored_value_present = false;
  const storedValueFalseVerifierResult = runP0VerifierWithTrafficEvidence(storedValueFalseEvidenceResult, evidenceCase.platform, systemHotelId);
  const storedValueFalseIssues = (storedValueFalseVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field facts without stored value proof', storedValueFalseVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(storedValueFalseVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports stored value proof missing', storedValueFalseIssues.includes('stored_value_present_not_true'), storedValueFalseIssues.join(', '));
  const missingPlatformHotelIdentityEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingPlatformHotelIdentityEvidenceResult.traffic_evidence[0].platform_hotel_identifier_present;
  const missingPlatformHotelIdentityVerifierResult = runP0VerifierWithTrafficEvidence(missingPlatformHotelIdentityEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingPlatformHotelIdentityIssues = (missingPlatformHotelIdentityVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects external evidence without platform hotel identity proof', missingPlatformHotelIdentityVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingPlatformHotelIdentityVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports missing platform hotel identity proof', missingPlatformHotelIdentityIssues.includes('platform_hotel_identifier_missing'), missingPlatformHotelIdentityIssues.join(', '));
  const invalidPlatformHotelIdentitySourceEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  invalidPlatformHotelIdentitySourceEvidenceResult.traffic_evidence[0].platform_hotel_identifier_source = 'system_hotel_id';
  const invalidPlatformHotelIdentitySourceVerifierResult = runP0VerifierWithTrafficEvidence(invalidPlatformHotelIdentitySourceEvidenceResult, evidenceCase.platform, systemHotelId);
  const invalidPlatformHotelIdentitySourceIssues = (invalidPlatformHotelIdentitySourceVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects local system hotel id as platform identity source', invalidPlatformHotelIdentitySourceVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(invalidPlatformHotelIdentitySourceVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports invalid platform hotel identity source', invalidPlatformHotelIdentitySourceIssues.includes('platform_hotel_identifier_source_invalid'), invalidPlatformHotelIdentitySourceIssues.join(', '));
  const missingClosureChainEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingClosureChainEvidenceResult.traffic_evidence[0].traffic_closure_chain;
  const missingClosureChainVerifierResult = runP0VerifierWithTrafficEvidence(missingClosureChainEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingClosureChainIssues = (missingClosureChainVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects external evidence without traffic closure chain', missingClosureChainVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingClosureChainVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports missing traffic closure chain', missingClosureChainIssues.includes('traffic_closure_chain_missing'), missingClosureChainIssues.join(', '));
  const incompleteClosureStageEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  incompleteClosureStageEvidenceResult.traffic_evidence[0].traffic_closure_chain.source_path.status = 'incomplete';
  const incompleteClosureStageVerifierResult = runP0VerifierWithTrafficEvidence(incompleteClosureStageEvidenceResult, evidenceCase.platform, systemHotelId);
  const incompleteClosureStageIssues = (incompleteClosureStageVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects non-ready traffic closure chain stage', incompleteClosureStageVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(incompleteClosureStageVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports non-ready traffic closure chain stage', incompleteClosureStageIssues.includes('traffic_closure_chain_stage_not_ready'), incompleteClosureStageIssues.join(', '));
  const incompleteClosureMetricRequiredEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  incompleteClosureMetricRequiredEvidenceResult.traffic_evidence[0].traffic_closure_chain.metric_key.required = 'list_exposure,detail_exposure';
  const incompleteClosureMetricRequiredVerifierResult = runP0VerifierWithTrafficEvidence(incompleteClosureMetricRequiredEvidenceResult, evidenceCase.platform, systemHotelId);
  const incompleteClosureMetricRequiredIssues = (incompleteClosureMetricRequiredVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects incomplete closure chain metric-key requirement', incompleteClosureMetricRequiredVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(incompleteClosureMetricRequiredVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports incomplete closure chain metric-key requirement', incompleteClosureMetricRequiredIssues.includes('traffic_closure_chain_metric_key_required_incomplete'), incompleteClosureMetricRequiredIssues.join(', '));
  const incompleteClosureStorageRequiredEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  incompleteClosureStorageRequiredEvidenceResult.traffic_evidence[0].traffic_closure_chain.storage_field.required = 'online_daily_data.list_exposure,online_daily_data.detail_exposure';
  const incompleteClosureStorageRequiredVerifierResult = runP0VerifierWithTrafficEvidence(incompleteClosureStorageRequiredEvidenceResult, evidenceCase.platform, systemHotelId);
  const incompleteClosureStorageRequiredIssues = (incompleteClosureStorageRequiredVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects incomplete closure chain storage-field requirement', incompleteClosureStorageRequiredVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(incompleteClosureStorageRequiredVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports incomplete closure chain storage-field requirement', incompleteClosureStorageRequiredIssues.includes('traffic_closure_chain_storage_field_required_incomplete'), incompleteClosureStorageRequiredIssues.join(', '));
  const readyVerifierClosureEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  readyVerifierClosureEvidenceResult.traffic_evidence[0].traffic_closure_chain.verifier.status = 'ready';
  const readyVerifierClosureVerifierResult = runP0VerifierWithTrafficEvidence(readyVerifierClosureEvidenceResult, evidenceCase.platform, systemHotelId);
  const readyVerifierClosureIssues = (readyVerifierClosureVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects pre-import evidence that marks verifier ready', readyVerifierClosureVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(readyVerifierClosureVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports invalid traffic closure verifier status', readyVerifierClosureIssues.includes('traffic_closure_chain_verifier_status_invalid'), readyVerifierClosureIssues.join(', '));
  const incompleteClosureVerifierRequiredEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  incompleteClosureVerifierRequiredEvidenceResult.traffic_evidence[0].traffic_closure_chain.verifier.required = 'ready after source proof';
  const incompleteClosureVerifierRequiredVerifierResult = runP0VerifierWithTrafficEvidence(incompleteClosureVerifierRequiredEvidenceResult, evidenceCase.platform, systemHotelId);
  const incompleteClosureVerifierRequiredIssues = (incompleteClosureVerifierRequiredVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects incomplete closure chain verifier requirement', incompleteClosureVerifierRequiredVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(incompleteClosureVerifierRequiredVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports incomplete closure chain verifier requirement', incompleteClosureVerifierRequiredIssues.includes('traffic_closure_chain_verifier_required_incomplete'), incompleteClosureVerifierRequiredIssues.join(', '));
  const missingClosurePolicyEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingClosurePolicyEvidenceResult.traffic_evidence[0].traffic_closure_chain_policy;
  const missingClosurePolicyVerifierResult = runP0VerifierWithTrafficEvidence(missingClosurePolicyEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingClosurePolicyIssues = (missingClosurePolicyVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects closure chain without pre-import policy', missingClosurePolicyVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingClosurePolicyVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports missing traffic closure chain policy', missingClosurePolicyIssues.includes('traffic_closure_chain_policy_missing'), missingClosurePolicyIssues.join(', '));
  const missingUiDesensitizedCountEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  delete missingUiDesensitizedCountEvidenceResult.traffic_evidence[0].ui_status.desensitized_capture_evidence_count;
  const missingUiDesensitizedCountVerifierResult = runP0VerifierWithTrafficEvidence(missingUiDesensitizedCountEvidenceResult, evidenceCase.platform, systemHotelId);
  const missingUiDesensitizedCountPlatform = missingUiDesensitizedCountVerifierResult.external_traffic_evidence?.platforms?.meituan || {};
  const missingUiDesensitizedCountIssues = (missingUiDesensitizedCountVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects UI status without desensitized capture evidence count', missingUiDesensitizedCountVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(missingUiDesensitizedCountVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier does not count incomplete UI evidence as ready', Number(missingUiDesensitizedCountPlatform.ui_status_ready_rows || 0) === 0, JSON.stringify(missingUiDesensitizedCountPlatform));
  check('external_evidence_contract', 'P0 verifier reports incomplete UI desensitized capture evidence count', missingUiDesensitizedCountIssues.includes('ui_status_count_incomplete'), missingUiDesensitizedCountIssues.join(', '));
  const weakSourcePathEvidenceResult = JSON.parse(JSON.stringify(importerResult));
  weakSourcePathEvidenceResult.traffic_evidence[0].field_facts[0].source_path = 'listExposure';
  const weakSourcePathVerifierResult = runP0VerifierWithTrafficEvidence(weakSourcePathEvidenceResult, evidenceCase.platform, systemHotelId);
  const weakSourcePathIssues = (weakSourcePathVerifierResult.external_traffic_evidence?.platforms?.meituan?.issues || []).map((issue) => issue.code);
  check('external_evidence_contract', 'P0 verifier rejects field-name-only source paths', weakSourcePathVerifierResult.external_traffic_evidence?.platforms?.meituan?.status === 'invalid', JSON.stringify(weakSourcePathVerifierResult.external_traffic_evidence || {}));
  check('external_evidence_contract', 'P0 verifier reports unstructured source path evidence', weakSourcePathIssues.includes('source_path_not_structured'), weakSourcePathIssues.join(', '));
}

const placeholderEvidenceCases = [
  {
    name: 'endpoint_template_pending_redacted_evidence',
    platform: 'ctrip',
    evidence: {
      platform: 'ctrip',
      status: 'templates_ready_pending_redacted_evidence',
      templates: [{
        candidate_section: 'traffic_report',
        data_type: 'traffic',
        evidence_status: 'missing_evidence',
        safe_to_catalog: false,
      }],
      required_evidence: [
        'redacted_request_url_hash',
        'payload_shape',
        'preview_response_shape',
        'hotel_and_date_parameters',
      ],
    },
  },
  {
    name: 'capture_audit_login_required_zero_response',
    platform: 'ctrip',
    evidence: {
      platform: 'ctrip',
      status: 'blocked_auth',
      auth_status: { status: 'login_required' },
      summary: {
        response_count: 0,
        standard_row_count: 0,
      },
      capture_gate: {
        status: 'fail',
        failed_checks: ['auth_session', 'response_count', 'standard_rows'],
      },
      expected_sections: {
        traffic_report: {
          evidence_status: 'missing_evidence',
        },
      },
    },
  },
  {
    name: 'diagnosis_snapshot_example_revenue_only',
    platform: 'ctrip',
    evidence: {
      platform: 'ctrip',
      status: 'ready',
      generated_at: '2030-01-01T00:00:00+08:00',
      counts: {
        responses: 3,
        catalog_facts: 7,
        standard_rows: 2,
      },
      available_groups: ['revenue'],
      missing_groups: [],
      input_kind: 'example_snapshot_not_target_date_traffic',
    },
  },
];

for (const placeholder of placeholderEvidenceCases) {
  const verifierResult = runP0VerifierWithTrafficEvidence(placeholder.evidence, placeholder.platform, systemHotelId);
  const external = verifierResult.external_traffic_evidence || {};
  const issueCodes = (external.issues || []).map((issue) => issue.code);
  const platformStatuses = Object.values(external.platforms || {}).map((platform) => platform.status);

  check('external_evidence_placeholder_contract', `${placeholder.name} rejected as external evidence`, external.status === 'invalid', JSON.stringify(external));
  check('external_evidence_placeholder_contract', `${placeholder.name} reports missing traffic_evidence rows`, issueCodes.includes('traffic_evidence_rows_missing'), issueCodes.join(', '));
  check('external_evidence_placeholder_contract', `${placeholder.name} does not create valid platform evidence`, !platformStatuses.includes('valid'), JSON.stringify(external.platforms || {}));
}

const browserProjectionCase = cases.find((item) => item.name === 'browser_capture_raw_metadata_projection_ready');
if (browserProjectionCase) {
  const importerResult = runImporterCase(browserProjectionCase);
  const importerMarkdown = runImporterMarkdownCase(browserProjectionCase);
  const verifierResult = runP0VerifierWithTrafficEvidence(importerResult, browserProjectionCase.platform);
  const scopedVerifierResult = runP0VerifierWithTrafficEvidence(importerResult, browserProjectionCase.platform, systemHotelId);
  const externalPlatform = verifierResult.external_traffic_evidence?.platforms?.[browserProjectionCase.platform] || {};
  const scopedExternalPlatform = scopedVerifierResult.external_traffic_evidence?.platforms?.[browserProjectionCase.platform] || {};
  check('browser_projection_external_evidence_contract', 'P0 verifier keeps DB closure incomplete with projected browser evidence only', verifierResult.status === 'incomplete', JSON.stringify(verifierResult.summary || {}));
  check('browser_projection_external_evidence_contract', 'P0 verifier accepts projected browser importer evidence as valid external evidence', externalPlatform.status === 'valid', JSON.stringify(externalPlatform));
  check('browser_projection_external_evidence_contract', 'P0 verifier accepts matching scoped projected browser evidence even when DB source scope is incomplete', scopedExternalPlatform.status === 'valid', JSON.stringify(scopedExternalPlatform));
  check('browser_projection_external_evidence_contract', 'projected browser importer evidence includes platform hotel identity proof', Number(externalPlatform.platform_hotel_identifier_rows || 0) > 0 && (externalPlatform.platform_hotel_identifier_sources || []).includes('hotel_id_family'), JSON.stringify(externalPlatform));
  check('browser_projection_external_evidence_contract', 'projected browser importer evidence keeps UI status ready', Number(externalPlatform.ui_status_ready_rows || 0) > 0 && (externalPlatform.ui_statuses || []).includes('ready'), JSON.stringify(externalPlatform));
  check('browser_projection_external_evidence_contract', 'projected browser importer evidence does not expose raw URLs', !JSON.stringify(importerResult.traffic_evidence || []).includes('https://'), JSON.stringify(importerResult.traffic_evidence || []));
  check('browser_projection_external_evidence_contract', 'projected browser importer evidence is not marked sensitive by P0 verifier', externalPlatform.sensitive_values_exposed === false, JSON.stringify(externalPlatform));
  check('browser_projection_external_evidence_contract', 'importer markdown keeps traffic evidence non-completion policy visible', importerMarkdown.includes('traffic evidence contract') && importerMarkdown.includes('traffic evidence completion policy') && importerMarkdown.includes('P0 closure still requires --execute import plus verify:p0-ota-field-loop target-date traffic rows'), importerMarkdown);
  check('browser_projection_external_evidence_contract', 'importer markdown keeps final completion policy and verifier command visible', importerMarkdown.includes('completion policy') && importerMarkdown.includes('next verifier command') && importerMarkdown.includes('Import is only accepted as P0 closure after verify:p0-ota-field-loop proves target-date traffic rows and traffic field facts.'), importerMarkdown);
}

const importerSource = readFileSync(importer, 'utf8');
const p0VerifierSource = readFileSync(p0Verifier, 'utf8');
const persistenceSource = readFileSync(path.join(root, 'app', 'service', 'OnlineDailyDataPersistenceService.php'), 'utf8');
check('execute_contract', 'execute uses prepared payload', importerSource.includes('p0_import_execute((array)$preparedExecute[\'payload\']'));
check('execute_contract', 'execute does not use raw payload', !importerSource.includes('p0_import_execute($payload'));
check('execute_contract', 'data.flowData execute payload shape is explicit', importerSource.includes("'payload_shape' => 'data.flowData'"));
check('execute_contract', 'validated_target_date_rows execute input source is explicit', importerSource.includes('validated_target_date_rows'));
check('execute_contract', 'execute performs DB readback verification', importerSource.includes('p0_import_post_execute_verification') && importerSource.includes("'post_execute_verification' => p0_import_post_execute_verification($options)"));
check('execute_contract', 'imported status requires post execute ready', importerSource.includes("($postExecuteVerification['status'] ?? '') === 'ready' ? 'imported' : 'blocked'"));
check('execute_contract', 'post execute incomplete stays explicit', importerSource.includes('post_execute_verification_incomplete'));
check('execute_contract', 'post execute readback tracks incomplete UI rows', importerSource.includes("'ui_status_incomplete_rows' => 0") && importerSource.includes("'sample_ui_statuses' => []"));
check('execute_contract', 'post execute ready requires zero incomplete UI rows', importerSource.includes("&& (int)$base['ui_status_incomplete_rows'] === 0"));
check('execute_contract', 'post execute ready requires stored platform hotel identity proof', importerSource.includes("'platform_hotel_identifier_source' => $options['platform'] === 'meituan' ? 'poi_id_family' : 'hotel_id_family'") && importerSource.includes("'missing_platform_hotel_identifier_rows' => 0") && importerSource.includes("$base['status'] = 'platform_hotel_identifier_missing'"));
check('execute_contract', 'post execute readback exposes DB-derived traffic closure chain', importerSource.includes('p0_import_post_execute_closure_chain') && importerSource.includes("'traffic_closure_chain' => p0_import_post_execute_closure_chain") && importerSource.includes("'traffic_closure_chain_policy'") && importerSource.includes('Post-execute closure chain is DB readback evidence only'));
check('execute_contract', 'post execute closure chain tracks every P0 stage from stored field facts', importerSource.includes("'capture_evidence' => []") && importerSource.includes("'source_path' => []") && importerSource.includes("'metric_key' => []") && importerSource.includes("'storage_field' => []") && importerSource.includes("'stored_value' => []") && importerSource.includes("$stageMetricKeys['capture_evidence'][$metricKey] = true") && importerSource.includes("$stageMetricKeys['source_path'][$metricKey] = true") && importerSource.includes("$stageMetricKeys['storage_field'][$metricKey] = true") && importerSource.includes("$stageMetricKeys['stored_value'][$metricKey] = true"));
check('execute_contract', 'post execute closure chain keeps verifier tied to P0 field-loop gate', importerSource.includes('post_execute_verification.status=ready and verify:p0-ota-field-loop target-date traffic gate ready') && importerSource.includes("'status' => (string)($verification['status'] ?? '') === 'ready' ? 'ready' : 'incomplete'"));
check('execute_contract', 'post execute exposes UI status incomplete state', importerSource.includes("'ui_status_incomplete'"));
check('execute_contract', 'importer blocks raw URL keys before evidence or execute', importerSource.includes('p0_import_is_raw_url_key') && importerSource.includes("str_ends_with($normalized, '_url')") && importerSource.includes("$normalized === 'endpoint'"));
check('execute_contract', 'importer blocks raw URL string values before evidence or execute', importerSource.includes('p0_import_is_raw_url_value') && importerSource.includes("preg_match('#https?://#i'"));
check('execute_contract', 'importer normalizes camelCase browser metadata keys before projection or sensitive scan', importerSource.includes('p0_import_is_sensitive_browser_metadata_key') && importerSource.includes('p0_import_normalize_sensitive_key_segment') && importerSource.includes('profile_(path|dir|directory)') && importerSource.includes('spider_token'));
check('execute_contract', 'importer projects browser capture payloads before sensitive scan or execute', importerSource.includes('p0_import_project_payload_for_import') && importerSource.includes('$payloadProjection = p0_import_project_payload_for_import($rawPayload)') && importerSource.includes("'payload_import_projection' => $payloadProjectionMetadata"));
check('execute_contract', 'browser capture import projection keeps only importable evidence keys', importerSource.includes('browser_capture_import_projection') && importerSource.includes("'standard_rows'") && importerSource.includes("'responses'") && importerSource.includes("'traffic'"));
check('execute_contract', 'browser capture import projection exposes removed metadata without raw values', importerSource.includes('removed_sensitive_metadata_paths') && importerSource.includes('dropped_top_level_keys') && importerSource.includes('removed_sensitive_metadata_count'));
check('execute_contract', 'importer markdown exposes browser capture projection status', importerSource.includes('payload import projection') && importerSource.includes('projection removed sensitive metadata') && importerSource.includes('projection dropped top-level metadata') && importerSource.includes('sensitive values exposed'));
check('execute_contract', 'importer markdown exposes traffic evidence non-completion policy', importerSource.includes('traffic evidence contract') && importerSource.includes('traffic evidence verifier command') && importerSource.includes('traffic evidence completion policy') && importerSource.includes('next verifier command'));
check('execute_contract', 'importer blocks rows without explicit source dates', importerSource.includes('p0_import_explicit_row_date') && importerSource.includes('p0_import_row_date_source_is_context_default') && importerSource.includes('target_date_explicit_row_date_missing') && importerSource.includes('command --date cannot be used as row-date evidence'));
check('execute_contract', 'importer requires browser capture date source evidence', importerSource.includes('browser_capture_row_date_source_missing') && importerSource.includes('missing_browser_date_source_evidence_rows') && importerSource.includes('capture_context.default_data_date is not accepted'));
check('execute_contract', 'importer only accepts traffic browser responses with explicit row counts as row evidence', importerSource.includes('p0_import_browser_response_is_traffic_evidence') && importerSource.includes('p0_import_browser_response_row_count') && importerSource.includes('standard_row_count') && importerSource.includes("['traffic', 'flow', 'conversion']") && importerSource.includes("'row_count'") && importerSource.includes("'section'"));
check('execute_contract', 'importer consumes browser response row counts once per matched traffic row', importerSource.includes('remaining_row_count') && importerSource.includes('$responseEvidence[\'responses\'][$index][\'remaining_row_count\'] = $remaining - 1'));
check('execute_contract', 'importer blocks weak source paths', importerSource.includes('p0_import_explicit_source_path') && importerSource.includes('target_date_source_path_missing') && importerSource.includes('field names alone are not accepted as source-path evidence'));
check('execute_contract', 'importer blocks rows without platform hotel identity', importerSource.includes('p0_import_platform_hotel_identifier') && importerSource.includes('target_date_platform_hotel_identifier_missing') && importerSource.includes('missing_platform_hotel_identifier_rows') && importerSource.includes('system_hotel_id is only the local scope'));
check('execute_contract', 'importer does not invent source paths for execute payload rows', !importerSource.includes("$row['_source_path'] = 'validated_target_date_rows.'"));
check('execute_contract', 'importer does not replace missing platform hotel id with system hotel id', !importerSource.includes("$row[$platform === 'meituan' ? 'poi_id' : 'hotel_id'] = (string)$systemHotelId") && !importerSource.includes("system_hotel_' . $systemHotelId"));
check('execute_contract', 'field fact preview uses platform hotel identity instead of system hotel id fallback', importerSource.includes('$platformHotelIdentifier = p0_import_platform_hotel_identifier($row, $platform);') && importerSource.includes("'hotel_id' => $platformHotelIdentifier") && !importerSource.includes("?? $systemHotelId)"));
check('execute_contract', 'importer blocks failed browser capture envelopes', importerSource.includes('p0_import_payload_scope_issues') && importerSource.includes('browser_capture_auth_not_verified') && importerSource.includes('browser_capture_gate_not_pass') && importerSource.includes('browser_capture_login_only_not_importable') && importerSource.includes('browser_capture_source_missing') && importerSource.includes('browser_capture_system_hotel_id_missing') && importerSource.includes('browser_capture_row_capture_evidence_missing') && importerSource.includes('browser_capture_row_date_source_missing') && importerSource.includes('browser_capture_response_evidence_missing') && importerSource.includes('system_hotel_id_mismatch'));
check('traffic_evidence_contract', 'importer emits traffic_evidence', importerSource.includes("'traffic_evidence' => $trafficEvidence"));
check('traffic_evidence_contract', 'importer emits dry-run P0 completion status distinct from ready_to_import', importerSource.includes("'p0_completion_status' => $p0CompletionStatus") && importerSource.includes('pre_import_ready_not_p0_complete') && importerSource.includes('P0 complete only when --execute saves target-date traffic rows'));
check('traffic_evidence_contract', 'importer top-level next verifier command stays platform and hotel scoped', importerSource.includes("'next_verifier_command' => 'npm.cmd run verify:p0-ota-field-loop -- --date=' . (string)$options['date'] . ' --platform=' . (string)$options['platform'] . ' --system-hotel-id=' . (int)$options['system-hotel-id']"));
check('traffic_evidence_contract', 'importer execute completion status remains below final P0 verifier', importerSource.includes('imported_post_execute_readback_ready_requires_p0_verifier') && !importerSource.includes('imported_and_post_execute_verifier_ready'));
check('traffic_evidence_contract', 'importer labels post execute readback as non-final P0 evidence', importerSource.includes("'post_execute_readback_policy'") && importerSource.includes('Importer post_execute_verification is DB readback evidence only') && importerSource.includes('a separate verify:p0-ota-field-loop run returns ready'));
check('traffic_evidence_contract', 'importer emits traffic evidence UI status with desensitized evidence and structured source path counts', importerSource.includes('$uiStatus = p0_import_external_ui_status') && importerSource.includes("'ui_status' => $uiStatus") && importerSource.includes('desensitized_capture_evidence_count') && importerSource.includes('structured_source_path_count'));
check('traffic_evidence_contract', 'importer emits desensitized platform hotel identity proof in traffic evidence', importerSource.includes('platform_hotel_identifier_present') && importerSource.includes('platform_hotel_identifier_source') && importerSource.includes('poi_id_family') && importerSource.includes('hotel_id_family'));
check('traffic_evidence_contract', 'importer emits pre-import traffic closure chain without completing P0', importerSource.includes('p0_import_traffic_closure_chain') && importerSource.includes("'traffic_closure_chain' => p0_import_traffic_closure_chain") && importerSource.includes("'traffic_closure_chain_policy'") && importerSource.includes('requires_execute_and_p0_verifier') && importerSource.includes('pre-import source proof only'));
check('traffic_evidence_contract', 'importer keeps external evidence non-completion policy', importerSource.includes('External traffic_evidence validates desensitized source proof only'));
check('traffic_evidence_contract', 'importer blocks cross-row metric coverage', importerSource.includes('traffic_field_fact_preview_rows_incomplete') && importerSource.includes('cross-row metric coverage is not accepted'));
check('traffic_evidence_contract', 'importer blocks traffic evidence and execute row count mismatches', importerSource.includes('traffic_evidence_execute_row_count_mismatch') && importerSource.includes('Traffic evidence rows, target-date rows, and execute payload rows must match before import.') && importerSource.includes('$trafficEvidenceRowCount') && importerSource.includes('$executeRowCount'));
check('traffic_evidence_contract', 'importer requires exact P0 traffic storage fields in preview', importerSource.includes('$requiredStorageFields = p0_import_required_traffic_storage_fields()') && importerSource.includes("trim((string)($fact['storage_field'] ?? '')) === $requiredStorageFields[$metricKey]"));
check('traffic_evidence_contract', 'importer requires metric-level trace and source hash before field facts are complete', importerSource.includes('p0_import_fact_has_desensitized_capture_evidence') && importerSource.includes('p0_import_fact_capture_evidence_matches_row') && importerSource.includes('$rowSourceTraceId') && importerSource.includes('$rowSourceUrlHash') && importerSource.includes("$desensitized['source_trace_id']") && importerSource.includes("$desensitized['source_url_hash']"));
check('traffic_evidence_contract', 'P0 verifier requires external evidence UI status', p0VerifierSource.includes('ui_status_missing') && p0VerifierSource.includes('ui_status_not_ready'));
check('traffic_evidence_contract', 'P0 verifier requires external traffic closure chain and pre-import policy', p0VerifierSource.includes('p0_validate_external_traffic_closure_chain') && p0VerifierSource.includes('traffic_closure_chain_missing') && p0VerifierSource.includes('traffic_closure_chain_stage_not_ready') && p0VerifierSource.includes('traffic_closure_chain_metric_key_required_incomplete') && p0VerifierSource.includes('traffic_closure_chain_storage_field_required_incomplete') && p0VerifierSource.includes('traffic_closure_chain_verifier_status_invalid') && p0VerifierSource.includes('traffic_closure_chain_verifier_required_incomplete') && p0VerifierSource.includes('traffic_closure_chain_policy_missing') && p0VerifierSource.includes('traffic_closure_chain_ready_rows'));
check('traffic_evidence_contract', 'P0 verifier counts only fully covered UI evidence rows as ready', p0VerifierSource.includes('$uiCountsReady') && p0VerifierSource.includes('$uiMissingCountsReady') && p0VerifierSource.includes("'ui_status_ready'"));
check('traffic_evidence_contract', 'P0 verifier requires metric-level capture evidence, structured source path, and stored value proof', p0VerifierSource.includes('p0_external_desensitized_capture_evidence') && p0VerifierSource.includes('p0_field_fact_capture_evidence_matches_row') && p0VerifierSource.includes("'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash']") && p0VerifierSource.includes('matched_capture_evidence_count') && p0VerifierSource.includes('desensitized_capture_evidence_count') && p0VerifierSource.includes('field_fact_capture_evidence_missing') && p0VerifierSource.includes('field_fact_source_trace_id_mismatch') && p0VerifierSource.includes('field_fact_source_url_hash_mismatch') && p0VerifierSource.includes('source_path_not_structured') && p0VerifierSource.includes('structured_source_path_count') && p0VerifierSource.includes('stored_value_present_not_true'));
check('traffic_evidence_contract', 'P0 verifier requires external platform hotel identity proof', p0VerifierSource.includes('platform_hotel_identifier_missing') && p0VerifierSource.includes('platform_hotel_identifier_source_invalid') && p0VerifierSource.includes('platform_hotel_identifier_rows') && p0VerifierSource.includes('External traffic evidence must prove the OTA platform hotel identifier is present without exposing the raw identifier.'));
check('traffic_evidence_contract', 'P0 verifier labels non-traffic source rows as reference only but keeps no-source rows distinct', p0VerifierSource.includes('source_chain_reference_only') && p0VerifierSource.includes('reference_only_non_traffic_source_rows') && p0VerifierSource.includes('no_target_date_source_rows') && p0VerifierSource.includes('Source field facts are non-traffic reference evidence') && p0VerifierSource.includes('No target-date source rows are loaded'));
check('traffic_evidence_contract', 'traffic persistence stores desensitized source trace id when the column exists', persistenceSource.includes('desensitizedSourceTraceId') && persistenceSource.includes("'source_trace_id'") && persistenceSource.includes('safeSourceTraceValue'));
check('traffic_evidence_contract', 'P0 verifier blocks raw URL keys in external evidence', p0VerifierSource.includes('p0_external_is_raw_url_key') && p0VerifierSource.includes("str_ends_with($normalized, '_url')") && p0VerifierSource.includes("$normalized === 'endpoint'"));
check('traffic_evidence_contract', 'P0 verifier blocks raw URL string values in external evidence', p0VerifierSource.includes('p0_external_is_raw_url_value') && p0VerifierSource.includes("preg_match('#https?://#i'"));
check('traffic_evidence_contract', 'P0 verifier normalizes camelCase sensitive metadata keys in external evidence', p0VerifierSource.includes('p0_external_is_sensitive_metadata_key') && p0VerifierSource.includes('p0_external_normalize_sensitive_key_segment') && p0VerifierSource.includes('profile_(path|dir|directory)') && p0VerifierSource.includes('spider_token'));
check('traffic_evidence_contract', 'P0 verifier requires stored traffic row UI status and structured source paths', p0VerifierSource.includes('p0_traffic_row_ui_status') && p0VerifierSource.includes('ui_status_incomplete_rows') && p0VerifierSource.includes('p0_source_path_is_structured') && p0VerifierSource.includes('source_path_structured'));
check('traffic_evidence_contract', 'P0 verifier requires stored platform hotel identity before ready traffic closure', p0VerifierSource.includes('p0_platform_hotel_identifier_present') && p0VerifierSource.includes('platform_hotel_identifier_status') && p0VerifierSource.includes("$base['status'] = 'platform_hotel_identifier_missing'") && p0VerifierSource.includes("&& (string)$base['platform_hotel_identifier_status'] === 'ready'"));
check('traffic_evidence_contract', 'P0 traffic gate exposes stored platform hotel identity status and counts', p0VerifierSource.includes("'platform_hotel_identifier_source' => $platformHotelIdentifierSource") && p0VerifierSource.includes("'platform_hotel_identifier_status' => $platformHotelIdentifierStatus") && p0VerifierSource.includes("'platform_hotel_identifier_rows' => $platformHotelIdentifierRows") && p0VerifierSource.includes("'missing_platform_hotel_identifier_rows' => $missingPlatformHotelIdentifierRows"));
check('traffic_evidence_contract', 'P0 traffic gate exposes stage-by-stage closure chain', p0VerifierSource.includes("'traffic_closure_chain' => [") && p0VerifierSource.includes("'capture_evidence' => [") && p0VerifierSource.includes("'source_path' => [") && p0VerifierSource.includes("'metric_key' => [") && p0VerifierSource.includes("'storage_field' => [") && p0VerifierSource.includes("'stored_value' => [") && p0VerifierSource.includes("'ui_status' => [") && p0VerifierSource.includes("'platform_hotel_identifier' => [") && p0VerifierSource.includes("'verifier' => [") && p0VerifierSource.includes('OTA-channel evidence only'));
check('traffic_evidence_contract', 'P0 verifier exposes a per-metric field loop matrix', p0VerifierSource.includes('field_loop_matrix') && p0VerifierSource.includes('p0_traffic_field_loop_matrix_index') && p0VerifierSource.includes('p0_mark_traffic_field_loop_metric') && p0VerifierSource.includes('expected_storage_field') && p0VerifierSource.includes('capture_evidence_matches_row') && p0VerifierSource.includes('ui_status_ready'));
check('traffic_evidence_contract', 'P0 verifier supports system hotel scoped closure', p0VerifierSource.includes('system-hotel-id') && p0VerifierSource.includes('system_hotel_id_mismatch'));
check('traffic_evidence_contract', 'P0 verifier exposes hotel scoped traffic sources and commands', p0VerifierSource.includes('hotel_scoped_sources') && p0VerifierSource.includes('hotel_scoped_commands') && p0VerifierSource.includes('payload_import_execute_command') && p0VerifierSource.includes('--system-hotel-id=') && p0VerifierSource.includes('Hotel Scoped Traffic Sources') && p0VerifierSource.includes('Hotel Scoped Traffic Commands'));
check('traffic_evidence_contract', 'P0 verifier exposes stable P0 next-action metadata for UI and employee summaries', p0VerifierSource.includes('p0_next_action_mode') && p0VerifierSource.includes('p0_next_action_entry') && p0VerifierSource.includes('p0_next_step_count') && p0VerifierSource.includes('metadata_only_no_sensitive_commands') && p0VerifierSource.includes('next_command_policy_detail'));
check('traffic_evidence_contract', 'P0 verifier exposes pre-import evidence status without marking closure complete', p0VerifierSource.includes('pre_import_evidence_status') && p0VerifierSource.includes('valid_external_evidence_not_ingested') && p0VerifierSource.includes('pre_import_evidence_policy') && p0VerifierSource.includes('pre-import evidence policy') && p0VerifierSource.includes('pre-import evidence') && p0VerifierSource.includes('not P0 complete until target-date traffic rows are ingested'));
check('traffic_evidence_contract', 'P0 verifier exposes hotel scoped payload contracts without importable fake data', p0VerifierSource.includes('hotel_scoped_payload_contracts') && p0VerifierSource.includes('p0_hotel_scoped_traffic_payload_contract') && p0VerifierSource.includes('contract_only_not_importable') && p0VerifierSource.includes('requires_real_ota_payload') && p0VerifierSource.includes('Hotel Scoped Payload Contracts') && p0VerifierSource.includes('dry_run_acceptance') && p0VerifierSource.includes('request.query.*') && p0VerifierSource.includes('command/default dates are not accepted') && p0VerifierSource.includes('target_date_platform_hotel_identifier_missing') && p0VerifierSource.includes('summary.missing_platform_hotel_identifier_rows'));
check('traffic_evidence_contract', 'P0 verifier exposes hotel scoped capture bridges without sensitive profile details', p0VerifierSource.includes('hotel_scoped_capture_bridges') && p0VerifierSource.includes('p0_hotel_scoped_capture_bridge_contract') && p0VerifierSource.includes('Hotel Scoped Capture Bridges') && p0VerifierSource.includes('browser_login_prepare_command') && p0VerifierSource.includes('browser_capture_command') && p0VerifierSource.includes('bridge_to_importer_command') && p0VerifierSource.includes('bridge_importer_acceptance') && p0VerifierSource.includes('importer acceptance') && p0VerifierSource.includes('payload_import_projection.applied') && p0VerifierSource.includes('summary.browser_response_evidence_rows') && p0VerifierSource.includes('summary.missing_platform_hotel_identifier_rows') && p0VerifierSource.includes('traffic_evidence[].platform_hotel_identifier_present') && p0VerifierSource.includes('traffic_evidence[].platform_hotel_identifier_source') && p0VerifierSource.includes('required_capture_scope_proof') && p0VerifierSource.includes('authorized_profile_matches_selected_hotel') && p0VerifierSource.includes('raw_profile_path_in_report') && p0VerifierSource.includes('--headless=false') && p0VerifierSource.includes('--data-date=') && p0VerifierSource.includes('--format=json') && p0VerifierSource.includes('traffic[].hotelId|hotel_id') && p0VerifierSource.includes('traffic[].poiId|poi_id') && p0VerifierSource.includes('traffic[].date_source') && p0VerifierSource.includes('traffic[].capture_evidence.source_trace_id') && p0VerifierSource.includes('standard_rows[].hotelId|hotel_id') && p0VerifierSource.includes('standard_rows[].date_source') && p0VerifierSource.includes('standard_rows[].capture_evidence.source_trace_id') && p0VerifierSource.includes('responses[].section') && p0VerifierSource.includes('responses[].row_count') && p0VerifierSource.includes('responses[].source_trace_id') && p0VerifierSource.includes('responses[].request_date_source'));
check('traffic_evidence_contract', 'P0 verifier does not treat profile directory presence as verified login', p0VerifierSource.includes('traffic_profile_login_verified_count') && p0VerifierSource.includes('manual_login_state_verified') && p0VerifierSource.includes('login state has been manually verified'));

const noSourceRowsVerifierResult = runP0VerifierSnapshot('ctrip', '2099-01-01');
const noSourceRowsPlatform = (noSourceRowsVerifierResult.platforms || []).find((platform) => platform.platform === 'ctrip') || {};
check('source_chain_scope_contract', 'P0 verifier does not label missing source rows as reference evidence', Number(noSourceRowsPlatform.target_date_rows || 0) === 0 && noSourceRowsPlatform.source_chain_reference_only === false && noSourceRowsPlatform.source_chain_scope === 'no_target_date_source_rows', JSON.stringify(noSourceRowsPlatform));
const noSourceRowsTraffic = (noSourceRowsVerifierResult.traffic_evidence_availability || []).find((traffic) => traffic.platform === 'ctrip') || {};
const noSourceRowsMatrix = noSourceRowsTraffic.traffic_field_fact_closure?.field_loop_matrix || [];
const requiredP0TrafficMetricKeys = ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'];
check('source_chain_scope_contract', 'P0 verifier expands no-source traffic closure into every required metric', JSON.stringify(noSourceRowsMatrix.map((row) => String(row.metric_key || '')).sort()) === JSON.stringify([...requiredP0TrafficMetricKeys].sort()), JSON.stringify(noSourceRowsMatrix));
check('source_chain_scope_contract', 'P0 verifier keeps every no-source metric explicitly unloaded', noSourceRowsMatrix.length === 5 && noSourceRowsMatrix.every((row) => row.status === 'no_target_date_traffic_rows' && Number(row.row_count || 0) === 0 && row.capture_evidence_present === false && row.source_path_structured === false && row.storage_field_matches_expected === false && row.stored_value_present === false && row.ui_status_ready === false), JSON.stringify(noSourceRowsMatrix));

const runtimeExecuteCases = [
  {
    name: 'runtime_execute_ctrip_target_date_traffic_ready',
    platform: 'ctrip',
    payload: {
      traffic: [
        trafficRow({
          hotelId: 'runtime-ctrip-platform-hotel',
          date: runtimeExecuteDate,
          dateSource: 'request.payload.dataDate',
          trace: 'ctrip:runtime-execute-traffic',
          hash: hash('r'),
          sourcePath: 'traffic.0',
        }),
      ],
    },
  },
  {
    name: 'runtime_execute_meituan_target_date_traffic_ready',
    platform: 'meituan',
    payload: {
      traffic: [
        trafficRow({
          poiId: 'runtime-meituan-platform-poi',
          dataDate: runtimeExecuteDate,
          dateSource: 'request.query.date',
          trace: 'meituan:runtime-execute-traffic',
          hash: hash('s'),
          sourcePath: 'traffic.0',
        }),
      ],
    },
  },
];

for (const executeCase of runtimeExecuteCases) {
  cleanupP0RuntimeRows(executeCase.platform, runtimeExecuteDate, runtimeExecuteSystemHotelId);
  try {
    const importerResult = runImporterExecuteCase(executeCase, runtimeExecuteDate, runtimeExecuteSystemHotelId);
    const postExecute = importerResult.post_execute_verification || {};
    check('runtime_execute_contract', `${executeCase.name} importer execute exits cleanly`, importerResult.exitCode === 0, JSON.stringify(importerResult.issues || []));
    check('runtime_execute_contract', `${executeCase.name} importer saves target-date traffic row`, importerResult.status === 'imported' && Number(importerResult.saved_count || 0) > 0, JSON.stringify(importerResult));
    check('runtime_execute_contract', `${executeCase.name} importer post-execute DB readback is ready`, postExecute.status === 'ready' && Number(postExecute.traffic_row_count || 0) > 0, JSON.stringify(postExecute));
    check('runtime_execute_contract', `${executeCase.name} importer post-execute keeps DB readback below final P0 completion`, importerResult.p0_completion_status === 'imported_post_execute_readback_ready_requires_p0_verifier' && String(importerResult.post_execute_readback_policy || '').includes('final P0 closure still requires'), JSON.stringify(importerResult));

    const verifierResult = runP0VerifierSnapshot(executeCase.platform, runtimeExecuteDate, runtimeExecuteSystemHotelId);
    const platformResult = (verifierResult.platforms || []).find((platform) => platform.platform === executeCase.platform) || {};
    const trafficGate = platformResult.p0_traffic_gate || {};
    const trafficAvailability = (verifierResult.traffic_evidence_availability || []).find((traffic) => traffic.platform === executeCase.platform) || {};
    const trafficClosure = trafficAvailability.traffic_field_fact_closure || {};
    check('runtime_execute_contract', `${executeCase.name} P0 verifier marks the ingested target-date traffic gate ready`, trafficGate.status === 'ready' && Number(verifierResult.summary?.traffic_gates_ready || 0) === 1, JSON.stringify({ status: verifierResult.status, summary: verifierResult.summary || {}, trafficGate, issues: verifierResult.issues || [] }));
    check('runtime_execute_contract', `${executeCase.name} P0 verifier counts scoped traffic rows`, Number(trafficGate.traffic_rows || 0) > 0 && Number(trafficAvailability.target_date?.traffic_rows || 0) > 0, JSON.stringify({ trafficGate, trafficAvailability }));
    check('runtime_execute_contract', `${executeCase.name} P0 verifier closes every required metric key`, trafficClosure.status === 'ready' && requiredP0TrafficMetricKeys.every((key) => (trafficClosure.complete_metric_keys || []).includes(key)), JSON.stringify(trafficClosure));
    check('runtime_execute_contract', `${executeCase.name} P0 verifier keeps platform hotel identity ready`, trafficGate.platform_hotel_identifier_status === 'ready' && Number(trafficGate.platform_hotel_identifier_rows || 0) > 0, JSON.stringify(trafficGate));
  } finally {
    cleanupP0RuntimeRows(executeCase.platform, runtimeExecuteDate, runtimeExecuteSystemHotelId);
  }

  const cleanupVerifierResult = runP0VerifierSnapshot(executeCase.platform, runtimeExecuteDate, runtimeExecuteSystemHotelId);
  const cleanupPlatformResult = (cleanupVerifierResult.platforms || []).find((platform) => platform.platform === executeCase.platform) || {};
  const cleanupTrafficGate = cleanupPlatformResult.p0_traffic_gate || {};
  const cleanupTrafficAvailability = (cleanupVerifierResult.traffic_evidence_availability || []).find((traffic) => traffic.platform === executeCase.platform) || {};
  check('runtime_execute_cleanup_contract', `${executeCase.name} cleanup removes synthetic target-date traffic rows`, cleanupTrafficGate.status === 'missing_target_date_traffic_rows' && Number(cleanupTrafficGate.traffic_rows || 0) === 0 && Number(cleanupTrafficAvailability.target_date?.traffic_rows || 0) === 0, JSON.stringify({
    status: cleanupVerifierResult.status,
    summary: cleanupVerifierResult.summary || {},
    trafficGate: cleanupTrafficGate,
    trafficAvailability: cleanupTrafficAvailability,
  }));
}

const failed = checks.filter((item) => !item.ok);
if (failed.length > 0) {
  console.error('P0 OTA traffic payload importer verification failed:');
  for (const failure of failed) {
    console.error(`- ${failure.caseName}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`[verify:p0-ota-traffic-importer] ${checks.length} checks passed`);

function trafficRow({ hotelId = '', poiId = '', storeId = '', date: rowDate = '', dataDate = '', dateSource = '', trace, hash: sourceHash, sourcePath = 'data.flowData.0' }) {
  return {
    ...(hotelId ? { hotelId } : {}),
    ...(poiId ? { poiId } : {}),
    ...(storeId ? { storeId } : {}),
    ...(rowDate ? { date: rowDate } : {}),
    ...(dataDate ? { dataDate } : {}),
    ...(dateSource ? { date_source: dateSource } : {}),
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

function runImporterMarkdownCase(item) {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-ota-importer-markdown-'));
  const payloadPath = path.join(dir, `${item.name}.json`);
  try {
    writeFileSync(payloadPath, JSON.stringify(item.payload, null, 2), 'utf8');
    const child = spawnSync(phpBinary, [
      importer,
      `--platform=${item.platform}`,
      `--date=${date}`,
      `--system-hotel-id=${systemHotelId}`,
      `--payload=${payloadPath}`,
      '--format=markdown',
    ], {
      cwd: root,
      encoding: 'utf8',
    });
    const stdout = String(child.stdout || '').trim();
    if (Number(child.status ?? 0) !== Number(item.expect.exitCode ?? 0)) {
      throw new Error(`${item.name} markdown exit code ${child.status}; stdout=${stdout}; stderr=${child.stderr || ''}`);
    }
    return stdout;
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function runImporterExecuteCase(item, executeDate, scopedSystemHotelId) {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-ota-importer-execute-'));
  const payloadPath = path.join(dir, `${item.name}.json`);
  try {
    writeFileSync(payloadPath, JSON.stringify(item.payload, null, 2), 'utf8');
    const child = spawnSync(phpBinary, [
      importer,
      `--platform=${item.platform}`,
      `--date=${executeDate}`,
      `--system-hotel-id=${scopedSystemHotelId}`,
      `--payload=${payloadPath}`,
      '--execute',
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
      throw new Error(`${item.name} execute returned invalid JSON: ${error.message}; stdout=${stdout}; stderr=${child.stderr || ''}`);
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

function runP0VerifierSnapshot(platform, verifierDate = date, scopedSystemHotelId = '') {
  const args = [
    p0Verifier,
    `--platform=${platform}`,
    `--date=${verifierDate}`,
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
    throw new Error(`P0 verifier snapshot returned invalid JSON: ${error.message}; stdout=${stdout}; stderr=${child.stderr || ''}`);
  }
}

function cleanupP0RuntimeRows(platform, cleanupDate, scopedSystemHotelId) {
  const source = JSON.stringify(platform);
  const dataDate = JSON.stringify(cleanupDate);
  const systemId = Number(scopedSystemHotelId);
  const code = `
    $root = getcwd();
    require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $app = new \\think\\App();
    $app->initialize();
    $columnRows = \\think\\facade\\Db::query('SHOW COLUMNS FROM online_daily_data');
    $columns = array_fill_keys(array_column($columnRows, 'Field'), true);
    $query = \\think\\facade\\Db::name('online_daily_data')
        ->where('source', ${source})
        ->where('data_date', ${dataDate})
        ->whereIn('data_type', ['traffic', 'flow', 'conversion']);
    if (isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', ${systemId});
    }
    $deleted = $query->delete();
    echo json_encode(['deleted' => $deleted], JSON_UNESCAPED_UNICODE);
  `;
  const child = spawnSync(phpBinary, ['-r', code], {
    cwd: root,
    encoding: 'utf8',
  });
  if (Number(child.status ?? 0) !== 0) {
    throw new Error(`cleanup failed for ${platform} ${cleanupDate}: stdout=${child.stdout || ''}; stderr=${child.stderr || ''}`);
  }
}

function check(caseName, label, ok, detail = '') {
  checks.push({ caseName, label, ok: Boolean(ok), detail });
}
