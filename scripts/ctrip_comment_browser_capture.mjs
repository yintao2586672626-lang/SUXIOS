import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import process from 'node:process';
import { launchOtaPersistentContext } from './lib/cloakbrowser_launcher.mjs';
import { fail, parseArgs, safeName, timestamp, waitForEnter } from './lib/shared_helpers.mjs';

const URLS = {
  comments: 'https://ebooking.ctrip.com/comment/commentList?microJump=true',
  orders: 'https://ebooking.ctrip.com/ebkorderv3?microJump=true',
  im: 'https://ebooking.ctrip.com/im/index?module=replyCustomer&sessionType=human&pageId=10650085973',
};

const args = parseArgs(process.argv.slice(2));
const commentPageUrl = String(args.pageUrl || URLS.comments).trim() || URLS.comments;
const orderPageUrl = String(args.orderPageUrl || URLS.orders).trim() || URLS.orders;
const imPageUrl = String(args.imPageUrl || URLS.im).trim() || URLS.im;
const commentApiKeyword = String(args.apiKeyword || 'getCommentList').trim() || 'getCommentList';
const profileId = String(args.profileId || args.hotelId || args.systemHotelId || '').trim();
if (!profileId) {
  fail('Missing --profile-id or --hotel-id. Example: node scripts/ctrip_comment_browser_capture.mjs --profile-id=store_A');
}

const hotelId = String(args.hotelId || '').trim();
const storageDir = resolve(args.profileDir || join('storage', `ctrip_profile_${safeName(profileId)}`));
const reportDir = resolve(args.reportDir || 'reports');
const assetDir = join(reportDir, 'ctrip_capture_assets');
const outputPath = resolve(args.output || join(reportDir, `ctrip_comment_capture_${safeName(profileId)}_${timestamp()}.json`));
const capturedAt = new Date().toISOString();

await mkdir(storageDir, { recursive: true });
await mkdir(reportDir, { recursive: true });
await mkdir(assetDir, { recursive: true });

const payload = {
  profile_id: profileId,
  hotel_id: hotelId,
  hotel_name: String(args.hotelName || ''),
  system_hotel_id: args.systemHotelId ? Number(args.systemHotelId) : null,
  page_url: commentPageUrl,
  api_keyword: commentApiKeyword,
  captured_at: capturedAt,
  source: 'ctrip_browser_profile',
  pages: [],
  responses: [],
  xhr_urls: [],
  reviews: [],
  orders: [],
  im_sessions: [],
  screenshots: [],
};
let commentRequestTemplate = null;
let orderRequestTemplate = null;
let imGroupRequestTemplate = null;
let imGroupHasNext = false;
let imGroupNextCursor = 0;
let imGroupNextPageIndex = 1;
let imMessageRequestTemplate = null;
let imGroups = [];

const browser = await launchOtaPersistentContext(storageDir, args);
const page = await browser.newPage();
registerResponseCapture(page, payload);

try {
  await ensureLoggedIn(page);
  await captureCommentsPage(page);
  if (!payload.reviews.length) {
    await retryCommentRequests(page);
  }
  if (!payload.reviews.length) {
    await collectDomFallback(page, payload);
  }
  await captureOrdersPage(page);
  await captureImPage(page);
  const commentCutoffMs = resolveImEarliestTimestampMs();
  payload.capture_limits = {
    comment_stop_policy: 'im_earliest_message_time',
    comment_cutoff_time: commentCutoffMs ? new Date(commentCutoffMs).toISOString() : '',
    comment_cutoff_source: commentCutoffMs ? 'im_sessions.message_time_min' : 'missing_im_session_time',
  };
  await fetchAdditionalCommentPages(page, commentCutoffMs);
  dedupeReviews(payload);
  dedupeOrders(payload);
  dedupeImSessions(payload);
  await writeFile(outputPath, JSON.stringify(payload, null, 2), 'utf8');

  console.log(JSON.stringify({
    output: outputPath,
    profile_dir: storageDir,
    counts: summarize(payload),
  }, null, 2));
} finally {
  await browser.close();
}

