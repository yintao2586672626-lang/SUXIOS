import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { findDynamicTailwindConstructions, inspectTailwindRuntimeBuild } from '../../scripts/lib/frontend_tailwind_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

test('Tailwind construction detection distinguishes identifier suffixes and actual utility prefixes', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-tailwind-prefix-'));
  const fixture = path.join(directory, 'fixture.js');
  try {
    fs.writeFileSync(fixture, [
      "'data-testid': `dual-ota-field-csv-text-${this.surface}`,",
      "class: `bg-${tone}`,",
      "class: `hover:text-${tone}`,",
      "class: 'border-' + tone,",
      "class: key ? `bg-${tone}` : 'bg-red-500',",
      "class: monkey ? `text-${tone}` : 'text-red-500',",
      "class: key ? 'border-' + tone : 'border-red-500',",
      "key: `bg-${tone}`,",
      "'data-testid': `text-${this.surface}`,",
      "id=\"text-${this.surface}\"",
      "'aria-label': `text-${this.surface}`,",
      "'data-testid': 'border-' + this.surface,",
      "{ key: `bg-${tone}`, class: key ? `text-${tone}` : 'text-red-500' },",
    ].join('\n'));
    assert.deepEqual(findDynamicTailwindConstructions([fixture]).map(row => row.line), [2, 3, 4, 5, 6, 7, 13]);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('Tailwind runtime artifact is deterministic, complete for project literals, and materially smaller', async () => {
  const report = await inspectTailwindRuntimeBuild(repoRoot);

  assert.deepEqual(report.failures, []);
  assert.equal(report.dynamic_constructions.length, 0);
  assert.equal(report.missing_referenced_selectors.length, 0);
  assert.ok(report.metrics.referenced_selector_count > 100);
  assert.ok(report.metrics.artifact_bytes < report.metrics.source_bytes * 0.25);
  assert.ok(report.metrics.artifact_gzip_bytes < report.metrics.source_gzip_bytes * 0.25);
  assert.match(report.metrics.artifact_hash, /^[a-f0-9]{10}$/);
});
