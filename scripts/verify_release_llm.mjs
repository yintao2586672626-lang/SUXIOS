import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  checkLlmConnectivityAttestation,
  resolveGitHead,
} from './lib/llm_attestation_checks.mjs';

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
const expectedReleaseCommit = process.env.RELEASE_EXPECTED_HEAD_SHA
  || resolveGitHead(repoRoot)
  || 'unresolved';
const result = checkLlmConnectivityAttestation({
  repoRoot,
  attestationPath,
  expectedReleaseCommit,
  expectedConfigDigest: process.env.LLM_PRODUCTION_CONFIG_DIGEST || '',
});

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
