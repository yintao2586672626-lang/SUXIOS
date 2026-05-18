const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// Edge Input Guard for SUXIOS.
// Run with:
//   BASE_URL=http://localhost:8080/ USERNAME=admin PASSWORD=admin123 E2E_EDGE_LIVE_API=0 npx playwright test tests/automation/edge-input-guard.spec.js

const MODULES = [
  { name: '智略·战略推演', path: 'ai-strategy', group: '筹建管理', groupPath: 'ai-construction' },
  { name: '智算·量化模拟', path: 'ai-simulation', group: '筹建管理', groupPath: 'ai-construction' },
  { name: '智策·可行性报告', path: 'ai-feasibility', group: '筹建管理', groupPath: 'ai-construction' },
  { name: '开业准备总览', path: 'opening-overview', group: '开业管理', groupPath: 'ai-opening' },
  { name: '开业检查清单', path: 'opening-checklist', group: '开业管理', groupPath: 'ai-opening' },
  { name: '策源·全维数据', path: 'ops-source', group: '运营管理', groupPath: 'ai-ops' },
  { name: '策析·根因定位', path: 'ops-analysis', group: '运营管理', groupPath: 'ai-ops' },
  { name: '策见·预警推送', path: 'ops-insight', group: '运营管理', groupPath: 'ai-ops' },
  { name: '策案·策略模拟', path: 'ops-plan', group: '运营管理', groupPath: 'ai-ops' },
  { name: '策行·效果追踪', path: 'ops-track', group: '运营管理', groupPath: 'ai-ops' },
  { name: '智投·市场评估', path: 'market-evaluation', group: '扩张管理', groupPath: 'ai-expansion' },
  { name: '智瞰·标杆选模', path: 'benchmark-model', group: '扩张管理', groupPath: 'ai-expansion' },
  { name: '智联·协同提效', path: 'collaboration-efficiency', group: '扩张管理', groupPath: 'ai-expansion' },
  { name: '智算·资产定价', path: 'asset-pricing', group: '转让管理', groupPath: 'ai-transfer' },
  { name: '智略·时机推演', path: 'timing-strategy', group: '转让管理', groupPath: 'ai-transfer' },
];

const config = {
  baseURL: process.env.BASE_URL || process.env.E2E_BASE_URL || 'http://localhost:8080/',
  username: process.env.USERNAME || process.env.E2E_USERNAME || 'admin',
  password: process.env.PASSWORD || process.env.E2E_PASSWORD || 'admin123',
  liveApi: process.env.E2E_EDGE_LIVE_API === '1',
  allowLiveMutation: process.env.E2E_EDGE_ALLOW_MUTATION === '1',
  mutationStatus: clampInt(process.env.E2E_EDGE_MUTATION_STATUS, 422, 400, 599),
  maxFieldsPerModule: clampInt(process.env.E2E_EDGE_MAX_FIELDS_PER_MODULE, 10000, 1, 50000),
  maxActionsPerModule: clampInt(process.env.E2E_EDGE_MAX_ACTIONS_PER_MODULE, 10000, 0, 50000),
  apiWaitMs: clampInt(process.env.E2E_EDGE_API_WAIT_MS, 700, 100, 5000),
};

const mutatingMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
const skippedFieldTypes = new Set(['hidden', 'file', 'submit', 'button', 'range', 'color', 'image']);
const destructiveActionPattern = /删除|清空|重置|退出|注销|归档|移除|禁用|停用|作废|撤销|解散|解绑|取消授权|Delete|Reset|Logout|Archive|Disable|Remove/i;
const liveMutationPattern = /保存|提交|新增|创建|导入|上传|生成|确认|确定|Save|Submit|Create|Import|Upload|Generate|Confirm/i;

const runId = safeFileName(process.env.E2E_RUN_ID || `${new Date().toISOString()}-${process.pid}`);
const outputRoot = path.join(process.cwd(), 'output', 'playwright', 'edge-input-guard');
const outputDir = path.join(outputRoot, 'runs', runId);
const screenshotDir = path.join(outputDir, 'screenshots');

