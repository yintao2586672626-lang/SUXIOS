import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const routes = readRouteContractSource(process.cwd());

test('frozen strategy, expansion and transfer groups keep auth before their read-only boundary', () => {
  for (const group of ['strategy', 'expansion', 'transfer']) {
    const start = routes.indexOf(`Route::group('api/${group}'`);
    const end = routes.indexOf('\n', routes.indexOf('})->middleware', start));
    const block = routes.slice(start, end);
    assert.ok(start >= 0);
    assert.match(block, /Auth::class\)->middleware\(\\app\\middleware\\RetiredFeatureReadOnly::class/);
    assert.match(block, /Route::get\('\/records'/);
    assert.match(block, /Route::get\('\/records\/:id'/);
  }
});

test('all feasibility mutations are retired and existing history reads keep their controllers', () => {
  const writeLines = routes.split('\n').filter(line => /Route::(?:post|put|patch|delete)\('\/feasibility-report/.test(line));
  assert.equal(writeLines.length, 4);
  for (const line of writeLines) assert.match(line, /RetiredFeatureReadOnly::class/);
  assert.match(routes, /Route::get\('\/feasibility-report\/detail\/:id', 'Agent\/feasibilityReportDetail'\)/);
  assert.match(routes, /Route::get\('\/feasibility-report\/list', 'Agent\/feasibilityReportList'\)/);
});

test('active quant simulation, opening and operating execution keep their own workflows', () => {
  for (const group of ['simulation', 'opening', 'operation']) {
    const start = routes.indexOf(`Route::group('api/${group}'`);
    assert.ok(start >= 0);
    const end = routes.indexOf('\n', routes.indexOf('})->middleware', start));
    assert.doesNotMatch(routes.slice(start, end), /RetiredFeatureReadOnly/);
  }
});
