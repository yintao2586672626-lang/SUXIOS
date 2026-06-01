import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import process from 'node:process';
import { launchOtaPersistentContext } from './lib/cloakbrowser_launcher.mjs';
import {
  buildCtripEndpointCandidates,
  buildCtripStandardRowsFromFacts,
  buildCtripPageUrls,
  ctripCatalogSummary,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
  getCtripSectionInteractionPlan,
  normalizeCtripCaptureSections,
  sectionDataType,
  sectionLabel,
} from './lib/ctrip_capture_catalog.mjs';
import {
  extractCtripApprovedMappingRows,
  normalizeCtripApprovedMappings,
} from './lib/ctrip_approved_mapping.mjs';
import {
  buildCtripEndpointEvidenceDraftsFromCapture,
  buildCtripEndpointEvidenceMatrix,
} from './lib/ctrip_endpoint_evidence.mjs';
import {
  buildCtripCaptureAudit,
  evaluateCtripCaptureAuditGate,
} from './lib/ctrip_capture_audit.mjs';
import { sanitizeOtaPayloadForStorage } from './lib/ota_capture_standard.mjs';
import { fail, parseArgs, safeName, timestamp } from './lib/shared_helpers.mjs';

const PAGE_URLS = buildCtripPageUrls();
const CATALOG_SUMMARY = ctripCatalogSummary();
const CTRIP_LOGIN_URL = 'https://ebooking.ctrip.com/login/index';

const args = parseArgs(process.argv.slice(2));
const profileId = stringValue(args.profileId || args.hotelId || args.systemHotelId || '').trim();
if (!profileId) {
  fail('Missing --profile-id or --hotel-id. Example: node scripts/ctrip_browser_capture.mjs --profile-id=59');
}

const loginOnly = booleanArg(args.loginOnly) || booleanArg(args.authOnly);
const requestedSections = normalizeSections(args.sections || args.captureSections || args.only || 'default');
const hotelId = stringValue(args.hotelId || '').trim();
const defaultDataDate = stringValue(args.dataDate || '').trim();
const storageDir = resolve(args.profileDir || join('storage', `ctrip_profile_${safeName(profileId)}`));
const reportDir = resolve(args.reportDir || 'reports');
const assetDir = join(reportDir, 'ctrip_capture_assets');
const outputPath = resolve(args.output || join(reportDir, `ctrip_browser_capture_${safeName(profileId)}_${timestamp()}.json`));
const capturedAt = new Date().toISOString();
const approvedMappingsPath = stringValue(args.approvedMappings || args.approvedMapping || args.p3Mappings || '').trim();
const approvedMappings = approvedMappingsPath
  ? normalizeCtripApprovedMappings(JSON.parse((await readFile(resolve(approvedMappingsPath), 'utf8')).replace(/^\uFEFF/, '')))
  : [];

await mkdir(storageDir, { recursive: true });
await mkdir(reportDir, { recursive: true });
await mkdir(assetDir, { recursive: true });
await mkdir(dirname(outputPath), { recursive: true });

const payload = {
  profile_id: profileId,
  hotel_id: hotelId,
  hotel_name: stringValue(args.hotelName || ''),
  system_hotel_id: args.systemHotelId ? Number(args.systemHotelId) : null,
  default_data_date: defaultDataDate,
  source: 'ctrip_browser_profile',
  mode: loginOnly ? 'login_only' : 'capture',
  captured_at: capturedAt,
  page_urls: PAGE_URLS,
  requested_sections: requestedSections,
  catalog: CATALOG_SUMMARY,
  approved_mappings: {
    configured: Boolean(approvedMappingsPath),
    path: approvedMappingsPath,
    mapping_count: approvedMappings.length,
  },
  pages: [],
  responses: [],
  xhr_urls: [],
  unmatched_xhr_urls: [],
  endpoint_candidates: [],
  p3_evidence_drafts: [],
  p3_evidence_matrix: null,
  capture_audit: null,
  capture_gate: null,
  capture_gap_report: null,
  by_section: {},
  rows: [],
  standard_rows: [],
  catalog_facts: [],
  business: [],
  traffic: [],
  reviews: [],
  screenshots: [],
  cookie_injection: { attempted: false, injected_count: 0, domains: [] },
  auth_status: { status: 'pending', message: 'Login status has not been checked.' },
};
for (const section of requestedSections) {
  payload.by_section[section] = [];
}
let activeCaptureSection = '';

const browser = await launchOtaPersistentContext(storageDir, args);
await grantCtripBrowserPermissions(browser);
payload.cookie_injection = await injectBrowserCookies(browser, args, 'ctrip');
const page = await browser.newPage();
registerResponseCapture(page, payload);

try {
  const loginStatus = await ensureLoggedIn(page);
  payload.auth_status = loginStatus;
  if (!loginStatus.ok) {
    payload.pages.push({
      name: 'auth',
      label: '登录状态',
      url: loginStatus.url || page.url(),
      configured_url: ctripLoginEntryUrl(),
      ok: false,
      auth_status: loginStatus.status,
      error: loginStatus.message,
    });
    process.exitCode = 2;
  } else if (loginOnly) {
    await holdInteractiveLoginWindow(page, 'Ctrip');
    await finalizeLoginOnlyPayload();
  } else {
    for (const section of requestedSections) {
      const pageTargets = PAGE_URLS[section] || [];
      if (pageTargets.length === 0) {
        payload.pages.push({ name: section, label: sectionLabel(section), url: '', ok: false, error: 'no page URL configured' });
        continue;
      }
      for (const targetPage of pageTargets) {
        await captureSection(page, section, targetPage.url, targetPage.confidence);
      }
    }
  }
  if (!loginOnly) {
    await finalizePayload();
  }

  console.log(JSON.stringify({
    output: outputPath,
    profile_dir: storageDir,
    auth_status: payload.auth_status,
    counts: summarize(payload),
  }, null, 2));
} finally {
  await browser.close();
}

