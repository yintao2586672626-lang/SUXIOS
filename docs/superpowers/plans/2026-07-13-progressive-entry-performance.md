# Progressive Entry Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Project rules prohibit subagent execution unless the user explicitly requests delegation.

**Goal:** Externalize and cache the unchanged Vue bootstrap, remove parser-blocking startup scripts, add deterministic performance budgets, and capture comparable before/after browser evidence without changing any page structure or business behavior.

**Architecture:** Keep the full HTML template in `public/index.html`, mechanically move only the largest inline JavaScript body to `public/app-main.js`, and execute the existing startup dependency chain with ordered `defer` scripts. Extend the existing public-entry guard to validate the combined HTML/application source, then add project-native static and browser performance measurements so later lazy-loading work is driven by evidence.

**Tech Stack:** Vue 3 global build, plain JavaScript, Node.js test runner, Playwright/Chrome, ThinkPHP static router, PowerShell on Windows.

---

## Scope and file map

- Create `public/app-main.js`: unchanged body of the current largest inline `<script>`.
- Modify `public/index.html`: retain all templates; use ordered deferred script tags and an asset-load error renderer.
- Modify `scripts/verify_public_entry_guard.mjs`: read HTML and app source separately, preserve all existing checks, enforce hash/order/externalization.
- Create `tests/automation/app_main_externalization.test.mjs`: executable contract for zero-structure externalization.
- Create `scripts/lib/frontend_performance_metrics.mjs`: pure metric summarization and percentile helpers.
- Create `tests/automation/frontend_performance_metrics.test.mjs`: tests the pure metric calculations.
- Create `scripts/measure_frontend_performance.mjs`: captures login-shell and authenticated-shell browser evidence.
- Create `scripts/lib/frontend_performance_budget.mjs`: collects deterministic entry/resource metrics and evaluates budgets.
- Create `tests/automation/frontend_performance_budget.test.mjs`: tests budget classification.
- Create `scripts/verify_frontend_performance_budget.mjs`: command-line budget gate.
- Modify `package.json`: expose performance measurement and budget commands.
- Update `docs/superpowers/plans/2026-07-13-progressive-entry-performance.md`: mark steps as they complete and record measured output.

This plan intentionally stops before page-specific helper migration, Tailwind pruning, request deduplication, or backend SQL changes. Those become separate evidence-based plans after this entry baseline is complete.

### Task 1: Add the browser metric calculation contract

**Files:**
- Create: `tests/automation/frontend_performance_metrics.test.mjs`
- Create: `scripts/lib/frontend_performance_metrics.mjs`

- [ ] **Step 1: Write the failing metric test**

Create `tests/automation/frontend_performance_metrics.test.mjs`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';
import {
  percentile,
  summarizeFrontendPerformance,
} from '../../scripts/lib/frontend_performance_metrics.mjs';

test('percentile uses the nearest-rank value from finite samples', () => {
  assert.equal(percentile([50, 10, 30, 20, 40], 0.95), 50);
  assert.equal(percentile([10, Number.NaN, 20], 0.5), 10);
  assert.equal(percentile([], 0.95), null);
});

test('summarizeFrontendPerformance reports navigation, paint, resources, and long tasks', () => {
  const summary = summarizeFrontendPerformance({
    navigation: {
      requestStart: 10,
      responseStart: 42.4,
      domInteractive: 310.2,
      domComplete: 520.8,
      loadEventEnd: 560.6,
    },
    paints: [
      { name: 'first-paint', startTime: 125.1 },
      { name: 'first-contentful-paint', startTime: 140.7 },
    ],
    lcp: 420.2,
    longTasks: [{ duration: 58.2 }, { duration: 91.6 }],
    resources: [
      { initiatorType: 'script', transferSize: 1200, duration: 30 },
      { initiatorType: 'link', name: 'http://localhost/style.css', transferSize: 800, duration: 20 },
      { initiatorType: 'fetch', transferSize: 500, duration: 45 },
    ],
  });

  assert.deepEqual(summary, {
    ttfb_ms: 32,
    fcp_ms: 141,
    lcp_ms: 420,
    dom_interactive_ms: 310,
    dom_complete_ms: 521,
    full_load_ms: 561,
    total_requests: 3,
    total_transfer_bytes: 2500,
    js_transfer_bytes: 1200,
    css_transfer_bytes: 800,
    long_task_count: 2,
    long_task_total_ms: 150,
    longest_task_ms: 92,
  });
});
```

- [ ] **Step 2: Run the test and confirm RED**

Run:

```powershell
node --test tests/automation/frontend_performance_metrics.test.mjs
```

Expected: FAIL with `ERR_MODULE_NOT_FOUND` for `scripts/lib/frontend_performance_metrics.mjs`.

- [ ] **Step 3: Implement the pure metric functions**

Create `scripts/lib/frontend_performance_metrics.mjs`:

```js
const finiteNumber = (value, fallback = 0) => Number.isFinite(Number(value))
  ? Number(value)
  : fallback;

