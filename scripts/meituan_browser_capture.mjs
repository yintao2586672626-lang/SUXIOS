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
  isImportableMeituanTrafficRow,
  normalizeMeituanTrafficCardRows,
} from './lib/meituan_browser_capture_normalize.mjs';
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
const outputPath = resolve(args.output || join(reportDir, `meituan_capture_${safeName(storeId)}_${timestamp()}.json`));
const captureSections = normalizeCaptureSections(args.sections || args.captureSections || args.only || 'traffic,orders');
const loginOnly = booleanArg(args.loginOnly) || booleanArg(args.authOnly) || booleanArg(args.prepareProfile);

await mkdir(storageDir, { recursive: true });
await mkdir(reportDir, { recursive: true });
await mkdir(assetDir, { recursive: true });

const payload = {
  store_id: storeId,
  poi_id: String(args.poiId || ''),
  poi_name: String(args.poiName || ''),
  system_hotel_id: args.systemHotelId ? Number(args.systemHotelId) : null,
  default_data_date: defaultDataDate,
  captured_at: capturedAt,
  source: 'meituan_browser_profile',
  mode: loginOnly ? 'login_only' : 'capture',
  capture_sections: Array.from(captureSections),
  pages: [],
  responses: [],
  reviews: [],
  traffic: [],
  ads: [],
  orders: [],
  screenshots: [],
  cookie_injection: { attempted: false, injected_count: 0, domains: [] },
  auth_status: { ok: false, status: 'pending', message: 'Login status has not been checked.' },
  capture_gate: null,
};

const browser = await launchOtaPersistentContext(storageDir, args);
payload.cookie_injection = await injectBrowserCookies(browser, args, 'meituan');

const page = await browser.newPage();
await bringLoginPageToFront(page);
registerResponseCapture(page, payload);

try {
  const loginStatus = await ensureLoggedIn(page);
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
  } else if (loginOnly) {
    await holdInteractiveLoginWindow(page, 'Meituan');
  } else if (!loginOnly) {
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

    if (args.adsUrl && wantsSection('ads')) {
      await capturePage(page, 'ads', String(args.adsUrl));
      await collectDomFallback(page, payload, 'ads');
    }

    if (wantsSection('orders')) {
      await capturePage(page, 'orders', URLS.orders);
      await collectDomFallback(page, payload, 'orders');
    }
  }

  dedupePayloadRows(payload);
  payload.capture_gate = loginOnly
    ? { status: loginStatus.ok ? 'pass' : 'fail', failed_check_ids: loginStatus.ok ? [] : ['auth_login_required'], mode: 'login_only' }
    : evaluateCaptureGate(payload);
  if (payload.capture_gate.status !== 'pass') {
    process.exitCode = 2;
  }
  await writeFile(outputPath, JSON.stringify(payload, null, 2), 'utf8');

  if (args.submit === 'true') {
    await submitPayload(payload);
  }

  console.log(JSON.stringify({
    output: outputPath,
    profile_dir: storageDir,
    counts: summarize(payload),
  }, null, 2));
} finally {
  await browser.close();
}

