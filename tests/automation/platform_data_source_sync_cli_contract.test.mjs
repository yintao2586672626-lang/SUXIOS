import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = readFileSync(path.join(repoRoot, 'scripts', 'run_platform_data_source_sync.php'), 'utf8');

test('operator sync accepts only platform-specific core capture sections', () => {
  assert.match(source, /'capture-sections:'/);
  assert.match(source, /'ctrip' => \['business_overview', 'traffic_report'\]/);
  assert.match(source, /'meituan' => \['orders', 'traffic'\]/);
  assert.match(source, /array_diff\(\$captureSections, \$allowedSections\)/);
  assert.match(source, /capture_sections_invalid_for_source_platform/);
});

test('validated capture sections stay bounded and are reported in the safe summary', () => {
  const validation = source.indexOf("capture_sections_invalid_for_source_platform");
  const boundedOption = source.indexOf("$syncOptions['bounded_capture_sections']");
  const syncCall = source.indexOf('syncDataSource($user, $sourceId, $syncOptions)');

  assert(validation >= 0 && boundedOption > validation && syncCall > boundedOption);
  assert.match(source, /\$syncOptions\['capture_sections'\] = \$boundedSections/);
  assert.match(source, /'capture_sections' => \$captureSections/);
});

test('operator sync keeps business date and data period mutually consistent', () => {
  assert.match(source, /'data-period:'/);
  assert.match(source, /new \\DateTimeZone\('Asia\/Shanghai'\)/);
  assert.match(source, /\$targetDay < \$today \? 'historical_daily' : 'realtime_snapshot'/);
  assert.match(source, /data_period_target_date_mismatch/);
  assert.match(source, /'data_period' => \$dataPeriod/);
  assert.match(source, /'snapshot_time' => \$dataPeriod === 'realtime_snapshot'/);
});

test('operator sync reads identity proof from bounded full Ctrip captures', () => {
  assert.match(source, /\$maxIdentityCaptureBytes = 16 \* 1024 \* 1024;/);
  assert.match(source, /\$resolvedCaptureOutputSize <= \$maxIdentityCaptureBytes/);
  assert.match(source, /'platform_identity_validation' => \$decodedCapture\['platform_identity_validation'\] \?\? null/);
});

test('operator sync reports and exits from the exact run readback instead of aggregate task counts', () => {
  assert.match(source, /\$receipt = is_array\(\$taskStats\['run_readback'\]/);
  assert.match(source, /->where\('data_period', \$dataPeriod\)/);
  assert.match(source, /\$receiptRowReadbackCount = \$taskId > 0 && \$targetRowIds !== \[\]/);
  assert.match(source, /->whereIn\('id', \$targetRowIds\)/);
  assert.match(source, /->where\('system_hotel_id', \(int\)\(\$receipt\['system_hotel_id'\]/);
  assert.match(source, /->where\('platform', \(string\)\(\$receipt\['platform'\]/);
  assert.match(source, /\$exactReadbackVerified =/);
  assert.match(source, /&& \$receiptRowReadbackCount === count\(\$targetRowIds\)/);
  assert.doesNotMatch(source, /&& \$targetDateReadbackCount === count\(\$targetRowIds\)/);
  assert.match(source, /'task_saved_count' =>/);
  assert.match(source, /'target_saved_count' => count\(\$targetRowIds\)/);
  assert.match(source, /'target_readback_count' => \$receiptRowReadbackCount/);
  assert.match(source, /'target_date_readback_count' => \$targetDateReadbackCount/);
  assert.match(source, /->where\('tenant_id', \$sourceTenantId\)/);
  assert.match(source, /->where\('system_hotel_id', \$sourceHotelId\)/);
  assert.match(source, /->where\('platform', \$sourcePlatform\)/);
  assert.match(source, /->where\('source', \$sourcePlatform\)/);
  assert.match(source, /->where\('data_period', \$dataPeriod\)/);
  assert.match(source, /\$exactReadbackVerified =/);
  assert.match(source, /\(int\)\(\$receipt\['system_hotel_id'\] \?\? 0\) === \$sourceHotelId/);
  assert.match(source, /\(string\)\(\$receipt\['platform'\] \?\? ''\)/);
  assert.match(source, /'task_saved_count' =>/);
  assert.match(source, /'target_saved_count' => count\(\$targetRowIds\)/);
  assert.match(source, /'target_readback_count' => \$targetDateReadbackCount/);
  assert.match(source, /&& \$exactReadbackVerified \? 0 : 2/);
});
