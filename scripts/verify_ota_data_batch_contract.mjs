import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

const repoRoot = process.cwd();
const nodeBin = process.execPath;
const scriptPath = join(repoRoot, 'scripts', 'verify_ota_data_batch.mjs');
const reportsDir = join(repoRoot, 'reports');
const markdownReport = join(reportsDir, 'ota_data_batch_validation.md');
const jsonReport = join(reportsDir, 'ota_data_batch_validation.json');
const fixtureDir = join(tmpdir(), 'suxi-ota-data-batch-contract');

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

rmSync(fixtureDir, { recursive: true, force: true });
mkdirSync(join(fixtureDir, 'hotel-a'), { recursive: true });
mkdirSync(join(fixtureDir, 'hotel-b'), { recursive: true });
mkdirSync(reportsDir, { recursive: true });
rmSync(markdownReport, { force: true });
rmSync(jsonReport, { force: true });

writeFileSync(join(fixtureDir, 'hotel-a', 'ota_rows.json'), JSON.stringify([
  {
    source: 'ctrip',
    hotel_id: '1001',
    hotel_name: 'Valid Hotel',
    data_date: '2026-05-17',
    amount: 1000,
    quantity: 5,
    adr: 200,
    raw_data: {
      hotelId: '1001',
      hotelName: 'Valid Hotel',
      dataDate: '2026-05-17',
      amount: 1000,
      quantity: 5,
      bookOrderNum: 3,
    },
  },
], null, 2));

writeFileSync(join(fixtureDir, 'hotel-b', 'rows.json'), JSON.stringify([
  {
    source: 'ctrip',
    hotel_id: '1002',
    hotel_name: 'Invalid Hotel',
    data_date: '2026-05-17',
    amount: 900,
    quantity: 3,
    adr: 200,
    raw_data: {
      hotelId: '1002',
      hotelName: 'Invalid Hotel',
      amount: 900,
      quantity: 3,
    },
  },
], null, 2));

const badRun = runCli([`--input-dir=${fixtureDir}`, '--json']);
assertContract(badRun.status === 1, `invalid batch must exit 1, got ${badRun.status}\n${badRun.stdout}\n${badRun.stderr}`);
assertContract(existsSync(markdownReport), 'Markdown batch report must be saved');
assertContract(existsSync(jsonReport), 'JSON batch report must be saved');

const stdoutJson = JSON.parse(badRun.stdout);
const savedJson = JSON.parse(readFileSync(jsonReport, 'utf8'));
const savedMarkdown = readFileSync(markdownReport, 'utf8');

assertContract(stdoutJson.summary.file_count === 2, 'JSON stdout must include file count');
assertContract(savedJson.summary.failed_file_count === 1, 'saved JSON must count failed files');
assertContract(savedJson.summary.abnormal_record_count === 1, 'saved JSON must count abnormal records');
assertContract(savedJson.results.some((item) => item.status === 'fail'), 'batch results must include failed file');
assertContract(savedJson.results.some((item) => item.record_results?.some((record) => record.abnormal === true)), 'record results must include abnormal marker');
assertContract(savedMarkdown.includes('OTA 批量数据校验') && savedMarkdown.includes('异常记录'), 'Markdown report must include batch summary and abnormal section');

console.log('OTA data batch CLI contract verification passed.');
