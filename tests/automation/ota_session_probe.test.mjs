import assert from 'node:assert/strict';
import test from 'node:test';
import {
  classifyOtaSessionProbeResponse,
  evaluateOtaSessionProbe,
  isRecognizedOtaSessionProbeResponse,
  OTA_SESSION_PROBE_CONTRACT_VERSION,
  otaSessionCookieInjectionDomains,
  recordOtaSessionProbeCandidateDiagnostic,
  sanitizeOtaObservedRoute,
  sanitizeOtaObservedUrl,
  summarizeOtaSessionCookies,
} from '../../scripts/lib/ota_session_probe.mjs';

const fixedNow = new Date('2026-07-19T02:00:00.000Z');

test('Ctrip probe requires positive business and reusable session evidence', () => {
  const cookieSummary = summarizeOtaSessionCookies('ctrip', [
    { name: 'session_id', value: 'must-not-leak', domain: '.ctrip.com', httpOnly: true, expires: -1 },
    { name: 'expired', value: 'must-not-leak', domain: '.ctrip.com', expires: 1 },
    { name: 'unrelated', value: 'must-not-leak', domain: '.example.com', expires: -1 },
  ], fixedNow.getTime());
  const result = evaluateOtaSessionProbe('ctrip', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://ebooking.ctrip.com/home/mainland',
    page_text: '酒店管理 工作台 订单 数据报表',
    cookie_summary: cookieSummary,
    successful_api_response_count: 1,
  }, { now: fixedNow });

  assert.equal(result.status, 'collectable');
  assert.equal(result.collectable, true);
  assert.equal(result.evidence_level, 'strong');
  assert.equal(result.signals.session_state.platform_state_count, 1);
  assert.equal(result.signals.session_state.session_state_count, 1);
  assert.equal(JSON.stringify(result).includes('must-not-leak'), false);
  assert.equal(Object.hasOwn(result.signals.url, 'url'), false);
});

test('observed auth URLs drop query and fragment material before persistence', () => {
  assert.equal(
    sanitizeOtaObservedUrl('https://ebooking.ctrip.com/home/mainland?ticket=must-not-leak#token'),
    'https://ebooking.ctrip.com/home/mainland',
  );
  assert.equal(sanitizeOtaObservedUrl('not-a-url'), '');
});

test('page and Cookie hints without a protected business response remain weak evidence', () => {
  const result = evaluateOtaSessionProbe('ctrip', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://ebooking.ctrip.com/home/mainland',
    page_text: '酒店管理 工作台 订单',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    successful_api_response_count: 0,
  }, { now: fixedNow });

  assert.equal(result.status, 'weak_evidence');
  assert.equal(result.collectable, false);
  assert.deepEqual(result.failed_check_ids, ['recognized_business_response']);
});

test('a recognized business API cannot replace reusable session state', () => {
  const result = evaluateOtaSessionProbe('meituan', {
    auth_status: { ok: true, status: 'authorized' },
    url: 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
    page_text: '商家工作台 经营数据',
    cookie_summary: { platform_cookie_count: 0, session_cookie_count: 0 },
    successful_api_response_count: 1,
    identity_status: 'not_checked_login_only',
  }, { now: fixedNow });

  assert.equal(result.status, 'cookies_incomplete');
  assert.equal(result.collectable, false);
  assert.equal(result.signals.api.status, 'pass');
  assert.equal(result.evidence_type, 'insufficient');
});

test('a recognized business API plus reusable session state is account-collectable and hotel-proof eligible only after identity match', () => {
  const result = evaluateOtaSessionProbe('meituan', {
    auth_status: { ok: true, status: 'authorized' },
    url: 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
    page_text: '商家工作台 经营数据',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    successful_api_response_count: 1,
    identity_status: 'matched',
  }, { now: fixedNow });

  assert.equal(result.status, 'collectable');
  assert.equal(result.collectable, true);
  assert.equal(result.proof_eligible, true);
  assert.equal(result.evidence_type, 'recognized_business_response_2xx_plus_session_cookie');
});

