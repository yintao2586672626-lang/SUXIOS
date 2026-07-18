import { findCtripEndpointByUrl } from './ctrip_capture_catalog.mjs';

export const OTA_SESSION_PROBE_CONTRACT_VERSION = '2026-07-19.1';

const COOKIE_INJECTION_DOMAINS = Object.freeze({
  ctrip: Object.freeze([
    'ebooking.ctrip.com',
    '.ctrip.com',
    'bbk.ctripbiz.cn',
    '.ctripbiz.cn',
    'bbk.ctripbiz.com',
    '.ctripbiz.com',
  ]),
  meituan: Object.freeze(['me.meituan.com', 'eb.meituan.com', '.meituan.com', '.dianping.com']),
});

const PLATFORM_RULES = {
  ctrip: {
    cookieDomain: /(^|\.)(ctrip\.com|trip\.com|ctripbiz\.cn|ctripbiz\.com)$/i,
    businessText: /订单|点评|评论|经营|数据|流量|工作台|房态|房价|酒店管理|ebooking|order|report|review|traffic|dashboard/i,
    trustedHost: host => host === 'ctrip.com'
      || host.endsWith('.ctrip.com')
      || host === 'ctripbiz.cn'
      || host.endsWith('.ctripbiz.cn')
      || host === 'ctripbiz.com'
      || host.endsWith('.ctripbiz.com'),
    businessPath: path => !/(^|\/)(login|passport|account|oauth|sso)(\/|$)/i.test(path),
    sessionCookieName: /^(?:cticket|(?:asp\.net_)?sessionid|jsessionid|session_?id|sid|auth_?token|access_?token|login_?token|passport|ctrip.*(?:ticket|session|auth|login)|(?:ebk|ebooking).*(?:session|ticket|token))$/i,
  },
  meituan: {
    cookieDomain: /(^|\.)(meituan\.com|dianping\.com)$/i,
    businessText: /订单|点评|评价|商家|经营|数据|流量|入住|工作台|曝光|浏览|order|review|merchant|traffic|dashboard/i,
    trustedHost: host => host === 'me.meituan.com' || host === 'eb.meituan.com' || host === 'ebmidas.dianping.com',
    businessPath: path => /\/ebooking\/merchant|\/newhb-sub-app|\/ebooking\/merchant\/ebiframe/i.test(path),
    sessionCookieName: /^(?:jsessionid|session_?id|sid|auth_?token|access_?token|login_?token|user_?ticket|ssoid|mt_c_token|_mtsi_eb_u|(?:eb|ebooking).*(?:session|ticket|token))$/i,
  },
};

const LOGIN_TEXT = /登录|密码|手机号|验证码|扫码|login|password|sign\s*in|verification\s*code|scan\s*(the\s*)?code/i;
const LOGIN_REQUIRED_TEXT = /(?:请|需要|必须|重新|立即|扫码|账号|密码|手机号).{0,8}登录|登录(?:页面|账号|密码)|(?:未|尚未)登录|log\s*in\s*(?:to|again|required)|sign\s*in\s*(?:to|again|required)|scan\s*(?:the\s*)?(?:qr\s*)?code\s*(?:to\s*)?(?:log|sign)\s*in/i;
const RISK_CONTROL_TEXT = /滑块|安全验证|访问频繁|操作频繁|异常流量|人机验证|风险验证|平台风控|risk\s*control|unusual\s*traffic|too\s*many\s*requests|verify\s*you\s*are\s*human|captcha\s*challenge/i;
const SESSION_EXPIRED_TEXT = /登录(?:状态|态|会话)?(?:已)?(?:过期|失效|无效)|会话(?:已)?(?:过期|失效|无效)|请重新登录|(?:login|session)\s*(?:has\s*)?(?:expired|invalid)|signed?\s*out/i;
const CHALLENGE_TEXT = /图形验证码|安全验证|人机验证|滑块|captcha|security\s*check|verify\s*you\s*are\s*human/i;

