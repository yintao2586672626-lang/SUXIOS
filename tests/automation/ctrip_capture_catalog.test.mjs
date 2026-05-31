import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import {
  buildCtripEndpointCandidates,
  buildCtripStandardRowsFromFacts,
  ctripCatalogSummary,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
  generateCtripCaptureMarkdown,
  getCtripSectionInteractionPlan,
  normalizeCtripCaptureSections,
} from '../../scripts/lib/ctrip_capture_catalog.mjs';

test('normalizes Ctrip capture presets for core and wide collection', () => {
  assert.deepEqual(normalizeCtripCaptureSections('core'), [
    'homepage',
    'business_overview',
    'sales_report',
    'room_type',
    'traffic_report',
  ]);

  const wide = normalizeCtripCaptureSections('wide');
  for (const section of [
    'homepage',
    'business_overview',
    'sales_report',
    'room_type',
    'traffic_report',
    'competitor_overview',
    'loss_analysis',
    'competitor_rank',
    'user_profile',
    'im_board',
    'ads_pyramid',
    'quality_psi',
    'market_calendar',
    'biztravel_bpi',
    'biztravel_business_report',
    'biztravel_competitor',
  ]) {
    assert.equal(wide.includes(section), true, section);
  }
  assert.equal(new Set(wide).size, wide.length);

  const summary = ctripCatalogSummary();
  assert.equal(summary.interaction_plan_section_count >= 10, true);
  assert.equal(summary.interaction_plan_step_count > summary.interaction_plan_section_count, true);
});

test('defines Ctrip section interaction plans for tabbed capture pages', () => {
  const sales = getCtripSectionInteractionPlan('sales_report').map(step => step.text);
  assert.equal(sales.includes('\u9500\u552e\u6570\u636e'), true);
  assert.equal(sales.includes('\u603b\u5e73\u53f0'), true);
  assert.equal(sales.includes('\u53bb\u54ea\u513f'), true);

  const roomType = getCtripSectionInteractionPlan('room_type').map(step => step.text);
  assert.deepEqual(roomType, ['\u9500\u552e\u6570\u636e', '\u623f\u578b']);

  const traffic = getCtripSectionInteractionPlan('traffic_report').map(step => step.text);
  for (const label of ['\u6d41\u91cf\u6570\u636e', '\u643a\u7a0b', '\u53bb\u54ea\u513f', '\u624b\u673aAPP', '\u7535\u8111\u7f51\u9875\u7248']) {
    assert.equal(traffic.includes(label), true, label);
  }

  const biztravel = getCtripSectionInteractionPlan('biztravel_competitor').map(step => step.text);
  assert.equal(biztravel.includes('\u7ade\u4e89\u5708\u699c\u5355'), true);
  assert.deepEqual(getCtripSectionInteractionPlan('unknown_section'), []);
});

test('prefers the active Ctrip page section for duplicate endpoint keywords', () => {
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/queryHotelMinPriceV1?hostType=Ebooking',
      { preferredSection: 'traffic_report' },
    )?.id,
    'traffic_hotel_min_price',
  );

  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/queryOrderTrendV1',
      { preferredSection: 'traffic_report' },
    )?.id,
    'traffic_order_trend',
  );

  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/getReportSuggestV1',
      { preferredSection: 'business_overview' },
    )?.id,
    'weekly_report',
  );
});

test('covers additional observed Ctrip screenshot endpoints outside review content', () => {
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/queryFlowTransformNewV1?hostType=Ebooking',
      { preferredSection: 'traffic_report' },
    )?.id,
    'traffic_flow_transform',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/datacenter/api/queryMarketDetails')?.id,
    'sales_market_detail',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/datacenter/api/queryVendibilityRoom')?.id,
    'room_venderbility',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/datacenter/api/getMasterHotelLabel')?.id,
    'competitor_hotel_label',
  );
  assert.equal(
    findCtripEndpointByUrl('https://bbk.ctripbiz.cn/api/benefitInfoList')?.id,
    'biztravel_bpi_benefit',
  );
  assert.equal(
    findCtripEndpointByUrl('https://bbk.ctripbiz.cn/api/dataCenterComparisonReportDetail')?.id,
    'biztravel_competitor_report',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/pyramidad/api/getEbkResourceYellowBar?hostType=HE')?.id,
    'ads_resource_yellow_bar',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/pyramidad/api/getDynamicConfig?_fxpcqlniredt=demo')?.id,
    'ads_dynamic_config',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/pyramidad/api/reportInjectFnInfo?_fxpcqlniredt=demo')?.id,
    'ads_report_injection',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/comment/api/listNegativeComment')?.id,
    undefined,
  );
});

