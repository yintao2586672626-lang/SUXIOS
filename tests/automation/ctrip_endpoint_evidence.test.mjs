import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildCtripEndpointEvidenceDraftsFromCapture,
  buildCtripEndpointEvidenceMatrix,
  buildCtripEndpointEvidenceTemplates,
  renderCtripEndpointEvidenceMatrixMarkdown,
  renderCtripEndpointEvidenceMarkdown,
  renderCtripEndpointEvidenceTemplatesMarkdown,
  validateCtripEndpointEvidenceBundle,
} from '../../scripts/lib/ctrip_endpoint_evidence.mjs';
import { extractCtripCatalogFacts, findCtripEndpointByUrl } from '../../scripts/lib/ctrip_capture_catalog.mjs';

test('generates P3 evidence templates for every Ctrip candidate direction', () => {
  const templates = buildCtripEndpointEvidenceTemplates({
    generatedAt: '2026-05-31T12:00:00.000Z',
  });

  assert.equal(templates.platform, 'ctrip');
  assert.equal(templates.status, 'templates_ready_pending_redacted_evidence');
  assert.equal(templates.summary.candidate_section_count, 6);

  const bySection = new Map(templates.templates.map((item) => [item.candidate_section, item]));
  for (const section of ['traffic_report', 'orders_detail', 'price_inventory', 'promotion', 'settlement_finance', 'contract_mice_rfp']) {
    const template = bySection.get(section);
    assert.ok(template, section);
    assert.equal(template.evidence_status, 'missing_evidence');
    assert.equal(template.safe_to_catalog, false);
    assert.equal(template.required_evidence.includes('Request URL'), true);
    assert.equal(template.required_evidence.includes('Payload'), true);
    assert.equal(template.required_evidence.includes('Preview / Response'), true);
    assert.equal(template.request_template.request_url, '');
    assert.equal(template.request_template.payload.hotelId, '<hotel or node id>');
    assert.equal(template.acceptance_criteria.some((item) => item.includes('业务字段')), true);
  }

  const markdown = renderCtripEndpointEvidenceTemplatesMarkdown(templates);
  assert.match(markdown, /# 携程 P3 接口证据采样模板/);
  assert.match(markdown, /订单明细/);
  assert.match(markdown, /价格房态/);
  assert.match(markdown, /npm run validate:ctrip-endpoint-evidence/);
  assert.equal(markdown.includes('secret-cookie'), false);
});

test('marks complete Ctrip DevTools evidence as ready for field mapping and redacts secrets', () => {
  const result = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch?_fxpcqlniredt=0903&x-traceID=trace',
    method: 'POST',
    headers: {
      Cookie: 'usertoken=secret-cookie',
      Authorization: 'Bearer secret-token',
    },
    payload: {
      nodeId: 'ctrip-1001',
      startDate: '2026-05-31',
      endDate: '2026-05-31',
    },
    response: {
      ResponseStatus: { Ack: 'Success' },
      data: {
        orderList: [{
          orderId: 'CTRIP-ORDER-001',
          guestName: 'Alice Zhang',
          guestPhone: '13812345678',
          orderCount: 1,
          roomNights: 2,
          orderAmount: '588.00',
          orderStatus: 'confirmed',
        }],
      },
    },
    page_context: {
      page: '订单管理',
      tab: '订单明细',
    },
    params: {
      hotel_id: 'ctrip-1001',
      data_date: '2026-05-31',
    },
  }, {
    capturedAt: '2026-05-31T09:00:00.000Z',
  });

  assert.equal(result.candidate_section, 'orders_detail');
  assert.equal(result.catalog_ready, true);
  assert.equal(result.evidence_status, 'complete_redacted');
  assert.deepEqual(result.missing_evidence, []);
  assert.equal(result.detected_response_keys.includes('roomNights'), true);
  assert.equal(result.detected_response_keys.includes('orderAmount'), true);
  assert.equal(result.field_mapping_draft.status, 'draft_pending_review');
  assert.equal(result.field_mapping_draft.ready_for_mapping, true);
  assert.equal(result.field_mapping_draft.safe_to_auto_apply, false);
  assert.equal(result.field_mapping_draft.fields.some((field) => field.source_key === 'orderAmount' && field.suggested_field_id === 'order_amount'), true);
  assert.equal(result.field_mapping_draft.fields.some((field) => field.source_key === 'roomNights' && field.standard_row_column === 'quantity'), true);
  assert.equal(result.field_mapping_draft.fields.some((field) => field.source_key === 'orderCount' && field.standard_row_column === 'book_order_num'), true);
  const orderIdDraft = result.field_mapping_draft.fields.find((field) => field.source_key === 'orderId');
  assert.equal(orderIdDraft?.privacy_handling, 'hash');
  assert.equal(orderIdDraft?.include_in_business_metrics, false);
  const guestPhoneDraft = result.field_mapping_draft.fields.find((field) => field.source_key === 'guestPhone');
  assert.equal(guestPhoneDraft?.privacy_handling, 'mask');
  assert.equal(guestPhoneDraft?.include_in_business_metrics, false);

  const encoded = JSON.stringify(result.redacted_bundle);
  assert.equal(encoded.includes('secret-cookie'), false);
  assert.equal(encoded.includes('secret-token'), false);
  assert.equal(encoded.includes('CTRIP-ORDER-001'), false);
  assert.equal(encoded.includes('Alice Zhang'), false);
  assert.equal(encoded.includes('13812345678'), false);
  assert.match(encoded, /order_id_hash/);
  assert.match(encoded, /guest_name_masked/);
  assert.match(encoded, /guest_phone_masked/);

  const markdown = renderCtripEndpointEvidenceMarkdown(result);
  assert.equal(markdown.includes('## 字段映射候选'), true);
  assert.equal(markdown.includes('order_amount'), true);
  assert.equal(markdown.includes('orders_detail'), true);
  assert.equal(markdown.includes('complete_redacted'), true);
});