export function summarizeOtaSessionCookies(platform, cookies = [], nowMs = Date.now()) {
  const rules = rulesFor(platform);
  const safeCookies = Array.isArray(cookies) ? cookies : [];
  let platformCookieCount = 0;
  let sessionCookieCount = 0;

  for (const cookie of safeCookies) {
    const domain = String(cookie?.domain || '').replace(/^\./, '').toLowerCase();
    if (!rules.cookieDomain.test(domain)) continue;
    const expires = Number(cookie?.expires ?? -1);
    if (Number.isFinite(expires) && expires > 0 && expires * 1000 <= nowMs) continue;
    platformCookieCount += 1;
    if (rules.sessionCookieName.test(String(cookie?.name || '').trim())) {
      sessionCookieCount += 1;
    }
  }

  return {
    platform_cookie_count: platformCookieCount,
    session_cookie_count: sessionCookieCount,
  };
}

export function sanitizeOtaObservedUrl(value) {
  try {
    const parsed = new URL(String(value || ''));
    parsed.search = '';
    parsed.hash = '';
    return parsed.toString();
  } catch {
    return '';
  }
}

export function sanitizeOtaObservedRoute(value) {
  try {
    const parsed = new URL(String(value || ''));
    const path = parsed.pathname
      .split('/')
      .map(segment => (/^\d+$/.test(segment) || /^[0-9a-f]{16,}$/i.test(segment) ? ':id' : segment))
      .join('/');
    return `${parsed.hostname.toLowerCase()}${path}`.slice(0, 320);
  } catch {
    return '';
  }
}

export function otaSessionCookieInjectionDomains(platform) {
  return [...COOKIE_INJECTION_DOMAINS[normalizePlatform(platform)]];
}

export function isTrustedOtaPlatformUrl(platform, value) {
  const rules = rulesFor(platform);
  try {
    return rules.trustedHost(new URL(String(value || '')).hostname.toLowerCase());
  } catch {
    return false;
  }
}

export function classifyOtaSessionProbeResponse(platform, meta = {}) {
  const normalizedPlatform = normalizePlatform(platform);
  const rules = rulesFor(normalizedPlatform);
  const status = Number(meta?.status || 0);
  const resourceType = String(meta?.resource_type || meta?.resourceType || '').trim().toLowerCase();
  const contentType = String(meta?.content_type || meta?.contentType || '').trim().toLowerCase();
  if (!['xhr', 'fetch'].includes(resourceType)) {
    return { classification: 'ignored', reason: 'non_ajax_resource' };
  }

  let parsed;
  try {
    parsed = new URL(String(meta?.url || ''));
  } catch {
    return { classification: 'ignored', reason: 'invalid_url' };
  }
  const host = parsed.hostname.toLowerCase();
  const path = parsed.pathname.toLowerCase();
  if (!rules.trustedHost(host)) {
    return { classification: 'ignored', reason: 'untrusted_host' };
  }
  if (/(^|\/)(login|passport|oauth|sso)(\/|$)/i.test(path)) {
    return { classification: 'ignored', reason: 'login_route' };
  }

  const recognizedRoute = isRecognizedOtaSessionProbeRoute(normalizedPlatform, parsed);
  if (status === 429) {
    return { classification: 'rate_limited', reason: 'http_429', recognized_route: recognizedRoute };
  }
  if (status === 401) {
    return { classification: 'authentication_required', reason: 'http_401', recognized_route: recognizedRoute };
  }
  if (status === 403) {
    return { classification: 'permission_denied', reason: 'http_403', recognized_route: recognizedRoute };
  }
  if (status < 200 || status >= 300) {
    return { classification: 'ignored', reason: 'non_success_status', recognized_route: recognizedRoute };
  }
  if (!contentType.includes('json')) {
    return recognizedRoute
      ? { classification: 'candidate_drift', reason: 'recognized_route_content_type_changed', recognized_route: true }
      : { classification: 'ignored', reason: 'non_json_response' };
  }
  if (recognizedRoute) {
    return { classification: 'recognized', reason: 'protected_business_json_2xx', recognized_route: true };
  }
  return { classification: 'candidate_drift', reason: 'unknown_business_json_route', recognized_route: false };
}

