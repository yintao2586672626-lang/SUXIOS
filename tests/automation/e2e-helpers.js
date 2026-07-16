const fs = require('fs');
const path = require('path');
const { expect } = require('@playwright/test');
const { Parser } = require('@json2csv/plainjs');

const MODULE = Object.freeze({
  AI_WORKBENCH: '\u4eca\u65e5\u7ecf\u8425\u770b\u677f',
  REVENUE_DIAGNOSIS: '\u6536\u76ca\u8bca\u65ad',
  DATA_TRUST: '\u6570\u636e\u53ef\u4fe1\u5ea6',
  AI_DAILY_REPORT: 'AI\u7ecf\u8425\u65e5\u62a5',
  EXECUTION_TRACKING: '\u6267\u884c\u8ddf\u8e2a',
  ADVANCED_AI: '\u9ad8\u7ea7AI\u5de5\u5177\u7bb1',
  HOTEL_MANAGEMENT: '\u95e8\u5e97\u7ba1\u7406',
  STRATEGY: '\u667a\u7565\u00b7\u6218\u7565\u63a8\u6f14',
  SIMULATION: '\u667a\u7b97\u00b7\u91cf\u5316\u6a21\u62df',
  FEASIBILITY: '\u667a\u7b56\u00b7\u53ef\u884c\u6027\u62a5\u544a',
  OPENING_OVERVIEW: '\u5f00\u4e1a\u51c6\u5907\u603b\u89c8',
  OPENING_CHECKLIST: '\u5f00\u4e1a\u68c0\u67e5\u6e05\u5355',
  DATA_SOURCE: '\u7b56\u6e90\u00b7\u5168\u7ef4\u6570\u636e',
  ROOT_CAUSE: '\u7b56\u6790\u00b7\u6839\u56e0\u5b9a\u4f4d',
  WARNING: '\u7b56\u89c1\u00b7\u9884\u8b66\u63a8\u9001',
  POLICY_SIMULATION: '\u7b56\u6848\u00b7\u7b56\u7565\u6a21\u62df',
  EFFECT_TRACKING: '\u7b56\u884c\u00b7\u6548\u679c\u8ffd\u8e2a',
  MARKET_EVALUATION: '\u667a\u6295\u00b7\u5e02\u573a\u8bc4\u4f30',
  BENCHMARK: '\u667a\u77b0\u00b7\u6807\u6746\u9009\u6a21',
  COLLABORATION: '\u667a\u8054\u00b7\u534f\u540c\u63d0\u6548',
  ASSET_PRICING: '\u667a\u7b97\u00b7\u8d44\u4ea7\u5b9a\u4ef7',
  TIMING: '\u667a\u7565\u00b7\u65f6\u673a\u63a8\u6f14',
  DATA_DASHBOARD: '\u667a\u51b3\u00b7\u6570\u636e\u770b\u677f',
});

const MODULES = [
  MODULE.AI_WORKBENCH,
  MODULE.REVENUE_DIAGNOSIS,
  MODULE.DATA_TRUST,
  MODULE.AI_DAILY_REPORT,
  MODULE.EXECUTION_TRACKING,
  MODULE.HOTEL_MANAGEMENT,
];

const GROUP = Object.freeze({
  BUSINESS_LOOP: '\u7ecf\u8425\u95ed\u73af',
  OTA_DATA: 'OTA\u6570\u636e',
  OPERATION_EXECUTION: '\u8fd0\u8425\u6267\u884c',
  MORE: '\u5f85\u5f00\u53d1...',
  PROJECT_BUILD: '\u7b79\u5efa\u7ba1\u7406',
  OPENING: '\u5f00\u4e1a\u7ba1\u7406',
  OPERATION: '\u8fd0\u8425\u7ba1\u7406',
  EXPANSION: '\u6269\u5f20\u7ba1\u7406',
  TRANSFER: '\u8f6c\u8ba9\u7ba1\u7406',
});

const GROUPS = [GROUP.BUSINESS_LOOP, GROUP.OTA_DATA, GROUP.OPERATION_EXECUTION, GROUP.MORE];
const PROJECT_MENU = '';