const rounded = (value) => Math.round(finiteNumber(value));

export function percentile(values, ratio = 0.95) {
  const samples = values
    .map(Number)
    .filter(Number.isFinite)
    .sort((left, right) => left - right);
  if (!samples.length) return null;
  const boundedRatio = Math.min(1, Math.max(0, Number(ratio) || 0));
  const index = Math.max(0, Math.ceil(samples.length * boundedRatio) - 1);
  return samples[index];
}

export function summarizeFrontendPerformance(snapshot = {}) {
  const navigation = snapshot.navigation || {};
  const paints = Array.isArray(snapshot.paints) ? snapshot.paints : [];
  const resources = Array.isArray(snapshot.resources) ? snapshot.resources : [];
  const longTasks = Array.isArray(snapshot.longTasks) ? snapshot.longTasks : [];
  const fcp = paints.find((entry) => entry?.name === 'first-contentful-paint');
  const resourceBytes = (predicate) => resources
    .filter(predicate)
    .reduce((total, entry) => total + finiteNumber(entry?.transferSize), 0);

  return {
    ttfb_ms: rounded(finiteNumber(navigation.responseStart) - finiteNumber(navigation.requestStart)),
    fcp_ms: fcp ? rounded(fcp.startTime) : null,
    lcp_ms: Number.isFinite(Number(snapshot.lcp)) ? rounded(snapshot.lcp) : null,
    dom_interactive_ms: rounded(navigation.domInteractive),
    dom_complete_ms: rounded(navigation.domComplete),
    full_load_ms: rounded(navigation.loadEventEnd),
    total_requests: resources.length,
    total_transfer_bytes: resourceBytes(() => true),
    js_transfer_bytes: resourceBytes((entry) => entry?.initiatorType === 'script'),
    css_transfer_bytes: resourceBytes((entry) => entry?.initiatorType === 'link'
      && String(entry?.name || '').split('?')[0].endsWith('.css')),
    long_task_count: longTasks.length,
    long_task_total_ms: rounded(longTasks.reduce((total, entry) => total + finiteNumber(entry?.duration), 0)),
    longest_task_ms: rounded(Math.max(0, ...longTasks.map((entry) => finiteNumber(entry?.duration)))),
  };
}
```

- [ ] **Step 4: Run the test and confirm GREEN**

Run:

```powershell
node --test tests/automation/frontend_performance_metrics.test.mjs
```

Expected: 2 tests pass, 0 fail.

- [ ] **Step 5: Commit the metric contract**

```powershell
git add scripts/lib/frontend_performance_metrics.mjs tests/automation/frontend_performance_metrics.test.mjs
git commit -m "[性能] 增加前端基准指标契约"
```

### Task 2: Capture the pre-change browser baseline

**Files:**
- Create: `scripts/measure_frontend_performance.mjs`
- Modify: `package.json`

- [ ] **Step 1: Add the measurement command**

Create `scripts/measure_frontend_performance.mjs`:

```js
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';
import { summarizeFrontendPerformance } from './lib/frontend_performance_metrics.mjs';

const options = Object.fromEntries(process.argv.slice(2).map((argument) => {
  const [key, ...rest] = argument.replace(/^--/, '').split('=');
  return [key, rest.join('=') || '1'];
}));
const baseURL = options.url || process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const label = String(options.label || 'frontend').replace(/[^a-zA-Z0-9._-]+/g, '-');
const authenticated = options.authenticated === '1';
const outputDir = path.resolve('output', 'performance');

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
await page.addInitScript(() => {
  window.__SUXI_PERFORMANCE = { lcp: null, longTasks: [] };
  try {
    new PerformanceObserver((list) => {
      const entries = list.getEntries();
      const latest = entries[entries.length - 1];
      if (latest) window.__SUXI_PERFORMANCE.lcp = latest.startTime;
    }).observe({ type: 'largest-contentful-paint', buffered: true });
  } catch (_error) {}
  try {
    new PerformanceObserver((list) => {
      window.__SUXI_PERFORMANCE.longTasks.push(...list.getEntries().map((entry) => ({
        startTime: entry.startTime,
        duration: entry.duration,
      })));
    }).observe({ type: 'longtask', buffered: true });
  } catch (_error) {}
});

