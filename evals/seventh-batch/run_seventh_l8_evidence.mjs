import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const batchDir = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(batchDir, '..', '..');
const runner = resolve(rootDir, 'scripts', 'run_hotel_diagnostic_l8_batch.mjs');
const result = spawnSync(process.execPath, [runner, '--batch=seventh'], {
  cwd: rootDir,
  encoding: 'utf8',
  windowsHide: true,
  stdio: 'pipe',
});

process.stdout.write(String(result.stdout ?? ''));
process.stderr.write(String(result.stderr ?? ''));
if (result.error) {
  throw result.error;
}
process.exitCode = Number.isInteger(result.status) ? result.status : 1;
