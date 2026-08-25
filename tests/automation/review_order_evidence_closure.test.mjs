import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');

const ctripPage = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
const meituanPage = read('resources/frontend/templates/fragments/26-page-meituan-ebooking.html');
const appShell = read('resources/frontend/templates/fragments/00-app-shell.html');
const frontend = read('public/app-main.js');
const routes = read('route/app.php');
const ctripController = read('app/controller/concern/CtripReviewOrderMatchConcern.php');
const meituanController = read('app/controller/concern/MeituanReviewOrderMatchConcern.php');
const meituanService = read('app/service/MeituanReviewOrderMatchService.php');
const meituanStaticSource = read('public/meituan-static.js');
const reviewMatchStaticSource = read('public/review-match-static.js');
const runtimeWindow = {};
new Function('window', meituanStaticSource)(runtimeWindow);
const meituanStatic = runtimeWindow.SUXI_MEITUAN_STATIC;
new Function('window', reviewMatchStaticSource)(runtimeWindow);
const reviewMatchStatic = runtimeWindow.SUXI_REVIEW_MATCH_STATIC;

test('Ctrip and Meituan expose a findable review-order evidence workbench', () => {
  for (const marker of [
    'data-testid="ctrip-review-order-evidence-tab"',
    "openCtripManualTab('ctrip-review-match')",
    'ctripReviewMatchForm.decisionReason',
    'rejectCtripReviewOrderMatch(sample)',
    'unbindCtripReviewOrderMatch(sample)',
    'manual_matched_count',
    'evidence_ready_count',
  ]) assert.ok(ctripPage.includes(marker), `Ctrip page missing ${marker}`);

  for (const marker of [
    'data-testid="meituan-review-order-evidence-tab"',
    'data-testid="meituan-review-order-evidence-workbench"',
    "openMeituanManualTab('meituan-review-match')",
    'meituanReviewMatchForm.decisionReason',
    'rejectMeituanReviewOrderMatch(sample)',
    'unbindMeituanReviewOrderMatch(sample)',
    'manual_matched_count',
    'evidence_ready_count',
  ]) assert.ok(meituanPage.includes(marker), `Meituan page missing ${marker}`);
  assert.ok(appShell.includes("platformHotelContext === 'meituan' ? fetchingCommentData || meituanConfigListLoading || !platformHotelOptions.length"));
});

test('frontend connects save, candidate calculation, manual decision and closure readback', () => {
  for (const marker of [
    'createCtripReviewMatchController',
    'createDeferredReviewControllerBridge',
    "staticKey: 'SUXI_REVIEW_MATCH_STATIC'",
    "staticKey: 'SUXI_MEITUAN_STATIC'",
    'instantiate: factory => factory({ ...options, state })',
    '...ctripReviewMatchControllerBindings',
    'createMeituanReviewMatchController',
    '...meituanReviewMatchControllerBindings',
    "onlineDataTab.value === 'meituan-review-match'",
    "hotelPool: onlineDataTab.value === 'meituan-review-match'",
    'meituanReviewMatchHotelOptions.value',
  ]) assert.ok(frontend.includes(marker), `frontend missing ${marker}`);
  assert.ok(
    !frontend.includes("const createMeituanReviewMatchController = requireMeituanStatic('createMeituanReviewMatchController')"),
    'Meituan review matching must not freeze the startup fallback before deferred helpers load',
  );
  assert.ok(reviewMatchStaticSource.includes('state.ctripReviewMatchForm || ref('));
  assert.ok(meituanStaticSource.includes('state.meituanReviewMatchForm || ref('));

  const bindingSpreadStart = frontend.indexOf('...ctripReviewMatchControllerBindings,');
  const setupReturnEnd = frontend.indexOf('\n            };', bindingSpreadStart);
  const setupReturn = frontend.slice(bindingSpreadStart, setupReturnEnd);
  for (const binding of [
    'saveCtripReviewImSession',
    'saveCtripReviewForMatch',
    'saveCtripOrderForMatch',
    'lookupCtripReviewOrderMatch',
    'bindCtripReviewOrderMatch',
    'rejectCtripReviewOrderMatch',
    'unbindCtripReviewOrderMatch',
  ]) {
    assert.ok(
      !new RegExp(`\\b${binding}\\s*,`).test(setupReturn),
      `${binding} must come from ctripReviewMatchControllerBindings instead of an undefined shorthand`,
    );
  }

  for (const marker of [
    'saveCtripReviewForMatch',
    'saveCtripOrderForMatch',
    'runCtripReviewMatchAutomation',
    'bindCtripReviewOrderMatch',
    'rejectCtripReviewOrderMatch',
    'unbindCtripReviewOrderMatch',
  ]) assert.ok(reviewMatchStaticSource.includes(marker), `Ctrip controller missing ${marker}`);

  for (const marker of [
    'saveMeituanReviewForMatch',
    'saveMeituanOrderForMatch',
    'lookupMeituanReviewOrderMatch',
    'runMeituanReviewMatchAutomation',
    'checkMeituanReviewMatchClosure',
    'bindMeituanReviewOrderMatch',
    'rejectMeituanReviewOrderMatch',
    'unbindMeituanReviewOrderMatch',
  ]) assert.ok(meituanStaticSource.includes(marker), `Meituan controller missing ${marker}`);

  for (const route of [
    '/ctrip-review-matches/reviews',
    '/ctrip-review-matches/orders',
    '/ctrip-review-matches/run',
    '/ctrip-review-matches/closure',
    '/ctrip-review-matches/bind',
    '/ctrip-review-matches/reject',
    '/ctrip-review-matches/unbind',
    '/meituan-review-matches/reviews',
    '/meituan-review-matches/orders',
    '/meituan-review-matches/lookup',
    '/meituan-review-matches/run',
    '/meituan-review-matches/closure',
    '/meituan-review-matches/bind',
    '/meituan-review-matches/reject',
    '/meituan-review-matches/unbind',
  ]) assert.ok(routes.includes(route), `route missing ${route}`);
});