test('previews standard rows for cataloged Ctrip endpoint evidence', () => {
  const rawRequestUrl = 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData?_fxpcqlniredt=0903&x-traceID=trace&token=query-secret-must-not-persist';
  const result = validateCtripEndpointEvidenceBundle({
    request_url: rawRequestUrl,
    method: 'POST',
    payload: {
      nodeId: 'ctrip-1001',
      startDate: '2026-05-31',
      endDate: '2026-05-31',
    },
    response: {
      data: {
        realTimeDataItems: [
          { key: 'UV', name: 'APP 访客量', value: '5', rank2: '14/22' },
          { key: 'OrderAmount', name: '预订销售额', value: '309.00', rank2: '16/22' },
          { key: 'OccupiedRooms', name: '在店间夜', value: '4' },
          { key: 'orderQuantity', name: '预订订单数', value: '1' },
        ],
        lossOrderDetail: {
          lossOrderCount: 5,
          targetUrl: '/datacenter/inland/marketanalysis/flowanalysis',
        },
      },
    },
    page_context: {
      page: '数据中心首页',
      tab: '实时概览',
    },
    params: {
      hotel_id: 'ctrip-1001',
      hotel_name: 'Demo Hotel',
      system_hotel_id: 7,
      data_date: '2026-05-31',
    },
  }, {
    capturedAt: '2026-05-31T09:30:00.000Z',
  });

  assert.equal(result.endpoint_id, 'homepage_realtime');
  assert.equal(result.catalog_ready, true);
  assert.equal(result.catalog_preview.catalog_fact_count >= 4, true);
  assert.equal(result.catalog_preview.standard_row_count >= 1, true);
  const core = result.catalog_preview.standard_rows.find((row) => row.amount === 309);
  assert.ok(core);
  assert.equal(core.system_hotel_id, 7);
  assert.equal(core.hotel_id, 'ctrip-1001');
  assert.equal(core.hotel_name, 'Demo Hotel');
  assert.equal(core.book_order_num, 1);
  assert.equal(core.quantity, 4);
  assert.equal(core.detail_exposure, 5);
  const safeRequestUrl = 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData';
  assert.equal(result.request_url, safeRequestUrl);
  assert.equal(result.redacted_bundle.request_url, safeRequestUrl);
  assert.equal(core.raw_data.source_url, safeRequestUrl);
  assert.match(result.source_url_hash, /^[a-f0-9]{64}$/);
  assert.doesNotMatch(JSON.stringify(result), /query-secret-must-not-persist/);
  assert.equal(result.catalog_preview.metric_keys.includes('order_amount'), true);
  assert.equal(result.catalog_preview.metric_keys.includes('visitor_count'), true);
});

