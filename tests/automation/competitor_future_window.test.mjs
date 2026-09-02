import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const service = readFileSync('app/service/CompetitorFutureWindowService.php', 'utf8');
const eventFeedService = readFileSync('app/service/CompetitorEventFeedService.php', 'utf8');
const controller = readFileSync('app/controller/CompetitorApi.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const ctripStaticLoader = readFileSync('public/ctrip-static-loader.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');

test('future window reuses strict event facts and never creates pricing actions', () => {
  assert.match(service, /CompetitorEventFeedService/);
  assert.match(eventFeedService, /public function buildRange\(/);
  assert.match(eventFeedService, /where\('check_in_date', \$stayDate\)[\s\S]*limit\(\$perDayLimit\)[\s\S]*select\(\)/);
  assert.match(eventFeedService, /COUNT\(\*\) AS matched_count/);
  assert.match(service, /DEFAULT_DAYS = 21/);
  assert.match(service, /MAX_DAYS = 31/);
  assert.match(service, /room_type_mapping_missing/);
  assert.match(service, /blocked_by_room_type_mapping/);
  assert.match(service, /price_suggestion_created' => false/);
  assert.match(service, /auto_write_ota' => false/);
  assert.doesNotMatch(service, /insert|update|delete|price_suggestions/i);
  assert.match(controller, /public function futureWindow\(\)/);
  assert.match(controller, /'can_view_online_data'/);
  assert.match(routes, /api\/competitor\/future-window/);
});

test('Ctrip competition workspace loads and renders a truthful 21-day matrix', () => {
  assert.match(appMain, /createCompetitorFutureWindowController/);
  assert.match(appMain, /createCompetitorFutureWindowController\(\{ ref, computed, request: \(\.\.\.args\) => request\(\.\.\.args\)/);
  assert.match(ctripStaticLoader, /const competitorFutureWindow = ref\(null\)/);
  assert.match(ctripStaticLoader, /\/competitor\/future-window\?/);
  assert.match(ctripStaticLoader, /days: '21'/);
  assert.match(ctripStaticLoader, /未来21天竞争事实返回的门店、平台或日期范围不一致/);
  assert.match(appMain, /loadCtripCompetitionWorkspace[\s\S]*loadCompetitorFutureWindow/);
  assert.match(template, /<ctrip-competitor-future-window-panel[^>]+data-testid="ctrip-competitor-future-window"[^>]+:model="competitorFutureWindowPanelModel"/);
  assert.match(appMain, /CtripCompetitorFutureWindowPanel/);
  assert.match(ctripStaticLoader, /data-testid': 'ctrip-competitor-future-window-loading'/);
  assert.match(ctripStaticLoader, /data-testid': 'ctrip-competitor-future-window-error'/);
  assert.match(ctripStaticLoader, /data-testid': 'ctrip-competitor-future-window-empty'/);
  assert.match(ctripStaticLoader, /data-testid': 'ctrip-competitor-future-window-day'/);
  assert.match(ctripStaticLoader, /房型尚未完成人工映射/);
  assert.match(ctripStaticLoader, /不生成调价建议/);
  assert.match(ctripStaticLoader, /缺失保持缺失，不以零价或零库存补位/);
});

test('future window controller rejects hotel drift and keeps missing terms missing', async () => {
  const sandbox = { window: {}, URL, URLSearchParams, console };
  vm.runInNewContext(ctripStaticLoader, sandbox);
  const ref = value => ({ value });
  const computed = getter => ({ get value() { return getter(); } });
  let hotelId = '80';
  let finishRequest;
  const controller = sandbox.window.SUXI_CTRIP_STATIC.createCompetitorFutureWindowController({
    ref,
    computed,
    request: () => new Promise(resolve => { finishRequest = resolve; }),
    getSystemHotelId: () => hotelId,
    getToday: () => '2026-08-29',
  });
  const pending = controller.loadCompetitorFutureWindow();
  hotelId = '81';
  finishRequest({ code: 200, data: {
    system_hotel_id: 80,
    platform: 'ctrip',
    start_date: '2026-08-29',
    days: 21,
    matrix: [],
  } });
  assert.equal(await pending, null);
  assert.equal(controller.competitorFutureWindow.value, null);

  controller.competitorFutureWindow.value = {
    matrix: [{ stay_date: '2026-08-29', status: 'partial', cells: [{ price: 299 }] }],
  };
  assert.match(controller.competitorFutureWindowDayText.value, /住晚未取得/);
  assert.match(controller.competitorFutureWindowDayText.value, /币种未取得 · 价格数值 299/);
  assert.match(controller.competitorFutureWindowDayText.value, /覆盖 未取得\/未取得 天/);
  assert.match(controller.competitorFutureWindowDayText.value, /事件 未取得 · 可售 未取得 · 可比价 未取得/);
  assert.doesNotMatch(controller.competitorFutureWindowDayText.value, /1晚|CNY 299/);
});
