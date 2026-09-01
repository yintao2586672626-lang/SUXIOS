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
  HomeYesterdayOperatingFacts,
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

const buildRevenueFactLayer = ({ pmsDate = '2026-07-23', hotelId = 80 } = {}) => {
  const mismatch = pmsDate !== '2026-07-23';
  return {
    hotel: { system_hotel_id: hotelId, tenant_id: 80, name: '测试酒店' },
    business_date: '2026-07-23',
    date_alignment: {
      status: mismatch ? 'blocked_date_mismatch' : 'aligned',
      comparison_allowed: !mismatch,
      target_business_date: '2026-07-23',
      message: mismatch
        ? '发现来源实际业务日期与目标日不一致，本次不可对账，也不可自动改日或合并。'
        : 'PMS、携程、美团均已按同一目标业务日精确回读。',
      sources: {
        dingdandao_pms: {
          observed_date: pmsDate,
          data_status: mismatch ? 'not_verified' : 'readback_verified',
        },
        ctrip_ota: { observed_date: '2026-07-23', data_status: 'readback_verified' },
        meituan_ota: { observed_date: '2026-07-23', data_status: 'readback_verified' },
      },
    },
    sources: {
      dingdandao_pms: {
        data_status: mismatch ? 'not_verified' : 'readback_verified',
        actual_business_date: pmsDate,
        fact_statuses: {
          room_revenue: { status: mismatch ? 'not_verified' : 'readback_verified' },
          payment_collected_amount: { status: 'missing' },
          sold_room_nights: { status: mismatch ? 'not_verified' : 'readback_verified' },
          sellable_room_nights: { status: mismatch ? 'not_calculable' : 'derived_verified' },
          occupancy_rate_percent: { status: mismatch ? 'not_verified' : 'readback_verified' },
          adr: { status: mismatch ? 'not_calculable' : 'derived_verified' },
          revpar: { status: mismatch ? 'not_calculable' : 'derived_verified' },
        },
      },
      ctrip_ota: {
        data_status: 'readback_verified',
        actual_business_date: '2026-07-23',
        analysis_readiness: { allowed: true, status: 'allowed' },
        fact_statuses: {
          revenue: { status: 'readback_verified' },
          list_exposure: { status: 'readback_verified' },
          detail_exposure: { status: 'readback_verified' },
          flow_rate_percent: { status: 'readback_verified' },
          submit_rate_percent: { status: 'readback_verified' },
          cancellation_rate_percent: { status: 'missing' },
        },
      },
      meituan_ota: {
        data_status: 'readback_verified',
        actual_business_date: '2026-07-23',
        analysis_readiness: { allowed: true, status: 'allowed' },
        fact_statuses: {
          revenue: { status: 'readback_verified' },
          list_exposure: { status: 'readback_verified' },
          detail_exposure: { status: 'readback_verified' },
          flow_rate_percent: { status: 'readback_verified' },
          submit_rate_percent: { status: 'readback_verified' },
          cancellation_rate_percent: { status: 'readback_verified' },
        },
      },
    },
    facts: {
      whole_hotel_accommodation: mismatch ? {
        room_revenue: null,
        payment_collected_amount: null,
        sold_room_nights: null,
        sellable_room_nights: null,
        occupancy_rate_percent: null,
      } : {
        room_revenue: 7930.11,
        payment_collected_amount: null,
        sold_room_nights: 15,
        sellable_room_nights: 15,
        occupancy_rate_percent: 100,
      },
      ota_channel: {
        ctrip: {
          revenue: 0,
          orders: 0,
          room_nights: 0,
          adr: null,
          list_exposure: 1200,
          detail_exposure: 680,
          flow_rate_percent: 56.67,
          submit_rate_percent: 0,
          cancellation_rate_percent: null,
        },
        meituan: {
          revenue: 1032.39,
          orders: 1,
          room_nights: 1,
          adr: 1032.39,
          list_exposure: 920,
          detail_exposure: 310,
          flow_rate_percent: 33.7,
          submit_rate_percent: 4.2,
          cancellation_rate_percent: 12,
        },
        combined: {
          orders: 1,
          room_nights: 1,
          revenue: 1032.39,
          adr: 1032.39,
        },
        combined_status: {
          data_status: 'readback_verified',
          fact_statuses: {
            revenue: { status: 'readback_verified' },
            orders: { status: 'readback_verified' },
            room_nights: { status: 'readback_verified' },
            adr: { status: 'derived_verified' },
          },
          analysis_readiness: {
            revenue_allowed: true,
            status: 'allowed',
          },
        },
      },
    },
    derived_metrics: {
      whole_hotel_adr: { status: mismatch ? 'not_calculable' : 'ready', value: mismatch ? null : 528.67 },
      whole_hotel_revpar: { status: mismatch ? 'not_calculable' : 'ready', value: mismatch ? null : 528.67 },
      ota_adr: { status: 'ready', value: 1032.39 },
      ota_room_night_share_percent: { status: mismatch ? 'not_calculable' : 'ready', value: mismatch ? null : 6.67 },
      ota_room_revenue_share_percent: { status: mismatch ? 'not_calculable' : 'ready', value: mismatch ? null : 13.02 },
      ota_cancellation_rate_percent: { status: 'ready', value: 12 },
    },
    reconciliation: {
      status: mismatch ? 'blocked' : 'partial',
      checks: [
        {
          key: 'business_date',
          label: '业务日期',
          status: mismatch ? 'blocked' : 'matched',
          detail: mismatch ? 'PMS实际业务日为2026-07-22。' : '三源同日。',
        },
        {
          key: 'duplicate_orders',
          label: '重复订单',
          status: 'canonicalized',
          order_identity_candidate_rows: 3,
          order_identity_covered_rows: 3,
          order_identity_coverage_percent: 100,
          distinct_verified_order_grains: 2,
          suppressed_duplicate_order_rows: 1,
          suppressed_representation_rows: 2,
          detail: '订单身份完整，已排除一条重复订单版本。',
        },
        {
          key: 'payment_caliber',
          label: '支付与收入口径',
          status: 'not_comparable',
          detail: 'PMS住宿房费不等于支付实收。',
        },
        {
          key: 'cancellation',
          label: '取消订单',
          status: 'ota_only_ready',
          combined_rate_percent: 12,
          detail: 'OTA取消率只按渠道订单口径。',
        },
        {
          key: 'summary_representation',
          label: '汇总与订单表示',
          status: 'not_checkable',
          differences: [],
          detail: '没有形成可比指标。',
        },
        {
          key: 'floor_vs_sales',
          label: '底价与销售收入',
          status: 'reference_only',
          minimum_floor_price: 350,
          combined_ota_adr: 1032.39,
          reference_gap: 682.39,
          detail: '房型粒度未对齐，只作参考。',
        },
      ],
    },
  };
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

test('a factless business date renders a compact recovery state instead of empty metric panels', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData: {
      ...temporalData,
      past: {
        ...temporalData.past,
        series: temporalData.past.series.slice(0, 1),
      },
    },
    hotelName: '测试酒店',
    selectedHotelId: 80,
  });

  assert.equal(model.yesterday.displayMode, 'empty');
  assert.equal(model.yesterday.availableFactCount, 0);
  assert.ok(model.yesterday.totalFactCount > 0);
  assert.equal(model.yesterday.latestAvailableDate, '2026-07-22');

  context.window.Vue = {
    Fragment: 'fragment',
    h: (type, props, children) => ({ type, props, children }),
  };
  const tree = HomeYesterdayOperatingFacts.render.call({
    model,
    showHeader: true,
    showControls: true,
    hotelOptions: [],
    selectedHotelId: 80,
    refreshing: false,
    $root: {},
    $emit: () => {},
  });
  const serialized = JSON.stringify(tree);

  assert.match(serialized, /home-yesterday-empty-state/);
  assert.match(serialized, /当前没有可用于经营判断的数据/);
  assert.match(serialized, /最近有记录 2026-07-22/);
  assert.doesNotMatch(serialized, /home-yesterday-dual-scope/);
  assert.doesNotMatch(serialized, /home-reconciliation-facts/);
});

