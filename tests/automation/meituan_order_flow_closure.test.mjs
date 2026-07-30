import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const fragment = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const staticSource = readFileSync('public/meituan-static.js', 'utf8');
const captureSource = readFileSync('scripts/meituan_browser_capture.mjs', 'utf8');
const standardSource = readFileSync('scripts/lib/ota_capture_standard.mjs', 'utf8');
const persistenceSource = readFileSync('app/controller/concern/MeituanCapturedDataConcern.php', 'utf8');

const sandbox = { console, window: {} };
vm.runInNewContext(`${staticSource}\nthis.__api = window.SUXI_MEITUAN_STATIC;`, sandbox);
const api = sandbox.__api;

const sliceBetween = (start, end) => {
  const startIndex = appMain.indexOf(start);
  const endIndex = appMain.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return appMain.slice(startIndex, endIndex);
};

test('Meituan owner navigation exposes one simple order flow page with truthful periods', () => {
  assert.match(fragment, /openMeituanManualTab\('meituan-order-flow'\)/);
  assert.match(fragment, /data-testid="meituan-order-flow-page"/);
  assert.match(fragment, /流失订单和流入订单放在同一页/);
  assert.match(fragment, /美团此功能不提供今日实时/);
  assert.match(fragment, /v-for="period in meituanOrderFlowPeriods"/);
  assert.match(fragment, /refreshMeituanOrderFlowData/);
  assert.match(fragment, /meituanOrderFlowActive\.rows/);
  assert.match(appMain, /data_type:\s*'order_flow'/);
  assert.match(appMain, /request\('\/online-data\/fetch-meituan-order-flow'/);
  assert.doesNotMatch(appMain, /const refreshMeituanOrderFlowData[\s\S]*?runMeituanBrowserCaptureForSections\(\['order_flow'\]/);
});

test('order flow reads reject stale scope and fetch success requires matching database readback', () => {
  const loader = sliceBetween('const loadMeituanOrderFlowData = async (options = {}) => {', '\n\n            const selectMeituanOrderFlowPeriod');
  const refresh = sliceBetween('const refreshMeituanOrderFlowData = async () => {', '\n\n            const runMeituanBrowserProfileLoginOnly');
  const responseIndex = loader.indexOf('await request(`/online-data/daily-data-list?');
  const responseGuardIndex = loader.indexOf('if (!isCurrentRequest()) return staleResult();', responseIndex);
  const firstResponseWriteIndex = loader.indexOf('meituanOrderFlowRows.value = rows;', responseIndex);

  assert.match(appMain, /let meituanOrderFlowRequestSeq = 0;/);
  assert.match(appMain, /let meituanOrderFlowRefreshRequestSeq = 0;/);
  assert.match(loader, /const requestSession = captureAuthSession\(\);/);
  assert.match(loader, /const requestSeq = \+\+meituanOrderFlowRequestSeq;/);
  assert.match(loader, /requestSeq === meituanOrderFlowRequestSeq/);
  assert.match(loader, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(loader, /String\(meituanForm\.value\.hotelId \|\| user\.value\?\.hotel_id \|\| ''\)\.trim\(\) === systemHotelId/);
  assert.match(loader, /String\(meituanOrderFlowPeriod\.value \|\| ''\) === requestPeriod/);
  assert.ok(responseIndex >= 0 && responseGuardIndex > responseIndex, 'order-flow response must be scope-checked after readback');
  assert.ok(firstResponseWriteIndex > responseGuardIndex, 'stale order-flow responses must be rejected before rows are written');
  assert.match(loader, /catch \(error\) \{\s*if \(!isCurrentRequest\(\)\) return staleResult\(\);/);
  assert.match(loader, /finally \{\s*if \(isCurrentRequest\(\)\) \{\s*meituanOrderFlowLoading\.value = false;/);
  assert.match(loader, /ok: true,\s*status: 'verified'/);
  assert.match(loader, /ok: false,\s*status: 'readback_failed'/);

  assert.match(refresh, /const requestSession = captureAuthSession\(\);/);
  assert.match(refresh, /const requestSeq = \+\+meituanOrderFlowRefreshRequestSeq;/);
  assert.match(refresh, /const readback = await loadMeituanOrderFlowData\(\{\s*hotelId: systemHotelId,\s*period: requestPeriod,/);
  assert.match(refresh, /const readbackMatchesScope = readback\?\.ok[\s\S]*readback\?\.hotelId === systemHotelId[\s\S]*readback\?\.period === requestPeriod/);
  assert.match(refresh, /if \(!readbackMatchesScope\) \{[\s\S]*数据库回读失败，结果未验证[\s\S]*showToast\(message, 'warning'\)/);
  const readbackCheckIndex = refresh.indexOf('if (!readbackMatchesScope) {');
  const successToastIndex = refresh.indexOf("showToast(partial ? '订单流向已部分更新' : '订单流向已更新'");
  assert.ok(readbackCheckIndex >= 0 && successToastIndex > readbackCheckIndex, 'success toast must follow a verified matching-scope readback');
  assert.match(refresh, /catch \(error\) \{\s*if \(!isCurrentRequest\(\)\) return null;/);
  assert.match(refresh, /finally \{\s*if \(isCurrentRequest\(\)\) \{\s*meituanOrderFlowFetching\.value = false;/);
});

test('order flow workspace prioritizes amount comparison and readable hotel detail', () => {
  assert.match(fragment, /data-testid="meituan-order-flow-hero"/);
  assert.match(fragment, /data-testid="meituan-order-flow-toolbar"/);
  assert.match(fragment, /data-testid="meituan-order-flow-summary"/);
  assert.match(fragment, /data-testid="meituan-order-flow-detail"/);
  assert.match(fragment, /流失金额/);
  assert.match(fragment, /流入金额/);
  assert.match(fragment, /Math\.max\(0, Math\.min\(row\.orderRatio, 100\)\)/);
  assert.match(fragment, /主要房型/);
});

test('Meituan capture recognizes the verified order loss endpoint as its own section', () => {
  assert.match(standardSource, /section:\s*'order_flow'.*\/peerrank\/order\/loss\/query/);
  assert.match(captureSource, /runMeituanOrderFlowInteractionPlan/);
  assert.match(captureSource, /replayMeituanOrderFlowDirections/);
  assert.match(captureSource, /direction: index === 0 \? 'loss' : 'inflow'/);
  assert.match(captureSource, /normalizeMeituanOrderFlowRows/);
  assert.match(captureSource, /lossType/);
  assert.match(persistenceSource, /data_type'\s*=>\s*'order_flow'/);
  assert.match(persistenceSource, /order_flow_period/);
});

test('order flow view keeps zero values and converts platform ratios for display', () => {
  const periodRange = api.resolveMeituanOrderFlowDateRange('last_7_days', new Date('2026-07-14T12:00:00'));
  assert.deepEqual({ ...periodRange }, {
    period: 'last_7_days',
    label: '近7天',
    startDate: '2026-07-07',
    endDate: '2026-07-13',
  });

  const rows = [
    {
      data_type: 'order_flow',
      data_date: '2026-07-13',
      amount: 0,
      quantity: 0,
      book_order_num: 0,
      update_time: '2026-07-14 01:20:00',
      raw_data: JSON.stringify({
        order_flow_row_type: 'summary',
        order_flow_direction: 'loss',
        order_flow_period: 'last_7_days',
        period_start: '2026-07-07',
        period_end: '2026-07-13',
        order_count: 0,
        room_nights: 0,
        amount: 0,
      }),
    },
    {
      data_type: 'order_flow',
      data_date: '2026-07-13',
      hotel_id: 'peer-1',
      hotel_name: '同行酒店',
      amount: 5234,
      book_order_num: 7,
      raw_data: JSON.stringify({
        order_flow_row_type: 'hotel_detail',
        order_flow_direction: 'loss',
        order_flow_period: 'last_7_days',
        order_count: 7,
        order_ratio: 0.0686,
        amount: '0.5234万',
        lossRoomList: [{ lossRoomName: '大床房', lossRoomCnt: 4 }],
      }),
    },
    {
      data_type: 'order_flow',
      data_date: '2026-07-13',
      amount: 85737,
      quantity: 145,
      book_order_num: 110,
      raw_data: JSON.stringify({
        order_flow_row_type: 'summary',
        order_flow_direction: 'inflow',
        order_flow_period: 'last_7_days',
        period_start: '2026-07-07',
        period_end: '2026-07-13',
        order_count: 110,
        room_nights: 145,
        amount: 85737,
      }),
    },
  ];
  const view = api.buildMeituanOrderFlowView(rows, 'last_7_days');
  assert.equal(view.status, 'complete');
  assert.equal(view.loss.summary.orderCount, 0);
  assert.equal(view.loss.summary.roomNights, 0);
  assert.equal(view.loss.summary.amount, 0);
  assert.equal(view.loss.rows[0].orderRatio, 6.86);
  assert.equal(view.loss.rows[0].amount, 5234);
  assert.equal(view.loss.rows[0].rooms[0].name, '大床房');
  assert.equal(view.inflow.summary.orderCount, 110);
});
