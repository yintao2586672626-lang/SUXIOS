import { spawnSync } from 'node:child_process';

const env = {
  ...process.env,
  E2E_FULL_MIN_LOOP: process.env.E2E_FULL_MIN_LOOP || '1',
  E2E_FULL_MAX_LOOP: process.env.E2E_FULL_MAX_LOOP || '3',
};

env.E2E_LOOP = process.env.E2E_LOOP || env.E2E_FULL_MAX_LOOP;

const result = spawnSync(process.execPath, [
  'tests/automation/run-quick-e2e-isolated.mjs',
  '--full-click-bounded',
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
