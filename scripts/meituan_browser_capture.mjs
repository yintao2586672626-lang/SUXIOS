import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import process from 'node:process';
import {
  attachOtaCaptureEvidence,
  buildOtaCaptureEvidence,
  classifyOtaResponse as classifyStandardOtaResponse,
  extractOtaRequestDateEvidence,
  injectBrowserCookies as injectStandardBrowserCookies,
  normalizeCaptureSections as normalizeStandardCaptureSections,
  sanitizeOtaPayloadForStorage,
} from './lib/ota_capture_standard.mjs';
import { launchOtaPersistentContext } from './lib/cloakbrowser_launcher.mjs';
import {
  buildMeituanOrderFlowReplayUrls,
  isImportableMeituanTrafficRow,
  normalizeMeituanFlowAnalysisRows,
  normalizeMeituanOrderFlowRows,
  normalizeMeituanPeerRankRows,
  normalizeMeituanSearchKeywordRows,
  normalizeMeituanTrafficCardRows,
  normalizeMeituanTrafficForecastRows,
} from './lib/meituan_browser_capture_normalize.mjs';
import {
  evaluateMeituanCaptureGate,
  filterMeituanCumulativeRowsByTargetDate,
  filterMeituanEventRowsByTargetDate,
} from './lib/meituan_capture_gate.mjs';
import {
  collectMeituanPlatformIdentifiers,
  evaluateMeituanPlatformIdentity,
  extractMeituanRequestPlatformIdentifiers,
  isMeituanOwnHotelPayloadKey,
} from './lib/meituan_platform_identity.mjs';
import {
  classifyOtaSessionProbeResponse,
  evaluateOtaSessionProbe,
  recordOtaSessionProbeCandidateDiagnostic,
  sanitizeOtaObservedUrl,
  summarizeOtaSessionCookies,
} from './lib/ota_session_probe.mjs';
import { fail, parseArgs, safeName, timestamp, waitForEnter } from './lib/shared_helpers.mjs';

const URLS = {
  login: 'https://me.meituan.com/ebooking/',
  check: 'https://me.meituan.com/ebooking/merchant/comment-manage-react',
  comments: 'https://me.meituan.com/ebooking/merchant/comment-manage-react#/home',
  traffic: 'https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Fdata-center%2Findex.html',
  newTraffic: 'https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html',
  orders: 'https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Forder-eb%2Findex.html%23%2Fcheckin',
};

const args = parseArgs(process.argv.slice(2));
const storeId = String(args.storeId || args.store || args.poiId || '').trim();
if (!storeId) {
  fail('Missing --store-id. Example: node scripts/meituan_browser_capture.mjs --store-id=68471');
}

const storageDir = resolve(args.profileDir || join('storage', `meituan_profile_${safeName(storeId)}`));
const reportDir = resolve(args.reportDir || 'reports');
const assetDir = join(reportDir, 'meituan_capture_assets');
const capturedAt = new Date().toISOString();
const defaultDataDate = String(args.dataDate || '').trim();
const dataPeriod = String(args.dataPeriod || '').trim();
const snapshotTime = String(args.snapshotTime || '').trim() || (dataPeriod === 'realtime_snapshot' ? capturedAt : '');
const outputPath = resolve(args.output || join(reportDir, `meituan_capture_${safeName(storeId)}_${timestamp()}.json`));
const captureSections = normalizeCaptureSections(args.sections || args.captureSections || args.only || 'traffic,orders');
const sessionProbeOnly = booleanArg(args.sessionProbeOnly) || booleanArg(args.session_probe_only);
const loginOnly = !sessionProbeOnly && (booleanArg(args.loginOnly) || booleanArg(args.authOnly) || booleanArg(args.prepareProfile));
const authOnly = sessionProbeOnly || loginOnly;

await mkdir(storageDir, { recursive: true });
await mkdir(reportDir, { recursive: true });
await mkdir(assetDir, { recursive: true });

const payload = {
  store_id: '',
  poi_id: '',
  poi_name: String(args.poiName || ''),
  system_hotel_id: args.systemHotelId ? Number(args.systemHotelId) : null,
  default_data_date: defaultDataDate,
  data_period: dataPeriod,
  snapshot_time: snapshotTime,
  captured_at: capturedAt,
  source: 'meituan_browser_profile',
  mode: sessionProbeOnly ? 'session_probe_only' : (loginOnly ? 'login_only' : 'capture'),
  capture_sections: Array.from(captureSections),
  section_evidence: {},
  pages: [],
  responses: [],
  reviews: [],
  traffic: [],
  flowAnalysis: [],
  order_flow: [],
  peerRank: [],
  searchKeywords: [],
  trafficForecast: [],
  ads: [],
  orders: [],
  screenshots: [],
  cookie_injection: { attempted: false, injected_count: 0, domains: [] },
  auth_status: { ok: false, status: 'pending', message: 'Login status has not been checked.' },
  session_probe: {
    schema_version: 1,
    mode: 'session_probe_only',
    platform: 'meituan',
    status: 'pending',
    collectable: false,
  },
  capture_gate: null,
};
const observedOrderFlowRequestUrls = new Set();
const pendingResponseCaptures = new Set();
const requestQueryEvidence = new WeakMap();
const observedPlatformIdentifiers = new Set();
let activeOrderQueryEvidence = null;
let orderQueryEpoch = 0;
let sessionProbeSuccessfulApiResponseCount = 0;
const sessionProbeResponseDiagnostics = {
  recognized_response_count: 0,
  candidate_drift_response_count: 0,
  access_denied_response_count: 0,
  authentication_required_response_count: 0,
  permission_denied_response_count: 0,
  rate_limited_response_count: 0,
  candidate_route_samples: [],
  candidate_reason_ids: [],
};

const browser = await launchOtaPersistentContext(storageDir, args);
payload.cookie_injection = sessionProbeOnly
  ? { attempted: false, injected_count: 0, domains: [], reason: 'session_probe_only' }
  : await injectBrowserCookies(browser, args, 'meituan');

const page = await browser.newPage();
await bringLoginPageToFront(page);
if (authOnly) {
  registerSessionProbeResponseObserver(page);
} else {
  registerResponseCapture(page, payload);
}

