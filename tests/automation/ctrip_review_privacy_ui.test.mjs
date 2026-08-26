import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync(
  new URL('../../resources/frontend/app-template.html', import.meta.url),
  'utf8',
);
const concern = readFileSync(
  new URL('../../app/controller/concern/CtripReviewOrderMatchConcern.php', import.meta.url),
  'utf8',
);
const policy = readFileSync(
  new URL('../../app/service/OtaReviewRiskPolicyService.php', import.meta.url),
  'utf8',
);
const scorer = readFileSync(
  new URL('../../app/service/CtripReviewOrderCandidateScoringService.php', import.meta.url),
  'utf8',
);
const appMain = readFileSync(
  new URL('../../public/app-main.js', import.meta.url),
  'utf8',
);
const reviewMatchStatic = readFileSync(
  new URL('../../public/review-match-static.js', import.meta.url),
  'utf8',
);

test('Ctrip review-order evidence matching is available without anonymous identity lookup', () => {
  const tab = template.match(/<button[^>]*data-testid="ctrip-review-order-evidence-tab"[^>]*>[\s\S]*?<\/button>/)?.[0] ?? '';

  assert.ok(tab, 'the Ctrip order-evidence tab must expose a stable marker');
  assert.doesNotMatch(tab, /disabled/);
  assert.match(tab, /@click="openCtripManualTab\('ctrip-review-match'\)"/);
  assert.match(tab, /点评订单证据/);
  assert.match(template, /不会猜测、还原或暴力反查匿名用户身份/);
  assert.match(template, /不保存客人身份字段/);
  assert.match(template, /成员身份不会入库/);
  assert.match(template, /点评订单候选与证据链/);
  assert.match(template, /缺失证据：/);
});

test('Ctrip anonymous identity preview remains blocked while order evidence routes are available', () => {
  assert.match(concern, /reviewRiskPolicyBlockedResponse\('ctrip_review_orderer_identity_preview'/);
  for (const operation of [
    'ctrip_review_order_lookup',
    'ctrip_review_order_match_automation',
    'ctrip_review_order_manual_bind',
    'ctrip_review_order_match_closure_check',
  ]) {
    assert.doesNotMatch(concern, new RegExp(`reviewRiskPolicyBlockedResponse\\('${operation}'`));
  }
  assert.match(concern, /'identity_resolution' => 'blocked_not_attempted'/);
  assert.match(concern, /'identity_fields_stored' => false/);
  assert.match(policy, /blocked_by_review_privacy_policy/);
});

test('Ctrip candidate scoring keeps PMS optional and exposes the five evidence statuses', () => {
  assert.match(concern, /authorized_ctrip_order_cache_pms_optional/);
  assert.match(concern, /'optional_sources' => \['ctrip_im_sessions', 'pms_order_details'\]/);
  assert.match(scorer, /checkout_0_14_days_before_review/);
  assert.match(scorer, /checkout_15_30_days_before_review/);
  assert.match(scorer, /\$breakdown\['duplicate_penalty'\] = -15/);
  assert.match(scorer, /MIN_CANDIDATE_SCORE_GAP = 20/);
  for (const status of ['confirmed', 'high_confidence', 'candidate', 'ambiguous', 'not_found']) {
    assert.match(reviewMatchStatic, new RegExp(`${status}:`));
  }
});

test('Ctrip manual binding verifies same-hotel records and exact database readback', () => {
  assert.match(
    concern,
    /Db::name\('ota_ctrip_reviews'\)[\s\S]{0,240}->where\('system_hotel_id', \$systemHotelId\)[\s\S]{0,160}->where\('comment_id', \$commentId\)/,
  );
  assert.match(
    concern,
    /Db::name\('ota_ctrip_orders'\)[\s\S]{0,240}->where\('system_hotel_id', \$systemHotelId\)[\s\S]{0,160}->where\('order_id', \$orderId\)/,
  );
  assert.match(concern, /携程评价订单绑定保存回读不一致/);
  assert.match(concern, /'save_status' => 'saved_and_readback_verified'/);
  assert.match(concern, /'same_hotel_review_verified' => true/);
  assert.match(concern, /'same_hotel_order_verified' => true/);
});

test('Ctrip review-order tools never fall back to another hotel identity', () => {
  assert.match(
    appMain,
    /const resolveCtripReviewMatchSystemHotelId = \(\) => String\(selectedCtripHotelId\.value \|\| ''\)\.trim\(\);/,
  );
  assert.doesNotMatch(appMain, /ctripReviewMatchForm\.value\.systemHotelId/);
  assert.doesNotMatch(appMain, /resolveCtripReviewMatchSystemHotelId[\s\S]{0,200}user\.value\?\.hotel_id/);
  assert.match(appMain, /throw new Error\('请先在顶部选择携程当前酒店'\)/);
  assert.match(
    appMain,
    /return \{ system_hotel_id: systemHotelId \};/,
  );
  assert.match(
    appMain,
    /buildBasePayload: buildCtripReviewMatchBasePayload/,
  );
  assert.match(reviewMatchStatic, /captureRequestContext\('ctrip'\)/);
  assert.match(reviewMatchStatic, /JSON\.stringify\(buildPayload\(\)\)/);
  assert.doesNotMatch(appMain, /resolveCtripReviewMatchSystemHotelId\(\)\s*\|\|\s*['"]58['"]?/);
});
