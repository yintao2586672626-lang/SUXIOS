import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { safeJsonParseErrorCode } from '../../scripts/lib/safe_json_parse_error.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sentinel = 'sk-PRIVATE_RELEASE_JSON_SENTINEL_7F31';
const releaseJsonEntrypoints = [
  'scripts/create_release_design_manifest.mjs',
  'scripts/create_release_ota_attestation.mjs',
  'scripts/promote_release_evidence_drafts.mjs',
  'scripts/lib/design_handoff_checks.mjs',
  'scripts/lib/security_scan_checks.mjs',
  'scripts/lib/llm_attestation_checks.mjs',
  'scripts/lib/ota_credential_checks.mjs',
  'scripts/report_release_evidence_gap_pack.mjs',
  'scripts/review_release_pr_candidates.mjs',
  'scripts/verify_release_evidence_gap_pack.mjs',
  'scripts/verify_release_external_state.mjs',
  'scripts/verify_release_operator_intake_packet.mjs',
  'scripts/verify_release_readiness.mjs',
];

test('release evidence JSON entrypoints route parse failures through the safe helper', () => {
  for (const relativePath of releaseJsonEntrypoints) {
    const source = fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
    assert.match(source, /safeJsonParseErrorCode/, `${relativePath} must use the shared safe JSON parse error helper`);
    assert.doesNotMatch(
      source,
      /(?:not valid JSON|not readable JSON|did not return valid JSON)[^\r\n]*\$\{error\.message\}/i,
      `${relativePath} must not interpolate a raw JSON parse error`,
    );
  }
});

test('release JSON parse failures never echo private input to stderr or result files', (t) => {
  let parseError = null;
  try {
    JSON.parse(sentinel);
  } catch (error) {
    parseError = error;
  }
  assert.equal(safeJsonParseErrorCode(parseError), 'parse_error');

  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'release-json-redaction-'));
  t.after(() => fs.rmSync(tempRoot, { recursive: true, force: true }));

  const inputPath = path.join(tempRoot, 'ota-input.json');
  const outputPath = path.join(tempRoot, 'ota-output.json');
  const resultPath = path.join(tempRoot, 'ota-create-result.json');
  fs.writeFileSync(inputPath, sentinel, 'utf8');

  const result = spawnSync(process.execPath, ['scripts/create_release_ota_attestation.mjs'], {
    cwd: repoRoot,
    encoding: 'utf8',
    env: {
      ...process.env,
      RELEASE_EVIDENCE_DIR: path.join(tempRoot, 'evidence'),
      OTA_CREDENTIAL_ROTATION_INPUT_FILE: inputPath,
      OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT: outputPath,
      OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE: resultPath,
    },
  });

  const combined = `${result.stdout || ''}\n${result.stderr || ''}`;
  assert.notEqual(result.status, 0);
  assert.match(combined, /parse_error/i);
  assert.doesNotMatch(combined, new RegExp(sentinel));
  assert.equal(fs.existsSync(resultPath), true);

  const resultText = fs.readFileSync(resultPath, 'utf8');
  assert.match(resultText, /parse_error/i);
  assert.doesNotMatch(resultText, new RegExp(sentinel));
  assert.equal(fs.existsSync(outputPath), false);
});