test('available strict facts keep the PMS, OTA, and reconciliation panels visible', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: buildRevenueFactLayer(),
  });
  const tree = HomeYesterdayOperatingFacts.render.call({
    model,
    showHeader: true,
    showControls: true,
    hotelOptions: [],
    selectedHotelId: 80,
    refreshing: false,
    $root: {},
    $emit: () => {},
  });
  const serialized = JSON.stringify(tree);

  assert.equal(model.yesterday.displayMode, 'partial');
  assert.match(serialized, /home-yesterday-dual-scope/);
  assert.match(serialized, /home-reconciliation-facts/);
  assert.doesNotMatch(serialized, /home-yesterday-empty-state/);
});

test('home business time model exposes PMS whole-hotel and OTA channel facts at the same time', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: buildRevenueFactLayer(),
  });

  assert.equal(model.yesterday.requiresHotelSelection, false);
  assert.equal(model.yesterday.dualScopeReady, false);
  assert.equal(model.yesterday.status, '部分取得');
  assert.equal(model.yesterday.wholeHotelFacts.find((fact) => fact.key === 'sold_room_nights')?.ready, true);
  assert.equal(model.yesterday.wholeHotelFacts.find((fact) => fact.key === 'sellable_room_nights')?.ready, true);
  assert.equal(
    model.yesterday.wholeHotelFacts.find((fact) => fact.key === 'payment_collected_amount')?.label,
    '支付实收（非会计收入）',
  );
  assert.equal(model.yesterday.wholeHotelFacts.find((fact) => fact.key === 'payment_collected_amount')?.value, '未取得');
  assert.match(
    model.yesterday.wholeHotelFacts.find((fact) => fact.key === 'payment_collected_amount')?.detail || '',
    /支付实收字段未接入/,
  );
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_orders')?.ready, true);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_night_share_percent')?.ready, true);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_revenue_share_percent')?.ready, true);
  assert.equal(model.yesterday.otaPlatformRows.find((row) => row.key === 'ctrip')?.facts.find((fact) => fact.label === '详情曝光')?.ready, true);
  assert.equal(model.yesterday.otaPlatformRows.find((row) => row.key === 'ctrip')?.facts.find((fact) => fact.label === '流量转化')?.ready, true);
  assert.equal(model.yesterday.otaPlatformRows.find((row) => row.key === 'ctrip')?.facts.find((fact) => fact.label === '取消率')?.value, '未取得');
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_cancellation_rate_percent')?.ready, true);
  assert.equal(model.yesterday.dateSourceRows.every((row) => row.status === '同日已验证'), true);
  assert.match(model.yesterday.reconciliationRows.find((row) => row.key === 'cancellation')?.value || '', /12%/);
  assert.equal(
    model.yesterday.reconciliationRows.find((row) => row.key === 'duplicate_orders')?.status,
    '订单级已去重',
  );
  assert.match(
    model.yesterday.reconciliationRows.find((row) => row.key === 'duplicate_orders')?.value || '',
    /1 条重复订单版本/,
  );
  assert.equal(
    model.yesterday.reconciliationRows.find((row) => row.key === 'summary_representation')?.status,
    '不可核验',
  );
  assert.match(
    model.yesterday.reconciliationRows.find((row) => row.key === 'summary_representation')?.value || '',
    /缺少同口径可比指标/,
  );
  assert.match(model.yesterday.reconciliationRows.find((row) => row.key === 'floor_vs_sales')?.value || '', /最低保护价/);
  assert.match(model.yesterday.summary, /两个口径分开显示/);
});