const MODULE_GROUPS = {
  [MODULE.REVENUE_DIAGNOSIS]: GROUP.BUSINESS_LOOP,
  [MODULE.DATA_TRUST]: GROUP.OTA_DATA,
  [MODULE.AI_DAILY_REPORT]: GROUP.OPERATION_EXECUTION,
  [MODULE.EXECUTION_TRACKING]: GROUP.OPERATION_EXECUTION,
  [MODULE.ADVANCED_AI]: GROUP.MORE,
  [MODULE.STRATEGY]: GROUP.PROJECT_BUILD,
  [MODULE.SIMULATION]: GROUP.PROJECT_BUILD,
  [MODULE.FEASIBILITY]: GROUP.PROJECT_BUILD,
  [MODULE.OPENING_OVERVIEW]: GROUP.OPENING,
  [MODULE.OPENING_CHECKLIST]: GROUP.OPENING,
  [MODULE.DATA_SOURCE]: GROUP.OPERATION,
  [MODULE.ROOT_CAUSE]: GROUP.OPERATION,
  [MODULE.WARNING]: GROUP.OPERATION,
  [MODULE.POLICY_SIMULATION]: GROUP.OPERATION,
  [MODULE.EFFECT_TRACKING]: GROUP.OPERATION,
  [MODULE.MARKET_EVALUATION]: GROUP.EXPANSION,
  [MODULE.BENCHMARK]: GROUP.EXPANSION,
  [MODULE.COLLABORATION]: GROUP.EXPANSION,
  [MODULE.ASSET_PRICING]: GROUP.TRANSFER,
  [MODULE.TIMING]: GROUP.TRANSFER,
  [MODULE.DATA_DASHBOARD]: GROUP.TRANSFER,
};

const MODULE_PATHS = {
  [MODULE.AI_WORKBENCH]: 'ai-workbench',
  [MODULE.REVENUE_DIAGNOSIS]: 'revenue-research-center',
  [MODULE.DATA_TRUST]: 'online-data',
  [MODULE.AI_DAILY_REPORT]: 'ai-daily-report',
  [MODULE.EXECUTION_TRACKING]: 'ops-track',
  [MODULE.ADVANCED_AI]: 'agent-center',
  [MODULE.HOTEL_MANAGEMENT]: 'hotels',
  [MODULE.STRATEGY]: 'ai-strategy',
  [MODULE.SIMULATION]: 'ai-simulation',
  [MODULE.FEASIBILITY]: 'ai-feasibility',
  [MODULE.OPENING_OVERVIEW]: 'opening-overview',
  [MODULE.OPENING_CHECKLIST]: 'opening-checklist',
  [MODULE.DATA_SOURCE]: 'ops-source',
  [MODULE.ROOT_CAUSE]: 'ops-analysis',
  [MODULE.WARNING]: 'ops-insight',
  [MODULE.POLICY_SIMULATION]: 'ops-plan',
  [MODULE.EFFECT_TRACKING]: 'ops-track',
  [MODULE.MARKET_EVALUATION]: 'market-evaluation',
  [MODULE.BENCHMARK]: 'benchmark-model',
  [MODULE.COLLABORATION]: 'collaboration-efficiency',
  [MODULE.ASSET_PRICING]: 'asset-pricing',
  [MODULE.TIMING]: 'timing-strategy',
  [MODULE.DATA_DASHBOARD]: 'decision-board',
};

const MODULE_NAV_TEST_IDS = {
  [MODULE.AI_WORKBENCH]: 'nav-lean-ai-workbench',
  [MODULE.HOTEL_MANAGEMENT]: 'nav-lean-hotel-management',
};

const GROUP_PATHS = {
  [GROUP.BUSINESS_LOOP]: 'lean-business-loop',
  [GROUP.OTA_DATA]: 'lean-ota-data',
  [GROUP.OPERATION_EXECUTION]: 'lean-operation',
  [GROUP.MORE]: 'lean-more',
  [GROUP.PROJECT_BUILD]: 'ai-construction',
  [GROUP.OPENING]: 'ai-opening',
  [GROUP.OPERATION]: 'ai-ops',
  [GROUP.EXPANSION]: 'ai-expansion',
  [GROUP.TRANSFER]: 'ai-transfer',
};

