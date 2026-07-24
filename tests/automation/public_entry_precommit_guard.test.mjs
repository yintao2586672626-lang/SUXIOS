import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

const hook = readFileSync('hooks/pre-commit.ps1', 'utf8');

test('public index changes must run the startup entry guard before visual checks', () => {
  const startupGuard = "if ($changed -contains 'public/index.html') {\n    Invoke-CheckedNative -FilePath 'npm.cmd' -ArgumentList @('run', 'verify:public-entry')\n}";
  const visualGuard = "if ($changed -contains 'public/index.html' -or $changed -contains 'public/style.css') {";

  assert.match(hook, new RegExp(startupGuard.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\n/g, '\\r?\\n')));
  assert.ok(hook.indexOf("Invoke-CheckedNative -FilePath 'npm.cmd' -ArgumentList @('run', 'verify:public-entry')") < hook.indexOf(visualGuard));
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
