import { spawnSync } from 'node:child_process';

const env = {
  ...process.env,
  E2E_FULL_MIN_LOOP: process.env.E2E_FULL_MIN_LOOP || '1',
  E2E_FULL_MAX_LOOP: process.env.E2E_FULL_MAX_LOOP || '3',
  E2E_MUTATE: process.env.E2E_MUTATE || '1',
  E2E_ALLOW_DESTRUCTIVE: process.env.E2E_ALLOW_DESTRUCTIVE || '0',
};

env.E2E_LOOP = process.env.E2E_LOOP || env.E2E_FULL_MAX_LOOP;

const result = spawnSync(process.execPath, [
  'node_modules/@playwright/test/cli.js',
  'test',
  'tests/automation/full-click-coverage.spec.js',
  '--workers=1',
  '--reporter=list',
], {
  cwd: process.cwd(),
  env,
  stdio: 'inherit',
});

if (result.error) {
  console.error(result.error.message);
  process.exit(1);
}

process.exit(result.status ?? 1);
