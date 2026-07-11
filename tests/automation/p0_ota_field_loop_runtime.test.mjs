import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const php = 'C:\\xampp\\php\\php.exe';
const shanghaiToday = () => {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
};
const runtimeDate = process.env.P0_OTA_RUNTIME_DATE || shanghaiToday();

function gateFailure(result) {
  return JSON.stringify({
    date: runtimeDate,
    status: result.payload?.status,
    summary: result.payload?.summary,
    issue_codes: (result.payload?.issues || []).map((issue) => issue?.code).filter(Boolean),
    platforms: (result.payload?.platforms || []).map((platform) => ({
      platform: platform?.platform,
      gate_status: platform?.p0_traffic_gate?.status,
      profile_scope_system_hotel_ids: platform?.p0_traffic_gate?.profile_scope_system_hotel_ids || [],
      missing_profile_source_hotel_ids: platform?.p0_traffic_gate?.profile_scope_missing_profile_source_hotel_ids || [],
      missing_traffic_source_hotel_ids: platform?.p0_traffic_gate?.profile_scope_missing_traffic_source_hotel_ids || [],
      missing_target_date_hotel_ids: platform?.p0_traffic_gate?.profile_scope_missing_target_date_traffic_hotel_ids || [],
      required_next_inputs: platform?.p0_traffic_gate?.required_next_inputs || [],
    })),
  });
}

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
    `--date=${runtimeDate}`,
    '--platform=ctrip',
  ]);
  const issueCodes = result.payload.issues.map((issue) => issue.code);

  assert.equal(result.exitCode, 0, gateFailure(result));
  assert.equal(result.payload.status, 'passed');
  assert.equal(result.payload.summary.p0_platforms_ready, 1);
  assert.equal(result.payload.summary.p0_platforms_incomplete, 0);
  assert.equal(result.payload.platforms[0].platform, 'ctrip');
  assert.equal(result.payload.platforms[0].field_fact_status, 'ready');
  assert.equal(result.payload.platforms[0].p0_traffic_gate.status, 'ready');
  assert(!issueCodes.includes('live_closure_incomplete'));
});

test('All-platform P0 field-loop verifier passes when Meituan target-date traffic rows and field facts are ready', (t) => {
  if (!existsSync(php)) {
    t.skip(`${php} is not available`);
    return;
  }

  const result = runVerifier([
    `--date=${runtimeDate}`,
  ]);
  const issueCodes = result.payload.issues.map((issue) => issue.code);
  const meituan = result.payload.platforms.find((platform) => platform.platform === 'meituan');

  assert.equal(result.exitCode, 0, gateFailure(result));
  assert.equal(result.payload.status, 'passed');
  assert.equal(result.payload.summary.p0_platforms_ready, 2);
  assert.equal(result.payload.summary.p0_platforms_incomplete, 0);
  assert.equal(meituan?.field_fact_status, 'ready');
  assert.equal(meituan?.p0_traffic_gate?.status, 'ready');
  assert.ok(Number(meituan?.p0_traffic_gate?.traffic_rows || 0) > 0);
  assert.ok(Number(meituan?.target_date_rows || 0) > 0);
  assert(!issueCodes.includes('meituan_traffic_evidence_availability_incomplete'));
  assert(!issueCodes.includes('live_closure_incomplete'));
});