export function isRecognizedOtaSessionProbeResponse(platform, meta = {}) {
  return classifyOtaSessionProbeResponse(platform, meta).classification === 'recognized';
}

export function evaluateOtaSessionProbe(platform, input = {}, options = {}) {
  const normalizedPlatform = normalizePlatform(platform);
  const rules = rulesFor(normalizedPlatform);
  const checkedAt = normalizeCheckedAt(options.now);
  const authStatus = input?.auth_status && typeof input.auth_status === 'object' ? input.auth_status : {};
  const authCode = String(authStatus.status || '').trim().toLowerCase();
  const authOk = authStatus.ok === true && ['logged_in', 'authorized'].includes(authCode);
  const urlSignals = inspectUrl(rules, input?.url);
  const pageText = String(input?.page_text || '').slice(0, 20000);
  const businessMarkerPresent = rules.businessText.test(pageText);
  const loginMarkerPresent = LOGIN_TEXT.test(pageText);
  const loginRequiredPresent = LOGIN_REQUIRED_TEXT.test(pageText);
  const sessionExpiredPresent = SESSION_EXPIRED_TEXT.test(pageText)
    || /session[_-]?expired|login[_-]?expired|session[_-]?invalid/.test(authCode);
  const challengePresent = CHALLENGE_TEXT.test(pageText)
    || /captcha|security[_-]?challenge|human[_-]?verification|slider/.test(authCode);
  const cookieSummary = normalizeCookieSummary(input?.cookie_summary);
  const responseDiagnostics = normalizeResponseDiagnostics(input?.response_diagnostics);
  const successfulApiResponseCount = Math.max(
    nonNegativeInteger(input?.successful_api_response_count),
    responseDiagnostics.recognized_response_count,
  );
  const candidateDriftResponseCount = responseDiagnostics.candidate_drift_response_count;
  const legacyAccessDeniedResponseCount = responseDiagnostics.access_denied_response_count;
  const authenticationRequiredResponseCount = responseDiagnostics.authentication_required_response_count;
  const permissionDeniedResponseCount = responseDiagnostics.permission_denied_response_count;
  const rateLimitedResponseCount = responseDiagnostics.rate_limited_response_count;
  const riskControlPresent = RISK_CONTROL_TEXT.test(pageText)
    || challengePresent
    || rateLimitedResponseCount > 0
    || /anti[_-]?bot|risk[_-]?control|captcha[_-]?challenge/.test(authCode);
  const identityStatus = String(input?.identity_status || 'not_checked').trim().toLowerCase() || 'not_checked';
  const identityMismatch = ['mismatch', 'mismatched', 'hotel_mismatch', 'store_mismatch', 'poi_mismatch'].includes(identityStatus);
  const identityMatched = ['matched', 'verified', 'hotel_matched', 'store_matched', 'poi_matched'].includes(identityStatus);
  const protectedApiEvidencePresent = successfulApiResponseCount > 0;
  const classifiedSessionStateCount = cookieSummary.session_cookie_count;
  const sessionStateCount = classifiedSessionStateCount > 0
    ? classifiedSessionStateCount
    : (protectedApiEvidencePresent ? cookieSummary.platform_cookie_count : 0);
  const sessionEvidencePresent = sessionStateCount > 0;
  const endpointRuleMissSuspected = candidateDriftResponseCount > 0 && !protectedApiEvidencePresent;
  const sessionCookieRuleFallback = protectedApiEvidencePresent
    && cookieSummary.platform_cookie_count > 0
    && classifiedSessionStateCount === 0;
  const loginSurfacePresent = urlSignals.login_path
    || loginRequiredPresent
    || (loginMarkerPresent && !businessMarkerPresent && successfulApiResponseCount === 0);

  let status = 'collectable';
  let message = '当前 Session 已通过多信号检查，可继续执行最小采集。';
  let nextAction = '执行目标日最小采集，并继续核对保存、DB 回读和页面回显。';
  const failedCheckIds = [];

  if (riskControlPresent) {
    status = 'anti_bot';
    message = '检测到平台风控或人机验证，当前 Session 不可采集。';
    nextAction = '停止自动重试，等待退避窗口结束后由账号使用者人工复核。';
    failedCheckIds.push('risk_control_clear');
  } else if (identityMismatch) {
    status = 'identity_mismatch';
    message = '当前平台身份与目标门店不匹配，禁止继续采集。';
    nextAction = '核对 Profile 与门店绑定后重新检测。';
    failedCheckIds.push('platform_identity_match');
  } else if (permissionDeniedResponseCount > 0) {
    status = 'permission_denied';
    message = '平台已响应，但当前账号缺少该业务模块或门店的访问权限。';
    nextAction = '核对平台账号的门店与模块权限；不要通过反复登录替代权限处理。';
    failedCheckIds.push('platform_permission');
  } else if (sessionExpiredPresent || loginSurfacePresent
    || authenticationRequiredResponseCount > 0 || legacyAccessDeniedResponseCount > 0
  ) {
    status = 'login_required';
    message = '当前 Profile 尚未形成可验证登录态。';
    nextAction = '由账号使用者在本机可见浏览器完成平台登录后重新检测。';
    failedCheckIds.push('auth_session');
  } else if (!urlSignals.trusted_host || !urlSignals.business_path) {
    status = 'weak_evidence';
    message = '已发现登录迹象，但业务页面或接口证据不足，不能标记为可采集。';
    nextAction = '刷新平台业务页后重新检测；若仍无业务证据，查看采集日志。';
    if (!urlSignals.trusted_host) failedCheckIds.push('trusted_business_host');
    if (!urlSignals.business_path) failedCheckIds.push('business_path');
  } else if (endpointRuleMissSuspected) {
    status = 'platform_contract_drift';
    message = '平台返回与当前 Session 识别规则不一致，已安全阻断，不能据此判断账号未登录。';
    nextAction = '运行授权范围内的接口证据校验并更新平台规则后重新检测；不要反复登录。';
    failedCheckIds.push('platform_contract_current');
  } else if (!authOk && !protectedApiEvidencePresent && !sessionEvidencePresent) {
    status = 'login_required';
    message = '当前 Profile 尚未形成可验证登录态。';
    nextAction = '由账号使用者在本机可见浏览器完成平台登录后重新检测。';
    failedCheckIds.push('auth_session');
  } else if (!protectedApiEvidencePresent) {
    status = 'weak_evidence';
    message = '业务页面与 Cookie 已出现，但尚未命中受保护业务 JSON，不能标记为可采集。';
    nextAction = '刷新平台业务页并触发一次只读查询；若出现未知接口，转入平台规则校准。';
    failedCheckIds.push('recognized_business_response');
  } else if (!sessionEvidencePresent) {
    status = 'cookies_incomplete';
    message = '受保护业务接口可访问，但未发现可复用 Session Cookie。';
    nextAction = '由账号使用者在本机刷新平台授权，再重新检测 Session。';
    failedCheckIds.push('session_cookie');
  }

  const collectable = status === 'collectable';
  if (collectable && !authOk) {
    message = '页面登录特征未命中，但受保护业务接口与可复用 Session 状态同时通过，可继续执行最小采集。';
  }
  const authEvidencePassed = authOk || collectable;
  const proofEligible = collectable && identityMatched;
  const evidenceType = collectable ? 'recognized_business_response_2xx_plus_session_cookie' : 'insufficient';
  const retryAfterSeconds = status === 'anti_bot'
    ? Math.max(60, nonNegativeInteger(options.antiBotBackoffSeconds || 900))
    : 0;
  const retryAt = retryAfterSeconds > 0
    ? new Date(new Date(checkedAt).getTime() + retryAfterSeconds * 1000).toISOString()
    : '';

  return {
    schema_version: 1,
    contract_version: OTA_SESSION_PROBE_CONTRACT_VERSION,
    mode: 'session_probe_only',
    platform: normalizedPlatform,
    performed: true,
    verified: collectable,
    status,
    collectable,
    proof_eligible: proofEligible,
    proof_blocker_ids: collectable && !identityMatched ? ['platform_identity_match'] : [],
    evidence_type: evidenceType,
    evidence_level: collectable ? 'strong' : (['weak_evidence', 'cookies_incomplete', 'platform_contract_drift'].includes(status) ? 'partial' : 'blocked'),
    sensitive_values_exposed: false,
    checked_at: checkedAt,
    retry_after_seconds: retryAfterSeconds,
    next_retry_at: retryAt,
    message,
    next_action: nextAction,
    failed_check_ids: failedCheckIds,
    signals: {
      auth: {
        status: authEvidencePassed ? 'pass' : 'fail',
        auth_status: authCode || 'unknown',
        evidence_source: authOk ? 'page_heuristic' : (collectable ? 'protected_api_plus_session_state' : 'none'),
      },
      url: {
        status: urlSignals.trusted_host && urlSignals.business_path ? 'pass' : 'fail',
        trusted_host: urlSignals.trusted_host,
        business_path: urlSignals.business_path,
        login_path: urlSignals.login_path,
      },
      page: {
        status: businessMarkerPresent && !riskControlPresent ? 'pass' : (riskControlPresent ? 'blocked' : 'not_observed'),
        business_marker_present: businessMarkerPresent,
        login_marker_present: loginMarkerPresent,
        login_required_present: loginRequiredPresent,
        session_expired_present: sessionExpiredPresent,
        challenge_present: challengePresent,
        risk_control_present: riskControlPresent,
      },
      session_state: {
        status: sessionEvidencePresent ? 'pass' : 'not_observed',
        platform_state_count: cookieSummary.platform_cookie_count,
        session_state_count: sessionStateCount,
        classified_session_state_count: classifiedSessionStateCount,
        evidence_source: classifiedSessionStateCount > 0
          ? 'recognized_session_cookie_name'
          : (sessionCookieRuleFallback ? 'protected_api_plus_persistent_platform_cookie' : 'none'),
      },
      api: {
        status: successfulApiResponseCount > 0 ? 'pass' : 'not_observed',
        successful_response_count: successfulApiResponseCount,
        candidate_drift_response_count: candidateDriftResponseCount,
        access_denied_response_count: legacyAccessDeniedResponseCount,
        authentication_required_response_count: authenticationRequiredResponseCount,
        permission_denied_response_count: permissionDeniedResponseCount,
        rate_limited_response_count: rateLimitedResponseCount,
      },
      identity: {
        status: identityMismatch ? 'mismatch' : identityStatus,
        hotel_scope_verified: identityMatched,
      },
    },
    drift_diagnostics: {
      contract_version: OTA_SESSION_PROBE_CONTRACT_VERSION,
      status: endpointRuleMissSuspected ? 'suspected' : 'none',
      signal_ids: [
        ...(endpointRuleMissSuspected ? ['protected_route_rule_miss'] : []),
      ],
      advisory_signal_ids: sessionCookieRuleFallback ? ['session_cookie_name_fallback'] : [],
      recognized_response_count: successfulApiResponseCount,
      candidate_response_count: candidateDriftResponseCount,
      candidate_route_samples: responseDiagnostics.candidate_route_samples,
      sensitive_values_exposed: false,
    },
  };
}

