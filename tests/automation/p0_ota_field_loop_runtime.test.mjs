import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const php = 'C:\\xampp\\php\\php.exe';

function runVerifier(args) {
  const result = spawnSync(php, [
    'scripts/verify_p0_ota_field_loop_closure.php',
    ...args,
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  const output = `${result.stdout || ''}${result.stderr || ''}`;
  const start = output.indexOf('{');
  const end = output.lastIndexOf('}');
  assert(start >= 0 && end > start, `Expected JSON verifier output, got:\n${output.slice(0, 1000)}`);

  return {
    exitCode: result.status,
    output,
    payload: JSON.parse(output.slice(start, end + 1)),
  };
}

test('Ctrip-only P0 field-loop verifier passes when OTA field evidence and traffic gate are ready', (t) => {
  if (!existsSync(php)) {
    t.skip(`${php} is not available`);
    return;
  }

  const result = runVerifier([
    '--date=2026-06-28',
    '--platform=ctrip',
  ]);
  const issueCodes = result.payload.issues.map((issue) => issue.code);

  assert.equal(result.exitCode, 0);
  assert.equal(result.payload.status, 'passed');
  assert.equal(result.payload.summary.p0_platforms_ready, 1);
  assert.equal(result.payload.summary.p0_platforms_incomplete, 0);
  assert.equal(result.payload.platforms[0].platform, 'ctrip');
  assert.equal(result.payload.platforms[0].field_fact_status, 'ready');
  assert.equal(result.payload.platforms[0].p0_traffic_gate.status, 'ready');
  assert(!issueCodes.includes('live_closure_incomplete'));
});

test('All-platform P0 field-loop verifier remains incomplete when Meituan target-date traffic rows are missing', (t) => {
  if (!existsSync(php)) {
    t.skip(`${php} is not available`);
    return;
  }

  const result = runVerifier([
    '--date=2026-06-28',
  ]);
  const issueCodes = result.payload.issues.map((issue) => issue.code);
  const meituan = result.payload.platforms.find((platform) => platform.platform === 'meituan');

  assert.equal(result.exitCode, 2);
  assert.equal(result.payload.status, 'incomplete');
  assert.equal(result.payload.summary.p0_platforms_incomplete, 1);
  assert.equal(meituan?.field_fact_status, 'not_loaded');
  assert.equal(meituan?.p0_traffic_gate?.status, 'missing_target_date_traffic_rows');
  assert(issueCodes.includes('meituan_traffic_evidence_availability_incomplete'));
});
