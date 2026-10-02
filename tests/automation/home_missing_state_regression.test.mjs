import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/home-static.js', 'utf8'), context);
const { buildHomeBusinessTimeModel, createHomeWeeklyOperatingPlanController } = context.window.SUXI_HOME_STATIC;

test('business-date selection is a declared component event', () => {
  assert.ok(context.window.SUXI_HOME_STATIC.HomeYesterdayOperatingFacts.emits.includes('update:selectedBusinessDate'));
});

test('same-day zero verified facts never claim partial acquisition or comparison', () => {
  const model = buildHomeBusinessTimeModel({
    today: '2026-09-05', selectedBusinessDate: '2026-09-04', selectedHotelId: 121,
    hotelName: 'test-only hotel',
    revenueFactLayer: {
      hotel: { system_hotel_id: 121 }, business_date: '2026-09-04',
      facts: {}, sources: {}, reconciliation: { status: 'partial' },
    },
  });
  assert.equal(model.yesterday.availableFactCount, 0);
  assert.equal(model.yesterday.status, '未取得');
  assert.doesNotMatch(model.yesterday.summary, /部分取得/);
  assert.equal(model.yesterday.reconciliationStatus, '未取得');
});

const empty = (hotelId = 121) => ({ code: 200, data: {
  hotel_id: hotelId, week_start: '2026-08-24', week_end: '2026-08-30', status: 'not_generated', readback_verified: false,
} });

test('failed temporal request plus an empty fact layer keeps the header and body in error state', () => {
  const model = buildHomeBusinessTimeModel({
    selectedBusinessDate: '2026-09-04', selectedHotelId: 121, error: 'temporal endpoint failed',
    revenueFactLayer: {
      hotel: { system_hotel_id: 121 }, business_date: '2026-09-04',
      facts: {}, sources: {}, reconciliation: { status: 'partial' },
    },
  });
  assert.equal(model.yesterday.displayMode, 'error');
  assert.equal(model.yesterday.status, '读取失败');
  assert.equal(model.yesterday.reconciliationStatus, '读取失败');
  assert.match(model.yesterday.summary, /temporal endpoint failed/);
});

test('fact request in flight is loading in both header and body, never a premature empty result', () => {
  const model = buildHomeBusinessTimeModel({
    selectedBusinessDate: '2026-09-04', selectedHotelId: 121, revenueFactLayerLoading: true,
  });
  assert.equal(model.yesterday.displayMode, 'loading');
  assert.equal(model.yesterday.status, '正在读取');
  assert.match(model.yesterday.summary, /正在读取/);
  assert.equal(model.yesterday.reconciliationStatus, '正在读取');
});
const controllerFor = (apiRequest) => createHomeWeeklyOperatingPlanController({
  ref: value => ({ value }), apiRequest,
  watchEffect: callback => { callback(); return () => {}; },
  captureReadContext: () => () => true,
  getHotelId: () => '121', getToday: () => '2026-09-05',
});

test('an explicitly not-generated weekly plan is a neutral empty state', async () => {
  const controller = controllerFor(async () => empty());
  assert.equal(await controller.loadHomeWeeklyOperatingPlan(), true);
  assert.equal(controller.homeWeeklyOperatingPlan.value, null);
  assert.equal(controller.homeWeeklyOperatingPlanError.value, '');
});

test('not-generated weekly response must still match the requested hotel and week', async () => {
  for (const data of [empty(122), { code: 200, data: { ...empty().data, week_end: '2026-08-23' } }]) {
    const controller = controllerFor(async () => data);
    assert.equal(await controller.loadHomeWeeklyOperatingPlan(), false);
    assert.match(controller.homeWeeklyOperatingPlanError.value, /所选周计划读取失败/);
    assert.equal(controller.homeWeeklyOperatingPlanReadStatus.value, 'error');
    assert.equal(controller.homeWeeklyOperatingPlan.value, null);
    assert.equal(controller.homeWeeklyOperatingPlanLoading.value, false);
  }
});

test('weekly storage errors and ordinary 404s must not be disguised as no plan', async () => {
  for (const code of [404, 500, 503]) {
    const controller = controllerFor(async () => ({ code, message: '读取周度经营计划失败' }));
    assert.equal(await controller.loadHomeWeeklyOperatingPlan(), false);
    assert.match(controller.homeWeeklyOperatingPlanError.value, /所选周计划读取失败/);
    assert.equal(controller.homeWeeklyOperatingPlanReadStatus.value, 'error');
    assert.equal(controller.homeWeeklyOperatingPlan.value, null);
    assert.equal(controller.homeWeeklyOperatingPlanLoading.value, false);
  }
});
