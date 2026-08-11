import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/verify_online_daily_data_health.php', 'utf8');
const inventoryCommon = readFileSync('scripts/lib/ota_data_inventory_common.php', 'utf8');

test('online daily data health accepts explicit forecast dates but still rejects ordinary future rows', () => {
  assert.match(source, /date_default_timezone_set\('Asia\/Shanghai'\)/);
  assert.match(source, /allowed_future_forecast_rows/);
  assert.match(source, /invalid_future_date_rows/);
  assert.match(source, /forecast_rows_beyond_declared_window/);
  assert.match(source, /contains non-forecast future data_date rows/);
  assert.doesNotMatch(source, /contains future data_date rows\./);
});

test('online daily data health treats automatic P0 traffic snapshots as task scoped only', () => {
  for (const contract of [source, inventoryCommon]) {
    assert.match(contract, /IN \('traffic', 'flow', 'conversion'\)/);
    assert.match(contract, /COALESCE\(sync_task_id, 0\) > 0/);
    assert.match(contract, /NOT IN \('manual', 'import_json', 'import_csv', 'import_excel'\)/);
    assert.match(contract, /END AS task_snapshot_key/);
    assert.match(contract, /GROUP BY[\s\S]*task_snapshot_key/);
  }
});
