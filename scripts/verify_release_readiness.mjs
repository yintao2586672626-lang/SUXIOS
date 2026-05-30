import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const failures = [];
const warnings = [];
const passes = [];

function addPass(message) {
  passes.push(message);
}

function addFailure(message) {
  failures.push(message);
}

function addWarning(message) {
  warnings.push(message);
}

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function exists(relativePath) {
  return fs.existsSync(path.join(repoRoot, relativePath));
}

function sleepMs(milliseconds) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds);
}

function parseEnv(content) {
  const values = new Map();
  for (const line of content.split(/\r?\n/)) {
    const match = line.match(/^\s*([^#][A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (!match) {
      continue;
    }
    values.set(match[1], match[2].replace(/^"|"$/g, '').trim());
  }
  return values;
}

function isPlaceholder(value) {
  return String(value ?? '').trim() === '' || /TODO|CHANGE_ME|example|your-|placeholder/i.test(String(value));
}

function checkEnvReadiness() {
  const envFile = process.env.RELEASE_ENV_FILE || '.env.production';
  if (!exists(envFile)) {
    addFailure(`Production env file was not found: ${envFile}. Set RELEASE_ENV_FILE to a controlled production env file before release.`);
    return;
  }

  const env = parseEnv(readText(envFile));
  const appDebug = (env.get('APP_DEBUG') ?? '').toLowerCase();
  const aiConfigSecret = env.get('AI_CONFIG_SECRET') ?? '';
  const dbPass = env.get('DB_PASS') ?? '';

  const placeholderFields = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'AI_CONFIG_SECRET'].filter((field) => {
    return isPlaceholder(env.get(field));
  });
  if (placeholderFields.length > 0) {
    addFailure(`Production env contains missing or placeholder values: ${placeholderFields.join(', ')}`);
  }

  if (appDebug === 'false') {
    addPass('APP_DEBUG is false.');
  } else {
    addFailure('APP_DEBUG is not false; production must not expose debug mode.');
  }

  if (aiConfigSecret.length >= 32 && !isPlaceholder(aiConfigSecret)) {
    addPass('AI_CONFIG_SECRET is present with sufficient length.');
  } else {
    addFailure('AI_CONFIG_SECRET is missing or too short for encrypted AI model configs.');
  }

  if (dbPass.length > 0 && !isPlaceholder(dbPass)) {
    addPass('DB_PASS is non-empty.');
  } else {
    addFailure('DB_PASS is empty; production database must not use an empty password.');
  }
}

function checkOpenAiEntrypoints() {
  const openAiClient = exists('app/service/OpenAIClient.php') ? readText('app/service/OpenAIClient.php') : '';
  const llmClient = exists('app/service/LlmClient.php') ? readText('app/service/LlmClient.php') : '';

  if (openAiClient.includes('OPENAI_API_KEY') && llmClient.includes('AI_CONFIG_SECRET')) {
    addFailure('Two AI configuration paths exist: OpenAIClient reads .env keys, while LlmClient reads encrypted DB model configs. Production entrypoint decision is required.');
  } else if (llmClient.includes('AI_CONFIG_SECRET') && llmClient.includes('AiModelConfig::where')) {
    addPass('Production AI client path is LlmClient with encrypted database model configs.');
  } else {
    addFailure('LlmClient database model configuration path was not detected.');
  }
}

function walkFiles(dir, output = []) {
  const absolute = path.join(repoRoot, dir);
  if (!fs.existsSync(absolute)) {
    return output;
  }

  let entries = [];
  try {
    entries = fs.readdirSync(absolute, { withFileTypes: true });
  } catch {
    addWarning(`Skipped unreadable local path during release scan: ${dir}`);
    return output;
  }

  for (const entry of entries) {
    const relative = path.join(dir, entry.name).replace(/\\/g, '/');
    if (entry.isDirectory()) {
      if (['vendor', 'node_modules', '.git', 'runtime', '.pytest_cache'].includes(entry.name)) {
        continue;
      }
      walkFiles(relative, output);
    } else {
      output.push(relative);
    }
  }
  return output;
}

function checkDesignArtifacts() {
  const designPatterns = [
    /\.(fig|sketch|xd|canva)$/i,
    /(^|\/)design-tokens\.json$/i,
    /(^|\/).*\.tokens\.json$/i,
  ];
  const matches = walkFiles('.').filter((file) => designPatterns.some((pattern) => pattern.test(file)));
  const manifestPath = 'docs/design_handoff_manifest.json';

  if (!exists(manifestPath) && matches.length === 0) {
    addFailure('No Figma/Canva/design-token artifacts or docs/design_handoff_manifest.json were found.');
    return;
  }

  if (exists(manifestPath)) {
    let manifest = null;
    try {
      manifest = JSON.parse(readText(manifestPath));
    } catch (error) {
      addFailure(`Design handoff manifest is not valid JSON: ${error.message}`);
      return;
    }

    const requiredStringFields = ['owner', 'last_reviewed_at', 'figma_url', 'canva_url', 'brand_kit_url'];
    const missingFields = requiredStringFields.filter((field) => {
      const value = String(manifest[field] ?? '').trim();
      return value === '' || value.includes('TODO') || value.includes('example.com');
    });

    if (missingFields.length > 0) {
      addFailure(`Design handoff manifest is incomplete: ${missingFields.join(', ')}`);
    } else if (!/^https:\/\/(www\.)?figma\.com\//.test(String(manifest.figma_url))) {
      addFailure('Design handoff manifest figma_url must be a figma.com URL.');
    } else if (!/^https:\/\/(www\.)?canva\.com\//.test(String(manifest.canva_url))) {
      addFailure('Design handoff manifest canva_url must be a canva.com URL.');
    } else {
      addPass('Design handoff manifest is present with Figma, Canva, and brand-kit references.');
    }
  }

  if (matches.length > 0) {
    addPass(`Design source/token artifacts found: ${matches.length}.`);
  }
}

function checkBackups() {
  const gitignore = exists('.gitignore') ? readText('.gitignore') : '';
  if (/^database\/backups\/\s*$/m.test(gitignore)) {
    addPass('database/backups is listed in .gitignore.');
  } else {
    addFailure('database/backups is not listed in .gitignore.');
  }

  const gitattributes = exists('.gitattributes') ? readText('.gitattributes') : '';
  if (/^database\/backups\/\*\s+export-ignore\s*$/m.test(gitattributes)) {
    addPass('database/backups is excluded from git archive exports.');
  } else {
    addFailure('database/backups is not marked export-ignore in .gitattributes.');
  }

  addWarning('Run `git ls-files database/backups` outside this script to confirm no backup file is tracked.');

  const backupDir = path.join(repoRoot, 'database/backups');
  if (!fs.existsSync(backupDir)) {
    addPass('database/backups directory is absent.');
    return;
  }

  let credentialMatches = 0;
  for (const file of walkFiles('database/backups')) {
    if (!file.endsWith('.sql')) {
      continue;
    }
    const text = readText(file);
    const matches = text.match(/usertoken|usersign|cookie\s*[:=]/gi);
    credentialMatches += matches ? matches.length : 0;
  }

  if (credentialMatches > 0) {
    addFailure(`database/backups contains OTA credential-shaped fields (${credentialMatches} matches). Rotate real credentials and exclude backups from release packages.`);
  } else {
    addPass('No OTA credential-shaped fields were found in database/backups SQL files.');
  }
}

function checkReleasePackageScope() {
  const gitignore = exists('.gitignore') ? readText('.gitignore') : '';
  const requiredIgnores = [
    '.env',
    '.env.*',
    'database/backups/',
    'hotelx_dump.sql',
    '*_dump.sql',
    '*_backup*.sql',
    '/storage/meituan_profile_*/',
    '/storage/ctrip_profile_*/',
    'reports/ctrip_capture_assets/',
    'reports/meituan_capture_assets/',
    'reports/ctrip_browser_capture_*.json',
    'reports/meituan_browser_capture_*.json',
  ];

  const missing = requiredIgnores.filter((entry) => {
    const escaped = entry.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return !new RegExp(`(^|\\n)${escaped}(\\r?\\n|$)`).test(gitignore);
  });

  if (missing.length > 0) {
    addFailure(`Release package sensitive-path ignore rules are missing: ${missing.join(', ')}`);
  } else {
    addPass('Release package sensitive-path ignore rules are present.');
  }
}

function checkCodexSecurityScan() {
  const configuredScanDir = process.env.CODEX_SECURITY_SCAN_DIR;
  const candidateDirs = [
    configuredScanDir,
    'docs/security/codex-security/latest',
    'security/codex-security/latest',
  ].filter(Boolean);

  const scanDir = candidateDirs.find((candidate) => {
    return fs.existsSync(path.isAbsolute(candidate) ? candidate : path.join(repoRoot, candidate));
  });

  if (!scanDir) {
    addFailure('Formal Codex Security scan reports were not found. Set CODEX_SECURITY_SCAN_DIR to a completed scan directory containing report.md and report.html before release.');
    return;
  }

  const resolveScanPath = (relativePath) => {
    const base = path.isAbsolute(scanDir) ? scanDir : path.join(repoRoot, scanDir);
    return path.join(base, relativePath);
  };

  const requiredArtifacts = [
    'report.md',
    'report.html',
    'artifacts/01_context/threat_model.md',
    'artifacts/02_discovery/finding_discovery_report.md',
    'artifacts/03_coverage/repository_coverage_ledger.md',
  ];
  const missingArtifacts = requiredArtifacts.filter((relativePath) => !fs.existsSync(resolveScanPath(relativePath)));

  if (missingArtifacts.length > 0) {
    addFailure(`Formal Codex Security scan is incomplete; missing artifacts: ${missingArtifacts.join(', ')}`);
    return;
  }

  addPass('Formal Codex Security scan reports and core coverage artifacts are present.');
}

function checkTooling() {
  addWarning('Confirm GitHub Actions ran `composer audit --no-interaction` and `npm audit --audit-level=moderate` on the current PR head.');
}

function checkGitEnvironment() {
  if (exists('.git/index.lock')) {
    sleepMs(1500);
  }

  if (exists('.git/index.lock')) {
    addFailure('.git/index.lock exists after retry; local git index is not ready for normal commit/pull flows.');
  } else {
    addPass('.git/index.lock is absent.');
  }

  addWarning('Run `git status --short --branch` before release; local cleanliness is intentionally verified outside this script.');
}

checkEnvReadiness();
checkOpenAiEntrypoints();
checkDesignArtifacts();
checkBackups();
checkReleasePackageScope();
checkCodexSecurityScan();
checkTooling();
checkGitEnvironment();

for (const message of passes) {
  console.log(`PASS: ${message}`);
}
for (const message of warnings) {
  console.warn(`WARN: ${message}`);
}
for (const message of failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release readiness summary: ${passes.length} passed, ${warnings.length} warnings, ${failures.length} failures.`);

if (failures.length > 0) {
  process.exit(1);
}
