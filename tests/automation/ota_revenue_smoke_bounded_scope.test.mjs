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

test('revenue metric smoke does not promote aliases or absent values into evidence', () => {
  const availableStart = source.indexOf("'available_room_nights' => [");
  const commissionStart = source.indexOf("'commission' => [", availableStart);
  const netStart = source.indexOf("'net_revenue' => [", commissionStart);
  const cancellationStart = source.indexOf("'cancellation' => [", netStart);
  const availableGroup = source.slice(availableStart, commissionStart);
  const netGroup = source.slice(netStart, cancellationStart);
  const expectedStart = source.indexOf('function expected_metrics');
  const expectedEnd = source.indexOf('$filters = parse_options', expectedStart);
  const expectedBlock = source.slice(expectedStart, expectedEnd);

  assert.doesNotMatch(availableGroup, /total_rooms_count|rooms_total/i);
  assert.doesNotMatch(netGroup, /settlement_amount/i);
  assert.match(source, /function raw_value_key_map/);
  assert.match(source, /\$child !== null/);
  assert.match(source, /rows_with_raw_value/);
  assert.doesNotMatch(expectedBlock, /sum_rows_with_fallback\(\$daily, 'room_revenue'/);
  assert.match(expectedBlock, /'room_revenue' => \$roomRevenueRows \? round\(\$roomRevenue, 2\) : null/);
  assert.match(expectedBlock, /'revenue' => \$revenueRows \? round\(\$revenue, 2\) : null/);
});
