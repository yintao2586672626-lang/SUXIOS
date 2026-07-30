import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('Meituan temporal runner uses a real authorized actor and never performs delivery', () => {
  const runner = readFileSync('scripts/run_meituan_temporal_refresh.php', 'utf8');

  assert.match(runner, /User::where\('id', \$userId\)->where\('status', 1\)->find\(\)/);
  assert.match(runner, /hasHotelPermission\(\$hotelId, 'can_view_online_data'\)/);
  assert.match(runner, /hasHotelPermission\(\$hotelId, 'can_fetch_online_data'\)/);
  assert.match(runner, /MeituanTemporalService/);
  assert.match(runner, /->refresh\(\$user, \$hotelId, \$asOfDate\)/);
  assert.match(runner, /->summary\(\$user, \$hotelId, \$asOfDate\)/);
  assert.match(runner, /'external_delivery_executed' => false/);
  assert.doesNotMatch(runner, /wecom|wechat|webhook/i);
});

test('Meituan temporal Windows task is scoped, serialized, and disabled-capable', () => {
  const register = readFileSync('scripts/register_meituan_temporal_task.ps1', 'utf8');

  assert.match(register, /\[int\]\$HotelId = 80/);
  assert.match(register, /\[int\]\$ActorUserId = 155/);
  assert.match(register, /@\('09:15', '13:00', '17:00', '21:00'\)/);
  assert.match(register, /-MultipleInstances IgnoreNew/);
  assert.match(register, /-LogonType Interactive/);
  assert.match(register, /php-win\.exe/);
  assert.match(register, /visible_window_expected = \$false/);
  assert.match(register, /-Hidden/);
  assert.match(register, /Disable-ScheduledTask/);
  assert.match(register, /external_delivery = \$false/);
  assert.doesNotMatch(register, /run_cloud_ota_pilot|send|webhook/i);
});