async function ensureLoggedIn(page) {
  await page.goto(ctripLoginEntryUrl(), { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
  await page.waitForTimeout(2000);
  await dismissBlockingOverlays(page);
  if (await looksLoggedIn(page)) {
    return { ok: true, status: 'logged_in', url: page.url(), message: 'Ctrip profile is logged in.' };
  }

  console.log(`Open Ctrip eBooking login page and complete login. Profile will be saved at ${storageDir}`);
  const timeoutMs = Number(args.loginTimeoutMs || 300000);
  const deadline = Date.now() + Math.max(timeoutMs, 30000);
  while (Date.now() < deadline) {
    await page.waitForTimeout(3000);
    if (await looksLoggedIn(page)) {
      return { ok: true, status: 'logged_in', url: page.url(), message: 'Ctrip profile is logged in.' };
    }
  }
  return {
    ok: false,
    status: 'login_required',
    url: page.url(),
    timeout_ms: timeoutMs,
    message: `Ctrip login timeout after ${Math.round(timeoutMs / 1000)} seconds`,
  };
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

async function finalizePayload() {
  dedupeRows(payload.business, row => row._fingerprint || JSON.stringify([row.hotelId, row.dataDate, row.amount, row.quantity, row.bookOrderNum]));
  dedupeRows(payload.traffic, row => row._fingerprint || JSON.stringify([row.hotelId, row.date, row.listExposure, row.detailExposure, row.orderFillingNum, row.orderSubmitNum]));
  dedupeRows(payload.reviews, row => row.review_id || JSON.stringify([row.content || '', row.user_name || '', row.comment_time || '']));
  dedupeRows(payload.rows, row => row._fingerprint || JSON.stringify([row._source_url, row.hotelId, row.dataDate || row.date, row.data_type, row.metric_key || '', row.value || row.amount || row.quantity || '']));
  dedupeRows(payload.standard_rows, row => JSON.stringify([row.source, row.data_type, row.hotel_id, row.system_hotel_id || '', row.data_date, row.dimension]));
  payload.endpoint_candidates = buildCtripEndpointCandidates(payload.unmatched_xhr_urls);
  payload.p3_evidence_matrix = buildCtripEndpointEvidenceMatrix(payload.p3_evidence_drafts, { generatedAt: capturedAt });
  const audit = buildCtripCaptureAudit([{ path: outputPath, payload }], { generatedAt: capturedAt });
  payload.capture_gate = evaluateCtripCaptureAuditGate(audit, captureGateOptions());
  payload.capture_gap_report = audit.capture_gap_report;
  payload.capture_audit = compactCaptureAudit(audit);
  await writeFile(outputPath, JSON.stringify(payload, null, 2), 'utf8');
}

async function finalizeLoginOnlyPayload() {
  payload.capture_gate = {
    status: 'pass',
    mode: loginOnly ? 'login_only' : 'capture',
    reason: 'login_only',
    failed_check_ids: [],
    checks: [{
      id: 'auth_session',
      status: 'pass',
      message: 'Ctrip login session prepared in browser profile.',
    }],
  };
  payload.capture_gap_report = {
    status: 'skipped',
    reason: 'login_only',
  };
  payload.capture_audit = {
    auth_status: payload.auth_status,
    capture_gap_report: payload.capture_gap_report,
  };
  payload.pages.push({
    name: 'auth',
    label: '登录状态',
    url: payload.auth_status?.url || '',
    configured_url: ctripLoginEntryUrl(),
    ok: true,
    auth_status: 'login_prepared',
    reason: 'login_only',
  });
  await writeFile(outputPath, JSON.stringify(payload, null, 2), 'utf8');
}

async function looksLoggedIn(page) {
  const url = page.url();
  if (/login|passport|account|oauth|sso/i.test(url)) {
    return false;
  }
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  if (/login|password|captcha|verification/i.test(text) && !/data|report|comment|order|ebooking/i.test(text)) {
    return false;
  }
  return true;
}

async function captureSection(page, section, url, confidence = '') {
  activeCaptureSection = section;
  let ok = true;
  let errorMessage = '';
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 });
  } catch (error) {
    ok = false;
    errorMessage = error.message;
  }

  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => null);
  await page.waitForTimeout(2500);
  await dismissBlockingOverlays(page);
  await clickLikelyRefreshButtons(page);
  const interactions = await runSectionInteractionPlan(page, section);
  await page.evaluate(() => window.scrollTo(0, Math.max(document.body.scrollHeight, document.documentElement.scrollHeight))).catch(() => null);
  await page.waitForTimeout(1200);
  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => null);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => null);

  const screenshot = join(assetDir, `${safeName(profileId)}_${section}_${timestamp()}.png`);
  await page.screenshot({ path: screenshot, fullPage: true }).catch(() => null);
  if (existsSync(screenshot)) {
    payload.screenshots.push({ name: section, path: screenshot });
  }
  payload.pages.push({ name: section, label: sectionLabel(section), url: page.url(), configured_url: url, confidence, ok, interactions, ...(errorMessage ? { error: errorMessage } : {}) });
  activeCaptureSection = '';
}