test('a recognized protected API remains strong when visible business copy drifts', () => {
  const result = evaluateOtaSessionProbe('ctrip', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://ebooking.ctrip.com/home/mainland',
    page_text: '',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    successful_api_response_count: 1,
    identity_status: 'matched',
  }, { now: fixedNow });

  assert.equal(result.status, 'collectable');
  assert.equal(result.collectable, true);
  assert.equal(result.signals.page.business_marker_present, false);
  assert.equal(result.drift_diagnostics.status, 'none');
});

test('candidate route drift is not overwritten by a stale page-login heuristic', () => {
  const result = evaluateOtaSessionProbe('ctrip', {
    auth_status: { ok: false, status: 'login_required' },
    url: 'https://ebooking.ctrip.com/home/mainland',
    page_text: '酒店管理 工作台 订单',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    response_diagnostics: { candidate_drift_response_count: 1 },
    identity_status: 'matched',
  }, { now: fixedNow });

  assert.equal(result.status, 'platform_contract_drift');
  assert.equal(result.collectable, false);
  assert.deepEqual(result.failed_check_ids, ['platform_contract_current']);
  assert.match(result.next_action, /不要反复登录/);
});

test('protected business API plus Session state can override a stale page heuristic', () => {
  const result = evaluateOtaSessionProbe('meituan', {
    auth_status: { ok: false, status: 'login_required' },
    url: 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
    page_text: '商家工作台 经营数据',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    successful_api_response_count: 1,
    identity_status: 'matched',
  }, { now: fixedNow });

  assert.equal(result.status, 'collectable');
  assert.equal(result.collectable, true);
  assert.equal(result.signals.auth.status, 'pass');
  assert.equal(result.signals.auth.evidence_source, 'protected_api_plus_session_state');
});

test('a protected 403 remains permission denied and never asks for relogin', () => {
  const result = evaluateOtaSessionProbe('meituan', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
    page_text: '商家工作台 经营数据',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    response_diagnostics: { permission_denied_response_count: 1 },
    identity_status: 'matched',
  }, { now: fixedNow });

  assert.equal(result.status, 'permission_denied');
  assert.equal(result.collectable, false);
  assert.deepEqual(result.failed_check_ids, ['platform_permission']);
  assert.match(result.next_action, /不要通过反复登录/);
});

test('ordinary SMS verification remains a login step and never triggers anti-bot backoff', () => {
  for (const platform of ['ctrip', 'meituan']) {
    const result = evaluateOtaSessionProbe(platform, {
      auth_status: { ok: false, status: 'login_required' },
      url: platform === 'ctrip'
        ? 'https://ebooking.ctrip.com/home/mainland'
        : 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
      page_text: '手机号 验证码 登录',
      cookie_summary: { platform_cookie_count: 0, session_cookie_count: 0 },
      successful_api_response_count: 0,
    }, { now: fixedNow });

    assert.equal(result.status, 'login_required');
    assert.equal(result.retry_after_seconds, 0);
  }
});

test('risk-control evidence has precedence and emits a bounded backoff', () => {
  const result = evaluateOtaSessionProbe('meituan', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
    page_text: '访问频繁，请完成滑块安全验证',
    cookie_summary: { platform_cookie_count: 4, session_cookie_count: 2 },
    successful_api_response_count: 1,
  }, { now: fixedNow, antiBotBackoffSeconds: 900 });

  assert.equal(result.status, 'anti_bot');
  assert.equal(result.collectable, false);
  assert.equal(result.retry_after_seconds, 900);
  assert.equal(result.next_retry_at, '2026-07-19T02:15:00.000Z');
  assert.equal(result.signals.page.risk_control_present, true);
});

test('untrusted or login surfaces stay blocked even with cookies', () => {
  const result = evaluateOtaSessionProbe('ctrip', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://passport.ctrip.com/login',
    page_text: '登录 密码',
    cookie_summary: { platform_cookie_count: 3, session_cookie_count: 2 },
  }, { now: fixedNow });

  assert.equal(result.status, 'login_required');
  assert.equal(result.collectable, false);
});

test('hotel identity mismatch blocks an otherwise healthy session', () => {
  const result = evaluateOtaSessionProbe('meituan', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
    page_text: '商家工作台 订单 经营数据',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    identity_status: 'mismatch',
  }, { now: fixedNow });

  assert.equal(result.status, 'identity_mismatch');
  assert.equal(result.collectable, false);
  assert.deepEqual(result.failed_check_ids, ['platform_identity_match']);
});

