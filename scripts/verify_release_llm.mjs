import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkLlmConnectivityAttestation } from './lib/llm_attestation_checks.mjs';

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

const attestationPath = process.env.LLM_CONNECTIVITY_ATTESTATION_FILE
  || existingEvidenceOrRepo('llm-attestation.json', 'docs/llm_connectivity_attestation.json');
const result = checkLlmConnectivityAttestation({ repoRoot, attestationPath });

for (const message of result.passes) {
  console.log(`PASS: ${message}`);
}
for (const message of result.failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release LLM connectivity summary: ${result.passes.length} passed, ${result.failures.length} failures.`);

if (result.failures.length > 0) {
  process.exit(1);
}