async function runSectionInteractionPlan(page, section) {
  const plan = getCtripSectionInteractionPlan(section);
  const results = [];
  for (const step of plan) {
    if (step.action !== 'click_text' || !step.text) {
      continue;
    }
    const result = await clickTextIfVisible(page, step.text);
    results.push({
      action: step.action,
      text: step.text,
      reason: step.reason || '',
      clicked: result.clicked,
      skipped: result.skipped || '',
      ...(result.error ? { error: result.error } : {}),
    });
    if (result.clicked) {
      await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => null);
      await page.waitForTimeout(900);
    }
  }
  return results;
}

async function clickTextIfVisible(page, text) {
  await dismissBlockingOverlays(page);
  const locators = [
    page.getByRole('tab', { name: text, exact: true }).first(),
    page.getByRole('button', { name: text, exact: true }).first(),
    page.getByRole('link', { name: text, exact: true }).first(),
    page.locator('a,button,label,[role="tab"],[role="button"]').filter({ hasText: text }).first(),
    page.getByText(text, { exact: true }).first(),
  ];
  let lastError = '';
  for (const locator of locators) {
    const visible = await locator.isVisible({ timeout: 500 }).catch(() => false);
    if (!visible) {
      continue;
    }
    const enabled = await locator.isEnabled({ timeout: 500 }).catch(() => true);
    if (!enabled) {
      return { clicked: false, skipped: 'disabled' };
    }
    try {
      await locator.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => null);
      await locator.click({ timeout: 2000 });
      return { clicked: true };
    } catch (error) {
      lastError = error.message;
      await dismissBlockingOverlays(page);
      try {
        await locator.click({ timeout: 1500, force: true });
        return { clicked: true, forced: true };
      } catch (forceError) {
        lastError = forceError.message;
      }
      try {
        await locator.evaluate((element) => element.click());
        return { clicked: true, evaluated: true };
      } catch (evaluateError) {
        lastError = evaluateError.message;
      }
    }
  }
  return lastError ? { clicked: false, error: lastError } : { clicked: false, skipped: 'not_visible' };
}

async function clickLikelyRefreshButtons(page) {
  await dismissBlockingOverlays(page);
  const selectors = [
    'button:has-text("查询")',
    'button:has-text("搜索")',
    'button:has-text("刷新")',
    'button:has-text("确定")',
  ];
  for (const selector of selectors) {
    const target = page.locator(selector).first();
    if (await target.isVisible({ timeout: 800 }).catch(() => false)) {
      await target.click({ timeout: 2500 }).catch(() => null);
      await page.waitForTimeout(1500);
    }
  }
}

async function grantCtripBrowserPermissions(context) {
  if (!context || typeof context.grantPermissions !== 'function') {
    return;
  }
  for (const origin of [
    'https://ebooking.ctrip.com',
    'https://bbk.ctripbiz.com',
    'https://bbk.ctripbiz.cn',
  ]) {
    await context.grantPermissions(['notifications'], { origin }).catch(() => null);
  }
}

async function dismissBlockingOverlays(page) {
  await page.keyboard.press('Escape').catch(() => null);
  const targets = [
    page.getByRole('button', { name: '知道了', exact: true }).first(),
    page.getByRole('button', { name: '我知道了', exact: true }).first(),
    page.getByRole('button', { name: '允许', exact: true }).first(),
    page.locator('button, .ant-modal-close, .c-modal-close, .modal-close, [aria-label="Close"], [aria-label="close"]').filter({ hasText: /^$/ }).first(),
    page.locator('text=语音通知自动播放失败').locator('..').getByText('知道了', { exact: true }).first(),
  ];
  for (const target of targets) {
    const visible = await target.isVisible({ timeout: 300 }).catch(() => false);
    if (!visible) {
      continue;
    }
    await target.click({ timeout: 1000, force: true }).catch(() => null);
    await page.waitForTimeout(250);
  }
  await page.locator('.ant-modal-mask, .modal-backdrop, .c-modal-mask').evaluateAll((nodes) => {
    for (const node of nodes) {
      node.style.pointerEvents = 'none';
      node.style.display = 'none';
    }
  }).catch(() => null);
}

