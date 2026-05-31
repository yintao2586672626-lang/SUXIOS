import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = checkDesignHandoff({ repoRoot });

for (const message of result.passes) {
  console.log(`PASS: ${message}`);
}
for (const message of result.warnings) {
  console.warn(`WARN: ${message}`);
}
for (const message of result.failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release design handoff summary: ${result.passes.length} passed, ${result.warnings.length} warnings, ${result.failures.length} failures.`);

if (result.failures.length > 0) {
  process.exit(1);
}
