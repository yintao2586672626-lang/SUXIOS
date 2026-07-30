import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const failures = [];
const passes = [];

const requiredBlockerIds = [
  'production-env-missing',
  'llm-connectivity-attestation-missing',
  'design-handoff-missing',
  'ota-credential-rotation-attestation-missing',
  'codex-security-scan-missing',
  'local-git-state-open',
];

const requiredScopes = [
  '@github',
  '@openai-developers',
  '@codex-security',
  '@figma',
  '@canva',
];

const requiredCommands = [
  'npm run review:release-env',
  'npm run review:release-llm',
  'npm run review:release-design',
  'npm run review:release-ota-credentials',
  'npm run review:release-security-scan',
  'npm run review:release-pr-candidates',
  'npm run review:release-staged-scope',
  'npm run review:release-external-state',
  'npm run review:release-readiness',
  'npm run review:functional-readiness',
  'npm run verify:release-status',
];

function readText(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function fail(message) {
  failures.push(message);
}

function pass(message) {
  passes.push(message);
}

function requireFile(relativePath) {
  if (!fs.existsSync(path.join(root, relativePath))) {
    fail(`${relativePath} is missing`);
    return '';
  }
  pass(`${relativePath} exists`);
  return readText(relativePath);
}

const register = requireFile('docs/release_issue_register.md');
const chineseReport = requireFile('docs/release_problem_report.zh-CN.md');
const evidenceChecklist = requireFile('docs/release_evidence_collection.zh-CN.md');
const policyText = requireFile('docs/release_blocker_policy.json');
const historicalStatusText = requireFile('docs/release_readiness_status.json');
const matrix = requireFile('docs/release_verification_command_matrix.md');

if (register) {
  for (const id of requiredBlockerIds) {
    if (!register.includes(id)) {
      fail(`release_issue_register.md must mention ${id}`);
    } else {
      pass(`release_issue_register.md mentions ${id}`);
    }
  }

  for (const scope of requiredScopes) {
    if (!register.includes(scope)) {
      fail(`release_issue_register.md must mention ${scope}`);
    } else {
      pass(`release_issue_register.md mentions ${scope}`);
    }
  }

  for (const command of requiredCommands) {
    if (!register.includes(command)) {
      fail(`release_issue_register.md must mention ${command}`);
    } else {
      pass(`release_issue_register.md mentions ${command}`);
    }
  }

  for (const phrase of [
    'Status: not release-ready',
    '.git/index.lock',
    'Do not mark any issue closed from narrative evidence alone',
    'Do not delete or sanitize local backup files without explicit operator approval',
    'Do not replace the formal Codex Security scan',
  ]) {
    if (!register.includes(phrase)) {
      fail(`release_issue_register.md must include rule/evidence: ${phrase}`);
    } else {
      pass(`release_issue_register.md includes ${phrase}`);
    }
  }
}

if (chineseReport) {
  for (const id of requiredBlockerIds) {
    if (!chineseReport.includes(id)) {
      fail(`release_problem_report.zh-CN.md must mention ${id}`);
    } else {
      pass(`release_problem_report.zh-CN.md mentions ${id}`);
    }
  }

  for (const command of requiredCommands) {
    if (!chineseReport.includes(command)) {
      fail(`release_problem_report.zh-CN.md must mention ${command}`);
    } else {
      pass(`release_problem_report.zh-CN.md mentions ${command}`);
    }
  }

  for (const phrase of [
    '当前仍不能上线使用',
    'vault/current-state.md',
    'current_use_forbidden=true',
    'live_review_required',
    '.git/index.lock',
    '不允许用口头说明替代验收命令',
    '不允许把模板、草稿、截图或旧 JSON 当作当前生产证据',
  ]) {
    if (!chineseReport.includes(phrase)) {
      fail(`release_problem_report.zh-CN.md must include ${phrase}`);
    } else {
      pass(`release_problem_report.zh-CN.md includes ${phrase}`);
    }
  }
}

if (evidenceChecklist) {
  for (const command of requiredCommands) {
    if (!evidenceChecklist.includes(command)) {
      fail(`release_evidence_collection.zh-CN.md must mention ${command}`);
    } else {
      pass(`release_evidence_collection.zh-CN.md mentions ${command}`);
    }
  }

  for (const phrase of [
    'RELEASE_ENV_FILE',
    'LLM_CONNECTIVITY_ATTESTATION_FILE',
    'DESIGN_HANDOFF_MANIFEST_FILE',
    'docs/design_handoff_manifest.json',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE',
    'CODEX_SECURITY_SCAN_DIR',
    'RELEASE_EXTERNAL_STATE_FILE',
    'docs/release_external_state_evidence.local.json',
    'redaction_checked=true',
    '未经明确授权不得删除或脱敏本地备份',
    '不能替代正式扫描',
  ]) {
    if (!evidenceChecklist.includes(phrase)) {
      fail(`release_evidence_collection.zh-CN.md must include ${phrase}`);
    } else {
      pass(`release_evidence_collection.zh-CN.md includes ${phrase}`);
    }
  }
}

if (policyText) {
  const policy = JSON.parse(policyText);
  if (policy.status_role !== 'stable_release_blocker_policy') {
    fail('release blocker policy must declare status_role=stable_release_blocker_policy');
  }
  if (policy.overall_status !== 'not_release_ready' || policy.release_ready !== false) {
    fail('release blocker policy must remain not_release_ready while live reviews are required');
  } else {
    pass('release blocker policy remains not_release_ready');
  }
  const statusIds = (policy.blockers || []).map((blocker) => blocker.id);
  for (const id of requiredBlockerIds) {
    if (!statusIds.includes(id)) {
      fail(`release blocker policy is missing blocker ${id}`);
    }
  }
  for (const blocker of policy.blockers || []) {
    if (blocker.status !== 'live_review_required') {
      fail(`release blocker policy ${blocker.id || 'unknown'} must remain live_review_required`);
    }
  }
}

if (historicalStatusText) {
  const historical = JSON.parse(historicalStatusText);
  if (
    historical.status_role !== 'historical_snapshot'
    || historical.current_use_forbidden !== true
    || historical.live_status_command !== 'npm run review:release-readiness'
    || historical.current_state_path !== 'vault/current-state.md'
  ) {
    fail('release_readiness_status.json must be explicitly historical and forbidden as current state');
  } else {
    pass('release_readiness_status.json is explicitly historical');
  }
}

if (matrix) {
  for (const id of requiredBlockerIds) {
    if (!matrix.includes(id)) {
      fail(`release verification command matrix is missing ${id}`);
    }
  }
}

if (failures.length > 0) {
  console.error('Release issue register verification failed:');
  for (const item of failures) {
    console.error(`- ${item}`);
  }
  process.exit(1);
}

console.log(`Release issue register verification passed (${passes.length} structural checks).`);
