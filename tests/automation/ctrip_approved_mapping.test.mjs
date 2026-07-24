import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildCtripApprovedMappingDryRun,
  buildCtripApprovedMappingCandidateFromEvidence,
  buildCtripApprovedMappingCandidatesFromCapture,
  extractCtripApprovedMappingRows,
  normalizeCtripApprovedMappings,
  renderCtripApprovedMappingDryRunMarkdown,
} from '../../scripts/lib/ctrip_approved_mapping.mjs';
import { validateCtripEndpointEvidenceBundle } from '../../scripts/lib/ctrip_endpoint_evidence.mjs';

test('approved mapping rows can omit raw request URLs for live persistence', () => {
  const mappings = normalizeCtripApprovedMappings({
    mappings: [{
      id: 'safe_live_mapping',
      approved: true,
      candidate_section: 'orders_detail',
      data_type: 'order',
      url_keywords: ['orderDetailSearch'],
      fields: [
        { source_path: 'data.orderList.*.orderAmount', suggested_field_id: 'order_amount', standard_row_column: 'amount', include_in_business_metrics: true },
      ],
    }],
  });

  const rows = extractCtripApprovedMappingRows({
    data: { orderList: [{ orderAmount: '588.00' }] },
  }, {
    mappings,
    url: 'https://ebooking.ctrip.com/restapi/orderDetailSearch?token=query-secret-must-not-persist',
    dataDate: '2026-07-22',
    persistRawSourceUrl: false,
  });

  assert.equal(rows.length, 1);
  assert.equal(Object.hasOwn(rows[0].raw_data, 'source_url'), false);
  assert.doesNotMatch(JSON.stringify(rows), /query-secret-must-not-persist/);
});

