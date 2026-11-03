import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { parse } from 'acorn';

const repo = fileURLToPath(new URL('../../', import.meta.url));
const source = fs.readFileSync(process.env.SUXIOS_SELF_AUDIT_SOURCE || path.join(repo, 'scripts/project_self_audit.mjs'), 'utf8');
const names = new Set(['safeReadText', 'safeStat', 'safeLstat', 'isUnlinkedAuditPath', 'isProtectedAuditPath', 'isInsideRepo', 'normalizePath', 'loadSplitDispositions']);
const functions = parse(source, { ecmaVersion: 'latest', sourceType: 'module' }).body
  .filter(node => node.type === 'FunctionDeclaration' && names.has(node.id.name))
  .map(node => source.slice(node.start, node.end)).join('\n');

function virtualBoundary({ leafLink = false, parentLink = false, mappedProtected = false, configLink = false } = {}) {
  const repoRoot = path.resolve('synthetic-audit-workspace');
  const visible = path.join(repoRoot, 'docs/reference.md');
  const config = path.join(repoRoot, 'docs/self_cleaning_split_dispositions.json');
  let reads = 0;
  const regular = { isSymbolicLink: () => false, isFile: () => true, size: 25 };
  const fakeFs = {
    existsSync: () => true,
    statSync: () => regular,
    lstatSync: file => ({ ...regular, isSymbolicLink: () =>
      leafLink && file === visible || parentLink && file === path.dirname(visible) || configLink && file === config }),
    realpathSync: file => file === visible && (leafLink || parentLink || mappedProtected)
      ? path.join(repoRoot, 'storage/protected-fixture/canary.md') : file,
    readFileSync: file => { reads++; return file === config ? '{"accepted":[]}' : Buffer.from('synthetic protected sentinel'); },
  };
  const context = vm.createContext({ fs: fakeFs, path, repoRoot });
  vm.runInContext(functions, context);
  return { context, visible, config, repoRoot, readCount: () => reads };
}

test('ordinary file keeps text and metadata behavior', () => {
  const f = virtualBoundary();
  assert.equal(f.context.safeReadText(f.visible), 'synthetic protected sentinel');
  assert.equal(f.context.safeStat(f.visible).isFile(), true);
  assert.equal(f.readCount(), 1);
});
test('ordinary alias to protected file cannot read its target', () => {
  const f = virtualBoundary({ leafLink: true });
  assert.equal(f.context.safeReadText(f.visible), null);
  assert.equal(f.context.safeStat(f.visible), null);
  assert.equal(f.readCount(), 0);
});
test('parent junction cannot expose a protected descendant', () => {
  const f = virtualBoundary({ parentLink: true });
  assert.equal(f.context.safeReadText(f.visible), null);
  assert.equal(f.context.safeStat(f.visible), null);
  assert.equal(f.context.safeLstat(f.visible), null);
  assert.equal(f.readCount(), 0);
});
test('canonical target is checked even if leaf metadata appears regular', () => {
  const f = virtualBoundary({ mappedProtected: true });
  assert.equal(f.context.safeReadText(f.visible), null);
  assert.equal(f.readCount(), 0);
});
test('path outside repository is rejected before reading', () => {
  const f = virtualBoundary();
  assert.equal(f.context.safeReadText(path.join(path.dirname(f.repoRoot), 'outside/canary.md')), null);
  assert.equal(f.readCount(), 0);
});
test('split-disposition config uses the same guarded read boundary', () => {
  const f = virtualBoundary({ configLink: true });
  const result = f.context.loadSplitDispositions();
  assert.equal(result.loaded, false);
  assert.ok(result.error.length > 0);
  assert.equal(f.readCount(), 0);
});