test('formal closure requires a manual match and preserves rejected or unbound readback', () => {
  for (const controller of [ctripController, meituanController]) {
    for (const marker of [
      "'manual_matched_count'",
      "'evidence_ready_count'",
      "'rejected_count'",
      "'unbound_count'",
      "'match_status' => 'matched'",
      "'match_status' => 'rejected'",
      "'match_status' => 'unbound'",
      "'save_status' => 'saved_and_readback_verified'",
    ]) assert.ok(controller.includes(marker), `controller missing ${marker}`);
  }
  assert.ok(ctripController.includes("'accepted_match_statuses' => ['matched']"));
  assert.ok(meituanController.includes("'accepted_formal_match_statuses' => ['matched']"));
});

test('Meituan matching excludes identity and phone evidence while retaining explicit policy state', () => {
  for (const marker of [
    "'identity_resolution' => 'blocked_not_attempted'",
    "'phone_evidence_used' => false",
    "'storage_contains_guest_identity' => false",
    "'phone_status' => OtaReviewRiskPolicyService::STATUS_BLOCKED",
    "'phone_source' => 'not_collected_by_policy'",
    "'meituan_user_id' => ''",
    "'guest_name_masked' => ''",
    "'phone_masked' => ''",
    "'phone_last4' => ''",
    "private const MIN_CANDIDATE_SCORE_GAP = 20",
    "'requires_manual_confirmation' => true",
  ]) assert.ok(meituanService.includes(marker), `privacy contract missing ${marker}`);
});

test('Meituan payload builder keeps unknown scores null and requires explicit manual decisions', () => {
  const baseForm = {
    reviewId: 'review-1',
    orderId: 'order-1',
    decisionReason: '日期证据冲突',
  };
  assert.deepEqual(
    meituanStatic.buildMeituanReviewMatchPayload('closure', { systemHotelId: '64' }),
    { system_hotel_id: '64', min_matched: 1 },
  );
  assert.deepEqual(
    meituanStatic.buildMeituanReviewMatchPayload('bind', { systemHotelId: '64', form: baseForm }),
    { system_hotel_id: '64', reviewId: 'review-1', orderId: 'order-1', reason: '日期证据冲突' },
  );
  assert.throws(
    () => meituanStatic.buildMeituanReviewMatchPayload('reject', {
      systemHotelId: '64',
      form: { ...baseForm, decisionReason: '' },
    }),
    /人工否决需要填写原因/,
  );
  const [normalized] = meituanStatic.normalizeMeituanReviewMatchSamples({
    data: { review_cards: [{ review_id: 'review-1', match_score: '', score_gap: null }] },
  });
  assert.equal(normalized.match_score, null);
  assert.equal(normalized.score_gap, null);
});

test('Ctrip deferred payload helpers keep hotel scope and strip IM identity evidence', () => {
  assert.deepEqual(
    reviewMatchStatic.buildCtripReviewMatchPayload({
      action: 'review',
      systemHotelId: '64',
      form: { commentId: 'comment-1', rawReviewJson: '' },
    }),
    { system_hotel_id: '64', review: { commentId: 'comment-1' } },
  );
  assert.deepEqual(
    reviewMatchStatic.buildCtripReviewMatchPayload({
      action: 'im',
      systemHotelId: '64',
      form: {
        imGroupId: 'group-1',
        rawImSessionJson: JSON.stringify({ members: [{ uid: 'must-not-survive' }] }),
      },
    }),
    { system_hotel_id: '64', session: { groupId: 'group-1', members: [] } },
  );
  assert.throws(
    () => reviewMatchStatic.buildCtripReviewMatchPayload({
      action: 'decision',
      systemHotelId: '64',
      form: { commentId: 'comment-1', decisionReason: '' },
      requireReason: true,
    }),
    /人工否决需要填写原因/,
  );
  const [normalized] = reviewMatchStatic.normalizeCtripReviewMatchSamples({
    data: { review_cards: [{ comment_id: 'comment-1', match_score: null }] },
  });
  assert.equal(normalized.match_score, null);
});

