import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyExecution,
  normalizeLocalExecutionText,
  parsePhpunitSummary,
} from '../../scripts/run_hotel_diagnostic_l8_batch.mjs';

test('PHPUnit summary parser preserves every non-success counter', () => {
  const summary = parsePhpunitSummary(
    'Tests: 1, Assertions: 2, Skipped: 3, Incomplete: 4, Risky: 5, Warnings: 6.',
    '',
  );

  assert.deepEqual(summary, {
    parsed: true,
    tests: 1,
    assertions: 2,
    failures: 0,
    errors: 0,
    skipped: 3,
    incomplete: 4,
    risky: 5,
    warnings: 6,
  });
});

test('no non-success PHPUnit counter can be classified as partial', () => {
  const cleanSummary = {
    parsed: true,
    tests: 1,
    assertions: 2,
    failures: 0,
    errors: 0,
    skipped: 0,
    incomplete: 0,
    risky: 0,
    warnings: 0,
  };

  assert.equal(classifyExecution(null, 0, cleanSummary).status, 'partial');
  for (const issue of ['errors', 'skipped', 'incomplete', 'risky', 'warnings']) {
    const classification = classifyExecution(null, 0, { ...cleanSummary, [issue]: 1 });
    assert.equal(classification.status, 'blocked', `${issue} must block promotion`);
    assert.match(classification.reason, new RegExp(`phpunit_${issue}:1`, 'u'));
  }
  assert.equal(
    classifyExecution(null, 1, { ...cleanSummary, failures: 1 }).status,
    'fail',
  );
});

test('persisted diagnostic output replaces the local workspace path', () => {
  const localPath = `${process.cwd()}\\phpunit.xml`;
  const normalized = normalizeLocalExecutionText(`Configuration: ${localPath}`);

  assert.equal(normalized, 'Configuration: <project>/phpunit.xml');
  assert.doesNotMatch(normalized, /[A-Z]:\\/u);
});
