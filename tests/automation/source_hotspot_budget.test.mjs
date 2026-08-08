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
      'line_ratchet_exceeded',
      'missing',
    ]);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('known debt is reported while its explicit no-growth ratchet remains enforceable', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-ratchet-'));
  try {
    fs.mkdirSync(path.join(root, 'app'), { recursive: true });
    fs.writeFileSync(path.join(root, 'app', 'known.php'), "one\ntwo\nthree\n", 'utf8');
    const passing = inspectSourceHotspotBudget(root, [
      { path: 'app/known.php', max_lines: 2, ratchet_max_lines: 3, boundary: 'fixture debt' },
    ]);
    assert.deepEqual(passing.failures, []);
    assert.equal(passing.debts[0].debt_lines, 1);

    fs.appendFileSync(path.join(root, 'app', 'known.php'), 'four\n', 'utf8');
    const growing = inspectSourceHotspotBudget(root, [
      { path: 'app/known.php', max_lines: 2, ratchet_max_lines: 3, boundary: 'fixture debt' },
    ]);
    assert.equal(growing.failures[0].reason, 'line_ratchet_exceeded');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('automatic discovery rejects a new oversized source absent from the reviewed budget list', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-discovery-'));
  try {
    fs.mkdirSync(path.join(root, 'app'), { recursive: true });
    fs.writeFileSync(path.join(root, 'app', 'unreviewed.php'), "one\ntwo\nthree\n", 'utf8');
    const result = inspectSourceHotspotBudget(root, [], {
      roots: ['app'],
      extensions: ['.php'],
      max_lines: 2,
    });
    assert.equal(result.failures[0].reason, 'unbudgeted_hotspot');
    assert.equal(result.failures[0].path, 'app/unreviewed.php');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('default discovery rejects an unbudgeted 4800-line app service', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-default-discovery-'));
  try {
    fs.mkdirSync(path.join(root, 'app', 'service'), { recursive: true });
    fs.writeFileSync(
      path.join(root, 'app', 'service', 'UnreviewedLargeService.php'),
      'line\n'.repeat(4_800),
      'utf8',
    );
    const result = inspectSourceHotspotBudget(root, []);
    assert.deepEqual(result.failures, [{
      path: 'app/service/UnreviewedLargeService.php',
      reason: 'unbudgeted_hotspot',
      actual_lines: 4_800,
      discovery_max_lines: 4_000,
    }]);
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

test('current source hotspots stay within reviewed no-growth ratchets and expose target debt', () => {
  const result = inspectSourceHotspotBudget(process.cwd());
  assert.deepEqual(result.failures, []);
  assert.ok(result.debts.length > 0);
  assert.ok(result.debts.some((item) => item.path === 'app/service/OtaLocalCollectorService.php'));
  assert.ok(result.debts.some((item) => item.path === 'app/service/RevenueAiOverviewService.php'));
});
