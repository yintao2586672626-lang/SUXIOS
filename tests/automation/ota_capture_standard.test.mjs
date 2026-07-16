import assert from 'node:assert/strict';
import test from 'node:test';

import {
  attachOtaCaptureEvidence,
  buildCapturePlan,
  buildCookieInjectionPlan,
  buildOtaCaptureEvidence,
  classifyOtaResponse,
  extractOtaRequestDateEvidence,
  normalizeCaptureSections,
  parseCookieHeader,
  sanitizeOtaPayloadForStorage,
} from '../../scripts/lib/ota_capture_standard.mjs';

test('normalizes OTA capture sections per platform', () => {
  assert.deepEqual(normalizeCaptureSections('meituan', 'flow,order'), ['traffic', 'orders']);
  assert.deepEqual(
    normalizeCaptureSections('meituan', 'businessData,peerRank,flowData,searchKeywords,roomTypes'),
    ['traffic'],
  );
  assert.deepEqual(
    normalizeCaptureSections('meituan', 'flowAnalysis,trafficForecast,flowForecast,flowConversion'),
    ['traffic'],
  );
  assert.deepEqual(normalizeCaptureSections('ctrip', 'business,traffic'), ['business', 'traffic']);
  assert.deepEqual(normalizeCaptureSections('meituan', ''), ['traffic', 'orders']);
  assert.deepEqual(normalizeCaptureSections('meituan', 'comment'), ['reviews']);
  assert.deepEqual(normalizeCaptureSections('meituan', 'full'), ['traffic', 'orders', 'ads', 'reviews']);
  assert.deepEqual(normalizeCaptureSections('meituan', 'all'), ['traffic', 'orders', 'ads', 'reviews']);
  assert.deepEqual(normalizeCaptureSections('meituan', 'realtime,advertising'), ['traffic', 'ads']);
  assert.deepEqual(normalizeCaptureSections('ctrip', 'review'), ['reviews']);
});

test('builds a capture plan with profile and cookie injection metadata', () => {
  const plan = buildCapturePlan({
    platform: 'meituan',
    storeId: '68471',
    sections: 'flow,order',
    profileDir: 'storage/custom_meituan_profile',
    cookies: 'token=abc; poi=68471',
  });

  assert.equal(plan.platform, 'meituan');
  assert.equal(plan.profile.id, '68471');
  assert.equal(plan.profile.storageDir, 'storage/custom_meituan_profile');
  assert.deepEqual(plan.sections, ['traffic', 'orders']);
  assert.deepEqual(plan.cookies.domains, ['me.meituan.com', 'eb.meituan.com', '.meituan.com', '.dianping.com']);
  assert.equal(plan.cookies.cookies.length, 8);
});

test('parses cookie headers without accepting malformed names', () => {
  assert.deepEqual(parseCookieHeader('a=1; empty; b=two=2; bad name=x'), [
    { name: 'a', value: '1' },
    { name: 'b', value: 'two=2' },
  ]);

  assert.throws(() => buildCookieInjectionPlan('ctrip', 'broken'), /empty or invalid Cookie header/);
});

