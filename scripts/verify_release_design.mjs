import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';

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

const manifestPath = process.env.DESIGN_HANDOFF_MANIFEST_FILE
  || existingEvidenceOrRepo('design_handoff_manifest.json', 'docs/design_handoff_manifest.json');
const result = checkDesignHandoff({
  repoRoot,
  manifestPath,
  requireOutsideRepo: Boolean(process.env.DESIGN_HANDOFF_MANIFEST_FILE) || manifestPath !== 'docs/design_handoff_manifest.json',
});

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
