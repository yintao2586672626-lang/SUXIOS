import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync('app/service/OtaStandardEtlService.php', 'utf8');
const controller = readFileSync('app/controller/OtaStandard.php', 'utf8');

test('OTA ETL paginates the full scoped dataset instead of silently truncating at the first limit', () => {
  assert.match(source, /while \(true\)[\s\S]*->limit\(\$offset, \$pageSize\)[\s\S]*\$offset \+= \$pageSize/);
  assert.match(source, /exceeds the safe row window; narrow the hotel\/date\/platform scope instead of using truncated metrics/);
  assert.doesNotMatch(source, /->limit\(\$limit\)->select\(\)->toArray\(\)/);
});

test('OTA ETL has explicit competitor, provenance, hotel-binding, and manual-override trust guards', () => {
  assert.match(source, /non_self_competitor_scope/);
  assert.match(source, /provenance_missing/);
  assert.match(source, /system_hotel_id_missing/);
  assert.match(source, /manual_override_unverified/);
  assert.match(source, /hotel_binding|wrong_hotel|mismatch/);
  assert.match(source, /readback_verified/);
  assert.match(source, /readback_unverified/);
  assert.match(source, /blockingValidationStatuses/);
});

test('formal OTA metrics, analysis and optimizer entries enforce the server-owned strict readback gate', () => {
  assert.match(controller, /private function decisionFilters\(\): array[\s\S]*\$filters\['strict_readback_only'\] = true/);

  const methodBody = name => {
    const start = controller.indexOf(`public function ${name}(`);
    assert.notEqual(start, -1, `missing controller method: ${name}`);
    const end = controller.indexOf('\n    public function ', start + 1);
    return controller.slice(start, end === -1 ? controller.length : end);
  };
  for (const name of [
    'revenueMetrics',
    'analysis',
    'operationOptimizer',
    'createOperationOptimizerExecutionIntent',
  ]) {
    assert.match(methodBody(name), /\$this->decisionFilters\(\)/, `${name} must use strict decision filters`);
  }
  assert.match(methodBody('dataset'), /\$this->filters\(\)/, 'diagnostic ETL must retain non-strict visibility');
  assert.doesNotMatch(methodBody('dataset'), /decisionFilters/);
});
