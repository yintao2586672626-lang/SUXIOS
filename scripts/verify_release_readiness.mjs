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

function checkEnvReadiness() {
  if (!exists('.env')) {
    addFailure('.env is missing; production deployment config has not been verified.');
    return;
  }

  const env = parseEnv(readText('.env'));
  const appDebug = (env.get('APP_DEBUG') ?? '').toLowerCase();
  const aiConfigSecret = env.get('AI_CONFIG_SECRET') ?? '';
  const dbPass = env.get('DB_PASS') ?? '';

  if (appDebug === 'false') {
    addPass('APP_DEBUG is false.');
  } else {
    addFailure('APP_DEBUG is not false; production must not expose debug mode.');
  }

  if (aiConfigSecret.length >= 32) {
    addPass('AI_CONFIG_SECRET is present with sufficient length.');
  } else {
    addFailure('AI_CONFIG_SECRET is missing or too short for encrypted AI model configs.');
  }

  if (dbPass.length > 0) {
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

  for (const entry of fs.readdirSync(absolute, { withFileTypes: true })) {
    const relative = path.join(dir, entry.name).replace(/\\/g, '/');
    if (entry.isDirectory()) {
      if (['vendor', 'node_modules', '.git', 'runtime'].includes(entry.name)) {
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
    /(^|\/)(figma|canva|brand|branding|design-system|ui-handoff)(\/|$)/i,
  ];
  const matches = walkFiles('.').filter((file) => designPatterns.some((pattern) => pattern.test(file)));

  if (matches.length > 0) {
    addPass(`Design handoff artifacts found: ${matches.length}.`);
  } else {
    addFailure('No Figma/Canva/design-token/brand handoff artifacts were found in the repository.');
  }
}

function checkBackups() {
  const gitignore = exists('.gitignore') ? readText('.gitignore') : '';
  if (/^database\/backups\/\s*$/m.test(gitignore)) {
    addPass('database/backups is listed in .gitignore.');
  } else {
    addFailure('database/backups is not listed in .gitignore.');
  }
  addWarning('Run `git ls-files database/backups` before release to confirm no backup file is tracked.');

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

function checkTooling() {
  addWarning('Run `composer audit --no-interaction` outside this script; this script avoids spawning external commands.');
  addWarning('Run `npm audit --audit-level=moderate --json` outside this script; the latest shell run passed with 0 vulnerabilities.');
}

function checkGitEnvironment() {
  if (exists('.git/index.lock')) {
    addFailure('.git/index.lock exists; local git index is not ready for normal commit/pull flows.');
  } else {
    addPass('.git/index.lock is absent.');
  }

  addWarning('Run `git status --short --branch` before release; local cleanliness is intentionally verified outside this script.');
}

checkEnvReadiness();
checkOpenAiEntrypoints();
checkDesignArtifacts();
checkBackups();
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
