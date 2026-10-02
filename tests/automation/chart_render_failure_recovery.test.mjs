import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync('public/app-main.js', 'utf8');
const extract = (start, end) => {
  const a = main.indexOf(start), b = main.indexOf(end, a);
  assert.ok(a >= 0 && b > a);
  return main.slice(a, b);
};
const variants = {
  home: { page: 'compass', status: 'homeTrendChartStatus', render: 'renderHomeTrendChart', schedule: 'scheduleHomeTrendChartRender', retry: 'retryHomeTrendChart', destroy: 'destroyHomeTrendChart', source:
    extract('let homeTrendChart = null;', 'const homeTrendHasSamples =')
    + extract('const renderHomeTrendChart =', 'const loadHomeTrends =') },
  analysis: { page: 'online-data', status: 'analysisChartStatus', render: 'renderAnalysisChart', schedule: 'scheduleAnalysisChartRender', retry: 'retryAnalysisChart', destroy: 'destroyAnalysisChart', source:
    extract('let analysisChart = null;', '// 加载数据分析')
    + extract('const renderAnalysisChart =', '// 全选/取消全选') },
};

function harness(kind) {
  const spec = variants[kind], queue = [], pending = [];
  const model = { mode: 'fail', loads: 0, draws: 0, throwDraw: false, missingContext: false, session: 1 };
  const homeData = { data_status: 'ok', sample_days: 2, chart: { labels: ['a', 'b'], metrics: { revenue: { data: [0, null] } } } };
  const analysisData = { summary: { period_count: 2 }, chart_data: { labels: ['a', 'b'], datasets: [{ data: [0, null] }] } };
  const canvas = { offsetParent: {}, getContext: () => model.missingContext ? null : {} };
  const context = {
    ref: value => ({ value }), computed: get => ({ get value() { return get(); } }), window: {},
    document: { getElementById: () => canvas, body: { contains: () => true } },
    currentPage: { value: spec.page }, onlineDataTab: { value: 'analysis' },
    homeTrendData: { value: homeData }, homeTrendHasSamples: { value: true }, homeTrendMetric: { value: 'revenue' },
    homeTrendRange: { value: '30' }, homeTrendCustomRange: { value: { start_date: '2026-09-01', end_date: '2026-09-02' } },
    filterReportHotel: { value: '7' },
    analysisData: { value: analysisData }, analysisDimension: { value: 'day' },
    onlineDataFilter: { value: { hotel_id: '7', start_date: '2026-09-01', end_date: '2026-09-02', source: 'ctrip', data_type: 'orders' } },
    captureAuthSession: () => model.session, isAuthSessionCurrent: session => session === model.session,
    buildHomeTrendChartConfig: data => data, buildOnlineAnalysisChartConfig: data => data,
    debugLog: () => {}, console: { warn: () => {} },
    deferFrameTask: task => queue.push(task), setTimeout: task => queue.push(task),
    loadChartJs: () => {
      model.loads++;
      if (model.mode === 'pending') return new Promise(resolve => pending.push(resolve));
      if (model.mode === 'success') { context.window.Chart = Chart; return Promise.resolve(Chart); }
      return Promise.resolve(null);
    },
  };
  let registeredChart = null;
  class Chart {
    constructor() {
      if (model.partialRegistration && registeredChart) throw new Error('Canvas is already in use');
      if (model.partialRegistration) registeredChart = this;
      if (model.throwDraw) throw new Error('synthetic draw failure');
      model.draws++;
    }
    static getChart() { return registeredChart; }
    destroy() { registeredChart = null; }
  }
  vm.createContext(context);
  vm.runInContext(`${spec.source}
    this.api={schedule:${spec.schedule},render:${spec.render},destroy:${spec.destroy},
      retry:typeof ${spec.retry}==='function'?${spec.retry}:()=>{},
      status:()=>typeof ${spec.status}==='undefined'?undefined:${spec.status}.value};`, context);
  const flush = async () => { for(let i=0;i<30;i++){await Promise.resolve(); const next=queue.shift(); if(next)next();} };
  return { ...context.api, model, context, queue, pending, flush, Chart, homeData, analysisData };
}

for (const kind of Object.keys(variants)) {
  test(`${kind}: exhausted resource retries expose error; manual retry renders unchanged data`, async () => {
    const h=harness(kind), before=JSON.stringify([h.homeData,h.analysisData]);
    h.schedule(); await h.flush();
    assert.equal(h.model.loads,5);
    assert.equal(h.status(),'error');
    h.model.mode='success';h.retry();await h.flush();
    assert.equal(h.status(),'ready');assert.equal(h.model.draws,1);
    assert.equal(h.model.loads,6);
    assert.equal(JSON.stringify([h.homeData,h.analysisData]),before);
  });

  test(`${kind}: loading is visible and repeated manual retry does not fork requests`, async () => {
    const h=harness(kind);h.model.mode='pending';h.schedule();await h.flush();
    assert.equal(h.status(),'loading');h.retry();h.retry();await h.flush();
    assert.equal(h.model.loads,1);
    h.context.window.Chart=h.Chart;h.pending.shift()(h.Chart);await h.flush();
    assert.equal(h.status(),'ready');
  });

  for (const cause of ['throwDraw','missingContext']) {
    test(`${kind}: ${cause} gives a recoverable failure, not a blank success`, async () => {
      const h=harness(kind);h.model.mode='success';h.model[cause]=true;h.schedule();await h.flush();
      assert.equal(h.status(),'error');h.model[cause]=false;h.retry();await h.flush();
      assert.equal(h.status(),'ready');assert.equal(h.model.draws,1);
    });
  }

  test(`${kind}: empty data keeps the existing empty state and does not load charts`, async () => {
    const h=harness(kind);h.context.homeTrendHasSamples.value=false;h.context.analysisData.value.chart_data=null;
    h.schedule();await h.flush();assert.equal(h.model.loads,0);assert.equal(h.status(),'idle');
  });

  test(`${kind}: initialization failure releases its registered canvas on retry or leaving the page`, async () => {
    const h=harness(kind);h.model.mode='success';h.model.partialRegistration=true;h.model.throwDraw=true;
    h.schedule();await h.flush();assert.equal(h.status(),'error');assert.ok(h.Chart.getChart());
    h.model.throwDraw=false;h.retry();await h.flush();assert.equal(h.status(),'ready');assert.equal(h.model.draws,1);
    h.model.throwDraw=true;h.retry();await h.flush();assert.equal(h.status(),'error');
    h.destroy();assert.equal(h.Chart.getChart(),null);
  });

  test(`${kind}: pending result from the previous visit cannot poison a new successful render`, async () => {
    const h=harness(kind);h.model.mode='pending';h.schedule();await h.flush();
    h.context.currentPage.value='users';h.destroy();h.context.currentPage.value=variants[kind].page;
    h.model.mode='success';h.schedule();await h.flush();assert.equal(h.status(),'ready');
    h.pending.shift()(null);await h.flush();assert.equal(h.status(),'ready');assert.equal(h.model.draws,1);
  });

  test(`${kind}: old filter/date or login session prevents a pending callback from publishing`, async () => {
    for(const change of ['scope','session']){
      const h=harness(kind);h.model.mode='pending';h.schedule();await h.flush();
      if(change==='scope'){h.context.homeTrendCustomRange.value.end_date='2026-09-03';h.context.onlineDataFilter.value.end_date='2026-09-03';}
      else h.model.session++;
      h.context.window.Chart=h.Chart;h.pending.shift()(h.Chart);await h.flush();assert.equal(h.model.draws,0);
    }
  });
}
