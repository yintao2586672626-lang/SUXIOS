import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildCapturePlan,
  buildCookieInjectionPlan,
  classifyOtaResponse,
  normalizeCaptureSections,
  parseCookieHeader,
  sanitizeOtaPayloadForStorage,
} from '../../scripts/lib/ota_capture_standard.mjs';

test('normalizes OTA capture sections per platform', () => {
  assert.deepEqual(normalizeCaptureSections('meituan', 'flow,order'), ['traffic', 'orders']);
  assert.deepEqual(normalizeCaptureSections('ctrip', 'business,traffic'), ['business', 'traffic']);
  assert.deepEqual(normalizeCaptureSections('meituan', ''), ['traffic', 'orders']);
  assert.throws(() => normalizeCaptureSections('meituan', 'comment'), /Comment\/review capture is disabled/);
  assert.throws(() => normalizeCaptureSections('ctrip', 'review'), /Comment\/review capture is disabled/);
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
  assert.equal(meituanReview.capture, false);
  assert.equal(meituanReview.reason, 'comment_collection_disabled');

  const ctripReview = classifyOtaResponse('ctrip', 'https://ebooking.ctrip.com/comment/getCommentList', {
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  });
  assert.equal(ctripReview.capture, false);
  assert.equal(ctripReview.reason, 'comment_collection_disabled');

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

  const asset = classifyOtaResponse('meituan', 'https://p0.meituan.net/assets/logo.png', {
    status: 200,
    resourceType: 'image',
    contentType: 'image/png',
  });
  assert.equal(asset.capture, false);
  assert.equal(asset.reason, 'non_business_resource');
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
  assert.match(sanitized.data.orderList[0].order_id_hash, /^[a-f0-9]{64}$/);
  assert.equal(sanitized.data.orderList[0].guest_name_masked, 'A***');
  assert.equal(sanitized.data.orderList[0].guest_phone_masked, '*******5678');
  assert.equal(sanitized.data.orderList[0].mobile_masked, '*******4321');
  assert.equal(sanitized.data.orderList[0].amount, 588);
});
