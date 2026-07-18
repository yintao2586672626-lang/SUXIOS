import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/verify_online_daily_data_health.php', 'utf8');

test('online daily data health accepts explicit forecast dates but still rejects ordinary future rows', () => {
  assert.match(source, /date_default_timezone_set\('Asia\/Shanghai'\)/);
  assert.match(source, /allowed_future_forecast_rows/);
  assert.match(source, /invalid_future_date_rows/);
  assert.match(source, /forecast_rows_beyond_declared_window/);
  assert.match(source, /contains non-forecast future data_date rows/);
  assert.doesNotMatch(source, /contains future data_date rows\./);
});
