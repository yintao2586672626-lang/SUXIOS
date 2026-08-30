import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  changedSkillNames,
  compareFileMaps,
  deriveDistributionStatus,
  treeDigest,
} from '../../scripts/audit_suxi_plugin_distribution.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scriptPath = path.join(repoRoot, 'scripts', 'audit_suxi_plugin_distribution.mjs');

function entry(hash, bytes = 1) {
  return { bytes, hash, kind: 'file' };
}

test('classifies missing, extra, changed, and synchronized files', () => {
  const candidate = new Map([
    ['skills/alpha/SKILL.md', entry('A')],
    ['skills/beta/SKILL.md', entry('B')],
    ['same.txt', entry('S')],
  ]);
  const distributed = new Map([
    ['skills/alpha/SKILL.md', entry('X', 2)],
    ['skills/gamma/SKILL.md', entry('G')],
    ['same.txt', entry('S')],
  ]);
  const drift = compareFileMaps(candidate, distributed);
  assert.deepEqual(drift.missingInRight, ['skills/beta/SKILL.md']);
  assert.deepEqual(drift.extraInRight, ['skills/gamma/SKILL.md']);
  assert.deepEqual(drift.contentDifferences, ['skills/alpha/SKILL.md']);
  assert.deepEqual(changedSkillNames(drift), ['alpha', 'beta', 'gamma']);
  assert.equal(drift.inSync, false);
  assert.equal(compareFileMaps(candidate, new Map(candidate)).inSync, true);
});

test('assigns status without treating short-window stability as publish authority', () => {
  const clean = { inSync: true };
  const drift = { inSync: false };
  assert.equal(deriveDistributionStatus({
    activeExists: true,
    candidateObservedStable: false,
    candidateToSource: clean,
    sourceToActive: clean,
    versionsAligned: true,
  }), 'candidate_changed_during_observation');
  assert.equal(deriveDistributionStatus({
    activeExists: true,
    candidateObservedStable: true,
    candidateToSource: drift,
    sourceToActive: clean,
    versionsAligned: true,
  }), 'candidate_ahead');
  assert.equal(deriveDistributionStatus({
    activeExists: true,
    candidateObservedStable: true,
    candidateToSource: clean,
    sourceToActive: drift,
    versionsAligned: true,
  }), 'installation_drift');
});

test('tree digest is deterministic across map insertion order', () => {
  const first = new Map([
    ['b.txt', entry('B')],
    ['a.txt', entry('A')],
  ]);
  const second = new Map([...first].reverse());
  assert.equal(treeDigest(first), treeDigest(second));
});

test('CLI self-test and help remain offline', () => {
  const selfTest = spawnSync(process.execPath, [scriptPath, '--self-test'], {
    cwd: repoRoot,
    encoding: 'utf8',
    timeout: 10_000,
    windowsHide: true,
  });
  assert.equal(selfTest.status, 0, selfTest.stderr);
  const payload = JSON.parse(selfTest.stdout);
  assert.equal(payload.status, 'passed');
  assert.equal(payload.cases.length, 8);
  assert.ok(payload.cases.every((row) => row.passed));

  const help = spawnSync(process.execPath, [scriptPath, '--help'], {
    cwd: repoRoot,
    encoding: 'utf8',
    timeout: 10_000,
    windowsHide: true,
  });
  assert.equal(help.status, 0, help.stderr);
  for (const option of [
    '--repo-plugin',
    '--marketplace-path',
    '--stability-window-ms',
    '--details',
  ]) {
    assert.ok(help.stdout.includes(option), `help must include ${option}`);
  }
});