try {
  const loginStatus = await ensureLoggedIn(page, { interactive: !sessionProbeOnly });
  payload.auth_status = loginStatus;
  if (!loginStatus.ok) {
    payload.pages.push({
      name: 'auth',
      url: loginStatus.url || page.url(),
      ok: false,
      auth_status: loginStatus.status,
      error: loginStatus.message,
    });
    process.exitCode = 2;
  } else if (authOnly) {
    if (loginOnly) {
      await holdInteractiveLoginWindow(page, 'Meituan');
    }
  } else {
    if (wantsSection('reviews')) {
      await capturePage(page, 'comments', URLS.comments);
      await collectDomFallback(page, payload, 'reviews');
    }

    if (wantsSection('traffic')) {
      await capturePage(page, 'traffic', URLS.traffic);
      await collectDomFallback(page, payload, 'traffic');

      await capturePage(page, 'newTraffic', URLS.newTraffic);
      await collectDomFallback(page, payload, 'traffic');
    }

    if (wantsSection('order_flow')) {
      await capturePage(page, 'orderFlow', URLS.newTraffic);
    }

    if (args.adsUrl && wantsSection('ads')) {
      await capturePage(page, 'ads', String(args.adsUrl));
      await collectDomFallback(page, payload, 'ads');
    }

    if (wantsSection('orders')) {
      await capturePage(page, 'orders', URLS.orders);
      await collectDomFallback(page, payload, 'orders');
    }
  }

  await waitForPendingResponseCaptures(page);
  const platformIdentityValidation = authOnly
    ? {
        schema_version: 1,
        status: 'not_checked_login_only',
        source_validation: false,
        evidence_source: 'none',
        expected_identifier_count: 0,
        observed_identifier_count: 0,
        matched_identifier_count: 0,
        mismatched_identifier_count: 0,
        validated_identifier: '',
      }
    : evaluateMeituanPlatformIdentity(
        [storeId, String(args.poiId || '')],
        Array.from(observedPlatformIdentifiers),
      );
  payload.platform_identity_validation = platformIdentityValidation;
  if (platformIdentityValidation.status === 'matched') {
    payload.poi_id = platformIdentityValidation.validated_identifier;
    if (!String(args.poiId || '').trim() || platformIdentityValidation.validated_identifier === storeId) {
      payload.store_id = platformIdentityValidation.validated_identifier;
    }
  }
  Object.assign(payload, filterMeituanCumulativeRowsByTargetDate(payload, defaultDataDate));
  if (wantsSection('order_flow')) {
    payload.order_flow = filterMeituanOrderFlowRowsByPeriod(payload.order_flow, dataPeriod);
  }
  Object.assign(payload, filterMeituanEventRowsByTargetDate(payload, defaultDataDate, captureSections));
  dedupePayloadRows(payload);
  if (authOnly) {
    payload.session_probe = await buildLoginOnlySessionProbe(platformIdentityValidation);
    if (payload.session_probe.collectable === true && payload.auth_status?.ok !== true) {
      payload.auth_status = {
        ...payload.auth_status,
        ok: true,
        status: 'authorized',
        message: 'Protected business API and reusable Session state are verified.',
      };
    } else if (payload.session_probe.status === 'anti_bot') {
      payload.auth_status = {
        ...payload.auth_status,
        ok: false,
        status: 'anti_bot',
        message: payload.session_probe.message,
        retry_after_seconds: payload.session_probe.retry_after_seconds,
        next_retry_at: payload.session_probe.next_retry_at,
      };
    }
  }
  const sectionGate = authOnly
    ? {
        status: payload.session_probe.collectable === true ? 'pass' : 'fail',
        failed_check_ids: payload.session_probe.collectable === true ? [] : payload.session_probe.failed_check_ids,
        mode: payload.mode,
        reason: 'session_probe_only',
        retry_after_seconds: payload.session_probe.retry_after_seconds,
        next_retry_at: payload.session_probe.next_retry_at,
        checks: [{
          id: 'session_collectability',
          status: payload.session_probe.collectable === true ? 'pass' : 'fail',
          message: payload.session_probe.message,
        }],
      }
    : evaluateMeituanCaptureGate(payload, captureSections, { targetDate: defaultDataDate });
  payload.capture_gate = !authOnly && platformIdentityValidation.status !== 'matched'
    ? {
        ...sectionGate,
        status: 'fail',
        failed_check_ids: Array.from(new Set([
          ...(Array.isArray(sectionGate.failed_check_ids) ? sectionGate.failed_check_ids : []),
          `platform_hotel_identity_${platformIdentityValidation.status}`,
        ])),
        platform_identity_status: platformIdentityValidation.status,
      }
    : sectionGate;
  if (payload.capture_gate.status !== 'pass') {
    process.exitCode = 2;
  } else if (authOnly) {
    process.exitCode = 0;
  }
  await writeFile(outputPath, JSON.stringify(payload, null, 2), 'utf8');

  if (!authOnly && args.submit === 'true') {
    await submitPayload(payload);
  }

  console.log(JSON.stringify({
    output: outputPath,
    profile_dir: storageDir,
    auth_status: payload.auth_status,
    session_probe: payload.session_probe,
    counts: summarize(payload),
  }, null, 2));
} finally {
  await browser.close();
}

async function ensureLoggedIn(page, options = {}) {
  await page.goto(URLS.check, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
  await bringLoginPageToFront(page);
  await page.waitForTimeout(2000);
  if (await looksLoggedIn(page)) {
    return { ok: true, status: 'logged_in', url: sanitizeObservedPageUrl(page.url()), message: 'Meituan profile is logged in.' };
  }

  if (options.interactive === false) {
    await page.goto(URLS.login, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => null);
    await page.waitForTimeout(3000);
    return (await looksLoggedIn(page))
      ? { ok: true, status: 'logged_in', url: sanitizeObservedPageUrl(page.url()), message: 'Meituan profile is logged in.' }
      : {
          ok: false,
          status: 'login_required',
          url: sanitizeObservedPageUrl(page.url()),
          message: 'Meituan existing Profile session is not ready for collection.',
        };
  }

  if (!(await looksLoggedIn(page))) {
    if (isHeadlessMode()) {
      // The comment-management route can render an empty SPA shell in
      // headless mode even when the persisted merchant session is valid.
      // Re-check through the neutral eBooking entry, which redirects an
      // authenticated Profile to the merchant home page, before declaring
      // that human login is required.
      await page.goto(URLS.login, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
      await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => null);
      await page.waitForTimeout(3000);
      if (await looksLoggedIn(page)) {
        return { ok: true, status: 'logged_in', url: sanitizeObservedPageUrl(page.url()), message: 'Meituan profile is logged in.' };
      }
      return {
        ok: false,
        status: 'login_required',
        url: sanitizeObservedPageUrl(page.url()),
        message: 'Meituan login session is not ready. Re-login with a visible browser Profile before scheduled sync.',
      };
    }

    await page.goto(URLS.login, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
    await bringLoginPageToFront(page);
    console.log(`Open login page and complete Meituan login. Profile will be saved at ${storageDir}`);
    if (args.loginMode === 'manual') {
      await waitForEnter('Press Enter after login succeeds...');
      await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
      return (await looksLoggedIn(page))
        ? { ok: true, status: 'logged_in', url: sanitizeObservedPageUrl(page.url()), message: 'Meituan profile is logged in.' }
        : { ok: false, status: 'login_required', url: sanitizeObservedPageUrl(page.url()), message: 'Meituan login was not completed.' };
    }

    const timeoutMs = Number(args.loginTimeoutMs || 300000);
    const deadline = Date.now() + Math.max(timeoutMs, 30000);
    while (Date.now() < deadline) {
      await page.waitForTimeout(3000);
      if (await looksLoggedIn(page)) {
        return { ok: true, status: 'logged_in', url: sanitizeObservedPageUrl(page.url()), message: 'Meituan profile is logged in.' };
      }
    }
    return {
      ok: false,
      status: 'login_required',
      url: sanitizeObservedPageUrl(page.url()),
      timeout_ms: timeoutMs,
      message: `Meituan login timeout after ${Math.round(timeoutMs / 1000)} seconds`,
    };
  }

  return { ok: true, status: 'logged_in', url: sanitizeObservedPageUrl(page.url()), message: 'Meituan profile is logged in.' };
}

async function bringLoginPageToFront(page) {
  if (typeof page.bringToFront === 'function') {
    await page.bringToFront().catch(() => null);
  }
}

async function holdInteractiveLoginWindow(page, platformName) {
  const waitMs = Math.max(0, Math.min(600000, numberValue(
    args.postLoginWaitMs || args.keepOpenMs || args.interactiveHoldMs,
    0,
  )));
  const enabled = booleanArg(args.interactiveLogin) || waitMs > 0;
  if (!enabled) {
    return;
  }
  const effectiveWaitMs = waitMs > 0 ? waitMs : 120000;
  console.log(`${platformName} login session is ready. Keeping browser open for ${Math.round(effectiveWaitMs / 1000)} seconds.`);
  const deadline = Date.now() + effectiveWaitMs;
  while (Date.now() < deadline) {
    if (typeof page.isClosed === 'function' && page.isClosed()) {
      return;
    }
    await page.waitForTimeout(Math.min(3000, Math.max(250, deadline - Date.now()))).catch(() => null);
  }
}

async function buildLoginOnlySessionProbe(platformIdentityValidation) {
  const pageText = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  const cookies = await browser.cookies().catch(() => []);
  const cookieSummary = summarizeOtaSessionCookies('meituan', cookies);
  return evaluateOtaSessionProbe('meituan', {
    auth_status: payload.auth_status,
    url: page.url(),
    page_text: pageText,
    cookie_summary: cookieSummary,
    successful_api_response_count: sessionProbeSuccessfulApiResponseCount,
    response_diagnostics: sessionProbeResponseDiagnostics,
    identity_status: platformIdentityValidation?.status || 'not_checked',
  });
}

async function looksLoggedIn(page) {
  const url = page.url();
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  if (/login|passport|account/i.test(url)) {
    return false;
  }
  const isMerchantPage = /me\.meituan\.com\/ebooking\/merchant/i.test(url);
  const isNewHbPage = /eb\.meituan\.com\/newhb-sub-app/i.test(url);
  const isAdsPage = /ebmidas\.dianping\.com/i.test(url);
  if (!isMerchantPage && !isNewHbPage && !isAdsPage) {
    return false;
  }
  if (/欢迎使用美团\s*ebooking|商家手机端|美团员工登录/.test(text)) {
    return false;
  }
  if (/登录|验证码|扫码/.test(text) && !/订单|点评|商家|经营|数据|流量|评价|入住|工作台/.test(text)) {
    return false;
  }
  return /订单|点评|商家|经营|数据|流量|评价|入住|工作台|曝光|浏览/.test(text);
}

async function capturePage(page, name, url) {
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 });
  } catch (error) {
    payload.pages.push({ name, url, ok: false, error: error.message });
    return;
  }
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => null);
  await page.waitForTimeout(3000);
  if (!(await looksLoggedIn(page))) {
    payload.auth_status = {
      ok: false,
      status: 'login_required',
      url: page.url(),
      message: 'Meituan page redirected to login during capture.',
    };
    payload.pages.push({ name, url: page.url(), ok: false, error: payload.auth_status.message });
    return;
  }
  await dismissMeituanOverlays(page);
  const interactions = name === 'traffic' || name === 'newTraffic'
    ? await runMeituanTrafficInteractionPlan(page)
    : name === 'orderFlow'
      ? await runMeituanOrderFlowInteractionPlan(page)
    : name === 'orders'
      ? await runMeituanOrderInteractionPlan(page)
      : name === 'comments'
        ? await runMeituanReviewInteractionPlan(page)
        : [];
  const sectionEvidence = name === 'ads' ? await detectMeituanAdsSectionEvidence(page) : null;
  if (sectionEvidence) {
    payload.section_evidence.ads = sectionEvidence;
  }
  const screenshot = join(assetDir, `${safeName(storeId)}_${name}_${timestamp()}.png`);
  await page.screenshot({ path: screenshot, fullPage: true }).catch(() => null);
  if (existsSync(screenshot)) {
    payload.screenshots.push({ name, path: screenshot });
  }
  payload.pages.push({
    name,
    url: page.url(),
    ok: true,
    interactions,
    ...(sectionEvidence ? { section_evidence: sectionEvidence } : {}),
  });
}

