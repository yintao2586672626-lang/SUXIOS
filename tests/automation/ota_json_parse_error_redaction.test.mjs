import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

import { parseJsonTextSafely } from '../../scripts/lib/safe_json_parse_error.mjs';
import { validateMetricFormulas } from '../../scripts/lib/ota_data_validator.mjs';

const repoRoot = path.resolve(import.meta.dirname, '..', '..');
const sentinel = 'Cookie=OTA_PARSE_SENTINEL_9f31b';

test('shared JSON parser reports only a safe parse code', () => {
  assert.throws(
    () => parseJsonTextSafely(sentinel, 'ota_payload_json'),
    (error) => {
      assert.match(String(error?.message || ''), /^ota_payload_json:parse_error/);
      assert.doesNotMatch(String(error?.message || ''), /Cookie=OTA_PARSE_SENTINEL/);
      return true;
    },
  );
});

test('OTA raw_data validation never copies malformed input into errors', () => {
  const result = validateMetricFormulas([{ hotel_id: 1, raw_data: sentinel }]);
  const encoded = JSON.stringify(result);
  assert.match(encoded, /parse_error/);
  assert.doesNotMatch(encoded, /Cookie=OTA_PARSE_SENTINEL/);
});

test('browser-assist normalizer stderr redacts malformed capture input', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'suxios-ota-parse-'));
  const inputPath = path.join(dir, 'capture.json');
  try {
    writeFileSync(inputPath, sentinel, 'utf8');
    const result = spawnSync(process.execPath, [
      path.join(repoRoot, 'scripts', 'normalize_ota_browser_assist_capture.mjs'),
      `--input=${inputPath}`,
    ], {
      cwd: repoRoot,
      encoding: 'utf8',
      windowsHide: true,
    });
    const output = `${result.stdout || ''}\n${result.stderr || ''}`;
    assert.notEqual(result.status, 0);
    assert.match(output, /browser_assist_capture_json:parse_error/);
    assert.doesNotMatch(output, /Cookie=OTA_PARSE_SENTINEL/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('Ctrip Cookie capture stderr redacts malformed credential input', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'suxios-ctrip-cookie-parse-'));
  const inputPath = path.join(dir, 'capture.json');
  try {
    writeFileSync(inputPath, sentinel, 'utf8');
    const result = spawnSync(process.execPath, [
      path.join(repoRoot, 'scripts', 'ctrip_cookie_api_capture.mjs'),
      `--input=${inputPath}`,
      `--output=${path.join(dir, 'output.json')}`,
    ], {
      cwd: repoRoot,
      encoding: 'utf8',
      windowsHide: true,
    });
    const output = `${result.stdout || ''}\n${result.stderr || ''}`;
    assert.notEqual(result.status, 0);
    assert.match(output, /ctrip_cookie_capture_json:parse_error/);
    assert.doesNotMatch(output, /Cookie=OTA_PARSE_SENTINEL/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('input-facing OTA parsers use the shared safe parser', () => {
  const normalizer = readFileSync(path.join(repoRoot, 'scripts', 'normalize_ota_browser_assist_capture.mjs'), 'utf8');
  const validator = readFileSync(path.join(repoRoot, 'scripts', 'lib', 'ota_data_validator.mjs'), 'utf8');
  assert.match(normalizer, /parseJsonTextSafely\(raw, 'browser_assist_capture_json'\)/);
  assert.match(validator, /safeJsonParseErrorCode\(error\)/);

  const inputFacingScripts = [
    'ctrip_browser_capture.mjs',
    'ctrip_cookie_api_capture.mjs',
    'audit_ctrip_capture_output.mjs',
    'build_ctrip_diagnosis_snapshot.mjs',
    'dry_run_ctrip_approved_mapping.mjs',
    'probe_ctrip_capture.mjs',
    'promote_ctrip_mapping_draft.mjs',
    'report_p0_ota_field_loop_audit.mjs',
    'report_p0_profile_next_steps.mjs',
    'report_revenue_ai_ctrip_auto_input_eligibility.mjs',
    'report_revenue_ai_ctrip_external_input_candidates.mjs',
    'run_revenue_ai_ctrip_quick_reply_to_pending_review.mjs',
    'scan_p0_ota_traffic_payload_candidates.mjs',
    'summarize_ctrip_capture_result.mjs',
    'validate_ctrip_endpoint_evidence.mjs',
    'verify_hotel_ota_login_eligibility_behavior.mjs',
    'verify_ota_data_batch.mjs',
    'verify_ota_data_metrics.mjs',
    'verify_p0_ota_ui_verifier_alignment.mjs',
    'verify_phase1_live_action_queue_runtime.mjs',
    'verify_revenue_ai_ctrip_operator_bundle.mjs',
    'verify_revenue_ai_ctrip_operator_quick_reply_preflight.mjs',
  ];
  for (const script of inputFacingScripts) {
    const source = readFileSync(path.join(repoRoot, 'scripts', script), 'utf8');
    assert.match(source, /parseJsonTextSafely/, `${script} must use the shared safe parser`);
  }

  const phase1Verifier = readFileSync(path.join(repoRoot, 'scripts', 'verify_phase1_live_action_queue_runtime.mjs'), 'utf8');
  assert.doesNotMatch(phase1Verifier, /result\.stderr \|\| result\.stdout/);
  assert.doesNotMatch(phase1Verifier, /JSON\.parse\(result\.stdout\)/);

  const loginEligibility = readFileSync(path.join(repoRoot, 'scripts', 'verify_hotel_ota_login_eligibility_behavior.mjs'), 'utf8');
  assert.doesNotMatch(loginEligibility, /console\.error\(result\.stderr \|\| result\.stdout\)/);
  assert.doesNotMatch(loginEligibility, /result\.stdout\.slice/);
});
