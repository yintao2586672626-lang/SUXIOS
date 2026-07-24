import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/verify_e2e_contracts.mjs', 'utf8');

test('E2E verifier summarizes failures only after its final contract checks', () => {
  const failureSummary = source.lastIndexOf('const failures = checks.filter((check) => !check.ok);');
  assert.ok(failureSummary >= 0, 'failure summary must exist');

  for (const marker of [
    "requireText('tests/automation/async-page-guard.spec.js', 'installHistoryFixtures'",
    "requireText('tests/automation/async-page-guard.spec.js', 'waitForResponse'",
    "requireNoText('tests/automation/async-page-guard.spec.js', 'waitForTimeout'",
  ]) {
    const checkIndex = source.lastIndexOf(marker);
    assert.ok(checkIndex >= 0, `missing final guard: ${marker}`);
    assert.ok(checkIndex < failureSummary, `guard must run before failure summary: ${marker}`);
  }

  assert.ok(
    failureSummary < source.lastIndexOf('if (failures.length)'),
    'failure summary must be consumed after it is computed',
  );
});
