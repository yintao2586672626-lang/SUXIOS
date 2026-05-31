import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkProductionEnvFile } from './lib/release_env_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const envFile = process.env.RELEASE_ENV_FILE || '.env.production';
const result = checkProductionEnvFile({
  repoRoot,
  envFile,
  requireOutsideRepo: Boolean(process.env.RELEASE_ENV_FILE),
});

for (const message of result.passes) {
  console.log(`PASS: ${message}`);
}
for (const message of result.failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release env summary: ${result.passes.length} passed, ${result.failures.length} failures.`);

if (result.failures.length > 0) {
  process.exit(1);
}