const startedAt = new Date().toISOString();
await page.goto(baseURL, { waitUntil: 'domcontentloaded', timeout: 30_000 });
let authTransitionMs = null;
if (authenticated) {
  const username = page.locator('input[name="username"]').first();
  if (await username.count()) {
    await username.fill(process.env.E2E_USERNAME || 'admin');
    await page.locator('input[name="password"]').first().fill(process.env.E2E_PASSWORD || 'admin123');
    const authStarted = Date.now();
    await page.locator('button[type="submit"]').first().click();
    await page.locator('input[name="username"]').waitFor({ state: 'detached', timeout: 10_000 });
    authTransitionMs = Date.now() - authStarted;
  }
}
await page.waitForTimeout(2500);

const snapshot = await page.evaluate(() => ({
  navigation: performance.getEntriesByType('navigation')[0]?.toJSON() || {},
  paints: performance.getEntriesByType('paint').map((entry) => entry.toJSON()),
  resources: performance.getEntriesByType('resource').map((entry) => ({
    name: entry.name,
    initiatorType: entry.initiatorType,
    transferSize: entry.transferSize,
    duration: entry.duration,
  })),
  lcp: window.__SUXI_PERFORMANCE?.lcp ?? null,
  longTasks: window.__SUXI_PERFORMANCE?.longTasks || [],
}));
const result = {
  schema_version: 1,
  label,
  url: baseURL,
  authenticated,
  started_at: startedAt,
  auth_transition_ms: authTransitionMs,
  metrics: summarizeFrontendPerformance(snapshot),
  largest_resources: [...snapshot.resources]
    .sort((left, right) => Number(right.transferSize || 0) - Number(left.transferSize || 0))
    .slice(0, 15),
};

fs.mkdirSync(outputDir, { recursive: true });
const outputPath = path.join(outputDir, `${label}.json`);
fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify({ output: outputPath, ...result }, null, 2));
await browser.close();
```

Add these scripts to `package.json`:

```json
"measure:performance": "node scripts/measure_frontend_performance.mjs",
"test:performance-metrics": "node --test tests/automation/frontend_performance_metrics.test.mjs"
```

- [ ] **Step 2: Validate the script syntax and unit contract**

Run:

```powershell
node --check scripts/measure_frontend_performance.mjs
npm.cmd run test:performance-metrics
```

Expected: syntax exit 0 and 2 tests pass.

- [ ] **Step 3: Verify the local stack before measurement**

Run:

```powershell
$response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8080/api/health' -TimeoutSec 5
if ($response.StatusCode -ne 200) { throw "health failed: $($response.StatusCode)" }
```

Expected: status 200. Do not start or restart the stack if it is already healthy.

- [ ] **Step 4: Capture login and authenticated pre-change baselines**

Run:

```powershell
npm.cmd run measure:performance -- --label=before-entry-split-login
npm.cmd run measure:performance -- --label=before-entry-split-authenticated --authenticated=1
```

Expected: both commands exit 0 and create:

- `output/performance/before-entry-split-login.json`
- `output/performance/before-entry-split-authenticated.json`

If authenticated login is externally blocked, retain the login baseline and mark the authenticated metric `unverified`; do not fabricate it.

- [ ] **Step 5: Commit the measurement harness**

```powershell
git add package.json scripts/measure_frontend_performance.mjs
git commit -m "[性能] 增加浏览器性能基准采集"
```

Do not add generated `output/` evidence to Git.

### Task 3: Define the externalization contract and observe RED

**Files:**
- Create: `tests/automation/app_main_externalization.test.mjs`

- [ ] **Step 1: Write the failing externalization test**

Create `tests/automation/app_main_externalization.test.mjs`:

```js
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';

const indexPath = 'public/index.html';
const appMainPath = 'public/app-main.js';