const DETAIL_API_PATTERNS = [
  /\/api\/expansion\/records\/[^/?]+$/,
  /\/api\/transfer\/records\/[^/?]+$/,
  /\/api\/strategy\/records\/[^/?]+$/,
  /\/api\/simulation\/records\/[^/?]+$/,
  /\/api\/agent\/feasibility-report\/detail\/[^/?]+$/,
];

function getConfig() {
  const username = String(process.env.E2E_USERNAME || '').trim();
  const password = String(process.env.E2E_PASSWORD || '');
  if (!username || !password) {
    throw new Error('E2E_USERNAME and E2E_PASSWORD are required; use an isolated E2E runner or provide explicit test credentials');
  }

  return {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8080/',
    username,
    password,
    hotelId: Number(process.env.E2E_HOTEL_ID || 0),
    hotelName: String(process.env.E2E_HOTEL_NAME || '').trim(),
    objectPrefix: String(process.env.E2E_OBJECT_PREFIX || '').trim(),
  };
}

function ensureCleanDir(dir) {
  fs.rmSync(dir, { recursive: true, force: true });
  fs.mkdirSync(dir, { recursive: true });
}

function safeFileName(value) {
  return String(value).replace(/[^\w.-]+/g, '_');
}

function createSuiteOutput(suiteName) {
  const suiteDir = path.join(process.cwd(), 'output', 'playwright', suiteName);
  const runSeed = process.env.E2E_RUN_ID || new Date().toISOString();
  const runId = safeFileName(`${runSeed}-${process.pid}`);
  const outputDir = path.join(suiteDir, 'runs', runId);
  const screenshotDir = path.join(outputDir, 'screenshots');
  return { suiteName, suiteDir, runId, outputDir, screenshotDir };
}

function writeLatestRunManifest(suiteOutput) {
  fs.mkdirSync(suiteOutput.suiteDir, { recursive: true });
  fs.writeFileSync(path.join(suiteOutput.suiteDir, 'latest-run.json'), JSON.stringify({
    suite: suiteOutput.suiteName,
    runId: suiteOutput.runId,
    outputDir: suiteOutput.outputDir,
    timestamp: new Date().toISOString(),
  }, null, 2));
}

function normalizeTestIdSegment(value) {
  const raw = String(value || '').trim().replace(/\s+/g, ' ');
  const ascii = raw
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  if (ascii) return ascii;

  let hash = 0;
  Array.from(raw).forEach((char) => {
    hash = ((hash << 5) - hash + char.charCodeAt(0)) >>> 0;
  });
  return raw ? `zh-${hash.toString(36)}` : 'unknown';
}

function modulePath(mod) {
  return MODULE_PATHS[mod] || normalizeTestIdSegment(mod);
}

function testIdForModule(mod) {
  return MODULE_NAV_TEST_IDS[mod] || `nav-${modulePath(mod)}`;
}

function pageTestIdForModule(mod) {
  return `page-${modulePath(mod)}`;
}

function appMain(page) {
  return page.getByTestId('app-main').or(page.locator('main')).first();
}

function isExtremeInputProfile() {
  return process.env.E2E_EXTREME_INPUTS === '1'
    || String(process.env.E2E_INPUT_PROFILE || '').toLowerCase() === 'extreme';
}

function extremeText(prefix = 'codex_extreme') {
  const suffix = 'x'.repeat(96);
  return `${prefix}_${Date.now()}_${suffix}_quotes_'_"_angle_<tag>_symbols_!@#$%^&*`;
}