test('recursively redacts URL-shaped response facts and page context while retaining value hashes', () => {
  const rawResponseUrl = 'https://response-user:response-pass@ebooking.ctrip.com/offer/detail?token=response-query-secret#response-fragment';
  const rawNestedPageUrl = 'https://page-user:page-pass@ebooking.ctrip.com/order/list?ticket=page-query-secret#page-fragment';
  const rawArrayPageUrl = 'https://array-user:array-pass@ebooking.ctrip.com/order/detail?auth=array-query-secret#array-fragment';
  const result = validateCtripEndpointEvidenceBundle({
    request_url: 'https://request-user:request-pass@ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData?token=request-query-secret#request-fragment',
    method: 'POST',
    payload: {
      nodeId: 'ctrip-1001',
      startDate: '2026-07-22',
      endDate: '2026-07-22',
    },
    response: {
      data: {
        visitorCount: 5,
        lossOrderDetail: {
          targetUrl: rawResponseUrl,
        },
      },
    },
    page_context: {
      page: 'data center',
      nested: {
        configured_url: rawNestedPageUrl,
      },
      tabs: [{ url: rawArrayPageUrl }],
    },
    params: {
      hotel_id: 'ctrip-1001',
      data_date: '2026-07-22',
    },
  }, {
    capturedAt: '2026-07-22T09:30:00.000Z',
  });

  assert.equal(result.request_url, 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData');
  assert.equal(result.redacted_bundle.response.data.lossOrderDetail.targetUrl, 'https://ebooking.ctrip.com/offer/detail');
  assert.match(result.redacted_bundle.response.data.lossOrderDetail.targetUrl_url_hash, /^[a-f0-9]{64}$/);
  assert.equal(result.redacted_bundle.page_context.nested.configured_url, 'https://ebooking.ctrip.com/order/list');
  assert.match(result.redacted_bundle.page_context.nested.configured_url_url_hash, /^[a-f0-9]{64}$/);
  assert.equal(result.redacted_bundle.page_context.tabs[0].url, 'https://ebooking.ctrip.com/order/detail');
  assert.match(result.redacted_bundle.page_context.tabs[0].url_url_hash, /^[a-f0-9]{64}$/);

  const catalogFacts = extractCtripCatalogFacts({
    data: {
      visitorCount: 5,
      lossOrderDetail: { targetUrl: rawResponseUrl },
    },
  }, {
    endpoint: findCtripEndpointByUrl(result.request_url),
    section: 'homepage',
    dataType: 'business',
    hotelId: 'ctrip-1001',
    dataDate: '2026-07-22',
    capturedAt: '2026-07-22T09:30:00.000Z',
    persistRawSourceUrl: false,
  });
  const catalogUrlFact = catalogFacts.find((fact) => fact.metric_key === 'target_url');
  assert.ok(catalogUrlFact);
  assert.equal(catalogUrlFact.value, 'https://ebooking.ctrip.com/offer/detail');
  assert.equal(catalogUrlFact.value_url_hash, result.redacted_bundle.response.data.lossOrderDetail.targetUrl_url_hash);

  const row = result.catalog_preview.standard_rows.find((candidate) => (
    candidate.raw_data?.facts?.some((fact) => fact.metric_key === 'target_url')
  ));
  assert.ok(row);
  const rawFact = row.raw_data.facts.find((fact) => fact.metric_key === 'target_url');
  const fieldFact = row.raw_data.field_facts.find((fact) => fact.metric_key === 'target_url');
  assert.equal(rawFact.value, 'https://ebooking.ctrip.com/offer/detail');
  assert.equal(rawFact.value_url_hash, catalogUrlFact.value_url_hash);
  assert.equal(fieldFact.value, 'https://ebooking.ctrip.com/offer/detail');
  assert.equal(fieldFact.value_url_hash, rawFact.value_url_hash);

  const encoded = JSON.stringify(result);
  for (const secret of [
    'response-user', 'response-pass', 'response-query-secret', 'response-fragment',
    'page-user', 'page-pass', 'page-query-secret', 'page-fragment',
    'array-user', 'array-pass', 'array-query-secret', 'array-fragment',
    'request-user', 'request-pass', 'request-query-secret', 'request-fragment',
  ]) {
    assert.equal(encoded.includes(secret), false, `${secret} must not persist`);
  }
});

test('uses Ctrip sales page context for shared beneficialdata endpoints', () => {
  const result = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/fetchCapacityOverViewV4',
    method: 'POST',
    payload: {
      nodeId: 'ctrip-1001',
      startDate: '2026-06-04',
      endDate: '2026-06-04',
    },
    response: {
      data: {
        orderQuantity: 5,
        occupiedRooms: 5,
        amount: 389,
      },
    },
    page_context: {
      page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true',
      page: '经营报告-销售数据',
      tab: '酒店',
    },
    params: {
      hotel_id: 'ctrip-1001',
      system_hotel_id: 7,
      data_date: '2026-06-04',
    },
  }, {
    capturedAt: '2026-06-04T09:30:00.000Z',
  });

  assert.equal(result.endpoint_id, 'sales_capacity_overview');
  assert.equal(result.candidate_section, 'sales_report');
  assert.equal(result.catalog_ready, true);
  assert.equal(result.catalog_preview.standard_rows.every((row) => row.capture_section === 'sales_report'), true);
});