async function ensureLoggedIn(page) {
  await page.goto(commentPageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
  await page.waitForTimeout(2000);
  if (await looksLoggedIn(page)) {
    return;
  }

  console.log(`Open Ctrip eBooking login page and complete login. Profile will be saved at ${storageDir}`);
  if (args.loginMode === 'manual') {
    await waitForEnter('Press Enter after Ctrip login succeeds...');
    await page.goto(commentPageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
    return;
  }

  const timeoutMs = Number(args.loginTimeoutMs || 300000);
  const deadline = Date.now() + Math.max(timeoutMs, 30000);
  while (Date.now() < deadline) {
    await page.waitForTimeout(3000);
    if (await looksLoggedIn(page)) {
      return;
    }
  }
  throw new Error(`Ctrip login timeout after ${Math.round(timeoutMs / 1000)} seconds`);
}

async function looksLoggedIn(page) {
  const url = page.url();
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  if (/login|passport|account|oauth|sso/i.test(url)) {
    return false;
  }
  if (/登录|验证码|扫码|密码/.test(text) && !/点评|评价|评论|getCommentList|订单|商家|经营|数据/.test(text)) {
    return false;
  }
  return true;
}

async function captureCommentsPage(page) {
  let ok = true;
  let errorMessage = '';
  const firstCommentResponse = waitForCommentListResponse(page, 30000);
  try {
    await page.goto(commentPageUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
  } catch (error) {
    ok = false;
    errorMessage = error.message;
  }

  await firstCommentResponse;
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => null);
  await page.waitForTimeout(3000);
  await page.evaluate(() => window.scrollTo(0, Math.max(document.body.scrollHeight, document.documentElement.scrollHeight))).catch(() => null);
  await page.waitForTimeout(1500);
  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => null);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => null);

  const screenshot = join(assetDir, `${safeName(profileId)}_comments_${timestamp()}.png`);
  await page.screenshot({ path: screenshot, fullPage: true }).catch(() => null);
  if (existsSync(screenshot)) {
    payload.screenshots.push({ name: 'comments', path: screenshot });
  }
  payload.pages.push({ name: 'comments', url: page.url(), ok, ...(errorMessage ? { error: errorMessage } : {}) });
}

function waitForCommentListResponse(page, timeout) {
  return page.waitForResponse(response => classifyCommentResponse(response.url()), { timeout }).catch(() => null);
}

async function fetchAdditionalCommentPages(page, commentCutoffMs) {
  if (!commentRequestTemplate || !commentRequestTemplate.body) {
    payload.responses.push({
      url: '',
      section: 'reviews',
      status: 0,
      request_type: 'fetch',
      stop_reason: 'comment_request_template_missing',
    });
    return;
  }
  if (!commentCutoffMs) {
    payload.responses.push({
      url: commentRequestTemplate.url,
      section: 'reviews',
      status: 0,
      request_type: 'fetch',
      stop_reason: 'im_earliest_time_missing',
    });
    return;
  }

  const configuredLimit = Math.max(1, Math.min(30, Number(args.commentPageLimit || args.comment_page_limit || 20) || 20));
  const totalPages = Math.max(1, Number(commentRequestTemplate.page_count || 0) || configuredLimit);
  const pageLimit = Math.min(configuredLimit, totalPages);
  for (let pageIndex = 2; pageIndex <= pageLimit; pageIndex++) {
    const result = await page.evaluate(async ({ url, body, pageIndex }) => {
      const nextBody = JSON.parse(JSON.stringify(body || {}));
      nextBody.pageIndex = pageIndex;
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nextBody),
      });
      const data = await response.json().catch(() => ({}));
      return { status: response.status, data };
    }, { url: commentRequestTemplate.url, body: commentRequestTemplate.body, pageIndex }).catch(error => ({
      status: 0,
      error: error.message,
      data: null,
    }));

    if (!result || result.error) {
      payload.responses.push({
        url: commentRequestTemplate.url,
        section: 'reviews',
        status: result ? result.status : 0,
        request_type: 'fetch',
        error: result ? result.error : 'unknown_comment_page_fetch_error',
        page_index: pageIndex,
      });
      break;
    }

    const rows = normalizeCommentList(result.data);
    const normalizedRows = rows.map(row => normalizeCommentRow(row, commentRequestTemplate.url, 'fetch:getCommentList'));
    const rowsWithTime = normalizedRows
      .map(row => extractReviewTimestampMs(row))
      .filter(time => time > 0);
    if (rows.length && !rowsWithTime.length) {
      payload.responses.push({
        url: commentRequestTemplate.url,
        section: 'reviews',
        status: result.status,
        request_type: 'fetch',
        row_count: rows.length,
        kept_count: 0,
        page_index: pageIndex,
        cutoff_time: new Date(commentCutoffMs).toISOString(),
        stop_reason: 'comment_time_unavailable',
      });
      break;
    }

    const keptRows = normalizedRows.filter(row => {
      const reviewTime = extractReviewTimestampMs(row);
      return reviewTime > 0 && reviewTime >= commentCutoffMs;
    });
    payload.responses.push({
      url: commentRequestTemplate.url,
      section: 'reviews',
      status: result.status,
      request_type: 'fetch',
      row_count: rows.length,
      kept_count: keptRows.length,
      page_index: pageIndex,
      cutoff_time: new Date(commentCutoffMs).toISOString(),
    });
    payload.reviews.push(...keptRows);

    if (!rows.length || keptRows.length < normalizedRows.length) {
      payload.responses.push({
        url: commentRequestTemplate.url,
        section: 'reviews',
        status: result.status,
        request_type: 'fetch',
        page_index: pageIndex,
        stop_reason: !rows.length ? 'empty_comment_page' : 'im_earliest_time_reached',
      });
      break;
    }
  }
}