test('approved mapping dry-run redacts request and response URL secrets while retaining hashes', () => {
  const result = buildCtripApprovedMappingDryRun({
    evidence: {
      request_url: 'https://request-user:request-pass@ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch?token=request-query-secret#request-fragment',
      response: {
        data: {
          orderList: [{
            orderAmount: '588.00',
            targetUrl: 'https://response-user:response-pass@ebooking.ctrip.com/order/detail?ticket=response-query-secret#response-fragment',
          }],
        },
      },
      params: {
        hotel_id: 'ctrip-1001',
        data_date: '2026-07-22',
      },
    },
    mappingConfig: {
      mappings: [{
        id: 'safe_url_mapping',
        approved: true,
        candidate_section: 'orders_detail',
        data_type: 'order',
        url_keywords: ['orderDetailSearch'],
        fields: [
          { source_path: 'data.orderList.*.orderAmount', suggested_field_id: 'order_amount', standard_row_column: 'amount', include_in_business_metrics: true },
          { source_path: 'data.orderList.*.targetUrl', suggested_field_id: 'target_url', include_in_business_metrics: false },
        ],
      }],
    },
    generatedAt: '2026-07-22T10:00:00.000Z',
  });

  assert.equal(result.status, 'pass');
  assert.equal(result.request_url, 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch');
  assert.match(result.source_url_hash, /^[a-f0-9]{64}$/);
  assert.equal(result.standard_rows[0].raw_data.source_url, result.request_url);
  assert.equal(result.standard_rows[0].raw_data.source_url_hash, result.source_url_hash);
  assert.equal(result.standard_rows[0].raw_data.fields.target_url, 'https://ebooking.ctrip.com/order/detail');
  assert.match(result.standard_rows[0].raw_data.fields.target_url_url_hash, /^[a-f0-9]{64}$/);

  const encoded = JSON.stringify(result);
  for (const secret of [
    'request-user', 'request-pass', 'request-query-secret', 'request-fragment',
    'response-user', 'response-pass', 'response-query-secret', 'response-fragment',
  ]) {
    assert.equal(encoded.includes(secret), false, `${secret} must not persist`);
  }
});

test('approved mapping keeps missing standard metrics null instead of inventing zero facts', () => {
  const mappings = normalizeCtripApprovedMappings({
    mappings: [{
      id: 'missing_metric_truth',
      approved: true,
      candidate_section: 'orders_detail',
      data_type: 'order',
      url_keywords: ['orderDetailSearch'],
      fields: [
        { source_path: 'data.orderList.*.roomNights', suggested_field_id: 'room_nights', standard_row_column: 'quantity', include_in_business_metrics: true },
        { source_path: 'data.orderList.*.orderAmount', suggested_field_id: 'order_amount', standard_row_column: 'amount', include_in_business_metrics: true },
      ],
    }],
  });

  const rows = extractCtripApprovedMappingRows({
    data: { orderList: [{ roomNights: 2 }] },
  }, {
    mappings,
    url: 'https://ebooking.ctrip.com/restapi/orderDetailSearch',
    dataDate: '2026-07-22',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].quantity, 2);
  assert.equal(rows[0].amount, null);
  assert.equal(rows[0].book_order_num, null);
  assert.deepEqual(rows[0].raw_data.facts.map((fact) => fact.metric_key), ['room_nights']);
  assert.deepEqual(rows[0].raw_data.field_facts.map((fact) => fact.metric_key), ['room_nights']);
});

test('approved mapping retains an all-zero source row and records captured field facts', () => {
  const mappings = normalizeCtripApprovedMappings({
    mappings: [{
      id: 'zero_metric_truth',
      approved: true,
      candidate_section: 'orders_detail',
      data_type: 'order',
      url_keywords: ['orderDetailSearch'],
      fields: [
        { source_path: 'data.orderList.*.roomNights', suggested_field_id: 'room_nights', standard_row_column: 'quantity', include_in_business_metrics: true },
        { source_path: 'data.orderList.*.orderAmount', suggested_field_id: 'order_amount', standard_row_column: 'amount', include_in_business_metrics: true },
      ],
    }],
  });

  const rows = extractCtripApprovedMappingRows({
    data: { orderList: [{ roomNights: 0, orderAmount: '0.00' }] },
  }, {
    mappings,
    url: 'https://ebooking.ctrip.com/restapi/orderDetailSearch',
    dataDate: '2026-07-22',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].quantity, 0);
  assert.equal(rows[0].amount, 0);
  assert.equal(rows[0].data_value, null);
  assert.deepEqual(rows[0].raw_data.facts, [
    {
      metric_key: 'room_nights',
      value: 0,
      source_path: 'data.orderList.0.roomNights',
      storage_field: 'online_daily_data.quantity',
    },
    {
      metric_key: 'order_amount',
      value: '0.00',
      source_path: 'data.orderList.0.orderAmount',
      storage_field: 'online_daily_data.amount',
    },
  ]);
  assert.deepEqual(rows[0].raw_data.field_facts, [
    {
      metric_key: 'room_nights',
      source_path: 'data.orderList.0.roomNights',
      storage_table: 'online_daily_data',
      storage_field: 'online_daily_data.quantity',
      status: 'captured',
      missing_state: '',
      stored_value_present: true,
      value: 0,
    },
    {
      metric_key: 'order_amount',
      source_path: 'data.orderList.0.orderAmount',
      storage_table: 'online_daily_data',
      storage_field: 'online_daily_data.amount',
      status: 'captured',
      missing_state: '',
      stored_value_present: true,
      value: '0.00',
    },
  ]);
});

test('ignores unapproved P3 mapping drafts', () => {
  const mappings = normalizeCtripApprovedMappings({
    mappings: [{
      id: 'order_detail_v1',
      approved: false,
      candidate_section: 'orders_detail',
      data_type: 'order',
      url_keywords: ['orderDetailSearch'],
      fields: [
        { source_path: 'data.orderList.*.orderAmount', suggested_field_id: 'order_amount', standard_row_column: 'amount', include_in_business_metrics: true },
      ],
    }],
  });

  const rows = extractCtripApprovedMappingRows({
    data: { orderList: [{ orderAmount: 588 }] },
  }, {
    url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch',
    mappings,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });

  assert.deepEqual(mappings, []);
  assert.deepEqual(rows, []);
});

test('extracts standard rows from approved P3 mapping without leaking order PII', () => {
  const mappings = normalizeCtripApprovedMappings({
    mappings: [{
      id: 'order_detail_v1',
      approved: true,
      candidate_section: 'orders_detail',
      data_type: 'order',
      url_keywords: ['orderDetailSearch'],
      fields: [
        { source_path: 'data.orderList.*.orderId', suggested_field_id: 'order_id', privacy_handling: 'hash', include_in_business_metrics: false },
        { source_path: 'data.orderList.*.guestName', suggested_field_id: 'guest_name', privacy_handling: 'mask', include_in_business_metrics: false },
        { source_path: 'data.orderList.*.guestPhone', suggested_field_id: 'guest_phone', privacy_handling: 'mask', include_in_business_metrics: false },
        { source_path: 'data.orderList.*.roomNights', suggested_field_id: 'room_nights', standard_row_column: 'quantity', include_in_business_metrics: true },
        { source_path: 'data.orderList.*.orderAmount', suggested_field_id: 'order_amount', standard_row_column: 'amount', include_in_business_metrics: true },
        { source_path: 'data.orderList.*.orderStatus', suggested_field_id: 'order_status', include_in_business_metrics: false },
      ],
    }],
  });

  const rows = extractCtripApprovedMappingRows({
    data: {
      orderList: [{
        orderId: 'CTRIP-ORDER-001',
        guestName: 'Alice Zhang',
        guestPhone: '13812345678',
        roomNights: 2,
        orderAmount: '588.00',
        orderStatus: 'confirmed',
      }],
    },
  }, {
    url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch',
    mappings,
    hotelId: 'ctrip-1001',
    hotelName: '长沙智选假日酒店',
    systemHotelId: 7,
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T11:00:00.000Z',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].source, 'ctrip');
  assert.equal(rows[0].data_type, 'order');
  assert.equal(rows[0].capture_section, 'orders_detail');
  assert.equal(rows[0].amount, 588);
  assert.equal(rows[0].quantity, 2);
  assert.equal(rows[0].data_date, '2026-05-31');
  assert.equal(rows[0].system_hotel_id, 7);
  assert.equal(rows[0].raw_data.mapping_id, 'order_detail_v1');
  assert.equal(rows[0].raw_data.fields.order_amount, '588.00');
  assert.equal(rows[0].raw_data.fields.room_nights, 2);
  assert.match(rows[0].raw_data.fields.order_id_hash, /^[a-f0-9]{64}$/);
  assert.equal(rows[0].raw_data.fields.guest_name_masked, 'A***');
  assert.equal(rows[0].raw_data.fields.guest_phone_masked, '*******5678');

  const encoded = JSON.stringify(rows);
  assert.equal(encoded.includes('CTRIP-ORDER-001'), false);
  assert.equal(encoded.includes('Alice Zhang'), false);
  assert.equal(encoded.includes('13812345678'), false);
});

test('builds unapproved review candidates from complete Profile P3 evidence drafts', () => {
  const completeOrderEvidence = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch',
    method: 'POST',
    payload: { hotelId: 'ctrip-1001', startDate: '2026-05-31' },
    response: {
      data: {
        orderList: [{
          orderId: 'CTRIP-ORDER-001',
          guestName: 'Alice Zhang',
          guestPhone: '13812345678',
          roomNights: 2,
          orderAmount: '588.00',
        }],
      },
    },
    page_context: { page: '订单管理', tab: '订单明细' },
    params: { hotel_id: 'ctrip-1001', data_date: '2026-05-31' },
  }, {
    capturedAt: '2026-05-31T14:00:00.000Z',
  });
  const incompletePriceEvidence = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/rateCalendarPrice',
    response: { data: { rows: [{ price: 399 }] } },
    page_context: { page: '价格房态' },
  }, {
    capturedAt: '2026-05-31T14:00:00.000Z',
  });

  const candidates = buildCtripApprovedMappingCandidatesFromCapture([{
    path: 'reports/ctrip_browser_capture_profile59.json',
    payload: {
      p3_evidence_drafts: [completeOrderEvidence, incompletePriceEvidence],
    },
  }], {
    generatedAt: '2026-05-31T15:00:00.000Z',
    mappingIdPrefix: 'profile59',
  });

  assert.equal(candidates.status, 'review_required');
  assert.equal(candidates.summary.capture_count, 1);
  assert.equal(candidates.summary.evidence_draft_count, 2);
  assert.equal(candidates.summary.ready_evidence_count, 1);
  assert.equal(candidates.summary.skipped_draft_count, 1);
  assert.equal(candidates.mappings.length, 1);
  assert.equal(candidates.mappings[0].id, 'profile59_orders_detail_orderdetailsearch');
  assert.equal(candidates.mappings[0].approved, false);
  assert.equal(candidates.mappings[0].review_required, true);
  assert.equal(candidates.mappings[0].candidate_section, 'orders_detail');
  assert.equal(candidates.mappings[0].fields.some((field) => field.source_path === 'data.orderList.*.orderAmount' && field.suggested_field_id === 'order_amount'), true);
  assert.deepEqual(normalizeCtripApprovedMappings(candidates), []);

  const encoded = JSON.stringify(candidates);
  assert.equal(encoded.includes('CTRIP-ORDER-001'), false);
  assert.equal(encoded.includes('Alice Zhang'), false);
  assert.equal(encoded.includes('13812345678'), false);
});

