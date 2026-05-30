import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkSecurityScanReports } from './lib/security_scan_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = checkSecurityScanReports({
  repoRoot,
  configuredScanDir: process.env.CODEX_SECURITY_SCAN_DIR,
});

for (const message of result.passes) {
  console.log(`PASS: ${message}`);
}
for (const message of result.failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release security scan summary: ${result.passes.length} passed, ${result.failures.length} failures.`);

if (result.failures.length > 0) {
  process.exit(1);
}
