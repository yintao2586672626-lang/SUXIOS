import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

const hook = readFileSync('hooks/pre-commit.ps1', 'utf8');
const stagedVerifier = readFileSync('hooks/verify-staged-frontend-build.mjs', 'utf8');

test('public index changes run startup and visual guards against the staged index snapshot', () => {
  assert.match(hook, /hooks\/verify-staged-frontend-build\.mjs/u);
  assert.match(hook, /--context-verifier/u);
  assert.doesNotMatch(hook, /npm\.cmd/u);
  assert.match(stagedVerifier, /const publicEntryChanged = changed\.includes\('public\/index\.html'\)/u);
  assert.match(stagedVerifier, /const tasteChanged = publicEntryChanged \|\| changed\.includes\('public\/style\.css'\)/u);
  assert.ok(stagedVerifier.indexOf("runNpmVerifier('verify:public-entry')") < stagedVerifier.indexOf("runNpmVerifier(fs.existsSync(tasteVerifier) ? 'verify:taste-coverage' : 'verify:p0-guards')"));
  assert.match(hook, /\$LASTEXITCODE/);
  assert.doesNotMatch(hook, /^\s*(?:node|npm\.cmd|git)\s+/m);
});

test('pre-commit propagates a failing native verifier before reporting success', (t) => {
  const powershell = process.platform === 'win32' ? 'powershell.exe' : 'pwsh';
  const probe = spawnSync(powershell, ['-NoProfile', '-Command', '$PSVersionTable.PSVersion.ToString()'], {
    encoding: 'utf8',
    windowsHide: true,
  });
  if (probe.error || probe.status !== 0) {
    t.skip(`${powershell} is not available`);
    return;
  }

  const dir = mkdtempSync(path.join(tmpdir(), 'suxi-precommit-failure-'));
  const failingVerifier = path.join(dir, 'fail.mjs');
  writeFileSync(failingVerifier, 'process.exit(7);\n', 'utf8');

  try {
    const args = ['-NoProfile'];
    if (process.platform === 'win32') {
      args.push('-ExecutionPolicy', 'Bypass');
    }
    args.push(
      '-File',
      'hooks/pre-commit.ps1',
      '-SkipProjectVerifiers',
      '-ContextVerifierPath',
      failingVerifier,
    );
    const result = spawnSync(powershell, args, {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });
    const output = `${result.stdout || ''}${result.stderr || ''}`;

    assert.equal(result.status, 7, output);
    assert.match(output, /node exited with code 7/);
    assert.doesNotMatch(output, /Skipped project verifiers by request|Pre-commit hook checks passed/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