async function detectMeituanAdsSectionEvidence(page) {
  const url = String(page.url() || '');
  if (!/\/online-sign(?:\.html)?(?:[?#]|$)/i.test(url)) {
    return null;
  }
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  const onboardingMarker = /\u7acb\u5373\u5f00\u542f\u63a8\u5e7f|\u63a8\u5e7f\u6280\u672f\u670d\u52a1\u534f\u8bae|\u6211\u5df2\u9605\u8bfb\u5e76\u540c\u610f/.test(text);
  if (!onboardingMarker) {
    return null;
  }
  return {
    status: 'not_applicable',
    reason: 'ads_not_enabled',
    evidence_source: 'page.dom',
    marker: 'meituan_ads_onboarding',
  };
}

async function runMeituanTrafficInteractionPlan(page) {
  const results = [];

  await clickMeituanTrafficStep(page, results, '\u540c\u884c\u5206\u6790', 'open peer ranking tab');
  for (const period of ['\u4eca\u65e5\u5b9e\u65f6', '\u6628\u65e5', '\u8fd17\u5929', '\u8fd130\u5929']) {
    await clickMeituanTrafficStep(page, results, period, `select peer period ${period}`);
    for (const tab of ['\u5165\u4f4f\u699c', '\u9500\u552e\u699c', '\u6d41\u91cf\u699c', '\u8f6c\u5316\u699c']) {
      await clickMeituanTrafficStep(page, results, tab, `select peer rank ${tab}`);
    }
  }

  await clickMeituanTrafficStep(page, results, '\u6d41\u91cf\u5206\u6790', 'open traffic analysis tab');
  for (const period of ['\u4eca\u65e5\u5b9e\u65f6', '\u6628\u65e5', '\u8fd17\u5929', '\u8fd130\u5929']) {
    await clickMeituanTrafficStep(page, results, period, `select traffic period ${period}`, 1800);
  }
  for (const tab of ['\u8be6\u60c5\u9875\u6d4f\u89c8\u4eba\u6570\uff08PV\uff09', '\u8be6\u60c5\u9875\u6d4f\u89c8\u4eba\u6570\uff08UV\uff09', '\u63d0\u524d\u8ba2\u8ba2\u5355\u91cf']) {
    await clickMeituanTrafficStep(page, results, tab, `select traffic forecast ${tab}`, 1500);
  }

  return results;
}

async function runMeituanOrderFlowInteractionPlan(page) {
  const results = [];
  await clickMeituanTrafficStep(page, results, '\u540c\u884c\u5206\u6790', 'open peer analysis tab', 1500);
  await clickMeituanTrafficStep(page, results, '\u8ba2\u5355\u6d41\u5931', 'open order flow panel', 1800);
  const periodLabel = {
    yesterday: '\u6628\u65e5',
    last_7_days: '\u8fd17\u5929',
    last_30_days: '\u8fd130\u5929',
  }[String(dataPeriod || '').trim().toLowerCase()] || '\u6628\u65e5';
  await clickMeituanTrafficStep(page, results, periodLabel, `select order flow period ${periodLabel}`, 2200);
  results.push(...await replayMeituanOrderFlowDirections(page));
  return results;
}

async function replayMeituanOrderFlowDirections(page) {
  const sourceUrl = Array.from(observedOrderFlowRequestUrls).reverse()
    .find(value => buildMeituanOrderFlowReplayUrls(value).length === 2);
  if (!sourceUrl) {
    return [{ action: 'replay order flow directions', ok: false, reason: 'verified_order_flow_request_not_observed' }];
  }

  const results = [];
  for (const [index, targetUrl] of buildMeituanOrderFlowReplayUrls(sourceUrl).entries()) {
    try {
      const response = await page.evaluate(async url => {
        const result = await fetch(url, { credentials: 'include', headers: { Accept: 'application/json' } });
        await result.text();
        return { ok: result.ok, status: result.status };
      }, targetUrl);
      results.push({
        action: 'replay order flow direction',
        direction: index === 0 ? 'loss' : 'inflow',
        ok: response?.ok === true,
        status: Number(response?.status || 0),
      });
    } catch (error) {
      results.push({
        action: 'replay order flow direction',
        direction: index === 0 ? 'loss' : 'inflow',
        ok: false,
        error: String(error?.message || error || 'request_failed'),
      });
    }
  }
  await page.waitForTimeout(600);
  return results;
}

async function runMeituanOrderInteractionPlan(page) {
  const results = [];
  const frame = page.frames().find(item => /\/order-eb\//i.test(item.url()));
  if (!frame) {
    return [{ action: 'open_all_orders', clicked: false, skipped: 'order_frame_not_found' }];
  }

  const allOrders = frame.getByText('\u5168\u90e8\u8ba2\u5355', { exact: true }).first();
  const allOrdersVisible = await allOrders.isVisible({ timeout: 2000 }).catch(() => false);
  if (allOrdersVisible) {
    await allOrders.click().catch(() => null);
    await frame.waitForTimeout(1500);
  }
  results.push({ action: 'open_all_orders', clicked: allOrdersVisible });

  if (!/^\d{4}-\d{2}-\d{2}$/.test(defaultDataDate)) {
    results.push({ action: 'set_purchase_date', changed: false, skipped: 'target_date_missing' });
    return results;
  }

  const dateInput = frame.locator('input[placeholder="\u8bf7\u9009\u62e9\u8d2d\u4e70\u65e5\u671f"]').first();
  const dateVisible = await dateInput.isVisible({ timeout: 3000 }).catch(() => false);
  if (!dateVisible) {
    results.push({ action: 'set_purchase_date', changed: false, skipped: 'purchase_date_input_not_found' });
    return results;
  }
  await dateInput.fill(`${defaultDataDate} - ${defaultDataDate}`);
  await dateInput.press('Tab').catch(() => null);
  const selectedDateValue = await dateInput.inputValue().catch(() => '');
  const selectedDates = String(selectedDateValue || '').match(/\d{4}-\d{2}-\d{2}/g) || [];
  const targetDateApplied = selectedDates.length >= 2
    && selectedDates.slice(0, 2).every(value => value === defaultDataDate);
  if (!targetDateApplied) {
    results.push({
      action: 'set_purchase_date',
      changed: false,
      target_date: defaultDataDate,
      skipped: 'purchase_date_value_not_applied',
    });
    return results;
  }
  results.push({ action: 'set_purchase_date', changed: true, target_date: defaultDataDate, verified_by: 'input_value_readback' });

  const query = frame.getByRole('button', { name: '\u67e5\u8be2', exact: true }).first();
  const queryVisible = await query.isVisible({ timeout: 2000 }).catch(() => false);
  let queryClicked = false;
  if (queryVisible) {
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => null);
    await waitForPendingResponseCaptures(page);
    const queryEpoch = ++orderQueryEpoch;
    activeOrderQueryEvidence = {
      query_epoch: queryEpoch,
      query_target_date: defaultDataDate,
      query_date_source: 'page.orders.purchase_date_input.readback',
    };
    payload.responses = payload.responses.filter(item => String(item?.section || '').trim().toLowerCase() !== 'orders');
    payload.orders = [];
    try {
      await query.click();
      queryClicked = true;
      await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => null);
      await frame.waitForTimeout(1800);
      await waitForPendingResponseCaptures(page);
    } finally {
      activeOrderQueryEvidence = null;
    }
    if (queryClicked) {
      payload.section_evidence.orders = {
        status: 'target_date_queried',
        target_date: defaultDataDate,
        evidence_source: 'page.form_readback',
        marker: 'meituan_orders_purchase_date_query',
        query_epoch: queryEpoch,
        response_count: payload.responses.filter(item => (
          String(item?.section || '').trim().toLowerCase() === 'orders'
          && Number(item?.query_epoch || 0) === queryEpoch
        )).length,
      };
    }
  }
  results.push({ action: 'query_target_date_orders', clicked: queryClicked, target_date: defaultDataDate });
  return results;
}

async function runMeituanReviewInteractionPlan(page) {
  const results = [];
  if (!/^\d{4}-\d{2}-\d{2}$/.test(defaultDataDate)) {
    return [{ action: 'set_review_date', changed: false, skipped: 'target_date_missing' }];
  }

  const startInput = page.locator('input[placeholder="\u5f00\u59cb\u65e5\u671f"]').first();
  const startVisible = await startInput.isVisible({ timeout: 3000 }).catch(() => false);
  if (!startVisible) {
    return [{ action: 'set_review_date', changed: false, skipped: 'review_date_input_not_found' }];
  }
  await startInput.click();
  await page.waitForTimeout(500);

  const [targetYear, targetMonth, targetDay] = defaultDataDate.split('-').map(Number);
  let targetCalendar = null;
  for (let attempt = 0; attempt < 24; attempt += 1) {
    const calendars = page.locator('.mtd-prime-date-calendar:visible');
    const count = await calendars.count();
    const visibleMonths = [];
    for (let index = 0; index < count; index += 1) {
      const calendar = calendars.nth(index);
      const header = await calendar.locator('.mtd-prime-date-calendar-header').innerText().catch(() => '');
      const match = header.match(/(\d{4})\s*\u5e74\s*(\d{1,2})\s*\u6708/);
      if (!match) continue;
      const year = Number(match[1]);
      const month = Number(match[2]);
      visibleMonths.push({ year, month, calendar });
      if (year === targetYear && month === targetMonth) {
        targetCalendar = calendar;
        break;
      }
    }
    if (targetCalendar) break;
    if (visibleMonths.length === 0) break;

    const first = visibleMonths[0];
    const firstIndex = first.year * 12 + first.month;
    const targetIndex = targetYear * 12 + targetMonth;
    const switcher = targetIndex < firstIndex
      ? page.locator('.mtd-prime-date-calendar:visible .left-switcher').first()
      : page.locator('.mtd-prime-date-calendar:visible .right-switcher').last();
    if (!(await switcher.isVisible().catch(() => false))) break;
    await switcher.click();
    await page.waitForTimeout(250);
  }

  if (!targetCalendar) {
    results.push({ action: 'set_review_date', changed: false, skipped: 'target_month_not_selectable' });
    return results;
  }
  const day = targetCalendar
    .locator('.mtd-prime-date-panel-data-wrapper:not(.disabled-date) .mtd-prime-date-cell-text')
    .filter({ hasText: new RegExp(`^${targetDay}$`) })
    .first();
  if (!(await day.isVisible({ timeout: 2000 }).catch(() => false))) {
    results.push({ action: 'set_review_date', changed: false, skipped: 'target_day_not_selectable' });
    return results;
  }
  await day.click();
  await day.click();
  const confirm = page.getByText('\u786e\u8ba4', { exact: true }).last();
  await confirm.click();
  results.push({ action: 'set_review_date', changed: true, target_date: defaultDataDate });

  const query = page.getByText('\u67e5\u8be2', { exact: true }).last();
  const queryVisible = await query.isVisible({ timeout: 2000 }).catch(() => false);
  if (queryVisible) {
    await query.click();
    await page.waitForLoadState('networkidle', { timeout: 12000 }).catch(() => null);
    await page.waitForTimeout(1600);
  }
  results.push({ action: 'query_target_date_reviews', clicked: queryVisible, target_date: defaultDataDate });
  return results;
}

async function clickMeituanTrafficStep(page, results, text, reason, waitMs = 1200) {
  await dismissMeituanOverlays(page);
  const result = await clickMeituanTextIfVisible(page, text);
  results.push({
    action: 'click_text',
    text,
    reason,
    clicked: result.clicked,
    skipped: result.skipped || '',
    ...(result.error ? { error: result.error } : {}),
  });
  if (result.clicked) {
    await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => null);
    await page.waitForTimeout(waitMs);
  }
}

