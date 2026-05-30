const { test } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const {
  MODULES,
  classifyError,
  createSuiteOutput,
  ensureCleanDir,
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
  waitForApiOrState,
  writeLatestRunManifest,
  writeJsonCsv,
} = require('./e2e-helpers');

const config = getConfig();
const MIN_KEY_FUNCTION_LOOPS = 50;
const MAX_KEY_FUNCTION_LOOPS = 100;
const DEFAULT_KEY_FUNCTION_LOOPS = 50;
const mutateForms = process.env.E2E_MUTATE !== '0';
const allowDestructive = process.env.E2E_ALLOW_DESTRUCTIVE === '1';
const maxButtonsPerModule = Math.max(1, Number(process.env.E2E_MAX_BUTTONS_PER_MODULE || 30));
const maxFieldsPerModule = Math.max(1, Number(process.env.E2E_MAX_FIELDS_PER_MODULE || 40));
const suiteOutput = createSuiteOutput('full-click');
const { outputDir, screenshotDir } = suiteOutput;
const backupDir = path.join(process.cwd(), 'output', 'playwright', 'db-backup');

const results = [];
const buttonResults = [];
const apiEvents = [];
const pageEvents = [];

function parseBoundedInteger(raw, fallback, min, max) {
  const value = Number(raw);
  if (!Number.isFinite(value)) return fallback;
  return Math.min(max, Math.max(min, Math.floor(value)));
}

const configuredMinKeyFunctionLoops = parseBoundedInteger(
  process.env.E2E_FULL_MIN_LOOP || process.env.E2E_MIN_LOOP,
  MIN_KEY_FUNCTION_LOOPS,
  1,
  MAX_KEY_FUNCTION_LOOPS,
);
const configuredMaxKeyFunctionLoops = parseBoundedInteger(
  process.env.E2E_FULL_MAX_LOOP || process.env.E2E_MAX_LOOP,
  MAX_KEY_FUNCTION_LOOPS,
  configuredMinKeyFunctionLoops,
  MAX_KEY_FUNCTION_LOOPS,
);

function parseKeyFunctionLoopCount() {
  const raw = process.env.E2E_LOOP || process.env.E2E_ITERATIONS || configuredMinKeyFunctionLoops;
  const value = Number(raw);
  if (!Number.isFinite(value)) return configuredMinKeyFunctionLoops;
  return Math.min(
    configuredMaxKeyFunctionLoops,
    Math.max(configuredMinKeyFunctionLoops, Math.floor(value)),
  );
}

const loopCount = parseKeyFunctionLoopCount();
const progress = {
  startedAt: new Date().toISOString(),
  loopCount,
  loopRange: {
    min: configuredMinKeyFunctionLoops,
    max: configuredMaxKeyFunctionLoops,
  },
  modules: MODULES.length,
  current: null,
};
let databaseBackup = null;

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 3000,
  navigationTimeout: 8000,
  trace: 'retain-on-failure',
  screenshot: 'only-on-failure',
});

test.setTimeout(0);

function writeReports() {
  fs.mkdirSync(outputDir, { recursive: true });
  fs.writeFileSync(path.join(outputDir, 'progress.json'), JSON.stringify(progress, null, 2));
  writeJsonCsv(outputDir, 'results', results);
  writeJsonCsv(outputDir, 'buttons', buttonResults);
  writeJsonCsv(outputDir, 'api-events', apiEvents);
  writeJsonCsv(outputDir, 'page-events', pageEvents);
  fs.writeFileSync(path.join(outputDir, 'summary.json'), JSON.stringify(
    summarize({ results, buttons: buttonResults, apiEvents, pageEvents }),
    null,
    2,
  ));
}

function executableExists(file) {
  return Boolean(file) && fs.existsSync(file);
}

function runCommand(command, args, options = {}) {
  const res = spawnSync(command, args, {
    cwd: process.cwd(),
    encoding: options.input ? undefined : 'utf8',
    input: options.input,
    timeout: options.timeout || 120000,
    shell: false,
  });
  return {
    status: res.status,
    error: res.error ? String(res.error) : '',
    stdout: res.stdout ? String(res.stdout) : '',
    stderr: res.stderr ? String(res.stderr) : '',
  };
}

