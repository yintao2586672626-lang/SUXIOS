import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  classifyExplicitInvocation,
  discoveryInputSnapshot,
  observePromptInput,
  parseArgs,
  promptInputConclusion,
  validateInvocationName,
} from '../../scripts/verify_codex_explicit_skill_invocation.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scriptPath = path.join(repoRoot, 'scripts', 'verify_codex_explicit_skill_invocation.mjs');

function observation({ all = false, matched = 0, token = false }) {
  return {
    allMarkersMatched: all,
    explicitTokenPreserved: token,
    matchedMarkers: matched,
  };
}

test('classifies full load, implicit leak, partial load, and missing load', () => {
  const implicit = observation({});
  assert.equal(classifyExplicitInvocation({
    implicit,
    explicit: observation({ all: true, matched: 2 }),
  }), 'pass');
  assert.equal(classifyExplicitInvocation({
    implicit: observation({ matched: 1 }),
    explicit: observation({ all: true, matched: 2 }),
  }), 'implicit_leak');
  assert.equal(classifyExplicitInvocation({
    implicit,
    explicit: observation({ matched: 1, token: true }),
  }), 'explicit_partial_load');
  assert.equal(classifyExplicitInvocation({
    implicit,
    explicit: observation({ token: true }),
  }), 'explicit_not_loaded');
  assert.equal(classifyExplicitInvocation({
    implicit,
    explicit: observation({}),
  }), 'explicit_token_missing');
  assert.equal(classifyExplicitInvocation({
    implicit,
    explicit: observation({ all: true, matched: 2 }),
    inputStable: false,
  }), 'input_changed_during_verification');
  assert.equal(
    promptInputConclusion('explicit_not_loaded'),
    'indeterminate_no_prompt_input_expansion',
  );
  assert.equal(
    promptInputConclusion('pass'),
    'explicit_body_observed_in_prompt_input',
  );
});

test('requires a clean base prompt and at least two unique markers', () => {
  assert.throws(
    () => parseArgs(['--skill-root', 'skill', '--prompt', 'plain', '--marker', 'one']),
    /At least two --marker values/u,
  );
  assert.throws(
    () => parseArgs([
      '--skill-root', 'skill',
      '--prompt', 'plain',
      '--marker', 'same',
      '--marker', 'same',
    ]),
    /must be unique/u,
  );
  const defaults = parseArgs([
    '--skill-root', 'skill',
    '--marker', 'one',
    '--marker', 'two',
  ]);
  assert.equal(defaults.prompt, null);
  assert.equal(defaults.invocationName, null);

  const namespaced = parseArgs([
    '--skill-root', 'skill',
    '--invocation-name', 'suxi-os-toolkit:suxi-product-decision',
    '--marker', 'one',
    '--marker', 'two',
  ]);
  assert.equal(namespaced.invocationName, 'suxi-os-toolkit:suxi-product-decision');
});

test('observes namespaced catalog entries with a valid multiline JavaScript regex', () => {
  const observed = observePromptInput({
    messageCount: 3,
    runtimeWarnings: {},
    text: '- bundle:suxi-product-decision: explicit route\n# 宿析产品决策\ndecision_status: decision_ready | provisional | blocked',
  }, [
    '# 宿析产品决策',
    'decision_status: decision_ready | provisional | blocked',
  ], 'suxi-product-decision');
  assert.equal(observed.catalogEntryObserved, true);
  assert.equal(observed.allMarkersMatched, true);
  assert.equal(observed.matchedMarkers, 2);
});

test('observes and validates the actual namespaced plugin invocation token', () => {
  const invocationName = validateInvocationName(
    'suxi-product-decision',
    'suxi-os-toolkit:suxi-product-decision',
  );
  const observed = observePromptInput({
    messageCount: 3,
    runtimeWarnings: {},
    text: '- suxi-os-toolkit:suxi-product-decision: explicit route\n$suxi-os-toolkit:suxi-product-decision\n# 宿析产品决策\ndecision_status: decision_ready | provisional | blocked',
  }, [
    '# 宿析产品决策',
    'decision_status: decision_ready | provisional | blocked',
  ], 'suxi-product-decision', invocationName);

  assert.equal(observed.catalogEntryObserved, true);
  assert.equal(observed.explicitTokenPreserved, true);
  assert.equal(observed.allMarkersMatched, true);
  assert.throws(
    () => validateInvocationName(
      'suxi-product-decision',
      'suxi-os-toolkit:suxi-user-research',
    ),
    /must end with the SKILL\.md name/u,
  );
});

test('discovery snapshot changes when Skill or policy input changes', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'suxi-explicit-input-snapshot-'));
  const skillPath = path.join(root, 'SKILL.md');
  const policyPath = path.join(root, 'openai.yaml');
  try {
    writeFileSync(skillPath, 'skill-v1\n');
    writeFileSync(policyPath, 'policy-v1\n');
    const first = discoveryInputSnapshot(skillPath, policyPath);
    const same = discoveryInputSnapshot(skillPath, policyPath);
    assert.deepEqual(same, first);

    writeFileSync(skillPath, 'skill-v2\n');
    const changed = discoveryInputSnapshot(skillPath, policyPath);
    assert.notEqual(changed.combinedSha256, first.combinedSha256);
    assert.notEqual(changed.skill.sha256, first.skill.sha256);
    assert.equal(changed.policy.sha256, first.policy.sha256);

    unlinkSync(policyPath);
    const missingPolicy = discoveryInputSnapshot(skillPath, policyPath);
    assert.equal(missingPolicy.policy.exists, false);
    assert.equal(missingPolicy.policy.sha256, null);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('CLI self-test and help stay offline', () => {
  const selfTest = spawnSync(process.execPath, [scriptPath, '--self-test'], {
    cwd: repoRoot,
    encoding: 'utf8',
    timeout: 10_000,
    windowsHide: true,
  });
  assert.equal(selfTest.status, 0, selfTest.stderr);
  const payload = JSON.parse(selfTest.stdout);
  assert.equal(payload.status, 'passed');
  assert.equal(payload.cases.length, 13);
  assert.ok(payload.cases.every((row) => row.passed));

  const help = spawnSync(process.execPath, [scriptPath, '--help'], {
    cwd: repoRoot,
    encoding: 'utf8',
    timeout: 10_000,
    windowsHide: true,
  });
  assert.equal(help.status, 0, help.stderr);
  for (const option of [
    '--skill-root',
    '--invocation-name',
    '--prompt',
    '--marker',
    '--details',
    '--self-test',
  ]) {
    assert.ok(help.stdout.includes(option), `help must include ${option}`);
  }
});
