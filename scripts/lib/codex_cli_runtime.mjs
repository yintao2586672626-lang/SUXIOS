import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

export function invocationForCandidate(candidate) {
  if (process.platform === 'win32' && candidate.toLocaleLowerCase().endsWith('.cmd')) {
    const codexScript = path.join(
      path.dirname(candidate),
      'node_modules',
      '@openai',
      'codex',
      'bin',
      'codex.js',
    );
    if (fs.existsSync(codexScript)) {
      return {
        command: process.execPath,
        kind: 'npm-command-shim',
        prefixArgs: [codexScript],
        source: candidate,
      };
    }
  }
  return {
    command: candidate,
    kind: 'direct-executable',
    prefixArgs: [],
    source: candidate,
  };
}

export function resolveCodexExecutable(override = null) {
  if (override) {
    const resolved = path.resolve(override);
    if (!fs.existsSync(resolved)) {
      throw new Error(`Codex executable does not exist: ${resolved}`);
    }
    return invocationForCandidate(resolved);
  }
  if (process.platform !== 'win32') return invocationForCandidate('codex');

  const lookup = spawnSync('where.exe', ['codex'], {
    encoding: 'utf8',
    timeout: 30_000,
    windowsHide: true,
  });
  if (lookup.error || lookup.status !== 0) {
    throw new Error('Unable to resolve codex from PATH');
  }
  const candidates = lookup.stdout
    .split(/\r?\n/u)
    .map((value) => value.trim())
    .filter(Boolean);
  const commandShim = candidates.find((candidate) => candidate.toLocaleLowerCase().endsWith('.cmd'));
  return invocationForCandidate(commandShim ?? candidates[0] ?? 'codex');
}

export function readCodexVersion(invocation) {
  const result = spawnSync(invocation.command, [...invocation.prefixArgs, '--version'], {
    encoding: 'utf8',
    timeout: 30_000,
    windowsHide: true,
  });
  if (result.error || result.status !== 0) return 'unknown';
  return result.stdout.trim() || 'unknown';
}

export function publicPath(filePath, cwd, details = false) {
  if (details) return filePath;
  const nativeRelative = path.relative(cwd, filePath);
  if (path.isAbsolute(nativeRelative)) return path.basename(filePath);
  const relative = nativeRelative.replaceAll('\\', '/');
  if (!relative || (!relative.startsWith('../') && relative !== '..')) return relative || '.';
  return path.basename(filePath);
}