test('account-level collectability does not become hotel proof while identity is unchecked', () => {
  const result = evaluateOtaSessionProbe('ctrip', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://ebooking.ctrip.com/home/mainland',
    page_text: 'hotel dashboard order report',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    successful_api_response_count: 1,
    identity_status: 'not_checked',
  }, { now: fixedNow });

  assert.equal(result.collectable, true);
  assert.equal(result.proof_eligible, false);
  assert.deepEqual(result.proof_blocker_ids, ['platform_identity_match']);
  assert.equal(result.signals.identity.hotel_scope_verified, false);
});

test('session-expired and verification overlays always block a business page', () => {
  const base = {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://ebooking.ctrip.com/home/mainland',
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    successful_api_response_count: 1,
    identity_status: 'matched',
  };
  const expired = evaluateOtaSessionProbe('ctrip', {
    ...base,
    page_text: 'hotel dashboard order report \u767b\u5f55\u5df2\u8fc7\u671f',
  }, { now: fixedNow });
  const challenge = evaluateOtaSessionProbe('ctrip', {
    ...base,
    page_text: 'hotel dashboard order report CAPTCHA',
  }, { now: fixedNow });

  assert.equal(expired.status, 'login_required');
  assert.equal(expired.collectable, false);
  assert.equal(expired.signals.page.session_expired_present, true);
  assert.equal(challenge.status, 'anti_bot');
  assert.equal(challenge.collectable, false);
  assert.equal(challenge.signals.page.challenge_present, true);
});

test('login-required overlays and non-auth HttpOnly cookies cannot imitate a reusable session', () => {
  const nonAuthCookies = summarizeOtaSessionCookies('ctrip', [
    { name: 'device_id', domain: '.ctrip.com', httpOnly: true, expires: -1 },
  ], fixedNow.getTime());
  assert.equal(nonAuthCookies.platform_cookie_count, 1);
  assert.equal(nonAuthCookies.session_cookie_count, 0);

  for (const pageText of [
    '酒店管理 工作台 订单 请扫码登录',
    '酒店管理 工作台 登录状态已失效',
    '酒店管理 工作台 登录态已过期',
  ]) {
    const result = evaluateOtaSessionProbe('ctrip', {
      auth_status: { ok: true, status: 'logged_in' },
      url: 'https://ebooking.ctrip.com/home/mainland',
      page_text: pageText,
      cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
      successful_api_response_count: 1,
      identity_status: 'matched',
    }, { now: fixedNow });
    assert.equal(result.status, 'login_required');
    assert.equal(result.collectable, false);
  }
});

test('Ctrip enterprise domains are trusted for profile cookies and business pages', () => {
  const cookies = summarizeOtaSessionCookies('ctrip', [
    { name: 'session_id', domain: '.ctripbiz.cn', httpOnly: true, expires: -1 },
  ], fixedNow.getTime());
  const result = evaluateOtaSessionProbe('ctrip', {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://bbk.ctripbiz.cn/report',
    page_text: 'hotel dashboard order report',
    cookie_summary: cookies,
    successful_api_response_count: 1,
    identity_status: 'matched',
  }, { now: fixedNow });

  assert.equal(cookies.session_cookie_count, 1);
  assert.equal(result.signals.url.trusted_host, true);
  assert.equal(result.collectable, true);
});