async function clickMeituanTextIfVisible(page, text) {
  const locators = [
    page.getByText(text, { exact: true }).first(),
    page.getByRole('tab', { name: text, exact: true }).first(),
    page.getByRole('button', { name: text, exact: true }).first(),
    page.getByRole('link', { name: text, exact: true }).first(),
    page.locator('a,button,label,li,[role="tab"],[role="button"],[class*="tab"],[class*="Tab"],[class*="menu"],[class*="Menu"]').filter({ hasText: text }).first(),
    page.locator('span').filter({ hasText: text }).first(),
  ];
  let lastError = '';
  for (const locator of locators) {
    const visible = await locator.isVisible({ timeout: 500 }).catch(() => false);
    if (!visible) {
      continue;
    }
    try {
      await locator.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => null);
      await locator.click({ timeout: 2000 });
      return { clicked: true };
    } catch (error) {
      lastError = error.message;
      try {
        await locator.click({ timeout: 1500, force: true });
        return { clicked: true, forced: true };
      } catch (forceError) {
        lastError = forceError.message;
      }
      try {
        await locator.evaluate((element) => {
          const target = element.closest('a,button,label,li,[role="tab"],[role="button"],[class*="tab"],[class*="Tab"],[class*="menu"],[class*="Menu"]') || element;
          target.click();
        });
        return { clicked: true, evaluated: true };
      } catch (evaluateError) {
        lastError = evaluateError.message;
      }
    }
  }
  return lastError ? { clicked: false, error: lastError } : { clicked: false, skipped: 'not_visible' };
}

async function dismissMeituanOverlays(page) {
  await page.keyboard.press('Escape').catch(() => null);
  const targets = [
    page.locator('.ant-modal-close, .ant-drawer-close, [class*="modal"] [class*="close"], [class*="Modal"] [class*="close"], [aria-label="Close"], [aria-label="close"]').first(),
    page.getByRole('button', { name: '\u5173\u95ed', exact: true }).first(),
    page.getByRole('button', { name: '\u77e5\u9053\u4e86', exact: true }).first(),
  ];
  for (const target of targets) {
    const visible = await target.isVisible({ timeout: 350 }).catch(() => false);
    if (!visible) {
      continue;
    }
    await target.click({ timeout: 1200, force: true }).catch(() => null);
    await page.waitForTimeout(350);
  }
}

function registerResponseCapture(page, target) {
  page.on('request', request => {
    if (activeOrderQueryEvidence) {
      requestQueryEvidence.set(request, { ...activeOrderQueryEvidence });
    }
  });
  page.on('response', response => {
    const task = captureMeituanResponse(response, target);
    pendingResponseCaptures.add(task);
    void task.finally(() => pendingResponseCaptures.delete(task)).catch(() => null);
  });
}

