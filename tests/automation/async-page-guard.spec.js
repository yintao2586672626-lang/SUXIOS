const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const {
  MODULE,
  appMain,
  classifyError,
  createSuiteOutput,
  ensureCleanDir,
  goModule,
  installDiagnostics,
  login,
  modulePath,
  safeFileName,
  shortError,
  summarize,
  writeLatestRunManifest,
  writeJsonCsv,
} = require('./e2e-helpers');

const suiteOutput = createSuiteOutput('async-page-guard');
const { outputDir, screenshotDir } = suiteOutput;
const results = [];
const apiEvents = [];
const pageEvents = [];

const fixtures = {
  strategy: {
    id: 9001,
    marker: 'codex_async_old_strategy_should_not_render',
    list: {
      id: 9001,
      project_name: 'codex_async_old_strategy_should_not_render',
      city: 'Shanghai',
      district: 'Pudong',
      total_score: 88,
      risk_level: 'low',
      created_at: '2026-05-17 10:00:00',
    },
    detail: {
      id: 9001,
      project_name: 'codex_async_old_strategy_should_not_render',
      city: 'Shanghai',
      district: 'Pudong',
      total_score: 88,
      risk_level: 'low',
      decision: 'hold',
      input: {
        project_name: 'codex_async_old_strategy_should_not_render',
        city: 'Shanghai',
        district: 'Pudong',
        property_area: 3200,
        room_count: 88,
        monthly_rent: 180000,
      },
      scores: {},
      recommendation: {
        recommended_model: 'midscale',
        target_customer: 'business',
        competition_pressure: 'medium',
        decision_direction: 'hold',
      },
      data_snapshot: {},
    },
  },
  simulation: {
    id: 9002,
    marker: 'codex_async_old_simulation_should_not_render',
    list: {
      id: 9002,
      project_name: 'codex_async_old_simulation_should_not_render',
      monthly_net_cashflow: 52000,
      payback_months: 38,
      risk_level: 'medium',
      created_at: '2026-05-17 10:01:00',
    },
    detail: {
      id: 9002,
      project_name: 'codex_async_old_simulation_should_not_render',
      input: {
        roomCount: 88,
        adr: 328,
        occupancyRate: 76,
      },
      result: {
        monthlyRevenue: 658944,
        monthlyNetCashflow: 52000,
        revPAR: 249.28,
        paybackMonths: 38,
      },
      scenarios: [],
      risk_hints: [],
    },
  },
  feasibility: {
    id: 9003,
    marker: 'codex_async_old_feasibility_should_not_render',
    list: {
      id: 9003,
      project_name: 'codex_async_old_feasibility_should_not_render',
      conclusion_grade: 'B',
      payback_months: 42,
      created_at: '2026-05-17 10:02:00',
    },
    detail: {
      id: 9003,
      input: {
        project_name: 'codex_async_old_feasibility_should_not_render',
        city: 'Shanghai',
        room_count: 88,
      },
      report: {
        conclusion_grade: 'B',
        conclusion_text: 'fixture',
        core_reason: 'async guard fixture',
        summary: {
          project_name: 'codex_async_old_feasibility_should_not_render',
          location: 'Shanghai',
          room_count: 88,
          total_investment: 2600000,
          payback_months: 42,
        },
      },
    },
  },
  expansion: {
    id: 9004,
    marker: 'codex_async_old_expansion_should_not_render',
    list: {
      id: 9004,
      record_type: 'market',
      project_name: 'codex_async_old_expansion_should_not_render',
      city_area: 'Shanghai Pudong',
      decision: 'hold',
      risk_level: 'medium',
      created_at: '2026-05-17 10:03:00',
    },
    detail: {
      id: 9004,
      record_type: 'market',
      project_name: 'codex_async_old_expansion_should_not_render',
      city_area: 'Shanghai Pudong',
      decision: 'hold',
      risk_level: 'medium',
      input: {
        project_name: 'codex_async_old_expansion_should_not_render',
        city: 'Shanghai',
        business_area: 'Pudong',
      },
      result: {
        decision: 'hold',
        risk_level: 'medium',
      },
    },
  },
  transfer: {
    id: 9005,
    marker: 'codex_async_old_transfer_should_not_render',
    list: {
      id: 9005,
      record_type: 'pricing',
      hotel_id: 1,
      hotel_name: 'codex_async_old_transfer_should_not_render',
      source_date: '2026-05-17',
      decision: 'hold',
      risk_level: 'medium',
      created_at: '2026-05-17 10:04:00',
    },
    detail: {
      id: 9005,
      record_type: 'pricing',
      hotel_id: 1,
      hotel_name: 'codex_async_old_transfer_should_not_render',
      input: {
        hotel_name: 'codex_async_old_transfer_should_not_render',
        room_count: 88,
        adr: 328,
        occupancy_rate: 76,
      },
      result: {
        suggestion: 'hold',
        risk_level: 'medium',
      },
      snapshot: {},
    },
  },
};

