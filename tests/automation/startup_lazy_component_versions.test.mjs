import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { STARTUP_LAZY_COMPONENTS, NESTED_LAZY_COMPONENTS, syncStartupLazyComponentVersions, syncRevenueAiStaticVersion } from '../../scripts/lib/frontend_lazy_asset_versions.mjs';

function fixture(quote = "'", hash = '0123456789') {
  const assets = new Map();
  const referenceSource = references => references.map((reference, index) => {
    assets.set(reference, Buffer.from(`// synthetic 中文 ${reference}\n`));
    return `const asset${index} = ${quote}${reference}?v=fixture-h${hash}${quote};`;
  }).join('\n');
  const entries = Object.entries(STARTUP_LAZY_COMPONENTS).map(([name, references]) => ({ name,
    source: referenceSource(references),
  }));
  for (const [parent, references] of Object.entries(NESTED_LAZY_COMPONENTS)) {
    assets.set(parent, Buffer.from(referenceSource(references)));
  }
  return { entries, assets, read: name => {
    if (!assets.has(name)) throw new Error(`Missing fixture asset: ${name}`);
    return assets.get(name);
  } };
}

test('revenue AI helper version follows bytes and rejects missing or ambiguous loader declarations', () => {
  const source = "const revenueAiStaticVersion = 'release-h0123456789';";
  const bytes = Buffer.from('// synthetic revenue helper 中文');
  const result = syncRevenueAiStaticVersion(source, bytes);
  assert.equal(result.hash, createHash('sha256').update(bytes).digest('hex').slice(0, 10));
  assert.equal(result.source, `const revenueAiStaticVersion = 'release-h${result.hash}';`);
  assert.equal(syncRevenueAiStaticVersion(result.source, bytes).source, result.source);
  assert.throws(() => syncRevenueAiStaticVersion('', bytes), /exactly one/);
  assert.throws(() => syncRevenueAiStaticVersion(source + source, bytes), /exactly one/);
});

test('all startup lazy dependencies track exact bytes while preserving release prefixes', () => {
  const f = fixture();
  const plan = syncStartupLazyComponentVersions(f.entries, f.read);
  assert.equal(plan.dependencies.size, 6);
  assert.deepEqual([...plan.dependencies.keys()].sort(), [...f.assets.keys()].sort());
  const rewritten = new Map(plan.dependencySources.map(entry => [entry.name, entry.source]));
  for (const entry of plan.sources) {
    assert.equal(entry.originalSource, f.entries.find(item => item.name === entry.name).source);
    for (const ref of STARTUP_LAZY_COMPONENTS[entry.name]) {
      const expected = createHash('sha256').update(rewritten.get(ref) ?? f.assets.get(ref)).digest('hex').slice(0, 10);
      assert.ok(entry.source.includes(`${ref}?v=fixture-h${expected}`));
    }
  }
  assert.deepEqual(syncStartupLazyComponentVersions(plan.sources, f.read).sources.map(x => x.source), plan.sources.map(x => x.source));
  const target = 'components/system/operating-intelligence-components.js';
  f.assets.set(target, Buffer.from('// synthetic changed business component'));
  const changed = syncStartupLazyComponentVersions(plan.sources, f.read);
  assert.notEqual(changed.sources[2].source, plan.sources[2].source);
  assert.equal(changed.sources[0].source, plan.sources[0].source);
});

test('missing loader, ambiguous references, or absent version cannot publish an apparently current graph', () => {
  const f = fixture();
  assert.throws(() => syncStartupLazyComponentVersions(f.entries.slice(1), f.read), /exactly once/);
  for (const source of ['', f.entries[2].source + '\n' + f.entries[2].source, f.entries[2].source.replaceAll('-h0123456789', '')]) {
    assert.throws(() => syncStartupLazyComponentVersions(f.entries.map((entry, i) => i === 2 ? { ...entry, source } : entry), f.read), /exactly once|stable prefix/);
  }
});

