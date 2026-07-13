import assert from 'node:assert/strict';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { inspectTailwindRuntimeBuild } from '../../scripts/lib/frontend_tailwind_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

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
