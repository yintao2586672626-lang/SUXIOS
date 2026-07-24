import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
  assessStartupGzipBudget,
  collectFrontendEntryMetrics,
  DEFAULT_FRONTEND_BUDGET,
  evaluateFrontendBudget,
} from '../../scripts/lib/frontend_performance_budget.mjs';

test('default startup budget uses the fast-iteration target, warning, and hard limit', () => {
  assert.deepEqual({
    target: DEFAULT_FRONTEND_BUDGET.target_startup_gzip_bytes,
    warning: DEFAULT_FRONTEND_BUDGET.warning_startup_gzip_bytes,
    hard_limit: DEFAULT_FRONTEND_BUDGET.max_startup_gzip_bytes,
  }, {
    target: 650_000,
    warning: 800_000,
    hard_limit: 850_000,
  });
});

test('assessStartupGzipBudget classifies every startup budget zone', () => {
  const cases = [
    [650_000, 'within_target'],
    [650_001, 'above_target'],
    [795_914, 'above_target'],
    [800_000, 'warning'],
    [850_000, 'warning'],
    [850_001, 'failed'],
  ];

  for (const [startupGzipBytes, expectedStatus] of cases) {
    assert.equal(
      assessStartupGzipBudget({ startup_gzip_bytes: startupGzipBytes }).status,
      expectedStatus,
    );
  }
});

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
    startup_gzip_bytes: 800_000,
    inline_script_bytes: 10_000,
    blocking_script_count: 0,
  }), []);
});

test('entry metrics separate public shell, home startup, and after-paint authenticated assets', () => {
  const repoRoot = mkdtempSync(path.join(tmpdir(), 'suxi-frontend-budget-'));
  const publicRoot = path.join(repoRoot, 'public');
  mkdirSync(publicRoot, { recursive: true });
  try {
    writeFileSync(path.join(publicRoot, 'index.html'), `<!doctype html>
      <html><head><link rel="stylesheet" href="shell.css?v=1"></head><body>
      <div id="app"></div>
      <script type="application/json" id="suxi-authenticated-assets">[
        "vue.runtime.global.prod.js?v=1",
        "app-startup-render.min.js?v=1",
        {"src":"app-render.min.js?v=1","phase":"after-first-paint"},
        "app-main.min.js?v=1"
      ]</script>
      <script defer src="app-bootstrap.js?v=1"></script>
      </body></html>`);
    for (const asset of ['shell.css', 'app-bootstrap.js', 'vue.runtime.global.prod.js', 'app-startup-render.min.js', 'app-render.min.js', 'app-main.min.js']) {
      writeFileSync(path.join(publicRoot, asset), `${asset}\n`.repeat(10));
    }

    const metrics = collectFrontendEntryMetrics(repoRoot);
    assert.deepEqual(metrics.public_shell_assets, ['shell.css', 'app-bootstrap.js']);
    assert.deepEqual(metrics.authenticated_assets, [
      'vue.runtime.global.prod.js',
      'app-startup-render.min.js',
      'app-render.min.js',
      'app-main.min.js',
    ]);
    assert.deepEqual(metrics.startup_assets, [
      'shell.css',
      'app-bootstrap.js',
      'vue.runtime.global.prod.js',
      'app-startup-render.min.js',
      'app-main.min.js',
    ]);
    assert.deepEqual(metrics.deferred_authenticated_assets, ['app-render.min.js']);
    assert(metrics.deferred_authenticated_gzip_bytes > 0);
    assert(metrics.full_authenticated_gzip_bytes > metrics.startup_gzip_bytes);
    assert(metrics.startup_gzip_bytes > metrics.public_shell_gzip_bytes);
  } finally {
    rmSync(repoRoot, { recursive: true, force: true });
  }
});