function registerSessionProbeResponseObserver(page) {
  page.on('response', response => {
    const requestType = response.request().resourceType();
    const status = Number(response.status() || 0);
    const contentType = response.headers()['content-type'] || '';
    const classified = classifyOtaSessionProbeResponse('meituan', {
      url: response.url(),
      status,
      resource_type: requestType,
      content_type: contentType,
    });
    const classification = classified.classification;
    if (classification === 'recognized') {
      sessionProbeSuccessfulApiResponseCount = Math.min(20, sessionProbeSuccessfulApiResponseCount + 1);
      sessionProbeResponseDiagnostics.recognized_response_count = sessionProbeSuccessfulApiResponseCount;
    } else if (classification === 'candidate_drift') {
      recordOtaSessionProbeCandidateDiagnostic(sessionProbeResponseDiagnostics, classified, response.url());
    } else if (classification === 'authentication_required') {
      sessionProbeResponseDiagnostics.authentication_required_response_count = Math.min(20, sessionProbeResponseDiagnostics.authentication_required_response_count + 1);
      sessionProbeResponseDiagnostics.access_denied_response_count = Math.min(20, sessionProbeResponseDiagnostics.access_denied_response_count + 1);
    } else if (classification === 'permission_denied') {
      sessionProbeResponseDiagnostics.permission_denied_response_count = Math.min(20, sessionProbeResponseDiagnostics.permission_denied_response_count + 1);
    } else if (classification === 'rate_limited') {
      sessionProbeResponseDiagnostics.rate_limited_response_count = Math.min(20, sessionProbeResponseDiagnostics.rate_limited_response_count + 1);
    }
  });
}

function sanitizeObservedPageUrl(value) {
  return sanitizeOtaObservedUrl(value);
}

async function captureMeituanResponse(response, target) {
    const url = response.url();
    const request = response.request();
    const queryEvidence = requestQueryEvidence.get(request) || null;
    const requestPayload = request?.postData?.() || '';
    const requestDateEvidence = extractOtaRequestDateEvidence({ url, payload: requestPayload });
    const contentType = response.headers()['content-type'] || '';
    const classified = classifyStandardOtaResponse('meituan', url, {
      status: response.status(),
      resourceType: response.request().resourceType(),
      contentType,
    });
    const section = classified.capture ? classified.section : '';
    if (!section || !wantsSection(section)) {
      return;
    }
    if (section === 'order_flow') {
      observedOrderFlowRequestUrls.add(url);
    }

    const status = response.status();
    let body = null;
    try {
      const text = await response.text();
      body = parseResponseBody(text, contentType);
    } catch (error) {
      const responseEvidence = buildOtaCaptureEvidence('meituan', { url, section, captureSource: `xhr:${section}` });
      target.responses.push({
        url_hash: responseEvidence.source_url_hash || '',
        source_trace_id: responseEvidence.source_trace_id || '',
        section,
        status,
        error: error.message,
        ...(queryEvidence || {}),
      });
      return;
    }

    const safeBody = sanitizeOtaPayloadForStorage(body, section);
    const supplementalMeta = meituanSupplementalResponseMeta(url, requestDateEvidence);
    const targetPayloadKey = meituanPayloadKeyForResponse(url, safeBody, section);
    for (const identifier of extractMeituanRequestPlatformIdentifiers(url, requestPayload)) {
      observedPlatformIdentifiers.add(identifier);
    }
    if (isMeituanOwnHotelPayloadKey(targetPayloadKey)) {
      for (const identifier of collectMeituanPlatformIdentifiers(safeBody)) {
        observedPlatformIdentifiers.add(identifier);
      }
    }
    const normalizedRows = normalizeCapturedList(safeBody, section, '', requestDateEvidence);
    const rows = meituanRowsForPayloadKey(targetPayloadKey, safeBody, normalizedRows, supplementalMeta);
    const responseEvidence = buildOtaCaptureEvidence('meituan', { url, section, captureSource: `xhr:${section}` });
    target.responses.push({
      url_hash: responseEvidence.source_url_hash || '',
      source_trace_id: responseEvidence.source_trace_id || '',
      section,
      payload_key: targetPayloadKey,
      status,
      row_count: rows.length,
      request_date_source: requestDateEvidence.date_source || '',
      date_range: supplementalMeta.dateRange,
      rank_type: supplementalMeta.rankType,
      forecast_type: supplementalMeta.forecastType,
      data: safeBody,
      ...(queryEvidence || {}),
    });
    if (!Array.isArray(target[targetPayloadKey])) {
      target[targetPayloadKey] = [];
    }
    target[targetPayloadKey].push(...rows.map(row => {
      row = withMeituanPlatformIdentifier(row);
      return attachOtaCaptureEvidence(row, 'meituan', {
        url,
        section,
        captureSource: row._capture_source || `xhr:${section}`,
      });
    }));
}

async function waitForPendingResponseCaptures(page) {
  let idleRounds = 0;
  for (let round = 0; round < 10; round += 1) {
    await page.waitForTimeout(100).catch(() => null);
    const pending = Array.from(pendingResponseCaptures);
    if (pending.length === 0) {
      idleRounds += 1;
      if (idleRounds >= 2) return;
      continue;
    }
    idleRounds = 0;
    await Promise.allSettled(pending);
  }
}

function normalizeCaptureSections(value) {
  return new Set(normalizeStandardCaptureSections('meituan', value));
}

function wantsSection(section) {
  return captureSections.has(section);
}

function withMeituanPlatformIdentifier(row) {
  // Preserve only identifiers actually present in the OTA response. The
  // configured Profile key is routing context, not source identity evidence.
  return { ...(row || {}) };
}

function meituanRowsForPayloadKey(payloadKey, safeBody, normalizedRows, meta) {
  if (payloadKey === 'peerRank') {
    return normalizeMeituanPeerRankRows(safeBody, meta);
  }
  if (payloadKey === 'searchKeywords') {
    return normalizeMeituanSearchKeywordRows(safeBody, meta);
  }
  if (payloadKey === 'trafficForecast') {
    return normalizeMeituanTrafficForecastRows(safeBody, meta);
  }
  if (payloadKey === 'flowAnalysis') {
    return normalizeMeituanFlowAnalysisRows(safeBody, meta);
  }
  if (payloadKey === 'order_flow') {
    return normalizeMeituanOrderFlowRows(safeBody, meta);
  }
  if (payloadKey === 'traffic') {
    return normalizedRows.filter(row => isImportableMeituanTrafficRow(row));
  }
  if (payloadKey === 'orders') {
    return normalizedRows.filter(isImportableMeituanOrderCaptureRow);
  }
  if (payloadKey === 'reviews') {
    return normalizedRows.filter(isImportableMeituanReviewCaptureRow);
  }
  return normalizedRows;
}

function isImportableMeituanReviewCaptureRow(row) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) return false;
  const keys = [
    'commentCount', 'comment_count', 'reviewCount', 'review_count', 'totalCommentCount', 'totalCount',
    'commentScore', 'comment_score', 'reviewScore', 'review_score', 'rating', 'score',
    'badReviewCount', 'bad_review_count', 'negativeCommentCount', 'negativeCount',
  ];
  return keys.some(key => Object.prototype.hasOwnProperty.call(row, key) && String(row[key] ?? '').trim() !== '');
}

function isImportableMeituanOrderCaptureRow(row) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) return false;
  const keys = [
    'order_id_hash', 'order_no_hash', 'booking_id_hash',
    'orderId', 'order_id', 'orderNo', 'order_no', 'bookingId', 'booking_id',
    'orders', 'order_count', 'orderCount', 'book_order_num', 'bookOrderNum', 'room_nights', 'roomNights',
  ];
  return keys.some(key => Object.prototype.hasOwnProperty.call(row, key) && String(row[key] ?? '').trim() !== '');
}