async function captureOrdersPage(page) {
  const orderPageLimit = Math.max(1, Math.min(120, Number(args.orderPageLimit || args.order_page_limit || 80) || 80));
  let ok = true;
  let errorMessage = '';
  const firstOrderResponse = waitForOrderListResponse(page, 30000);
  try {
    await page.goto(orderPageUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
  } catch (error) {
    ok = false;
    errorMessage = error.message;
  }

  await firstOrderResponse;
  await page.waitForTimeout(800);
  await fetchAdditionalOrderPages(page, orderPageLimit);
  payload.pages.push({ name: 'orders', url: page.url(), ok, ...(errorMessage ? { error: errorMessage } : {}) });
}

function waitForOrderListResponse(page, timeout) {
  return page.waitForResponse(response => /queryOrderList/i.test(response.url()), { timeout }).catch(() => null);
}

async function fetchAdditionalOrderPages(page, orderPageLimit) {
  if (!orderRequestTemplate || !orderRequestTemplate.body) {
    return;
  }
  const firstPageSize = Math.max(1, Number(orderRequestTemplate.row_count || 10) || 10);
  const totalPages = Math.max(1, Math.ceil(Number(orderRequestTemplate.total || 0) / firstPageSize) || orderPageLimit);
  const pageLimit = Math.min(orderPageLimit, totalPages);
  for (let pageIndex = 1; pageIndex < pageLimit; pageIndex++) {
    const result = await page.evaluate(async ({ url, body, pageIndex }) => {
      const nextBody = JSON.parse(JSON.stringify(body || {}));
      nextBody.orderQueryCondition = nextBody.orderQueryCondition || {};
      nextBody.orderQueryCondition.pageInfo = nextBody.orderQueryCondition.pageInfo || {};
      nextBody.orderQueryCondition.pageInfo.pageIndex = pageIndex;
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nextBody),
      });
      const data = await response.json().catch(() => ({}));
      return { status: response.status, data };
    }, { url: orderRequestTemplate.url, body: orderRequestTemplate.body, pageIndex }).catch(error => ({
      status: 0,
      error: error.message,
      data: null,
    }));

    if (!result || result.error) {
      payload.responses.push({ url: orderRequestTemplate.url, section: 'orders', status: result ? result.status : 0, request_type: 'fetch', error: result ? result.error : 'unknown_order_page_fetch_error', page_index: pageIndex });
      break;
    }
    const rows = normalizeOrderList(result.data);
    payload.responses.push({ url: orderRequestTemplate.url, section: 'orders', status: result.status, request_type: 'fetch', row_count: rows.length, page_index: pageIndex });
    payload.orders.push(...rows.map(row => normalizeOrderRow(row, orderRequestTemplate.url)));
    if (!rows.length) {
      break;
    }
  }
}

async function captureImPage(page) {
  let ok = true;
  let errorMessage = '';
  try {
    await page.goto(imPageUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
  } catch (error) {
    ok = false;
    errorMessage = error.message;
  }

  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => null);
  await page.waitForTimeout(5000);
  await clickImShowAll(page);
  await page.waitForTimeout(8000);
  await fetchAdditionalImGroups(page);
  await fetchAdditionalImSessions(page);
  payload.pages.push({ name: 'im', url: page.url(), ok, ...(errorMessage ? { error: errorMessage } : {}) });
}

async function clickImShowAll(page) {
  await page.evaluate(() => {
    const label = '\u67e5\u770b\u5168\u90e8';
    const buttons = Array.from(document.querySelectorAll('button'));
    const target = buttons.find(el => String(el.innerText || el.textContent || '').trim() === label);
    if (target) {
      target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
    }
  }).catch(() => null);
}

async function retryCommentRequests(page) {
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => null);
  await page.waitForTimeout(3000);

  const triggerSelectors = [
    'button:has-text("查询")',
    'button:has-text("搜索")',
    'button:has-text("筛选")',
    'text=全部点评',
    'text=点评列表',
  ];
  for (const selector of triggerSelectors) {
    const target = page.locator(selector).first();
    if (await target.isVisible({ timeout: 1000 }).catch(() => false)) {
      await target.click({ timeout: 3000 }).catch(() => null);
      await page.waitForTimeout(2500);
      if (payload.reviews.length) {
        break;
      }
    }
  }
}

