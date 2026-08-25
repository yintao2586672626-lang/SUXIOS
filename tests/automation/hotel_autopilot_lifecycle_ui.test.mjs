import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const appMain = read('public/app-main.js');
const hotelPage = read('resources/frontend/templates/fragments/18-page-hotels.html');
const hotelDialog = read('resources/frontend/templates/fragments/40-dialog-hotel.html');

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

test('hotel list loads one batch lifecycle snapshot after the hotel scope is known', () => {
  const loader = sliceBetween(
    appMain,
    'const loadHotelAutomationLifecycles = async',
    'const hotelAutomationLifecycle = (hotel = {}) =>',
  );
  const hotelLoader = sliceBetween(
    appMain,
    'const loadHotels = async',
    'let startupHotelListLoadTimer = null',
  );

  assert.match(loader, /request\('\/hotels\/automation-lifecycle'/);
  assert.match(loader, /Array\.isArray\(res\.data\?\.items\)/);
  assert.match(loader, /const requestSeq = \+\+hotelAutomationLifecycleRequestSeq/);
  assert.match(loader, /requestSeq === hotelAutomationLifecycleRequestSeq/);
  assert.match(loader, /hotelAutomationLifecycleScope\(hotels\.value\) === requestedScope/);
  assert.match(loader, /expectedTenantId && normalized\.tenant_id !== expectedTenantId/);
  assert.match(hotelLoader, /await loadHotelAutomationLifecycles\(hotels\.value/);
});

test('late or cross-tenant lifecycle responses cannot replace the current hotel list scope', () => {
  const loader = sliceBetween(
    appMain,
    'const loadHotelAutomationLifecycles = async',
    'const hotelAutomationLifecycle = (hotel = {}) =>',
  );
  const finalGuard = loader.lastIndexOf('if (!scopeIsCurrent())');
  const commit = loader.indexOf('hotelAutomationLifecycleById.value = next');

  assert.ok(finalGuard >= 0);
  assert.ok(commit > finalGuard);
  assert.match(loader, /hotelScopeById\.has\(String\(hotelId\)\)/);
  assert.match(loader, /if \(expectedTenantId === undefined\) return/);
  assert.doesNotMatch(loader, /items\s*\[\s*0\s*\]/);
});

test('creation response is applied immediately and then refreshed through the batch endpoint', () => {
  const saver = sliceBetween(
    appMain,
    'const saveHotel = async',
    'const openHotelDeleteModal = (hotel) =>',
  );
  const applyAt = saver.indexOf('applyHotelAutomationLifecycle(savedHotelId, savedHotel.automation_lifecycle)');
  const reloadAt = saver.indexOf('await loadHotels({ force: true, includeInactive: true })');

  assert.ok(applyAt >= 0);
  assert.ok(reloadAt > applyAt);
});

test('desktop and mobile hotel cards show lifecycle status, progress and a truthful next action', () => {
  assert.equal((hotelPage.match(/:is="hotelAutomationLifecycleSummary"/g) || []).length, 2);
  assert.match(appMain, /'data-testid': 'hotel-autopilot-lifecycle'/);
  assert.match(appMain, /'data-testid': 'hotel-autopilot-status'/);
  assert.match(appMain, /'data-testid': 'hotel-autopilot-next-action'/);
  assert.match(appMain, /'aria-valuenow': progress\.percentage/);
  assert.match(appMain, /下一步尚未返回/);
  assert.match(appMain, /profile_draft_ready: '画像草稿待核验'/);
  assert.match(appMain, /if \(status === 'continuous_running'\)/);
  assert.doesNotMatch(appMain, /profile_draft_ready[^\n]+(?:成功|已完成)/);
});

test('final backend lifecycle states have explicit non-fallback labels', () => {
  assert.match(appMain, /awaiting_login: '等待平台授权登录'/);
  assert.match(appMain, /scheduled_waiting_first_collection: '已排期，等待首次可信采集'/);
  assert.match(appMain, /awaiting_analysis: '等待自动分析'/);
  assert.match(appMain, /awaiting_profile: '等待画像基础资料'/);
  assert.match(appMain, /blocked: '自动流程受阻'/);
  assert.match(appMain, /disabled: '自动运行已停用'/);
  assert.match(appMain, /if \(status === 'disabled'\)[\s\S]*?border-slate-200 bg-slate-100 text-slate-600/);
});

test('lifecycle next actions only route to existing human-facing surfaces', () => {
  const action = sliceBetween(
    appMain,
    'const openHotelAutomationLifecycleAction = async',
    '// 加载数据',
  );

  assert.match(action, /next_action_code === 'open_hotel_login'/);
  assert.match(action, /openHotelModal\(hotel, \{ onboarding: true, startStep: 'authorization' \}\)/);
  assert.match(action, /next_action_code === 'open_hotel_binding'/);
  assert.match(action, /openHotelModal\(hotel, \{ onboarding: true, startStep: 'verification' \}\)/);
  assert.match(action, /open_hotel_binding', 'open_hotel_login/);
  assert.match(action, /openHotelModal\(hotel, \{ expandOta: true \}\)/);
  assert.match(action, /next_action_code === 'provide_business_profile'[\s\S]*?openHotelModal\(hotel\)/);
  assert.match(action, /automationMonitorContractHotelId\.value = hotelId/);
  assert.match(action, /currentPage\.value = 'automation-monitor'/);
  assert.match(action, /operatingNetworkHotelId\.value = hotelId/);
  assert.match(action, /currentPage\.value = 'knowledge-center'/);
  assert.match(action, /next_action_code === 'open_profile_draft'/);
  assert.doesNotMatch(action, /request\(|apiRequest\(|fetch\(/);
});

test('hotel dialog collects optional manual business context without claiming verification', () => {
  assert.match(hotelDialog, /:is="hotelBusinessProfileEditor"/);
  assert.match(appMain, /'data-testid': 'hotel-business-profile'/);
  assert.match(appMain, /经营基础资料（可后补）/);
  assert.match(appMain, /人工提供 · 未核验/);
  assert.match(appMain, /不会被当作已核验事实，也不会直接执行平台动作/);
  assert.match(appMain, /hotelBackgroundProfileFields\.map/);
  assert.match(appMain, /hotelBackgroundProfileForm\.value\[field\.key\]/);
  for (const field of [
    'positioning',
    'targetGuests',
    'trafficContext',
    'priceSensitivity',
  ]) {
    assert.match(appMain, new RegExp(`key: '${field}'`));
  }
  assert.match(appMain, /hotelBackgroundProfileForm\.value\.operatingRemark/);
});