function meituanPayloadKeyForResponse(url, body, section) {
  if (section === 'order_flow') {
    return 'order_flow';
  }
  if (section !== 'traffic') {
    return section;
  }
  const value = String(url || '').toLowerCase();
  if (value.includes('/business/peer/rank/data/detail') || bodyHasPath(body, ['data', 'peerRankData']) || bodyHasPath(body, ['peerRankData'])) {
    return 'peerRank';
  }
  if (value.includes('flowconversion') || value.includes('flowtrenddetail') || value.includes('flowtrend')) {
    return 'flowAnalysis';
  }
  if (value.includes('searchkeywords') || bodyHasSearchKeywordCards(body) || bodyHasPath(body, ['data', 'searchKeywords']) || bodyHasPath(body, ['data', 'searchKeyWords'])) {
    return 'searchKeywords';
  }
  if (value.includes('flowforecast')) {
    return 'trafficForecast';
  }
  return 'traffic';
}

function meituanSupplementalResponseMeta(url, requestDateEvidence = {}) {
  const query = urlQueryParams(url);
  return {
    requestDateEvidence,
    defaultDataDate,
    capturedAt,
    dateRange: query.get('dateRange') || '',
    rankType: query.get('rankType') || '',
    forecastType: query.get('type') || '',
    analysisType: meituanFlowAnalysisType(url),
    orderFlowDirection: query.get('lossType') === '1' ? 'inflow' : (query.get('lossType') === '0' ? 'loss' : ''),
    periodStart: query.get('startDate') || '',
    periodEnd: query.get('endDate') || '',
  };
}

function filterMeituanOrderFlowRowsByPeriod(rows, period) {
  const source = Array.isArray(rows) ? rows : [];
  const normalized = String(period || '').trim().toLowerCase();
  if (!['yesterday', 'last_7_days', 'last_30_days'].includes(normalized)) {
    return source;
  }
  return source.filter(row => String(row?.order_flow_period || '').trim().toLowerCase() === normalized);
}

function meituanFlowAnalysisType(url) {
  const value = String(url || '').toLowerCase();
  if (value.includes('flowconversion')) {
    return 'conversion';
  }
  if (value.includes('flowtrenddetail')) {
    return 'source';
  }
  if (value.includes('flowtrend')) {
    return 'trend';
  }
  return '';
}

function urlQueryParams(url) {
  try {
    return new URL(String(url || ''), 'https://eb.meituan.com').searchParams;
  } catch {
    return new URLSearchParams();
  }
}

function bodyHasPath(value, path) {
  return readPath(value, path) !== undefined;
}

function bodyHasSearchKeywordCards(value) {
  const cards = readPath(value, ['data', 'cards']) || readPath(value, ['data', 'data', 'cards']) || readPath(value, ['cards']);
  return Array.isArray(cards) && cards.some(card => {
    if (!card || typeof card !== 'object' || Array.isArray(card)) {
      return false;
    }
    return Array.isArray(card.itemList || card.items || card.keywords);
  });
}

function parseResponseBody(text, contentType) {
  const trimmed = String(text || '').trim();
  if (contentType.includes('json') || trimmed.startsWith('{') || trimmed.startsWith('[')) {
    try {
      return JSON.parse(trimmed);
    } catch {
      return { _raw_text: trimmed.slice(0, 2000) };
    }
  }
  return { _raw_text: trimmed.slice(0, 2000) };
}

function normalizeCapturedList(value, section, sourcePath = '', requestDateEvidence = {}) {
  if (!value || typeof value !== 'object') {
    return [];
  }
  if (Array.isArray(value)) {
    return value
      .filter(item => item && typeof item === 'object')
      .map((item, index) => decorateCapturedRow(item, sourcePath ? `${sourcePath}.${index}` : String(index), section, requestDateEvidence));
  }
  if (section === 'traffic') {
    const cardRows = normalizeMeituanTrafficCardRows(value, {
      sourcePath,
      requestDateEvidence,
      defaultDataDate,
    });
    if (cardRows.length) {
      return cardRows;
    }
  }
  if (section === 'orders') {
    for (const path of [['data', 'results'], ['data', 'orders'], ['data', 'orderList'], ['results'], ['orders'], ['orderList']]) {
      const nested = readPath(value, path);
      if (Array.isArray(nested)) {
        return normalizeCapturedList(nested, section, joinSourcePath(sourcePath, path), requestDateEvidence);
      }
    }
  }

  const paths = {
    reviews: [
      ['data', 'commentList'], ['data', 'comments'], ['data', 'list'], ['commentList'], ['comments'], ['list'], ['data'],
    ],
    traffic: [
      ['data', 'businessData'], ['data', 'peerRank'], ['data', 'peer_rank'], ['data', 'rankings'], ['data', 'weightTraffic'], ['data', 'weight_traffic'], ['data', 'traffic'], ['data', 'peerTrends'],
      ['data', 'searchKeywords'], ['data', 'search_keywords'], ['data', 'keywords'], ['data', 'roomTypes'], ['data', 'room_types'], ['data', 'products'], ['data', 'list'], ['data', 'rows'],
      ['businessData'], ['peerRank'], ['peer_rank'], ['rankings'], ['weightTraffic'], ['weight_traffic'], ['traffic'], ['peerTrends'], ['searchKeywords'], ['search_keywords'], ['keywords'],
      ['roomTypes'], ['room_types'], ['products'], ['list'], ['rows'], ['data'],
    ],
    ads: [
      ['data', 'cureShops'], ['data', 'list'], ['data', 'rows'], ['cureShops'], ['list'], ['rows'], ['data'],
    ],
    orders: [
      ['data', 'results'], ['data', 'orders'], ['data', 'list'], ['data', 'orderList'], ['results'], ['orders'], ['orderList'], ['list'], ['data'],
    ],
  }[section] || [['data'], ['list']];

  for (const path of paths) {
    const nested = readPath(value, path);
    const rows = normalizeCapturedList(nested, section, joinSourcePath(sourcePath, path), requestDateEvidence);
    if (rows.length) {
      return rows;
    }
  }
  return [decorateCapturedRow(value, sourcePath || '$', section, requestDateEvidence)];
}

function joinSourcePath(prefix, parts) {
  const suffix = parts.map(part => String(part)).join('.');
  return prefix ? `${prefix}.${suffix}` : suffix;
}

function decorateCapturedRow(row, sourcePath, section = '', requestDateEvidence = {}) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) {
    return row;
  }
  const rowHasDate = [row.date, row.dataDate, row.statDate, row.stat_date, row.data_date, row.reportDate, row.day]
    .some(value => String(value ?? '').trim() !== '');
  let datePatch = {};
  if (section === 'traffic' || section === 'ads') {
    if (rowHasDate) {
      datePatch = row.date_source || row.dateSource ? {} : { date_source: 'row' };
    } else if (requestDateEvidence.date) {
      datePatch = { dataDate: requestDateEvidence.date, date_source: requestDateEvidence.date_source || 'request' };
    } else if (defaultDataDate) {
      datePatch = { dataDate: defaultDataDate, date_source: 'capture_context.default_data_date' };
    }
  } else if (section === 'orders' || section === 'reviews') {
    const eventDate = meituanEventDateEvidence(row, section);
    if (eventDate.date) {
      datePatch = { dataDate: eventDate.date, date_source: eventDate.date_source };
    } else if (requestDateEvidence.date) {
      datePatch = { dataDate: requestDateEvidence.date, date_source: requestDateEvidence.date_source || 'request' };
    }
  }
  if (row._source_path) {
    return { ...row, ...datePatch };
  }
  return { ...row, ...datePatch, _source_path: sourcePath || '$' };
}

function meituanEventDateEvidence(row, section) {
  const keys = section === 'orders'
    ? ['orderDate', 'order_date', 'bookingDate', 'booking_date', 'orderTime', 'order_time', 'createTime', 'buyTime', 'purchaseTime', 'purchase_time', 'data_date', 'dataDate', 'date']
    : ['reviewDate', 'review_date', 'commentDate', 'comment_date', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'submitTime', 'submit_time', 'data_date', 'dataDate', 'date'];
  for (const key of keys) {
    const date = normalizeMeituanTrafficDateText(row?.[key]);
    if (date) return { date, date_source: `row.${key}` };
  }
  return { date: '', date_source: '' };
}