async function ensureLoggedIn(page) {
  await page.goto(URLS.check, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
  await bringLoginPageToFront(page);
  await page.waitForTimeout(2000);
  if (await looksLoggedIn(page)) {
    return { ok: true, status: 'logged_in', url: page.url(), message: 'Meituan profile is logged in.' };
  }

  if (!(await looksLoggedIn(page))) {
    if (isHeadlessMode()) {
      return {
        ok: false,
        status: 'login_required',
        url: page.url(),
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
        ? { ok: true, status: 'logged_in', url: page.url(), message: 'Meituan profile is logged in.' }
        : { ok: false, status: 'login_required', url: page.url(), message: 'Meituan login was not completed.' };
    }

    const timeoutMs = Number(args.loginTimeoutMs || 300000);
    const deadline = Date.now() + Math.max(timeoutMs, 30000);
    while (Date.now() < deadline) {
      await page.waitForTimeout(3000);
      if (await looksLoggedIn(page)) {
        return { ok: true, status: 'logged_in', url: page.url(), message: 'Meituan profile is logged in.' };
      }
    }
    return {
      ok: false,
      status: 'login_required',
      url: page.url(),
      timeout_ms: timeoutMs,
      message: `Meituan login timeout after ${Math.round(timeoutMs / 1000)} seconds`,
    };
  }

  return { ok: true, status: 'logged_in', url: page.url(), message: 'Meituan profile is logged in.' };
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
    : [];
  const screenshot = join(assetDir, `${safeName(storeId)}_${name}_${timestamp()}.png`);
  await page.screenshot({ path: screenshot, fullPage: true }).catch(() => null);
  if (existsSync(screenshot)) {
    payload.screenshots.push({ name, path: screenshot });
  }
  payload.pages.push({ name, url: page.url(), ok: true, interactions });
}

async function runMeituanTrafficInteractionPlan(page) {
  const steps = [
    ['\u6d41\u91cf\u5206\u6790', 'open traffic analysis tab'],
    ['\u6628\u65e5', 'select yesterday traffic period'],
  ];
  const results = [];
  for (const [text, reason] of steps) {
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
      await page.waitForTimeout(1200);
    }
  }
  return results;
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
  page.on('response', async response => {
    const url = response.url();
    const request = response.request();
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

    const status = response.status();
    let body = null;
    try {
      const text = await response.text();
      body = parseResponseBody(text, contentType);
    } catch (error) {
      const responseEvidence = buildOtaCaptureEvidence('meituan', { url, section, captureSource: `xhr:${section}` });
      target.responses.push({ url_hash: responseEvidence.source_url_hash || '', source_trace_id: responseEvidence.source_trace_id || '', section, status, error: error.message });
      return;
    }

    const safeBody = sanitizeOtaPayloadForStorage(body, section);
    const normalizedRows = normalizeCapturedList(safeBody, section, '', requestDateEvidence);
    const rows = section === 'traffic'
      ? normalizedRows.filter(row => isImportableMeituanTrafficRow(row))
      : normalizedRows;
    const responseEvidence = buildOtaCaptureEvidence('meituan', { url, section, captureSource: `xhr:${section}` });
    target.responses.push({ url_hash: responseEvidence.source_url_hash || '', source_trace_id: responseEvidence.source_trace_id || '', section, status, row_count: rows.length, request_date_source: requestDateEvidence.date_source || '', data: safeBody });
    target[section].push(...rows.map(row => {
      row = withMeituanPlatformIdentifier(row);
      return attachOtaCaptureEvidence(row, 'meituan', {
        url,
        section,
        captureSource: row._capture_source || `xhr:${section}`,
      });
    }));
  });
}

function normalizeCaptureSections(value) {
  return new Set(normalizeStandardCaptureSections('meituan', value));
}

function wantsSection(section) {
  return captureSections.has(section);
}

function withMeituanPlatformIdentifier(row) {
  const next = { ...(row || {}) };
  const hasPlatformIdentifier = [
    next.poiId,
    next.poi_id,
    next.storeId,
    next.store_id,
    next.shopId,
    next.shop_id,
    next.partnerId,
    next.partner_id,
  ].some(value => String(value || '').trim() !== '');
  if (!hasPlatformIdentifier) {
    next.storeId = storeId;
    next.store_id = storeId;
  }
  return next;
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
      ['data', 'orders'], ['data', 'list'], ['data', 'orderList'], ['orders'], ['orderList'], ['list'], ['data'],
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
  if (section === 'traffic') {
    if (rowHasDate) {
      datePatch = row.date_source || row.dateSource ? {} : { date_source: 'row' };
    } else if (requestDateEvidence.date) {
      datePatch = { dataDate: requestDateEvidence.date, date_source: requestDateEvidence.date_source || 'request' };
    } else if (defaultDataDate) {
      datePatch = { dataDate: defaultDataDate, date_source: 'capture_context.default_data_date' };
    }
  }
  if (row._source_path) {
    return { ...row, ...datePatch };
  }
  return { ...row, ...datePatch, _source_path: sourcePath || '$' };
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
  for (const section of ['reviews', 'traffic', 'ads', 'orders']) {
    const seen = new Set();
    target[section] = target[section].filter(row => {
      const key = JSON.stringify([
        row.review_id ?? row.reviewId ?? row.commentId ?? '',
        row.order_id ?? row.orderId ?? '',
        row.date ?? row.dataDate ?? row.statDate ?? '',
        row.poi_id ?? row.poiId ?? row.hotel_id ?? row.hotelId ?? '',
        row.source_trace_id ?? row.capture_evidence?.source_trace_id ?? row.source_url_hash ?? row.capture_evidence?.source_url_hash ?? '',
        row._dom_text ?? '',
      ]);
      if (seen.has(key)) {
        return false;
      }
      seen.add(key);
      return true;
    });
  }
}

function evaluateCaptureGate(data) {
  const failed = [];
  const sectionCounts = {
    traffic: data.traffic.length,
    orders: data.orders.length,
    ads: data.ads.length,
    reviews: data.reviews.length,
  };
  const requestedCoreSections = Array.from(captureSections).filter(section => section !== 'reviews');
  const requestedCoreRowCount = requestedCoreSections.reduce((sum, section) => sum + (sectionCounts[section] || 0), 0);
  const capturedResponseCount = data.responses.filter(item => item && item.row_count > 0).length;

  if (!data.auth_status?.ok) {
    failed.push('auth_login_required');
  }
  if (capturedResponseCount === 0 && data.responses.length === 0) {
    failed.push('xhr_not_captured');
  } else if (capturedResponseCount === 0) {
    failed.push('xhr_without_importable_rows');
  }
  if (requestedCoreSections.length > 0 && requestedCoreRowCount === 0) {
    failed.push('no_business_rows');
  }

  return {
    status: failed.length ? 'fail' : 'pass',
    failed_check_ids: failed,
    section_counts: sectionCounts,
    response_count: data.responses.length,
    captured_response_count: capturedResponseCount,
    requested_sections: Array.from(captureSections),
  };
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