function backupDatabase() {
  if (process.env.E2E_DB_BACKUP === '0') {
    return {
      type: 'database-backup',
      category: 'safe-skip',
      status: 'skipped',
      reason: 'E2E_DB_BACKUP=0',
      timestamp: new Date().toISOString(),
    };
  }

  const mysqldump = process.env.E2E_MYSQLDUMP || 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
  const dbName = process.env.E2E_DB_NAME || 'hotelx';
  const dbUser = process.env.E2E_DB_USER || 'root';
  const dbHost = process.env.E2E_DB_HOST || '127.0.0.1';
  const dbPort = process.env.E2E_DB_PORT || '3306';
  const dbPassword = process.env.E2E_DB_PASSWORD || '';

  if (!executableExists(mysqldump)) {
    return {
      type: 'database-backup',
      category: 'test-environment',
      status: 'skipped',
      reason: `mysqldump not found: ${mysqldump}`,
      timestamp: new Date().toISOString(),
    };
  }

  fs.mkdirSync(backupDir, { recursive: true });
  const file = path.join(backupDir, `${dbName}-${new Date().toISOString().replace(/[:.]/g, '-')}.sql`);
  const args = ['-h', dbHost, '-P', dbPort, '-u', dbUser, `--result-file=${file}`, dbName];
  if (dbPassword) args.splice(5, 0, `-p${dbPassword}`);
  const res = runCommand(mysqldump, args, { timeout: 180000 });

  return {
    type: 'database-backup',
    category: res.status === 0 ? 'db-backup' : 'test-environment',
    status: res.status === 0 ? 'success' : 'fail',
    file: res.status === 0 ? file : null,
    error: res.status === 0 ? null : (res.stderr || res.error).slice(0, 500),
    timestamp: new Date().toISOString(),
  };
}

function restoreDatabase(backup) {
  if (process.env.E2E_DB_RESTORE !== '1') {
    return {
      type: 'database-restore',
      category: 'safe-skip',
      status: 'skipped',
      reason: 'set E2E_DB_RESTORE=1 to restore the pre-test backup',
      timestamp: new Date().toISOString(),
    };
  }
  if (!backup?.file || !fs.existsSync(backup.file)) {
    return {
      type: 'database-restore',
      category: 'test-environment',
      status: 'fail',
      reason: 'backup file is not available',
      timestamp: new Date().toISOString(),
    };
  }

  const mysql = process.env.E2E_MYSQL || 'C:\\xampp\\mysql\\bin\\mysql.exe';
  const dbName = process.env.E2E_DB_NAME || 'hotelx';
  const dbUser = process.env.E2E_DB_USER || 'root';
  const dbHost = process.env.E2E_DB_HOST || '127.0.0.1';
  const dbPort = process.env.E2E_DB_PORT || '3306';
  const dbPassword = process.env.E2E_DB_PASSWORD || '';

  if (!executableExists(mysql)) {
    return {
      type: 'database-restore',
      category: 'test-environment',
      status: 'fail',
      reason: `mysql not found: ${mysql}`,
      timestamp: new Date().toISOString(),
    };
  }

  const args = ['-h', dbHost, '-P', dbPort, '-u', dbUser, dbName];
  if (dbPassword) args.splice(5, 0, `-p${dbPassword}`);
  const res = runCommand(mysql, args, { input: fs.readFileSync(backup.file), timeout: 180000 });
  return {
    type: 'database-restore',
    category: res.status === 0 ? 'db-restore' : 'test-environment',
    status: res.status === 0 ? 'success' : 'fail',
    file: backup.file,
    error: res.status === 0 ? null : (res.stderr || res.error).slice(0, 500),
    timestamp: new Date().toISOString(),
  };
}

function isDangerousButton(text) {
  if (allowDestructive) return false;
  return /删除|清空|重置|退出|注销|归档|移除|禁用|停用|作废|撤销|解绑|取消授权/.test(text);
}

function isPageSwitchButton(target) {
  const value = `${target?.testId || ''} ${target?.text || ''} ${target?.signature || ''}`.toLowerCase();
  return /reuse|detail|history|enter|open|view|复用|详情|进入|查看|打开/.test(value);
}

async function fieldMeta(field) {
  return field.evaluate((element) => {
    const style = window.getComputedStyle(element);
    const container = element.closest('div');
    const label = element.closest('label') || container?.querySelector('label');
    return {
      visible: !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length) && style.visibility !== 'hidden' && style.display !== 'none',
      disabled: element.disabled,
      readOnly: element.readOnly,
      tag: element.tagName.toLowerCase(),
      type: element.type || 'text',
      testId: element.dataset.testid || '',
      name: element.getAttribute('name') || '',
      placeholder: element.getAttribute('placeholder') || '',
      ariaLabel: element.getAttribute('aria-label') || '',
      label: label ? label.innerText.trim().replace(/\s+/g, ' ') : '',
    };
  }).catch(() => null);
}