const moduleResults = [];
const fieldResults = [];
const buttonResults = [];
const apiEvents = [];
const pageEvents = [];
const testedFieldCases = new Set();
const clickedActionSignatures = new Set();

test.use({
  browserName: 'chromium',
  channel: process.env.E2E_BROWSER_CHANNEL || 'chrome',
  headless: process.env.E2E_HEADLESS !== '0',
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 4000,
  navigationTimeout: 10000,
  trace: 'retain-on-failure',
  screenshot: 'only-on-failure',
});

test.setTimeout(0);

test.beforeAll(() => {
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(screenshotDir, { recursive: true });
});

test.afterAll(() => {
  writeReports();
  fs.mkdirSync(outputRoot, { recursive: true });
  fs.writeFileSync(path.join(outputRoot, 'latest-run.json'), JSON.stringify({
    suite: 'edge-input-guard',
    runId,
    outputDir,
    timestamp: new Date().toISOString(),
  }, null, 2));
  console.log(`Edge Input Guard report: ${path.join(outputDir, 'index.html')}`);
});

test('edge input guard: all modules, fields, buttons, dialogs, and 422 validation mocks', async ({ page }) => {
  installDiagnostics(page);

  // Step 1: log in before installing write-request mocks, so auth remains real.
  await login(page);

  // Step 2: prevent real database writes by default.
  await installEdgeApiMocks(page);

  for (const mod of MODULES) {
    const started = Date.now();
    try {
      await goModule(page, mod);

      // Step 3: run multiple passes so newly opened dialogs/popups are also scanned.
      const stats = { fields: 0, fieldApplied: 0, fieldRejected: 0, fieldSkipped: 0, buttons: 0, clicked: 0, buttonSkipped: 0 };
      for (let pass = 1; pass <= 3; pass += 1) {
        const fieldStats = await exerciseFields(page, mod, pass);
        const buttonStats = await clickButtons(page, mod, pass);
        stats.fields += fieldStats.total;
        stats.fieldApplied += fieldStats.applied;
        stats.fieldRejected += fieldStats.rejected;
        stats.fieldSkipped += fieldStats.skipped;
        stats.buttons += buttonStats.total;
        stats.clicked += buttonStats.clicked;
        stats.buttonSkipped += buttonStats.skipped;
        await closeTransientUi(page);
      }

      moduleResults.push({
        module: mod.name,
        status: 'success',
        ...stats,
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
      console.log(`[edge-input] ${mod.name} fields=${stats.fieldApplied} buttons=${stats.clicked}`);
    } catch (error) {
      const screenshot = path.join(screenshotDir, `${safeFileName(mod.name)}.png`);
      const html = path.join(screenshotDir, `${safeFileName(mod.name)}.html`);
      await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
      fs.writeFileSync(html, await page.content().catch(() => ''));
      moduleResults.push({
        module: mod.name,
        status: 'fail',
        category: classifyError(error, 'product-bug'),
        error: shortError(error),
        screenshot,
        html,
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
      await login(page).catch(() => {});
      await installEdgeApiMocks(page).catch(() => {});
    }
    writeReports();
  }

  expect(moduleResults.filter((row) => row.status === 'fail'), JSON.stringify(moduleResults, null, 2)).toHaveLength(0);
  expect(pageEvents.filter((row) => row.type === 'pageerror'), JSON.stringify(pageEvents, null, 2)).toHaveLength(0);
});

function clampInt(raw, fallback, min, max) {
  const value = Number(raw || fallback);
  if (!Number.isFinite(value)) return fallback;
  return Math.max(min, Math.min(max, Math.floor(value)));
}

function safeFileName(value) {
  return String(value || 'unknown').replace(/[^\w.-]+/g, '_');
}

function preview(value, limit = 160) {
  const text = String(value ?? '').replace(/\s+/g, ' ');
  return text.length > limit ? `${text.slice(0, limit)}...` : text;
}

function shortError(error) {
  return String(error && (error.message || error)).slice(0, 800);
}

function classifyError(error, fallback = 'product-bug') {
  const message = shortError(error).toLowerCase();
  if (/invalid|validation|malformed|not a valid|422|400/.test(message)) return 'test-data-invalid';
  if (/requestfailed|failed to fetch|net::|status[ =][45]\d\d|http [45]\d\d/.test(message)) return 'api-error';
  if (/pageerror|console-error|javascript/.test(message)) return 'page-error';
  if (/timeout|detached|not visible|not enabled|intercepts pointer events|locator|strict mode/.test(message)) return 'selector-or-dom-state';
  return fallback;
}

function countBy(rows, key = 'category') {
  return rows.reduce((acc, row) => {
    const value = row[key] || 'uncategorized';
    acc[value] = (acc[value] || 0) + 1;
    return acc;
  }, {});
}

function appMain(page) {
  return page.getByTestId('app-main').or(page.locator('main')).first();
}

async function navRoot(page) {
  const stableNav = page.getByTestId('app-nav');
  if (await stableNav.count().catch(() => 0)) return stableNav;
  return page.locator('aside nav, nav').first();
}

async function firstVisible(locators) {
  for (const locator of locators) {
    const count = await locator.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const item = locator.nth(index);
      if (await item.isVisible().catch(() => false)) return item;
    }
  }
  return null;
}

async function login(page) {
  await page.goto(config.baseURL, { waitUntil: 'domcontentloaded', timeout: 30000 });

  const username = page.getByTestId('login-username')
    .or(page.locator('input[name="username"], input[type="text"], input[placeholder*="用户"], input[placeholder*="账号"]'))
    .first();
  if (!(await username.count().catch(() => 0))) return;
  if (!(await username.isVisible().catch(() => false))) return;

  await username.fill(config.username);
  await page.getByTestId('login-password')
    .or(page.locator('input[name="password"], input[type="password"]'))
    .first()
    .fill(config.password);
  await page.getByTestId('login-submit')
    .or(page.locator('button[type="submit"], button:has-text("登录"), button:has-text("Login")'))
    .first()
    .click();

  await expect(page.locator('input[name="username"], input[type="password"]')).toHaveCount(0, { timeout: 15000 });
}

async function goModule(page, mod) {
  await closeTransientUi(page);
  const nav = await navRoot(page);
  let moduleItem = await firstVisible([
    page.getByTestId(`nav-${mod.path}`),
    nav.getByText(mod.name, { exact: true }),
  ]);

  if (!moduleItem) {
    let groupItem = await firstVisible([
      page.getByTestId(`nav-${mod.groupPath}`),
      nav.getByText(mod.group, { exact: true }),
    ]);
    if (!groupItem) {
      const projectMenu = await firstVisible([
        page.getByTestId('nav-project-ai-management'),
        nav.getByText('项目AI管理', { exact: true }),
      ]);
      if (projectMenu) await projectMenu.click({ timeout: 3000 }).catch(() => {});
      groupItem = await firstVisible([
        page.getByTestId(`nav-${mod.groupPath}`),
        nav.getByText(mod.group, { exact: true }),
      ]);
    }
    if (groupItem) await groupItem.click({ timeout: 3000 }).catch(() => {});

    moduleItem = await firstVisible([
      page.getByTestId(`nav-${mod.path}`),
      nav.getByText(mod.name, { exact: true }),
    ]);
    if (!moduleItem && groupItem) {
      await groupItem.click({ timeout: 3000 }).catch(() => {});
      moduleItem = await firstVisible([
        page.getByTestId(`nav-${mod.path}`),
        nav.getByText(mod.name, { exact: true }),
      ]);
    }
  }

  expect(moduleItem, `module nav item not found: ${mod.name}`).toBeTruthy();
  await moduleItem.click({ timeout: 4000 });
  const pageContainer = page.getByTestId(`page-${mod.path}`);
  if (await pageContainer.count().catch(() => 0)) {
    await expect(pageContainer.first()).toBeVisible({ timeout: 8000 });
  } else {
    await expect(appMain(page)).toBeVisible({ timeout: 8000 });
  }
  await expect(appMain(page)).toBeVisible({ timeout: 8000 });
}

function installDiagnostics(page) {
  page.on('requestfailed', (request) => {
    if (!request.url().includes('/api/')) return;
    apiEvents.push({
      phase: 'requestfailed',
      category: 'api-error',
      method: request.method(),
      url: request.url(),
      error: request.failure() ? request.failure().errorText : null,
      timestamp: new Date().toISOString(),
    });
  });

  page.on('response', (response) => {
    if (!response.url().includes('/api/')) return;
    const status = response.status();
    apiEvents.push({
      phase: 'response',
      category: status === 400 || status === 422 ? 'test-data-invalid' : status >= 400 ? 'api-error' : null,
      method: response.request().method(),
      url: response.url(),
      status,
      ok: response.ok(),
      timestamp: new Date().toISOString(),
    });
  });

  page.on('pageerror', (error) => {
    pageEvents.push({
      type: 'pageerror',
      category: 'page-error',
      message: shortError(error),
      timestamp: new Date().toISOString(),
    });
  });

  page.on('console', (message) => {
    if (!['error', 'warning'].includes(message.type())) return;
    pageEvents.push({
      type: `console-${message.type()}`,
      category: message.type() === 'error' ? 'page-error' : 'page-warning',
      message: preview(message.text(), 500),
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
}

async function installEdgeApiMocks(page) {
  await page.unroute('**/api/**').catch(() => {});
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();
    if (config.liveApi || !mutatingMethods.has(method)) {
      await route.continue();
      return;
    }

    const pathname = new URL(request.url()).pathname;
    if (/\/api\/auth\/login$/.test(pathname)) {
      await route.continue();
      return;
    }

    apiEvents.push({
      phase: 'mocked-response',
      category: 'test-data-invalid',
      method,
      url: request.url(),
      status: config.mutationStatus,
      ok: false,
      timestamp: new Date().toISOString(),
    });

    await route.fulfill({
      status: config.mutationStatus,
      contentType: 'application/json',
      body: JSON.stringify({
        code: config.mutationStatus,
        message: 'edge_input_guard_mock_validation_error',
        data: null,
      }),
    });
  });
}

async function collectVisibleFields(page) {
  return page.evaluate((limit) => {
    window.__edgeInputFieldSeq = window.__edgeInputFieldSeq || 0;
    const visible = (element) => {
      const style = window.getComputedStyle(element);
      return style.visibility !== 'hidden'
        && style.display !== 'none'
        && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    };
    const labelFor = (element) => {
      const label = element.closest('label');
      if (label) return label.innerText || '';
      if (element.id && window.CSS && CSS.escape) {
        const byFor = document.querySelector(`label[for="${CSS.escape(element.id)}"]`);
        if (byFor) return byFor.innerText || '';
      }
      const container = element.closest('div, td, th, section, form, [role="dialog"]');
      const nearby = container ? container.querySelector('label') : null;
      return nearby ? nearby.innerText || '' : '';
    };

    return Array.from(document.querySelectorAll('main input, main textarea, main select, [role="dialog"] input, [role="dialog"] textarea, [role="dialog"] select, .modal input, .modal textarea, .modal select'))
      .filter(visible)
      .slice(0, limit)
      .map((element, index) => {
        if (!element.dataset.edgeInputFieldId) {
          window.__edgeInputFieldSeq += 1;
          element.dataset.edgeInputFieldId = String(window.__edgeInputFieldSeq);
        }
        const tag = element.tagName.toLowerCase();
        return {
          id: element.dataset.edgeInputFieldId,
          signature: [
            element.dataset.testid || '',
            element.name || '',
            element.placeholder || '',
            element.getAttribute('aria-label') || '',
            labelFor(element).trim().replace(/\s+/g, ' '),
            tag,
            element.type || '',
            index,
          ].join('|'),
          tag,
          type: element.type || 'text',
          disabled: element.disabled,
          readOnly: element.readOnly,
          testId: element.dataset.testid || '',
          name: element.name || '',
          placeholder: element.placeholder || '',
          ariaLabel: element.getAttribute('aria-label') || '',
          label: labelFor(element).trim().replace(/\s+/g, ' '),
          options: tag === 'select'
            ? Array.from(element.options).map((option, optionIndex) => ({ index: optionIndex, value: option.value, disabled: option.disabled }))
            : [],
        };
      });
  }, config.maxFieldsPerModule);
}

function fieldName(meta) {
  return preview(meta.testId || meta.name || meta.placeholder || meta.ariaLabel || meta.label || `${meta.tag}:${meta.type}:${meta.id}`);
}

function edgeCasesForField(meta) {
  const tag = String(meta.tag || '').toLowerCase();
  const type = String(meta.type || 'text').toLowerCase();
  if (meta.disabled || meta.readOnly || skippedFieldTypes.has(type)) return [];

  if (tag === 'select') {
    return (meta.options || [])
      .filter((option) => !option.disabled)
      .slice(0, 3)
      .map((option) => ({ name: option.value ? `select-${option.index}` : 'empty-select', kind: 'select', index: option.index }));
  }

  if (type === 'checkbox' || type === 'radio') {
    return [
      { name: 'unchecked', kind: 'checked', checked: false },
      { name: 'checked', kind: 'checked', checked: true },
    ];
  }

  if (type === 'number') {
    return [
      { name: 'empty', kind: 'fill', value: '' },
      { name: 'negative-number', kind: 'fill', value: '-999999' },
      { name: 'large-number', kind: 'fill', value: '999999999999999999999999' },
      { name: 'precision-number', kind: 'fill', value: '0.0000001' },
      { name: 'invalid-number', kind: 'fill', value: 'not-a-number' },
    ];
  }

  if (['date', 'month', 'datetime-local', 'time'].includes(type)) {
    return [
      { name: 'empty', kind: 'fill', value: '' },
      { name: 'invalid-date', kind: 'fill', value: type === 'time' ? '25:61' : '2026-02-30' },
      { name: 'past-boundary', kind: 'fill', value: type === 'time' ? '00:00' : '1900-01-01' },
      { name: 'future-boundary', kind: 'fill', value: type === 'time' ? '23:59' : '2999-12-31' },
    ];
  }

  if (type === 'email') {
    return [
      { name: 'empty', kind: 'fill', value: '' },
      { name: 'invalid-email', kind: 'fill', value: 'not-an-email' },
      { name: 'long-email', kind: 'fill', value: `${'a'.repeat(1100)}@example.com` },
      { name: 'xss-email', kind: 'fill', value: '<script>alert("edge")</script>' },
    ];
  }

  if (type === 'url') {
    return [
      { name: 'empty', kind: 'fill', value: '' },
      { name: 'invalid-url', kind: 'fill', value: 'not-a-url' },
      { name: 'long-url', kind: 'fill', value: `https://example.com/${'x'.repeat(1100)}` },
      { name: 'javascript-url', kind: 'fill', value: 'javascript:alert("edge")' },
    ];
  }

  return [
    { name: 'empty', kind: 'fill', value: '' },
    { name: 'long-text-1200', kind: 'fill', value: `edge_${'x'.repeat(1200)}` },
    { name: 'special-characters', kind: 'fill', value: 'edge_"\'<>${}[]&=|`\\' },
    { name: 'script-like-text', kind: 'fill', value: '<script>alert("edge-input-guard")</script>' },
    { name: 'xss-img-payload', kind: 'fill', value: '<img src=x onerror=alert("edge")>' },
    { name: 'invalid-email-like', kind: 'fill', value: 'not-an-email' },
    { name: 'invalid-url-like', kind: 'fill', value: 'not-a-url' },
  ];
}

async function applyEdgeCase(locator, edgeCase) {
  if (edgeCase.kind === 'select') {
    await locator.selectOption({ index: edgeCase.index }, { timeout: 2500 });
  } else if (edgeCase.kind === 'checked') {
    await locator.setChecked(edgeCase.checked, { timeout: 2500 });
  } else {
    await locator.fill(edgeCase.value, { timeout: 2500 });
  }
  await locator.dispatchEvent('input').catch(() => {});
  await locator.dispatchEvent('change').catch(() => {});
  await locator.blur().catch(() => {});
  return locator.evaluate((element) => ({
    value: element.value,
    checked: Boolean(element.checked),
    valid: element.validity ? element.validity.valid : null,
    validationMessage: element.validationMessage || '',
  }));
}

async function exerciseFields(page, mod, pass) {
  const fields = await collectVisibleFields(page);
  const stats = { total: fields.length, applied: 0, rejected: 0, skipped: 0 };

  for (const meta of fields) {
    const cases = edgeCasesForField(meta);
    if (!cases.length) {
      stats.skipped += 1;
      fieldResults.push({ module: mod.name, pass, field: fieldName(meta), status: 'skipped', category: 'safe-skip', reason: 'unsupported-or-readonly-field', timestamp: new Date().toISOString() });
      continue;
    }

    for (const edgeCase of cases) {
      const key = `${mod.path}|${meta.signature}|${edgeCase.name}`;
      if (testedFieldCases.has(key)) continue;
      testedFieldCases.add(key);

      const started = Date.now();
      const locator = page.locator(`[data-edge-input-field-id="${meta.id}"]`).first();
      try {
        const state = await applyEdgeCase(locator, edgeCase);
        await expect(appMain(page)).toBeVisible({ timeout: 2000 });
        stats.applied += 1;
        fieldResults.push({
          module: mod.name,
          pass,
          field: fieldName(meta),
          tag: meta.tag,
          type: meta.type,
          caseName: edgeCase.name,
          status: 'applied',
          category: state.valid === false ? 'test-data-invalid' : null,
          valid: state.valid,
          validationMessage: preview(state.validationMessage),
          valuePreview: preview(state.value),
          valueLength: String(state.value || '').length,
          checked: state.checked,
          ms: Date.now() - started,
          timestamp: new Date().toISOString(),
        });
      } catch (error) {
        const category = classifyError(error, 'selector-or-dom-state');
        const status = category === 'test-data-invalid' ? 'rejected' : 'skipped';
        if (status === 'rejected') stats.rejected += 1;
        else stats.skipped += 1;
        fieldResults.push({
          module: mod.name,
          pass,
          field: fieldName(meta),
          tag: meta.tag,
          type: meta.type,
          caseName: edgeCase.name,
          status,
          category,
          error: shortError(error),
          ms: Date.now() - started,
          timestamp: new Date().toISOString(),
        });
      }
    }
  }
  return stats;
}

async function collectVisibleButtons(page) {
  return page.evaluate((limit) => {
    window.__edgeInputButtonSeq = window.__edgeInputButtonSeq || 0;
    const hasDialog = Array.from(document.querySelectorAll('[role="dialog"], .modal, .el-dialog')).some((element) => {
      const style = window.getComputedStyle(element);
      return style.visibility !== 'hidden' && style.display !== 'none' && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    });
    const visible = (element) => {
      const style = window.getComputedStyle(element);
      return !element.disabled
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    };
    const selector = hasDialog
      ? '[role="dialog"] button, .modal button, .el-dialog button'
      : 'main button, [role="dialog"] button, .modal button, .el-dialog button';

    return Array.from(document.querySelectorAll(selector))
      .filter(visible)
      .slice(0, limit)
      .map((button, index) => {
        if (!button.dataset.edgeInputButtonId) {
          window.__edgeInputButtonSeq += 1;
          button.dataset.edgeInputButtonId = String(window.__edgeInputButtonSeq);
        }
        const label = (button.innerText || button.textContent || button.getAttribute('aria-label') || button.title || '').trim().replace(/\s+/g, ' ');
        const classPart = String(button.className || '').replace(/\s+/g, ' ').slice(0, 120);
        return {
          id: button.dataset.edgeInputButtonId,
          testId: button.dataset.testid || '',
          label,
          signature: [button.dataset.testid || '', label, button.type || '', button.title || '', button.getAttribute('aria-label') || '', classPart, index].join('|'),
        };
      });
  }, config.maxActionsPerModule);
}

function skipButtonReason(button) {
  const label = button.label || button.testId;
  if (!label) return 'unlabeled-button';
  if (destructiveActionPattern.test(label)) return 'destructive-button';
  if (config.liveApi && !config.allowLiveMutation && liveMutationPattern.test(label)) return 'live-api-mutation-guard';
  return null;
}

async function waitForApiOrState(page, action) {
  const responsePromise = page.waitForResponse((response) => response.url().includes('/api/'), { timeout: config.apiWaitMs }).catch(() => null);
  await action();
  const response = await responsePromise;
  await expect(appMain(page)).toBeVisible({ timeout: 3000 });
  return response;
}

async function clickButtons(page, mod, pass) {
  const buttons = await collectVisibleButtons(page);
  const stats = { total: buttons.length, clicked: 0, skipped: 0 };

  for (const button of buttons) {
    const key = `${mod.path}|${button.signature}`;
    if (clickedActionSignatures.has(key)) continue;
    clickedActionSignatures.add(key);

    const label = preview(button.label || button.testId || `[button:${button.id}]`);
    const skipReason = skipButtonReason(button);
    if (skipReason) {
      stats.skipped += 1;
      buttonResults.push({ module: mod.name, pass, button: label, status: 'skipped', category: 'safe-skip', reason: skipReason, timestamp: new Date().toISOString() });
      continue;
    }

    const started = Date.now();
    const locator = button.testId
      ? page.getByTestId(button.testId).first()
      : page.locator(`[data-edge-input-button-id="${button.id}"]`).first();

    try {
      await locator.click({ trial: true, timeout: 1200 });
    } catch (error) {
      stats.skipped += 1;
      buttonResults.push({ module: mod.name, pass, button: label, status: 'skipped', category: 'safe-skip', reason: 'unclickable-button', error: shortError(error), timestamp: new Date().toISOString() });
      continue;
    }

    try {
      const response = await waitForApiOrState(page, () => locator.click({ timeout: 3000 }));
      stats.clicked += 1;
      buttonResults.push({
        module: mod.name,
        pass,
        button: label,
        status: 'clicked',
        responseStatus: response ? response.status() : null,
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
      await exerciseFields(page, mod, `${pass}.dialog`);
    } catch (error) {
      stats.skipped += 1;
      buttonResults.push({
        module: mod.name,
        pass,
        button: label,
        status: 'skipped',
        category: classifyError(error, 'selector-or-dom-state'),
        reason: 'click-failed-safely',
        error: shortError(error),
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
    }
  }
  return stats;
}

async function closeTransientUi(page) {
  await page.keyboard.press('Escape').catch(() => {});
  const closeButtons = page.locator('[role="dialog"] button:has-text("关闭"), [role="dialog"] button:has-text("取消"), .modal button:has-text("关闭"), .modal button:has-text("取消")');
  const count = Math.min(await closeButtons.count().catch(() => 0), 3);
  for (let index = 0; index < count; index += 1) {
    await closeButtons.nth(index).click({ timeout: 1000 }).catch(() => {});
  }
}

function summarize() {
  return {
    config: {
      baseURL: config.baseURL,
      username: config.username,
      liveApi: config.liveApi,
      mutationStatus: config.mutationStatus,
      maxFieldsPerModule: config.maxFieldsPerModule,
      maxActionsPerModule: config.maxActionsPerModule,
    },
    modules: {
      total: moduleResults.length,
      success: moduleResults.filter((row) => row.status === 'success').length,
      fail: moduleResults.filter((row) => row.status === 'fail').length,
      failByCategory: countBy(moduleResults.filter((row) => row.status === 'fail')),
    },
    fields: {
      total: fieldResults.length,
      applied: fieldResults.filter((row) => row.status === 'applied').length,
      rejected: fieldResults.filter((row) => row.status === 'rejected').length,
      skipped: fieldResults.filter((row) => row.status === 'skipped').length,
      byCategory: countBy(fieldResults),
    },
    buttons: {
      total: buttonResults.length,
      clicked: buttonResults.filter((row) => row.status === 'clicked').length,
      skipped: buttonResults.filter((row) => row.status === 'skipped').length,
      byCategory: countBy(buttonResults),
    },
    api: {
      total: apiEvents.length,
      mocked422: apiEvents.filter((row) => row.phase === 'mocked-response' && row.status === 422).length,
      badResponses: apiEvents.filter((row) => row.status >= 400 || row.category === 'api-error').length,
      byCategory: countBy(apiEvents),
    },
    pageEvents: {
      total: pageEvents.length,
      byType: countBy(pageEvents, 'type'),
      byCategory: countBy(pageEvents),
    },
    outputDir,
    generatedAt: new Date().toISOString(),
  };
}

function writeJson(name, data) {
  fs.mkdirSync(outputDir, { recursive: true });
  fs.writeFileSync(path.join(outputDir, name), JSON.stringify(data, null, 2));
}

function htmlEscape(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function renderRows(rows, columns) {
  return rows.slice(0, 300).map((row) => `<tr>${columns.map((column) => `<td>${htmlEscape(row[column])}</td>`).join('')}</tr>`).join('\n');
}

function writeHtmlReport(summary) {
  const html = `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>SUXIOS Edge Input Guard Report</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; background: #f8fafc; }
    h1, h2 { margin: 0 0 12px; }
    section { margin: 18px 0; padding: 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
    .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .metric { padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; }
    .metric strong { display: block; font-size: 22px; margin-top: 4px; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
    th { background: #f3f4f6; }
    code { background: #eef2ff; padding: 2px 4px; border-radius: 4px; }
  </style>
</head>
<body>
  <h1>SUXIOS Edge Input Guard Report</h1>
  <p>Generated at ${htmlEscape(summary.generatedAt)}. Live API: <code>${htmlEscape(summary.config.liveApi)}</code>. Output: <code>${htmlEscape(summary.outputDir)}</code></p>
  <section class="grid">
    <div class="metric">Modules<strong>${summary.modules.success}/${summary.modules.total}</strong></div>
    <div class="metric">Fields Applied<strong>${summary.fields.applied}</strong></div>
    <div class="metric">Buttons Clicked<strong>${summary.buttons.clicked}</strong></div>
    <div class="metric">Mocked 422<strong>${summary.api.mocked422}</strong></div>
  </section>
  <section>
    <h2>Summary</h2>
    <pre>${htmlEscape(JSON.stringify(summary, null, 2))}</pre>
  </section>
  <section>
    <h2>Module Results</h2>
    <table><thead><tr><th>module</th><th>status</th><th>fields</th><th>buttons</th><th>ms</th><th>error</th></tr></thead><tbody>
      ${renderRows(moduleResults, ['module', 'status', 'fieldApplied', 'clicked', 'ms', 'error'])}
    </tbody></table>
  </section>
  <section>
    <h2>Console Errors And Warnings</h2>
    <table><thead><tr><th>type</th><th>category</th><th>message</th><th>timestamp</th></tr></thead><tbody>
      ${renderRows(pageEvents, ['type', 'category', 'message', 'timestamp'])}
    </tbody></table>
  </section>
  <section>
    <h2>Button Actions</h2>
    <table><thead><tr><th>module</th><th>button</th><th>status</th><th>reason</th><th>responseStatus</th><th>error</th></tr></thead><tbody>
      ${renderRows(buttonResults, ['module', 'button', 'status', 'reason', 'responseStatus', 'error'])}
    </tbody></table>
  </section>
</body>
</html>`;
  fs.writeFileSync(path.join(outputDir, 'index.html'), html);
}

function writeReports() {
  const summary = summarize();
  writeJson('summary.json', summary);
  writeJson('modules.json', moduleResults);
  writeJson('fields.json', fieldResults);
  writeJson('buttons.json', buttonResults);
  writeJson('api-events.json', apiEvents);
  writeJson('page-events.json', pageEvents);
  writeHtmlReport(summary);
}
