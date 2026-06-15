import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const scanner = path.join(root, 'scripts', 'scan_p0_ota_traffic_payload_candidates.mjs');
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const p0Verifier = path.join(root, 'scripts', 'verify_p0_ota_field_loop_closure.php');
const targetDate = '2026-06-15';
const systemHotelId = '7';

test('P0 OTA traffic payload scanner defaults to P0 verifier hotel-scoped candidates', () => {
  const expectedPaths = p0VerifierExpectedPayloadPaths(targetDate);
  assert.ok(expectedPaths.length > 0, 'P0 verifier should expose hotel-scoped payload candidates');

  const result = runScanner([
    `--date=${targetDate}`,
    '--format=json',
  ]);
  assert.equal(result.status, 0, result.stderr);
  const json = JSON.parse(result.stdout);
  assert.equal(json.scope.system_hotel_id, 'p0_verifier_hotel_scoped');
  assert.equal(json.scope.hotel_scope_policy, 'read_p0_verifier_hotel_scoped_next_steps');
  assert.deepEqual([...json.expected_candidate_paths].sort(), expectedPaths);
  const expectedMissingPaths = expectedPaths
    .filter((payloadPath) => !existsSync(path.join(root, payloadPath)))
    .sort();
  if (expectedMissingPaths.length > 0) {
    const missingAction = json.next_actions.find((action) => Array.isArray(action.missing_payloads));
    assert.ok(missingAction, 'scanner should include a missing-payload action when expected payloads are absent');
    assert.deepEqual(missingAction.missing_payloads.map((item) => item.payload).sort(), expectedMissingPaths);
  }
  for (const candidate of [...json.ready_candidates, ...json.blocked_candidates]) {
    assert.match(candidate.next_verifier_command, new RegExp(`--platform=${candidate.platform}`));
    assert.match(candidate.next_verifier_command, new RegExp(`--system-hotel-id=${candidate.system_hotel_id}`));
  }

  const markdownResult = runScanner([
    `--date=${targetDate}`,
    '--format=markdown',
  ]);
  assert.equal(markdownResult.status, 0, markdownResult.stderr);
  if (expectedMissingPaths.length > 0) {
    assert.match(markdownResult.stdout, /## Missing Authorized Payloads/);
  }
  if ([...json.blocked_candidates].length > 0) {
    assert.ok(Array.isArray(json.blocked_issue_summary));
    assert.ok(json.blocked_issue_summary.length > 0);
    assert.match(markdownResult.stdout, /## Blocked Candidate Fixes/);
    assert.match(markdownResult.stdout, /## Blocked Issue Summary/);
    for (const issue of json.blocked_issue_summary) {
      assert.match(markdownResult.stdout, new RegExp(escapeRegExp(issue.code)));
    }
  }
  for (const expectedPath of expectedPaths) {
    assert.match(markdownResult.stdout, new RegExp(escapeRegExp(expectedPath)));
  }
});

test('P0 OTA traffic payload scanner finds ready dry-run candidates without exposing payload content', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-traffic-candidates-ready-'));
  try {
    const payloadPath = path.join(dir, 'authorized-traffic.json');
    writeFileSync(payloadPath, JSON.stringify({
      traffic: [{
        hotelId: 'ctrip-platform-1001',
        date: targetDate,
        date_source: 'request.payload.dataDate',
        _source_path: 'traffic.0',
        capture_evidence: {
          source_trace_id: 'ctrip:test-ready-traffic',
          source_url_hash: 'a'.repeat(64),
        },
        listExposure: 20,
        detailExposure: 10,
        flowRate: 50,
        orderFillingNum: 4,
        orderSubmitNum: 2,
      }],
    }), 'utf8');

    const result = runScanner([
      `--date=${targetDate}`,
      `--system-hotel-id=${systemHotelId}`,
      '--platform=ctrip',
      `--input=${payloadPath}`,
      '--format=json',
    ]);
    assert.equal(result.status, 0, result.stderr);
    const json = JSON.parse(result.stdout);
    assert.equal(json.status, 'ready_candidates_found');
    assert.equal(json.summary.ready_candidate_count, 1);
    assert.equal(json.ready_candidates[0].status, 'ready_to_import');
    assert.equal(json.ready_candidates[0].target_date_rows, 1);
    assert.equal(json.ready_candidates[0].traffic_evidence_rows, 1);
    assert.match(json.next_actions[0].command, /import:p0-ota-traffic-payload:execute/);
    assert.match(json.next_actions[0].verification, /verify:p0-ota-field-loop/);
    assert.match(json.ready_candidates[0].next_verifier_command, /--platform=ctrip/);
    assert.match(json.ready_candidates[0].next_verifier_command, /--system-hotel-id=7/);
    assert.equal(JSON.stringify(json).includes('ctrip-platform-1001'), false);
    assert.equal(JSON.stringify(json).includes('listExposure'), false);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 OTA traffic payload scanner infers platform and system hotel from P0 payload filename', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-traffic-candidates-inferred-'));
  try {
    const payloadPath = path.join(dir, 'p0_traffic_ctrip_60_20260615.json');
    writeFileSync(payloadPath, JSON.stringify({
      traffic: [{
        hotelId: 'ctrip-platform-1001',
        date: targetDate,
        date_source: 'request.payload.dataDate',
        _source_path: 'traffic.0',
        capture_evidence: {
          source_trace_id: 'ctrip:test-inferred-traffic',
          source_url_hash: 'b'.repeat(64),
        },
        listExposure: 20,
        detailExposure: 10,
        flowRate: 50,
        orderFillingNum: 4,
        orderSubmitNum: 2,
      }],
    }), 'utf8');

    const result = runScanner([
      `--date=${targetDate}`,
      '--platform=all',
      `--input=${payloadPath}`,
      '--format=json',
    ]);
    assert.equal(result.status, 0, result.stderr);
    const json = JSON.parse(result.stdout);
    assert.equal(json.summary.dry_run_count, 1);
    assert.equal(json.ready_candidates[0].platform, 'ctrip');
    assert.equal(json.ready_candidates[0].system_hotel_id, '60');
    assert.match(json.next_actions[0].command, /--system-hotel-id=60/);
    assert.match(json.ready_candidates[0].next_verifier_command, /--platform=ctrip/);
    assert.match(json.ready_candidates[0].next_verifier_command, /--system-hotel-id=60/);
    assert.deepEqual(json.blocked_candidates, []);
    assert.equal(json.ready_candidates.some((item) => item.platform === 'meituan'), false);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 OTA traffic payload scanner keeps incomplete payloads blocked and explains missing evidence', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-traffic-candidates-blocked-'));
  try {
    const payloadPath = path.join(dir, 'incomplete-traffic.json');
    writeFileSync(payloadPath, JSON.stringify({
      traffic: [{
        hotelId: 'ctrip-platform-1001',
        date: targetDate,
        listExposure: 20,
      }],
    }), 'utf8');

    const result = runScanner([
      `--date=${targetDate}`,
      `--system-hotel-id=${systemHotelId}`,
      '--platform=ctrip',
      `--input=${payloadPath}`,
      '--format=json',
    ]);
    assert.equal(result.status, 0, result.stderr);
    const json = JSON.parse(result.stdout);
    assert.equal(json.status, 'no_ready_candidates');
    assert.equal(json.summary.ready_candidate_count, 0);
    assert.equal(json.summary.blocked_candidate_count, 1);
    assert.equal(json.blocked_candidates[0].status, 'blocked');
    assert.ok(json.blocked_candidates[0].issue_codes.includes('desensitized_capture_evidence_missing'));
    assert.ok(json.blocked_candidates[0].required_fixes.some((fix) => fix.code === 'desensitized_capture_evidence_missing'));
    assert.ok(json.blocked_candidates[0].required_fixes.some((fix) => fix.evidence_fields.includes('capture_evidence.source_trace_id')));
    assert.ok(json.blocked_issue_summary.some((issue) => issue.code === 'desensitized_capture_evidence_missing'));
    assert.ok(json.blocked_issue_summary.some((issue) => issue.affected_candidates.some((candidate) => candidate.payload.endsWith(path.basename(payloadPath)))));
    assert.ok(json.next_actions[0].required_inputs.includes('authorized Ctrip/Meituan traffic JSON payload for the target date'));
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 OTA traffic payload scanner reports missing candidates and strict mode fails without import', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-traffic-candidates-empty-'));
  try {
    const result = runScanner([
      `--date=${targetDate}`,
      `--system-hotel-id=${systemHotelId}`,
      '--platform=ctrip',
      `--input=${dir}`,
      '--format=json',
    ]);
    assert.equal(result.status, 0, result.stderr);
    const json = JSON.parse(result.stdout);
    assert.equal(json.status, 'missing_candidates');
    assert.equal(json.summary.candidate_file_count, 0);
    assert.deepEqual(json.ready_candidates, []);

    const strictResult = runScanner([
      `--date=${targetDate}`,
      `--system-hotel-id=${systemHotelId}`,
      '--platform=ctrip',
      `--input=${dir}`,
      '--format=json',
      '--strict',
    ]);
    assert.equal(strictResult.status, 1);
    assert.equal(JSON.parse(strictResult.stdout).status, 'missing_candidates');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

function runScanner(args) {
  return spawnSync(process.execPath, [scanner, ...args], {
    cwd: root,
    encoding: 'utf8',
  });
}

function p0VerifierExpectedPayloadPaths(date) {
  const result = spawnSync(phpBinary, [
    p0Verifier,
    `--date=${date}`,
    '--platform=ctrip,meituan',
    '--format=json',
  ], {
    cwd: root,
    encoding: 'utf8',
  });
  assert.ok([0, 1, 2].includes(Number(result.status ?? 0)), result.stderr);
  const json = JSON.parse(String(result.stdout || '').replace(/^\uFEFF/, '').trim());
  return (json.platforms || [])
    .flatMap((platform) => platform.p0_traffic_gate?.hotel_scoped_next_steps || [])
    .map((step) => String(step.payload_candidate_path || ''))
    .filter(Boolean)
    .sort();
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