async function fillVisibleFields(page, loop, mod) {
  if (!mutateForms) return { filled: 0, skipped: 0 };

  const fields = page.locator('main input, main textarea, main select, [role="dialog"] input, [role="dialog"] textarea, [role="dialog"] select');
  const count = Math.min(await fields.count(), maxFieldsPerModule);
  let filled = 0;
  let skipped = 0;
  for (let index = 0; index < count; index += 1) {
    const field = fields.nth(index);
    const meta = await fieldMeta(field);

    if (!meta || !meta.visible || meta.disabled || meta.readOnly || ['hidden', 'file', 'submit', 'button', 'range', 'color', 'image'].includes(meta.type)) {
      skipped += 1;
      continue;
    }

    try {
      if (meta.tag === 'select') {
        const optionCount = await field.locator('option').count();
        if (optionCount > 0) {
          const optionIndex = optionCount > 1 ? 1 : 0;
          await field.selectOption({ index: optionIndex }, { timeout: 2000 });
          filled += 1;
        }
      } else if (meta.type === 'checkbox' || meta.type === 'radio') {
        await field.setChecked(true, { timeout: 2000 }).catch(() => {});
        filled += 1;
      } else {
        await field.fill(semanticInputValue(meta), { timeout: 2000 });
        filled += 1;
      }
    } catch (error) {
      skipped += 1;
      pageEvents.push({
        type: 'field-fill-fail',
        category: classifyError(error, 'test-data-invalid'),
        loop,
        module: mod,
        index,
        testId: meta.testId,
        label: meta.label,
        error: shortError(error),
        timestamp: new Date().toISOString(),
      });
    }
  }
  return { filled, skipped };
}

async function collectVisibleButtons(page) {
  return page.evaluate(() => {
    window.__suxiE2EButtonSeq = window.__suxiE2EButtonSeq || 0;
    return Array.from(document.querySelectorAll('#app button'))
      .filter((button) => {
        if (button.closest('aside')) return false;
        const style = window.getComputedStyle(button);
        return !button.disabled
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && !!(button.offsetWidth || button.offsetHeight || button.getClientRects().length);
      })
      .map((button, index) => {
        if (!button.dataset.testid && !button.dataset.suxiE2eButtonId) {
          window.__suxiE2EButtonSeq += 1;
          button.dataset.suxiE2eButtonId = String(window.__suxiE2EButtonSeq);
        }
        const text = (button.innerText || button.textContent || button.getAttribute('aria-label') || button.title || '').trim().replace(/\s+/g, ' ');
        const classPart = String(button.className || '').replace(/\s+/g, ' ').slice(0, 120);
        return {
          id: button.dataset.suxiE2eButtonId || '',
          testId: button.dataset.testid || '',
          index,
          signature: [button.dataset.testid || '', text, button.type || '', button.title || '', button.getAttribute('aria-label') || '', classPart].join('|'),
          text,
          type: button.type || '',
          title: button.title || '',
        };
      });
  });
}

async function currentPagePath(page) {
  return page.locator('main').first().getAttribute('data-current-page').catch(() => '');
}

async function waitForStablePagePath(page, settleMs = 500, timeoutMs = 2500) {
  await page.evaluate(() => {
    window.__suxiE2EStablePath = null;
  }).catch(() => {});

  const handle = await page.waitForFunction(
    ({ requiredStableMs }) => {
      const path = document.querySelector('main')?.getAttribute('data-current-page') || '';
      const now = Date.now();
      const state = window.__suxiE2EStablePath || { path, stableSince: now };
      if (state.path !== path) {
        state.path = path;
        state.stableSince = now;
      }
      window.__suxiE2EStablePath = state;
      return now - state.stableSince >= requiredStableMs ? state.path : false;
    },
    { requiredStableMs: settleMs },
    { timeout: timeoutMs, polling: 100 },
  ).catch(() => null);

  return handle ? handle.jsonValue() : currentPagePath(page);
}