function registerResponseCapture(page, target) {
  page.on('response', async response => {
    const requestType = response.request().resourceType();
    if (requestType !== 'xhr' && requestType !== 'fetch') {
      return;
    }

    const url = response.url();
    const urlLower = String(url || '').toLowerCase();
    if (!isCtripCaptureUrl(urlLower)) {
      return;
    }
    if (target.xhr_urls.length < 200) {
      target.xhr_urls.push({ url, status: response.status(), request_type: requestType });
    }
    if (response.status() !== 200) {
      return;
    }

    const request = response.request();
    const endpoint = findCtripEndpointByUrl(url, { preferredSection: activeCaptureSection });
    const urlSection = endpoint?.section || '';
    const approvedMappingMatches = approvedMappingsForUrl(url);
    const unmatchedXhr = {
      url,
      status: response.status(),
      request_type: requestType,
      method: request?.method?.() || '',
    };
    if (!endpoint && target.unmatched_xhr_urls.length < 200) {
      target.unmatched_xhr_urls.push(unmatchedXhr);
    }
    const p3Candidate = buildCtripEndpointCandidates([unmatchedXhr])[0] || null;
    if (!urlSection && approvedMappingMatches.length === 0 && !p3Candidate && !urlLower.includes('datacenter') && !urlLower.includes('pyramid') && !urlLower.includes('psi') && !urlLower.includes('bpi')) {
      return;
    }

    let body = null;
    try {
      const contentType = response.headers()['content-type'] || '';
      const text = await response.text();
      body = parseResponseBody(text, contentType);
    } catch (error) {
      target.responses.push({ url, section: urlSection || 'unknown', endpoint_id: endpoint?.id || '', status: response.status(), request_type: requestType, error: error.message });
      return;
    }

    if (p3Candidate && target.p3_evidence_drafts.length < 80) {
      const requestPayload = request?.postData?.() || '';
      const drafts = buildCtripEndpointEvidenceDraftsFromCapture([{
        ...unmatchedXhr,
        headers: request?.headers?.() || {},
        payload: requestPayload,
        response: body,
        page_url: page.url(),
        captured_at: capturedAt,
        section: activeCaptureSection || p3Candidate.candidate_section,
        page_context: {
          page: sectionLabel(activeCaptureSection || p3Candidate.candidate_section),
          module: activeCaptureSection || p3Candidate.candidate_section,
          url: page.url(),
        },
      }], {
        profileId,
        hotelId: hotelId || profileId,
        defaultDataDate,
        capturedAt,
        pageUrl: page.url(),
        activeSection: activeCaptureSection || p3Candidate.candidate_section,
        params: {
          hotel_id: hotelId || profileId,
          data_date: defaultDataDate,
        },
      });
      target.p3_evidence_drafts.push(...drafts);
    }

    const section = urlSection || approvedMappingMatches[0]?.candidate_section || inferSection(body, url);
    if (!section || (!requestedSections.includes(section) && approvedMappingMatches.length === 0)) {
      return;
    }

    const dataType = endpoint?.dataType || approvedMappingMatches[0]?.data_type || sectionDataType(section);
    const safeBody = sanitizeOtaPayloadForStorage(body, dataType);
    const rows = normalizeRows(safeBody, dataType, url).map(row => ({
      ...row,
      section,
      data_type: dataType,
      endpoint_id: endpoint?.id || '',
      endpoint_label: endpoint?.label || '',
    }));
    const factContext = {
      endpoint,
      section,
      dataType,
      hotelId: hotelId || profileId,
      dataDate: defaultDataDate,
      capturedAt,
      url,
    };
    const catalogFacts = extractCtripCatalogFacts(safeBody, factContext);
    const standardRows = buildCtripStandardRowsFromFacts(catalogFacts, {
      ...factContext,
      systemHotelId: payload.system_hotel_id,
      hotelName: payload.hotel_name,
      profileId,
      defaultDataDate,
    });
    const approvedRows = extractCtripApprovedMappingRows(body, {
      ...factContext,
      mappings: approvedMappings,
      systemHotelId: payload.system_hotel_id,
      hotelName: payload.hotel_name,
      profileId,
      defaultDataDate,
    });
    target.catalog_facts.push(...catalogFacts);
    target.standard_rows.push(...standardRows);
    target.standard_rows.push(...approvedRows);
    target.responses.push({
      url,
      section,
      section_label: sectionLabel(section),
      endpoint_id: endpoint?.id || '',
      endpoint_label: endpoint?.label || '',
      data_type: dataType,
      status: response.status(),
      request_type: requestType,
      keyword_hit: Boolean(urlSection),
      row_count: rows.length,
      catalog_fact_count: catalogFacts.length,
      standard_row_count: standardRows.length + approvedRows.length,
      approved_mapping_row_count: approvedRows.length,
      data: safeBody,
    });

    target.rows.push(...rows);
    target.rows.push(...approvedRows);
    target.by_section[section] ||= [];
    target.by_section[section].push(...rows);
    target.by_section[section].push(...approvedRows);
    if (dataType === 'business') {
      target.business.push(...rows);
    } else if (dataType === 'traffic') {
      target.traffic.push(...rows);
    } else if (dataType === 'review') {
      target.reviews.push(...rows);
    }
  });
}

function classifyByUrl(url) {
  return findCtripEndpointByUrl(url, { preferredSection: activeCaptureSection })?.section || '';
}