test('main Vue bootstrap is external, deferred, ordered, and content-versioned', () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  assert.equal(fs.existsSync(appMainPath), true, 'public/app-main.js must exist');
  const appMain = fs.readFileSync(appMainPath, 'utf8');
  assert.doesNotMatch(html, /const\s+suxiApp\s*=\s*createApp\(/);
  assert.match(appMain, /const\s+suxiApp\s*=\s*createApp\(/);

  const deferredSources = [...html.matchAll(/<script\s+defer\s+src="([^"]+)"[^>]*><\/script>/g)]
    .map((match) => match[1]);
  assert.equal(deferredSources[0]?.split('?')[0], 'vue.global.prod.js');
  assert.equal(deferredSources.at(-1)?.split('?')[0], 'app-main.js');
  assert.ok(deferredSources.every((source) => source.endsWith('.js') || source.includes('.js?')));

  const appReference = deferredSources.at(-1);
  const versionHash = appReference.match(/-h([a-f0-9]{10})(?:$|&)/)?.[1];
  const contentHash = crypto.createHash('sha256').update(appMain).digest('hex').slice(0, 10);
  assert.equal(versionHash, contentHash);
});

test('asset failure renderer survives app-main load failure without changing success DOM', () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  assert.match(html, /window\.SUXI_RENDER_ASSET_LOAD_ERROR\s*=/);
  assert.match(html, /onerror="window\.SUXI_RENDER_ASSET_LOAD_ERROR\('app-main\.js'\)"/);
  assert.match(html, /<div id="app" v-cloak>/);
});
```

- [ ] **Step 2: Run the test and confirm RED**

Run:

```powershell
node --test tests/automation/app_main_externalization.test.mjs
```

Expected: FAIL because `public/app-main.js` does not exist.

- [ ] **Step 3: Commit the proven failing contract**

```powershell
git add tests/automation/app_main_externalization.test.mjs
git commit -m "[性能] 锁定入口脚本外置契约"
```

### Task 4: Mechanically externalize the unchanged Vue bootstrap

**Files:**
- Create: `public/app-main.js`
- Modify: `public/index.html`

- [ ] **Step 1: Mechanically extract the largest inline script and add ordered defer tags**

Run the following one-time Node transformation from the HOTEL root. It selects the largest inline script, writes its body without semantic edits, adds `defer` to existing local script tags, computes the app hash, and inserts `app-main.js` last in the deferred chain:

```powershell
@'
import crypto from 'node:crypto';
import fs from 'node:fs';

const indexPath = 'public/index.html';
const appPath = 'public/app-main.js';
const html = fs.readFileSync(indexPath, 'utf8');
const matches = [...html.matchAll(/<script(?<attrs>[^>]*)>(?<body>[\s\S]*?)<\/script>/gi)]
  .filter((match) => !/\bsrc\s*=/.test(match.groups.attrs));
const main = matches.sort((left, right) => right.groups.body.length - left.groups.body.length)[0];
if (!main || !main.groups.body.includes('const suxiApp = createApp(')) {
  throw new Error('Largest inline script is not the Vue app bootstrap.');
}
const appMain = main.groups.body.replace(/^\r?\n/, '').replace(/\r?\n\s*$/, '\n');
const hash = crypto.createHash('sha256').update(appMain).digest('hex').slice(0, 10);
let nextHtml = html.slice(0, main.index) + html.slice(main.index + main[0].length);
nextHtml = nextHtml.replace(
  /<script\s+src="([^"]+\.js(?:\?[^"]*)?)"><\/script>/g,
  '<script defer src="$1"></script>',
);
const appTag = `    <script defer src="app-main.js?v=20260713-entry-split-h${hash}" onerror="window.SUXI_RENDER_ASSET_LOAD_ERROR('app-main.js')"></script>\n`;
nextHtml = nextHtml.replace('</head>', `${appTag}</head>`);
fs.writeFileSync(appPath, appMain);
fs.writeFileSync(indexPath, nextHtml);
console.log(JSON.stringify({ hash, index_bytes: Buffer.byteLength(nextHtml), app_bytes: Buffer.byteLength(appMain) }));
'@ | node --input-type=module -
```

Expected: JSON reports `index_bytes` below 2,000,000 and `app_bytes` above 1,000,000.

- [ ] **Step 2: Add the independent asset-load error renderer**

Insert this short inline script before the first deferred script in `public/index.html`:

```html
    <script>
        (() => {
            const escapeAssetErrorText = (value) => String(value || '').replace(/[<>&"']/g, (char) => ({
                '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;',
            }[char]));
            window.SUXI_RENDER_ASSET_LOAD_ERROR = (assetName) => {
                const render = () => {
                    const appRoot = document.getElementById('app');
                    if (!appRoot || appRoot.dataset.assetErrorRendered === '1') return;
                    appRoot.dataset.assetErrorRendered = '1';
                    appRoot.removeAttribute('v-cloak');
                    const asset = escapeAssetErrorText(assetName || 'unknown asset');
                    appRoot.innerHTML = `<div class="min-h-screen bg-gray-100 flex items-center justify-center p-6"><div class="max-w-xl w-full bg-white border border-red-200 rounded-xl shadow-sm p-6"><div class="text-lg font-semibold text-red-700 mb-2">项目资源加载失败</div><div class="text-sm text-gray-600 mb-4">关键前端资源未能加载，请刷新页面；如果仍失败，请保留下面资源名称。</div><pre class="text-xs whitespace-pre-wrap break-words bg-red-50 text-red-700 border border-red-100 rounded p-3">${asset}</pre></div></div>`;
                };
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', render, { once: true });
                    return;
                }
                render();
            };
        })();
    </script>
