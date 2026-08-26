import assert from 'node:assert/strict';
import {
  copyFileSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const repositoryRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
);
const sourceScript = path.join(
  repositoryRoot,
  'scripts',
  'generate_project_state.ps1',
);
const powershell = process.platform === 'win32' ? 'powershell.exe' : 'pwsh';
const powershellProbe = spawnSync(
  powershell,
  ['-NoProfile', '-Command', '$PSVersionTable.PSVersion.ToString()'],
  { encoding: 'utf8', windowsHide: true },
);
const powershellAvailable = powershellProbe.status === 0;

function run(command, args, cwd) {
  const result = spawnSync(command, args, {
    cwd,
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(
    result.status,
    0,
    `${command} ${args.join(' ')} failed:\n${result.stdout}\n${result.stderr}`,
  );
  return result;
}

test(
  'project state refresh and check support a local branch without upstream',
  { skip: !powershellAvailable },
  () => {
    const root = mkdtempSync(path.join(tmpdir(), 'suxios-project-state-'));
    try {
      mkdirSync(path.join(root, 'scripts'), { recursive: true });
      mkdirSync(path.join(root, 'vault'), { recursive: true });
      copyFileSync(
        sourceScript,
        path.join(root, 'scripts', 'generate_project_state.ps1'),
      );
      writeFileSync(
        path.join(root, '.gitignore'),
        '/vault/current-state.md\n',
        'utf8',
      );
      writeFileSync(path.join(root, 'README.md'), '# Fixture\n', 'utf8');

      run('git', ['init'], root);
      run('git', ['config', 'user.name', 'SUXIOS Test'], root);
      run('git', ['config', 'user.email', 'test@suxios.local'], root);
      run('git', ['add', '.'], root);
      run('git', ['commit', '-m', 'fixture'], root);
      run('git', ['checkout', '-b', 'review/no-upstream'], root);

      const script = path.join(root, 'scripts', 'generate_project_state.ps1');
      const shellArgs = process.platform === 'win32'
        ? ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', script]
        : ['-NoProfile', '-File', script];
      run(powershell, [...shellArgs, '-Write'], root);

      const currentState = readFileSync(
        path.join(root, 'vault', 'current-state.md'),
        'utf8',
      );
      assert.match(currentState, /\| Upstream \| not configured \|/);
      assert.match(currentState, /\| Worktree \| clean \|/);

      writeFileSync(path.join(root, '.git', 'index.lock'), 'transient-test-lock', 'utf8');
      const check = run(powershell, [...shellArgs, '-Check'], root);
      rmSync(path.join(root, '.git', 'index.lock'), { force: true });
      assert.match(check.stdout, /Project state snapshot is current/);
    } finally {
      rmSync(root, { recursive: true, force: true });
    }
  },
);