test('floor reconciliation shows verified sales evidence without inventing a floor price', () => {
  const layer = buildRevenueFactLayer();
  const floorCheck = layer.reconciliation.checks.find(
    (row) => row.key === 'floor_vs_sales',
  );
  floorCheck.status = 'incomplete';
  floorCheck.minimum_floor_price = null;
  floorCheck.combined_ota_adr = null;
  floorCheck.reference_gap = null;
  floorCheck.floor_evidence = {
    status: 'missing',
    minimum_floor_price: null,
    forbidden_substitutes: [
      'ota_lead_price',
      'ota_sales_avg_price',
      'historical_adr',
      'competitor_price',
    ],
  };
  floorCheck.sales_evidence = {
    ctrip: {
      status: 'available',
      value: 8468,
      finality: 'provisional',
    },
    meituan: {
      status: 'conflicted',
      value: 2853.3,
      finality: 'provisional',
      current_snapshot_conflict: {
        selected_value: 2853.3,
        candidate_value: 2570.52,
        absolute_delta: 282.78,
        delta_percent_of_selected: 9.91,
      },
    },
  };
  floorCheck.detail = '已取得 2/2 个平台的目标日OTA销售收入证据；最低保护价未取得，当前不可相减。';

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: layer,
  });
  const row = model.yesterday.reconciliationRows.find(
    (item) => item.key === 'floor_vs_sales',
  );

  assert.match(row?.value || '', /销售证据 2\/2/);
  assert.match(row?.value || '', /最低保护价未取得/);
  assert.match(row?.value || '', /同批表示差 ¥282\.78/);
  assert.match(row?.detail || '', /当前不可相减/);
});

