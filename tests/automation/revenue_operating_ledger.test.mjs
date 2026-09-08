import assert from 'node:assert/strict';
import { readFileSync, mkdirSync, mkdtempSync, existsSync, unlinkSync, rmdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';
import { chromium } from 'playwright-core';
import { parse, compile } from '@vue/compiler-dom';

function savedSyntheticModel() {
  const directory = mkdtempSync(path.join(os.tmpdir(), 'suxi-ledger-model-'));
  const target = path.join(directory, 'model.json');
  const php = process.env.PHP_BINARY || process.env.SUXI_PHP
    || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
  try {
    // Exercise the real attestation, isolated SQLite save and exact readback each run.
    // A parallel backend job or a previous local run must not supply this fixture.
    execFileSync(php, ['vendor/bin/phpunit', '--colors=never',
      '--filter', 'testServerAttestationAndSqliteSnapshotSaveDuplicateExactVersionRecoveryAndOldData',
      'tests/RevenueOperatingLedgerServiceTest.php'], {
      env: { ...process.env, SUXI_LEDGER_MODEL_FIXTURE_OUTPUT: target },
      windowsHide: true, timeout: 60_000, stdio: 'pipe',
    });
    return JSON.parse(readFileSync(target, 'utf8'));
  } finally {
    if (existsSync(target)) unlinkSync(target);
    rmdirSync(directory);
  }
}
const model = savedSyntheticModel();
const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/revenue-overview-contract-static.js', 'utf8'), context);
vm.runInNewContext(readFileSync('public/revenue-cockpit-static.js', 'utf8'), context);
vm.runInNewContext(readFileSync('public/revenue-ai-static.js', 'utf8'), context);
const helpers = context.window.SUXI_REVENUE_AI_STATIC;

test('ledger download preserves the saved model, missing dates, unknown remainder and exact sources', () => {
  const rows = helpers.buildRevenueCockpitDownloadRows(model).filter(row => row.section === '经营底账 · 来源与差额');
  assert.equal(rows.length, model.operatingLedger.metrics.length + model.operatingLedger.differences.length);
  const order = rows.find(row => row.card === '渠道订单额');
  assert.equal(order.display, 1000);
  const missing = rows.find(row => row.card === '住宿房费');
  assert.equal(missing.display, '未形成完整可信金额');
  assert.equal(missing.missing_state, '2026-08-20');
  const difference = rows.find(row => row.card === '订单额与结算差额');
  assert.match(difference.explanation, /未解释：100/);
  assert.match(difference.evidence, /2026-07-31/);
  assert.match(order.evidence, new RegExp(model.operatingLedger.version));
  assert.match(helpers.buildRevenueCockpitCsv(model), /online_daily_data#101/);
  assert.match(helpers.buildRevenueCockpitCsv(model), /synthetic/);
});

test('actual ledger template renders, drills into evidence and recovers on desktop and mobile using synthetic data', async () => {
  const fragment = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
  const ast = parse(fragment);
  const find = node => {
    if (node.type === 1 && node.props.some(prop => prop.type === 6 && prop.name === 'data-testid' && prop.value?.content === 'revenue-operating-ledger')) return node;
    for (const child of node.children || []) { const found = find(child); if (found) return found; }
  };
  const section = find(ast);
  assert.ok(section, 'Actual revenue analysis ledger section exists');
  const template = fragment.slice(section.loc.start.offset, section.loc.end.offset);
  const compiled = compile(template, { mode: 'function' }).code;
  const browser = await chromium.launch({ headless: true,
    ...(process.platform === 'win32' ? { channel: 'msedge' } : {}) });
  mkdirSync('output/long-goal', { recursive: true });
  const errors = [];
  try {
    for (const [name, width] of [['desktop', 1440], ['mobile', 390]]) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      page.on('pageerror', error => errors.push(error.message));
      await page.setContent('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="app"></div></body></html>');
      await page.addStyleTag({ content: readFileSync('public/tailwind.min.css', 'utf8') + '\n' + readFileSync('public/style.min.css', 'utf8') + '\nhtml,body{margin:0;padding:8px;max-width:100%;}*{box-sizing:border-box}' });
      await page.addScriptTag({ content: readFileSync('public/vue.runtime.global.prod.js', 'utf8') });
      await page.evaluate(({ model, compiled }) => {
        window.ledgerApp = Vue.createApp({ data: () => ({ revenueCockpitModel: model }), render: new Function('Vue', compiled)(Vue) }).mount('#app');
      }, { model, compiled });
      const root = page.getByTestId('revenue-operating-ledger');
      assert.equal(await root.count(), 1);
      assert.match(await root.textContent(), /synthetic 测试样本/);
      await page.locator('[data-ledger-metric="ctrip:order_amount"] > summary').click();
      assert.equal(await page.locator('[data-ledger-metric="ctrip:order_amount"]').getAttribute('open'), '');
      assert.match(await page.locator('[data-ledger-metric="ctrip:order_amount"]').innerText(), /online_daily_data#101/);
      await page.locator('[data-ledger-difference="ctrip"] > summary').click();
      assert.match(await page.locator('[data-ledger-difference="ctrip"]').innerText(), /2026-07-31/);
      assert.match(await page.locator('[data-ledger-difference="ctrip"]').innerText(), /未解释：100/);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${name} must not overflow`);
      await page.screenshot({ path: path.resolve(`output/long-goal/ledger-${name}.png`), fullPage: true });
      // A failed/changed-scope model clears the old ledger. Reinstating the saved model restores its exact version.
      await page.evaluate(() => { window.ledgerApp.revenueCockpitModel = { status: 'failed' }; });
      assert.equal(await root.count(), 0);
      await page.evaluate(model => { window.ledgerApp.revenueCockpitModel = model; }, model);
      assert.match(await root.innerText(), new RegExp(model.operatingLedger.version));
      await page.close();
    }
    assert.deepEqual(errors, []);
  } finally { await browser.close(); }
});