test('covers reusable Ctrip platform notice endpoints as support facts only', () => {
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/getEbkResourcePopups')?.id,
    'platform_resource_popups',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/getMultiNotifyMessage')?.id,
    'platform_notifications',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/queryEPush')?.id,
    'platform_notifications',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/collect?metaSender=1.3.81')?.id,
    undefined,
  );

  const endpoint = findCtripEndpointByUrl('https://ebooking.ctrip.com/api/getEbkResourcePopups');
  const facts = extractCtripCatalogFacts({
    data: [{ title: '活动提示', content: '请查看账户余额', targetUrl: '/pyramidad/dataReport' }],
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url: 'https://ebooking.ctrip.com/api/getEbkResourcePopups',
  });
  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: 'Demo Hotel',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].raw_data.fact_only, true);
  assert.equal(rows[0].amount, 0);
  assert.equal(rows[0].book_order_num, 0);
  assert.equal(rows[0].raw_data.dimension_values.notice_title, '活动提示');
});

test('extracts Ctrip metric-pair response items into catalog facts and standard rows', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'homepage_realtime');

  const payload = {
    data: {
      realTimeDataItems: [
        { key: 'UV', name: 'APP 访客量', value: '5', rank2: '14/22' },
        { key: 'OrderAmount', name: '预订销售额', value: '309.00', rank2: '16/22' },
        { key: 'MinPrice', name: '实时起价', value: '289.00' },
        { key: 'HotelRating', name: '点评分', value: '4.5' },
        { key: 'OccupiedRooms', name: '在店间夜', value: '4' },
        { key: 'orderQuantity', name: '预订订单数', value: '1' },
        { key: 'Tensity', name: '紧张度', value: '3.74%' },
      ],
      lossOrderDetail: {
        lossOrderCount: 5,
        targetUrl: '/datacenter/inland/marketanalysis/flowanalysis',
      },
    },
  };

  const facts = extractCtripCatalogFacts(payload, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const metricKeys = new Set(facts.map((fact) => fact.metric_key));
  assert.equal(metricKeys.has('visitor_count'), true);
  assert.equal(metricKeys.has('order_amount'), true);
  assert.equal(metricKeys.has('avg_price'), true);
  assert.equal(metricKeys.has('comment_score_summary'), true);
  assert.equal(metricKeys.has('room_nights'), true);
  assert.equal(metricKeys.has('order_count'), true);
  assert.equal(metricKeys.has('tensity'), true);
  assert.equal(facts.some((fact) => fact.metric_key === 'hotel_name' && fact.value === 'APP 访客量'), false);

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: '长沙智选假日酒店',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });
  const core = rows.find((row) => row.dimension.includes('homepage_realtime') && row.amount === 309);

  assert.ok(core);
  assert.equal(core.system_hotel_id, 7);
  assert.equal(core.hotel_id, 'ctrip-1001');
  assert.equal(core.hotel_name, '长沙智选假日酒店');
  assert.equal(core.data_date, '2026-05-31');
  assert.equal(core.data_type, 'business');
  assert.equal(core.amount, 309);
  assert.equal(core.quantity, 4);
  assert.equal(core.book_order_num, 1);
  assert.equal(core.detail_exposure, 5);
  assert.equal(core.comment_score, 4.5);

  const loss = rows.find((row) => row.raw_data.metrics.loss_order_count === 5);
  assert.ok(loss);
  assert.equal(loss.book_order_num, 5);
});

test('builds standard rows for sales, traffic, competitor, PSI and biztravel endpoints', () => {
  const cases = [
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/queryMarketDetailsV1',
      payload: { data: { rows: [{ statDate: '2026-05-31', orderQuantity: 3, roomNights: 4, orderAmount: '241.72' }] } },
      expected: { data_type: 'business', amount: 241.72, quantity: 4, book_order_num: 3 },
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/queryScanFlowDetailsV2?hostType=Ebooking',
      payload: { data: { rows: [{ statDate: '2026-05-31', listExposure: 48, detailUv: 2, orderFillingNum: 1, orderSubmitNum: 0, flowRate: '4.17%' }] } },
      expected: { data_type: 'traffic', list_exposure: 48, detail_exposure: 2, order_filling_num: 1, flow_rate: 4.17 },
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/getTripartiteOrderLoss',
      payload: { data: { lossOrderCount: 11, lossRoomNight: 16, lossOrderAmount: '5560.04', commonViewRate: '18.31%' } },
      expected: { data_type: 'business', amount: 5560.04, quantity: 16, book_order_num: 11, flow_rate: 18.31 },
    },
    {
      url: 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2?hostType=HE',
      payload: { data: { psiScore: '4.54', baseScore: '4.06', rewardScore: '0.48', replyRate: '100%' } },
      expected: { data_type: 'quality', data_value: 4.54, flow_rate: 100 },
    },
    {
      url: 'https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail',
      payload: { data: { rows: [{ statDate: '2026-05-31', roomNights: 1, amount: '340.00', orderQuantity: 2 }] } },
      expected: { data_type: 'business', amount: 340, quantity: 1, book_order_num: 2 },
    },
  ];

  for (const item of cases) {
    const endpoint = findCtripEndpointByUrl(item.url);
    assert.ok(endpoint, item.url);
    const facts = extractCtripCatalogFacts(item.payload, {
      endpoint,
      section: endpoint.section,
      dataType: endpoint.dataType,
      hotelId: 'ctrip-1001',
      dataDate: '2026-05-31',
      capturedAt: '2026-05-31T03:30:00.000Z',
      url: item.url,
    });
    const rows = buildCtripStandardRowsFromFacts(facts, {
      systemHotelId: 7,
      hotelName: '长沙智选假日酒店',
      profileId: 'ctrip-1001',
      dataDate: '2026-05-31',
    });
    const row = rows.find((candidate) => candidate.data_type === item.expected.data_type);
    assert.ok(row, item.url);
    for (const [key, value] of Object.entries(item.expected)) {
      assert.equal(row[key], value, `${item.url} ${key}`);
    }
  }
});

