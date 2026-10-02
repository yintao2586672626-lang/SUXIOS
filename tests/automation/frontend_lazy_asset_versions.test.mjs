import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { syncOperationStaticVersion, syncRevenueStaticVersions, syncOperatingIntelligenceVersion, syncStartupLazyComponentVersions, syncStartupLazyHtmlVersions, STARTUP_LAZY_COMPONENTS, ACTION_LAZY_HELPERS, syncActionLazyHelperVersions } from '../../scripts/lib/frontend_lazy_asset_versions.mjs';

const source = "const unrelated = 'keep';\nconst operationStaticScriptVersion = 'release-v1-h0123456789';\n";
test('changing lazy helper bytes updates its content hash without changing unrelated entry code', () => {
  const helper = Buffer.from('window.SUXI_OPERATION_STATIC = { label: "复盘" };');
  const result = syncOperationStaticVersion(source, helper);
  const expected = createHash('sha256').update(helper).digest('hex').slice(0, 10);
  assert.equal(result.hash, expected);
  assert.equal(result.source, source.replace('0123456789', expected));
  assert.equal(syncOperationStaticVersion(result.source, helper).source, result.source);
  assert.notEqual(syncOperationStaticVersion(result.source, Buffer.concat([helper, Buffer.from('\n')])).hash, expected);
});

test('missing or ambiguous lazy loader declarations fail instead of silently leaving a stale cache URL', () => {
  for (const value of ['', source + source, source.replace('-h0123456789', '')]) {
    assert.throws(() => syncOperationStaticVersion(value, 'helper'), /exactly one/);
  }
});

test('revenue child changes invalidate both lazy loader levels and stay reproducible', () => {
  const parent = "const revenueAiStaticVersion = 'test-h0123456789';";
  const child = "const revenueCockpitStaticVersion = 'test-h0123456789';";
  const first = syncRevenueStaticVersions(parent, child, 'ledger-v1');
  const second = syncRevenueStaticVersions(first.appMain, first.revenueAi, 'ledger-v2');
  assert.notEqual(first.appMain, second.appMain);
  assert.notEqual(first.revenueAi, second.revenueAi);
  assert.deepEqual(syncRevenueStaticVersions(second.appMain, second.revenueAi, 'ledger-v2'), second);
  assert.throws(() => syncRevenueStaticVersions(parent, '', 'v1'), /exactly one/);
});

test('assistant loader receives the current component content hash and changes no other code', () => {
  const loader = "const fullScript = 'components/system/operating-intelligence-components.js?v=release-h0123456789';\nconst keep = 1;";
  const component = Buffer.from('synthetic assistant component');
  const updated = syncOperatingIntelligenceVersion(loader, component);
  const hash = createHash('sha256').update(component).digest('hex').slice(0, 10);
  assert.equal(updated.source, loader.replace('0123456789', hash));
  assert.equal(syncOperatingIntelligenceVersion(updated.source, component).source, updated.source);
  assert.throws(() => syncOperatingIntelligenceVersion(loader + loader, component), /exactly one/);
});

test('nested component changes invalidate both the parent loader and startup loader, then stabilize', () => {
  const assets = new Map();
  const entries = Object.entries(STARTUP_LAZY_COMPONENTS).map(([name, children]) => ({
    name,
    source: children.map(child => {
      assets.set(child, Buffer.from('window.fixture = true;'));
      return `const asset${assets.size} = '${child}?v=fixture-h0123456789';`;
    }).join('\n'),
  }));
  const parent = 'components/system/app-main-components.js';
  const child = 'components/system/manager-coaching-panel.js';
  assets.set(parent, Buffer.from(`const child = '${child}?v=20260926-v1';`));
  assets.set(child, Buffer.from('window.coaching = 1;'));
  const initial = syncStartupLazyComponentVersions(entries, name => assets.get(name));
  assert.equal(initial.dependencySources.length, 1);
  assert.match(initial.dependencySources[0].source, /20260926-v1-h[a-f0-9]{10}/);
  const html = `"src":"${parent}?v=fixture-h0123456789"`;
  const expectedParentHash = createHash('sha256').update(initial.dependencySources[0].source).digest('hex').slice(0,10);
  assert.equal(syncStartupLazyHtmlVersions(html, initial), html.replace('0123456789',expectedParentHash));
  for (const dependency of initial.dependencySources) assets.set(dependency.name, Buffer.from(dependency.source));
  const stable = syncStartupLazyComponentVersions(initial.sources, name => assets.get(name));
  assert.equal(stable.dependencySources.length, 0);
  assert.deepEqual(stable.sources.map(entry => entry.source), initial.sources.map(entry => entry.source));
  assets.set(child, Buffer.from('window.coaching = 2;'));
  const changed = syncStartupLazyComponentVersions(stable.sources, name => assets.get(name));
  assert.equal(changed.dependencySources.length, 1);
  assert.notEqual(changed.sources[0].source, stable.sources[0].source);
  assert.equal(changed.sources[1].source, stable.sources[1].source);
});

test('action helpers retain release prefixes while their changed bytes invalidate immutable cache URLs', () => {
  const source = Object.keys(ACTION_LAZY_HELPERS).map(name => `const ${name} = 'release-h80-v3';`).join('\n');
  const bytes = name => Buffer.from(`window.fixture = '${name}';`);
  const first = syncActionLazyHelperVersions(source, bytes);
  assert.equal(first.dependencies.size, Object.keys(ACTION_LAZY_HELPERS).length);
  assert.equal((first.source.match(/release-h80-v3-h[a-f0-9]{10}/g) || []).length, first.dependencies.size);
  assert.equal(syncActionLazyHelperVersions(first.source, bytes).source, first.source);
  assert.notEqual(syncActionLazyHelperVersions(first.source, name => Buffer.concat([bytes(name),Buffer.from('changed')])).source, first.source);
  assert.throws(() => syncActionLazyHelperVersions('', bytes), /exactly one/);
});
