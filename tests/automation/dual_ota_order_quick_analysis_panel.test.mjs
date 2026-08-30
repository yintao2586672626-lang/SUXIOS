import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { cssContainsClassSelector } from '../../scripts/lib/frontend_tailwind_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = relativePath => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

const panel = read('public/components/online-data/ctrip-order-analysis-panel.js');
const loader = read('public/components/online-data/ctrip-order-analysis-loader.js');
const appMainComponents = read('public/components/system/app-main-components.js');
const ctripPage = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
const meituanPage = read('resources/frontend/templates/fragments/26-page-meituan-ebooking.html');
const appMain = read('public/app-main.js');
const runtimeCss = [read('public/tailwind.min.css'), read('public/style.min.css')].join('\n');

const loadPanelComponent = (globals = {}) => {
    const window = {};
  const Vue = {
    h: (type, props, children) => ({ type, props: props || {}, children }),
    nextTick: callback => Promise.resolve().then(callback),
  };
    vm.runInNewContext(panel, {
      window,
      Vue,
      URLSearchParams,
      sessionStorage: { getItem: () => '' },
      ...globals,
    });
    return { component: window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody, Vue };
};

const childNodes = node => (Array.isArray(node) ? node : [node])
  .flatMap(item => (item && typeof item === 'object' && Array.isArray(item.children) ? [item, ...childNodes(item.children)] : (item ? [item] : [])));

const nodeText = node => (Array.isArray(node) ? node : [node])
  .map(item => {
    if (item === null || item === undefined || item === false) return '';
    if (typeof item === 'string' || typeof item === 'number') return String(item);
    return nodeText(item.children || []);
  })
  .join('');

const findTestId = (node, testId) => childNodes(node).find(item => item?.props?.['data-testid'] === testId);

const mountRenderContext = (component, detailMode) => {
  const instance = {
    ...component.data(),
    ctx: { platformHotelSelectedId: 80, platformHotelSelectedName: '示例酒店', token: 'test-token' },
    detailMode,
  };
  for (const [name, getter] of Object.entries(component.computed)) {
    Object.defineProperty(instance, name, { configurable: true, get: () => getter.call(instance) });
  }
  for (const [name, method] of Object.entries(component.methods)) {
    instance[name] = method.bind(instance);
  }
  instance.quickAnalysis = {
    contract_version: 'dual_ota_order_quick_analysis.v1',
    status: 'ready',
    hotel: { id: 80, name: '示例酒店' },
    date_range: { from: '2026-08-01', to: '2026-08-30' },
    platforms: {
      ctrip: { status: 'verified', metrics: Object.fromEntries(['orders', 'room_nights', 'revenue', 'adr', 'cancellation_rate'].map((key, index) => [key, { value: index + 1, status: 'verified' }])) },
      meituan: { status: 'available_unverified', metrics: Object.fromEntries(['orders', 'room_nights', 'revenue', 'adr', 'cancellation_rate'].map((key, index) => [key, { value: index + 2, status: 'available_unverified' }])) },
    },
    comparison: {
      can_compare: true,
      reason: '同口径已核验',
      metrics: { orders: { status: 'ready', delta: -1, leader: 'meituan' } },
    },
    actions: [],
  };
  return instance;
};

