import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = relativePath => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('Ctrip public profile UI exposes ID-only add and truthful static-data boundary', () => {
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
  const panel = template.split('<!-- 携程公开酒店档案 -->')[1]?.split('<!-- 流量概要 -->')[0] || '';

  assert.match(template, /data-testid="ctrip-public-profile-tab"/);
  assert.match(panel, /data-testid="ctrip-public-profile-panel"/);
  assert.match(panel, /data-testid="ctrip-public-profile-id-input"/);
  assert.match(panel, /data-testid="ctrip-public-profile-add"/);
  assert.match(panel, /添加并自动补全/);
  assert.match(panel, /<option value="self">本店<\/option>/);
  assert.match(panel, /<option value="competitor">竞品酒店<\/option>/);
  assert.match(panel, /客房总数是酒店静态资料，不等于任一日期的可售库存/);
  assert.match(panel, /不采集动态价格、订单、流量或 PMS 数据/);
  assert.doesNotMatch(panel, /Cookie|spidertoken|Authorization/);
});

test('Ctrip public profile UI calls scoped add, read, and refresh endpoints', () => {
  const source = read('public/app-main.js');
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');

  assert.match(source, /const normalizeCtripPublicHotelIdInput =/);
  assert.match(source, /hostname === 'hotels\.ctrip\.com'/);
  assert.match(source, /\/online-data\/ctrip\/public-profiles\?system_hotel_id=/);
  assert.match(source, /request\('\/online-data\/ctrip\/public-profiles\/add'/);
  assert.match(source, /request\('\/online-data\/ctrip\/public-profiles\/sync'/);
  assert.match(source, /role,\s+replace,/);
  assert.match(source, /businessContext: \{ hotelId: systemHotelId, platform: 'ctrip' \}/);
  assert.match(source, /binding_saved_collection_failed/);
  assert.match(source, /mutationSeq !== ctripPublicProfileMutationSeq[\s\S]*systemHotelId !== String\(selectedCtripHotelId\.value/);
  assert.match(template, /ctrip-public-profile-hotel-select[^>]*:disabled="ctripPublicProfileLoading \|\| ctripPublicProfileSaving \|\| ctripPublicProfileRefreshing"/);
});

test('Ctrip competition-circle operations are reachable from the public-profile page with truthful evidence states', () => {
  const source = read('public/app-main.js');
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
  const panel = template.split('<!-- 携程公开酒店档案 -->')[1]?.split('<!-- 流量概要 -->')[0] || '';

  assert.match(panel, /data-testid="ctrip-competitive-operations-panel"/);
  assert.match(panel, /data-testid="ctrip-competitive-operations-refresh"/);
  assert.match(panel, /携程 OTA 竞争圈口径/);
  assert.match(panel, /可用于诊断/);
  assert.match(panel, /排除记录/);
  assert.match(source, /const loadCtripCompetitiveOperations = async/);
  assert.match(source, /\/online-data\/ctrip\/competitive-operations\?system_hotel_id=/);
  assert.match(source, /start_date=\$\{encodeURIComponent\(startDate\)\}/);
  assert.match(source, /end_date=\$\{encodeURIComponent\(endDate\)\}/);
  assert.match(source, /businessContext: \{ hotelId: systemHotelId, platform: 'ctrip' \}/);
  assert.match(source, /ctripCompetitiveOperationsStatusText/);
});

test('ID-only backend route persists a dedicated public binding and retains competitors for refresh', () => {
  const routes = read('route/app.php');
  const concern = read('app/controller/concern/CtripCompetitiveOperationsConcern.php');
  const service = read('app/service/CtripPublicHotelProfileService.php');

  assert.match(routes, /Route::post\('\/ctrip\/public-profiles\/add', 'OnlineData\/addCtripPublicProfile'\)/);
  assert.match(concern, /checkActionPermission\('can_fetch_online_data'\)/);
  assert.match(concern, /currentUserCanMaintainOtaConfig\(\$systemHotelId\)/);
  assert.match(service, /PUBLIC_BINDING_CONFIG_KEY = 'ctrip_public_hotel_bindings'/);
  assert.match(service, /public function addByHotelId\(/);
  assert.match(service, /'binding_saved_collection_failed'/);
  assert.match(service, /Db::name\('competitor_hotel'\)/);
  assert.match(service, /'room_count_semantics' => self::ROOM_COUNT_SEMANTICS/);
  assert.match(service, /archived_self/);
  assert.match(service, /isAllowedFinalUrl\(\$finalUrl, \$otaHotelId\)/);
  assert.match(service, /if \(\$this->looksBlocked\(\$body\)\)/);
});
