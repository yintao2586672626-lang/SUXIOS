import assert from 'node:assert/strict';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
  VERIFICATION_LEVELS,
  buildVerificationPlan,
  normalizeRepoPath,
  parseSmartVerificationArgs,
} from '../../scripts/verify_smart.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function commandIds(plan) {
  return plan.commands.map((entry) => entry.id);
}

test('daily documentation edit stays at Level 1 without project-wide guards', () => {
  const plan = buildVerificationPlan({
    level: VERIFICATION_LEVELS.daily,
    files: ['.agents/skills/suxi-test-guard/SKILL.md'],
    repoRoot,
  });

  assert.deepEqual(commandIds(plan), ['git-diff-check']);
  assert.equal(plan.weight, 'light');
  assert.match(plan.notes.join('\n'), /不运行项目级全量守卫/);
});

test('daily selected Node test gets syntax and the smallest related regression test', () => {
  const plan = buildVerificationPlan({
    level: VERIFICATION_LEVELS.daily,
    files: ['tests/automation/verify_smart.test.mjs'],
    repoRoot,
  });
  const ids = commandIds(plan);

  assert.ok(ids.includes('node-check:tests/automation/verify_smart.test.mjs'));
  assert.ok(ids.includes('node-test:tests/automation/verify_smart.test.mjs'));
  assert.ok(!ids.includes('verify-e2e-contracts'));
  assert.ok(!ids.includes('self-check'));
});

test('feature-level public JavaScript selects entry and contract guards', () => {
  const plan = buildVerificationPlan({
    level: VERIFICATION_LEVELS.feature,
    files: ['public/app-main.js'],
    repoRoot,
  });
  const ids = commandIds(plan);

  assert.ok(ids.includes('node-check:public/app-main.js'));
  assert.ok(ids.includes('verify-public-entry'));
  assert.ok(ids.includes('verify-e2e-contracts'));
  assert.ok(!ids.includes('self-check'));
});

test('feature-level route change selects route coverage and contract guard', () => {
  const plan = buildVerificationPlan({
    level: VERIFICATION_LEVELS.feature,
    files: ['route/app.php'],
    repoRoot,
  });
  const ids = commandIds(plan);

  assert.ok(ids.includes('php-lint:route/app.php'));
  assert.ok(ids.includes('verify-e2e-contracts'));
  assert.ok(ids.includes('verify-route-coverage'));
  assert.ok(!ids.includes('verify-public-entry'));
});

test('commit level uses the umbrella once and omits nested P0 and E2E commands', () => {
  const plan = buildVerificationPlan({
    level: VERIFICATION_LEVELS.commit,
    files: ['app/controller/OnlineData.php', 'public/app-main.js'],
    repoRoot,
  });
  const ids = commandIds(plan);

  assert.equal(ids.filter((id) => id === 'self-check').length, 1);
  assert.ok(ids.includes('phpunit-full'));
  assert.ok(ids.includes('node-full'));
  assert.ok(!ids.includes('verify:p0-guards'));
  assert.ok(!ids.includes('verify-e2e-contracts'));
  assert.match(plan.notes.join('\n'), /self:check 已包含 verify:p0-guards/);
});

test('conflicting levels and paths outside HOTEL fail clearly', () => {
  assert.throws(
    () => parseSmartVerificationArgs(['--feature', '--commit']),
    /Choose only one verification level/,
  );
  assert.throws(
    () => normalizeRepoPath('../outside.js', repoRoot, repoRoot),
    /outside HOTEL/,
  );
});