test('session response metadata accepts only known protected JSON endpoints on exact hosts', () => {
  const base = { status: 200, resource_type: 'xhr', content_type: 'application/json' };
  assert.equal(isRecognizedOtaSessionProbeResponse('ctrip', {
    ...base,
    url: 'https://ebooking.ctrip.com/api/getDayReportRealTimeDate',
  }), true);
  assert.equal(isRecognizedOtaSessionProbeResponse('ctrip', {
    ...base,
    url: 'https://ebooking.ctrip.com/telemetry/collect',
  }), false);
  for (const url of [
    'https://ebooking.ctrip.com/telemetry?next=/api/getDayReportRealTimeDate',
    'https://ebooking.ctrip.com/telemetry?event=getDayReportRealTimeDate',
    'https://ebooking.ctrip.com/public/config?source=dataCenterBusinessReportDetail',
  ]) {
    assert.equal(isRecognizedOtaSessionProbeResponse('ctrip', { ...base, url }), false);
  }
  assert.equal(isRecognizedOtaSessionProbeResponse('ctrip', {
    ...base,
    url: 'https://evilctrip.com/api/getDayReportRealTimeDate',
  }), false);
  assert.equal(isRecognizedOtaSessionProbeResponse('meituan', {
    ...base,
    url: 'https://eb.meituan.com/api/v1/ebooking/business/flowTrend',
  }), true);
  for (const url of [
    'https://eb.meituan.com/api/v1/ebooking/business/peertrends',
    'https://eb.meituan.com/api/v1/ebooking/commentsinfo',
    'https://eb.meituan.com/api/v1/ebooking/order-eb/list',
    'https://me.meituan.com/api/gw/v1/merchantportal/account/queryAccountInfo',
    'https://me.meituan.com/api/gw/v1/base/common/queryVpoiBaseInfo',
    'https://me.meituan.com/api/gw/v1/ampaccount/accountpoi/poiInfos',
    'https://me.meituan.com/api/gw/v1/order/unhandled/count',
  ]) {
    assert.equal(isRecognizedOtaSessionProbeResponse('meituan', { ...base, url }), true, url);
  }
  assert.equal(isRecognizedOtaSessionProbeResponse('meituan', {
    ...base,
    url: 'https://eb.meituan.com/api/v1/ebooking/telemetry/traffic',
  }), false);
  assert.equal(isRecognizedOtaSessionProbeResponse('meituan', {
    ...base,
    content_type: 'text/html',
    url: 'https://eb.meituan.com/api/v1/ebooking/business/flowTrend',
  }), false);
});

test('trusted unknown JSON and changed content types become bounded contract-drift diagnostics', () => {
  const base = { status: 200, resource_type: 'xhr', content_type: 'application/json' };
  const unknown = classifyOtaSessionProbeResponse('meituan', {
    ...base,
    url: 'https://eb.meituan.com/api/v1/ebooking/business/newDashboardV2?token=must-not-leak',
  });
  const changed = classifyOtaSessionProbeResponse('meituan', {
    ...base,
    content_type: 'text/html',
    url: 'https://eb.meituan.com/api/v1/ebooking/business/flowTrend',
  });

  assert.equal(unknown.classification, 'candidate_drift');
  assert.equal(unknown.reason, 'unknown_business_json_route');
  assert.equal(changed.classification, 'candidate_drift');
  assert.equal(changed.reason, 'recognized_route_content_type_changed');
  assert.equal(JSON.stringify([unknown, changed]).includes('must-not-leak'), false);
  assert.equal(
    sanitizeOtaObservedRoute('https://eb.meituan.com/api/v1/ebooking/business/newDashboardV2/123456?token=must-not-leak'),
    'eb.meituan.com/api/v1/ebooking/business/newDashboardV2/:id',
  );
});