function inferSection(value, url) {
  if (!value || typeof value !== 'object') {
    return '';
  }
  const sample = JSON.stringify(value).slice(0, 8000).toLowerCase();
  if (/commentlist|commentid|reviewid|commentcontent|reviewcontent/.test(sample)) {
    return 'reviews';
  }
  if (/listexposure|detailexposure|flowrate|orderfillingnum|ordersubmitnum|traffic|pv|uv/.test(sample)) {
    return 'traffic';
  }
  if (/amount|saleamount|totalamount|bookordernum|roomnights|marketoverview|dayreport/.test(sample)) {
    return 'business';
  }
  if (String(url || '').toLowerCase().includes('flow')) {
    return 'traffic';
  }
  return '';
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

function normalizeRows(value, section, sourceUrl) {
  if (section === 'reviews') {
    return normalizeCommentList(value).map(row => normalizeCommentRow(row, sourceUrl, 'xhr:getCommentList'));
  }
  if (section === 'traffic') {
    return normalizeGenericList(value, 'traffic')
      .map(row => normalizeTrafficRow(row, sourceUrl))
      .filter(Boolean);
  }
  return normalizeGenericList(value, 'business')
    .map(row => normalizeBusinessRow(row, sourceUrl))
    .filter(Boolean);
}

function normalizeGenericList(value, section, depth = 0) {
  if (!value || typeof value !== 'object' || depth > 6) {
    return [];
  }
  if (Array.isArray(value)) {
    return value.flatMap(item => normalizeGenericList(item, section, depth + 1));
  }

  if (section === 'traffic' && looksLikeTrafficRow(value)) {
    return [value];
  }
  if (section === 'business' && looksLikeBusinessRow(value)) {
    return [value];
  }

  const paths = section === 'traffic'
    ? [
        ['data', 'list'],
        ['data', 'rows'],
        ['data', 'traffic'],
        ['data', 'businessData'],
        ['data', 'peerTrends'],
        ['data', 'rankList'],
        ['data', 'ranking'],
        ['data', 'rankData'],
        ['data', 'categoryRank'],
        ['data', 'categoryRankList'],
        ['data', 'competitionRank'],
        ['data', 'competitionRankList'],
        ['data', 'competeRank'],
        ['data', 'competeRankList'],
        ['data', 'scanFlowDetails'],
        ['data', 'flowData'],
        ['data', 'trafficData'],
        ['data', 'statData'],
        ['result', 'list'],
        ['result', 'rows'],
        ['result', 'rankList'],
        ['list'],
        ['rows'],
        ['rankList'],
        ['categoryRankList'],
        ['competitionRankList'],
        ['data'],
      ]
    : [
        ['data', 'hotelList'],
        ['data', 'list'],
        ['data', 'rows'],
        ['data', 'overview'],
        ['data', 'marketOverView'],
        ['data', 'marketOverview'],
        ['result', 'hotelList'],
        ['result', 'list'],
        ['result', 'rows'],
        ['hotelList'],
        ['list'],
        ['rows'],
        ['data'],
      ];

  for (const path of paths) {
    const nested = readPath(value, path);
    const rows = normalizeGenericList(nested, section, depth + 1);
    if (rows.length) {
      return rows;
    }
  }

  const rows = [];
  for (const nested of Object.values(value)) {
    rows.push(...normalizeGenericList(nested, section, depth + 1));
    if (rows.length > 100) {
      break;
    }
  }
  return rows;
}

function looksLikeBusinessRow(row) {
  const keys = [
    'amount', 'Amount', 'totalAmount', 'saleAmount', 'orderAmount', 'gmv',
    '成交收入', '成交金额', '销售额',
    'quantity', 'roomNights', 'checkOutQuantity',
    '成交间夜', '间夜', '房晚',
    'bookOrderNum', 'orderCount', 'orderNum',
    '订单数', '成交订单数',
    'commentScore', 'avgScore',
    'uv', 'visitorCount', 'yesterdayUv', '昨日UV', 'PSI', 'psi', 'replyRate', '回复率', 'favoriteCount', '收藏数', 'visitorRank', '访客排名',
  ];
  return keys.some(key => Object.prototype.hasOwnProperty.call(row, key));
}

function normalizeBusinessRow(row, sourceUrl) {
  const amount = numberValue(firstValue(row, ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount', 'orderAmount', 'gmv', 'turnover', 'bookingAmount', '成交收入', '成交金额', '销售额']), 0);
  const quantity = numberValue(firstValue(row, ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity', 'roomNightCount', 'nightNum', '成交间夜', '间夜', '房晚']), 0);
  const bookOrderNum = numberValue(firstValue(row, ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings', '成交订单数', '订单数']), 0);
  const commentScore = normalizeScore(firstValue(row, ['commentScore', 'comment_score', 'score', 'avgScore', 'rating', 'overallScore']));
  const yesterdayUv = numberValue(firstValue(row, ['yesterdayUv', 'yesterdayUV', 'uv', 'UV', 'visitorCount', 'detailUv', 'totalDetailNum', 'visitors', '昨日UV', '访客数']), 0);
  const avgPrice = numberValue(firstValue(row, ['avgPrice', 'averagePrice', 'adr', 'ADR', '均价', '平均房价']), quantity > 0 ? amount / quantity : 0);
  const conversionRate = normalizePercent(firstValue(row, ['conversionRate', 'convertionRate', 'bookRate', '成交率', '转化率']), 0);
  const competitorUv = numberValue(firstValue(row, ['competitorUv', 'competitorUV', 'competeUv', 'competeUV', 'peerUv', 'peerUV', '竞品UV']), 0);
  const competitorOrders = numberValue(firstValue(row, ['competitorOrders', 'competitorOrderNum', 'competeOrderNum', 'peerOrderNum', '竞品订单', '竞品订单数']), 0);
  const competitorAmount = numberValue(firstValue(row, ['competitorAmount', 'competitorRevenue', 'competeAmount', 'peerAmount', '竞品收入', '竞品成交收入']), 0);
  const psi = numberValue(firstValue(row, ['psi', 'PSI', 'psiScore', 'PSI值']), 0);
  const replyRate = normalizePercent(firstValue(row, ['replyRate', 'reply_rate', '回复率']), 0);
  const favoriteCount = numberValue(firstValue(row, ['favoriteCount', 'favorite_count', 'collectCount', '收藏数', '收藏量']), 0);
  const visitorRank = numberValue(firstValue(row, ['visitorRank', 'visitor_rank', 'uvRank', '访客排名']), 0);
  if (amount <= 0 && quantity <= 0 && bookOrderNum <= 0 && commentScore <= 0 && yesterdayUv <= 0 && competitorUv <= 0 && psi <= 0 && favoriteCount <= 0) {
    return null;
  }

  const dataDate = normalizeDate(firstValue(row, ['dataDate', 'date', 'data_date', 'statDate', 'stat_date', 'bizDate', 'businessDate', 'reportDate'])) || defaultDataDate;
  const resolvedHotelId = stringValue(firstValue(row, ['hotelId', 'hotel_id', 'HotelId', 'hotelID', 'nodeId', 'node_id'], hotelId || profileId));
  if (!resolvedHotelId) {
    return null;
  }

  return {
    ...row,
    hotelId: resolvedHotelId,
    hotelName: stringValue(firstValue(row, ['hotelName', 'hotel_name', 'HotelName', 'name'], args.hotelName || '')),
    dataDate,
    amount,
    quantity: Math.round(quantity),
    bookOrderNum: Math.round(bookOrderNum),
    commentScore,
    totalDetailNum: Math.round(yesterdayUv),
    avgPrice: Math.round(avgPrice * 100) / 100,
    convertionRate: Math.round(conversionRate * 100) / 100,
    competitorUv: Math.round(competitorUv),
    competitorOrderNum: Math.round(competitorOrders),
    competitorAmount: Math.round(competitorAmount * 100) / 100,
    psi: Math.round(psi * 100) / 100,
    replyRate: Math.round(replyRate * 100) / 100,
    favoriteCount: Math.round(favoriteCount),
    visitorRank: Math.round(visitorRank),
    _source_url: sourceUrl,
    _capture_source: 'xhr:business',
    _fingerprint: JSON.stringify([resolvedHotelId, dataDate, amount, quantity, bookOrderNum, commentScore, yesterdayUv]),
  };
}

function looksLikeTrafficRow(row) {
  const keys = [
    'listExposure', 'detailExposure', 'flowRate', 'orderFillingNum', 'orderSubmitNum',
    'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews',
    'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'visitorCount', 'views', 'traffic',
    'clickCount', 'click_count', 'clickNum', 'clicks', 'orderCount', 'order_count', 'orderNum',
    'bookOrderNum', 'dealNum', 'conversionRate', 'conversion_rate', 'convertionRate',
    'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr',
    'rank', 'ranking', 'rankNo', 'rankIndex', 'competitionRank', 'competitorRank',
    'competeRank', 'categoryRank', 'cateRank', 'categoryRanking', 'rankJson',
    'rawRankJson', 'rankingJson',
  ];
  return keys.some(key => Object.prototype.hasOwnProperty.call(row, key));
}

function normalizeTrafficRow(row, sourceUrl) {
  const listExposure = numberValue(firstValue(row, ['listExposure', 'list_exposure', 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view']), 0);
  const detailExposure = numberValue(firstValue(row, ['detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views', 'pageViews']), 0);
  const orderFillingNum = numberValue(firstValue(row, ['orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount', 'click_count', 'clicks', 'clickNum', 'fillUsers']), 0);
  const orderSubmitNum = numberValue(firstValue(row, ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders']), 0);
  const flowRate = normalizePercent(firstValue(row, ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr']), listExposure > 0 ? (detailExposure / listExposure) * 100 : 0);
  const hasRank = firstValue(row, ['rank', 'ranking', 'competitionRank', 'competitorRank', 'competeRank', 'categoryRank', 'cateRank', 'categoryRanking', 'rankJson', 'rawRankJson', 'rankingJson'], '') !== '';
  if (listExposure <= 0 && detailExposure <= 0 && orderFillingNum <= 0 && orderSubmitNum <= 0 && !hasRank) {
    return null;
  }

  const compareText = String(firstValue(row, ['compareType', 'compare_type', 'type', 'rankType', 'name', 'hotelName'], '')).toLowerCase();
  const isCompetitor = /competitor|peer|average|avg|compete/.test(compareText);
  const resolvedHotelId = stringValue(firstValue(row, ['hotelId', 'hotel_id', 'HotelId', 'hotelID', 'nodeId', 'node_id'], isCompetitor ? '-1' : (hotelId || profileId)));
  if (!resolvedHotelId) {
    return null;
  }
  const dataDate = normalizeDate(firstValue(row, ['date', 'dataDate', 'statDate', 'data_date', 'stat_date', 'reportDate', 'day'])) || defaultDataDate;

  return {
    ...row,
    hotelId: resolvedHotelId,
    date: dataDate,
    listExposure: Math.round(listExposure),
    detailExposure: Math.round(detailExposure),
    flowRate: Math.round(flowRate * 100) / 100,
    orderFillingNum: Math.round(orderFillingNum),
    orderSubmitNum: Math.round(orderSubmitNum),
    _source_url: sourceUrl,
    _capture_source: 'xhr:traffic',
    _fingerprint: JSON.stringify([resolvedHotelId, dataDate, listExposure, detailExposure, orderFillingNum, orderSubmitNum]),
  };
}

function normalizeCommentList(value) {
  if (!value || typeof value !== 'object') {
    return [];
  }
  if (Array.isArray(value)) {
    return value.filter(item => item && typeof item === 'object');
  }

  const paths = [
    ['data', 'commentList'],
    ['data', 'comments'],
    ['data', 'list'],
    ['data', 'rows'],
    ['result', 'commentList'],
    ['result', 'comments'],
    ['result', 'list'],
    ['commentList'],
    ['comments'],
    ['list'],
    ['rows'],
    ['data'],
  ];

  for (const path of paths) {
    const nested = readPath(value, path);
    const rows = normalizeCommentList(nested);
    if (rows.length) {
      return rows;
    }
  }

  return looksLikeCommentRow(value) ? [value] : [];
}

function looksLikeCommentRow(value) {
  const keys = [
    'commentId', 'comment_id', 'reviewId', 'review_id', 'id',
    'commentContent', 'reviewContent', 'content', 'comment',
    'score', 'rating', 'totalScore', 'overallScore', 'commentScore',
  ];
  return keys.some(key => Object.prototype.hasOwnProperty.call(value, key));
}

function normalizeCommentRow(row, sourceUrl, captureSource) {
  const reviewId = stringValue(firstValue(row, ['review_id', 'reviewId', 'comment_id', 'commentId', 'id', 'orderId']));
  const score = normalizeScore(firstValue(row, ['score', 'rating', 'rate', 'totalScore', 'overallScore', 'commentScore', 'star']));
  const content = stringValue(firstValue(row, ['content', 'comment', 'commentContent', 'reviewContent', 'contentText', 'text']));
  const reply = stringValue(firstValue(row, ['reply', 'replyContent', 'merchantReply', 'bizReply', 'hotelReply', 'replyText']));
  const userName = stringValue(firstValue(row, ['user_name', 'userName', 'nickName', 'nickname', 'customerName', 'guestName']));
  const roomType = stringValue(firstValue(row, ['room_type', 'roomType', 'roomName', 'productName', 'ratePlanName']));
  const checkInDate = stringValue(firstValue(row, ['check_in_date', 'checkInDate', 'stayDate', 'stay_date', 'arrivalDate']));
  const commentTime = stringValue(firstValue(row, ['comment_time', 'commentTime', 'review_time', 'reviewTime', 'createTime', 'create_time', 'submitTime', 'submit_time', 'date']));
  const tags = normalizeTags(firstValue(row, ['tags', 'tagList', 'labelList', 'labels', 'tagNames']));
  const hasReply = booleanValue(firstValue(row, ['has_reply', 'hasReply', 'isReply', 'isReplied', 'replied']), Boolean(reply));
  const isNegative = booleanValue(firstValue(row, ['is_negative', 'isNegative', 'badComment', 'negative']), score > 0 && score < 4);

  return {
    ...row,
    review_id: reviewId,
    score,
    content,
    reply,
    has_reply: hasReply,
    is_negative: isNegative,
    user_name: userName,
    room_type: roomType,
    check_in_date: checkInDate,
    comment_time: commentTime,
    tags,
    _raw: row,
    _source_url: sourceUrl,
    _capture_source: captureSource,
  };
}

function dedupeRows(rows, keyFn) {
  const seen = new Set();
  const unique = [];
  for (const row of rows) {
    const key = keyFn(row);
    if (!key || seen.has(key)) {
      continue;
    }
    seen.add(key);
    unique.push(row);
  }
  rows.splice(0, rows.length, ...unique);
}

function firstValue(data, keys, fallback = '') {
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(data, key) && data[key] !== null && data[key] !== '') {
      return data[key];
    }
  }
  return fallback;
}

function numberValue(value, fallback = 0) {
  if (typeof value === 'string') {
    value = value.replace(/[,%\s]/g, '').trim();
  }
  return Number.isFinite(Number(value)) ? Number(value) : fallback;
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  if (typeof value === 'string') {
    return value.trim();
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return '';
}

function normalizeScore(value) {
  const score = numberValue(value, 0);
  if (score > 5 && score <= 50) {
    return Math.round((score / 10) * 10) / 10;
  }
  if (score > 50 && score <= 100) {
    return Math.round((score / 20) * 10) / 10;
  }
  return Math.round(score * 10) / 10;
}

function normalizePercent(value, fallback = 0) {
  const number = numberValue(value, fallback);
  if (number > 0 && number <= 1) {
    return number * 100;
  }
  return number;
}

function normalizeDate(value) {
  if (value === null || value === undefined) {
    return '';
  }
  const text = String(value).trim();
  let match = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
  if (!match) {
    match = text.match(/^(\d{4})(\d{2})(\d{2})$/);
  }
  if (!match) {
    return '';
  }
  return `${match[1]}-${String(match[2]).padStart(2, '0')}-${String(match[3]).padStart(2, '0')}`;
}

function normalizeTags(value) {
  if (!value) {
    return [];
  }
  if (Array.isArray(value)) {
    return value.map(item => {
      if (item && typeof item === 'object') {
        return stringValue(firstValue(item, ['name', 'tagName', 'label', 'text', 'value']));
      }
      return stringValue(item);
    }).filter(Boolean);
  }
  if (typeof value === 'string') {
    return value.split(/[,，、]/).map(item => item.trim()).filter(Boolean);
  }
  return [];
}

function booleanValue(value, fallback = false) {
  if (value === null || value === undefined || value === '') {
    return Boolean(fallback);
  }
  if (typeof value === 'boolean') {
    return value;
  }
  if (typeof value === 'number') {
    return value === 1;
  }
  return ['1', 'true', 'yes', 'y'].includes(String(value).trim().toLowerCase());
}

function booleanArg(value) {
  if (value === true) {
    return true;
  }
  const text = String(value ?? '').trim().toLowerCase();
  return ['1', 'true', 'yes', 'y', 'on'].includes(text);
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
  const raw = await readCookieSource(parsedArgs);
  if (!raw) {
    return { attempted: false, injected_count: 0, domains: [] };
  }

  const pairs = parseCookieHeader(raw);
  if (!pairs.length) {
    throw new Error('Cookie injection failed: empty or invalid Cookie header');
  }

  const domains = allowedCookieDomains(platform);
  const cookies = [];
  for (const domain of domains) {
    for (const pair of pairs) {
      cookies.push({
        name: pair.name,
        value: pair.value,
        domain,
        path: '/',
        secure: true,
        sameSite: 'Lax',
      });
    }
  }

  await context.addCookies(cookies);
  return { attempted: true, injected_count: cookies.length, domains };
}

async function readCookieSource(parsedArgs) {
  const inline = stringValue(parsedArgs.cookies || parsedArgs.cookie || '');
  if (inline) {
    return inline;
  }
  const filePath = stringValue(parsedArgs.cookiesFile || parsedArgs.cookieFile || '');
  if (!filePath) {
    return '';
  }
  return (await readFile(resolve(filePath), 'utf8')).trim();
}

function parseCookieHeader(raw) {
  return String(raw || '')
    .split(';')
    .map(part => part.trim())
    .filter(Boolean)
    .map(part => {
      const index = part.indexOf('=');
      if (index <= 0) {
        return null;
      }
      const name = part.slice(0, index).trim();
      const value = part.slice(index + 1).trim();
      if (!name || /[\s;]/.test(name)) {
        return null;
      }
      return { name, value };
    })
    .filter(Boolean);
}

function allowedCookieDomains(platform) {
  if (platform === 'ctrip') {
    return ['ebooking.ctrip.com', '.ctrip.com', 'bbk.ctripbiz.cn', '.ctripbiz.cn', 'bbk.ctripbiz.com', '.ctripbiz.com'];
  }
  return [];
}

function isCtripCaptureUrl(url) {
  const lower = String(url || '').toLowerCase();
  return lower.includes('ctrip.com')
    || lower.includes('ctripbiz.cn')
    || lower.includes('ctripbiz.com');
}

function firstKnownPageUrl() {
  for (const section of ['business_overview', ...requestedSections]) {
    const first = PAGE_URLS[section]?.[0]?.url;
    if (first) {
      return first;
    }
  }
  return 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true';
}

function ctripLoginEntryUrl() {
  return stringValue(args.loginUrl || args.login_url || args.entryUrl || args.entry_url || '').trim() || CTRIP_LOGIN_URL;
}

function normalizeSections(value) {
  return normalizeCtripCaptureSections(value);
}

function approvedMappingsForUrl(url) {
  const lower = String(url || '').toLowerCase();
  if (!lower || approvedMappings.length === 0) {
    return [];
  }
  return approvedMappings.filter((mapping) => (
    (mapping.url_keywords || []).some((keyword) => lower.includes(String(keyword || '').toLowerCase()))
  ));
}

function summarize(data) {
  const bySection = {};
  for (const [section, rows] of Object.entries(data.by_section || {})) {
    bySection[section] = rows.length;
  }
  return {
    business: data.business.length,
    traffic: data.traffic.length,
    reviews: data.reviews.length,
    rows: data.rows.length,
    standard_rows: data.standard_rows.length,
    catalog_facts: data.catalog_facts.length,
    endpoint_candidates: data.endpoint_candidates.length,
    p3_evidence_drafts: data.p3_evidence_drafts.length,
    p3_evidence_ready: data.p3_evidence_drafts.filter(item => item.catalog_ready).length,
    responses: data.responses.length,
    capture_gate: data.capture_gate?.status || 'unknown',
    capture_gap_status: data.capture_gap_report?.status || 'unknown',
    by_section: bySection,
  };
}

function captureGateOptions() {
  return {
    minResponseCount: args.minResponseCount ?? 1,
    minStandardRows: args.minStandardRows ?? 1,
    maxMissingEndpoints: args.maxMissingEndpoints ?? 0,
    minFieldCoverageRate: args.minFieldCoverageRate,
    maxMissingFields: args.maxMissingFields,
    requireFieldCoverage: args.requireFieldCoverage ? true : undefined,
    requireEndpointCoverage: args.allowMissingEndpoints ? false : undefined,
    requireExpectedEndpoints: args.allowEmptyExpectedEndpoints ? false : undefined,
    requireAuthSession: args.allowUnverifiedAuth ? false : undefined,
  };
}

function compactCaptureAudit(audit) {
  const missingBySection = {};
  for (const [section, stats] of Object.entries(audit.endpoint_coverage?.sections || {})) {
    if ((stats.missing_endpoint_count || 0) > 0) {
      missingBySection[section] = {
        expected_endpoint_count: stats.expected_endpoint_count || 0,
        captured_endpoint_count: stats.captured_endpoint_count || 0,
        missing_endpoint_count: stats.missing_endpoint_count || 0,
        missing_endpoint_ids: stats.missing_endpoint_ids || [],
      };
    }
  }
  const missingFieldsBySection = {};
  for (const [section, stats] of Object.entries(audit.field_coverage?.sections || {})) {
    if ((stats.missing_field_count || 0) > 0) {
      missingFieldsBySection[section] = {
        expected_field_count: stats.expected_field_count || 0,
        captured_field_count: stats.captured_field_count || 0,
        missing_field_count: stats.missing_field_count || 0,
        missing_field_ids: (stats.missing_field_ids || []).slice(0, 80),
      };
    }
  }
  return {
    generated_at: audit.generated_at,
    summary: audit.summary,
    auth_status: audit.auth_status,
    endpoint_coverage: audit.endpoint_coverage?.summary || null,
    field_coverage: audit.field_coverage?.summary || null,
    capture_gap_report: audit.capture_gap_report || null,
    missing_by_section: missingBySection,
    missing_fields_by_section: missingFieldsBySection,
    interactions_by_section: audit.interactions_by_section || {},
  };
}
