import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();

const requiredScope = [
  '@github',
  '@openai-developers',
  '@codex-security',
  '@figma',
  '@canva',
];

const requiredDocs = [
  'docs/release_readiness_remaining_issues.md',
  'docs/release_blocker_close_plan.md',
  'docs/release_readiness_status.json',
  'docs/release_readiness_status.schema.json',
  'docs/deployment_env_checklist.md',
  'docs/design_handoff_manifest.example.json',
  'docs/ota_credential_rotation_checklist.md',
  'docs/ota_credential_rotation_attestation.example.json',
  'docs/codex_security_scan_authorization.md',
  'docs/ui-handoff/README.md',
  'docs/release_external_state_evidence.example.json',
  'docs/release_external_state_result.example.json',
  'docs/llm_connectivity_attestation.example.json',
  'docs/release_readiness_result.example.json',
];

const requiredPackageScripts = [
  'review:release-readiness',
  'review:release-external-state',
  'verify:release-status',
];

const requiredOpenFailurePatterns = [
  /production env/i,
  /LLM|connectivity|LLM_CONNECTIVITY_ATTESTATION_FILE/i,
  /figma|canva|design-token|design_handoff_manifest|design_handoff_manifest\.json/i,
  /database\/backups|credential-shaped/i,
  /OTA credential rotation|OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE/i,
  /Codex Security|CODEX_SECURITY_SCAN_DIR/i,
];

const requiredExternalStateFailurePatterns = [
  /worktree/i,
  /evidence|\.git\/index\.lock/i,
];

const requiredDoNotClaimReadyPatterns = [
  /production env/i,
  /LLM|connectivity/i,
  /figma|canva|design-token/i,
  /OTA credentials|database\/backups/i,
  /OTA credential rotation/i,
  /Codex Security/i,
  /git state/i,
];

const requiredReportBlockerPatterns = [
  /Production env/i,
  /LLM_CONNECTIVITY_ATTESTATION_FILE|LLM connectivity/i,
  /Figma|Canva|design token/i,
  /standalone design-token files or screenshots are not sufficient/i,
  /database\/backups|credential-shaped/i,
  /OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE|OTA credential rotation/i,
  /CODEX_SECURITY_SCAN_DIR|Codex Security/i,
  /\.git\/index\.lock|Local Git state/i,
];

const requiredBlockerIds = [
  'production-env-missing',
  'llm-connectivity-attestation-missing',
  'design-handoff-missing',
  'backup-credential-shaped-fields',
  'ota-credential-rotation-attestation-missing',
  'codex-security-scan-missing',
  'local-git-state-open',
];

const requiredBlockerScopes = {
  'production-env-missing': ['@openai-developers'],
  'llm-connectivity-attestation-missing': ['@openai-developers'],
  'design-handoff-missing': ['@figma', '@canva'],
  'backup-credential-shaped-fields': ['@codex-security'],
  'ota-credential-rotation-attestation-missing': ['@codex-security'],
  'codex-security-scan-missing': ['@codex-security'],
  'local-git-state-open': ['@github'],
};

const requiredSecurityScanPatterns = [
  /subagents/i,
  /Threat model/i,
  /Finding discovery/i,
  /Validation/i,
  /Attack-path analysis/i,
  /Markdown\s*\/\s*HTML final report/i,
  /production configuration/i,
  /OTA credentials/i,
  /tenant isolation/i,
  /file import/i,
  /external HTTP/i,
];

const requiredDesignManifestKeys = [
  'owner',
  'last_reviewed_at',
  'figma_url',
  'canva_url',
  'brand_kit_url',
  'design_tokens_path',
  'covered_flows',
  'open_issues',
];

const requiredExternalEvidenceKeys = [
  'reviewed_at',
  'reviewer',
  'commands',
];

const requiredLlmAttestationKeys = [
  'reviewed_at',
  'reviewer',
  'environment',
  'provider',
  'model_key',
  'model_name',
  'base_url',
  'ai_model_config_enabled',
  'ai_config_secret_checked',
  'redaction_checked',
  'request',
  'result',
  'evidence_ref',
];

const requiredOtaAttestationKeys = [
  'reviewed_at',
  'reviewer',
  'redaction_checked',
  'platforms',
  'backup_cleanup',
];

const requiredReadinessResultKeys = [
  'schema_version',
  'generated_at',
  'command',
  'status',
  'summary',
  'passes',
  'warnings',
  'failures',
];

const requiredExternalStateResultKeys = requiredReadinessResultKeys;