function registerResponseCapture(page, target) {
  page.on('response', async response => {
    const requestType = response.request().resourceType();
    if (requestType !== 'xhr' && requestType !== 'fetch') {
      return;
    }

    const url = response.url();
    const urlLower = String(url || '').toLowerCase();
    if (target.xhr_urls.length < 120 && urlLower.includes('ctrip.com')) {
      target.xhr_urls.push({ url, status: response.status(), request_type: requestType });
    }

    const keywordHit = classifyCommentResponse(url);
    const commentPageRelated = urlLower.includes('ctrip.com') && (urlLower.includes('comment') || urlLower.includes('review'));
    const orderListHit = urlLower.includes('queryorderlist');
    const imMessageHit = /querymessagelistbygroupid|querymessagelistv2/i.test(url);
    const imGroupListHit = urlLower.includes('getimgrouplistv2');
    if (!keywordHit && !commentPageRelated && !orderListHit && !imMessageHit && !imGroupListHit) {
      return;
    }

    const status = response.status();
    let body = null;
    try {
      const contentType = response.headers()['content-type'] || '';
      const text = await response.text();
      body = parseResponseBody(text, contentType);
    } catch (error) {
      target.responses.push({ url, section: 'unknown', status, request_type: requestType, error: error.message });
      return;
    }

    if (orderListHit) {
      const rows = normalizeOrderList(body);
      if (rows.length) {
        captureOrderRequestTemplate(response.request(), body, rows.length);
        target.responses.push({ url, section: 'orders', status, request_type: requestType, row_count: rows.length });
        target.orders.push(...rows.map(row => normalizeOrderRow(row, url)));
      }
    }

    if (imMessageHit) {
      captureImMessageRequestTemplate(response.request());
      const session = normalizeImSession(body, response.request().postData(), url);
      target.responses.push({
        url,
        section: 'im_sessions',
        status,
        request_type: requestType,
        row_count: session && session.members ? session.members.length : 0,
        group_id_present: Boolean(session && session.groupId),
      });
      if (session) {
        target.im_sessions.push(session);
      }
    }

    if (imGroupListHit) {
      captureImGroupRequestTemplate(response.request(), body);
      captureImGroups(body);
      target.responses.push({
        url,
        section: 'im_groups',
        status,
        request_type: requestType,
        row_count: Array.isArray(body.groupList) ? body.groupList.length : 0,
      });
    }

    if (keywordHit || commentPageRelated) {
      const commentRequestBody = keywordHit ? parseMaybeJsonObject(response.request().postData()) : {};
      const commentPageIndex = Number(commentRequestBody && commentRequestBody.pageIndex ? commentRequestBody.pageIndex : 1) || 1;
      if (keywordHit && commentPageIndex > 1) {
        return;
      }
      const rows = normalizeCommentList(body);
      if (!keywordHit && !rows.length && !looksLikeCommentPayload(body)) {
        return;
      }
      if (keywordHit && rows.length) {
        captureCommentRequestTemplate(response.request(), body, rows.length);
      }
      target.responses.push({ url, section: 'reviews', status, request_type: requestType, keyword_hit: keywordHit, row_count: rows.length, data: body });
      target.reviews.push(...rows.map(row => normalizeCommentRow(row, url, keywordHit ? 'xhr:getCommentList' : 'xhr:comment-json')));
    }
  });
}

function captureCommentRequestTemplate(request, body, rowCount) {
  if (commentRequestTemplate) {
    return;
  }
  const requestBody = parseMaybeJsonObject(request.postData());
  if (!requestBody || typeof requestBody !== 'object' || !Object.prototype.hasOwnProperty.call(requestBody, 'pageIndex')) {
    return;
  }
  commentRequestTemplate = {
    url: request.url(),
    body: requestBody,
    row_count: rowCount,
    current_page: Number(body && body.currentPageIndex ? body.currentPageIndex : requestBody.pageIndex) || 1,
    page_count: Number(body && body.pageCount ? body.pageCount : 0) || 0,
    total: Number(body && body.commentCount ? body.commentCount : 0) || 0,
  };
}

function captureImGroupRequestTemplate(request, body) {
  const groups = Array.isArray(body && body.groupList) ? body.groupList : [];
  if (!groups.length) {
    return;
  }
  const requestBody = parseMaybeJsonObject(request.postData());
  if (!requestBody || typeof requestBody !== 'object' || !Object.prototype.hasOwnProperty.call(requestBody, 'pageCursor')) {
    return;
  }
  imGroupRequestTemplate = {
    url: request.url(),
    body: requestBody,
  };
}

function captureImMessageRequestTemplate(request) {
  if (imMessageRequestTemplate) {
    return;
  }
  const body = parseMaybeJsonObject(request.postData());
  if (!body || typeof body !== 'object' || !body.groupId) {
    return;
  }
  imMessageRequestTemplate = {
    url: request.url(),
    body,
  };
}

