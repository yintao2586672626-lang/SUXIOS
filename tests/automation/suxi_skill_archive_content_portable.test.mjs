import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

import { extractGithubActionsJob } from './helpers/github_actions_workflow.mjs';
import {
  buildPortableArchiveContentReport,
  portableArchiveContentVersion,
} from '../../scripts/verify_suxi_skill_behavior_archive_content.mjs';

const repoRoot = path.resolve(import.meta.dirname, '../..');
const verifierPath = path.join(repoRoot, 'scripts', 'verify_suxi_skill_behavior_archive_content.mjs');

test('portable archive report fails closed without replayed runs and identities', () => {
  const report = buildPortableArchiveContentReport({ status: 'PASS', read_only: true });
  assert.equal(report.schema_version, portableArchiveContentVersion);
  assert.equal(report.status, 'FAIL');
  assert.equal(report.verified_counts, null);
  assert.deepEqual(report.failures, [
    'archive_content_identity_missing',
    'archive_run_replay_not_pass',
    'archive_verified_counts_invalid',
  ]);
});

test('tracked governed-Skill archive content replays in a clean portable checkout', () => {
  const result = spawnSync(process.execPath, [verifierPath], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.status, 0, result.stderr);
  const report = JSON.parse(result.stdout);
  assert.equal(report.status, 'PASS');
  assert.equal(report.read_only, true);
  assert.equal(report.run_results.length, 3);
  assert.equal(report.verified_counts.runs, 3);
  assert.ok(report.run_results.every(row => row.status === 'PASS' && row.failure_count === 0));
  assert.match(report.evidence_boundary, /excludes physical-path archive seals/u);
});

test('Skill evidence workflow uses portable archive replay instead of a missing local eval root', () => {
  const packageJson = JSON.parse(readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
  const workflow = readFileSync(path.join(repoRoot, '.github', 'workflows', 'php.yml'), 'utf8');
  const skillJob = extractGithubActionsJob(workflow, 'skill_evidence');
  assert.equal(
    packageJson.scripts['verify:skill-archive-content'],
    'node scripts/verify_suxi_skill_behavior_archive_content.mjs',
  );
  assert.match(skillJob, /npm run verify:skill-archive-content/u);
  assert.doesNotMatch(skillJob, /suxi_skill_behavior_eval\.mjs verify-suite/u);
});

test('portable archive verifier is read-only and performs no child execution or network access', () => {
  const source = readFileSync(verifierPath, 'utf8');
  assert.doesNotMatch(source, /node:child_process|spawnSync|execFile|writeFile|rmSync|unlinkSync/u);
  assert.doesNotMatch(source, /https?:|fetch\(|WebSocket/u);
  assert.match(source, /inspectEvidenceArchiveContents/u);
});
