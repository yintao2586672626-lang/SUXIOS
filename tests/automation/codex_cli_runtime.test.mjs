import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
  invocationForCandidate,
  publicPath,
  readCodexVersion,
  resolveCodexExecutable,
} from '../../scripts/lib/codex_cli_runtime.mjs';

test('resolves an explicit executable and reads its version', () => {
  const invocation = resolveCodexExecutable(process.execPath);
  assert.equal(invocation.kind, 'direct-executable');
  assert.equal(invocation.command, path.resolve(process.execPath));
  assert.match(readCodexVersion(invocation), /^v\d+/u);
});

test('keeps a direct non-shim candidate unchanged', () => {
  const invocation = invocationForCandidate(process.execPath);
  assert.equal(invocation.command, process.execPath);
  assert.deepEqual(invocation.prefixArgs, []);
  assert.equal(invocation.source, process.execPath);
});

test('recognizes the Windows npm command shim layout when present', { skip: process.platform !== 'win32' }, () => {
  const root = mkdtempSync(path.join(tmpdir(), 'suxi-codex-shim-'));
  try {
    const shim = path.join(root, 'codex.cmd');
    const script = path.join(root, 'node_modules', '@openai', 'codex', 'bin', 'codex.js');
    mkdirSync(path.dirname(script), { recursive: true });
    writeFileSync(shim, '@echo off\n');
    writeFileSync(script, '#!/usr/bin/env node\n');
    const invocation = invocationForCandidate(shim);
    assert.equal(invocation.kind, 'npm-command-shim');
    assert.equal(invocation.command, process.execPath);
    assert.deepEqual(invocation.prefixArgs, [script]);
    assert.equal(invocation.source, shim);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('hides outside and cross-volume paths unless details are requested', () => {
  assert.equal(publicPath('/workspace/project/file.json', '/workspace/project', false), 'file.json');
  assert.equal(publicPath('/outside/file.json', '/workspace/project', false), 'file.json');
  if (process.platform === 'win32') {
    assert.equal(publicPath('Z:\\private\\file.json', 'C:\\workspace', false), 'file.json');
  }
  const absolute = path.resolve('visible.json');
  assert.equal(publicPath(absolute, process.cwd(), true), absolute);
});
