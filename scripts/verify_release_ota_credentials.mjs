import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkOtaCredentialRelease } from './lib/ota_credential_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const attestationPath = process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE || 'docs/ota_credential_rotation_attestation.json';
const result = checkOtaCredentialRelease({ repoRoot, attestationPath });

for (const message of result.passes) {
  console.log(`PASS: ${message}`);
}
for (const message of result.warnings) {
  console.warn(`WARN: ${message}`);
}
for (const message of result.failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release OTA credential summary: ${result.passes.length} passed, ${result.warnings.length} warnings, ${result.failures.length} failures.`);

if (result.failures.length > 0) {
  process.exit(1);
}