function isRecognizedOtaSessionProbeRoute(platform, parsed) {
  const path = parsed.pathname.toLowerCase();
  if (platform === 'ctrip') {
    const endpoint = findCtripEndpointByUrl(`${parsed.origin}${parsed.pathname}`);
    return Boolean(endpoint && !['supporting', 'screenshot_only', 'fact_only', 'aggregate_only'].includes(String(endpoint.status || '')));
  }
  const host = parsed.hostname.toLowerCase();
  if (host === 'me.meituan.com') {
    const merchantGatewayPatterns = [
      /\/api\/gw\/v1\/merchantportal\/account\/queryaccountinfo(?:\/|$)/i,
      /\/api\/gw\/v1\/base\/common\/queryvpoibaseinfo(?:\/|$)/i,
      /\/api\/gw\/v1\/ampaccount\/accountpoi\/poiinfos(?:\/|$)/i,
      /\/api\/gw\/v1\/order\/unhandled\/count(?:\/|$)/i,
    ];
    return merchantGatewayPatterns.some((pattern) => pattern.test(path));
  }
  if (host !== 'eb.meituan.com' || !path.startsWith('/api/v1/ebooking/')) {
    return false;
  }
  const protectedRoutePatterns = [
    /\/business\/(?:businessdata|weighttraffic|peer\/rank|peertrends|flowconversion|flowtrend|flowtrenddetail|flowforecast|search-?keywords?|room-?types?)(?:\/|$)/i,
    /\/peerrank\/order\/loss\/query(?:\/|$)/i,
    /\/orders(?:\/list)?(?:\/|$)/i,
    /\/order\/unhandled\/count(?:\/|$)/i,
    /\/order-eb(?:\/|$)/i,
    /\/comments?(?:\/|$)/i,
    /\/commentsinfo(?:\/|$)/i,
    /\/comments\/statistics(?:\/|$)/i,
    /\/querygeneralcommentinfo(?:\/|$)/i,
    /\/cureshops(?:\/|$)/i,
  ];
  return protectedRoutePatterns.some((pattern) => pattern.test(path));
}