test('representation cleanup never masquerades as order-level deduplication', () => {
  const layer = buildRevenueFactLayer();
  const duplicateCheck = layer.reconciliation.checks.find(
    (row) => row.key === 'duplicate_orders',
  );
  duplicateCheck.status = 'not_checkable';
  duplicateCheck.order_identity_candidate_rows = 0;
  duplicateCheck.order_identity_covered_rows = 0;
  duplicateCheck.suppressed_duplicate_order_rows = 0;
  duplicateCheck.suppressed_representation_rows = 2;

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: layer,
  });

  const row = model.yesterday.reconciliationRows.find(
    (item) => item.key === 'duplicate_orders',
  );
  assert.equal(row?.status, '不可核验');
  assert.match(row?.value || '', /仅归并 2 条重复表示，订单级未核验/);
});

test('newer untrusted order version remains visible as a review warning', () => {
  const layer = buildRevenueFactLayer();
  const duplicateCheck = layer.reconciliation.checks.find(
    (row) => row.key === 'duplicate_orders',
  );
  duplicateCheck.status = 'partial';
  duplicateCheck.order_identity_candidate_rows = 2;
  duplicateCheck.order_identity_covered_rows = 1;
  duplicateCheck.suppressed_duplicate_order_rows = 1;
  duplicateCheck.newer_untrusted_duplicate_order_rows = 1;
  duplicateCheck.detail = '另有 1 条时间更新但未通过信任门的同订单版本，需复核。';

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: layer,
  });

  const row = model.yesterday.reconciliationRows.find(
    (item) => item.key === 'duplicate_orders',
  );
  assert.equal(row?.status, '部分可对照');
  assert.match(row?.value || '', /1 条重复订单版本/);
  assert.match(row?.detail || '', /未通过信任门/);
});

test('PMS cards require their own fact status even when the envelope and value look ready', () => {
  const layer = buildRevenueFactLayer();
  layer.facts.whole_hotel_accommodation.payment_collected_amount = 1888;
  layer.facts.whole_hotel_accommodation.sellable_room_nights = 15;
  layer.sources.dingdandao_pms.fact_statuses.payment_collected_amount = {
    status: 'missing',
  };
  layer.sources.dingdandao_pms.fact_statuses.sellable_room_nights = {
    status: 'not_calculable',
  };

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: layer,
  });

  const payment = model.yesterday.wholeHotelFacts.find(
    (fact) => fact.key === 'payment_collected_amount',
  );
  const sellable = model.yesterday.wholeHotelFacts.find(
    (fact) => fact.key === 'sellable_room_nights',
  );
  assert.equal(payment?.ready, false);
  assert.equal(payment?.value, '未取得');
  assert.equal(sellable?.ready, false);
  assert.equal(sellable?.value, '未取得');
});

