import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), context, {
  filename: 'public/data-health-static.js',
});
const helpers = context.window.SUXI_DATA_HEALTH_STATIC;

const readyConfig = (overrides = {}) => ({
  config_id: 'config-1',
  system_hotel_id: 7,
  name: '测试配置',
  credential_status: 'ready',
  has_cookies: true,
  update_time: '2026-07-10 10:00:00',
  ...overrides,
});

test('OTA config effectiveness uses current-config persisted evidence', () => {
  const effective = helpers.otaConfigEffectState({
    config: readyConfig({
      collection_evidence_status: 'success_after_current_config',
      latest_platform_success_at: '2026-07-12 15:22:46',
      stored_platform_row_count: 12,
    }),
    platform: 'ctrip',
  });
  assert.equal(effective.status, 'effective');
  assert.equal(effective.text, '已生效');

  const historical = helpers.otaConfigEffectState({
    config: readyConfig({
      collection_evidence_status: 'historical_success_before_config_update',
      latest_platform_success_at: '2026-07-09 09:00:00',
      stored_platform_row_count: 3,
    }),
    platform: 'ctrip',
  });
  assert.equal(historical.status, 'pending');
  assert.equal(historical.text, '配置已更新，待验证');

  const firstSuccessPending = helpers.otaConfigEffectState({
    config: readyConfig({ collection_evidence_status: 'no_successful_storage' }),
    platform: 'ctrip',
  });
  assert.equal(firstSuccessPending.status, 'pending');
  assert.equal(firstSuccessPending.text, '待首次成功');

  const blocked = helpers.otaConfigEffectState({
    config: readyConfig({
      collection_evidence_status: 'success_after_current_config',
      latest_platform_success_at: '2026-07-12 15:22:46',
      stored_platform_row_count: 9,
    }),
    platform: 'meituan',
    missingFields: ['平台门店标识'],
  });
  assert.equal(blocked.status, 'blocked');
  assert.equal(blocked.text, '成功过，当前需处理');

  const unverified = helpers.otaConfigEffectState({
    config: readyConfig({ collection_evidence_status: 'unverified' }),
    platform: 'ctrip',
  });
  assert.equal(unverified.status, 'unknown');
  assert.equal(unverified.text, '证据未验证');
});

test('OTA config overview rows expose platform status and latest successful storage', () => {
  const rows = helpers.buildOtaConfigOverviewRows({
    platform: 'ctrip',
    hotelNameResolver: (_config, hotelId) => `门店${hotelId}`,
    configs: [
      readyConfig({
        config_id: 'effective',
        collection_evidence_status: 'success_after_current_config',
        latest_platform_success_at: '2026-07-12 15:22:46',
        latest_platform_data_date: '2026-07-11',
        stored_platform_row_count: 20,
      }),
      readyConfig({
        config_id: 'pending',
        system_hotel_id: 8,
        collection_evidence_status: 'no_successful_storage',
      }),
    ],
  });

  assert.equal(rows.length, 2);
  assert.equal(rows[0].effectStatus, 'pending', 'needs-attention rows should be listed first');
  assert.equal(rows[0].hotelId, '8', 'quick actions must retain the affected hotel context');
  assert.equal(rows[1].latestSuccessText, '2026-07-12 15:22');
  assert.match(rows[1].platformIdentityText, /待识别/);

  const summary = helpers.summarizeOtaConfigOverviewRows(rows);
  assert.deepEqual(
    { total: summary.total, effective: summary.effective, needsAttention: summary.needsAttention },
    { total: 2, effective: 1, needsAttention: 1 },
  );
  assert.equal(summary.latestSuccessText, '2026-07-12 15:22');
});

test('one-click acquisition page presents the two OTA config groups without the old issue table', () => {
  const fragment = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
  const appMain = readFileSync('public/app-main.js', 'utf8');
  const overviewStart = fragment.indexOf('data-testid="ota-direct-view-overview"');
  const overviewEnd = fragment.indexOf('data-testid="manual-one-click-fetch"', overviewStart);
  const overview = fragment.slice(overviewStart, overviewEnd);
  const editStart = appMain.indexOf('const editOtaConfigOverviewRow = async');
  const editEnd = appMain.indexOf('const otaDirectCtripStats = computed', editStart);
  const editHandler = appMain.slice(editStart, editEnd);

  assert.match(overview, /平台配置与最近成功/);
  assert.match(overview, /data-testid="ota-config-overview"/);
  assert.match(overview, /'ota-config-platform-' \+ group\.platform/);
  assert.match(overview, /最近成功：\{\{ row\.latestSuccessText \}\}/);
  assert.match(overview, /editOtaConfigOverviewRow\(row\)/);
  assert.doesNotMatch(overview, /<th[^>]*>问题<\/th>/);
  assert.doesNotMatch(overview, /otaDirectIssueRows/);
  assert.match(editHandler, /const hotelId = String\(row\?\.hotelId \|\| ''\)\.trim\(\)/);
  assert.match(editHandler, /findMeituanConfigByHotelId\(hotelId\)/);
  assert.match(editHandler, /meituanForm\.value\.hotelId = hotelId/);
  assert.match(editHandler, /findCtripConfigByHotelId\(hotelId\)/);
  assert.match(editHandler, /selectedCtripHotelId\.value = hotelId/);
});

test('config list APIs append hotel-scoped platform persistence evidence', () => {
  const otaConcern = readFileSync('app/controller/concern/OtaConfigConcern.php', 'utf8');
  const ctripConcern = readFileSync('app/controller/concern/OnlineDataRequestConcern.php', 'utf8');
  const meituanConcern = readFileSync('app/controller/concern/MeituanConfigConcern.php', 'utf8');

  assert.match(otaConcern, /whereIn\('system_hotel_id', array_values\(\$hotelIds\)\)/);
  assert.match(otaConcern, /where\('source', \$platform\)/);
  assert.match(otaConcern, /latest_platform_success_at/);
  assert.match(otaConcern, /success_after_current_config/);
  assert.match(otaConcern, /hotel_platform_persisted_rows_only/);
  assert.match(ctripConcern, /appendOtaConfigCollectionEvidence\(array_values\(\$list\), 'ctrip'\)/);
  assert.match(meituanConcern, /appendOtaConfigCollectionEvidence\(array_values\(\$list\), 'meituan'\)/);
});
