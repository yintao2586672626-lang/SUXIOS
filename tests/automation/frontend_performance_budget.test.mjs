import assert from 'node:assert/strict';
import test from 'node:test';
import { evaluateFrontendBudget } from '../../scripts/lib/frontend_performance_budget.mjs';

test('evaluateFrontendBudget reports each exceeded entry limit', () => {
  const failures = evaluateFrontendBudget({
    index_bytes: 2_100_000,
    startup_gzip_bytes: 1_700_000,
    inline_script_bytes: 25_000,
    blocking_script_count: 1,
  }, {
    max_index_bytes: 2_000_000,
    max_startup_gzip_bytes: 1_600_000,
    max_inline_script_bytes: 20_000,
    max_blocking_script_count: 0,
  });
  assert.deepEqual(failures.map((item) => item.metric), [
    'index_bytes',
    'startup_gzip_bytes',
    'inline_script_bytes',
    'blocking_script_count',
  ]);
});

test('evaluateFrontendBudget passes metrics within every limit', () => {
  assert.deepEqual(evaluateFrontendBudget({
    index_bytes: 1_900_000,
    startup_gzip_bytes: 1_500_000,
    inline_script_bytes: 10_000,
    blocking_script_count: 0,
  }), []);
});
