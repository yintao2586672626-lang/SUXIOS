import { chromium } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';
import readline from 'node:readline/promises';
import process from 'node:process';

const URLS = {
  login: 'https://me.meituan.com/ebooking/',
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
const outputPath = resolve(args.output || join(reportDir, `meituan_capture_${safeName(storeId)}_${timestamp()}.json`));
const captureSections = normalizeCaptureSections(args.sections || args.captureSections || args.only || 'all');

await mkdir(storageDir, { recursive: true });
await mkdir(reportDir, { recursive: true });
await mkdir(assetDir, { recursive: true });

const payload = {
  store_id: storeId,
  poi_id: String(args.poiId || ''),
  poi_name: String(args.poiName || ''),
  system_hotel_id: args.systemHotelId ? Number(args.systemHotelId) : null,
  captured_at: capturedAt,
  source: 'meituan_browser_profile',
  capture_sections: Array.from(captureSections),
  pages: [],
  responses: [],
  reviews: [],
  traffic: [],
  ads: [],
  orders: [],
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

  dedupePayloadRows(payload);
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
  await page.goto(URLS.login, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  if (!(await looksLoggedIn(page))) {
    console.log(`Open login page and complete Meituan login. Profile will be saved at ${storageDir}`);
    if (args.loginMode === 'manual') {
      await waitForEnter('Press Enter after login succeeds...');
      await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
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
    throw new Error(`Meituan login timeout after ${Math.round(timeoutMs / 1000)} seconds`);
  }
}

async function looksLoggedIn(page) {
  const url = page.url();
  const text = await page.locator('body').innerText({ timeout: 5000 }).catch(() => '');
  if (/login|passport|account/i.test(url)) {
    return false;
  }
  if (/登录|验证码|扫码/.test(text) && !/订单|点评|商家|经营|数据/.test(text)) {
    return false;
  }
  return true;
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
  const screenshot = join(assetDir, `${safeName(storeId)}_${name}_${timestamp()}.png`);
  await page.screenshot({ path: screenshot, fullPage: true }).catch(() => null);
  if (existsSync(screenshot)) {
    payload.screenshots.push({ name, path: screenshot });
  }
  payload.pages.push({ name, url: page.url(), ok: true });
}

function registerResponseCapture(page, target) {
  page.on('response', async response => {
    const url = response.url();
    const section = classifyResponse(url);
    if (!section || !wantsSection(section)) {
      return;
    }

    const status = response.status();
    let body = null;
    try {
      const contentType = response.headers()['content-type'] || '';
      const text = await response.text();
      body = parseResponseBody(text, contentType);
    } catch (error) {
      target.responses.push({ url, section, status, error: error.message });
      return;
    }

    const rows = normalizeCapturedList(body, section);
    target.responses.push({ url, section, status, row_count: rows.length, data: body });
    target[section].push(...rows.map(row => ({ ...row, _source_url: url })));
  });
}

function normalizeCaptureSections(value) {
  const raw = String(value || 'all').trim();
  if (!raw || raw === 'all' || raw === '*') {
    return new Set(['reviews', 'traffic', 'ads', 'orders']);
  }

  const aliases = {
    review: 'reviews',
    reviews: 'reviews',
    comment: 'reviews',
    comments: 'reviews',
    traffic: 'traffic',
    flow: 'traffic',
    ads: 'ads',
    ad: 'ads',
    advertising: 'ads',
    orders: 'orders',
    order: 'orders',
  };
  const selected = raw.split(/[,\s]+/)
    .map(item => aliases[item.trim().toLowerCase()] || '')
    .filter(Boolean);
  return new Set(selected.length ? selected : ['reviews', 'traffic', 'ads', 'orders']);
}

function wantsSection(section) {
  return captureSections.has(section);
}

function classifyResponse(url) {
  const value = url.toLowerCase();
  if (value.includes('querygeneralcommentinfo') || value.includes('commentsinfo') || value.includes('comments/statistics')) {
    return 'reviews';
  }
  if (value.includes('businessdata') || value.includes('traffic') || value.includes('peertrends')) {
    return 'traffic';
  }
  if (value.includes('cureshops')) {
    return 'ads';
  }
  if (value.includes('/orders/list') || value.includes('/order/unhandled/count')) {
    return 'orders';
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

function normalizeCapturedList(value, section) {
  if (!value || typeof value !== 'object') {
    return [];
  }
  if (Array.isArray(value)) {
    return value.filter(item => item && typeof item === 'object');
  }

  const paths = {
    reviews: [
      ['data', 'commentList'], ['data', 'comments'], ['data', 'list'], ['commentList'], ['comments'], ['list'], ['data'],
    ],
    traffic: [
      ['data', 'businessData'], ['data', 'traffic'], ['data', 'peerTrends'], ['data', 'list'], ['data', 'rows'],
      ['businessData'], ['traffic'], ['peerTrends'], ['list'], ['rows'], ['data'],
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
    const rows = normalizeCapturedList(nested, section);
    if (rows.length) {
      return rows;
    }
  }
  return [value];
}

async function collectDomFallback(page, target, section) {
  const rows = await page.evaluate(sectionName => {
    const text = (node) => (node?.innerText || node?.textContent || '').trim().replace(/\s+/g, ' ');
    const tableRows = Array.from(document.querySelectorAll('table tbody tr')).slice(0, 80).map((tr, index) => ({
      _dom_index: index,
      _dom_text: text(tr),
    })).filter(row => row._dom_text);
    const cards = Array.from(document.querySelectorAll('[class*="comment"], [class*="order"], [class*="traffic"], [class*="card"], .ant-list-item')).slice(0, 80).map((node, index) => ({
      _dom_index: index,
      _dom_text: text(node),
    })).filter(row => row._dom_text && row._dom_text.length > 10);
    return [...tableRows, ...cards].map(row => ({ ...row, _capture_source: `dom:${sectionName}` }));
  }, section);

  if (!rows.length) {
    return;
  }
  target[section].push(...rows);
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
        row._source_url ?? '',
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
    traffic: data.traffic.length,
    ads: data.ads.length,
    orders: data.orders.length,
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