const asciiReleaseDocs = [
  'docs/release_readiness_remaining_issues.md',
  'docs/release_blocker_close_plan.md',
  'docs/release_readiness_status.json',
  'docs/codex_security_scan_authorization.md',
  'docs/release_readiness_result.example.json',
  'docs/release_external_state_result.example.json',
];

const issues = [];
const passes = [];

function fail(message) {
  issues.push(message);
}

function pass(message) {
  passes.push(message);
}

function readText(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function readJson(relativePath) {
  try {
    return JSON.parse(readText(relativePath));
  } catch (error) {
    fail(`${relativePath} is not valid JSON: ${error.message}`);
    return null;
  }
}

function assertFileExists(relativePath) {
  const absolutePath = path.join(root, relativePath);
  if (!fs.existsSync(absolutePath)) {
    fail(`${relativePath} is missing`);
    return false;
  }
  pass(`${relativePath} exists`);
  return true;
}

function assertArrayContainsPatterns(values, patterns, label) {
  const joined = Array.isArray(values) ? values.join('\n') : '';
  let missing = false;
  for (const pattern of patterns) {
    if (!pattern.test(joined)) {
      fail(`${label} does not mention required blocker pattern ${pattern}`);
      missing = true;
    }
  }
  if (!missing) {
    pass(`${label} covers required blocker patterns`);
  }
}

function assertTextContainsPatterns(text, patterns, label) {
  let missing = false;
  for (const pattern of patterns) {
    if (!pattern.test(text)) {
      fail(`${label} does not mention required pattern ${pattern}`);
      missing = true;
    }
  }
  if (!missing) {
    pass(`${label} covers required patterns`);
  }
}

function assertExactStringArray(actual, expected, label) {
  if (!Array.isArray(actual)) {
    fail(`${label} must be an array`);
    return;
  }
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    fail(`${label} must be exactly ${JSON.stringify(expected)}; got ${JSON.stringify(actual)}`);
    return;
  }
  pass(`${label} matches contract`);
}

function assertAsciiText(relativePath) {
  if (!fs.existsSync(path.join(root, relativePath))) {
    fail(`${relativePath} is missing`);
    return;
  }
  const text = readText(relativePath);
  const invalid = [...text].find((char) => char.charCodeAt(0) > 0x7f);
  if (invalid) {
    fail(`${relativePath} contains non-ASCII text; keep release status docs encoding-stable`);
    return;
  }
  pass(`${relativePath} is ASCII-only`);
}

for (const doc of requiredDocs) {
  assertFileExists(doc);
}

for (const doc of asciiReleaseDocs) {
  assertAsciiText(doc);
}