function semanticInputValue(meta = {}) {
  const raw = [
    meta.testId,
    meta.name,
    meta.placeholder,
    meta.label,
    meta.ariaLabel,
    meta.type,
  ].filter(Boolean).join(' ').toLowerCase();
  const text = raw.replace(/\s+/g, ' ');
  const type = String(meta.type || 'text').toLowerCase();

  if (isExtremeInputProfile()) {
    if (type === 'date' || /date|day/.test(text)) return '2038-01-19';
    if (type === 'email' || /email/.test(text)) return `codex.extreme+${Date.now()}@example.com`;
    if (type === 'tel' || /phone|tel/.test(text)) return '19999999999';
    if (type === 'url' || /url|website/.test(text)) return 'https://example.com/suxios/extreme?case=codex&value=999999';

    if (type === 'number' || /revpar|gop|adr|occ|rate|score|count|cost|budget|rent|area|room|price|revenue|profit/.test(text)) {
      if (/occupancy|occ|percent|rate/.test(text)) return '99.99';
      if (/rating|score/.test(text)) return '5';
      if (/room|count|order/.test(text)) return '999';
      return '9999999';
    }

    if (/city/.test(text)) return 'Shanghai Boundary Extreme Zone';
    if (/district|business/.test(text)) return extremeText('codex_extreme_district');
    if (/hotel type|property type|type/.test(text)) return 'codex_extreme_property_type_long_stay_business';
    if (/customer|audience/.test(text)) return 'codex_extreme_mixed_audience';
    if (/address/.test(text)) return extremeText('codex_extreme_address');
    if (/name|title/.test(text)) return extremeText('codex_extreme_project');

    return extremeText();
  }

  if (type === 'date' || /日期|date|day/.test(text)) return '2026-05-17';
  if (type === 'email' || /邮箱|email/.test(text)) return `codex_${Date.now()}@example.com`;
  if (type === 'tel' || /电话|手机|phone|tel/.test(text)) return '13000000000';
  if (type === 'url' || /链接|url|website/.test(text)) return 'https://example.com/suxios';

  if (type === 'number' || /金额|价格|房价|出租率|入住率|revpar|gop|adr|occ|rate|score|count|cost|budget|rent|area|room|price|revenue|profit/.test(text)) {
    if (/出租率|入住率|occupancy|occ|率|percent|rate/.test(text)) return '76';
    if (/revpar/.test(text)) return '256';
    if (/gop|利润|profit/.test(text)) return '380000';
    if (/adr|房价|均价|price/.test(text)) return '328';
    if (/评分|rating|score/.test(text)) return '4.7';
    if (/房间|间夜|room/.test(text)) return '88';
    if (/面积|area/.test(text)) return '3200';
    if (/租金|rent/.test(text)) return '180000';
    if (/预算|投资|装修|cost|budget|investment/.test(text)) return '2200000';
    if (/营收|收入|revenue/.test(text)) return '120000';
    if (/数量|订单|count|order/.test(text)) return '120';
    return '100';
  }

  if (/城市|city/.test(text)) return '上海';
  if (/区域|商圈|district|business/.test(text)) return '浦东新区';
  if (/酒店类型|物业类型|hotel type|property type|type/.test(text)) return '中端商务酒店';
  if (/客源|客户|customer|audience/.test(text)) return '商务差旅';
  if (/地址|address/.test(text)) return '上海市浦东新区世纪大道';
  if (/项目|名称|name|title/.test(text)) return `codex_automation_project_${Date.now()}`;

  return `codex_automation_${Date.now()}`;
}

function shortError(error) {
  return (error && (error.message || String(error))).slice(0, 500);
}

function classifyError(error, fallback = 'product-bug') {
  const message = shortError(error).toLowerCase();
  if (/invalid|validation|date|number|input|fill|code=4(00|22)|status[ =]4(00|22)|\b(400|422)\b/.test(message)) return 'test-data-invalid';
  if (/status[ =][45]\d\d|code=[45]\d\d|http [45]\d\d|requestfailed|failed to fetch|net::/.test(message)) return 'api-error';
  if (/pageerror|console-error|javascript/.test(message)) return 'page-error';
  if (/timeout|detached|not visible|not enabled|strict mode|locator/.test(message)) return 'selector-or-dom-state';
  return fallback;
}

function classifyApiStatus(status) {
  if (status === 400 || status === 422) return 'test-data-invalid';
  if (status >= 400) return 'api-error';
  return null;
}

