import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const failures = [];
const passes = [];

const requiredBlockerIds = [
  'production-env-missing',
  'llm-connectivity-attestation-missing',
  'design-handoff-missing',
  'backup-credential-shaped-fields',
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
const statusText = requireFile('docs/release_readiness_status.json');
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
    '4498 credential-shaped matches',
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

if (statusText) {
  const status = JSON.parse(statusText);
  if (status.overall_status !== 'not_release_ready') {
    fail('release readiness status must remain not_release_ready while issue register has open blockers');
  } else {
    pass('release readiness status remains not_release_ready');
  }
  const statusIds = (status.blockers || []).map((blocker) => blocker.id);
  for (const id of requiredBlockerIds) {
    if (!statusIds.includes(id)) {
      fail(`release readiness status is missing blocker ${id}`);
    }
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
