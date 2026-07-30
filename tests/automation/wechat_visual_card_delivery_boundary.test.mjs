import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('hourly visual card uses a stable hotel-scoped delivery boundary', async () => {
  const source = await readFile(
    new URL('../../scripts/send_test_wechat_visual_card.php', import.meta.url),
    'utf8',
  );
  assert.match(source, /CloudAutomationStateStore/);
  assert.match(source, /hourly_monitor_visual_card/);
  assert.match(source, /hourly-monitor-image:\{\$hotelId\}:\{\$robotId\}:\{\$hourKey\}/);
  assert.match(source, /\$scope = \$testOnly \? 'test_only' : 'operating_group'/);
  assert.match(source, /--robot-id 2/);
  assert.match(source, /beginDeliveryAttempt/);
  assert.match(source, /A render error must not leave a/);
  assert.match(source, /in_array\(\$existingStatus, \['sending', 'delivery_outcome_unknown'\], true\)/);
  assert.match(source, /\$record = \$state->recordDeliveryAttempt/);
  assert.match(source, /\(string\)\(\$delivery\['delivery_status'\] \?\? ''\) === 'sent' \? 0 : 2/);
  assert.ok(
    source.indexOf("if (proc_close($process) !== 0)") < source.indexOf('$record = $state->queueDelivery'),
    'render must finish before a visual-card delivery record is queued',
  );
});

test('cloud hourly monitor can send the matching visual card to its explicitly bound robot', async () => {
  const command = await readFile(
    new URL('../../app/command/BroadcastHourlyHotelMonitor.php', import.meta.url),
    'utf8',
  );
  const unit = await readFile(
    new URL('../../deploy/systemd/suxios-cloud-hotel-monitor.service', import.meta.url),
    'utf8',
  );
  assert.match(command, /addOption\('with-visual-card'/);
  assert.match(command, /sendVisualCard\(\$hotelId, \$robotId, \$testOnly\)/);
  assert.match(command, /--test-robot-id/);
  assert.match(command, /--robot-id/);
  assert.match(unit, /--test-robot-id=\$\{SUXIOS_MONITOR_TEST_ROBOT_ID\}/);
  assert.match(unit, /--with-visual-card/);
});
