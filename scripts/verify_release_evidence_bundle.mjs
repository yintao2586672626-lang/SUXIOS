import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';
import { checkLlmConnectivityAttestation } from './lib/llm_attestation_checks.mjs';
import { checkOtaCredentialRelease } from './lib/ota_credential_checks.mjs';
import { checkProductionEnvFile } from './lib/release_env_checks.mjs';
import { checkSecurityScanReports } from './lib/security_scan_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');

const sections = [];

function resolveOutputPath(outputPath) {
  return path.isAbsolute(outputPath) ? outputPath : path.join(repoRoot, outputPath);
}

function evidencePath(fileName) {
  return path.join(evidenceDir, fileName);
}

function existingEvidenceOrRepo(evidenceFileName, repoRelativeFallback) {
  const candidate = evidencePath(evidenceFileName);
  return fs.existsSync(candidate) ? candidate : repoRelativeFallback;
}

function addSection(name, input, result) {
  sections.push({
    name,
    input,
    passes: result.passes || [],
    warnings: result.warnings || [],
    failures: result.failures || [],
  });
}

const envFile = process.env.RELEASE_ENV_FILE || evidencePath('production.env');
const llmAttestationFile = process.env.LLM_CONNECTIVITY_ATTESTATION_FILE
  || existingEvidenceOrRepo('llm-attestation.json', 'docs/llm_connectivity_attestation.json');
const otaAttestationFile = process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE
  || existingEvidenceOrRepo('ota_credential_rotation_attestation.json', 'docs/ota_credential_rotation_attestation.json');
const securityScanDir = process.env.CODEX_SECURITY_SCAN_DIR
  || (fs.existsSync(evidencePath('codex-security/latest')) ? evidencePath('codex-security/latest') : undefined);

addSection(
  'production-env',
  envFile,
  checkProductionEnvFile({
    repoRoot,
    envFile,
    requireOutsideRepo: true,
  }),
);

addSection(
  'production-llm',
  llmAttestationFile,
  checkLlmConnectivityAttestation({
    repoRoot,
    attestationPath: llmAttestationFile,
  }),
);

const designManifestFile = process.env.DESIGN_HANDOFF_MANIFEST_FILE
  || existingEvidenceOrRepo('design_handoff_manifest.json', 'docs/design_handoff_manifest.json');
const designResult = checkDesignHandoff({
  repoRoot,
  manifestPath: designManifestFile,
  requireOutsideRepo: Boolean(process.env.DESIGN_HANDOFF_MANIFEST_FILE),
});
addSection(
  'design-handoff',
  designManifestFile,
  designResult,
);

addSection(
  'ota-credential-rotation',
  otaAttestationFile,
  checkOtaCredentialRelease({
    repoRoot,
    attestationPath: otaAttestationFile,
  }),
);

addSection(
  'codex-security-scan',
  securityScanDir || 'docs/security/codex-security/latest',
  checkSecurityScanReports({
    repoRoot,
    configuredScanDir: securityScanDir,
  }),
);

const passes = sections.flatMap((section) => section.passes.map((message) => `${section.name}: ${message}`));
const warnings = sections.flatMap((section) => section.warnings.map((message) => `${section.name}: ${message}`));
const failures = sections.flatMap((section) => section.failures.map((message) => `${section.name}: ${message}`));

const result = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run review:release-evidence',
  evidence_dir: evidenceDir,
  status: failures.length > 0 ? 'failed' : 'passed',
  summary: {
    passed: passes.length,
    warnings: warnings.length,
    failures: failures.length,
  },
  sections,
};

console.log(`Release evidence directory: ${evidenceDir}`);
for (const section of sections) {
  console.log(`\n[${section.name}] input: ${section.input}`);
  for (const message of section.passes) {
    console.log(`PASS: ${message}`);
  }
  for (const message of section.warnings) {
    console.warn(`WARN: ${message}`);
  }
  for (const message of section.failures) {
    console.error(`FAIL: ${message}`);
  }
}

if (process.env.RELEASE_EVIDENCE_RESULT_FILE) {
  const outputPath = resolveOutputPath(process.env.RELEASE_EVIDENCE_RESULT_FILE);
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

console.log(`\nRelease evidence summary: ${passes.length} passed, ${warnings.length} warnings, ${failures.length} failures.`);

if (failures.length > 0) {
  process.exit(1);
}
