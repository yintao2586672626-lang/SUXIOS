import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  buildProbePrompt,
  exactJsonMatch,
  parseArgs,
  parseJsonEvents,
  scoreProbe,
} from '../../scripts/verify_codex_explicit_skill_model_load.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const scriptPath = path.join(repoRoot, 'scripts', 'verify_codex_explicit_skill_model_load.mjs');

test('matches JSON objects canonically while preserving array order', () => {
  assert.equal(exactJsonMatch(
    { modes: ['plan', 'retest'], loaded: true },
    { loaded: true, modes: ['plan', 'retest'] },
  ), true);
  assert.equal(exactJsonMatch(
    { loaded: true, modes: ['retest', 'plan'] },
    { loaded: true, modes: ['plan', 'retest'] },
  ), false);
});

test('parses Codex JSONL and rejects malformed event lines', () => {
  const events = parseJsonEvents([
    JSON.stringify({ type: 'thread.started', thread_id: 'thread-1' }),
    JSON.stringify({ type: 'item.completed', item: { type: 'agent_message', text: '{}' } }),
  ].join('\n'));
  assert.equal(events.length, 2);
  assert.throws(() => parseJsonEvents('{bad json}\n'), /Invalid Codex JSON event/u);
});

test('requires exact output, stable inputs, and zero tool events', () => {
  const expected = { loaded: true };
  const clean = scoreProbe({
    actual: expected,
    events: [{ type: 'item.completed', item: { type: 'agent_message' } }],
    expected,
    inputStable: true,
  });
  assert.equal(clean.status, 'pass');
  assert.equal(clean.toolEventCount, 0);

  const withTool = scoreProbe({
    actual: expected,
    events: [{ type: 'item.completed', item: { type: 'mcp_tool_call' } }],
    expected,
    inputStable: true,
  });
  assert.equal(withTool.status, 'fail');
  assert.ok(withTool.reasons.includes('tool_event_observed'));

  const changed = scoreProbe({
    actual: expected,
    events: [],
    expected,
    inputStable: false,
  });
  assert.ok(changed.reasons.includes('discovery_input_changed'));
});

test('builds a blind prompt with the invocation token but without expected JSON', () => {
  const expected = { loaded: true, heading: 'hidden expected value' };
  const prompt = buildProbePrompt(
    'suxi-os-toolkit:suxi-product-decision',
    'Return the level-one heading from the loaded body.',
  );
  assert.ok(prompt.includes('$suxi-os-toolkit:suxi-product-decision'));
  assert.equal(prompt.includes(JSON.stringify(expected)), false);
  assert.ok(prompt.includes('Do not read files, run commands, or call tools'));
});

test('CLI parsing requires a complete low-sensitivity model probe contract', () => {
  assert.throws(() => parseArgs([]), /--skill-root is required/u);
  const options = parseArgs([
    '--skill-root', '.agents/skills/suxi-product-decision',
    '--invocation-name', 'suxi-os-toolkit:suxi-product-decision',
    '--question', 'Return one body-derived fact.',
    '--expected-json', '{"loaded":true}',
  ]);
  assert.equal(options.model, 'gpt-5.5');
  assert.equal(options.reasoningEffort, 'low');
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
  assert.equal(payload.cases.length, 5);
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
    '--question',
    '--expected-json',
    '--model',
    '--self-test',
  ]) {
    assert.ok(help.stdout.includes(option), `help must include ${option}`);
  }
});
