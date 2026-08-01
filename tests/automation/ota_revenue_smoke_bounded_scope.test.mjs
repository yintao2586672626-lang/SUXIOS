import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/verify_ota_revenue_metrics_smoke.php', 'utf8');

test('revenue metric smoke automatically uses a trusted bounded scope', () => {
  assert.match(source, /function has_bounded_metric_scope/);
  assert.match(source, /function latest_trusted_metric_scope/);
  assert.match(source, /blockingValidationStatuses/);
  assert.match(source, /quotedSqlList/);
  assert.match(source, /where\('readback_verified', 1\)/);
  assert.match(source, /bounded_scope_selected/);
  assert.match(source, /Cannot run an unbounded revenue metric smoke/);
});
