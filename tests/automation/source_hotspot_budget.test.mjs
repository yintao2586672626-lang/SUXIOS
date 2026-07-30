import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
  inspectSourceHotspotBudget,
  SOURCE_HOTSPOT_BUDGETS,
  sourceLineCount,
} from '../../scripts/lib/source_hotspot_budget.mjs';
import { SOURCE_CONCERN_PATHS } from '../../scripts/lib/source_aggregate.mjs';

test('source line counting is newline-style independent', () => {
  assert.equal(sourceLineCount('one\ntwo\n'), 2);
  assert.equal(sourceLineCount('one\r\ntwo\r\n'), 2);
  assert.equal(sourceLineCount('one\rtwo'), 2);
  assert.equal(sourceLineCount(''), 0);
});

test('hotspot budget reports missing and oversized sources', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-budget-'));
  try {
    fs.mkdirSync(path.join(root, 'app'), { recursive: true });
    fs.writeFileSync(path.join(root, 'app', 'small.php'), "one\ntwo\n", 'utf8');
    const result = inspectSourceHotspotBudget(root, [
      { path: 'app/small.php', max_lines: 1, boundary: 'fixture' },
      { path: 'app/missing.php', max_lines: 1, boundary: 'fixture' },
    ]);

    assert.deepEqual(result.failures.map((item) => item.reason), [
      'line_budget_exceeded',
      'missing',
    ]);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('every hotspot parent and extracted concern has a committed budget', () => {
  const budgetPaths = new Set(SOURCE_HOTSPOT_BUDGETS.map((item) => item.path));
  for (const [parent, concerns] of Object.entries(SOURCE_CONCERN_PATHS)) {
    assert.equal(budgetPaths.has(parent), true, `missing parent budget: ${parent}`);
    for (const concern of concerns) {
      assert.equal(budgetPaths.has(concern), true, `missing concern budget: ${concern}`);
    }
  }
});

test('current source hotspots stay within their post-split budgets', () => {
  const result = inspectSourceHotspotBudget(process.cwd());
  assert.deepEqual(result.failures, []);
});