const cases = [
  {
    name: 'strategy-detail',
    source: MODULE.STRATEGY,
    target: MODULE.SIMULATION,
    actionTestId: 'history-strategy-reuse-9001',
    detailPath: '/api/strategy/records/9001',
    marker: fixtures.strategy.marker,
  },
  {
    name: 'simulation-detail',
    source: MODULE.SIMULATION,
    target: MODULE.FEASIBILITY,
    actionTestId: 'history-simulation-reuse-9002',
    detailPath: '/api/simulation/records/9002',
    marker: fixtures.simulation.marker,
  },
  {
    name: 'feasibility-detail',
    source: MODULE.FEASIBILITY,
    target: MODULE.MARKET_EVALUATION,
    actionTestId: 'history-feasibility-reuse-9003',
    detailPath: '/api/agent/feasibility-report/detail/9003',
    marker: fixtures.feasibility.marker,
  },
  {
    name: 'expansion-detail',
    source: MODULE.MARKET_EVALUATION,
    target: MODULE.ASSET_PRICING,
    actionTestId: 'history-expansion-reuse-9004',
    detailPath: '/api/expansion/records/9004',
    marker: fixtures.expansion.marker,
  },
  {
    name: 'transfer-detail',
    source: MODULE.ASSET_PRICING,
    target: MODULE.DATA_DASHBOARD,
    actionTestId: 'history-transfer-reuse-9005',
    detailPath: '/api/transfer/records/9005',
    marker: fixtures.transfer.marker,
    markerMayAppearInTargetHistory: true,
  },
];

function ok(data) {
  return {
    code: 200,
    message: 'ok',
    data,
    time: Math.floor(Date.now() / 1000),
  };
}

function routeFixture(method, pattern, data, delayed = false) {
  return { method, pattern, data, delayed };
}

async function installHistoryFixtures(page, delayMs = 1200) {
  const routes = [
    routeFixture('POST', /^\/api\/strategy\/simulate$/, ok({
      record_id: fixtures.strategy.id,
      total_score: 88,
      risk_level: 'low',
      decision: 'fixture',
      scores: {},
      recommendation: fixtures.strategy.detail.recommendation,
      data_snapshot: {},
    })),
    routeFixture('GET', /^\/api\/strategy\/records$/, ok({ list: [fixtures.strategy.list] })),
    routeFixture('GET', /^\/api\/strategy\/records\/9001$/, ok(fixtures.strategy.detail), true),

    routeFixture('GET', /^\/api\/simulation\/records$/, ok({ list: [fixtures.simulation.list] })),
    routeFixture('GET', /^\/api\/simulation\/records\/9002$/, ok(fixtures.simulation.detail), true),

    routeFixture('GET', /^\/api\/agent\/feasibility-report\/list$/, ok({ list: [fixtures.feasibility.list] })),
    routeFixture('GET', /^\/api\/agent\/feasibility-report\/detail\/9003$/, ok(fixtures.feasibility.detail), true),

    routeFixture('GET', /^\/api\/expansion\/records$/, ok({ list: [fixtures.expansion.list] })),
    routeFixture('GET', /^\/api\/expansion\/records\/9004$/, ok(fixtures.expansion.detail), true),

    routeFixture('GET', /^\/api\/transfer\/records$/, ok({ list: [fixtures.transfer.list] })),
    routeFixture('GET', /^\/api\/transfer\/records\/9005$/, ok(fixtures.transfer.detail), true),
  ];

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const fixture = routes.find((item) => item.method === request.method() && item.pattern.test(url.pathname));
    if (!fixture) {
      await route.continue();
      return;
    }

    if (fixture.delayed) {
      await new Promise((resolve) => setTimeout(resolve, delayMs));
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(fixture.data),
    });
  });
}

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

for (const scenario of cases) {
  test(`async detail response must not switch page: ${scenario.name}`, async ({ page }) => {
    installDiagnostics(page, { apiEvents, pageEvents });
    await installHistoryFixtures(page);
    await login(page);
    await goModule(page, scenario.source);

    const started = Date.now();
    const targetPath = modulePath(scenario.target);

    try {
      const action = page.getByTestId(scenario.actionTestId);
      await expect(action).toBeVisible({ timeout: 5000 });
      const detailResponse = page.waitForResponse(
        (response) => response.url().includes(scenario.detailPath) && response.status() === 200,
        { timeout: 5000 },
      );
      await action.click({ timeout: 4000 });
      await goModule(page, scenario.target);

      const response = await detailResponse;
      expect(response.ok()).toBeTruthy();

      await expect.poll(
        async () => appMain(page).getAttribute('data-current-page'),
        { timeout: 2000, intervals: [100, 250, 500, 1000] },
      ).toBe(targetPath);
      if (!scenario.markerMayAppearInTargetHistory) {
        await expect(appMain(page)).not.toContainText(scenario.marker, { timeout: 1000 });
      }

      const badApiEvents = apiEvents.filter((event) => event.category === 'api-error' || event.ok === false);
      expect(badApiEvents, JSON.stringify(badApiEvents, null, 2)).toHaveLength(0);

      results.push({
        scenario: scenario.name,
        source: scenario.source,
        target: scenario.target,
        actionTestId: scenario.actionTestId,
        status: 'success',
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
    } catch (error) {
      const prefix = `${scenario.name}_${safeFileName(scenario.source)}`;
      const screenshot = path.join(screenshotDir, `${prefix}.png`);
      const html = path.join(screenshotDir, `${prefix}.html`);
      await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
      fs.writeFileSync(html, await page.content().catch(() => ''));
      results.push({
        scenario: scenario.name,
        source: scenario.source,
        target: scenario.target,
        actionTestId: scenario.actionTestId,
        status: 'fail',
        category: classifyError(error, 'product-bug'),
        error: shortError(error),
        screenshot,
        html,
        ms: Date.now() - started,
        timestamp: new Date().toISOString(),
      });
      throw error;
    }
  });
}