test('keeps incomplete Ctrip evidence out of the formal catalog', () => {
  const result = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/settlementBillList',
    response: { data: { totalAmount: '900.00' } },
    page_context: { page: '结算财务' },
  });

  assert.equal(result.candidate_section, 'settlement_finance');
  assert.equal(result.catalog_ready, false);
  assert.equal(result.evidence_status, 'incomplete');
  assert.equal(result.field_mapping_draft.ready_for_mapping, false);
  assert.deepEqual(result.field_mapping_draft.fields, []);
  assert.equal(result.missing_evidence.includes('Payload'), true);
  assert.equal(result.missing_evidence.includes('hotel/date parameters'), true);
});

test('builds a P3 evidence coverage matrix from multiple Ctrip endpoint bundles', () => {
  const order = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch',
    method: 'POST',
    payload: { hotelId: 'ctrip-1001', startDate: '2026-05-31' },
    response: { data: { orderList: [{ orderId: 'O-1', roomNights: 2, orderAmount: 588 }] } },
    page_context: { page: '订单管理', tab: '订单明细' },
    params: { hotel_id: 'ctrip-1001', data_date: '2026-05-31' },
  });
  const promotion = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/promotionCampaignList',
    method: 'POST',
    payload: { hotelId: 'ctrip-1001', startDate: '2026-05-31' },
    response: { data: { rows: [{ campaignName: '满减', discountAmount: 20 }] } },
    page_context: { page: '促销推广', tab: '活动列表' },
    params: { hotel_id: 'ctrip-1001', data_date: '2026-05-31' },
  });
  const settlement = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/settlementBillList',
    response: { data: { totalAmount: 900 } },
    page_context: { page: '结算财务' },
  });

  const matrix = buildCtripEndpointEvidenceMatrix([order, promotion, settlement], {
    generatedAt: '2026-05-31T10:00:00.000Z',
  });

  assert.equal(matrix.summary.total_bundles, 3);
  assert.equal(matrix.summary.ready_bundle_count, 2);
  assert.equal(matrix.summary.incomplete_bundle_count, 1);
  assert.equal(matrix.summary.ready_section_count, 2);
  assert.equal(matrix.sections.orders_detail.status, 'ready_for_review');
  assert.equal(matrix.sections.promotion.status, 'ready_for_review');
  assert.equal(matrix.sections.settlement_finance.status, 'incomplete_evidence');
  assert.equal(matrix.sections.traffic_report.status, 'missing_evidence');
  assert.equal(matrix.sections.price_inventory.status, 'missing_evidence');
  assert.equal(matrix.sections.contract_mice_rfp.status, 'missing_evidence');
  assert.deepEqual(matrix.missing_sections, ['traffic_report', 'price_inventory', 'contract_mice_rfp']);
  assert.equal(matrix.next_actions[0].includes('traffic_report'), true);

  const markdown = renderCtripEndpointEvidenceMatrixMarkdown(matrix);
  assert.equal(markdown.includes('## P3 证据覆盖矩阵'), true);
  assert.equal(markdown.includes('orders_detail'), true);
  assert.equal(markdown.includes('price_inventory'), true);
  assert.equal(markdown.includes('missing_evidence'), true);
});