test('dry-runs approved P3 mapping against an endpoint evidence bundle', () => {
  const result = buildCtripApprovedMappingDryRun({
    evidence: {
      request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch',
      response: {
        data: {
          orderList: [{
            orderId: 'CTRIP-ORDER-001',
            guestName: 'Alice Zhang',
            guestPhone: '13812345678',
            roomNights: 2,
            orderAmount: '588.00',
          }],
        },
      },
      params: {
        hotel_id: 'ctrip-1001',
        data_date: '2026-05-31',
      },
    },
    mappingConfig: {
      mappings: [{
        id: 'order_detail_v1',
        approved: true,
        candidate_section: 'orders_detail',
        data_type: 'order',
        url_keywords: ['orderDetailSearch'],
        fields: [
          { source_path: 'data.orderList.*.orderId', suggested_field_id: 'order_id', privacy_handling: 'hash', include_in_business_metrics: false },
          { source_path: 'data.orderList.*.guestName', suggested_field_id: 'guest_name', privacy_handling: 'mask', include_in_business_metrics: false },
          { source_path: 'data.orderList.*.guestPhone', suggested_field_id: 'guest_phone', privacy_handling: 'mask', include_in_business_metrics: false },
          { source_path: 'data.orderList.*.roomNights', suggested_field_id: 'room_nights', standard_row_column: 'quantity', include_in_business_metrics: true },
          { source_path: 'data.orderList.*.orderAmount', suggested_field_id: 'order_amount', standard_row_column: 'amount', include_in_business_metrics: true },
        ],
      }],
    },
    generatedAt: '2026-05-31T12:00:00.000Z',
  });

  assert.equal(result.status, 'pass');
  assert.equal(result.summary.approved_mapping_count, 1);
  assert.equal(result.summary.standard_row_count, 1);
  assert.equal(result.standard_rows[0].amount, 588);
  assert.equal(result.standard_rows[0].quantity, 2);
  assert.equal(result.standard_rows[0].raw_data.fields.order_amount, '588.00');
  assert.match(result.standard_rows[0].raw_data.fields.order_id_hash, /^[a-f0-9]{64}$/);

  const encoded = JSON.stringify(result);
  assert.equal(encoded.includes('CTRIP-ORDER-001'), false);
  assert.equal(encoded.includes('Alice Zhang'), false);
  assert.equal(encoded.includes('13812345678'), false);

  const markdown = renderCtripApprovedMappingDryRunMarkdown(result);
  assert.equal(markdown.includes('## 标准行预览'), true);
  assert.equal(markdown.includes('order_detail_v1'), true);
});

