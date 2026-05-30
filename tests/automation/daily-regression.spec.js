const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const {
  MODULES,
  classifyError,
  collectMainStats,
  createSuiteOutput,
  ensureCleanDir,
  goModule,
  installDiagnostics,
  login,
  safeFileName,
  shortError,
  summarize,
  writeLatestRunManifest,
  writeJsonCsv,
} = require('./e2e-helpers');

const suiteOutput = createSuiteOutput('daily-regression');
const { outputDir, screenshotDir } = suiteOutput;
const results = [];
const apiEvents = [];
const pageEvents = [];

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 4000,
  navigationTimeout: 10000,
});

test.beforeAll(() => {
  ensureCleanDir(outputDir);
  fs.mkdirSync(screenshotDir, { recursive: true });
});

test.afterAll(() => {
  writeJsonCsv(outputDir, 'results', results);
  writeJsonCsv(outputDir, 'api-events', apiEvents);
  writeJsonCsv(outputDir, 'page-events', pageEvents);
  fs.writeFileSync(path.join(outputDir, 'summary.json'), JSON.stringify(
    summarize({ results, apiEvents, pageEvents }),
    null,
    2,
  ));
  writeLatestRunManifest(suiteOutput);
});

test('daily fast regression: login, module rendering, and API health', async ({ page }) => {
  installDiagnostics(page, { apiEvents, pageEvents });
  await login(page);

  for (const mod of MODULES) {
    const started = Date.now();
    try {
      await goModule(page, mod);
      const stats = await collectMainStats(page);
      const warnings = [];
      if (stats.visibleButtons === 0 && stats.visibleInputs === 0 && stats.visibleLinks === 0) {
        warnings.push('no-visible-core-action');
      }
      expect(stats.textLength).toBeGreaterThan(mod.length);

      results.push({
        module: mod,
        status: 'success',
        ...stats,
        warnings: warnings.length ? warnings.join('|') : null,
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
    } catch (error) {
      const prefix = safeFileName(mod);
      const screenshot = path.join(screenshotDir, `${prefix}.png`);
      const html = path.join(screenshotDir, `${prefix}.html`);
      await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
      fs.writeFileSync(html, await page.content());
      results.push({
        module: mod,
        status: 'fail',
        category: classifyError(error, 'product-bug'),
        error: shortError(error),
        screenshot,
        html,
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
      await login(page).catch(() => {});
    }
  }

  const moduleFailures = results.filter((row) => row.status === 'fail');
  const badApiEvents = apiEvents.filter((event) => event.category === 'api-error' || event.ok === false);
  const hardPageEvents = pageEvents.filter((event) => event.category === 'page-error');

  expect(moduleFailures, JSON.stringify(moduleFailures, null, 2)).toHaveLength(0);
  expect(badApiEvents, JSON.stringify(badApiEvents, null, 2)).toHaveLength(0);
  expect(hardPageEvents, JSON.stringify(hardPageEvents, null, 2)).toHaveLength(0);
});
