import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const runner = readFileSync('scripts/bootstrap_cloud_collection_plan.php', 'utf8');

test('collection plan bootstrap is dry-run by default and never collects or sends', () => {
  assert.match(runner, /array_key_exists\('execute', \$options\)/);
  assert.match(runner, /'mode' => 'dry_run'/);
  assert.match(runner, /HotelCollectionBindingReceiptService/);
  assert.match(runner, /HotelCollectionPlanService/);
  assert.match(runner, /'activate' => true/);
  assert.match(runner, /'readback_verified' => true/);
  assert.match(runner, /'execution_authorized' => true/);
  assert.match(runner, /'collection_started' => false/);
  assert.match(runner, /'message_sent' => false/);
  assert.doesNotMatch(runner, /dispatchVerifiedCapture|webhook|Wechat|gatewayRequest|collection\/open/);
});
