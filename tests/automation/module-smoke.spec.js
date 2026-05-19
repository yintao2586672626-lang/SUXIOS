const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { Parser } = require('json2csv');
const {
  MODULES,
  appMain,
  classifyError,
  createSuiteOutput,
  goModule,
  login,
  safeFileName,
  semanticInputValue,
  waitForApiOrState,
  writeLatestRunManifest,
} = require('./e2e-helpers');

const modules = MODULES;
const suiteOutput = createSuiteOutput('module-smoke');
const { outputDir, screenshotDir } = suiteOutput;
const results = [];
const MIN_VERIFY_ITERATIONS = 1;
const MAX_VERIFY_ITERATIONS = 5;
const DEFAULT_VERIFY_ITERATIONS = 1;
const mutateForms = process.env.E2E_MUTATE === '1';
const mutationSkipButtonPattern = /\u53d6\u6d88|\u5220\u9664|\u5f52\u6863|\u9000\u51fa|\u91cd\u7f6e|Cancel|Delete|Archive|Reset/i;

function parseVerifyIterations() {
  const raw = process.env.E2E_LOOP || process.env.E2E_ITERATIONS || DEFAULT_VERIFY_ITERATIONS;
  const value = Number(raw);
  if (!Number.isFinite(value)) return DEFAULT_VERIFY_ITERATIONS;
  return Math.min(
    MAX_VERIFY_ITERATIONS,
    Math.max(MIN_VERIFY_ITERATIONS, Math.floor(value)),
  );
}

const iterations = parseVerifyIterations();

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
});

function ensureOutputDirs() {
  fs.mkdirSync(screenshotDir, { recursive: true });
}

function prepareOutputDirs() {
  fs.mkdirSync(outputDir, { recursive: true });
  fs.rmSync(screenshotDir, { recursive: true, force: true });
  ensureOutputDirs();
}

async function isUsable(locator) {
  return locator.evaluate((element) => {
    const style = window.getComputedStyle(element);
    return !element.disabled
      && !element.readOnly
      && style.visibility !== 'hidden'
      && style.display !== 'none'
      && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
  }).catch(() => false);
}

async function fillSemanticValue(input) {
  const tag = await input.evaluate((element) => element.tagName.toLowerCase());
  if (tag === 'select') {
    const optionCount = await input.locator('option').count();
    if (optionCount > 0) {
      await input.selectOption({ index: Math.min(1, optionCount - 1) });
    }
    return;
  }

  const type = await input.evaluate((element) => element.type || 'text');
  if (type === 'checkbox' || type === 'radio') {
    await input.setChecked(true);
    return;
  }
  if (type === 'file') return;

  await input.fill(semanticInputValue({
    ariaLabel: await input.getAttribute('aria-label'),
    name: await input.getAttribute('name'),
    placeholder: await input.getAttribute('placeholder'),
    testId: await input.getAttribute('data-testid'),
    type,
  }));
}

async function inspectOrMutateMain(page) {
  const main = appMain(page);
  const inputs = main.locator('input, textarea, select');
  const buttons = main.locator('button');
  const inputCount = await inputs.count();
  const buttonCount = await buttons.count();

  if (!mutateForms) {
    return { inputCount, buttonCount, mutated: false };
  }

  for (let index = 0; index < inputCount; index += 1) {
    const input = inputs.nth(index);
    if (await isUsable(input)) {
      await fillSemanticValue(input);
    }
  }

  for (let index = 0; index < buttonCount; index += 1) {
    const button = buttons.nth(index);
    const text = (await button.innerText().catch(() => '')).trim();
    if (!text || mutationSkipButtonPattern.test(text) || !(await isUsable(button))) {
      continue;
    }
    await waitForApiOrState(page, () => button.click({ timeout: 2000 }).catch(() => {}), {
      stateLocator: appMain(page),
    });
  }

  return { inputCount, buttonCount, mutated: true };
}

test.beforeAll(() => {
  prepareOutputDirs();
});

for (const mod of modules) {
  for (let iteration = 1; iteration <= iterations; iteration += 1) {
    test(`${mod} iteration ${iteration}`, async ({ page }) => {
      try {
        await login(page);
        await goModule(page, mod);

        const main = appMain(page);
        await expect(main).toContainText(mod, { timeout: 5000 });
        const pageStats = await inspectOrMutateMain(page);

        results.push({
          module: mod,
          iteration,
          status: 'success',
          error: null,
          ...pageStats,
          timestamp: new Date().toISOString(),
        });
      } catch (error) {
        const filePrefix = `${safeFileName(mod)}_${iteration}`;
        const screenshotPath = path.join(screenshotDir, `${filePrefix}.png`);
        const htmlPath = path.join(screenshotDir, `${filePrefix}.html`);
        await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => {});
        fs.writeFileSync(htmlPath, await page.content().catch(() => ''));

        results.push({
          module: mod,
          iteration,
          status: 'fail',
          category: classifyError(error),
          error: error.message,
          screenshot: screenshotPath,
          html: htmlPath,
          timestamp: new Date().toISOString(),
        });

        throw error;
      }
    });
  }
}

test.afterAll(() => {
  ensureOutputDirs();
  fs.writeFileSync(path.join(outputDir, 'results.json'), JSON.stringify(results, null, 2));
  const parser = new Parser();
  fs.writeFileSync(path.join(outputDir, 'results.csv'), parser.parse(results));
  writeLatestRunManifest(suiteOutput);
  console.log('module smoke test complete; JSON/CSV reports generated.');
});
