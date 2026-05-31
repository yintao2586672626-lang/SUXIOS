import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const packageJsonPath = path.join(repoRoot, 'package.json');
const startupScriptPath = path.join(repoRoot, 'scripts', 'start_local_stack.ps1');

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

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Local start contract passed.');