```

- [ ] **Step 3: Verify the extracted script syntax and GREEN contract**

Run:

```powershell
node --check public/app-main.js
node --test tests/automation/app_main_externalization.test.mjs
```

Expected: syntax exit 0 and 2 tests pass.

- [ ] **Step 4: Prove the HTML structure did not move**

Run this structural comparison against the parent commit, ignoring the removed script body and added head tags:

```powershell
@'
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const stripScripts = (html) => html.replace(/<script[\s\S]*?<\/script>/gi, '');
const before = execFileSync('git', ['show', 'HEAD:public/index.html'], { encoding: 'utf8' });
const after = fs.readFileSync('public/index.html', 'utf8');
assert.equal(stripScripts(after), stripScripts(before));
console.log('HTML template structure unchanged.');
'@ | node --input-type=module -
```

Expected: `HTML template structure unchanged.`

Do not continue if this assertion fails; restore the extraction and locate the unintended template change.

### Task 5: Extend the existing public-entry guard for split sources

**Files:**
- Modify: `scripts/verify_public_entry_guard.mjs`

- [ ] **Step 1: Run the existing guard and observe the compatibility failure**

Run:

```powershell
npm.cmd run verify:public-entry
```

Expected: FAIL because behavior markers such as `createApp` moved out of `public/index.html`. This is the RED evidence for the guard adaptation.

- [ ] **Step 2: Read HTML and app behavior separately, then retain combined marker coverage**

At the path declarations, add:

```js
const appMainPath = path.join(repoRoot, 'public/app-main.js');
```

Replace the initial content load inside the existing `indexPath` branch with:

```js
  const stat = fs.statSync(indexPath);
  const htmlContent = fs.readFileSync(indexPath, 'utf8');
  const appMainContent = fs.existsSync(appMainPath) ? fs.readFileSync(appMainPath, 'utf8') : '';
  let content = `${htmlContent}\n${appMainContent}`;