function installDiagnostics(page, sinks = {}) {
  const apiEvents = sinks.apiEvents || [];
  const pageEvents = sinks.pageEvents || [];

  page.on('requestfailed', (request) => {
    const url = request.url();
    if (!url.includes('/api/')) return;
    apiEvents.push({
      phase: 'requestfailed',
      category: 'api-error',
      method: request.method(),
      url,
      error: request.failure() ? request.failure().errorText : null,
      timestamp: new Date().toISOString(),
    });
  });

  page.on('response', (response) => {
    const url = response.url();
    if (!url.includes('/api/')) return;
    apiEvents.push({
      phase: 'response',
      category: classifyApiStatus(response.status()),
      status: response.status(),
      url,
      ok: response.ok(),
      timestamp: new Date().toISOString(),
    });
  });

  page.on('pageerror', (error) => {
    pageEvents.push({
      type: 'pageerror',
      category: 'page-error',
      error: shortError(error),
      timestamp: new Date().toISOString(),
    });
  });

  page.on('console', (message) => {
    if (!['error', 'warning'].includes(message.type())) return;
    pageEvents.push({
      type: `console-${message.type()}`,
      category: message.type() === 'error' ? 'page-error' : 'page-warning',
      message: message.text().slice(0, 500),
      timestamp: new Date().toISOString(),
    });
  });

  page.on('dialog', async (dialog) => {
    pageEvents.push({
      type: 'dialog',
      category: 'page-dialog',
      message: dialog.message(),
      timestamp: new Date().toISOString(),
    });
    await dialog.accept().catch(() => {});
  });

  return { apiEvents, pageEvents };
}

async function login(page, config = getConfig()) {
  await page.goto(config.baseURL, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const usernameInput = page.getByTestId('login-username').or(page.locator('input[name="username"]')).first();
  if (await usernameInput.count()) {
    await usernameInput.fill(config.username);
    await page.getByTestId('login-password').or(page.locator('input[name="password"]')).first().fill(config.password);
    await page.getByTestId('login-submit').or(page.locator('button[type="submit"]')).first().click();
  }
  await expect(page.locator('input[name="username"]')).toHaveCount(0, { timeout: 10000 });
}

async function navRoot(page) {
  const stableNav = page.getByTestId('app-nav');
  if (await stableNav.count()) return stableNav;
  return page.locator('aside.sidebar nav');
}

async function firstVisibleLocator(locators) {
  for (const locator of locators) {
    const count = await locator.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const candidate = locator.nth(index);
      if (await candidate.isVisible().catch(() => false)) return candidate;
    }
  }
  return null;
}

async function expandModuleMenus(page, targetModule) {
  const nav = await navRoot(page);
  const targetLocators = [
    page.getByTestId(testIdForModule(targetModule)),
    nav.getByText(targetModule, { exact: true }),
  ];
  if (await firstVisibleLocator(targetLocators)) return nav;

  const group = MODULE_GROUPS[targetModule];
  if (group) {
    const groupTestId = `nav-${GROUP_PATHS[group] || normalizeTestIdSegment(group)}`;
    const item = await firstVisibleLocator([
      page.getByTestId(groupTestId),
      nav.getByText(group, { exact: true }),
    ]);
    if (item) {
      await item.click({ timeout: 3000 });
      await page.waitForTimeout(50);
    }
  }

  return nav;
}

async function goModule(page, mod) {
  const nav = await expandModuleMenus(page, mod);
  const navItem = await firstVisibleLocator([
    page.getByTestId(testIdForModule(mod)),
    nav.getByText(mod, { exact: true }),
  ]);
  expect(navItem, `nav item not found: ${mod}`).toBeTruthy();
  await navItem.click({ timeout: 3000 });
  await expect(page.getByTestId(pageTestIdForModule(mod))).toBeVisible({ timeout: 5000 });
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', modulePath(mod), { timeout: 5000 });
}

async function waitForApiOrState(page, action, options = {}) {
  const {
    responsePredicate = (response) => response.url().includes('/api/'),
    stateLocator = page.locator('main'),
    responseTimeout = Number(process.env.E2E_API_WAIT_MS || 350),
    stateTimeout = 3000,
  } = options;
  const responsePromise = page.waitForResponse(responsePredicate, { timeout: responseTimeout }).catch(() => null);
  const actionResult = await action();
  const response = await responsePromise;
  if (stateLocator) {
    await expect(stateLocator).toBeVisible({ timeout: stateTimeout });
  }
  return { actionResult, response };
}