test('candidate endpoints block while protected APIs can safely anchor renamed Session cookies', () => {
  const base = {
    auth_status: { ok: true, status: 'logged_in' },
    url: 'https://ebooking.ctrip.com/home/mainland',
    page_text: '酒店管理 工作台 订单',
    identity_status: 'matched',
  };
  const endpointDrift = evaluateOtaSessionProbe('ctrip', {
    ...base,
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 1 },
    response_diagnostics: {
      candidate_drift_response_count: 1,
      candidate_route_samples: [
        'https://ebooking.ctrip.com/api/new-dashboard/123456?token=must-not-leak',
        'https://ebooking.ctrip.com/api/new-dashboard/123456?token=second-secret',
      ],
    },
  }, { now: fixedNow });
  const cookieDrift = evaluateOtaSessionProbe('ctrip', {
    ...base,
    cookie_summary: { platform_cookie_count: 2, session_cookie_count: 0 },
    response_diagnostics: { recognized_response_count: 1 },
  }, { now: fixedNow });

  assert.equal(endpointDrift.status, 'platform_contract_drift');
  assert.equal(endpointDrift.collectable, false);
  assert.deepEqual(endpointDrift.failed_check_ids, ['platform_contract_current']);
  assert.equal(endpointDrift.drift_diagnostics.status, 'suspected');
  assert.equal(endpointDrift.drift_diagnostics.sensitive_values_exposed, false);
  assert.equal(endpointDrift.contract_version, OTA_SESSION_PROBE_CONTRACT_VERSION);
  assert.deepEqual(endpointDrift.drift_diagnostics.signal_ids, ['protected_route_rule_miss']);
  assert.deepEqual(endpointDrift.drift_diagnostics.candidate_route_samples, [
    'ebooking.ctrip.com/api/new-dashboard/:id',
  ]);
  assert.equal(JSON.stringify(endpointDrift).includes('must-not-leak'), false);
  assert.equal(JSON.stringify(endpointDrift).includes('second-secret'), false);
  assert.equal(cookieDrift.status, 'collectable');
  assert.equal(cookieDrift.collectable, true);
  assert.equal(cookieDrift.signals.session_state.session_state_count, 2);
  assert.equal(cookieDrift.signals.session_state.classified_session_state_count, 0);
  assert.equal(cookieDrift.signals.session_state.evidence_source, 'protected_api_plus_persistent_platform_cookie');
  assert.equal(cookieDrift.drift_diagnostics.status, 'none');
  assert.deepEqual(cookieDrift.drift_diagnostics.signal_ids, []);
  assert.deepEqual(cookieDrift.drift_diagnostics.advisory_signal_ids, ['session_cookie_name_fallback']);
});

test('Ctrip and Meituan observers can retain bounded candidate routes and reason IDs without URL secrets', () => {
  for (const platform of ['ctrip', 'meituan']) {
    const host = platform === 'ctrip' ? 'ebooking.ctrip.com' : 'eb.meituan.com';
    const diagnostics = {
      candidate_drift_response_count: 0,
      candidate_route_samples: [],
      candidate_reason_ids: [],
    };
    for (let index = 0; index < 25; index += 1) {
      const url = `https://${host}/api/v999/new-contract/route-${index}/token/must-not-leak?access_token=secret-${index}#cookie`;
      const classified = classifyOtaSessionProbeResponse(platform, {
        url,
        status: 200,
        resource_type: 'fetch',
        content_type: 'application/json',
      });
      assert.equal(classified.classification, 'candidate_drift');
      recordOtaSessionProbeCandidateDiagnostic(diagnostics, classified, url);
    }

    assert.equal(diagnostics.candidate_drift_response_count, 20);
    assert.equal(diagnostics.candidate_route_samples.length, 20);
    assert.deepEqual(diagnostics.candidate_reason_ids, ['unknown_business_json_route']);
    assert.match(diagnostics.candidate_route_samples[0], /\/token\/:redacted$/);
    assert.equal(JSON.stringify(diagnostics).includes('must-not-leak'), false);
    assert.equal(JSON.stringify(diagnostics).includes('secret-'), false);
    assert.equal(JSON.stringify(diagnostics).includes('?'), false);
    assert.equal(JSON.stringify(diagnostics).includes('#'), false);
    assert.equal(
      sanitizeOtaObservedRoute(`https://${host}/api/v999/store/550e8400-e29b-41d4-a716-446655440000?token=secret`),
      `${host}/api/v999/store/:id`,
    );

    const normalized = evaluateOtaSessionProbe(platform, {
      auth_status: { ok: true, status: 'logged_in' },
      url: platform === 'ctrip'
        ? 'https://ebooking.ctrip.com/home/mainland'
        : 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
      cookie_summary: { platform_cookie_count: 1, session_cookie_count: 1 },
      identity_status: 'matched',
      response_diagnostics: diagnostics,
    }, { now: fixedNow });
    assert.equal(normalized.status, 'platform_contract_drift');
    assert.equal(normalized.drift_diagnostics.candidate_route_samples.length, 20);
    assert.deepEqual(normalized.drift_diagnostics.candidate_reason_ids, ['unknown_business_json_route']);
    assert.equal(JSON.stringify(normalized).includes('must-not-leak'), false);
  }
});