test('classifies OTA JSON responses by platform and section', () => {
  const meituanReview = classifyOtaResponse('meituan', 'https://eb.meituan.com/api/v1/ebooking/comments/commentsInfo', {
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  });
  assert.equal(meituanReview.capture, true);
  assert.equal(meituanReview.section, 'reviews');
  assert.equal(meituanReview.reason, 'url_keyword');

  const ctripReview = classifyOtaResponse('ctrip', 'https://ebooking.ctrip.com/comment/getCommentList', {
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  });
  assert.equal(ctripReview.capture, true);
  assert.equal(ctripReview.section, 'reviews');
  assert.equal(ctripReview.capture_section, 'comment_review');
  assert.equal(ctripReview.reason, 'url_keyword');

  const ctripTraffic = classifyOtaResponse('ctrip', 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1', {
    status: 200,
    resourceType: 'fetch',
    contentType: 'application/json',
  });
  assert.equal(ctripTraffic.capture, true);
  assert.equal(ctripTraffic.section, 'traffic');

  const ctripAds = classifyOtaResponse('ctrip', 'https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport', {
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  });
  assert.equal(ctripAds.capture, true);
  assert.equal(ctripAds.section, 'ads');

  const meituanPeerRank = classifyOtaResponse('meituan', 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail', {
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  });
  assert.equal(meituanPeerRank.capture, true);
  assert.equal(meituanPeerRank.section, 'traffic');

  for (const url of [
    'https://eb.meituan.com/api/v1/ebooking/business/flowConversion?dateRange=1',
    'https://eb.meituan.com/api/v1/ebooking/business/flowTrend?dateRange=7',
    'https://eb.meituan.com/api/v1/ebooking/business/flowTrendDetail?dateRange=30',
    'https://eb.meituan.com/api/v1/ebooking/business/flowForecast?type=2',
    'https://eb.meituan.com/api/v1/ebooking/business/searchKeyWords',
  ]) {
    const classified = classifyOtaResponse('meituan', url, {
      status: 200,
      resourceType: 'xhr',
      contentType: 'application/json',
    });
    assert.equal(classified.capture, true, url);
    assert.equal(classified.section, 'traffic', url);
  }

  const asset = classifyOtaResponse('meituan', 'https://p0.meituan.net/assets/logo.png', {
    status: 200,
    resourceType: 'image',
    contentType: 'image/png',
  });
  assert.equal(asset.capture, false);
  assert.equal(asset.reason, 'non_business_resource');

  const orderIframeDocument = classifyOtaResponse('meituan', 'https://eb.meituan.com/ebooking/order-eb/index.html#/checkin', {
    status: 200,
    resourceType: 'document',
    contentType: 'text/html; charset=utf-8',
  });
  assert.equal(orderIframeDocument.capture, false);
  assert.equal(orderIframeDocument.reason, 'order_json_xhr_required');

  const orderListJson = classifyOtaResponse('meituan', 'https://eb.meituan.com/api/v1/ebooking/orders/list', {
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json; charset=utf-8',
  });
  assert.equal(orderListJson.capture, true);
  assert.equal(orderListJson.section, 'orders');
});

test('extracts request date evidence only when the request proves one target date', () => {
  assert.deepEqual(
    extractOtaRequestDateEvidence({
      url: 'https://example.test/traffic?startDate=2026-06-14&endDate=2026-06-14',
    }),
    { date: '2026-06-14', date_source: 'request.query.startDate' },
  );

  assert.deepEqual(
    extractOtaRequestDateEvidence({
      payload: JSON.stringify({ filters: { dataDate: '2026/06/14' } }),
    }),
    { date: '2026-06-14', date_source: 'request.payload.filters.dataDate' },
  );

  assert.deepEqual(
    extractOtaRequestDateEvidence({
      payload: `params=${encodeURIComponent(JSON.stringify({ query: { statDate: '20260614' } }))}`,
    }),
    { date: '2026-06-14', date_source: 'request.payload.params.query.statDate' },
  );

  assert.deepEqual(
    extractOtaRequestDateEvidence({
      url: 'https://example.test/traffic?startDate=2026-06-13&endDate=2026-06-14',
    }),
    { date: '', date_source: '' },
  );

  assert.deepEqual(
    extractOtaRequestDateEvidence({
      url: 'https://eb.meituan.com/api/v1/ebooking/orders?startTime=1783699200000&endTime=1783785599999',
    }),
    { date: '2026-07-11', date_source: 'request.query.startTime' },
  );
});

test('builds complete desensitized capture evidence without raw source URLs', () => {
  const evidence = buildOtaCaptureEvidence('meituan', {
    url: 'https://eb.meituan.com/api/v1/ebooking/business/flow?token=secret&date=2026-06-14',
    section: 'traffic',
    sourcePath: 'data.rows.0',
    captureSource: 'xhr:traffic',
  });

  assert.equal(evidence.section, 'traffic');
  assert.equal(evidence.source_path, 'data.rows.0');
  assert.equal(evidence.capture_source, 'xhr:traffic');
  assert.match(evidence.source_url_hash, /^[a-f0-9]{64}$/);
  assert.match(evidence.source_trace_id, /^meituan:[a-f0-9]{64}$/);
  assert.equal(Object.hasOwn(evidence, 'url'), false);
  assert.equal(Object.hasOwn(evidence, 'source_url'), false);
});