test('verified OTA metrics remain visible when the strict platform envelope is partial', () => {
  const partialLayer = buildRevenueFactLayer();
  partialLayer.date_alignment.status = 'incomplete';
  partialLayer.date_alignment.comparison_allowed = false;
  partialLayer.date_alignment.sources.ctrip_ota = {
    observed_date: '2026-07-23',
    data_status: 'partial',
  };
  partialLayer.date_alignment.sources.meituan_ota = {
    observed_date: null,
    data_status: 'missing',
  };
  partialLayer.sources.ctrip_ota.data_status = 'partial';
  partialLayer.sources.ctrip_ota.fact_statuses = {
    ...partialLayer.sources.ctrip_ota.fact_statuses,
    revenue: { status: 'readback_verified' },
    orders: { status: 'readback_verified' },
    room_nights: { status: 'readback_verified' },
    adr: { status: 'readback_verified' },
  };
  partialLayer.sources.meituan_ota = {
    data_status: 'missing',
    actual_business_date: null,
    fact_statuses: {},
  };
  partialLayer.facts.ota_channel.ctrip = {
    ...partialLayer.facts.ota_channel.ctrip,
    revenue: 8468,
    orders: 0,
    room_nights: 2,
    adr: 973,
  };
  partialLayer.facts.ota_channel.meituan = {
    orders: null,
    room_nights: null,
    adr: null,
    list_exposure: null,
    detail_exposure: null,
    flow_rate_percent: null,
    submit_rate_percent: null,
    cancellation_rate_percent: null,
  };
  partialLayer.facts.ota_channel.combined = {
    revenue: null,
    orders: null,
    room_nights: null,
    adr: null,
  };
  partialLayer.facts.ota_channel.combined_status = {
    data_status: 'partial',
    fact_statuses: {
      revenue: { status: 'not_verified' },
      orders: { status: 'not_verified' },
      room_nights: { status: 'not_verified' },
      adr: { status: 'not_calculable' },
    },
  };
  partialLayer.derived_metrics.ota_adr = { status: 'not_calculable', value: null };
  partialLayer.derived_metrics.ota_room_night_share_percent = { status: 'not_calculable', value: null };
  partialLayer.derived_metrics.ota_room_revenue_share_percent = { status: 'not_calculable', value: null };
  partialLayer.derived_metrics.ota_cancellation_rate_percent = { status: 'not_calculable', value: null };

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: partialLayer,
  });
  const ctrip = model.yesterday.otaPlatformRows.find((row) => row.key === 'ctrip');
  const meituan = model.yesterday.otaPlatformRows.find((row) => row.key === 'meituan');

  assert.equal(ctrip?.status, '部分取得');
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-revenue')?.ready, true);
  assert.match(ctrip?.facts.find((fact) => fact.key === 'ctrip-revenue')?.value || '', /8,?468/);
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-orders')?.ready, true);
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-orders')?.value, '0单');
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-room-nights')?.value, '2间夜');
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-adr')?.ready, true);
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-detail-exposure')?.ready, true);
  assert.equal(meituan?.facts.every((fact) => fact.ready === false), true);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_orders')?.ready, false);
  assert.equal(
    model.yesterday.dateSourceRows.find((row) => row.key === 'ctrip_ota')?.status,
    '同日部分已验证',
  );
});

test('combined OTA cards preserve verified orders and room nights when revenue analysis is blocked', () => {
  const partialLayer = buildRevenueFactLayer();
  partialLayer.sources.ctrip_ota.data_status = 'partial';
  partialLayer.sources.meituan_ota.data_status = 'partial';
  partialLayer.sources.meituan_ota.analysis_readiness = {
    allowed: false,
    status: 'blocked_representation_conflict',
  };
  partialLayer.facts.ota_channel.combined = {
    revenue: null,
    orders: 5,
    room_nights: 7,
    adr: null,
  };
  partialLayer.facts.ota_channel.combined_status = {
    data_status: 'partial_readback_verified',
    fact_statuses: {
      revenue: { status: 'analysis_blocked' },
      orders: { status: 'readback_verified' },
      room_nights: { status: 'readback_verified' },
      adr: { status: 'not_calculable' },
    },
  };
  partialLayer.derived_metrics.ota_adr = {
    status: 'not_calculable',
    value: null,
  };
  partialLayer.derived_metrics.ota_room_night_share_percent = {
    status: 'ready',
    value: 46.67,
  };
  partialLayer.derived_metrics.ota_room_revenue_share_percent = {
    status: 'not_calculable',
    value: null,
  };

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: 'metric-level fixture',
    selectedHotelId: 80,
    revenueFactLayer: partialLayer,
  });

  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_orders')?.ready,
    true,
  );
  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_nights')?.ready,
    true,
  );
  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_adr')?.ready,
    false,
  );
  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_night_share_percent')?.ready,
    true,
  );
  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_revenue_share_percent')?.ready,
    false,
  );
  assert.equal(model.yesterday.dualScopeReady, false);
});