const status = readJson('docs/release_readiness_status.json');
if (status) {
  if (status.schema_version !== 1) {
    fail('schema_version must be 1');
  } else {
    pass('schema_version is 1');
  }

  if (status.overall_status !== 'not_release_ready') {
    fail(`overall_status must remain not_release_ready until blockers close; got ${status.overall_status}`);
  } else {
    pass('overall_status is not_release_ready');
  }

  assertExactStringArray(status.scope, requiredScope, 'scope');

  const pluginStatus = Array.isArray(status.plugin_status) ? status.plugin_status : [];
  assertExactStringArray(
    pluginStatus.map((entry) => entry?.plugin),
    requiredScope,
    'plugin_status plugin list',
  );
  for (const plugin of requiredScope) {
    const entry = pluginStatus.find((candidate) => candidate?.plugin === plugin);
    if (!entry) {
      fail(`plugin_status is missing ${plugin}`);
      continue;
    }
    for (const field of ['status', 'resolved', 'open']) {
      if (!(field in entry)) {
        fail(`plugin_status entry ${plugin} is missing ${field}`);
      }
    }
    if (!Array.isArray(entry.resolved)) {
      fail(`plugin_status entry ${plugin}.resolved must be an array`);
    }
    if (!Array.isArray(entry.open)) {
      fail(`plugin_status entry ${plugin}.open must be an array`);
    }
  }

  const releaseCheck = status.release_readiness_check ?? {};
  if (releaseCheck.command !== 'npm run review:release-readiness') {
    fail('release_readiness_check.command must be npm run review:release-readiness');
  }
  if (releaseCheck.status !== 'failing_as_expected') {
    fail('release_readiness_check.status must be failing_as_expected while release blockers remain');
  }
  if (releaseCheck.result_file_template !== 'docs/release_readiness_result.example.json') {
    fail('release_readiness_check.result_file_template must reference docs/release_readiness_result.example.json');
  }
  assertArrayContainsPatterns(
    releaseCheck.open_failures,
    requiredOpenFailurePatterns,
    'release_readiness_check.open_failures',
  );

  const externalStateCheck = status.external_state_check ?? {};
  if (externalStateCheck.command !== 'npm run review:release-external-state') {
    fail('external_state_check.command must be npm run review:release-external-state');
  }
  if (externalStateCheck.evidence_file_template !== 'docs/release_external_state_evidence.example.json') {
    fail('external_state_check.evidence_file_template must reference docs/release_external_state_evidence.example.json');
  }
  if (externalStateCheck.result_file_template !== 'docs/release_external_state_result.example.json') {
    fail('external_state_check.result_file_template must reference docs/release_external_state_result.example.json');
  }
  if (externalStateCheck.status !== 'failing_as_expected') {
    fail('external_state_check.status must be failing_as_expected while local git blockers remain');
  }
  assertArrayContainsPatterns(
    externalStateCheck.open_failures,
    requiredExternalStateFailurePatterns,
    'external_state_check.open_failures',
  );

  assertArrayContainsPatterns(
    status.do_not_claim_ready_until,
    requiredDoNotClaimReadyPatterns,
    'do_not_claim_ready_until',
  );

  const blockers = Array.isArray(status.blockers) ? status.blockers : [];
  if (blockers.length !== requiredBlockerIds.length) {
    fail(`blockers must contain exactly ${requiredBlockerIds.length} entries`);
  }
  for (const id of requiredBlockerIds) {
    const blocker = blockers.find((candidate) => candidate?.id === id);
    if (!blocker) {
      fail(`blockers is missing ${id}`);
      continue;
    }
    if (blocker.status !== 'open') {
      fail(`blocker ${id} must remain open until proven closed`);
    }
    for (const field of ['title', 'evidence', 'close_condition']) {
      if (typeof blocker[field] !== 'string' || blocker[field].trim() === '') {
        fail(`blocker ${id} is missing ${field}`);
      }
    }
    if (!Array.isArray(blocker.scope) || blocker.scope.length === 0) {
      fail(`blocker ${id} must declare at least one scope`);
    } else {
      const expectedScope = requiredBlockerScopes[id].slice().sort();
      const actualScope = blocker.scope.slice().sort();
      if (JSON.stringify(actualScope) !== JSON.stringify(expectedScope)) {
        fail(`blocker ${id} scope must be ${expectedScope.join(', ')}`);
      }
    }
  }
}

const packageJson = readJson('package.json');
if (packageJson) {
  for (const scriptName of requiredPackageScripts) {
    if (typeof packageJson.scripts?.[scriptName] !== 'string') {
      fail(`package.json scripts is missing ${scriptName}`);
    }
  }
}

for (const jsonDoc of [
  'docs/release_readiness_status.schema.json',
  'docs/design_handoff_manifest.example.json',
  'docs/ota_credential_rotation_attestation.example.json',
  'docs/release_external_state_evidence.example.json',
  'docs/release_external_state_result.example.json',
  'docs/llm_connectivity_attestation.example.json',
  'docs/release_readiness_result.example.json',
]) {
  readJson(jsonDoc);
}

const releaseStatusSchema = readJson('docs/release_readiness_status.schema.json');
if (releaseStatusSchema) {
  const schemaProperties = releaseStatusSchema.properties || {};
  const schemaScopeEnum = schemaProperties.scope?.items?.enum;
  const schemaBlockerIdEnum = schemaProperties.blockers?.items?.properties?.id?.enum;
  const schemaBlockerScopeEnum = schemaProperties.blockers?.items?.properties?.scope?.items?.enum;

  assertExactStringArray(schemaScopeEnum, requiredScope, 'release readiness schema scope enum');
  assertExactStringArray(schemaBlockerIdEnum, requiredBlockerIds, 'release readiness schema blocker id enum');
  assertExactStringArray(schemaBlockerScopeEnum, requiredScope, 'release readiness schema blocker scope enum');

  if (schemaProperties.overall_status?.const !== 'not_release_ready') {
    fail('release readiness schema overall_status.const must be not_release_ready');
  } else {
    pass('release readiness schema overall_status.const is not_release_ready');
  }

  if (schemaProperties.blockers?.minItems !== requiredBlockerIds.length) {
    fail(`release readiness schema blockers.minItems must be ${requiredBlockerIds.length}`);
  } else {
    pass('release readiness schema blockers.minItems matches blocker count');
  }

  if (schemaProperties.blockers?.maxItems !== requiredBlockerIds.length) {
    fail(`release readiness schema blockers.maxItems must be ${requiredBlockerIds.length}`);
  } else {
    pass('release readiness schema blockers.maxItems matches blocker count');
  }

  if (schemaProperties.scope?.maxItems !== requiredScope.length) {
    fail(`release readiness schema scope.maxItems must be ${requiredScope.length}`);
  } else {
    pass('release readiness schema scope.maxItems matches plugin scope count');
  }

  if (schemaProperties.plugin_status?.maxItems !== requiredScope.length) {
    fail(`release readiness schema plugin_status.maxItems must be ${requiredScope.length}`);
  } else {
    pass('release readiness schema plugin_status.maxItems matches plugin scope count');
  }

  if (!schemaProperties.release_readiness_check?.required?.includes('result_file_template')) {
    fail('release readiness schema release_readiness_check.required must include result_file_template');
  } else {
    pass('release readiness schema requires release readiness result file template');
  }

  if (!schemaProperties.external_state_check?.required?.includes('result_file_template')) {
    fail('release readiness schema external_state_check.required must include result_file_template');
  } else {
    pass('release readiness schema requires external-state result file template');
  }
}

