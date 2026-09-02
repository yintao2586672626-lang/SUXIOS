#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptPath = fileURLToPath(import.meta.url);
const repoRoot = path.resolve(path.dirname(scriptPath), '..');

export const INTEGRATION_GATE_CONTRACT = 'suxios.integration_gate.v1';

export function parseIntegrationArgs(argv = process.argv.slice(2)) {
  const unknown = argv.filter((argument) => argument !== '--staged');
  if (unknown.length > 0) throw new Error(`unsupported_argument:${unknown[0]}`);
  return { staged: argv.includes('--staged') };
}

export function integrationChecks({ staged = false } = {}) {
  const checks = [
    {
      id: 'verifier_domain_registry',
      command: process.execPath,
      args: ['scripts/verify_verifier_domain_registry.mjs'],
    },
    {
      id: 'migration_checksum_lock',
      command: process.execPath,
      args: ['scripts/verify_migration_checksum_lock.mjs'],
    },
    {
      id: 'source_hotspot_budget',
      command: process.execPath,
      args: ['scripts/verify_source_hotspot_budget.mjs'],
    },
  ];
  if (!staged) {
    checks.push({
      id: 'p0_guards',
      npmScript: 'verify:p0-guards',
    });
    checks.push({
      id: 'working_tree_diff_check',
      command: 'git',
      args: ['diff', '--check'],
    });
  }
  return checks;
}

export function npmCommand(script, platform = process.platform, env = process.env) {
  if (platform === 'win32') {
    return {
      command: env.ComSpec || 'cmd.exe',
      args: ['/d', '/s', '/c', `npm.cmd run ${script}`],
    };
  }
  return { command: 'npm', args: ['run', script] };
}

export function runIntegrationGate({
  staged = false,
  cwd = repoRoot,
  spawn = spawnSync,
  log = console.log,
  logError = console.error,
  platform = process.platform,
  env = process.env,
} = {}) {
  const checks = integrationChecks({ staged });
  log(`Integration gate ${INTEGRATION_GATE_CONTRACT}; profile=${staged ? 'staged-index' : 'complete'}; checks=${checks.length}.`);
  for (const check of checks) {
    const invocation = check.npmScript
      ? npmCommand(check.npmScript, platform, env)
      : { command: check.command, args: check.args };
    log(`[INTEGRATION START] ${check.id}`);
    const result = spawn(invocation.command, invocation.args, {
      cwd,
      stdio: 'inherit',
      env,
      windowsHide: true,
    });
    if (result.error || result.status !== 0) {
      logError(
        `[INTEGRATION FAILED] ${check.id}; exit=${result.status ?? 'null'}; ${result.error?.message || 'command_failed'}`,
      );
      return { status: result.status ?? 1, failedCheck: check.id };
    }
    log(`[INTEGRATION PASS] ${check.id}`);
  }
  log('Canonical integration gate passed.');
  return { status: 0, failedCheck: null };
}

if (process.argv[1] && path.resolve(process.argv[1]) === scriptPath) {
  try {
    const result = runIntegrationGate(parseIntegrationArgs());
    process.exitCode = result.status;
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 2;
  }
}
