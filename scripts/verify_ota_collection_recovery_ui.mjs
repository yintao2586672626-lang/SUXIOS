import assert from 'node:assert/strict';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';
import { recoverySources, recoveryTask } from '../tests/automation/helpers/ota_recovery_fixture.mjs';

const root = fileURLToPath(new URL('../', import.meta.url));
const output = path.join(root, 'output/long-goal/ui');
await mkdir(output, { recursive: true });
const [template, css, tailwind, vue, sources] = await Promise.all([
  readFile(path.join(root, 'resources/frontend/templates/fragments/35-page-online-data.html'), 'utf8'),
  readFile(path.join(root, 'public/style.min.css'), 'utf8'),
  readFile(path.join(root, 'public/tailwind.min.css'), 'utf8'),
  readFile(path.join(root, 'public/vue.global.prod.js'), 'utf8'), recoverySources(),
]);
const start = template.indexOf('<section data-testid="local-collector-collection-receipts"');
const panel = template.slice(start, template.indexOf('</section>', start) + '</section>'.length);
assert.ok(start > 0 && panel.includes('local-collector-recovery'));
const fixtures = ['success', 'partial', 'failed', 'unknown', 'recovered_success'].map((state, index) => recoveryTask(state, 41 + index));
const browser = await chromium.launch({ headless: true });
const errors = [];
const checks = [];
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, offline: true });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (/^https?:/.test(request.url())) errors.push('Unexpected external request'); });
  await page.setContent(`<html lang="zh-CN"><head><meta charset="utf-8"><style>${tailwind}\n${css}</style></head><body class="bg-slate-50 p-4"><h1 class="mb-3 text-lg font-semibold">Synthetic · 采集恢复状态验收</h1><p class="mb-3 text-xs">隔离合成数据；不连接酒店或 OTA，不代表现场采集。</p><main id="app">${panel}</main></body></html>`);
  await page.addScriptTag({ content: vue });
  await page.addScriptTag({ content: `
    const { ref, computed } = Vue;
    ${sources.projection}
    const fixtures = ${JSON.stringify(fixtures)};
    const localCollectorRecoveryViews = ref({});
    let localCollectorRecoveryRequestSequence = 0;
    const localCollectorCollectionTaskRows = computed(() => fixtures.map(task => {
      const row = buildLocalCollectorCollectionReceipt(task);
      return { ...row, hotelName: '合成示例酒店', recoveryView: localCollectorRecoveryViews.value[row.evidenceKey] };
    }));
    const captureAuthSession = () => 1;
    const isAuthSessionCurrent = value => value === 1;
    window.recoveryCalls = [];
    const request = async (url, options) => {
      const body = JSON.parse(options.body);
      window.recoveryCalls.push({ url, body });
      await new Promise(resolve => setTimeout(resolve, 80));
      const task = fixtures.find(item => item.id === Number(url.match(/tasks\\/(\\d+)/)[1]));
      return { code: 200, data: { recovery: task.recovery_item, message: '合成核对完成：保留原酒店、日期与结果' } };
    };
    const loadLocalCollectorStatus = async () => {};
    ${sources.action}
    Vue.createApp({ setup() { return {
      localCollectorCollectionTaskRows, localCollectorLoading: false, localCollectorError: '',
      localCollectorPlatformText: value => value === 'meituan' ? '美团' : '携程',
      runLocalCollectorRecovery, loadLocalCollectorTaskEvidence: () => {},
    }; } }).mount('#app');
  ` });
  await page.locator('[data-testid="local-collector-receipt-44"]').waitFor();
  const labels = await page.locator('[data-testid="local-collector-receipt-state"]').allTextContents();
  assert.equal(new Set(labels).size, 5);
  assert.ok(labels.includes('恢复后成功'));
  const unknown = page.locator('[data-testid="local-collector-receipt-44"]');
  assert.equal(await unknown.locator('[data-testid="local-collector-recovery-backfill"]').count(), 0);
  await unknown.locator('[data-testid="local-collector-recovery-reconcile"]').click();
  await unknown.locator('[role="status"]').waitFor();
  const calls = await page.evaluate(() => window.recoveryCalls);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].body.scope.business_date, '2026-09-01');
  assert.equal(calls[0].body.action, 'reconcile');
  checks.push('five_distinct_states', 'unknown_has_no_backfill', 'click_posts_original_scope', 'recovery_feedback_visible');
  for (const [name, width] of [['desktop', 1440], ['mobile', 390]]) {
    await page.setViewportSize({ width, height: 1000 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, name + ' horizontal overflow');
    await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true });
    checks.push(name + '_no_horizontal_overflow');
  }
  assert.deepEqual(errors, []);
  const result = { status: 'passed', evidence: 'synthetic_isolated_browser', checks, labels, external_requests: 0, errors };
  await writeFile(path.join(output, 'result.json'), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
} finally { await browser.close(); }
