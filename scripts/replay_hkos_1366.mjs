// Replay reviewed source fragments with in-memory fixtures; no plugin bootstrap.
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const base = new URL('../docs/knowledge/hkos-workbench-v1.3.66/', import.meta.url);
const manifest = JSON.parse(readFileSync(new URL('source-manifest.json', base), 'utf8'));
const bootstrap = `function __commonJS(defs) { return function () {
  const module = { exports: {} }; Object.values(defs)[0](module.exports, module); return module.exports;
}; }`;
const ctx = vm.createContext(Object.create(null), { codeGeneration: { strings: false, wasm: false } });
vm.runInContext(bootstrap, ctx);
for (const [file, loader, name] of [
  ['controlled-source-policy', 'require_controlled_source_policy', 'policy'],
  ['usage-dashboard-telemetry', 'require_usage_dashboard_telemetry', 'telemetry'],
]) {
  const record = manifest.retained_sources.find(s => s.file === `sources/${file}.cjs.txt`);
  const fragment = readFileSync(new URL(record.file, base), 'utf8');
  assert.equal(createHash('sha256').update(fragment).digest('hex').toUpperCase(), record.sha256);
  assert(!/\brequire\(|\bprocess\b|\bfetch\b|\bimport\b/.test(fragment));
  vm.runInContext(fragment + `\nvar ${name} = ${loader}();`, ctx, { timeout: 1000 });
}
const result = vm.runInContext(`(() => {
  const good = { approved: true, allowLocalContentStudio: true, distributionRight: 'CUSTOMER_PRIVATE',
    rightsEvidenceId: 'synthetic-fixture', version: '1', approvedBy: 'fixture reviewer',
    approvedAt: '2026-09-01', reviewDueAt: '2026-10-01', publish_scope: 'customer_local' };
  const rules = [
    ['valid_local_reference', {}, true], ['unapproved', { approved: false }, false],
    ['expired', { reviewDueAt: '2026-09-26' }, false],
    ['explicit_denial', { external_use: false }, false],
    ['internal_only', { publish_scope: 'internal_only' }, false],
    ['missing_rights', { rightsEvidenceId: '' }, false],
  ].map(([name, change, expected]) => ({ name, expected,
    actual: policy.mayUseLocalContentStudioSource({ ...good, ...change }, '2026-09-27') }));
  let saved = null;
  const store = telemetry.createUsageTelemetryStore({ read: () => null, write: v => { saved = v; },
    today: () => '2026-09-27', schedule: () => 0 });
  const empty = store.snapshot();
  store.recordFeatureOpen('knowledge'); store.recordSearch('SOP');
  const flush = store.flush();
  const restored = telemetry.createUsageTelemetryStore({ read: () => saved }).snapshot();
  let day = '2026-09-24';
  const skipped = telemetry.createUsageTelemetryStore({ today: () => day, schedule: () => 0 });
  skipped.markInboxDay(0); day = '2026-09-27'; skipped.markInboxDay(0);
  let attempts = 0;
  const failed = telemetry.createUsageTelemetryStore({ today: () => day, schedule: () => 0,
    write: () => { attempts++; throw new Error('synthetic write failure'); } });
  failed.recordFeatureOpen('knowledge');
  const firstFlush = failed.flush(); const secondFlush = failed.flush();
  return { rules, empty, flush, restored, skippedDays: skipped.snapshot().inboxFreeStreak,
    failedSave: { attempts, firstFlush, secondFlush } };
})()`, ctx, { timeout: 1000 });
for (const rule of result.rules) assert.equal(rule.actual, rule.expected, rule.name);
assert.equal(result.empty.sinceDay, '');
assert.equal(Object.keys(result.empty.featureOpens).length, 0);
assert.equal(result.flush, true);
assert.equal(result.restored.featureOpens.knowledge, 1);
assert.equal(result.restored.searches.SOP, 1);
assert.equal(result.restored.sinceDay, '2026-09-27');
// Assert the observed defects, not a desired corrected behavior.
assert.equal(result.skippedDays, 2);
assert.equal(result.failedSave.attempts, 1);
assert.equal(result.failedSave.firstFlush, false);
assert.equal(result.failedSave.secondFlush, false);
console.log(JSON.stringify({ status: 'passed', source_version: manifest.package_version,
  scope: 'reviewed_pure_fragments_with_synthetic_memory_ports',
  policy_cases: result.rules, normal_telemetry_readback: 'exact', empty_state: 'not_started',
  reproduced_source_defects: [
    { code: 'nonconsecutive_days_counted_as_streak', dates: ['2026-09-24', '2026-09-27'], observed: result.skippedDays, required_for_continuous_days: 1 },
    { code: 'failed_write_clears_dirty_and_prevents_retry', ...result.failedSave },
  ], plugin_installed: false, whole_plugin_verified: false }));