async function ensureModulePage(page, mod) {
  const expectedPath = modulePath(mod);
  const actualPath = await currentPagePath(page);
  if (actualPath && actualPath !== expectedPath) {
    let lastError = null;
    for (let attempt = 1; attempt <= 3; attempt += 1) {
      try {
        await goModule(page, mod);
        await page.getByTestId(pageTestIdForModule(mod)).or(page.locator('main')).first().waitFor({ state: 'visible', timeout: 5000 });
        const settledPath = await waitForStablePagePath(page, 500, 2500);
        if (!settledPath || settledPath === expectedPath) {
          return { restored: true, from: actualPath, to: expectedPath, attempts: attempt };
        }
      } catch (error) {
        lastError = error;
      }
      await waitForStablePagePath(page, 200, 500 * attempt).catch(() => currentPagePath(page));
    }
    if (lastError) throw lastError;
    return { restored: true, from: actualPath, to: expectedPath, attempts: 3 };
  }
  return { restored: false, from: actualPath || expectedPath, to: expectedPath };
}

async function buttonUsable(locator) {
  return locator.evaluate((button) => {
    const style = window.getComputedStyle(button);
    return !button.disabled
      && style.visibility !== 'hidden'
      && style.display !== 'none'
      && !!(button.offsetWidth || button.offsetHeight || button.getClientRects().length);
  }).catch(() => false);
}

async function firstUsableButton(locator) {
  const count = await locator.count().catch(() => 0);
  for (let index = 0; index < count; index += 1) {
    const candidate = locator.nth(index);
    if (await buttonUsable(candidate)) return candidate;
  }
  return null;
}

async function relocateButton(page, target) {
  if (target.testId) {
    const byTestId = await firstUsableButton(page.getByTestId(target.testId));
    if (byTestId) return byTestId;
  }
  if (target.id) {
    const byGeneratedId = await firstUsableButton(page.locator(`[data-suxi-e2e-button-id="${target.id}"]`));
    if (byGeneratedId) return byGeneratedId;
  }

  const relocated = await page.evaluate((buttonTarget) => {
    window.__suxiE2EButtonSeq = window.__suxiE2EButtonSeq || 0;
    const isVisible = (button) => {
      const style = window.getComputedStyle(button);
      return !button.disabled
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !!(button.offsetWidth || button.offsetHeight || button.getClientRects().length);
    };
    const makeSignature = (button) => {
      const text = (button.innerText || button.textContent || button.getAttribute('aria-label') || button.title || '').trim().replace(/\s+/g, ' ');
      const classPart = String(button.className || '').replace(/\s+/g, ' ').slice(0, 120);
      return {
        text,
        signature: [button.dataset.testid || '', text, button.type || '', button.title || '', button.getAttribute('aria-label') || '', classPart].join('|'),
      };
    };
    const buttons = Array.from(document.querySelectorAll('#app button')).filter((button) => {
      if (button.closest('aside')) return false;
      return isVisible(button);
    });
    const match = buttons.find((button) => {
      const meta = makeSignature(button);
      return (buttonTarget.testId && button.dataset.testid === buttonTarget.testId)
        || (buttonTarget.signature && meta.signature === buttonTarget.signature)
        || (buttonTarget.text && meta.text === buttonTarget.text);
    });
    if (!match) return null;
    if (!match.dataset.suxiE2eButtonId) {
      window.__suxiE2EButtonSeq += 1;
      match.dataset.suxiE2eButtonId = String(window.__suxiE2EButtonSeq);
    }
    return {
      id: match.dataset.suxiE2eButtonId,
      testId: match.dataset.testid || '',
      ...makeSignature(match),
    };
  }, target).catch(() => null);

  if (!relocated?.id) return null;
  return firstUsableButton(page.locator(`[data-suxi-e2e-button-id="${relocated.id}"]`));
}