async function collectMeituanTrafficDomRows(page) {
  return page.evaluate(() => {
    const text = (node) => (node?.innerText || node?.textContent || '').trim().replace(/\s+/g, ' ');
    const fullText = text(document.body);
    const numberFrom = (patterns) => {
      for (const pattern of patterns) {
        const match = fullText.match(pattern);
        if (match && match[1]) {
          const num = Number(String(match[1]).replace(/,/g, ''));
          const multiplier = String(match[2] || '').includes('\u4e07') ? 10000 : 1;
          if (Number.isFinite(num)) return num * multiplier;
        }
      }
      return 0;
    };
    const normalizeNumber = (value, unit = '') => {
      const num = Number(String(value || '').replace(/,/g, ''));
      if (!Number.isFinite(num)) return 0;
      return Math.round(num * (String(unit || '').includes('\u4e07') ? 10000 : 1));
    };
    const pageDateMatch = fullText.match(/(?:\u6570\u636e\u66f4\u65b0\u65f6\u95f4|\u66f4\u65b0\u65f6\u95f4|\u66f4\u65b0\u4e8e)[\uff1a:\s]*(\d{4})[\/\-\u5e74](\d{1,2})[\/\-\u6708](\d{1,2})/);
    const pageDataDate = pageDateMatch
      ? `${pageDateMatch[1]}-${String(pageDateMatch[2]).padStart(2, '0')}-${String(pageDateMatch[3]).padStart(2, '0')}`
      : '';
    const withDate = pageDataDate ? { dataDate: pageDataDate, date_source: 'page.visible_update_time' } : {};
    const rows = [];
    const flowFunnel = fullText.match(/\u6211\u7684\u9152\u5e97\s*\u540c\u884c\u5747\u503c\s*\u66dd\u5149\u4eba\u6570\s*\u6d4f\u89c8\u4eba\u6570\s*\u652f\u4ed8\u8ba2\u5355\u6570\s*([\d,.]+)\s+([\d,.]+)\s+([\d,.]+)\s+([\d,.]+)\s+([\d,.]+)\s+([\d,.]+)\s*\u66dd\u5149-\u6d4f\u89c8\s*\u8f6c\u5316\u7387\s*([\d.]+)%\s*([\d.]+)%\s*\u6d4f\u89c8-\u652f\u4ed8\s*\u8f6c\u5316\u7387\s*([\d.]+)%/);
    if (flowFunnel) {
      const orders = normalizeNumber(flowFunnel[5]);
      rows.push({
        _capture_source: 'dom:traffic:flow_funnel',
        _source_path: 'dom.traffic.flow_funnel',
        _dom_text: fullText.slice(0, 1600),
        ...withDate,
        listExposure: normalizeNumber(flowFunnel[1]),
        detailExposure: normalizeNumber(flowFunnel[3]),
        flowRate: Number(flowFunnel[7]),
        orderFillingNum: orders,
        orderSubmitNum: orders,
        _order_filling_source_policy: 'meituan_flow_funnel_no_separate_order_filling_step_pay_order_count_used',
        _order_submit_source_label: 'pay_order_count',
      });
    }
    if (rows.length === 0) {
      const exposure = numberFrom([/\u66dd\u5149\u91cf\s*([\d,.]+)\s*(\u4e07)?\s*\u6b21/, /\u66dd\u5149[^\d]{0,10}([\d,.]+)\s*(\u4e07)?/]);
      const visitors = numberFrom([/\u6d4f\u89c8\u4eba\u6570\s*([\d,.]+)\s*(\u4e07)?\s*\u4eba/, /\u8bbf\u5ba2[^\d]{0,10}([\d,.]+)\s*(\u4e07)?/, /UV[^\d]{0,10}([\d,.]+)/i]);
      const flowRate = numberFrom([/\u652f\u4ed8\u8f6c\u5316\u7387\s*([\d.]+)\s*%/, /\u6d4f\u89c8-\u652f\u4ed8\s*\u8f6c\u5316\u7387\s*([\d.]+)\s*%/]);
      const orders = numberFrom([/\u652f\u4ed8\u8ba2\u5355\u6570\s*([\d,.]+)\s*(\u4e07)?\s*\u5355/, /\u8ba2\u5355[^\d]{0,10}([\d,.]+)\s*(\u4e07)?/]);
      if (exposure > 0 || visitors > 0 || orders > 0) {
        rows.push({
          _capture_source: 'dom:traffic:home_summary',
          _source_path: 'dom.traffic.home_summary',
          _dom_text: fullText.slice(0, 1200),
          ...withDate,
          listExposure: exposure,
          detailExposure: visitors,
          flowRate: flowRate || (exposure > 0 ? Math.round((visitors / exposure) * 10000) / 100 : 0),
          orderFillingNum: orders,
          orderSubmitNum: orders,
          _order_filling_source_policy: 'meituan_home_summary_no_separate_order_filling_step_pay_order_count_used',
          _order_submit_source_label: 'pay_order_count',
        });
      }
    }
    return rows;
  }).catch(() => []);
}

async function collectDomFallback(page, target, section) {
  if (section === 'traffic') {
    const rows = await collectMeituanTrafficDomRows(page);
    if (rows.length > 0) {
      const url = page.url();
      const responseDateEvidence = extractMeituanTrafficDateEvidenceFromResponses(target);
      const capturedRows = rows.map(row => {
        if (responseDateEvidence.date && !hasTrafficRowDate(row)) {
          row = {
            ...row,
            dataDate: responseDateEvidence.date,
            date_source: responseDateEvidence.date_source,
          };
        }
        row = withMeituanPlatformIdentifier(row);
        return attachOtaCaptureEvidence(row, 'meituan', {
          url,
          section,
          captureSource: row._capture_source || `dom:${section}`,
        });
      });
      target[section].push(...capturedRows);
      appendDomCaptureEvidenceResponses(target, capturedRows, section);
    }
    return;
  }
  if (section === 'orders') {
    await collectMeituanOrderDomAggregate(page, target);
    return;
  }
  const rows = await page.evaluate(sectionName => {
    const text = (node) => (node?.innerText || node?.textContent || '').trim().replace(/\s+/g, ' ');
    const fullText = text(document.body);
    const numberFrom = (patterns) => {
      for (const pattern of patterns) {
        const match = fullText.match(pattern);
        if (match && match[1]) {
          const num = Number(String(match[1]).replace(/,/g, ''));
          if (Number.isFinite(num)) return num;
        }
      }
      return 0;
    };
    const structuredRows = [];
    if (sectionName === 'traffic') {
      const exposure = numberFrom([/曝光量\s*([\d,]+)\s*次/, /曝光[^\d]{0,10}([\d,]+)/, /PV[^\d]{0,10}([\d,]+)/i]);
      const visitors = numberFrom([/浏览人数\s*([\d,]+)\s*人/, /访客[^\d]{0,10}([\d,]+)/, /UV[^\d]{0,10}([\d,]+)/i]);
      const orders = numberFrom([/订单量\s*([\d,]+)\s*单/, /订单[^\d]{0,10}([\d,]+)/]);
      if (exposure > 0 || visitors > 0 || orders > 0) {
        structuredRows.push({
          _capture_source: 'dom:traffic:structured',
          _dom_text: fullText.slice(0, 1200),
          exposure_count: exposure,
          page_views: visitors,
          unique_visitors: visitors,
          click_count: orders,
          order_count: orders,
        });
      }
    }
    const tableRows = Array.from(document.querySelectorAll('table tbody tr')).slice(0, 80).map((tr, index) => ({
      _dom_index: index,
      _dom_text: text(tr),
    })).filter(row => row._dom_text);
    const cards = Array.from(document.querySelectorAll('[class*="comment"], [class*="order"], [class*="traffic"], [class*="card"], .ant-list-item')).slice(0, 80).map((node, index) => ({
      _dom_index: index,
      _dom_text: text(node),
    })).filter(row => row._dom_text && row._dom_text.length > 10);
    return [...structuredRows, ...tableRows, ...cards].map(row => ({
      ...row,
      _capture_source: row._capture_source || `dom:${sectionName}`,
      _source_path: row._source_path || row._capture_source || `dom:${sectionName}`,
    }));
  }, section);

  if (!rows.length) {
    return;
  }
  const url = page.url();
  const capturedRows = rows.map(row => {
    row = withMeituanPlatformIdentifier(row);
    return attachOtaCaptureEvidence(row, 'meituan', {
      url,
      section,
      captureSource: row._capture_source || `dom:${section}`,
    });
  });
  target[section].push(...capturedRows);
  appendDomCaptureEvidenceResponses(target, capturedRows, section);
}