test('dual-OTA quick analysis uses one authenticated persisted-read endpoint and four ranges', () => {
  assert.match(panel, /fetch\(`\/api\/online-data\/dual-ota\/order-analysis\?\$\{params\.toString\(\)}`/);
  assert.match(panel, /new URLSearchParams\(\{ system_hotel_id: String\(hotelId\) \}\)/);
  assert.match(panel, /params\.set\('date_from', this\.quickDateFrom\)/);
  assert.match(panel, /params\.set\('date_to', this\.quickDateTo\)/);
  assert.match(panel, /headers:\s*authToken\s*\?\s*\{ Authorization: `Bearer \$\{authToken}` \}\s*:\s*\{\}/);
  assert.match(panel, /cache:\s*'no-store'/);
  assert.match(panel, /setQuickRangePreset\('30d', false\)/);
  assert.match(panel, /timeZone:\s*'Asia\/Shanghai'/);
  for (const preset of ['7d', '30d', 'all', 'custom']) {
    assert.ok(panel.includes(`toolbarButton('${preset === '7d' ? '近7天' : preset === '30d' ? '近30天' : preset === 'all' ? '最近已存30天' : '自定义'}', '${preset}')`));
  }
});

test('both platform pages mount the same quick analysis with deliberate detail modes', () => {
  assert.match(ctripPage, /<ctrip-order-analysis-panel\s+:ctx="\$root"\s+detail-mode="ctrip"><\/ctrip-order-analysis-panel>/);
  assert.match(meituanPage, /<ctrip-order-analysis-panel\s+:ctx="\$root"\s+detail-mode="summary"><\/ctrip-order-analysis-panel>/);
  assert.ok(
    meituanPage.indexOf('detail-mode="summary"') > meituanPage.indexOf('data-testid="meituan-owner-navigation"'),
    'quick analysis must sit after the Meituan owner navigation',
  );
  assert.ok(
    meituanPage.indexOf('detail-mode="summary"') < meituanPage.indexOf('<!-- 美团老板工作台主内容 -->'),
    'quick analysis must remain above the detailed Meituan workbench',
  );
  assert.match(panel, /showCtripDetail\(\)\s*\{[\s\S]*?this\.detailMode !== 'summary'/);
  assert.match(panel, /if \(!this\.showCtripDetail\) return quickPanel/);
  assert.match(meituanPage, /data-testid="meituan-orders-page" tabindex="-1"/);
  assert.match(meituanPage, /data-testid="meituan-order-flow-page" tabindex="-1"/);
});

test('quick analysis preserves per-metric truth states and blocks unsafe comparison claims', () => {
  for (const status of ['verified', 'available_unverified', 'missing']) {
    assert.ok(panel.includes(`${status}:`), `panel must name ${status}`);
  }
  for (const metric of ['orders', 'room_nights', 'revenue', 'adr', 'cancellation_rate']) {
    assert.ok(panel.includes(metric), `panel must render ${metric}`);
  }
  assert.match(panel, /分别展示，不判高低/);
  assert.match(panel, /comparison\?\.can_compare === true/);
  assert.match(panel, /两平台的同店、同日期、同口径证据未同时核验/);
  assert.match(panel, /data-testid': 'dual-ota-order-quick-error'/);
  assert.match(panel, /暂时没有可展示的双平台订单回读/);
  assert.match(panel, /revenue:\s*\{ label: 'OTA 房费收入' \}/);
});

test('quick analysis offers bounded补数 and page-switch actions and refreshes after writes', () => {
  assert.match(panel, /this\.ctx\?\.openCtripChannelOrderEvidenceUpload/);
  assert.match(panel, /platformHotelOptionsFor/);
  assert.match(appMain, /platformHotelOptions, platformHotelOptionsFor/);
  assert.match(panel, /selectPlatformHotelOption/);
  assert.match(panel, /不能跳转补数；请先完成该平台酒店绑定/);
  assert.match(panel, /scrollIntoView/);
  assert.match(panel, /focus\?\.\(\{ preventScroll: true \}\)/);
  assert.match(panel, /uploadReceiptKey\(next, previous\)[\s\S]*?this\.loadQuickAnalysis\(\)/);
  assert.match(panel, /meituanRefreshKey\(next, previous\)[\s\S]*?this\.loadQuickAnalysis\(\)/);
  for (const field of ['capturedAt', 'periodStart', 'periodEnd', 'orderCount', 'roomNights', 'amount']) {
    assert.ok(panel.includes(field), `flow refresh identity must include ${field}`);
  }
  assert.match(panel, /data-testid': 'dual-ota-order-flow-summary'/);
  assert.match(panel, /orderFlow\.status === 'missing'/);
  assert.match(panel, /last_7_days: '近7天'/);
  assert.match(panel, /last_30_days: '近30天'/);
  assert.match(panel, /this\.quickStale = !!this\.quickAnalysis/);
  assert.match(panel, /data-testid': 'dual-ota-order-read-failure'/);
  assert.match(panel, /return number === null \? '不可计算' : `\$\{number\.toFixed\(1\)\}%`/);
});

test('existing Ctrip deep analysis remains available below the unified quick view', () => {
  assert.match(panel, /data-testid': 'ctrip-order-analysis-panel'/);
  assert.match(panel, /订单深度分析/);
  assert.match(panel, /连住分布/);
  assert.match(panel, /提前预订分布/);
  assert.match(panel, /房型偏好/);
  assert.match(panel, /参考底价（非确认收入）/);
  assert.match(panel, /\[quickPanel, detailPanel\]/);
});

test('summary and Ctrip modes render without duplicating the deep panel', () => {
  const { component } = loadPanelComponent();
  const summary = mountRenderContext(component, 'summary');
  const summaryNode = component.render.call(summary);
  assert.equal(summaryNode.props['data-testid'], 'dual-ota-order-quick-analysis-panel');

  const ctrip = mountRenderContext(component, 'ctrip');
  const ctripNode = component.render.call(ctrip);
  assert.equal(ctripNode.props['data-testid'], 'dual-ota-order-analysis-stack');
  assert.equal(ctripNode.children.length, 2);
  assert.equal(ctripNode.children[0].props['data-testid'], 'dual-ota-order-quick-analysis-panel');
  assert.equal(ctripNode.children[1].props['data-testid'], 'ctrip-order-analysis-panel');
});

test('percentage formatting keeps backend percent units and comparison points exact', () => {
  const { component } = loadPanelComponent();
  const instance = mountRenderContext(component, 'summary');
  assert.equal(instance.quickMetricText('cancellation_rate', 1), '1.0%');
  assert.equal(instance.quickMetricText('cancellation_rate', 0), '0.0%');
  assert.equal(instance.comparisonMetricText('cancellation_rate', 0.5), '+0.5 个百分点');
  assert.equal(instance.comparisonMetricText('cancellation_rate', -0.5), '-0.5 个百分点');
});

test('cross-platform recovery keeps the same hotel and focuses the requested workbench', async () => {
  const focusEvents = [];
  const document = {
    querySelector: selector => ({
      scrollIntoView: options => focusEvents.push(['scroll', selector, options.block]),
      focus: options => focusEvents.push(['focus', selector, options.preventScroll]),
    }),
  };
  const { component } = loadPanelComponent({ document });
  const instance = mountRenderContext(component, 'summary');
  const selected = { ctrip: '80', meituan: '81' };
  const openedTabs = [];
  instance.ctx.currentPage = 'ctrip-ebooking';
  Object.defineProperty(instance.ctx, 'platformHotelSelectedId', {
    configurable: true,
    get: () => (instance.ctx.currentPage === 'meituan-ebooking' ? selected.meituan : selected.ctrip),
  });
  instance.ctx.platformHotelOptionsFor = () => [{ id: 80, name: '示例酒店' }, { id: 81, name: '另一家酒店' }];
  instance.ctx.selectPlatformHotelOption = hotel => {
    selected[instance.ctx.currentPage === 'meituan-ebooking' ? 'meituan' : 'ctrip'] = String(hotel.id);
  };
  instance.ctx.openMeituanManualTab = tab => openedTabs.push(tab);

  assert.equal(await instance.openPlatformPage('meituan', 'meituan-orders'), true);
  assert.equal(instance.ctx.currentPage, 'meituan-ebooking');
  assert.equal(selected.meituan, '80');
  assert.deepEqual(openedTabs, ['meituan-orders']);
  assert.deepEqual(focusEvents.at(-1), ['focus', '[data-testid="meituan-orders-page"]', true]);
});

test('recovery refuses an unavailable destination hotel instead of switching pages', async () => {
  const { component } = loadPanelComponent();
  const instance = mountRenderContext(component, 'summary');
  const notices = [];
  instance.ctx.currentPage = 'ctrip-ebooking';
  instance.ctx.platformHotelOptionsFor = () => [{ id: 81, name: '另一家酒店' }];
  instance.ctx.showToast = (...args) => notices.push(args);

  assert.equal(await instance.openPlatformPage('meituan', 'meituan-orders'), false);
  assert.equal(instance.ctx.currentPage, 'ctrip-ebooking');
  assert.match(instance.quickError, /不能跳转补数/);
  assert.equal(notices[0][1], 'error');
});

test('Ctrip upload navigates with hotel isolation before opening the file control', async () => {
  const { component } = loadPanelComponent();
  const instance = mountRenderContext(component, 'summary');
  let opened = 0;
  instance.ctx.currentPage = 'meituan-ebooking';
  instance.ctx.platformHotelOptionsFor = () => [{ id: 80, name: '示例酒店' }];
  instance.ctx.openCtripManualTab = () => {};
  instance.ctx.openCtripChannelOrderEvidenceUpload = () => { opened += 1; };
  assert.equal(await instance.openCtripUpload(), true);
  assert.equal(instance.ctx.currentPage, 'ctrip-ebooking');
  assert.equal(opened, 1);
});

test('order-flow refresh identity changes when a complete period receives new facts', () => {
  const { component } = loadPanelComponent();
  const instance = mountRenderContext(component, 'summary');
  instance.ctx.meituanOrderFlowView = {
    status: 'complete',
    period: 'last_7_days',
    periodStart: '2026-08-24',
    periodEnd: '2026-08-30',
    capturedAt: '2026-08-30 10:00:00',
    loss: { summary: { orderCount: 2, roomNights: 3, amount: 400 } },
    inflow: { summary: { orderCount: 1, roomNights: 1, amount: 200 } },
  };
  const first = instance.meituanRefreshKey;
  instance.ctx.meituanOrderFlowView.capturedAt = '2026-08-30 11:00:00';
  const recaptured = instance.meituanRefreshKey;
  instance.ctx.meituanOrderFlowView.loss.summary.amount = 450;
  const changedValue = instance.meituanRefreshKey;
  assert.notEqual(first, recaptured);
  assert.notEqual(recaptured, changedValue);
});

test('transient read failure preserves the last success and labels it stale', async () => {
  const fetch = async () => ({
    ok: false,
    status: 503,
    json: async () => ({ code: 503, message: '服务暂不可用' }),
  });
  const { component } = loadPanelComponent({ fetch });
  const instance = mountRenderContext(component, 'summary');
  const previous = instance.quickAnalysis;
  await instance.loadQuickAnalysis();
  assert.equal(instance.quickAnalysis, previous);
  assert.equal(instance.quickStale, true);
  const rendered = component.render.call(instance);
  assert.match(nodeText(findTestId(rendered, 'dual-ota-order-quick-error')), /以下仍为上次成功回读/);
  assert.doesNotMatch(nodeText(rendered), /暂时没有可展示/);
});

test('first-load failure renders a dedicated retry state, never a no-data supplement prompt', async () => {
  const fetch = async () => ({
    ok: false,
    status: 500,
    json: async () => ({ code: 500, message: '数据库回读失败' }),
  });
  const { component } = loadPanelComponent({ fetch });
  const instance = mountRenderContext(component, 'summary');
  instance.quickAnalysis = null;
  await instance.loadQuickAnalysis();
  const rendered = component.render.call(instance);
  assert.ok(findTestId(rendered, 'dual-ota-order-read-failure'));
  assert.match(nodeText(rendered), /没有把读取失败当成“无数据”/);
  assert.doesNotMatch(nodeText(rendered), /可先上传携程订单/);
});

test('required recovery actions are deduplicated and disappear when evidence is ready', () => {
  const { component } = loadPanelComponent();
  const instance = mountRenderContext(component, 'summary');
  instance.quickAnalysis.actions = [
    { key: 'ctrip_order_upload', platform: 'ctrip', required: true, status: 'required' },
    { key: 'ctrip_order_collect', platform: 'ctrip', required: true, status: 'required' },
    { key: 'meituan_order_collect', platform: 'meituan', required: true, status: 'required' },
    { key: 'meituan_order_flow_collect', platform: 'meituan', required: true, status: 'required' },
  ];
  assert.deepEqual(
    Array.from(instance.quickRequiredActions, action => action.key),
    ['ctrip_order_upload', 'meituan_order_collect', 'meituan_order_flow_collect'],
  );
  instance.quickAnalysis.actions = instance.quickAnalysis.actions.map(action => ({ ...action, required: false, status: 'available' }));
  assert.equal(instance.quickRequiredActions.length, 0);
  assert.equal(findTestId(component.render.call(instance), 'dual-ota-order-required-actions'), undefined);
});

test('Shanghai presets, accessible state, and shipped selectors remain deterministic', () => {
  class FixedDate extends Date {
    constructor(...args) {
      super(...(args.length ? args : ['2026-08-30T16:30:00.000Z']));
    }
  }
  const { component } = loadPanelComponent({ Date: FixedDate });
  const instance = mountRenderContext(component, 'summary');
  instance.setQuickRangePreset('7d', false);
  assert.equal(instance.quickDateFrom, '2026-08-25');
  assert.equal(instance.quickDateTo, '2026-08-31');
  const rendered = component.render.call(instance);
  assert.equal(rendered.props.tabindex, '-1');
  assert.equal(rendered.props['aria-busy'], 'false');
  assert.equal(findTestId(rendered, 'dual-ota-order-range-7d').props['aria-pressed'], 'true');

  for (const token of ['bg-green-800', 'bg-gray-50', 'sm:grid-cols-3', 'focus:ring-yellow-200', 'text-white']) {
    assert.equal(cssContainsClassSelector(runtimeCss, token), true, `shipped CSS must contain ${token}`);
  }
  for (const unsupported of ['bg-[#06110d]', 'bg-[#143a31]', 'border-[#143a31]/25', 'grid-cols-[minmax(0,1fr)_auto]']) {
    assert.equal(panel.includes(unsupported), false, `quick analysis must not rely on missing selector ${unsupported}`);
  }
  assert.match(loader, /正在加载双平台订单快析/);
  assert.match(appMainComponents, /正在加载双平台订单快析/);
});