const llmAttestationExample = readJson('docs/llm_connectivity_attestation.example.json');
if (llmAttestationExample) {
  let attestationComplete = true;
  for (const key of requiredLlmAttestationKeys) {
    if (!(key in llmAttestationExample)) {
      fail(`docs/llm_connectivity_attestation.example.json is missing ${key}`);
      attestationComplete = false;
    }
  }
  if (llmAttestationExample.result?.status === 'passed') {
    fail('docs/llm_connectivity_attestation.example.json must remain a placeholder template, not a passing attestation');
    attestationComplete = false;
  }
  if (llmAttestationExample.redaction_checked !== true) {
    fail('docs/llm_connectivity_attestation.example.json redaction_checked must be true');
    attestationComplete = false;
  }
  if (attestationComplete) {
    pass('docs/llm_connectivity_attestation.example.json covers required fields');
  }
}

const otaAttestationExample = readJson('docs/ota_credential_rotation_attestation.example.json');
if (otaAttestationExample) {
  let otaAttestationComplete = true;
  for (const key of requiredOtaAttestationKeys) {
    if (!(key in otaAttestationExample)) {
      fail(`docs/ota_credential_rotation_attestation.example.json is missing ${key}`);
      otaAttestationComplete = false;
    }
  }
  if (otaAttestationExample.redaction_checked !== true) {
    fail('docs/ota_credential_rotation_attestation.example.json redaction_checked must be true');
    otaAttestationComplete = false;
  }
  const cleanup = otaAttestationExample.backup_cleanup || {};
  for (const key of ['database_backups_action', 'paths_reviewed', 'git_tracking_check', 'release_readiness_check']) {
    if (!(key in cleanup)) {
      fail(`docs/ota_credential_rotation_attestation.example.json backup_cleanup is missing ${key}`);
      otaAttestationComplete = false;
    }
  }
  if (otaAttestationComplete) {
    pass('docs/ota_credential_rotation_attestation.example.json covers required fields');
  }
}

const externalEvidenceExample = readJson('docs/release_external_state_evidence.example.json');
if (externalEvidenceExample) {
  let evidenceComplete = true;
  for (const key of requiredExternalEvidenceKeys) {
    if (!(key in externalEvidenceExample)) {
      fail(`docs/release_external_state_evidence.example.json is missing ${key}`);
      evidenceComplete = false;
    }
  }
  for (const commandKey of [
    'git_ls_files_database_backups',
    'git_status_short_branch',
    'gh_pr_view',
  ]) {
    if (!(commandKey in (externalEvidenceExample.commands || {}))) {
      fail(`docs/release_external_state_evidence.example.json commands is missing ${commandKey}`);
      evidenceComplete = false;
    }
  }
  if (evidenceComplete) {
    pass('docs/release_external_state_evidence.example.json covers required commands');
  }
}