function inspectUrl(rules, rawUrl) {
  try {
    const parsed = new URL(String(rawUrl || ''));
    const host = parsed.hostname.toLowerCase();
    const path = `${parsed.pathname}${parsed.hash || ''}`;
    const trustedHost = Boolean(rules.trustedHost(host));
    const loginPath = /(^|\/)(login|passport|account|oauth|sso)(\/|$)/i.test(path);
    return {
      trusted_host: trustedHost,
      business_path: trustedHost && Boolean(rules.businessPath(path)),
      login_path: loginPath,
    };
  } catch {
    return { trusted_host: false, business_path: false, login_path: false };
  }
}

function normalizeCookieSummary(value) {
  const summary = value && typeof value === 'object' ? value : {};
  return {
    platform_cookie_count: nonNegativeInteger(summary.platform_cookie_count),
    session_cookie_count: nonNegativeInteger(summary.session_cookie_count),
  };
}

function normalizeResponseDiagnostics(value) {
  const diagnostics = value && typeof value === 'object' ? value : {};
  return {
    recognized_response_count: nonNegativeInteger(diagnostics.recognized_response_count),
    candidate_drift_response_count: nonNegativeInteger(diagnostics.candidate_drift_response_count),
    access_denied_response_count: nonNegativeInteger(diagnostics.access_denied_response_count),
    authentication_required_response_count: nonNegativeInteger(diagnostics.authentication_required_response_count),
    permission_denied_response_count: nonNegativeInteger(diagnostics.permission_denied_response_count),
    rate_limited_response_count: nonNegativeInteger(diagnostics.rate_limited_response_count),
    candidate_route_samples: [...new Set((Array.isArray(diagnostics.candidate_route_samples)
      ? diagnostics.candidate_route_samples
      : [])
      .map(sanitizeOtaObservedRoute)
      .filter(Boolean))].slice(0, 20),
  };
}

function normalizePlatform(platform) {
  const normalized = String(platform || '').trim().toLowerCase();
  if (!Object.hasOwn(PLATFORM_RULES, normalized)) {
    throw new TypeError(`Unsupported OTA session probe platform: ${normalized || 'empty'}`);
  }
  return normalized;
}

function rulesFor(platform) {
  return PLATFORM_RULES[normalizePlatform(platform)];
}

function nonNegativeInteger(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? Math.max(0, Math.trunc(number)) : 0;
}

function normalizeCheckedAt(now) {
  if (now instanceof Date && Number.isFinite(now.getTime())) return now.toISOString();
  if (typeof now === 'string' || typeof now === 'number') {
    const parsed = new Date(now);
    if (Number.isFinite(parsed.getTime())) return parsed.toISOString();
  }
  return new Date().toISOString();
}