async function collectMeituanOrderDomAggregate(page, target) {
  const evidence = target?.section_evidence?.orders;
  const targetDate = String(evidence?.target_date || '').trim();
  const queryEpoch = Number(evidence?.query_epoch || 0);
  if (
    evidence?.status !== 'target_date_queried'
    || evidence?.evidence_source !== 'page.form_readback'
    || evidence?.marker !== 'meituan_orders_purchase_date_query'
    || !/^\d{4}-\d{2}-\d{2}$/.test(targetDate)
    || !Number.isInteger(queryEpoch)
    || queryEpoch <= 0
  ) {
    return;
  }

  const frame = page.frames().find(item => /\/order-eb\//i.test(item.url()));
  if (!frame) return;
  const summary = await frame.evaluate(expectedDate => {
    const text = (document.body?.innerText || document.body?.textContent || '').replace(/\s+/g, ' ');
    const totalMatch = text.match(/\u5171\s*([\d,]+)\s*\u4e2a\u8ba2\u5355/);
    if (!totalMatch) return null;
    const orderCount = Number(String(totalMatch[1] || '').replace(/,/g, ''));
    if (!Number.isInteger(orderCount) || orderCount < 0) return null;
    const purchaseDates = Array.from(text.matchAll(/\u8d2d\u4e70\u65f6\u95f4[\uff1a:]\s*(\d{4}-\d{2}-\d{2})/g), match => match[1]);
    if (purchaseDates.length !== orderCount || purchaseDates.some(value => value !== expectedDate)) {
      return null;
    }
    return {
      order_count: orderCount,
      visible_order_date_count: purchaseDates.length,
      visible_order_dates_match_target: true,
    };
  }, targetDate).catch(() => null);
  if (!summary) return;

  let row = {
    ...summary,
    orders: summary.order_count,
    dataDate: targetDate,
    date_source: 'page.orders.purchase_date_input.readback',
    compare_type: 'self',
    is_self: true,
    query_epoch: queryEpoch,
    page_summary_marker: 'meituan_orders_target_date_summary',
    _capture_source: 'dom:orders:target_date_summary',
    _source_path: 'dom.orders.target_date_summary',
  };
  row = withMeituanPlatformIdentifier(row);
  row = attachOtaCaptureEvidence(row, 'meituan', {
    url: frame.url(),
    section: 'orders',
    captureSource: row._capture_source,
  });
  target.orders.push(row);
  appendDomCaptureEvidenceResponses(target, [row], 'orders');
}

function hasTrafficRowDate(row) {
  return [row?.date, row?.dataDate, row?.statDate, row?.stat_date, row?.data_date, row?.reportDate, row?.day]
    .some(value => String(value ?? '').trim() !== '');
}

function extractMeituanTrafficDateEvidenceFromResponses(target) {
  const responses = Array.isArray(target?.responses) ? [...target.responses].reverse() : [];
  for (const response of responses) {
    if (!response || String(response.section || response.capture_section || '').toLowerCase() !== 'traffic') {
      continue;
    }
    const dateText = findMeituanTrafficDateText(response.data);
    const date = normalizeMeituanTrafficDateText(dateText);
    if (date) {
      return {
        date,
        date_source: 'response.rtDataUpdateTime',
      };
    }
  }
  return { date: '', date_source: '' };
}

function findMeituanTrafficDateText(value) {
  if (!value || typeof value !== 'object') {
    return '';
  }
  const direct = [
    value.rtDataUpdateTime,
    value.updateTime,
    value.updatedAt,
    value?.data?.rtDataUpdateTime,
    value?.data?.updateTime,
    value?.data?.updatedAt,
  ];
  for (const item of direct) {
    const text = String(item || '').trim();
    if (text) {
      return text;
    }
  }
  const serialized = JSON.stringify(value);
  const match = serialized.match(/(?:\u6570\u636e\u66f4\u65b0\u65f6\u95f4|\u66f4\u65b0\u65f6\u95f4|\u66f4\u65b0\u4e8e)[\uff1a:\s]*(\d{4}[\/\-\u5e74]\d{1,2}[\/\-\u6708]\d{1,2})/);
  return match ? match[0] : '';
}

function normalizeMeituanTrafficDateText(value) {
  const text = String(value || '').trim();
  const match = text.match(/(\d{4})[\/\-\u5e74](\d{1,2})[\/\-\u6708](\d{1,2})/);
  if (!match) {
    return '';
  }
  return `${match[1]}-${String(match[2]).padStart(2, '0')}-${String(match[3]).padStart(2, '0')}`;
}

function appendDomCaptureEvidenceResponses(target, rows, section) {
  for (const row of rows) {
    const evidence = row && typeof row.capture_evidence === 'object' ? row.capture_evidence : {};
    const sourceTraceId = String(row?.source_trace_id || evidence.source_trace_id || '').trim();
    const sourceUrlHash = String(row?.source_url_hash || evidence.source_url_hash || '').trim();
    if (!sourceTraceId && !sourceUrlHash) {
      continue;
    }
    target.responses.push({
      url_hash: sourceUrlHash,
      source_trace_id: sourceTraceId,
      section,
      capture_section: section,
      endpoint_id: row?._source_path || row?._capture_source || `dom.${section}`,
      endpoint_label: 'dom_visible_metric_evidence',
      status: 200,
      row_count: 1,
      request_date_source: row?.date_source || row?.dateSource || '',
    });
  }
}

function dedupePayloadRows(target) {
  for (const section of ['reviews', 'traffic', 'order_flow', 'ads', 'orders']) {
    const seen = new Set();
    target[section] = target[section].filter(row => {
      const key = JSON.stringify([
        row.review_id ?? row.reviewId ?? row.commentId ?? '',
        row.order_id ?? row.orderId ?? '',
        row.date ?? row.dataDate ?? row.statDate ?? '',
        row.poi_id ?? row.poiId ?? row.hotel_id ?? row.hotelId ?? '',
        row.source_trace_id ?? row.capture_evidence?.source_trace_id ?? row.source_url_hash ?? row.capture_evidence?.source_url_hash ?? '',
        row._dom_text ?? '',
        row.dimension ?? '',
      ]);
      if (seen.has(key)) {
        return false;
      }
      seen.add(key);
      return true;
    });
  }
}

async function submitPayload(data) {
  const apiBase = String(args.apiBase || 'http://localhost/HOTEL/public/api/online-data').replace(/\/$/, '');
  const token = String(args.token || process.env.SUXIOS_TOKEN || '').trim();
  if (!token) {
    fail('Missing --token or SUXIOS_TOKEN for --submit=true');
  }

  const response = await fetch(`${apiBase}/save-meituan-captured-data`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: token.startsWith('Bearer ') ? token : `Bearer ${token}`,
    },
    body: JSON.stringify({
      system_hotel_id: args.systemHotelId ? Number(args.systemHotelId) : null,
      payload: data,
    }),
  });
  const result = await response.text();
  if (!response.ok) {
    fail(`Submit failed: HTTP ${response.status} ${result.slice(0, 500)}`);
  }
  console.log(result);
}

function readPath(value, path) {
  let current = value;
  for (const key of path) {
    if (!current || typeof current !== 'object' || !(key in current)) {
      return undefined;
    }
    current = current[key];
  }
  return current;
}

async function injectBrowserCookies(context, parsedArgs, platform) {
  return injectStandardBrowserCookies(context, parsedArgs, platform);
}

function summarize(data) {
  return {
    reviews: data.reviews.length,
    traffic: data.traffic.length,
    flowAnalysis: data.flowAnalysis.length,
    order_flow: data.order_flow.length,
    peerRank: data.peerRank.length,
    searchKeywords: data.searchKeywords.length,
    trafficForecast: data.trafficForecast.length,
    ads: data.ads.length,
    orders: data.orders.length,
    responses: data.responses.length,
  };
}

function booleanArg(value) {
  if (value === true) {
    return true;
  }
  const text = String(value ?? '').trim().toLowerCase();
  return ['1', 'true', 'yes', 'y', 'on'].includes(text);
}

function numberValue(value, fallback = 0) {
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function isHeadlessMode() {
  if (args.headless === undefined || args.headless === null || args.headless === '') {
    return false;
  }
  return booleanArg(args.headless);
}
