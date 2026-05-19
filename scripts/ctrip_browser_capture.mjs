import { chromium } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import process from 'node:process';

const URLS = {
  business: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true',
  traffic: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
  reviews: 'https://ebooking.ctrip.com/comment/commentList?microJump=true',
};

const KEYWORDS = {
  business: [
    'getDayReportRealTimeDate',
    'fetchMarketOverViewV2',
    'getDayReportFlowCompete',
    'getDayReportServerQuantity',
    'fetchVisitorTitleV2',
    'fetchCapacityOverViewV4',
    'getDayReportCompeteHotelReport',
  ],
  traffic: [
    'queryScanFlowDetailsV2',
    'queryFlowTransforNew',
    'queryHomePageRealTimeData',
    'getFlowData',
    'getTrafficData',
    'getStatData',
  ],
  reviews: ['getCommentList'],
};

const args = parseArgs(process.argv.slice(2));
const profileId = stringValue(args.profileId || args.hotelId || args.systemHotelId || '').trim();
if (!profileId) {
  fail('Missing --profile-id or --hotel-id. Example: node scripts/ctrip_browser_capture.mjs --profile-id=59');
}

const requestedSections = normalizeSections(args.sections || 'business,traffic');
const hotelId = stringValue(args.hotelId || '').trim();
const defaultDataDate = stringValue(args.dataDate || '').trim();
const storageDir = resolve(args.profileDir || join('storage', `ctrip_profile_${safeName(profileId)}`));
const reportDir = resolve(args.reportDir || 'reports');
const assetDir = join(reportDir, 'ctrip_capture_assets');
const outputPath = resolve(args.output || join(reportDir, `ctrip_browser_capture_${safeName(profileId)}_${timestamp()}.json`));
const capturedAt = new Date().toISOString();

await mkdir(storageDir, { recursive: true });
await mkdir(reportDir, { recursive: true });
await mkdir(assetDir, { recursive: true });

const payload = {
  profile_id: profileId,
  hotel_id: hotelId,
  hotel_name: stringValue(args.hotelName || ''),
  system_hotel_id: args.systemHotelId ? Number(args.systemHotelId) : null,
  default_data_date: defaultDataDate,
  source: 'ctrip_browser_profile',
  captured_at: capturedAt,
  page_urls: URLS,
  pages: [],
  responses: [],
  xhr_urls: [],
  business: [],
  traffic: [],
  reviews: [],
  screenshots: [],
};

const launchOptions = {
  headless: args.headless === 'true',
  viewport: { width: 1440, height: 960 },
  locale: 'zh-CN',
};
if (args.chromePath) {
  launchOptions.executablePath = String(args.chromePath);
}

const browser = await chromium.launchPersistentContext(storageDir, launchOptions);
const page = await browser.newPage();
registerResponseCapture(page, payload);

try {
  await ensureLoggedIn(page);
  for (const section of requestedSections) {
    await captureSection(page, section, URLS[section]);
  }
  dedupeRows(payload.business, row => row._fingerprint || JSON.stringify([row.hotelId, row.dataDate, row.amount, row.quantity, row.bookOrderNum]));
  dedupeRows(payload.traffic, row => row._fingerprint || JSON.stringify([row.hotelId, row.date, row.listExposure, row.detailExposure, row.orderFillingNum, row.orderSubmitNum]));
  dedupeRows(payload.reviews, row => row.review_id || JSON.stringify([row.content || '', row.user_name || '', row.comment_time || '']));
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
  await page.goto(URLS.business, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
  await page.waitForTimeout(2000);
  if (await looksLoggedIn(page)) {
    return;
  }

  console.log(`Open Ctrip eBooking login page and complete login. Profile will be saved at ${storageDir}`);
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
  if (/login|passport|account|oauth|sso/i.test(url)) {
    return false;
  }
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  if (/login|password|captcha|verification/i.test(text) && !/data|report|comment|order|ebooking/i.test(text)) {
    return false;
  }
  return true;
}

async function captureSection(page, section, url) {
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
  await clickLikelyRefreshButtons(page);
  await page.evaluate(() => window.scrollTo(0, Math.max(document.body.scrollHeight, document.documentElement.scrollHeight))).catch(() => null);
  await page.waitForTimeout(1200);
  await page.evaluate(() => window.scrollTo(0, 0)).catch(() => null);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => null);

  const screenshot = join(assetDir, `${safeName(profileId)}_${section}_${timestamp()}.png`);
  await page.screenshot({ path: screenshot, fullPage: true }).catch(() => null);
  if (existsSync(screenshot)) {
    payload.screenshots.push({ name: section, path: screenshot });
  }
  payload.pages.push({ name: section, url: page.url(), ok, ...(errorMessage ? { error: errorMessage } : {}) });
}

async function clickLikelyRefreshButtons(page) {
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

function registerResponseCapture(page, target) {
  page.on('response', async response => {
    const requestType = response.request().resourceType();
    if (requestType !== 'xhr' && requestType !== 'fetch') {
      return;
    }

    const url = response.url();
    const urlLower = String(url || '').toLowerCase();
    if (!urlLower.includes('ctrip.com')) {
      return;
    }
    if (target.xhr_urls.length < 200) {
      target.xhr_urls.push({ url, status: response.status(), request_type: requestType });
    }
    if (response.status() !== 200) {
      return;
    }

    const urlSection = classifyByUrl(url);
    if (!urlSection && !urlLower.includes('datacenter') && !urlLower.includes('comment') && !urlLower.includes('review')) {
      return;
    }

    let body = null;
    try {
      const contentType = response.headers()['content-type'] || '';
      const text = await response.text();
      body = parseResponseBody(text, contentType);
    } catch (error) {
      target.responses.push({ url, section: urlSection || 'unknown', status: response.status(), request_type: requestType, error: error.message });
      return;
    }

    const section = urlSection || inferSection(body, url);
    if (!section || !requestedSections.includes(section)) {
      return;
    }

    const rows = normalizeRows(body, section, url);
    target.responses.push({
      url,
      section,
      status: response.status(),
      request_type: requestType,
      keyword_hit: Boolean(urlSection),
      row_count: rows.length,
      data: body,
    });

    if (section === 'business') {
      target.business.push(...rows);
    } else if (section === 'traffic') {
      target.traffic.push(...rows);
    } else if (section === 'reviews') {
      target.reviews.push(...rows);
    }
  });
}

function classifyByUrl(url) {
  const lower = String(url || '').toLowerCase();
  for (const section of ['business', 'traffic', 'reviews']) {
    if (KEYWORDS[section].some(keyword => lower.includes(keyword.toLowerCase()))) {
      return section;
    }
  }
  return '';
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

function parseArgs(argv) {
  const result = {};
  for (const arg of argv) {
    if (!arg.startsWith('--')) {
      continue;
    }
    const [rawKey, ...rest] = arg.slice(2).split('=');
    const key = rawKey.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
    result[key] = rest.length ? rest.join('=') : 'true';
  }
  return result;
}

function normalizeSections(value) {
  const allowed = new Set(['business', 'traffic', 'reviews']);
  const sections = String(value || '')
    .split(',')
    .map(item => item.trim().toLowerCase())
    .filter(item => allowed.has(item));
  return sections.length ? sections : ['business', 'traffic'];
}

function summarize(data) {
  return {
    business: data.business.length,
    traffic: data.traffic.length,
    reviews: data.reviews.length,
    responses: data.responses.length,
  };
}

function timestamp() {
  return new Date().toISOString().replace(/[-:T.Z]/g, '').slice(0, 14);
}

function safeName(value) {
  return String(value || 'default').replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 80);
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
