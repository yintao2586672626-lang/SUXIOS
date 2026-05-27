import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import readline from 'node:readline/promises';
import process from 'node:process';
import { launchOtaPersistentContext } from './lib/cloakbrowser_launcher.mjs';

const URLS = {
  comments: 'https://ebooking.ctrip.com/comment/commentList?microJump=true',
};

const args = parseArgs(process.argv.slice(2));
const commentPageUrl = String(args.pageUrl || URLS.comments).trim() || URLS.comments;
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
  screenshots: [],
};

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
  dedupeReviews(payload);
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
  try {
    await page.goto(commentPageUrl, { waitUntil: 'domcontentloaded', timeout: 90000 });
  } catch (error) {
    ok = false;
    errorMessage = error.message;
  }

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
    if (!keywordHit && !commentPageRelated) {
      return;
    }

    const status = response.status();
    let body = null;
    try {
      const contentType = response.headers()['content-type'] || '';
      const text = await response.text();
      body = parseResponseBody(text, contentType);
    } catch (error) {
      target.responses.push({ url, section: 'reviews', status, request_type: requestType, error: error.message });
      return;
    }

    const rows = normalizeCommentList(body);
    if (!keywordHit && !rows.length && !looksLikeCommentPayload(body)) {
      return;
    }
    target.responses.push({ url, section: 'reviews', status, request_type: requestType, keyword_hit: keywordHit, row_count: rows.length, data: body });
    target.reviews.push(...rows.map(row => normalizeCommentRow(row, url, keywordHit ? 'xhr:getCommentList' : 'xhr:comment-json')));
  });
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

function summarize(data) {
  return {
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

async function waitForEnter(prompt) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  try {
    await rl.question(prompt);
  } finally {
    rl.close();
  }
}

function fail(message) {
  console.error(message);
  process.exit(1);
}
