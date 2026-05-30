import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

const repoRoot = process.cwd();
const nodeBin = process.execPath;
const scriptPath = join(repoRoot, 'scripts', 'verify_ota_data_metrics.mjs');
const reportsDir = join(repoRoot, 'reports');
const markdownReport = join(reportsDir, 'ota_data_validation.md');
const jsonReport = join(reportsDir, 'ota_data_validation.json');
const fixtureDir = join(tmpdir(), 'suxi-ota-data-metrics-contract');

function assertContract(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function runCli(args) {
  return spawnSync(nodeBin, [scriptPath, ...args], {
    cwd: repoRoot,
    encoding: 'utf8',
  });
}

mkdirSync(fixtureDir, { recursive: true });
mkdirSync(reportsDir, { recursive: true });
rmSync(markdownReport, { force: true });
rmSync(jsonReport, { force: true });

const rowsPath = join(fixtureDir, 'ota_rows.json');
writeFileSync(rowsPath, JSON.stringify([
  {
    source: 'ctrip',
    hotel_id: '1001',
    hotel_name: 'Valid Hotel',
    data_date: '2026-05-17',
    amount: 1000,
    quantity: 5,
    adr: 200,
    comp_set_adr: 250,
    ari: 80,
    conversion_rate: 12,
    comp_set_conversion_rate: 10,
    sci: 120,
    occupancy_rate: 75,
    comp_set_occupancy_rate: 80,
    mpi: 93.75,
    raw_data: {
      hotelId: '1001',
      hotelName: 'Valid Hotel',
      dataDate: '2026-05-17',
      amount: 1000,
      quantity: 5,
      bookOrderNum: 3,
    },
  },
  {
    source: 'ctrip',
    hotel_id: '1002',
    hotel_name: 'Invalid Hotel',
    data_date: '2026-05-17',
    amount: 900,
    quantity: 3,
    adr: 200,
    hotel_adr: 300,
    comp_set_adr: 200,
    ari: 90,
    conversion_rate: 8,
    comp_set_conversion_rate: 10,
    sci: 120,
    occupancy_rate: 90,
    comp_set_occupancy_rate: 75,
    mpi: 90,
    raw_data: {
      hotelId: '1002',
      hotelName: 'Invalid Hotel',
      amount: 900,
      quantity: 3,
      bookOrderNum: 2,
    },
  },
], null, 2));

const badRun = runCli([`--input=${rowsPath}`, '--json']);
assertContract(badRun.status === 1, `invalid input must exit 1, got ${badRun.status}\n${badRun.stdout}\n${badRun.stderr}`);
assertContract(existsSync(markdownReport), 'Markdown report must be saved to reports/ota_data_validation.md');
assertContract(existsSync(jsonReport), 'JSON report must be saved to reports/ota_data_validation.json');

const stdoutJson = JSON.parse(badRun.stdout);
const savedJson = JSON.parse(readFileSync(jsonReport, 'utf8'));
const savedMarkdown = readFileSync(markdownReport, 'utf8');

assertContract(stdoutJson.summary.checked_rows === 2, 'JSON stdout must include checked row count');
assertContract(savedJson.summary.passed_records === 1, 'saved JSON must count passed records');
assertContract(savedJson.summary.failed_records === 1, 'saved JSON must count failed records');
assertContract(Array.isArray(savedJson.record_results) && savedJson.record_results.length === 2, 'saved JSON must include per-record results');
assertContract(savedJson.record_results[0].status === 'pass', 'first record must pass');
assertContract(savedJson.record_results[1].status === 'fail', 'second record must fail');
assertContract(savedJson.record_results[1].errors.some((issue) => issue.metric === 'ADR'), 'second record must include ADR error');
assertContract(savedMarkdown.includes('校验通过') && savedMarkdown.includes('异常记录明细'), 'Markdown report must include Chinese summary sections');

const meituanPayloadPath = join(fixtureDir, 'meituan_payload.json');
writeFileSync(meituanPayloadPath, JSON.stringify({
  data: {
    peerRankData: [
      {
        dimName: '入住间夜榜',
        aiMetricName: 'P_RZ_NIGHT_COUNT',
        roundRanks: [
          {
            poiId: '68471',
            poiName: 'Valid Meituan Hotel',
            dataValue: 80,
            rankType: 'P_RZ',
            date: '2026-05-17',
          },
        ],
      },
    ],
  },
}, null, 2));

const payloadRun = runCli([`--source-payload=${meituanPayloadPath}`, '--source=meituan_business', '--json']);
assertContract(payloadRun.status === 0, `meituan_business payload alias must pass, got ${payloadRun.status}\n${payloadRun.stdout}\n${payloadRun.stderr}`);
const payloadJson = JSON.parse(payloadRun.stdout);
assertContract(payloadJson.details.payload_mapping.source === 'meituan_rank', 'meituan_business must normalize to meituan_rank');
assertContract(payloadJson.details.payload_mapping.rows === 1, 'Meituan payload row count must be 1');

const selfTestRun = runCli(['--self-test']);
assertContract(selfTestRun.status === 0, `self-test must pass, got ${selfTestRun.status}\n${selfTestRun.stdout}\n${selfTestRun.stderr}`);

console.log('OTA data metrics CLI contract verification passed.');