function captureImGroups(body) {
  const groups = Array.isArray(body && body.groupList) ? body.groupList : [];
  for (const group of groups) {
    if (!group || typeof group !== 'object') {
      continue;
    }
    const groupId = stringValue(group.groupId);
    const sessionId = stringValue(group.sessionId);
    if (!groupId) {
      continue;
    }
    imGroups.push({
      groupId,
      sessionId,
      bizType: stringValue(group.bizType),
      verfId: stringValue(group.verfId || group.masterHotelId),
    });
  }
  const seen = new Set();
  imGroups = imGroups.filter(group => {
    const key = group.groupId;
    if (!key || seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
  if (Object.prototype.hasOwnProperty.call(body || {}, 'isHaveNextPage')) {
    imGroupHasNext = Boolean(body.isHaveNextPage);
  }
  if (Number.isFinite(Number(body && body.pageCursor))) {
    imGroupNextCursor = Number(body.pageCursor);
  }
}

async function fetchAdditionalImGroups(page) {
  if (!imGroupRequestTemplate || !imGroupHasNext) {
    return;
  }
  const groupListPageLimit = Math.max(1, Math.min(20, Number(args.imGroupPageLimit || args.im_group_page_limit || 8) || 8));
  for (let index = 0; index < groupListPageLimit && imGroupHasNext; index++) {
    const result = await page.evaluate(async ({ url, body, pageCursor, pageIndex }) => {
      const nextBody = JSON.parse(JSON.stringify(body || {}));
      nextBody.pageCursor = pageCursor;
      nextBody.pageIndex = pageIndex;
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nextBody),
      });
      const data = await response.json().catch(() => ({}));
      return { status: response.status, data };
    }, {
      url: imGroupRequestTemplate.url,
      body: imGroupRequestTemplate.body,
      pageCursor: imGroupNextCursor,
      pageIndex: imGroupNextPageIndex,
    }).catch(error => ({
      status: 0,
      error: error.message,
      data: null,
    }));

    if (!result || result.error) {
      payload.responses.push({ url: imGroupRequestTemplate.url, section: 'im_groups', status: result ? result.status : 0, request_type: 'fetch', error: result ? result.error : 'unknown_im_group_fetch_error' });
      break;
    }
    const before = imGroups.length;
    captureImGroups(result.data);
    payload.responses.push({
      url: imGroupRequestTemplate.url,
      section: 'im_groups',
      status: result.status,
      request_type: 'fetch',
      row_count: Math.max(0, imGroups.length - before),
      page_index: imGroupNextPageIndex,
    });
    imGroupNextPageIndex++;
    if (!Array.isArray(result.data && result.data.groupList) || result.data.groupList.length === 0) {
      break;
    }
  }
}

async function fetchAdditionalImSessions(page) {
  if (!imMessageRequestTemplate || !imGroups.length) {
    return;
  }
  const groupLimit = Math.max(1, Math.min(120, Number(args.imGroupLimit || args.im_group_limit || 80) || 80));
  for (const group of imGroups.slice(0, groupLimit)) {
    if (payload.im_sessions.some(session => String(session.groupId || '') === group.groupId)) {
      continue;
    }
    const result = await page.evaluate(async ({ url, body, group }) => {
      const nextBody = JSON.parse(JSON.stringify(body || {}));
      nextBody.groupId = group.groupId;
      if (group.sessionId) nextBody.sessionId = group.sessionId;
      if (group.bizType) nextBody.bizType = group.bizType;
      if (group.verfId) nextBody.verfId = group.verfId;
      nextBody.endMsgTime = 0;
      nextBody.includeEndMsg = false;
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nextBody),
      });
      const data = await response.json().catch(() => ({}));
      return { status: response.status, data };
    }, { url: imMessageRequestTemplate.url, body: imMessageRequestTemplate.body, group }).catch(error => ({
      status: 0,
      error: error.message,
      data: null,
    }));

    if (!result || result.error) {
      payload.responses.push({ url: imMessageRequestTemplate.url, section: 'im_sessions', status: result ? result.status : 0, request_type: 'fetch', error: result ? result.error : 'unknown_im_session_fetch_error' });
      continue;
    }
    const session = normalizeImSession(result.data, { groupId: group.groupId, sessionId: group.sessionId, bizType: group.bizType, verfId: group.verfId }, imMessageRequestTemplate.url);
    payload.responses.push({
      url: imMessageRequestTemplate.url,
      section: 'im_sessions',
      status: result.status,
      request_type: 'fetch',
      row_count: session && session.members ? session.members.length : 0,
      group_id_present: Boolean(session && session.groupId),
    });
    if (session) {
      payload.im_sessions.push(session);
    }
  }
}

function captureOrderRequestTemplate(request, body, rowCount) {
  if (orderRequestTemplate) {
    return;
  }
  const requestBody = parseMaybeJsonObject(request.postData());
  if (!requestBody || typeof requestBody !== 'object' || !requestBody.orderQueryCondition) {
    return;
  }
  orderRequestTemplate = {
    url: request.url(),
    body: requestBody,
    row_count: rowCount,
    total: Number(body && body.total ? body.total : 0) || 0,
  };
}

