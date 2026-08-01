import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const repairSource = readFileSync(new URL('../../scripts/repair_ota_data_integrity.php', import.meta.url), 'utf8');

test('OTA integrity repair retains non-current config versions instead of deleting them', () => {
  assert.match(repairSource, /\$preserved\[\$configId\] = \$config;/);
  assert.match(repairSource, /\$config\['config_status'\] = 'history';/);
  assert.doesNotMatch(repairSource, /\$collapsed\[\$configId\] = \$primary;/);
});

test('OTA integrity repair reports active platform hotel ID conflicts', () => {
  assert.match(repairSource, /'active_platform_hotel_binding_conflicts'/);
  assert.match(repairSource, /'active_system_hotel_binding_conflicts'/);
});

test('OTA integrity repair corrects canonical IDs and retires wrong-owner configs as history', () => {
  assert.match(repairSource, /'platform_binding_repairs'/);
  assert.match(repairSource, /wrong_owner_marked_history/);
  assert.match(repairSource, /canonical_id_corrected/);
});

test('OTA integrity repair keeps hidden history counts idempotent', () => {
  assert.match(repairSource, /legacy_history_count/);
  assert.match(repairSource, /\$legacyHiddenHistoryCount \+ \$materialHistoryCount/);
});

test('operator-confirmed Ctrip identity uses the real UTF-8 hotel name', () => {
  assert.match(repairSource, /=== '西安天诚'/);
});
