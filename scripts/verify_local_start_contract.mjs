import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const packageJsonPath = path.join(repoRoot, 'package.json');
const startupScriptPath = path.join(repoRoot, 'scripts', 'start_local_stack.ps1');
const localOriginServerPath = path.join(repoRoot, 'scripts', 'local_origin_server.mjs');
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
    '$DatabaseOnly',
    'Assert-DatabaseReady',
    'Assert-DatabaseVersion',
    'Invoke-OtaRetentionPreview',
    '@("think", "db:check")',
    'Start-ThinkPhp',
    'Test-StaticAsset',
    '/api/health',
    'public/router.php',
    'local_origin_server.mjs',
    'BackendPort',
    'PhpWorkerCount',
    'BackendPorts',
    '--backends=',
    '-WindowStyle Hidden',
    'X-SUXIOS-Backend-Pool-Size',
    'ConvertFrom-Json -ErrorAction Stop',
    '$payload.checks.application -eq "ok"',
    '$payload.checks.database -eq "ok"',
    '$payload.checks.database_schema -eq "ok"',
    '--dry-run',
    'SetEnvironmentVariable("PATH", $null, "Process")',
    'information_schema.SCHEMATA',
    'information_schema.TABLES',
  ];

  for (const token of requiredTokens) {
    if (!script.includes(token)) {
      failures.push(`startup script must include ${token}`);
    }
  }

  if (!/Start-LocalMySql[\s\S]*Assert-DatabaseReady[\s\S]*Assert-DatabaseVersion[\s\S]*Start-ThinkPhp/.test(script)) {
    failures.push('startup script must verify MySQL and database schema version before starting ThinkPHP');
  }

  const databaseVersionCall = script.lastIndexOf('Assert-DatabaseVersion');
  const databaseOnlyGuard = script.indexOf('if ($DatabaseOnly) {', databaseVersionCall);
  const retentionPreviewCall = script.lastIndexOf('Invoke-OtaRetentionPreview');
  const nodeResolution = script.indexOf('$NodeExe = Resolve-CommandSource "node"');
  if (databaseVersionCall < 0
    || databaseOnlyGuard <= databaseVersionCall
    || retentionPreviewCall <= databaseOnlyGuard
    || !/if \(\$DatabaseOnly\) \{[\s\S]{0,240}?return[\s\S]{0,40}?\}/.test(script.slice(databaseOnlyGuard, retentionPreviewCall))) {
    failures.push('DatabaseOnly must return after database/schema verification and before retention or HTTP worker startup');
  }
  if (nodeResolution <= databaseOnlyGuard || nodeResolution >= retentionPreviewCall) {
    failures.push('DatabaseOnly must not depend on Node.js or the HTTP origin server');
  }

  if (/\$response\.Content\s+-like\s+"\*status\*"|\$response\.Content\s+-like\s+"\*ok\*"/.test(script)) {
    failures.push('startup health checks must parse exact JSON status instead of accepting text substrings');
  }

  if (!/online-data:cleanup-dormant-profiles[\s\S]{0,180}--dry-run/.test(script)) {
    failures.push('ordinary local startup may only preview OTA retention with --dry-run');
  }

  if (/"think",\s*"run"|public\/index\.php|public\\index\.php/.test(script)) {
    failures.push('startup script must serve PHP through public/router.php so static CSS/JS files are not routed as ThinkPHP controllers');
  }

  if (!/ValidateRange\(3,\s*16\)[\s\S]*\$PhpWorkerCount\s*=\s*3/.test(script)) {
    failures.push('startup script must default to at least three configurable PHP workers');
  }

  if (!/foreach \(\$workerPort in \$BackendPorts\)[\s\S]*Test-BackendHttpHealth -TargetPort \$workerPort/.test(script)) {
    failures.push('startup script must health-check every configured PHP worker');
  }
}

if (!fs.existsSync(localOriginServerPath)) {
  failures.push('scripts/local_origin_server.mjs is missing');
} else {
  const originServer = fs.readFileSync(localOriginServerPath, 'utf8');
  for (const token of [
    'createLocalOriginServer',
    'fs.createReadStream',
    'proxyToBackend',
    'createBackendPool',
    'nextHealthy',
    'markUnhealthy',
    '没有可用的本机 PHP worker',
    'X-SUXIOS-Backend-Pool-Size',
    '127.0.0.1',
  ]) {
    if (!originServer.includes(token)) {
      failures.push(`local origin server must include ${token}`);
    }
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
