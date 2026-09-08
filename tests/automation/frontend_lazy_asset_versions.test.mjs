import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { syncOperationStaticVersion, syncRevenueStaticVersions, syncOperatingIntelligenceVersion } from '../../scripts/lib/frontend_lazy_asset_versions.mjs';

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
