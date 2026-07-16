import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const outputFiles = [
  'hotel_system_500_test_cases_2026-07-14.json',
  'hotel_system_500_test_cases_and_audit_2026-07-14.md',
  'hotel_system_audit_summary_2026-07-14.md',
];

function generate(outputDir) {
  const result = spawnSync(process.execPath, ['scripts/generate_hotel_system_500_audit.mjs'], {
    cwd: process.cwd(),
    env: { ...process.env, SUXI_AUDIT_OUTPUT_DIR: outputDir },
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
}

test('500-case audit generation is byte-stable across isolated output directories', () => {
  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-500-audit-'));
  const firstDir = path.join(tempRoot, 'first');
  const secondDir = path.join(tempRoot, 'second');

  try {
    generate(firstDir);
    generate(secondDir);

    for (const file of outputFiles) {
      assert.deepEqual(readFileSync(path.join(firstDir, file)), readFileSync(path.join(secondDir, file)), file);
    }

    const audit = JSON.parse(readFileSync(path.join(firstDir, outputFiles[0]), 'utf8'));
    assert.equal(audit.metadata.generated_at, '2026-07-14T00:00:00+08:00');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});