async function collectMainStats(page) {
  return page.locator('main').evaluate((main) => {
    const isVisible = (element) => {
      const style = window.getComputedStyle(element);
      return style.visibility !== 'hidden'
        && style.display !== 'none'
        && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    };

    return {
      textLength: (main.innerText || '').trim().length,
      visibleButtons: Array.from(main.querySelectorAll('button')).filter(isVisible).length,
      visibleInputs: Array.from(main.querySelectorAll('input, textarea, select')).filter(isVisible).length,
      visibleLinks: Array.from(main.querySelectorAll('a')).filter(isVisible).length,
      visibleTables: Array.from(main.querySelectorAll('table')).filter(isVisible).length,
      visibleCards: Array.from(main.querySelectorAll('[class*="card"], .bg-white')).filter(isVisible).length,
    };
  });
}

async function findFirstVisibleAction(page, patterns) {
  const buttons = page.locator('main button');
  const count = await buttons.count();
  for (let index = 0; index < count; index += 1) {
    const button = buttons.nth(index);
    const text = (await button.innerText().catch(() => '')).trim().replace(/\s+/g, ' ');
    if (!text || !patterns.some((pattern) => pattern.test(text))) continue;
    const usable = await button.evaluate((element) => {
      const style = window.getComputedStyle(element);
      return !element.disabled
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    }).catch(() => false);
    if (usable) return { locator: button, text };
  }
  return null;
}

async function delayDetailApis(page, delayMs = 1200) {
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    if (DETAIL_API_PATTERNS.some((pattern) => pattern.test(url.pathname))) {
      await new Promise((resolve) => setTimeout(resolve, delayMs));
    }
    await route.continue();
  });
}

function writeJsonCsv(outputDir, name, rows) {
  fs.mkdirSync(outputDir, { recursive: true });
  fs.writeFileSync(path.join(outputDir, `${name}.json`), JSON.stringify(rows, null, 2));
  fs.writeFileSync(path.join(outputDir, `${name}.csv`), rows.length ? new Parser().parse(rows) : '');
}

function summarize({ results = [], buttons = [], apiEvents = [], pageEvents = [] }) {
  const byCategory = (rows) => rows.reduce((acc, row) => {
    const category = row.category || row.reason || 'uncategorized';
    acc[category] = (acc[category] || 0) + 1;
    return acc;
  }, {});

  const failedResults = results.filter((row) => row.status === 'fail');
  const failedButtons = buttons.filter((row) => row.status === 'fail');
  const skippedButtons = buttons.filter((row) => row.status === 'skipped');
  const badApis = apiEvents.filter((row) => row.category === 'api-error' || row.ok === false);

  return {
    results: {
      total: results.length,
      success: results.filter((row) => row.status === 'success').length,
      fail: failedResults.length,
      skipped: results.filter((row) => row.status === 'skipped').length,
      failByCategory: byCategory(failedResults),
    },
    buttons: {
      total: buttons.length,
      clicked: buttons.filter((row) => row.status === 'clicked').length,
      skipped: skippedButtons.length,
      fail: failedButtons.length,
      skippedByCategory: byCategory(skippedButtons),
      failByCategory: byCategory(failedButtons),
    },
    api: {
      total: apiEvents.length,
      badResponses: badApis.length,
      byCategory: byCategory(badApis),
    },
    pageEvents: {
      total: pageEvents.length,
      byCategory: byCategory(pageEvents),
    },
  };
}

module.exports = {
  MODULE,
  MODULES,
  MODULE_PATHS,
  GROUPS,
  PROJECT_MENU,
  appMain,
  classifyError,
  collectMainStats,
  createSuiteOutput,
  delayDetailApis,
  ensureCleanDir,
  findFirstVisibleAction,
  getConfig,
  goModule,
  installDiagnostics,
  login,
  modulePath,
  pageTestIdForModule,
  safeFileName,
  semanticInputValue,
  shortError,
  summarize,
  testIdForModule,
  waitForApiOrState,
  writeJsonCsv,
  writeLatestRunManifest,
};
