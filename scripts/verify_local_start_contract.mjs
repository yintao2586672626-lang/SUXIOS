import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const packageJsonPath = path.join(repoRoot, 'package.json');
const startupScriptPath = path.join(repoRoot, 'scripts', 'start_local_stack.ps1');
const agentInstructionPath = path.join(repoRoot, 'AGENTS.md');
const codexHandoffPath = path.join(repoRoot, 'CODEX_HANDOFF.md');
const codexStartPromptPath = path.join(repoRoot, 'CODEX_START_PROMPT.md');

const failures = [];

const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
const scripts = packageJson.scripts || {};

for (const name of ['start', 'start:local']) {
  const command = scripts[name] || '';
  if (!command.includes('scripts/start_local_stack.ps1')) {
    failures.push(`package.json script "${name}" must run scripts/start_local_stack.ps1`);
  }
}

if (!fs.existsSync(startupScriptPath)) {
  failures.push('scripts/start_local_stack.ps1 is missing');
} else {
  const script = fs.readFileSync(startupScriptPath, 'utf8');
  const requiredTokens = [
    'Start-LocalMySql',
    'Wait-MySql',
    'Assert-DatabaseReady',
    'Start-ThinkPhp',
    '/api/health',
    'information_schema.SCHEMATA',
    'information_schema.TABLES',
  ];

  for (const token of requiredTokens) {
    if (!script.includes(token)) {
      failures.push(`startup script must include ${token}`);
    }
  }

  if (!/Start-LocalMySql[\s\S]*Assert-DatabaseReady[\s\S]*Start-ThinkPhp/.test(script)) {
    failures.push('startup script must start/verify MySQL before starting ThinkPHP');
  }
}

const startupDocs = [
  ['AGENTS.md', agentInstructionPath],
  ['CODEX_HANDOFF.md', codexHandoffPath],
  ['CODEX_START_PROMPT.md', codexStartPromptPath],
];

for (const [label, filePath] of startupDocs) {
  if (!fs.existsSync(filePath)) {
    failures.push(`${label} is missing`);
    continue;
  }

  const content = fs.readFileSync(filePath, 'utf8');
  if (!content.includes('start_local_stack.ps1') && !content.includes('npm.cmd run start')) {
    failures.push(`${label} must point local startup to scripts/start_local_stack.ps1 or npm.cmd run start`);
  }

  if (/php(?:\.exe)?["']?\s+think\s+run/i.test(content)) {
    failures.push(`${label} must not instruct agents to start ThinkPHP directly without the local stack script`);
  }
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Local start contract passed.');
