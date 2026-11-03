// Replay only the reviewed, pure policy fragment; never load the Obsidian plugin.
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const base = new URL('../docs/knowledge/hkos-workbench/', import.meta.url);
const manifest = JSON.parse(readFileSync(new URL('source-manifest.json', base), 'utf8'));
const record = manifest.retained_sources.find(item => item.file === 'sources/controlled-source-policy.cjs.txt');
const fragment = readFileSync(new URL(record.file, base), 'utf8');
assert.equal(createHash('sha256').update(fragment).digest('hex').toUpperCase(), record.sha256);
assert(!/\brequire\(|\bprocess\b|\bfetch\b|\bimport\b/.test(fragment));
const bootstrap = `function __commonJS(definitions) {
  return function () { const module = { exports: {} }; Object.values(definitions)[0](module.exports, module); return module.exports; };
}`;
const context = vm.createContext(Object.create(null), { codeGeneration: { strings: false, wasm: false } });
vm.runInContext(bootstrap + fragment + '\nvar policy = require_controlled_source_policy();', context, { timeout: 1000 });
const good = { approved: true, allowLocalContentStudio: true, distributionRight: 'CUSTOMER_PRIVATE',
  rightsEvidenceId: 'fixture-only', version: '1.0', approvedBy: 'fixture reviewer',
  approvedAt: '2026-09-01', reviewDueAt: '2026-10-01', publish_scope: 'customer_local', status: 'active' };
const cases = [
  ['valid_local_draft_source', {}, true],
  ['not_approved', { approved: false }, false],
  ['review_expired', { reviewDueAt: '2026-09-25' }, false],
  ['reviewer_missing', { approvedBy: '' }, false],
  ['future_approval', { approvedAt: '2026-09-27' }, false],
  ['invalid_calendar_date', { approvedAt: '2026-02-30' }, false],
  ['superseded', { superseded_by: 'fixture-v2' }, false],
  ['restricted', { publish_scope: ['customer_local', 'restricted'] }, false],
  ['explicitly_denied', { external_use: false }, false],
];
for (const [name, changes, expected] of cases) {
  context.input = { ...good, ...changes };
  const actual = vm.runInContext('policy.mayUseLocalContentStudioSource(input, "2026-09-26")', context, { timeout: 1000 });
  assert.equal(actual, expected, name);
}
console.log(JSON.stringify({ status: 'passed', source_version: manifest.package_version,
  source_sha256: record.sha256, cases: cases.map(([name]) => name),
  scope: 'pure_local_content_source_policy_only', plugin_installed: false }));