const readinessResultExample = readJson('docs/release_readiness_result.example.json');
if (readinessResultExample) {
  let resultComplete = true;
  for (const key of requiredReadinessResultKeys) {
    if (!(key in readinessResultExample)) {
      fail(`docs/release_readiness_result.example.json is missing ${key}`);
      resultComplete = false;
    }
  }
  if (readinessResultExample.command !== 'npm run review:release-readiness') {
    fail('docs/release_readiness_result.example.json command must be npm run review:release-readiness');
    resultComplete = false;
  }
  if (!Array.isArray(readinessResultExample.failures) || readinessResultExample.failures.length < requiredOpenFailurePatterns.length) {
    fail(`docs/release_readiness_result.example.json failures must include at least ${requiredOpenFailurePatterns.length} entries`);
    resultComplete = false;
  }
  assertArrayContainsPatterns(
    readinessResultExample.failures,
    requiredOpenFailurePatterns,
    'docs/release_readiness_result.example.json failures',
  );
  if (resultComplete) {
    pass('docs/release_readiness_result.example.json covers required fields');
  }
}

const externalStateResultExample = readJson('docs/release_external_state_result.example.json');
if (externalStateResultExample) {
  let resultComplete = true;
  for (const key of requiredExternalStateResultKeys) {
    if (!(key in externalStateResultExample)) {
      fail(`docs/release_external_state_result.example.json is missing ${key}`);
      resultComplete = false;
    }
  }
  if (externalStateResultExample.command !== 'npm run review:release-external-state') {
    fail('docs/release_external_state_result.example.json command must be npm run review:release-external-state');
    resultComplete = false;
  }
  if (!Array.isArray(externalStateResultExample.failures) || externalStateResultExample.failures.length < requiredExternalStateFailurePatterns.length) {
    fail(`docs/release_external_state_result.example.json failures must include at least ${requiredExternalStateFailurePatterns.length} entries`);
    resultComplete = false;
  }
  assertArrayContainsPatterns(
    externalStateResultExample.failures,
    requiredExternalStateFailurePatterns,
    'docs/release_external_state_result.example.json failures',
  );
  if (resultComplete) {
    pass('docs/release_external_state_result.example.json covers required fields');
  }
}

const designManifestExample = readJson('docs/design_handoff_manifest.example.json');
if (designManifestExample) {
  let manifestComplete = true;
  for (const key of requiredDesignManifestKeys) {
    if (!(key in designManifestExample)) {
      fail(`docs/design_handoff_manifest.example.json is missing ${key}`);
      manifestComplete = false;
    }
  }
  for (const flow of [
    'login',
    'ota-data',
    'revenue-analysis',
    'ai-decision',
    'operations-management',
    'investment-decision',
  ]) {
    if (!designManifestExample.covered_flows?.includes(flow)) {
      fail(`docs/design_handoff_manifest.example.json covered_flows is missing ${flow}`);
      manifestComplete = false;
    }
  }
  if (manifestComplete) {
    pass('docs/design_handoff_manifest.example.json covers required fields and flows');
  }
}

try {
  assertTextContainsPatterns(
    readText('docs/codex_security_scan_authorization.md'),
    requiredSecurityScanPatterns,
    'docs/codex_security_scan_authorization.md',
  );
} catch (error) {
  fail(`could not read Codex Security authorization doc: ${error.message}`);
}

try {
  const report = readText('docs/release_readiness_remaining_issues.md');
  if (!report.includes('docs/release_readiness_status.json')) {
    fail('release_readiness_remaining_issues.md must reference docs/release_readiness_status.json');
  }
  assertTextContainsPatterns(
    report,
    requiredReportBlockerPatterns,
    'docs/release_readiness_remaining_issues.md',
  );
  for (const plugin of requiredScope) {
    if (!report.includes(plugin)) {
      fail(`release_readiness_remaining_issues.md must mention ${plugin}`);
    }
  }
} catch (error) {
  fail(`could not read release readiness report: ${error.message}`);
}

try {
  const closePlan = readText('docs/release_blocker_close_plan.md');
  for (const id of requiredBlockerIds) {
    if (!closePlan.includes(id)) {
      fail(`release_blocker_close_plan.md must mention ${id}`);
    }
  }
  for (const command of [
    'npm run review:release-readiness',
    'npm run review:release-external-state',
    'LLM_CONNECTIVITY_ATTESTATION_FILE',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE',
    'CODEX_SECURITY_SCAN_DIR',
    'docs/design_handoff_manifest.json',
  ]) {
    if (!closePlan.includes(command)) {
      fail(`release_blocker_close_plan.md must mention ${command}`);
    }
  }
} catch (error) {
  fail(`could not read release blocker close plan: ${error.message}`);
}

if (issues.length > 0) {
  console.error('Release status contract failed:');
  for (const issue of issues) {
    console.error(`- ${issue}`);
  }
  process.exit(1);
}

console.log(`Release status contract passed (${passes.length} structural checks).`);
