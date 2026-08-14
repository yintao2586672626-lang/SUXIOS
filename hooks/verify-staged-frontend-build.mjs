import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
  isManagedFrontendPath,
  isPublicEntryPath,
  normalizeManagedGitPath,
} from './frontend-managed-paths.mjs';

const helperRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const repoArgIndex = process.argv.indexOf('--repo');
const requestedRepo = repoArgIndex >= 0 ? process.argv[repoArgIndex + 1] : helperRoot;
if (!requestedRepo) throw new Error('--repo requires a path.');
const repoRoot = path.resolve(requestedRepo);
const contextVerifierIndex = process.argv.indexOf('--context-verifier');
const contextVerifier = contextVerifierIndex >= 0 ? process.argv[contextVerifierIndex + 1] : '';
if (contextVerifierIndex >= 0 && !contextVerifier) throw new Error('--context-verifier requires a path.');
const contextOnly = process.argv.includes('--context-only');

class CommandExitError extends Error {
  constructor(command, status) {
    super(`${command} exited with code ${status}`);
    this.exitCode = Number.isInteger(status) ? status : 1;
  }
}

const run = (command, args, options = {}) => {
  const result = spawnSync(command, args, {
    cwd: options.cwd || repoRoot,
    encoding: 'utf8',
    stdio: options.capture ? 'pipe' : 'inherit',
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    if (options.capture) {
      if (result.stdout) process.stdout.write(result.stdout);
      if (result.stderr) process.stderr.write(result.stderr);
    }
    throw new CommandExitError(command, result.status);
  }
  return options.capture ? result.stdout : '';
};

const resolveOuterAgentsPath = () => {
  const commonGitDir = run('git', [
    'rev-parse', '--path-format=absolute', '--git-common-dir',
  ], { capture: true }).trim();
  const commonRepoRoot = path.basename(commonGitDir).toLowerCase() === '.git'
    ? path.dirname(commonGitDir)
    : repoRoot;
  const candidates = [
    path.resolve(commonRepoRoot, '..', 'AGENTS.md'),
    path.resolve(repoRoot, '..', 'AGENTS.md'),
  ];
  return candidates.find((candidate) => fs.existsSync(candidate)) || '';
};

const changed = run('git', [
  'diff', '--name-only', '--cached', '--no-renames', '--diff-filter=ACMRD',
], { capture: true })
  .split(/\r?\n/u)
  .map(normalizeManagedGitPath)
  .filter(Boolean);

const managedFrontendChanged = changed.some(isManagedFrontendPath);
const publicEntryChanged = changed.includes('public/index.html');
const publicEntryVerifierChanged = changed.some(isPublicEntryPath);
const tasteChanged = publicEntryChanged || changed.includes('public/style.css');
const ctripChanged = changed.some((file) => /ctrip|OnlineData\.php|route\/app\.php/u.test(file));
const contextChanged = changed.some((file) => /AGENTS\.md|\.agents\/skills|vault\/|evals\/|rules\/|hooks\//u.test(file));
const needsSnapshot = Boolean(contextVerifier)
  || managedFrontendChanged
  || publicEntryChanged
  || publicEntryVerifierChanged
  || tasteChanged
  || ctripChanged
  || contextChanged;

if (!needsSnapshot) {
  console.log('No staged project verifier inputs; index verification skipped.');
  process.exit(0);
}

const snapshotRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-staged-frontend-'));
const snapshotRepoRoot = path.join(snapshotRoot, 'HOTEL');
const dependencyLink = path.join(snapshotRepoRoot, 'node_modules');
let commandFailure = null;
try {
  fs.mkdirSync(snapshotRepoRoot, { recursive: true });
  const checkoutPrefix = `${snapshotRepoRoot.replaceAll('\\', '/')}/`;
  run('git', ['checkout-index', '--all', '--force', `--prefix=${checkoutPrefix}`]);

  // The project context verifier deliberately reads the workspace-level
  // AGENTS.md outside the nested HOTEL repository. Mirror that read-only
  // boundary while every repository-owned file still comes from the index.
  const outerAgents = resolveOuterAgentsPath();
  if (outerAgents) fs.copyFileSync(outerAgents, path.join(snapshotRoot, 'AGENTS.md'));

  const dependencyRoot = path.join(repoRoot, 'node_modules');
  if (fs.existsSync(dependencyRoot) && !fs.existsSync(dependencyLink)) {
    fs.symlinkSync(dependencyRoot, dependencyLink, 'junction');
  }

  const runNode = (script) => run(process.execPath, [script], { cwd: snapshotRepoRoot });
  const runNpmVerifier = (verifier) => {
    if (process.platform === 'win32') {
      run(process.env.ComSpec || 'cmd.exe', ['/d', '/s', '/c', `npm.cmd run ${verifier}`], {
        cwd: snapshotRepoRoot,
      });
    } else {
      run('npm', ['run', verifier], { cwd: snapshotRepoRoot });
    }
  };

  if (contextVerifier) runNode(contextVerifier);

  if (!contextOnly && managedFrontendChanged) {
    for (const verifier of [
      'verify:frontend-template',
      'verify:frontend-entry-build',
      'verify:tailwind-runtime',
    ]) runNpmVerifier(verifier);
  }

  if (!contextOnly && publicEntryVerifierChanged) runNpmVerifier('verify:public-entry');
  if (!contextOnly && tasteChanged) {
    const tasteVerifier = path.join(snapshotRepoRoot, 'scripts', 'verify_taste_page_coverage.mjs');
    runNpmVerifier(fs.existsSync(tasteVerifier) ? 'verify:taste-coverage' : 'verify:p0-guards');
  }
  if (!contextOnly && ctripChanged) runNpmVerifier('verify:ctrip-capture-catalog');
  if (!contextOnly && contextChanged && !contextVerifier) runNpmVerifier('verify:context-assets');

  console.log('Staged project index verification passed.');
} catch (error) {
  commandFailure = error;
} finally {
  if (fs.existsSync(dependencyLink)) fs.unlinkSync(dependencyLink);
  fs.rmSync(snapshotRoot, { recursive: true, force: true });
}

if (commandFailure) {
  if (commandFailure instanceof CommandExitError) {
    console.error(commandFailure.message);
    process.exit(commandFailure.exitCode);
  }
  throw commandFailure;
}