test('candidate route sanitization fails closed for modern credential-shaped path segments', () => {
  const host = 'ebooking.ctrip.com';
  const samples = [
    ['01890f4c-7b2a-7cc2-9f2c-4a7d6e5b3c1a', ':id'],
    ['sessionid/cookie-secret-value', 'sessionid/:redacted'],
    ['x-api-key/api-secret-value', 'x-api-key/:redacted'],
    ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signatureValue123', ':redacted'],
    ['AbCdEfGhIjKlMnOpQrStUv123456', ':redacted'],
    ['token%2Fsuper-secret-value', ':redacted'],
    ['abcdefghijklmnopqrstuvwxyzABCDEFGH', ':redacted'],
    ['QWxhZGRpbjpvcGVuIHNlc2FtZQ==', ':redacted'],
    ['token%252Fdouble-secret-value', ':redacted'],
    ['token%2525252Fdeep-secret-value', ':redacted'],
    ['connect.sid/cookie-secret-value', 'connect.sid/:redacted'],
    ['ctrip_session/shortsecret', 'ctrip_session/:redacted'],
    ['ctrip-auth/shortsecret', 'ctrip-auth/:redacted'],
    ['ebk_session/shortsecret', 'ebk_session/:redacted'],
    ['ebooking-ticket/shortsecret', 'ebooking-ticket/:redacted'],
    ['ABCDEFGHIJKLMNOPQRSTUVWX.token/shortsecret', ':redacted/:redacted'],
    ['v4.public.abcdefghijklmnopqrstuvwxyz1234567890.signature/shortsecret', ':redacted/:redacted'],
    ['abcdefghijklmnopqrstuvwxyz.auth/shortsecret', ':redacted/:redacted'],
    ['PHPSESSID/shortsecret', 'phpsessid/:redacted'],
    ['foo.bar.sid/shortsecret', 'foo.bar.sid/:redacted'],
    ['ebfooToken/shortsecret', 'ebfootoken/:redacted'],
    ['ebMerchantSession/shortsecret', 'ebmerchantsession/:redacted'],
  ];

  for (const [path, expected] of samples) {
    const sanitized = sanitizeOtaObservedRoute(`https://${host}/api/${path}`);
    assert.equal(sanitized, `${host}/api/${expected}`);
    assert.equal(sanitized.includes('secret-value'), false);
    assert.equal(sanitized.includes('eyJ'), false);
    assert.equal(sanitized.includes('AbCd'), false);
    assert.equal(sanitized.includes('QWxh'), false);
    assert.equal(sanitized.includes('double-secret'), false);
    assert.equal(sanitized.includes('deep-secret'), false);
    assert.equal(sanitized.includes('abcdefghijklmnopqrstuvwxyz'), false);
    assert.equal(sanitized.includes('shortsecret'), false);
    assert.equal(sanitized.includes('ABCDEFGHIJKLMNOP'), false);
    assert.equal(sanitized.includes('abcdefghijklmnopqrstuvwxyz.auth'), false);
    assert.equal(sanitized.includes('v4.public.'), false);
  }
});

test('HTTP access and rate-limit responses stay distinguishable without response bodies', () => {
  const base = {
    resource_type: 'fetch',
    content_type: 'application/json',
    url: 'https://eb.meituan.com/api/v1/ebooking/business/flowTrend',
  };
  assert.equal(classifyOtaSessionProbeResponse('meituan', { ...base, status: 401 }).classification, 'authentication_required');
  assert.equal(classifyOtaSessionProbeResponse('meituan', { ...base, status: 403 }).classification, 'permission_denied');
  assert.equal(classifyOtaSessionProbeResponse('meituan', { ...base, status: 429 }).classification, 'rate_limited');
});

test('capture Cookie domains come from the versioned Session contract', () => {
  assert.deepEqual(otaSessionCookieInjectionDomains('ctrip'), [
    'ebooking.ctrip.com',
    '.ctrip.com',
    'bbk.ctripbiz.cn',
    '.ctripbiz.cn',
    'bbk.ctripbiz.com',
    '.ctripbiz.com',
  ]);
  assert.deepEqual(otaSessionCookieInjectionDomains('meituan'), [
    'me.meituan.com',
    'eb.meituan.com',
    '.meituan.com',
    '.dianping.com',
  ]);
});
