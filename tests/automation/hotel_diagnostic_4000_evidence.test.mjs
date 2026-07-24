import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const generator = path.join(root, 'scripts', 'generate_hotel_diagnostic_4000.mjs');
const auditDate = '2026-07-15';
const childProcessMaxBuffer = 16 * 1024 * 1024;

test('4000-case generator only promotes L8 variants with signature-bound direct evidence', () => {
  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-4000-evidence-'));
  const batchFixtureDir = mkdtempSync(path.join(root, 'tests', 'automation', '.tmp-suxi-4000-batch-'));
  const outputDir = path.join(tempRoot, 'output');
  const evidencePath = path.join(tempRoot, 'execution-evidence.json');
  const batchFixturePath = path.join(batchFixtureDir, 'batch-evidence.json');
  const batchFixtureRef = path.relative(root, batchFixturePath).replaceAll('\\', '/');

  try {
    const baseline = runGenerator(outputDir, evidencePath);
    assert.equal(baseline.status, 0, baseline.stderr);

    const baselineSummary = readSummary(outputDir);
    assert.equal(baselineSummary.totals.cases, 4000);
    assert.equal(baselineSummary.totals.execution_evidence_records, 0);
    assert.equal(baselineSummary.totals.variant_execution_statuses.not_executed, 4000);

    const baselineCases = readFileSync(casesPath(outputDir), 'utf8');
    const baselineResults = readFileSync(summaryPath(outputDir), 'utf8');
    const repeatedBaseline = runGenerator(outputDir, evidencePath);
    assert.equal(repeatedBaseline.status, 0, repeatedBaseline.stderr);
    assert.equal(readFileSync(casesPath(outputDir), 'utf8'), baselineCases);
    assert.equal(readFileSync(summaryPath(outputDir), 'utf8'), baselineResults);

    const target = readCases(outputDir).find((entry) => entry.id === 'DX-1249');
    assert.ok(target, 'DX-1249 must exist in the generated L8 matrix');
    assert.equal(target.variant_execution_status, 'not_executed');

    const batchCase = {
      case_id: target.id,
      scenario_signature: target.scenario_signature,
      status: 'pass',
      exit_code: 0,
    };
    writeBatchEvidence(batchFixturePath, [batchCase]);

    const evidenceRecord = {
      case_id: target.id,
      scenario_signature: target.scenario_signature,
      status: 'pass',
      executed_at: '2026-07-15T09:30:00+08:00',
      runner: 'node:test isolated fixture',
      evidence_ref: `${batchFixtureRef}#case=DX-1249`,
      evidence_sha256: sha256File(batchFixturePath),
      exit_code: 0,
      assertions: ['quality state remains explicit for the selected L8 variant'],
      notes: 'Synthetic isolated fixture; not live OTA evidence.',
    };

    writeLedger(evidencePath, [{
      ...evidenceRecord,
      evidence_ref: 'tests/automation/does-not-exist.json#case=DX-1249',
    }]);
    const missingEvidence = runGenerator(outputDir, evidencePath);
    assert.notEqual(missingEvidence.status, 0);
    assert.match(`${missingEvidence.stdout}\n${missingEvidence.stderr}`, /evidence_ref file is missing for DX-1249/);

    writeLedger(evidencePath, [{
      ...evidenceRecord,
      evidence_ref: `${batchFixturePath}#case=DX-1249`,
    }]);
    const absoluteEvidence = runGenerator(outputDir, evidencePath);
    assert.notEqual(absoluteEvidence.status, 0);
    assert.match(`${absoluteEvidence.stdout}\n${absoluteEvidence.stderr}`, /evidence_ref must be project-relative for DX-1249/);

    writeLedger(evidencePath, [{
      ...evidenceRecord,
      evidence_ref: '../package.json#case=DX-1249',
    }]);
    const escapedEvidence = runGenerator(outputDir, evidencePath);
    assert.notEqual(escapedEvidence.status, 0);
    assert.match(`${escapedEvidence.stdout}\n${escapedEvidence.stderr}`, /evidence_ref must be project-relative for DX-1249/);

    writeLedger(evidencePath, [{
      ...evidenceRecord,
      evidence_ref: batchFixtureRef,
    }]);
    const missingAnchor = runGenerator(outputDir, evidencePath);
    assert.notEqual(missingAnchor.status, 0);
    assert.match(`${missingAnchor.stdout}\n${missingAnchor.stderr}`, /must include exactly one #case=DX-1249 anchor/);

    writeLedger(evidencePath, [{
      ...evidenceRecord,
      evidence_ref: `${batchFixtureRef}#case=DX-1250`,
    }]);
    const mismatchedAnchor = runGenerator(outputDir, evidencePath);
    assert.notEqual(mismatchedAnchor.status, 0);
    assert.match(`${mismatchedAnchor.stdout}\n${mismatchedAnchor.stderr}`, /case anchor mismatch for DX-1249: DX-1250/);

    writeLedger(evidencePath, [{
      ...evidenceRecord,
      evidence_ref: 'package.json#case=DX-1249',
    }]);
    const arbitraryJson = runGenerator(outputDir, evidencePath);
    assert.notEqual(arbitraryJson.status, 0);
    assert.match(`${arbitraryJson.stdout}\n${arbitraryJson.stderr}`, /batch contract is invalid for DX-1249/);

    writeBatchEvidence(batchFixturePath, [{ ...batchCase, case_id: 'DX-0001' }]);
    writeLedger(evidencePath, [evidenceRecord]);
    const missingBoundCase = runGenerator(outputDir, evidencePath);
    assert.notEqual(missingBoundCase.status, 0);
    assert.match(`${missingBoundCase.stdout}\n${missingBoundCase.stderr}`, /must resolve exactly one batch case for DX-1249/);

    writeBatchEvidence(batchFixturePath, [batchCase, batchCase]);
    const duplicateBoundCase = runGenerator(outputDir, evidencePath);
    assert.notEqual(duplicateBoundCase.status, 0);
    assert.match(`${duplicateBoundCase.stdout}\n${duplicateBoundCase.stderr}`, /must resolve exactly one batch case for DX-1249/);

    writeBatchEvidence(batchFixturePath, [{ ...batchCase, scenario_signature: `sha256:${'0'.repeat(64)}` }]);
    const mismatchedBatchSignature = runGenerator(outputDir, evidencePath);
    assert.notEqual(mismatchedBatchSignature.status, 0);
    assert.match(`${mismatchedBatchSignature.stdout}\n${mismatchedBatchSignature.stderr}`, /batch scenario_signature mismatch for DX-1249/);

    writeBatchEvidence(batchFixturePath, [{ ...batchCase, status: 'partial' }]);
    const mismatchedBatchStatus = runGenerator(outputDir, evidencePath);
    assert.notEqual(mismatchedBatchStatus.status, 0);
    assert.match(`${mismatchedBatchStatus.stdout}\n${mismatchedBatchStatus.stderr}`, /batch status mismatch for DX-1249/);

    writeBatchEvidence(batchFixturePath, [{ ...batchCase, status: 'blocked', exit_code: 9 }]);
    writeLedger(evidencePath, [{ ...evidenceRecord, status: 'blocked', exit_code: null }]);
    const mismatchedBatchExit = runGenerator(outputDir, evidencePath);
    assert.notEqual(mismatchedBatchExit.status, 0);
    assert.match(`${mismatchedBatchExit.stdout}\n${mismatchedBatchExit.stderr}`, /batch exit_code mismatch for DX-1249/);

    writeBatchEvidence(batchFixturePath, [batchCase]);
    writeLedger(evidencePath, [{
      ...evidenceRecord,
      evidence_sha256: `sha256:${'0'.repeat(64)}`,
    }]);
    const mismatchedEvidenceHash = runGenerator(outputDir, evidencePath);
    assert.notEqual(mismatchedEvidenceHash.status, 0);
    assert.match(`${mismatchedEvidenceHash.stdout}\n${mismatchedEvidenceHash.stderr}`, /evidence_sha256 mismatch for DX-1249/);

    writeLedger(evidencePath, [{ ...evidenceRecord, status: 'partial', exit_code: 7 }]);
    const partialWithFailureExit = runGenerator(outputDir, evidencePath);
    assert.notEqual(partialWithFailureExit.status, 0);
    assert.match(`${partialWithFailureExit.stdout}\n${partialWithFailureExit.stderr}`, /exit_code is inconsistent with status partial for DX-1249/);

    writeLedger(evidencePath, [{ ...evidenceRecord, status: 'fail', exit_code: 0 }]);
    const failureWithSuccessExit = runGenerator(outputDir, evidencePath);
    assert.notEqual(failureWithSuccessExit.status, 0);
    assert.match(`${failureWithSuccessExit.stdout}\n${failureWithSuccessExit.stderr}`, /exit_code is inconsistent with status fail for DX-1249/);

    const { assertions: _assertions, ...recordWithoutSummary } = evidenceRecord;
    writeLedger(evidencePath, [recordWithoutSummary]);
    const missingSummary = runGenerator(outputDir, evidencePath);
    assert.notEqual(missingSummary.status, 0);
    assert.match(`${missingSummary.stdout}\n${missingSummary.stderr}`, /minimum assertion or output summary is required for DX-1249/);

    writeLedger(evidencePath, [{
      ...recordWithoutSummary,
      output_summary: { tests: 1, assertions: 1 },
    }]);
    const structuredSummary = runGenerator(outputDir, evidencePath);
    assert.equal(structuredSummary.status, 0, structuredSummary.stderr);

    writeLedger(evidencePath, [evidenceRecord]);

    const promoted = runGenerator(outputDir, evidencePath);
    assert.equal(promoted.status, 0, promoted.stderr);
    const promotedSummary = readSummary(outputDir);
    assert.equal(promotedSummary.totals.execution_evidence_records, 1);
    assert.equal(promotedSummary.totals.variant_execution_statuses.pass, 1);
    assert.equal(promotedSummary.totals.variant_execution_statuses.not_executed, 3999);

    const promotedTarget = readCases(outputDir).find((entry) => entry.id === target.id);
    assert.equal(promotedTarget.variant_execution_status, 'pass');
    assert.equal(promotedTarget.pending_execution_note, null);
    assert.equal(promotedTarget.variant_execution_evidence.evidence_ref, evidenceRecord.evidence_ref);
    assert.equal(promotedTarget.variant_execution_evidence.evidence_sha256, evidenceRecord.evidence_sha256);

    const { evidence_sha256: _evidenceSha256, ...recordWithoutPinnedHash } = evidenceRecord;
    writeLedger(evidencePath, [recordWithoutPinnedHash]);
    const compatibleUnpinnedEvidence = runGenerator(outputDir, evidencePath);
    assert.equal(compatibleUnpinnedEvidence.status, 0, compatibleUnpinnedEvidence.stderr);
    const compatibleTarget = readCases(outputDir).find((entry) => entry.id === target.id);
    assert.equal(compatibleTarget.variant_execution_evidence.evidence_sha256, evidenceRecord.evidence_sha256);

    writeLedger(evidencePath, [{ ...evidenceRecord, scenario_signature: `sha256:${'0'.repeat(64)}` }]);
    const mismatched = runGenerator(outputDir, evidencePath);
    assert.notEqual(mismatched.status, 0);
    assert.match(`${mismatched.stdout}\n${mismatched.stderr}`, /signature mismatch for DX-1249/);

    writeLedger(evidencePath, [evidenceRecord, evidenceRecord]);
    const duplicated = runGenerator(outputDir, evidencePath);
    assert.notEqual(duplicated.status, 0);
    assert.match(`${duplicated.stdout}\n${duplicated.stderr}`, /duplicate case_id: DX-1249/);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
    rmSync(batchFixtureDir, { recursive: true, force: true });
  }
});

function runGenerator(outputDir, evidencePath) {
  return spawnSync(process.execPath, [generator], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: childProcessMaxBuffer,
    env: {
      ...process.env,
      SUXI_DIAGNOSTIC_OUTPUT_DIR: outputDir,
      SUXI_DIAGNOSTIC_EVIDENCE_PATH: evidencePath,
    },
  });
}

function readSummary(outputDir) {
  return JSON.parse(readFileSync(summaryPath(outputDir), 'utf8'));
}

function readCases(outputDir) {
  return readFileSync(casesPath(outputDir), 'utf8')
    .trim()
    .split(/\r?\n/u)
    .map((line) => JSON.parse(line));
}

function summaryPath(outputDir) {
  return path.join(outputDir, `hotel_system_4000_diagnostic_results_${auditDate}.json`);
}

function casesPath(outputDir) {
  return path.join(outputDir, `hotel_system_4000_diagnostic_cases_${auditDate}.jsonl`);
}

function writeLedger(evidencePath, records) {
  writeFileSync(evidencePath, `${JSON.stringify({
    schema_version: 1,
    audit_date: auditDate,
    records,
  }, null, 2)}\n`, 'utf8');
}

function writeBatchEvidence(filePath, cases) {
  writeFileSync(filePath, `${JSON.stringify({
    schema_version: 1,
    audit_date: auditDate,
    cases,
  }, null, 2)}\n`, 'utf8');
}

function sha256File(filePath) {
  return `sha256:${createHash('sha256').update(readFileSync(filePath)).digest('hex')}`;
}