test('combined OTA cards reject a platform whose actual business date differs', () => {
  const mismatchedLayer = buildRevenueFactLayer();
  mismatchedLayer.sources.meituan_ota.actual_business_date = '2026-07-22';

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: 'date-boundary fixture',
    selectedHotelId: 80,
    revenueFactLayer: mismatchedLayer,
  });

  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_orders')?.ready,
    false,
  );
  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_nights')?.ready,
    false,
  );
  assert.equal(
    model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_adr')?.ready,
    false,
  );
  assert.equal(model.yesterday.dualScopeReady, false);
});

test('a strict fact layer for another date never raises target-day summary readiness', () => {
  const otherDateLayer = buildRevenueFactLayer();
  otherDateLayer.business_date = '2026-07-24';
  otherDateLayer.date_alignment.target_business_date = '2026-07-24';
  Object.values(otherDateLayer.date_alignment.sources).forEach((sourceRow) => {
    sourceRow.observed_date = '2026-07-24';
  });
  Object.values(otherDateLayer.sources).forEach((sourceRow) => {
    sourceRow.actual_business_date = '2026-07-24';
  });

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: 'date-scope fixture',
    selectedHotelId: 80,
    revenueFactLayer: otherDateLayer,
  });

  assert.equal(model.yesterday.date, '2026-07-23');
  assert.equal(model.yesterday.status, '未取得');
  assert.equal(model.yesterday.otaChannelFacts.every((fact) => fact.ready === false), true);
  assert.match(model.yesterday.summary, /当前回读日 2026-07-24 不用于替代或计入完成度/);
  assert.doesNotMatch(model.yesterday.summary, /4\/4/);
  assert.match(model.yesterday.sourceText, /目标日 2026-07-23 未命中/);
});

test('fact readback is labeled as analysis limited when revenue credibility blocks', () => {
  const limitedLayer = buildRevenueFactLayer();
  limitedLayer.sources.meituan_ota.analysis_readiness = {
    allowed: false,
    status: 'blocked',
  };

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: limitedLayer,
  });
  const meituan = model.yesterday.otaPlatformRows.find((row) => row.key === 'meituan');

  assert.equal(meituan?.status, '事实已回读·分析受限');
  assert.equal(meituan?.facts.find((fact) => fact.key === 'meituan-revenue')?.ready, true);
  assert.match(meituan?.facts.find((fact) => fact.key === 'meituan-revenue')?.value || '', /1,?032\.39/);
});

test('platform raw metrics reject derived status while ADR may use it', () => {
  const partialLayer = buildRevenueFactLayer();
  partialLayer.sources.ctrip_ota.data_status = 'partial';
  partialLayer.sources.ctrip_ota.fact_statuses.orders = {
    status: 'derived_verified',
  };
  partialLayer.sources.ctrip_ota.fact_statuses.adr = {
    status: 'derived_verified',
  };
  partialLayer.facts.ota_channel.ctrip.orders = 0;
  partialLayer.facts.ota_channel.ctrip.adr = 973;

  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: partialLayer,
  });
  const ctrip = model.yesterday.otaPlatformRows.find((row) => row.key === 'ctrip');

  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-orders')?.ready, false);
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-orders')?.value, '未取得');
  assert.equal(ctrip?.facts.find((fact) => fact.key === 'ctrip-adr')?.ready, true);
  assert.match(ctrip?.facts.find((fact) => fact.key === 'ctrip-adr')?.value || '', /973/);
});

test('date mismatch keeps exact OTA facts visible but blocks PMS and cross-source derivatives', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: buildRevenueFactLayer({ pmsDate: '2026-07-22' }),
  });

  assert.equal(model.yesterday.dualScopeReady, false);
  assert.equal(model.yesterday.dateAlignmentStatus, 'blocked_date_mismatch');
  assert.equal(model.yesterday.wholeHotelFacts.every((fact) => fact.ready === false), true);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_orders')?.ready, true);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_adr')?.ready, true);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_night_share_percent')?.ready, false);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_room_revenue_share_percent')?.ready, false);
  assert.equal(model.yesterday.otaPlatformRows.every((row) => row.status === '已验证'), true);
  assert.equal(model.yesterday.dateSourceRows.find((row) => row.key === 'dingdandao_pms')?.status, '日期错位');
  assert.equal(model.yesterday.reconciliationStatus, '日期阻断');
  assert.match(model.yesterday.summary, /不会自动改日或混合口径/);
});

