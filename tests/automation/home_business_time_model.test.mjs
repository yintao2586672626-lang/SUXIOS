import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/home-static.js', 'utf8');
const publicIndex = readFileSync('public/index.html', 'utf8');
const context = { window: {} };
vm.runInNewContext(source, context, { filename: 'public/home-static.js' });

const {
  buildHomeBusinessTimeModel,
  buildHomeDataSources,
  buildCompassDataReadiness,
} = context.window.SUXI_HOME_STATIC;

const temporalData = {
  metric_scope: 'ota_channel',
  scope_note: '仅反映已授权 OTA 渠道数据，不代表酒店全口径经营结果。',
  past: {
    status: 'ready',
    period: { start_date: '2026-06-24', end_date: '2026-07-23' },
    source: { fact_rows: 8 },
    series: [
      {
        date: '2026-07-22',
        ota_revenue: 9999,
        ota_orders: 99,
        ota_room_nights: 88,
        ota_detail_exposure: 777,
        platforms: ['ctrip'],
      },
      {
        date: '2026-07-23',
        ota_revenue: 0,
        ota_orders: 0,
        ota_room_nights: 0,
        ota_detail_exposure: 0,
        platforms: ['ctrip', 'meituan'],
      },
    ],
  },
  present: {
    status: 'ready',
    snapshot_row_count: 3,
    as_of_time: '2026-07-24 09:30:00',
    metrics: { ota_revenue: 1234 },
  },
  future: {
    status: 'ready',
    series: [{ date: '2026-07-25' }],
  },
};

test('home business time model uses the exact yesterday row and preserves captured zero facts', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    futureCard: { value: 'OTA收入 向上', detail: '预计明日落在可信区间。' },
    revenueMetricCards: [
      {
        scopeLabel: '全酒店口径',
        truthStatus: 'verified',
        truth: { source: { methods: ['pms'] } },
      },
      {
        scopeLabel: '全酒店口径',
        truthStatus: 'verified',
        truth: { source: { methods: ['excel_import'] } },
      },
    ],
    revenueOverviewScope: 'hotel',
  });

  assert.equal(model.hotelName, '测试酒店');
  assert.equal(model.yesterday.date, '2026-07-23');
  assert.equal(model.yesterday.status, '已取得');
  assert.deepEqual(
    Array.from(model.yesterday.facts, (fact) => fact.value),
    ['¥0', '0单', '0间夜', '0次'],
  );
  assert.ok(model.yesterday.facts.every((fact) => fact.detail.includes('2026-07-23')));
  assert.equal(model.today.status, '已取得');
  assert.match(model.today.summary, /3 条 OTA 实时快照/);
  assert.equal(Object.hasOwn(model.today, 'metrics'), false, 'today exposes acquisition status, not intraday operating metrics');
  assert.equal(model.future.status, '已形成');
  assert.match(model.future.note, /AI辅助研判/);
  assert.match(model.future.note, /不自动执行/);
  assert.equal(model.scopeRows.find((row) => row.key === 'pms')?.status, '已验证');
  assert.equal(model.scopeRows.find((row) => row.key === 'import')?.status, '已验证');
});

test('home business time model never substitutes an older historical row for yesterday', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData: {
      ...temporalData,
      past: {
        ...temporalData.past,
        series: temporalData.past.series.slice(0, 1),
      },
      present: { status: 'empty', snapshot_row_count: 0 },
      future: { status: 'empty', series: [] },
    },
    hotelName: '测试酒店',
  });

  assert.equal(model.yesterday.status, '未取得');
  assert.ok(model.yesterday.facts.every((fact) => fact.value === '未取得'));
  assert.match(model.yesterday.summary, /最近历史日 2026-07-22 不用于替代/);
  assert.equal(model.today.status, '未取得');
  assert.equal(model.future.status, '尚未形成');
});

test('competitor data is diagnostic reference and does not raise core fact readiness', () => {
  const sources = buildHomeDataSources({
    sampleDays: 7,
    trendReady: true,
    channelSignal: { status: 'ok' },
    priceSignal: { status: 'pending' },
  });
  const competitor = sources.find((sourceRow) => sourceRow.name === '竞对价格');
  const readiness = buildCompassDataReadiness(sources);

  assert.equal(competitor?.role, 'diagnostic');
  assert.equal(readiness.percent, 100);
  assert.match(readiness.diagnosticText, /不计入核心事实就绪度/);
});

test('home static cache key matches the shipped business time model content', () => {
  const runtime = readFileSync('public/app-startup-helpers.min.js');
  const hash = createHash('sha256').update(runtime).digest('hex').slice(0, 10);
  assert.match(publicIndex, new RegExp(`app-startup-helpers\\.min\\.js\\?v=[^"']*h${hash}`));
});