async function clickVisibleButtons(page, loop, mod) {
  const clicked = new Set();
  let clickedCount = 0;
  let skippedCount = 0;
  let failedCount = 0;
  let passWithoutNewButton = 0;

  while (passWithoutNewButton < 2) {
    const restoreState = await ensureModulePage(page, mod);
    if (restoreState.restored) {
      pageEvents.push({
        type: 'module-page-restored',
        category: 'selector-or-dom-state',
        loop,
        module: mod,
        from: restoreState.from,
        to: restoreState.to,
        timestamp: new Date().toISOString(),
      });
    }
    const buttons = await collectVisibleButtons(page);
    if (clicked.size >= maxButtonsPerModule) break;

    const target = buttons.find((button) => !clicked.has(button.signature));
    if (!target) {
      passWithoutNewButton += 1;
      continue;
    }

    clicked.add(target.signature);
    const buttonText = target.text || target.testId || `[button:${target.id}]`;
    if (isDangerousButton(buttonText)) {
      skippedCount += 1;
      buttonResults.push({
        loop,
        module: mod,
        button: buttonText,
        testId: target.testId,
        status: 'skipped',
        category: 'safe-skip',
        signature: target.signature,
        reason: 'dangerous-button-guard',
        timestamp: new Date().toISOString(),
      });
      continue;
    }

    const apiStart = apiEvents.length;
    try {
      const buttonLocator = await relocateButton(page, target);
      if (!buttonLocator) {
        skippedCount += 1;
        buttonResults.push({
          loop,
          module: mod,
          button: buttonText,
          testId: target.testId,
          status: 'skipped',
          category: 'selector-or-dom-state',
          signature: target.signature,
          reason: 'button-unavailable-after-rerender',
          timestamp: new Date().toISOString(),
        });
        continue;
      }
      const waitResult = await waitForApiOrState(page, () => buttonLocator.click({ timeout: 4000 }), {
        stateLocator: page.locator('main'),
      });
      const expectedPath = modulePath(mod);
      let afterClickPath = await currentPagePath(page);
      if ((!afterClickPath || afterClickPath === expectedPath) && isPageSwitchButton(target)) {
        afterClickPath = await waitForStablePagePath(page, 300, 1000);
      }
      if (afterClickPath && afterClickPath !== expectedPath) {
        afterClickPath = await waitForStablePagePath(page);
      }
      clickedCount += 1;
      buttonResults.push({
        loop,
        module: mod,
        button: buttonText,
        testId: target.testId,
        status: 'clicked',
        signature: target.signature,
        pageChangedTo: afterClickPath && afterClickPath !== expectedPath ? afterClickPath : null,
        apiEvents: apiEvents.length - apiStart,
        responseStatus: waitResult.response ? waitResult.response.status() : null,
        timestamp: new Date().toISOString(),
      });
      if (afterClickPath && afterClickPath !== expectedPath) {
        await ensureModulePage(page, mod);
      }
    } catch (error) {
      failedCount += 1;
      buttonResults.push({
        loop,
        module: mod,
        button: buttonText,
        testId: target.testId,
        status: 'fail',
        category: classifyError(error, 'product-bug'),
        signature: target.signature,
        error: shortError(error),
        timestamp: new Date().toISOString(),
      });
    }
  }

  return { clickedCount, skippedCount, failedCount };
}

test.beforeAll(() => {
  ensureCleanDir(outputDir);
  fs.mkdirSync(screenshotDir, { recursive: true });
  databaseBackup = backupDatabase();
  pageEvents.push(databaseBackup);
});

test.afterAll(() => {
  pageEvents.push(restoreDatabase(databaseBackup));
  writeReports();
  writeLatestRunManifest(suiteOutput);
  console.log('全量点击测试完成，JSON/CSV/summary 报告已生成');
});

test('模块关键功能全量点击验证（轮数可配置）', async ({ page }) => {
  installDiagnostics(page, { apiEvents, pageEvents });
  await login(page, config);

  for (let loop = 1; loop <= loopCount; loop += 1) {
    for (const mod of MODULES) {
      const started = Date.now();
      progress.current = {
        loop,
        module: mod,
        startedAt: new Date().toISOString(),
        completed: results.length,
      };
      writeReports();
      try {
        await goModule(page, mod);
        const fieldStats = await fillVisibleFields(page, loop, mod);
        const buttonStats = await clickVisibleButtons(page, loop, mod);
        results.push({
          loop,
          module: mod,
          status: 'success',
          ...fieldStats,
          ...buttonStats,
          ms: Date.now() - started,
          timestamp: new Date().toISOString(),
        });
        console.log(`[full-click] loop=${loop}/${loopCount} module=${mod} clicked=${buttonStats.clickedCount} skipped=${buttonStats.skippedCount} failed=${buttonStats.failedCount}`);
      } catch (error) {
        const prefix = `${String(loop).padStart(3, '0')}_${safeFileName(mod)}`;
        const screenshot = path.join(screenshotDir, `${prefix}.png`);
        const html = path.join(screenshotDir, `${prefix}.html`);
        await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
        fs.writeFileSync(html, await page.content());
        results.push({
          loop,
          module: mod,
          status: 'fail',
          category: classifyError(error, 'product-bug'),
          error: shortError(error),
          screenshot,
          html,
          ms: Date.now() - started,
          timestamp: new Date().toISOString(),
        });
        console.log(`[full-click] loop=${loop}/${loopCount} module=${mod} failed=${shortError(error)}`);
        await login(page, config).catch(() => {});
      }
      writeReports();
    }
  }
});