test('attaches row-level complete capture evidence and removes raw URL aliases', () => {
  const row = attachOtaCaptureEvidence({
    dataDate: '2026-06-14',
    metric_key: 'traffic_flow',
    value: 123,
    _source_path: 'data.rows.0.flow',
    url: 'https://ebooking.ctrip.com/datacenter/api/traffic?spiderToken=secret',
    capture_evidence: {
      _source_url: 'https://ebooking.ctrip.com/raw-url-should-not-survive?token=secret',
      url: 'https://ebooking.ctrip.com/raw-url-should-not-survive?token=secret',
    },
  }, 'ctrip', {
    section: 'traffic',
    captureSource: 'xhr:traffic',
  });

  assert.equal(row.metric_key, 'traffic_flow');
  assert.equal(row._source_path, 'data.rows.0.flow');
  assert.equal(row.source_url, undefined);
  assert.equal(row._source_url, undefined);
  assert.equal(row.url, undefined);
  assert.match(row.source_trace_id, /^ctrip:[a-f0-9]{64}$/);
  assert.match(row.source_url_hash, /^[a-f0-9]{64}$/);
  assert.equal(row.capture_evidence.source_path, 'data.rows.0.flow');
  assert.equal(row.capture_evidence.section, 'traffic');
  assert.equal(row.capture_evidence.capture_source, 'xhr:traffic');
  assert.equal(row.capture_evidence.source_trace_id, row.source_trace_id);
  assert.equal(row.capture_evidence.source_url_hash, row.source_url_hash);
  assert.equal(Object.hasOwn(row.capture_evidence, 'source_url'), false);
  assert.equal(Object.hasOwn(row.capture_evidence, '_source_url'), false);
  assert.equal(Object.hasOwn(row.capture_evidence, 'url'), false);
  assert.equal(JSON.stringify(row).includes('https://'), false);
});

test('sanitizes order payloads before capture output is written', () => {
  const sanitized = sanitizeOtaPayloadForStorage({
    data: {
      orderList: [
        {
          orderId: 'CTRIP-ORDER-202605280001',
          guestName: 'Alice Zhang',
          guestPhone: '13812345678',
          mobile: '13987654321',
          idCardNo: 'ID-SECRET-001',
          customerRemark: 'late arrival',
          amount: 588,
        },
      ],
    },
    headers: {
      Cookie: 'session=secret-cookie',
      Authorization: 'Bearer secret-token',
    },
    spiderkey: 'dynamic-spider-secret',
    fingerPrintKeys: 'dynamic-fingerprint-secret',
  }, 'orders');

  const encoded = JSON.stringify(sanitized);
  assert.equal(encoded.includes('CTRIP-ORDER-202605280001'), false);
  assert.equal(encoded.includes('Alice Zhang'), false);
  assert.equal(encoded.includes('13812345678'), false);
  assert.equal(encoded.includes('13987654321'), false);
  assert.equal(encoded.includes('ID-SECRET-001'), false);
  assert.equal(encoded.includes('late arrival'), false);
  assert.equal(encoded.includes('secret-cookie'), false);
  assert.equal(encoded.includes('secret-token'), false);
  assert.equal(encoded.includes('dynamic-spider-secret'), false);
  assert.equal(encoded.includes('dynamic-fingerprint-secret'), false);
  assert.match(sanitized.data.orderList[0].order_id_hash, /^[a-f0-9]{64}$/);
  assert.equal(sanitized.data.orderList[0].guest_name_masked, 'A***');
  assert.equal(sanitized.data.orderList[0].guest_phone_masked, '*******5678');
  assert.equal(sanitized.data.orderList[0].mobile_masked, '*******4321');
  assert.equal(sanitized.data.orderList[0].amount, 588);
});

test('sanitizes review payloads down to aggregate-safe fields', () => {
  const sanitized = sanitizeOtaPayloadForStorage({
    data: {
      hotelName: '西安空港城天诚商务宾馆',
      statDate: '2026-06-06',
      commentList: [
        {
          commentId: 'COMMENT-SECRET-001',
          commentContent: '房间很吵',
          replyContent: '抱歉，我们会改进',
          userName: '张三',
          roomType: '商务大床房',
          orderId: 'ORDER-SECRET-001',
          channelName: '携程',
          commentScore: 3.2,
          commentTime: '2026-06-06',
          badReviewCount: 6,
        },
      ],
      totalCount: 577,
    },
  }, 'reviews');

  const encoded = JSON.stringify(sanitized);
  assert.equal(encoded.includes('房间很吵'), false);
  assert.equal(encoded.includes('抱歉'), false);
  assert.equal(encoded.includes('张三'), false);
  assert.equal(encoded.includes('商务大床房'), false);
  assert.equal(encoded.includes('COMMENT-SECRET-001'), false);
  assert.equal(encoded.includes('ORDER-SECRET-001'), false);
  assert.equal(sanitized.data.hotelName, '西安空港城天诚商务宾馆');
  assert.equal(sanitized.data.statDate, '2026-06-06');
  assert.equal(Object.hasOwn(sanitized.data, 'commentList'), false);
  assert.equal(sanitized.data.channelName, '携程');
  assert.equal(sanitized.data.commentScore, 3.2);
  assert.equal(sanitized.data.badReviewCount, 6);
  assert.equal(sanitized.data.totalCount, 577);
});