test('nested child bytes propagate through the rewritten parent into its startup loader', () => {
  const f = fixture();
  const parent = 'components/system/app-main-components.js';
  const child = 'components/system/manager-coaching-panel.js';
  const initial = syncStartupLazyComponentVersions(f.entries, f.read);
  f.assets.set(child, Buffer.from('// synthetic changed coaching child 中文'));
  const changed = syncStartupLazyComponentVersions(initial.sources, f.read);
  const childHash = createHash('sha256').update(f.assets.get(child)).digest('hex').slice(0, 10);
  const expectedParent = f.assets.get(parent).toString('utf8').replace('-h0123456789', `-h${childHash}`);
  assert.deepEqual(changed.dependencySources, [{ name: parent, originalSource: f.assets.get(parent).toString('utf8'), source: expectedParent }]);
  const parentHash = createHash('sha256').update(expectedParent).digest('hex').slice(0, 10);
  assert.ok(changed.sources[0].source.includes(`${parent}?v=fixture-h${parentHash}`));
  assert.notEqual(changed.sources[0].source, initial.sources[0].source);
  assert.deepEqual(changed.sources.slice(1).map(entry => entry.source), initial.sources.slice(1).map(entry => entry.source));
  f.assets.set(parent, Buffer.from(expectedParent));
  const stable = syncStartupLazyComponentVersions(changed.sources, f.read);
  assert.deepEqual(stable.dependencySources, []);
  assert.deepEqual(stable.sources.map(entry => entry.source), changed.sources.map(entry => entry.source));
});

test('missing nested bytes or malformed nested references reject the graph', () => {
  const f = fixture();
  const parent = 'components/system/app-main-components.js';
  const child = 'components/system/manager-coaching-panel.js';
  const originalParent = f.assets.get(parent).toString('utf8');
  for (const source of ['', originalParent + '\n' + originalParent, originalParent.replace('-h0123456789', '')]) {
    f.assets.set(parent, Buffer.from(source));
    assert.throws(() => syncStartupLazyComponentVersions(f.entries, f.read), /exactly once|stable prefix/);
  }
  f.assets.set(parent, Buffer.from(originalParent));
  f.assets.delete(child);
  assert.throws(() => syncStartupLazyComponentVersions(f.entries, f.read), { message: `Missing fixture asset: ${child}` });
});

test('legacy 12-character hashes and each JavaScript quote style converge to the canonical version', () => {
  for (const quote of ["'", '"', '`']) {
    const f = fixture(quote, '0123456789ab');
    const plan = syncStartupLazyComponentVersions(f.entries, f.read);
    const rewritten = new Map(plan.dependencySources.map(entry => [entry.name, entry.source]));
    for (const entry of plan.sources) {
      for (const ref of STARTUP_LAZY_COMPONENTS[entry.name]) {
        const expected = createHash('sha256').update(rewritten.get(ref) ?? f.assets.get(ref)).digest('hex').slice(0, 10);
        assert.ok(entry.source.includes(`${quote}${ref}?v=fixture-h${expected}${quote}`));
      }
    }
    for (const [parent, references] of Object.entries(NESTED_LAZY_COMPONENTS)) {
      for (const ref of references) {
        const expected = createHash('sha256').update(f.assets.get(ref)).digest('hex').slice(0, 10);
        assert.ok(rewritten.get(parent).includes(`${quote}${ref}?v=fixture-h${expected}${quote}`));
      }
    }
  }
});

test('workflow panel legacy release migrates once and subsequent source changes invalidate its loader', () => {
  const f = fixture();
  const target = 'components/operations/task-workflow-panel.js';
  f.entries[0].source = f.entries[0].source.replace(`${target}?v=fixture-h0123456789`, `${target}?v=20260908-workflow-v1`);
  const first = syncStartupLazyComponentVersions(f.entries, f.read);
  assert.ok(first.dependencies.has(target));
  const expected = createHash('sha256').update(f.assets.get(target)).digest('hex').slice(0, 10);
  assert.ok(first.sources[0].source.includes(`${target}?v=20260908-workflow-v1-h${expected}`));
  f.assets.set(target, Buffer.from('// changed workflow recovery'));
  const second = syncStartupLazyComponentVersions(first.sources, f.read);
  assert.notEqual(first.sources[0].source, second.sources[0].source);
  assert.equal(first.sources[1].source, second.sources[1].source);
  assert.deepEqual(syncStartupLazyComponentVersions(second.sources, f.read).sources.map(x => x.source), second.sources.map(x => x.source));
  const invalid = f.entries.map((entry, index) => index === 0
    ? { ...entry, source: entry.source.replace('20260908-workflow-v1', 'unknown-unversioned') } : entry);
  assert.throws(() => syncStartupLazyComponentVersions(invalid, f.read), /stable prefix/);
});
