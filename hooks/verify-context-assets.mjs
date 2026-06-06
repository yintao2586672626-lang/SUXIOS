import fs from 'node:fs';
import path from 'node:path';

const repoRoot = process.cwd();
const outerRoot = path.dirname(repoRoot);
const failures = [];

function read(relativePath, base = repoRoot) {
  return fs.readFileSync(path.join(base, relativePath), 'utf8');
}

function exists(relativePath, base = repoRoot) {
  return fs.existsSync(path.join(base, relativePath));
}

function requireIncludes(label, text, needle) {
  if (!text.includes(needle)) {
    failures.push(`${label} is missing required text: ${needle}`);
  }
}

if (!exists('AGENTS.md', outerRoot)) {
  failures.push('outer AGENTS.md is missing');
} else {
  const outerAgents = read('AGENTS.md', outerRoot);
  requireIncludes('outer AGENTS.md', outerAgents, 'Durable Context Assetization');
  requireIncludes('outer AGENTS.md', outerAgents, 'HOTEL/.agents/skills/');
  requireIncludes('outer AGENTS.md', outerAgents, 'HOTEL/hooks/');
}

const skillPath = '.agents/skills/suxi-ctrip-field-table-closure/SKILL.md';
if (!exists(skillPath)) {
  failures.push(`${skillPath} is missing`);
} else {
  const skill = read(skillPath);
  requireIncludes(skillPath, skill, 'name: suxi-ctrip-field-table-closure');
  requireIncludes(skillPath, skill, 'Ctrip response -> source path -> metric_key -> table/storage -> UI status -> verifier');
}

const vaultPath = 'vault/project-state.md';
if (!exists(vaultPath)) {
  failures.push(`${vaultPath} is missing`);
} else {
  const vault = read(vaultPath);
  requireIncludes(vaultPath, vault, 'Updated: 2026-06-06 Asia/Shanghai');
  requireIncludes(vaultPath, vault, 'codex/save-project-20260531');
  requireIncludes(vaultPath, vault, 'Ctrip response -> field -> table closure');
}

const rulesPath = 'rules/permissions.md';
if (!exists(rulesPath)) {
  failures.push(`${rulesPath} is missing`);
} else {
  const rules = read(rulesPath);
  requireIncludes(rulesPath, rules, 'Protected Scopes');
  requireIncludes(rulesPath, rules, 'Do not use network, account authorization');
  requireIncludes(rulesPath, rules, 'Do not label OTA-only data as whole-hotel');
}

const evalPath = 'evals/ctrip-field-table-closure-failures.jsonl';
if (!exists(evalPath)) {
  failures.push(`${evalPath} is missing`);
} else {
  const lines = read(evalPath).split(/\r?\n/).filter((line) => line.trim() !== '');
  if (lines.length < 5) {
    failures.push(`${evalPath} must contain at least 5 eval cases`);
  }
  const ids = new Set();
  for (const [index, line] of lines.entries()) {
    let row;
    try {
      row = JSON.parse(line);
    } catch (error) {
      failures.push(`${evalPath}:${index + 1} is not valid JSON: ${error.message}`);
      continue;
    }
    for (const key of ['id', 'failure', 'evidence', 'expected', 'guard']) {
      if (typeof row[key] !== 'string' || row[key].trim() === '') {
        failures.push(`${evalPath}:${index + 1} missing non-empty ${key}`);
      }
    }
    if (ids.has(row.id)) {
      failures.push(`${evalPath}:${index + 1} duplicate id ${row.id}`);
    }
    ids.add(row.id);
  }
}

if (!exists('hooks/pre-commit.ps1')) {
  failures.push('hooks/pre-commit.ps1 is missing');
}

const packageJson = JSON.parse(read('package.json'));
if (packageJson.scripts?.['verify:context-assets'] !== 'node hooks/verify-context-assets.mjs') {
  failures.push('package.json missing verify:context-assets script');
}
if (packageJson.scripts?.['hook:pre-commit'] !== 'powershell -NoProfile -ExecutionPolicy Bypass -File hooks/pre-commit.ps1') {
  failures.push('package.json missing hook:pre-commit script');
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Context asset verification passed.');