test('extracts Ctrip competitor comparison cards into self value, peer average and rank rows', () => {
  const url = 'https://ebooking.ctrip.com/datacenter/api/getManagementData';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'competitor_management');

  const facts = extractCtripCatalogFacts({
    data: {
      cards: [
        { name: '预订订单量', myValue: 143, competitorAvg: 144, rank: 7 },
        { name: '预订销售额', myValue: '4.62万', competitorAvg: '5.12万', rank: 10 },
      ],
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-24',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: '长沙智选假日酒店',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-24',
  });

  const orderRow = rows.find((row) => row.raw_data.metrics.order_count === 143);
  assert.ok(orderRow);
  assert.equal(orderRow.book_order_num, 143);
  assert.equal(orderRow.raw_data.metrics.competitor_average, 144);
  assert.equal(orderRow.raw_data.metrics.rank, 7);
  assert.equal(orderRow.compare_type, 'competitor');

  const amountRow = rows.find((row) => row.amount === 46200);
  assert.ok(amountRow);
  assert.equal(amountRow.raw_data.metrics.competitor_average, 51200);
  assert.equal(amountRow.raw_data.metrics.rank, 10);
});

test('keeps non-numeric Ctrip facts as standard rows without inventing metrics', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'hot_calendar');

  const facts = extractCtripCatalogFacts({
    otherDataList: [{
      hotSpotName: 'Concert A',
      startDate: '2026-06-06',
      endDate: '2026-06-06',
    }],
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: 'Demo Hotel',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'business');
  assert.equal(rows[0].data_date, '2026-06-06');
  assert.equal(rows[0].amount, 0);
  assert.equal(rows[0].quantity, 0);
  assert.equal(rows[0].book_order_num, 0);
  assert.equal(rows[0].raw_data.fact_only, true);
  assert.equal(rows[0].raw_data.metric_status, 'non_numeric_fact');
  assert.equal(rows[0].raw_data.dimension_values.hot_spot_name, 'Concert A');
  assert.equal(rows[0].raw_data.metrics.hot_spot_name, 'Concert A');
});