function classifyCommentResponse(url) {
  return String(url || '').toLowerCase().includes(commentApiKeyword.toLowerCase());
}

function looksLikeCommentPayload(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  const text = JSON.stringify(value).slice(0, 5000);
  return /commentList|comments|reviewId|commentId|commentContent|reviewContent/i.test(text);
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

function normalizeCommentList(value) {
  if (!value || typeof value !== 'object') {
    return [];
  }
  if (Array.isArray(value)) {
    return value.filter(item => item && typeof item === 'object');
  }

  const paths = [
    ['data', 'commentList'],
    ['data', 'commentlist'],
    ['data', 'comments'],
    ['data', 'list'],
    ['data', 'rows'],
    ['result', 'commentList'],
    ['result', 'commentlist'],
    ['result', 'comments'],
    ['result', 'list'],
    ['commentList'],
    ['commentlist'],
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
  const rawScore = firstValue(row, ['score', 'rating', 'rate', 'totalScore', 'overallScore', 'commentScore', 'star']);
  const score = normalizeScore(rawScore);
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

function normalizeOrderList(value) {
  if (!value || typeof value !== 'object') {
    return [];
  }
  if (Array.isArray(value)) {
    return value.filter(item => item && typeof item === 'object');
  }

  const paths = [
    ['data', 'orderList'],
    ['data', 'orders'],
    ['data', 'list'],
    ['result', 'orderList'],
    ['result', 'orders'],
    ['orderList'],
    ['orders'],
    ['list'],
  ];

  for (const path of paths) {
    const nested = readPath(value, path);
    const rows = normalizeOrderList(nested);
    if (rows.length) {
      return rows;
    }
  }

  return looksLikeOrderRow(value) ? [value] : [];
}

function looksLikeOrderRow(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  return ['orderId', 'orderNo', 'bookingOrderId', 'reservationOrderId', 'roomName', 'arrival', 'clientName']
    .some(key => Object.prototype.hasOwnProperty.call(value, key));
}

function normalizeOrderRow(row, sourceUrl) {
  const orderId = stringValue(firstValue(row, ['orderId', 'order_id', 'orderNo', 'order_no', 'orderSn', 'order_sn', 'bookingOrderId', 'booking_order_id', 'reservationOrderId', 'reservation_order_id']));
  const guestName = stringValue(firstValue(row, ['guestName', 'guest_name', 'customerName', 'customer_name', 'contactName', 'contact_name', 'clientName', 'client_name']));
  const arrivalDate = stringValue(firstValue(row, ['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in', 'checkinTime', 'checkin_time', 'stayDate', 'stay_date']));
  const departureDate = stringValue(firstValue(row, ['departureDate', 'departure_date', 'departure', 'checkOutDate', 'check_out_date', 'checkOut', 'check_out', 'checkoutTime', 'checkout_time']));
  const roomName = stringValue(firstValue(row, ['roomName', 'room_name', 'roomType', 'room_type', 'room_type_name', 'productName', 'product_name', 'ratePlanName', 'rate_plan_name']));
  const orderStatus = stringValue(firstValue(row, ['orderStatus', 'order_status', 'orderStatusDesc', 'order_status_desc', 'orderState', 'order_state', 'status']));

  return {
    orderId,
    guestName,
    clientName: guestName,
    arrivalDate,
    departureDate,
    roomName,
    orderStatus,
    source_platform: 'ctrip',
    _source_url: sourceUrl,
    _capture_source: 'xhr:queryOrderList',
  };
}

function normalizeImSession(body, requestBody, sourceUrl) {
  const request = parseMaybeJsonObject(requestBody);
  const groupId = findTextDeep(request, ['groupId', 'group_id', 'groupID', 'sessionGroupId'])
    || findTextDeep(body, ['groupId', 'group_id', 'groupID', 'sessionGroupId']);
  const members = findMembers(body);
  if (!groupId || !members.length) {
    return null;
  }
  const messageTimes = collectTimestampMs(body);
  const messageTimeMin = messageTimes.length ? Math.min(...messageTimes) : 0;
  const messageTimeMax = messageTimes.length ? Math.max(...messageTimes) : 0;

  return {
    groupId,
    members,
    orderId: findTextDeep(body, ['orderId', 'order_id', 'orderNo', 'order_no']),
    arrivalDate: findTextDeep(body, ['arrivalDate', 'arrival_date', 'arrival', 'checkInDate', 'check_in_date', 'checkIn', 'check_in']),
    roomName: findTextDeep(body, ['roomName', 'room_name', 'roomType', 'room_type']),
    message_time_min: messageTimeMin ? new Date(messageTimeMin).toISOString() : '',
    message_time_max: messageTimeMax ? new Date(messageTimeMax).toISOString() : '',
    source: 'ctrip_authorized_profile_queryMessageListV2',
    _source_url: sourceUrl,
  };
}

function resolveImEarliestTimestampMs() {
  const times = [];
  for (const session of payload.im_sessions || []) {
    const minTime = parseTimestampMs(session && session.message_time_min);
    const maxTime = parseTimestampMs(session && session.message_time_max);
    if (minTime) times.push(minTime);
    if (maxTime) times.push(maxTime);
  }
  return times.length ? Math.min(...times) : 0;
}

function extractReviewTimestampMs(row) {
  const value = firstValue(row || {}, [
    'addtime',
    'addTime',
    'comment_time',
    'commentTime',
    'review_time',
    'reviewTime',
    'reviewDate',
    'review_date',
    'createTime',
    'create_time',
    'submitTime',
    'submit_time',
    'publishTime',
    'publish_time',
    'date',
  ]);
  return parseTimestampMs(value);
}

function collectTimestampMs(value, depth = 0, parentKey = '') {
  if (depth > 8 || value === null || value === undefined) {
    return [];
  }
  const times = [];
  if (isTimestampKey(parentKey)) {
    const time = parseTimestampMs(value);
    if (time) {
      times.push(time);
    }
  }
  if (Array.isArray(value)) {
    for (const item of value) {
      times.push(...collectTimestampMs(item, depth + 1, parentKey));
    }
    return times;
  }
  if (typeof value !== 'object') {
    return times;
  }
  for (const [key, child] of Object.entries(value)) {
    times.push(...collectTimestampMs(child, depth + 1, key));
  }
  return Array.from(new Set(times));
}

function isTimestampKey(key) {
  const lower = String(key || '').toLowerCase();
  if (!lower) {
    return false;
  }
  if (/timezone|timeout|runtime|elapsed|duration|page|arrival|departure|checkin|checkout/.test(lower)) {
    return false;
  }
  return /time|date|timestamp|addtime|sendtime|msgtime|createtime|updatetime|lastmsgtime|endmsgtime|startmsgtime/.test(lower);
}

function parseTimestampMs(value) {
  if (value === null || value === undefined || value === '') {
    return 0;
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    return normalizeTimestampNumber(value);
  }
  const text = String(value || '').trim();
  if (!text) {
    return 0;
  }
  const ctripDate = text.match(/\/Date\((\d{10,13})(?:[+-]\d{4})?\)\//);
  if (ctripDate) {
    return normalizeTimestampNumber(Number(ctripDate[1]));
  }
  if (/^\d{10,13}$/.test(text)) {
    return normalizeTimestampNumber(Number(text));
  }
  const normalized = text
    .replace(/年/g, '-')
    .replace(/月/g, '-')
    .replace(/日/g, ' ')
    .replace(/\//g, '-')
    .replace(/\s+/g, ' ')
    .trim();
  const time = Date.parse(normalized);
  return Number.isFinite(time) ? normalizeTimestampNumber(time) : 0;
}

function normalizeTimestampNumber(value) {
  if (!Number.isFinite(value) || value <= 0) {
    return 0;
  }
  const milliseconds = value < 10000000000 ? value * 1000 : value;
  const min = Date.UTC(2018, 0, 1);
  const max = Date.UTC(2100, 0, 1);
  return milliseconds >= min && milliseconds <= max ? milliseconds : 0;
}

function parseMaybeJsonObject(value) {
  if (!value) {
    return {};
  }
  if (typeof value === 'object') {
    return value;
  }
  const text = String(value || '').trim();
  if (!text) {
    return {};
  }
  try {
    return JSON.parse(text);
  } catch {
    try {
      return Object.fromEntries(new URLSearchParams(text).entries());
    } catch {
      return {};
    }
  }
}

function findTextDeep(value, keys, depth = 0) {
  if (depth > 6 || !value || typeof value !== 'object') {
    return '';
  }
  for (const key of keys) {
    const text = stringValue(value[key]);
    if (text) {
      return text;
    }
  }
  for (const child of Object.values(value)) {
    const text = findTextDeep(child, keys, depth + 1);
    if (text) {
      return text;
    }
  }
  return '';
}

function findMembers(value, depth = 0) {
  if (depth > 6 || !value || typeof value !== 'object') {
    return [];
  }
  for (const key of ['members', 'memberList', 'member_list', 'imMembers', 'users', 'userList']) {
    const members = normalizeMembers(value[key]);
    if (members.length) {
      return members;
    }
  }
  if (Array.isArray(value)) {
    const members = normalizeMembers(value);
    if (members.length) {
      return members;
    }
  }
  for (const child of Object.values(value)) {
    const members = findMembers(child, depth + 1);
    if (members.length) {
      return members;
    }
  }
  return [];
}

function normalizeMembers(value) {
  if (Array.isArray(value)) {
    return value.filter(item => item && typeof item === 'object').map((item, index) => normalizeMember(item, String(index))).filter(Boolean);
  }
  if (value && typeof value === 'object') {
    return Object.entries(value).map(([uid, member]) => {
      if (member && typeof member === 'object') {
        return normalizeMember({ uid: member.uid || uid, ...member }, uid);
      }
      return normalizeMember({ uid, nickName: String(member || '') }, uid);
    }).filter(Boolean);
  }
  return [];
}

function normalizeMember(member, fallbackUid) {
  const uid = stringValue(firstValue(member, ['uid', 'userId', 'user_id', 'memberUid', 'member_uid'], fallbackUid));
  const nickName = stringValue(firstValue(member, ['nickName', 'nickname', 'nick_name', 'name', 'guestName', 'guest_name']));
  const roleType = stringValue(firstValue(member, ['roleType', 'role_type', 'role', 'userType', 'user_type']));
  const avatar = stringValue(firstValue(member, ['pic', 'avatar', 'avatarUrl', 'avatar_url']));
  if (!uid && !nickName) {
    return null;
  }
  return {
    uid,
    nickName,
    roleType,
    pic: avatar,
  };
}

async function collectDomFallback(page, target) {
  const rows = await page.evaluate(() => {
    const textOf = node => (node?.innerText || node?.textContent || '').trim().replace(/\s+/g, ' ');
    const dateFromText = text => {
      const match = text.match(/(20\d{2}[-/.年]\d{1,2}[-/.月]\d{1,2})/);
      return match ? match[1].replace(/[年月/.]/g, '-').replace(/日/g, '') : '';
    };
    const scoreFromText = text => {
      const match = text.match(/([1-5](?:\.\d)?)\s*分/);
      return match ? Number(match[1]) : 0;
    };
    const selectors = [
      '[class*="comment"]',
      '[class*="Comment"]',
      '[class*="review"]',
      '[class*="Review"]',
      '[class*="evaluate"]',
      '.ant-list-item',
      'table tbody tr',
    ];
    const nodes = Array.from(new Set(selectors.flatMap(selector => Array.from(document.querySelectorAll(selector)))));
    return nodes.slice(0, 120).map((node, index) => {
      const text = textOf(node);
      const score = scoreFromText(text);
      return {
        review_id: node.getAttribute('data-comment-id') || node.getAttribute('data-id') || node.id || '',
        score,
        content: text,
        reply: /回复[:：]/.test(text) ? text : '',
        has_reply: /回复|已回复/.test(text),
        is_negative: score > 0 ? score < 4 : /差评|不满意|投诉|脏|吵|差/.test(text),
        user_name: '',
        room_type: '',
        check_in_date: '',
        comment_time: dateFromText(text),
        tags: [],
        _dom_index: index,
        _dom_text: text,
        _capture_source: 'dom:commentList',
      };
    }).filter(row => row._dom_text && row._dom_text.length > 12);
  });

  target.reviews.push(...rows);
}

function dedupeReviews(target) {
  const seen = new Set();
  target.reviews = target.reviews.filter(row => {
    const key = row.review_id
      || JSON.stringify([row.content || '', row.user_name || '', row.comment_time || '', row._dom_text || '']);
    if (!key || seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

function dedupeOrders(target) {
  const seen = new Set();
  target.orders = target.orders.filter(row => {
    const key = row.orderId || JSON.stringify([row.guestName || '', row.arrivalDate || '', row.roomName || '']);
    if (!key || seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

function dedupeImSessions(target) {
  const seen = new Set();
  target.im_sessions = target.im_sessions.filter(row => {
    const key = row.groupId || JSON.stringify(row.members || []);
    if (!key || seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

function firstValue(data, keys, fallback = '') {
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(data, key) && data[key] !== null && data[key] !== '') {
      return data[key];
    }
  }
  return fallback;
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
  if (typeof value === 'string') {
    value = value.replace(/[,分]/g, '').trim();
  }
  if (!Number.isFinite(Number(value))) {
    return 0;
  }
  const score = Number(value);
  if (score > 5 && score <= 50) {
    return Math.round((score / 10) * 10) / 10;
  }
  if (score > 50 && score <= 100) {
    return Math.round((score / 20) * 10) / 10;
  }
  return Math.round(score * 10) / 10;
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
    return value.split(/[,，、|]/).map(item => item.trim()).filter(Boolean);
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
  return ['1', 'true', 'yes', 'y', '已回复', '是'].includes(String(value).trim().toLowerCase());
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

function summarize(data) {
  return {
    reviews: data.reviews.length,
    orders: data.orders.length,
    im_sessions: data.im_sessions.length,
    responses: data.responses.length,
  };
}