test('builds redacted P3 evidence drafts from browser profile captures', () => {
  const drafts = buildCtripEndpointEvidenceDraftsFromCapture([
    {
      url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch?_fxpcqlniredt=0903&x-traceID=trace',
      status: 200,
      method: 'POST',
      request_type: 'xhr',
      headers: {
        Cookie: 'usertoken=secret-cookie',
        Authorization: 'Bearer secret-token',
      },
      payload: '{"hotelId":"ctrip-1001","startDate":"2026-05-31","endDate":"2026-05-31"}',
      response: {
        ResponseStatus: { Ack: 'Success' },
        data: {
          orderList: [{
            orderId: 'CTRIP-ORDER-001',
            guestName: '张三',
            guestPhone: '13812345678',
            roomNights: 2,
            orderAmount: '588.00',
          }],
        },
      },
      page_context: {
        page: '订单管理',
        tab: '订单明细',
      },
    },
    {
      url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getEbkResourcePopups',
      status: 200,
      method: 'GET',
      response: { data: [] },
    },
  ], {
    profileId: 'profile-59',
    hotelId: 'ctrip-1001',
    defaultDataDate: '2026-05-31',
    capturedAt: '2026-05-31T12:00:00.000Z',
    pageUrl: 'https://ebooking.ctrip.com/order/list?microJump=true',
    activeSection: 'orders_detail',
  });

  assert.equal(drafts.length, 1);
  assert.equal(drafts[0].capture_source, 'browser_profile_candidate');
  assert.equal(drafts[0].review_required, true);
  assert.equal(drafts[0].candidate_section, 'orders_detail');
  assert.equal(drafts[0].evidence_status, 'complete_redacted');
  assert.equal(drafts[0].catalog_ready, true);
  assert.equal(drafts[0].redacted_bundle.payload.hotelId, 'ctrip-1001');
  assert.equal(drafts[0].redacted_bundle.page_context.source, 'browser_profile');
  assert.equal(drafts[0].redacted_bundle.page_context.page_url, 'https://ebooking.ctrip.com/order/list');
  assert.equal(drafts[0].redacted_bundle.params.hotel_id, 'ctrip-1001');
  assert.equal(drafts[0].field_mapping_draft.safe_to_auto_apply, false);

  const encoded = JSON.stringify(drafts[0]);
  assert.equal(encoded.includes('secret-cookie'), false);
  assert.equal(encoded.includes('secret-token'), false);
  assert.equal(encoded.includes('CTRIP-ORDER-001'), false);
  assert.equal(encoded.includes('张三'), false);
  assert.equal(encoded.includes('13812345678'), false);
  assert.match(encoded, /order_id_hash/);
  assert.match(encoded, /guest_name_masked/);
  assert.match(encoded, /guest_phone_masked/);
});