test('keeps numeric Ctrip support facts out of operating metrics', () => {
  const url = 'https://ebooking.ctrip.com/pyramidad/api/getDynamicConfig?hostType=HE';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'ads_dynamic_config');

  const facts = extractCtripCatalogFacts({
    data: {
      items: [{
        configKey: 'showBudgetWarning',
        value: 1,
        title: '账户余额提醒',
      }],
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: 'Demo Hotel',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'advertising');
  assert.equal(rows[0].amount, 0);
  assert.equal(rows[0].quantity, 0);
  assert.equal(rows[0].book_order_num, 0);
  assert.equal(rows[0].data_value, 0);
  assert.equal(rows[0].raw_data.fact_only, true);
  assert.equal(rows[0].raw_data.metric_status, 'non_numeric_fact');
  assert.equal(rows[0].raw_data.dimension_values.config_value, 1);
});

test('classifies unmatched Ctrip URLs into evidence candidates for P3 catalog work', () => {
  const candidates = buildCtripEndpointCandidates([
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/rateCalendarPriceQuery' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/promotionCampaignList' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/settlementBillList' },
    { url: 'https://bbk.ctripbiz.cn/api/miceRfpQuoteSearch' },
    { url: 'https://bbk.ctripbiz.cn/api/agreementContractSearch' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/getEbkResourcePopups' },
  ]);

  const byUrl = new Map(candidates.map((item) => [item.url, item]));
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch')?.candidate_section, 'orders_detail');
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/rateCalendarPriceQuery')?.candidate_section, 'price_inventory');
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/promotionCampaignList')?.candidate_section, 'promotion');
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/settlementBillList')?.candidate_section, 'settlement_finance');
  assert.equal(byUrl.get('https://bbk.ctripbiz.cn/api/miceRfpQuoteSearch')?.candidate_section, 'contract_mice_rfp');
  assert.equal(byUrl.get('https://bbk.ctripbiz.cn/api/agreementContractSearch')?.candidate_section, 'contract_mice_rfp');
  assert.equal(byUrl.has('https://ebooking.ctrip.com/restapi/soa2/12345/getEbkResourcePopups'), false);

  const order = byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch');
  assert.equal(order.evidence_status, 'needs_payload_response');
  assert.equal(order.safe_to_catalog, false);
  assert.equal(order.required_evidence.includes('Request URL'), true);
  assert.equal(order.required_evidence.includes('Payload'), true);
  assert.equal(order.required_evidence.includes('Preview / Response'), true);
});

test('renders Ctrip i18n metric definitions into the generated field inventory', () => {
  const markdown = generateCtripCaptureMarkdown({
    i18nReference: {
      source: '携程酒店商家后台 i18n 语言包 (zh-CN)',
      total_modules: 9,
      total_entries: 6771,
      matched_terms: ['预订订单数'],
      metric_definitions: [{
        term: '预订订单数',
        definition: '统计周期内全平台预订订单量合计，不含取消订单',
        source_key: 'Key.DataCenter.IndexType.Order.HoverText',
      }],
    },
  });

  assert.match(markdown, /### 指标口径速查/);
  assert.match(markdown, /预订订单数/);
  assert.match(markdown, /Key\.DataCenter\.IndexType\.Order\.HoverText/);
});

test('renders project logic from Ctrip fields to operating review', () => {
  const markdown = generateCtripCaptureMarkdown();

  assert.match(markdown, /项目文字描述统一为/);
  assert.match(markdown, /诊断、动作、复盘、沉淀/);
  assert.match(markdown, /## 字段到业务动作的链路/);
  assert.match(markdown, /## 字段进入系统的判定顺序/);
  assert.match(markdown, /采集证据/);
  assert.match(markdown, /标准事实/);
  assert.match(markdown, /经营诊断/);
  assert.match(markdown, /效果复盘/);
  assert.match(markdown, /--fail-on-gate/);
  assert.match(markdown, /Capture Gate/);
});

test('keeps i18n terminology as naming reference instead of auto-approved fields', () => {
  const markdown = generateCtripCaptureMarkdown({
    i18nReference: {
      source: '携程酒店商家后台 i18n 语言包 (zh-CN)',
      total_modules: 9,
      total_entries: 6771,
      matched_terms: ['预订订单数', 'PSI'],
      metric_definitions: [],
    },
  });

  assert.match(markdown, /i18n 只作为命名和页面语义参考/);
  assert.match(markdown, /翻译包本身不是业务数据/);
  assert.match(markdown, /前端埋点上报代码不能直接生成经营指标/);
  assert.match(markdown, /正式字段仍以接口证据、source path 和可复现上下文为准/);
  assert.match(markdown, /竞争圈、商旅、广告和 OTA 零售渠道必须分开表达/);
});

test('catalog verifier can resolve i18n terminology from SUXIOS_CTRIP_I18N_FILE env', () => {
  const dir = mkdtempSync(join(tmpdir(), 'ctrip-i18n-'));
  const i18nPath = join(dir, 'i18n_translations.json');
  writeFileSync(i18nPath, JSON.stringify({
    meta: { source: 'fixture_i18n_translations.json', total_modules: 1, total_entries: 3 },
    modules: {
      datacenter: {
        entries: {
          'Key.DataCenter.IndexType.Order.HoverText': '预订订单数：统计所选日期内的订单数量。',
          'Key.DataCenter.IndexType.Sale.HoverText': '预订销售额：统计所选日期内的预订金额。',
          'Key.DataCenter.IndexType.ListExposure.Title': '列表页曝光量',
        },
      },
    },
  }), 'utf8');

  const output = execFileSync('node', ['scripts/verify_ctrip_capture_catalog.mjs', '--json'], {
    cwd: process.cwd(),
    encoding: 'utf8',
    env: {
      ...process.env,
      SUXIOS_CTRIP_I18N_FILE: i18nPath,
    },
  });
  const summary = JSON.parse(output);

  assert.equal(summary.i18n_reference.source, 'fixture_i18n_translations.json');
  assert.equal(summary.i18n_reference.matched_terms.includes('预订订单数'), true);
  assert.equal(summary.i18n_reference.metric_definitions.some((item) => item.term === '预订销售额'), true);
});
