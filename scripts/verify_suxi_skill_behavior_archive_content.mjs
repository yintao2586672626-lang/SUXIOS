#!/usr/bin/env node

import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { inspectEvidenceArchiveContents } from './suxi_skill_behavior_eval.mjs';

const scriptPath = fileURLToPath(import.meta.url);
const SHA256 = /^[a-f0-9]{64}$/u;

export const portableArchiveContentVersion = 'suxi.skill.behavior_archive_content_portable.v1';

const isSha256 = value => SHA256.test(String(value || ''));

export function buildPortableArchiveContentReport(source = {}) {
  const runResults = Array.isArray(source.run_results) ? source.run_results : [];
  const failures = [];
  if (source.status !== 'PASS') failures.push('archive_content_replay_not_pass');
  if (Array.isArray(source.archive_failures) && source.archive_failures.length > 0) {
    failures.push('archive_content_failures_present');
  }
  if (!isSha256(source.archive_manifest_sha256) || !isSha256(source.source_ledger_sha256)) {
    failures.push('archive_content_identity_missing');
  }
  if (runResults.length === 0
    || runResults.some(row => row?.status !== 'PASS' || (row?.failures?.length || 0) > 0)) {
    failures.push('archive_run_replay_not_pass');
  }
  if (!source.verified_counts || source.verified_counts.runs !== runResults.length) {
    failures.push('archive_verified_counts_invalid');
  }
  if (source.read_only !== true) failures.push('archive_replay_not_read_only');

  const uniqueFailures = [...new Set(failures)].sort();
  return {
    schema_version: portableArchiveContentVersion,
    status: uniqueFailures.length === 0 ? 'PASS' : 'FAIL',
    archive_manifest_sha256: isSha256(source.archive_manifest_sha256)
      ? source.archive_manifest_sha256
      : null,
    source_ledger_sha256: isSha256(source.source_ledger_sha256)
      ? source.source_ledger_sha256
      : null,
    verified_counts: uniqueFailures.length === 0 ? source.verified_counts : null,
    run_results: runResults.map(row => ({
      skill_name: String(row?.skill_name || ''),
      run_id: String(row?.run_id || ''),
      status: String(row?.status || 'FAIL'),
      failure_count: Array.isArray(row?.failures) ? row.failures.length : 1,
    })),
    failures: uniqueFailures,
    read_only: true,
    evidence_boundary: 'PASS deterministically replays the tracked archive contents, hashes, counts, source ledger, judgments, exact spans, and run seals in this checkout. It deliberately excludes physical-path archive seals, local verifier receipts, model or judge identity, fixed-test execution receipts, deployment, and field behavior.',
  };
}

export function verifyPortableArchiveContent() {
  try {
    return buildPortableArchiveContentReport(inspectEvidenceArchiveContents().result);
  } catch {
    return buildPortableArchiveContentReport({
      status: 'FAIL',
      archive_failures: ['portable_archive_content_replay_error'],
      run_results: [],
      read_only: true,
    });
  }
}

function samePath(left, right) {
  const normalize = value => path.resolve(value).replaceAll('\\', '/').toLowerCase();
  return normalize(left) === normalize(right);
}

if (process.argv[1] && samePath(process.argv[1], scriptPath)) {
  const report = verifyPortableArchiveContent();
  const output = `${JSON.stringify(report, null, 2)}\n`;
  if (report.status === 'PASS') process.stdout.write(output);
  else {
    process.stderr.write(output);
    process.exitCode = 1;
  }
}
