import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkProductionEnvFile } from './lib/release_env_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseEvidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');

function existingEvidenceOrRepo(evidenceFileName, repoRelativeFallback) {
  const candidate = path.join(releaseEvidenceDir, evidenceFileName);
  if (fs.existsSync(candidate)) {
    return candidate;
  }
  const fallbackPath = path.join(repoRoot, repoRelativeFallback);
  if (fs.existsSync(fallbackPath)) {
    return repoRelativeFallback;
  }
  return candidate;
}

const envFile = process.env.RELEASE_ENV_FILE || existingEvidenceOrRepo('production.env', '.env.production');
const result = checkProductionEnvFile({
  repoRoot,
  envFile,
  requireOutsideRepo: Boolean(process.env.RELEASE_ENV_FILE) || envFile !== '.env.production',
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