```

Add these failures immediately after the size check:

```js
  if (!appMainContent) {
    failures.push('public/app-main.js is missing or empty.');
  }
  if (/const\s+suxiApp\s*=\s*createApp\(/.test(htmlContent)) {
    failures.push('public/index.html must not inline the main Vue bootstrap after entry externalization.');
  }
  if (!/const\s+suxiApp\s*=\s*createApp\(/.test(appMainContent)) {
    failures.push('public/app-main.js must contain the main Vue bootstrap.');
  }
  const appMainReference = htmlContent.match(/<script\s+defer\s+src="app-main\.js\?v=[^"]*-h([a-f0-9]{10})"[^>]*><\/script>/);
  const appMainHash = appMainContent
    ? crypto.createHash('sha256').update(appMainContent).digest('hex').slice(0, 10)
    : '';
  if (!appMainReference || appMainReference[1] !== appMainHash) {
    failures.push('public/index.html must use the current public/app-main.js content hash in its immutable cache version.');
  }
  const deferredScripts = [...htmlContent.matchAll(/<script\s+defer\s+src="([^"]+)"[^>]*><\/script>/g)]
    .map((match) => match[1].split('?')[0]);
  if (deferredScripts[0] !== 'vue.global.prod.js' || deferredScripts.at(-1) !== 'app-main.js') {
    failures.push('public/index.html must keep Vue first and app-main.js last in the ordered deferred startup chain.');
  }
```

For HTML tag-stack checks, pass `htmlContent` rather than combined `content` into `openTagStackBefore`. Keep `content` for existing source marker and helper-contract checks.

- [ ] **Step 3: Run the guard and focused tests to confirm GREEN**

Run:

```powershell
npm.cmd run verify:public-entry
node --test tests/automation/app_main_externalization.test.mjs tests/automation/public_entry_precommit_guard.test.mjs
```

Expected: all commands exit 0; public entry guard passes; 3 tests pass.

- [ ] **Step 4: Commit the externalized entry and guard together**

```powershell
git add public/index.html public/app-main.js scripts/verify_public_entry_guard.mjs tests/automation/app_main_externalization.test.mjs
git commit -m "[性能] 外置并缓存前端主启动脚本"
```

### Task 6: Add a deterministic frontend performance budget

**Files:**
- Create: `scripts/lib/frontend_performance_budget.mjs`
- Create: `tests/automation/frontend_performance_budget.test.mjs`
- Create: `scripts/verify_frontend_performance_budget.mjs`
- Modify: `package.json`

- [ ] **Step 1: Write the failing budget test**

Create `tests/automation/frontend_performance_budget.test.mjs`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';
import { evaluateFrontendBudget } from '../../scripts/lib/frontend_performance_budget.mjs';

test('evaluateFrontendBudget reports each exceeded entry limit', () => {
  const failures = evaluateFrontendBudget({
    index_bytes: 2_100_000,
    startup_gzip_bytes: 1_700_000,
    inline_script_bytes: 25_000,
    blocking_script_count: 1,
  }, {
    max_index_bytes: 2_000_000,
    max_startup_gzip_bytes: 1_600_000,
    max_inline_script_bytes: 20_000,
    max_blocking_script_count: 0,
  });
  assert.deepEqual(failures.map((item) => item.metric), [
    'index_bytes',
    'startup_gzip_bytes',
    'inline_script_bytes',
    'blocking_script_count',
  ]);
});

test('evaluateFrontendBudget passes metrics within every limit', () => {
  assert.deepEqual(evaluateFrontendBudget({
    index_bytes: 1_900_000,
    startup_gzip_bytes: 1_500_000,
    inline_script_bytes: 10_000,
    blocking_script_count: 0,
  }), []);
});
```

- [ ] **Step 2: Run the budget test and confirm RED**

Run:

```powershell
node --test tests/automation/frontend_performance_budget.test.mjs
```

Expected: FAIL with `ERR_MODULE_NOT_FOUND`.

- [ ] **Step 3: Implement budget collection and evaluation**

Create `scripts/lib/frontend_performance_budget.mjs`:

```js
import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';

export const DEFAULT_FRONTEND_BUDGET = Object.freeze({
  max_index_bytes: 2_000_000,
  max_startup_gzip_bytes: 1_600_000,
  max_inline_script_bytes: 20_000,
  max_blocking_script_count: 0,
});

const LIMITS = Object.freeze({
  index_bytes: 'max_index_bytes',
  startup_gzip_bytes: 'max_startup_gzip_bytes',
  inline_script_bytes: 'max_inline_script_bytes',
  blocking_script_count: 'max_blocking_script_count',
});

export function evaluateFrontendBudget(metrics, budget = DEFAULT_FRONTEND_BUDGET) {
  return Object.entries(LIMITS).flatMap(([metric, limitKey]) => {
    const actual = Number(metrics?.[metric] || 0);
    const limit = Number(budget?.[limitKey]);
    return actual > limit ? [{ metric, actual, limit }] : [];
  });
}

export function collectFrontendEntryMetrics(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const indexPath = path.join(publicRoot, 'index.html');
  const index = fs.readFileSync(indexPath);
  const html = index.toString('utf8');
  const head = html.slice(0, html.indexOf('</head>'));
  const localReferences = [...head.matchAll(/<(?:script|link)\b[^>]*(?:src|href)="([^"?]+)(?:\?[^"]*)?"[^>]*>/g)]
    .map((match) => match[1])
    .filter((reference) => /\.(?:js|css)$/.test(reference));
  const uniqueReferences = [...new Set(localReferences)];
  const startupFiles = [indexPath, ...uniqueReferences.map((reference) => path.join(publicRoot, reference))];
  const missing = startupFiles.filter((file) => !fs.existsSync(file));
  if (missing.length) throw new Error(`Missing startup assets: ${missing.join(', ')}`);
  const inlineScriptBytes = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)]
    .reduce((total, match) => total + Buffer.byteLength(match[1]), 0);
  const blockingScriptCount = [...head.matchAll(/<script\b([^>]*)\bsrc="[^"]+"[^>]*><\/script>/g)]
    .filter((match) => !/\bdefer\b/.test(match[1])).length;

  return {
    index_bytes: index.length,
    startup_gzip_bytes: startupFiles.reduce((total, file) => total + gzipSync(fs.readFileSync(file), { level: 1 }).length, 0),
    inline_script_bytes: inlineScriptBytes,
    blocking_script_count: blockingScriptCount,
    startup_asset_count: uniqueReferences.length,
    startup_assets: uniqueReferences,
  };
}
```

Create `scripts/verify_frontend_performance_budget.mjs`:

```js
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  collectFrontendEntryMetrics,
  DEFAULT_FRONTEND_BUDGET,
  evaluateFrontendBudget,
} from './lib/frontend_performance_budget.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const metrics = collectFrontendEntryMetrics(repoRoot);
const failures = evaluateFrontendBudget(metrics);
console.log(JSON.stringify({ metrics, budget: DEFAULT_FRONTEND_BUDGET, failures }, null, 2));
if (failures.length) process.exit(1);
```

Add to `package.json`:

```json
"verify:performance-budget": "node scripts/verify_frontend_performance_budget.mjs",
"test:performance-budget": "node --test tests/automation/frontend_performance_budget.test.mjs"
```

- [ ] **Step 4: Run tests and the live budget gate**

Run:

```powershell
npm.cmd run test:performance-budget
npm.cmd run verify:performance-budget
```

Expected: 2 tests pass and the budget command exits 0. If measured startup gzip is above 1,600,000 bytes without a regression, set `max_startup_gzip_bytes` to the measured post-split value rounded up to the next 50,000 bytes and record that exact value in this plan; do not weaken the other limits.

- [ ] **Step 5: Wire the budget into the focused entry gate**

Change `package.json` so `verify:public-entry` becomes:

```json
"verify:public-entry": "node scripts/verify_public_entry_guard.mjs && node scripts/verify_frontend_performance_budget.mjs"
```

Run:

```powershell
npm.cmd run verify:public-entry
```

Expected: both public entry guard and performance budget pass.

- [ ] **Step 6: Commit the performance budget**

```powershell
git add package.json scripts/lib/frontend_performance_budget.mjs scripts/verify_frontend_performance_budget.mjs tests/automation/frontend_performance_budget.test.mjs
git commit -m "[性能] 增加前端入口预算门禁"
```

### Task 7: Capture post-change evidence and run the regression matrix

**Files:**
- Modify: `docs/superpowers/plans/2026-07-13-progressive-entry-performance.md`
- Generated, not committed: `output/performance/after-entry-split-login.json`
- Generated, not committed: `output/performance/after-entry-split-authenticated.json`

- [ ] **Step 1: Capture post-change browser measurements**

Run:

```powershell
npm.cmd run measure:performance -- --label=after-entry-split-login
npm.cmd run measure:performance -- --label=after-entry-split-authenticated --authenticated=1
```

Expected: both commands exit 0 or authenticated remains explicitly `unverified` for the same external blocker recorded before the change.

- [ ] **Step 2: Compare matching before/after environments**

Run:

```powershell
$pairs = @(
  @('before-entry-split-login','after-entry-split-login'),
  @('before-entry-split-authenticated','after-entry-split-authenticated')
)
foreach ($pair in $pairs) {
  $beforePath = "output/performance/$($pair[0]).json"
  $afterPath = "output/performance/$($pair[1]).json"
  if (!(Test-Path $beforePath) -or !(Test-Path $afterPath)) { continue }
  $before = Get-Content $beforePath -Raw -Encoding UTF8 | ConvertFrom-Json
  $after = Get-Content $afterPath -Raw -Encoding UTF8 | ConvertFrom-Json
  [pscustomobject]@{
    pair = "$($pair[0]) -> $($pair[1])"
    fcp_before = $before.metrics.fcp_ms
    fcp_after = $after.metrics.fcp_ms
    lcp_before = $before.metrics.lcp_ms
    lcp_after = $after.metrics.lcp_ms
    transfer_before = $before.metrics.total_transfer_bytes
    transfer_after = $after.metrics.total_transfer_bytes
    long_tasks_before = $before.metrics.long_task_total_ms
    long_tasks_after = $after.metrics.long_task_total_ms
  }
}
```

Record the printed values under a new `## Execution Evidence` section in this plan. Do not claim improvement where a metric is null, environment differs, or variance contradicts the claim.

- [ ] **Step 3: Run focused syntax and contract verification**

Run:

```powershell
node --check public/app-main.js
node --test tests/automation/frontend_performance_metrics.test.mjs tests/automation/frontend_performance_budget.test.mjs tests/automation/app_main_externalization.test.mjs tests/automation/public_entry_precommit_guard.test.mjs
npm.cmd run verify:public-entry
npm.cmd run verify:e2e-contracts
```

Expected: every command exits 0.

- [ ] **Step 4: Run page structure and functional regression**

Run:

```powershell
npm.cmd run test:e2e:module
npm.cmd run test:e2e:quick
```

Expected: all selected pages render, API diagnostics contain no hard failures, and Playwright exits 0. Inspect generated screenshots/HTML only when a test fails.

- [ ] **Step 5: Run the full project gates proportional to the entry change**

Run:

```powershell
npm.cmd run verify:p0-guards
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never
git diff --check
git status --short
```

Expected: P0 guards pass, PHPUnit reports 0 failures, diff check is clean, and status contains only the plan evidence update if it has not yet been committed.

- [ ] **Step 6: Verify HTTP cache behavior**

Extract the current `app-main.js` URL from `public/index.html`, then run:

```powershell
$html = Get-Content public/index.html -Raw -Encoding UTF8
$asset = [regex]::Match($html, 'app-main\.js\?v=[^"'']+').Value
$first = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:8080/$asset"
$second = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:8080/$asset" -Headers @{ 'If-None-Match' = $first.Headers.ETag }
if ($first.StatusCode -ne 200) { throw 'first app-main request did not return 200' }
if ($second.StatusCode -ne 304) { throw 'conditional app-main request did not return 304' }
$first.Headers.'Cache-Control'
```

Expected: first request 200, conditional request 304, and Cache-Control contains `public`, `max-age=2592000`, and `immutable`.

- [ ] **Step 7: Commit verified execution evidence**

Append exact before/after values, verification commands, pass counts, and any `unverified` item under `## Execution Evidence`, then run:

```powershell
git add docs/superpowers/plans/2026-07-13-progressive-entry-performance.md
git commit -m "[性能] 记录入口优化验证证据"
```

### Task 8: Decide the next evidence-based optimization slice

**Files:**
- None. This is a read-only decision gate based on the completed measurement evidence.

- [ ] **Step 1: Rank the remaining bottlenecks from measured evidence**

Use this order:

1. Largest blocking or eagerly loaded first-party helper.
2. Longest main-thread task during app bootstrap or page switch.
3. Duplicate read-only request with identical URL and parameters.
4. Largest safe CSS reduction with complete dynamic-class coverage.
5. Slowest backend endpoint with query count and `EXPLAIN` evidence.

- [ ] **Step 2: Select exactly one next slice**

Create a separate TDD plan for one of:

- page-specific helper lazy loading;
- page request deduplication/cancellation;
- Tailwind production CSS generation;
- backend hot endpoint/query optimization.

Do not mix all four into one implementation batch. The active overall goal remains open until every measured material bottleneck is resolved and the completion audit in the design spec passes.

## Plan self-review record

- Spec coverage: Tasks 1-7 cover first-batch measurement, externalization, defer ordering, cache versioning, explicit error handling, performance budgets, browser comparison, structure checks and regression gates.
- Deliberate decomposition: helper migration, request scheduling, CSS pruning and backend SQL work remain separate plans because their implementation depends on first-batch measurements.
- Type consistency: browser snapshot keys match `summarizeFrontendPerformance`; budget metric keys match `evaluateFrontendBudget`; package script names are used consistently.
- Placeholder scan: no unresolved implementation markers remain.
