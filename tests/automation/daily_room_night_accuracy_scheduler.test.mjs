import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('daily T+1 room-night runner is focused, authorized, idempotent-aware, and write-free', () => {
  const runner = readFileSync('scripts/run_daily_room_night_accuracy.php', 'utf8');

  assert.match(runner, /User::where\('id', \$userId\)->where\('status', 1\)->find\(\)/);
  assert.match(runner, /hasHotelPermission\(\$hotelId, 'can_view_online_data'\)/);
  assert.match(runner, /TemporalInsightService/);
  assert.match(runner, /1,\s*'ota_room_nights'\s*\)/);
  assert.match(runner, /dailyRoomNightAccuracyReceipt\(\$hotelId, \$asOfDate\)/);
  assert.match(runner, /idempotent_readback_verified/);
  assert.match(runner, /SUXIOS_DAILY_ROOM_NIGHT_ACCURACY=/);
  assert.match(runner, /'external_action_executed' => false/);
  assert.match(runner, /'automatic_price_write' => false/);
  assert.match(runner, /'ota_write_executed' => false/);
  assert.doesNotMatch(runner, /writePrice|executePrice|writeOta|webhook|wecom|wechat/i);
});

test('daily T+1 room-night Windows task is one-hotel, serialized, enabled-capable, and read back', () => {
  const register = readFileSync('scripts/register_daily_room_night_accuracy_task.ps1', 'utf8');

  assert.match(register, /SUXIOS Daily T1 OTA Room Nights H\$HotelId/);
  assert.match(register, /\[int\]\$HotelId = 80/);
  assert.match(register, /\[int\]\$ActorUserId = 155/);
  assert.match(register, /\[string\]\$DailyAt = '21:30'/);
  assert.match(register, /New-ScheduledTaskTrigger -Daily -At \$DailyAt/);
  assert.match(register, /-MultipleInstances IgnoreNew/);
  assert.match(register, /-LogonType Interactive/);
  assert.match(register, /php-win\.exe/);
  assert.match(register, /-Hidden/);
  assert.match(register, /Get-ScheduledTaskInfo/);
  assert.match(register, /readbackVerified/);
  assert.match(register, /automatic_price_write = \$false/);
  assert.doesNotMatch(register, /Start-ScheduledTask|webhook|wecom|wechat/i);
});

test('home requests only T+1 OTA room nights and labels the immature result as observation', () => {
  const appMain = readFileSync('public/app-main.js', 'utf8');

  assert.match(
    appMain,
    /new URLSearchParams\(\{ history_days: '30', future_days: '1', metric_key: 'ota_room_nights' \}\)/,
  );
  assert.match(
    appMain,
    /JSON\.stringify\(\{ hotel_id: Number\(hotelId\), future_days: 1, metric_key: 'ota_room_nights' \}\)/,
  );
  assert.match(appMain, /isDailyRoomNightFocus[\s\S]*?'观察中'/);
  assert.match(appMain, /daily_accuracy_receipt/);
});
