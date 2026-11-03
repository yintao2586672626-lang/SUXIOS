import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { STARTUP_LAZY_COMPONENTS, syncStartupLazyComponentVersions, syncRevenueAiStaticVersion } from '../../scripts/lib/frontend_lazy_asset_versions.mjs';

function fixture() {
  const assets = new Map();
  const entries = Object.entries(STARTUP_LAZY_COMPONENTS).map(([name, references]) => ({ name,
    source: references.map((reference, index) => {
      assets.set(reference, Buffer.from(`// synthetic 中文 ${reference}\n`));
      return `const asset${index} = '${reference}?v=fixture-h0123456789';`;
    }).join('\n'),
  }));
  return { entries, assets, read: name => assets.get(name) };
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
  assert.equal(plan.dependencies.size, 4);
  for (const entry of plan.sources) {
    assert.equal(entry.originalSource, f.entries.find(item => item.name === entry.name).source);
    for (const ref of STARTUP_LAZY_COMPONENTS[entry.name]) {
      const expected = createHash('sha256').update(f.assets.get(ref)).digest('hex').slice(0, 10);
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

test('legacy 12-character hashes and each JavaScript quote style converge to the canonical version', () => {
  for (const quote of ["'", '"', '`']) {
    const f = fixture();
    f.entries = f.entries.map(entry => ({ ...entry, source: entry.source.replaceAll("'", quote).replaceAll('-h0123456789', '-h0123456789ab') }));
    const plan = syncStartupLazyComponentVersions(f.entries, f.read);
    for (const entry of plan.sources) {
      for (const ref of STARTUP_LAZY_COMPONENTS[entry.name]) {
        const expected = createHash('sha256').update(f.assets.get(ref)).digest('hex').slice(0, 10);
        assert.ok(entry.source.includes(`${quote}${ref}?v=fixture-h${expected}${quote}`));
      }
    }
  }
});
