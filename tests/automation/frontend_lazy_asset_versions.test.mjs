import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { syncOperationStaticVersion } from '../../scripts/lib/frontend_lazy_asset_versions.mjs';

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