test('exact fact layer remains usable when the temporal insight request fails', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData: {
      metric_scope: 'ota_channel',
      past: { status: 'empty', series: [] },
      present: { status: 'empty', snapshot_row_count: 0 },
      future: { status: 'empty', series: [] },
    },
    hotelName: '测试酒店',
    selectedHotelId: 80,
    revenueFactLayer: buildRevenueFactLayer(),
    error: '时间趋势接口暂时不可用',
  });

  assert.equal(model.yesterday.date, '2026-07-23');
  assert.equal(model.yesterday.status, '部分取得');
  assert.equal(model.yesterday.dualScopeReady, false);
  assert.equal(model.yesterday.wholeHotelFacts.find((fact) => fact.key === 'sold_room_nights')?.ready, true);
  assert.equal(model.yesterday.otaChannelFacts.find((fact) => fact.key === 'ota_orders')?.ready, true);
  assert.match(model.yesterday.summary, /两个口径分开显示/);
});

test('fact layer from another hotel is never reused after hotel selection changes', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '所选酒店',
    selectedHotelId: 80,
    revenueFactLayer: buildRevenueFactLayer({ hotelId: 81 }),
  });

  assert.equal(model.yesterday.requiresHotelSelection, false);
  assert.equal(model.yesterday.hotelScopeMismatch, true);
  assert.equal(model.yesterday.dualScopeReady, false);
  assert.equal(model.yesterday.wholeHotelFacts.every((fact) => fact.ready === false), true);
  assert.equal(model.yesterday.otaChannelFacts.every((fact) => fact.ready === false), true);
  assert.match(model.yesterday.summary, /不属于所选门店/);
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

test('one upstream fact-layer error is grouped once instead of repeated across every metric card', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '根因聚合酒店',
    selectedHotelId: 80,
    revenueFactLayer: null,
    revenueFactLayerError: '基础经营事实接口暂时不可用',
  });

  assert.equal(model.yesterday.status, '读取失败');
  assert.equal(model.yesterday.blockingIssues.length, 1);
  assert.equal(model.yesterday.blockingIssues[0].key, 'fact-layer-request');
  assert.match(model.yesterday.summary, /各指标暂不重复报错/);
  assert.ok(model.yesterday.wholeHotelFacts.every((fact) => fact.status === '受上游阻断'));
  assert.ok(model.yesterday.otaChannelFacts.every((fact) => fact.status === '受上游阻断'));
  assert.ok(model.yesterday.otaPlatformRows.every((row) => row.status === '受上游阻断'));
  assert.ok(model.yesterday.wholeHotelFacts.every((fact) => fact.value === '未读取'));
});

test('missing same-day sources are summarized as one primary blocker without inventing facts', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '三源缺口酒店',
    selectedHotelId: 80,
    revenueFactLayer: null,
  });

  assert.equal(model.yesterday.blockingIssues.length, 1);
  assert.equal(model.yesterday.blockingIssues[0].key, 'three-source-readback-incomplete');
  assert.match(model.yesterday.blockingIssues[0].detail, /PMS实际业务日、携程实际业务日、美团实际业务日/);
  assert.ok(model.yesterday.wholeHotelFacts.every((fact) => fact.ready === false));
  assert.ok(model.yesterday.otaChannelFacts.every((fact) => fact.ready === false));
});

test('selected business date wins over a stale temporal range and old fact-layer date', () => {
  const model = buildHomeBusinessTimeModel({
    temporalData,
    hotelName: '日期切换酒店',
    selectedHotelId: 80,
    selectedBusinessDate: '2026-07-22',
    revenueFactLayer: buildRevenueFactLayer(),
  });

  assert.equal(model.yesterday.date, '2026-07-22');
  assert.equal(model.yesterday.dualScopeReady, false);
  assert.ok(model.yesterday.wholeHotelFacts.every((fact) => fact.ready === false));
  assert.match(model.yesterday.summary, /当前回读日 2026-07-23 不用于替代/);
  assert.equal(model.timeline[0].label, '业务日事实');
});

test('home static cache key matches the shipped business time model content', () => {
  const runtime = readFileSync('public/app-startup-helpers.min.js');
  const hash = createHash('sha256').update(runtime).digest('hex').slice(0, 10);
  assert.match(publicIndex, new RegExp(`app-startup-helpers\\.min\\.js\\?v=[^"']*h${hash}`));
});
