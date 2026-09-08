import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/home-static.js', 'utf8'), context);
context.window.Vue = { Fragment: 'fragment', h: (type, props, children) => ({ type, props, children }) };
const { HomeYesterdayOperatingFacts, HomeOperatingOrchestration, buildHomeBusinessTimeModel } = context.window.SUXI_HOME_STATIC;
const nodes = tree => Array.isArray(tree) ? tree.flatMap(nodes)
  : (tree && typeof tree === 'object' ? [tree, ...nodes(tree.children)] : []);
const renderFacts = model => HomeYesterdayOperatingFacts.render.call({
  model, compact: true, showHeader: true, showControls: false,
  hotelOptions: [], selectedHotelId: 80, refreshing: false, $root: {}, $emit: () => {},
});

test('compact overview reuses scoped values and preserves verified zero and missing values', () => {
  // Synthetic presentation fixture. These values are never persisted or served as hotel facts.
  const fact = (key, value, ready) => ({ key, label: key, value, ready, status: ready ? '已取得' : '未取得' });
  const model = {
    hotelName: 'test-only presentation', selectedBusinessDate: '2026-09-06',
    yesterday: {
      date: '2026-09-06', displayMode: 'partial',
      wholeHotelDerivedFacts: [fact('room_revenue', '¥0.00', true)],
      wholeHotelFacts: [fact('occupancy_rate_percent', '未取得', false)],
      otaChannelFacts: [fact('ota_orders', '7 单', true), fact('ota_room_nights', '未取得', false)],
    },
  };
  const tree = renderFacts(model);
  const pms = nodes(tree).find(node => node.props?.['data-scope'] === 'pms');
  const ota = nodes(tree).find(node => node.props?.['data-scope'] === 'ota');
  assert.deepEqual(nodes(pms).filter(node => node.type === 'strong').map(node => node.children), ['¥0.00', '未取得']);
  assert.deepEqual(nodes(ota).filter(node => node.type === 'strong').map(node => node.children), ['7 单', '未取得']);
  const details = nodes(tree).find(node => node.type === 'details' && node.props?.class === 'home-full-facts-fold');
  assert.ok(details);
  assert.notEqual(details.props.open, true);
  assert.ok(nodes(details).some(node => node.props?.['data-testid'] === 'home-reconciliation-facts'));
});

test('compact empty, error and loading states never expose metric cards as current facts', () => {
  for (const input of [{}, { error: 'synthetic read failure' }, { revenueFactLayerLoading: true }]) {
    const model = buildHomeBusinessTimeModel({
      selectedHotelId: 80, selectedBusinessDate: '2026-09-06',
      hotelName: 'test-only presentation', ...input,
    });
    const tree = renderFacts(model);
    assert.ok(!nodes(tree).some(node => node.props?.['data-testid'] === 'home-facts-overview'));
    assert.ok(!nodes(tree).some(node => node.props?.['data-testid'] === 'home-yesterday-dual-scope'));
    assert.ok(nodes(tree).some(node => ['home-yesterday-empty-state', 'home-yesterday-facts-loading'].includes(node.props?.['data-testid'])));
  }
});

test('compact data-source action remains available after the filters move to the toolbar', () => {
  let opened;
  const model = buildHomeBusinessTimeModel({ selectedHotelId: 80, selectedBusinessDate: '2026-09-06' });
  const tree = HomeYesterdayOperatingFacts.render.call({
    model, compact: true, showHeader: true, showControls: false, hotelOptions: [],
    $root: { openHomeQuickEntry: value => { opened = value; } }, $emit: () => {},
  });
  const action = nodes(tree).find(node => node.props?.class === 'home-facts-recovery-action');
  assert.ok(action);
  action.props.onClick();
  assert.equal(opened.page, 'online-data');
  assert.equal(opened.tab, 'data-health');
});

test('compact failed task reads stay visible and never claim there are no tasks', () => {
  const tree = HomeOperatingOrchestration.render.call({
    model: { stateCode: 'failed', stateLabel: '读取失败', notice: 'synthetic task read failure', items: [], anomalyItems: [] },
    compact: true, weeklyError: 'synthetic weekly read failure', $emit: () => {},
  });
  const visibleTexts = tree => {
    if (Array.isArray(tree)) return tree.flatMap(visibleTexts);
    if (typeof tree === 'string') return [tree];
    if (!tree || typeof tree !== 'object') return [];
    if (tree.type === 'details' && !tree.props?.open) return visibleTexts(tree.children.filter(node => node?.type === 'summary'));
    return visibleTexts(tree.children);
  };
  const visible = visibleTexts(tree).join(' ');
  assert.match(visible, /synthetic task read failure/);
  assert.match(visible, /暂时无法确认今日待办/);
  assert.match(visible, /周度计划 · 读取失败/);
  assert.doesNotMatch(visible, /今天没有匹配的运营任务/);
});

test('stored history navigation preserves current facts and explicitly changes only the business date', () => {
  const model = buildHomeBusinessTimeModel({
    selectedHotelId: 80, selectedBusinessDate: '2026-09-07',
    revenueFactLayer: {
      hotel: { system_hotel_id: 80, tenant_id: 1 }, business_date: '2026-09-07',
      stored_history: {
        tenant_id: 1, system_hotel_id: 80, requested_business_date: '2026-09-07', status: 'ready',
        platforms: [{ platform: 'ctrip', status: 'stored', latest_stored_date: '2026-09-01' }],
      },
    },
  });
  assert.equal(model.yesterday.availableFactCount, 0);
  assert.equal(model.yesterday.date, '2026-09-07');
  assert.equal(model.yesterday.storedHistoryEntries[0].date, '2026-09-01');
  let emitted;
  const tree = HomeYesterdayOperatingFacts.render.call({
    model, compact: true, showHeader: true, showControls: false, hotelOptions: [], $root: {},
    $emit: (...args) => { emitted = args; },
  });
  const action = nodes(tree).find(node => node.props?.['data-testid'] === 'home-stored-history-ctrip');
  assert.ok(action);
  action.props.onClick();
  assert.deepEqual(emitted, ['update:selectedBusinessDate', '2026-09-01']);
  assert.equal(model.yesterday.date, '2026-09-07');
});

test('history shortcuts reject different hotels, tenants, request dates and in-flight responses', () => {
  const metadata = { tenant_id: 1, system_hotel_id: 80, requested_business_date: '2026-09-07', status: 'ready',
    platforms: [{ platform: 'ctrip', status: 'stored', latest_stored_date: '2026-09-01' }] };
  for (const override of [{ system_hotel_id: 81 }, { tenant_id: 2 }, { requested_business_date: '2026-09-06' },
    { platforms: [{ platform: 'ctrip', status: 'stored', latest_stored_date: '2026-09-08' }] }]) {
    const model = buildHomeBusinessTimeModel({ selectedHotelId: 80, selectedBusinessDate: '2026-09-07',
      revenueFactLayer: { hotel: { system_hotel_id: 80, tenant_id: 1 }, business_date: '2026-09-07', stored_history: { ...metadata, ...override } } });
    assert.equal(model.yesterday.storedHistoryEntries.length, 0);
  }
  const loading = buildHomeBusinessTimeModel({ selectedHotelId: 80, selectedBusinessDate: '2026-09-07', revenueFactLayerLoading: true,
    revenueFactLayer: { hotel: { system_hotel_id: 80, tenant_id: 1 }, business_date: '2026-09-07', stored_history: metadata } });
  assert.equal(loading.yesterday.storedHistoryEntries.length, 0);
});
