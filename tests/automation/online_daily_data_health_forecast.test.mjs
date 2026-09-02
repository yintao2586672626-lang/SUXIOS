import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/verify_online_daily_data_health.php', 'utf8');
const inventoryCommon = readFileSync('scripts/lib/ota_data_inventory_common.php', 'utf8');

test('online daily data health accepts versioned forecast and on-books dates but rejects unsupported future rows', () => {
  assert.match(source, /date_default_timezone_set\('Asia\/Shanghai'\)/);
  assert.match(source, /allowed_future_forecast_rows/);
  assert.match(source, /allowed_future_on_books_rows/);
  assert.match(source, /future_on_books/);
  assert.match(source, /snapshot_batches/);
  assert.match(source, /missing_snapshot_identity_rows/);
  assert.match(source, /invalid_future_date_rows/);
  assert.match(source, /unversioned_forecast_rows/);
  assert.match(source, /forecast_rows_beyond_declared_window/);
  assert.match(source, /future rows without a supported period role/);
  assert.match(source, /forecast rows without snapshot identity/);
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

test('online daily data health excludes only explicit duplicate quarantines and rejects mislabeled values', () => {
  assert.match(source, /duplicate_business_key_superseded/);
  assert.match(source, /quarantined_duplicate_rows/);
  assert.match(source, /unsafe_numeric_status_rows/);
  assert.match(source, /out-of-domain values not marked unsafe/);
  assert.match(source, /recent rows without platform identity/);
});