test('Ctrip deferred action controller writes in scope and refreshes exact closure after a decision', async () => {
  const calls = [];
  const notices = [];
  const loading = { value: '' };
  const lookupLoadingCommentId = { value: '' };
  const result = { value: null };
  const controller = reviewMatchStatic.createCtripReviewMatchActionController({
    request: async (path, options) => {
      calls.push({ path, body: JSON.parse(options.body) });
      return path.endsWith('/closure')
        ? { code: 200, data: { review_cards: [{ comment_id: 'comment-1', match_status: 'matched' }] } }
        : { code: 200, data: { save_status: 'saved_and_readback_verified' } };
    },
    captureRequestContext: () => ({ hotelId: '64' }),
    isRequestContextCurrent: () => true,
    staleActionResult: context => ({ status: 'stale', context }),
    showToast: (message, level) => notices.push({ message, level }),
    loading,
    lookupLoadingCommentId,
    result,
    buildImPayload: () => ({ system_hotel_id: '64', session: { groupId: 'group-1', members: [] } }),
    buildReviewPayload: () => ({ system_hotel_id: '64', review: { commentId: 'comment-1' } }),
    buildOrderPayload: () => ({ system_hotel_id: '64', order: { orderId: 'order-1' } }),
    buildLookupPayload: () => ({ system_hotel_id: '64', commentId: 'comment-1' }),
    buildAutomationPayload: () => ({ system_hotel_id: '64' }),
    buildBasePayload: () => ({ system_hotel_id: '64' }),
    buildBindPayload: () => ({ system_hotel_id: '64', commentId: 'comment-1', orderId: 'order-1' }),
    buildDecisionPayload: () => ({ system_hotel_id: '64', commentId: 'comment-1', orderId: 'order-1' }),
  });

  await controller.saveReview();
  await controller.bind(null);
  assert.deepEqual(calls.map(call => call.path), [
    '/online-data/ctrip-review-matches/reviews',
    '/online-data/ctrip-review-matches/bind',
    '/online-data/ctrip-review-matches/closure',
  ]);
  assert.equal(calls[0].body.system_hotel_id, '64');
  assert.equal(result.value.data.review_cards[0].match_status, 'matched');
  assert.equal(loading.value, '');
  assert.equal(notices.at(-1).level, 'success');
});

test('Meituan controller executes write then exact closure refresh and exposes explicit missing-hotel failure', async () => {
  const ref = value => ({ value });
  const computed = getter => ({ get value() { return getter(); } });
  const calls = [];
  const notices = [];
  const controller = meituanStatic.createMeituanReviewMatchController(
    ref,
    computed,
    async (path, options) => {
      calls.push({ path, body: JSON.parse(options.body) });
      return path.endsWith('/closure')
        ? { code: 200, data: { review_cards: [{ review_id: 'review-1', match_status: 'unmatched' }] } }
        : { code: 200, data: { save_status: 'saved_and_readback_verified' } };
    },
    () => ({ hotelId: '64' }),
    () => true,
    context => ({ status: 'stale', context }),
    (message, level) => notices.push({ message, level }),
    () => '64',
  );
  controller.meituanReviewMatchForm.value.reviewId = 'review-1';
  await controller.saveMeituanReviewForMatch();
  assert.deepEqual(calls.map(call => call.path), [
    '/online-data/meituan-review-matches/reviews',
    '/online-data/meituan-review-matches/closure',
  ]);
  assert.equal(calls[0].body.system_hotel_id, '64');
  assert.equal(calls[0].body.review.reviewId, 'review-1');
  assert.equal(controller.meituanReviewMatchSamples.value[0].review_id, 'review-1');
  assert.equal(notices.at(-1).level, 'success');

  const missingHotelCalls = [];
  const missingHotelController = meituanStatic.createMeituanReviewMatchController(
    ref,
    computed,
    async (...args) => missingHotelCalls.push(args),
    () => ({ hotelId: '' }),
    () => true,
    context => ({ status: 'stale', context }),
    () => {},
    () => '',
  );
  assert.deepEqual(
    await missingHotelController.checkMeituanReviewMatchClosure(),
    { status: 'missing_hotel' },
  );
  assert.equal(missingHotelCalls.length, 0);
});
