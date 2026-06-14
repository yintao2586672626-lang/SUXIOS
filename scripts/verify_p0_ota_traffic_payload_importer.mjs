import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const importer = path.join(root, 'scripts', 'import_p0_ota_traffic_payload.php');
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
    name: 'missing_desensitized_evidence_blocked',
    platform: 'meituan',
    payload: {
      data: {
        flowData: [
          {
            poiId: 'demo',
            date,
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
];

for (const item of cases) {
  const result = runImporterCase(item);
  const issueCodes = new Set((result.issues || []).map((issue) => issue.code));
  const summary = result.summary || {};
  check(item.name, `${item.name} status`, result.status === item.expect.status, `expected ${item.expect.status}, got ${result.status}`);
  check(item.name, `${item.name} exit code`, result.exitCode === item.expect.exitCode, `expected ${item.expect.exitCode}, got ${result.exitCode}`);
  check(item.name, `${item.name} target rows`, Number(summary.target_date_rows || 0) === item.expect.targetRows, JSON.stringify(summary));
  check(item.name, `${item.name} evidence rows`, Number(summary.rows_with_desensitized_capture_evidence || 0) === item.expect.evidenceRows, JSON.stringify(summary));
  for (const code of item.expect.issuesPresent || []) {
    check(item.name, `${item.name} issue ${code} present`, issueCodes.has(code), [...issueCodes].join(', '));
  }
  for (const code of item.expect.issuesAbsent || []) {
    check(item.name, `${item.name} issue ${code} absent`, !issueCodes.has(code), [...issueCodes].join(', '));
  }
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

function trafficRow({ hotelId = '', poiId = '', date: rowDate = '', dataDate = '', trace, hash: sourceHash }) {
  return {
    ...(hotelId ? { hotelId } : {}),
    ...(poiId ? { poiId } : {}),
    ...(rowDate ? { date: rowDate } : {}),
    ...(dataDate ? { dataDate } : {}),
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

function check(caseName, label, ok, detail = '') {
  checks.push({ caseName, label, ok: Boolean(ok), detail });
}