test('promotes field mapping draft to an unapproved review candidate only', () => {
  const evidenceResult = validateCtripEndpointEvidenceBundle({
    request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch',
    method: 'POST',
    payload: { hotelId: 'ctrip-1001', startDate: '2026-05-31' },
    response: {
      data: {
        orderList: [{
          orderId: 'CTRIP-ORDER-001',
          guestName: 'Alice Zhang',
          guestPhone: '13812345678',
          roomNights: 2,
          orderAmount: '588.00',
        }],
      },
    },
    page_context: { page: '订单管理', tab: '订单明细' },
    params: { hotel_id: 'ctrip-1001', data_date: '2026-05-31' },
  });

  const candidate = buildCtripApprovedMappingCandidateFromEvidence(evidenceResult, {
    mappingId: 'order_detail_candidate',
    generatedAt: '2026-05-31T13:00:00.000Z',
  });

  assert.equal(candidate.mappings.length, 1);
  assert.equal(candidate.mappings[0].approved, false);
  assert.equal(candidate.mappings[0].candidate_section, 'orders_detail');
  assert.equal(candidate.mappings[0].data_type, 'order');
  assert.equal(candidate.mappings[0].url_keywords.includes('orderDetailSearch'), true);
  assert.equal(candidate.mappings[0].fields.some((field) => field.source_path === 'data.orderList.*.orderAmount' && field.suggested_field_id === 'order_amount' && field.standard_row_column === 'amount'), true);
  assert.equal(candidate.mappings[0].fields.some((field) => field.suggested_field_id === 'order_id' && field.privacy_handling === 'hash'), true);
  assert.deepEqual(normalizeCtripApprovedMappings(candidate), []);

  const encoded = JSON.stringify(candidate);
  assert.equal(encoded.includes('CTRIP-ORDER-001'), false);
  assert.equal(encoded.includes('Alice Zhang'), false);
  assert.equal(encoded.includes('13812345678'), false);
});
