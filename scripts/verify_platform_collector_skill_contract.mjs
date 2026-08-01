import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const root = process.cwd();
const failures = [];
const cache = new Map();

const projectContract = '.agents/skills/suxi-ota-ops/references/platform-collector-adapter-contract.md';
const pluginContract = 'plugins/suxi-os-toolkit/skills/suxi-ota-ops/references/platform-collector-adapter-contract.md';
const evalPath = 'evals/platform-collector-common-contract-failures.jsonl';
const pluginManifestPath = 'plugins/suxi-os-toolkit/.codex-plugin/plugin.json';
const pluginVersion = JSON.parse(fs.readFileSync(path.join(root, pluginManifestPath), 'utf8')).version;
const loadedSkillRoot = path.join(
  os.homedir(),
  '.codex',
  'plugins',
  'cache',
  'suxi-local-marketplace',
  'suxi-os-toolkit',
  pluginVersion,
  'skills',
);
const loadedContract = path.join(
  loadedSkillRoot,
  'suxi-ota-ops',
  'references',
  'platform-collector-adapter-contract.md',
);
const loadedOtaSkill = path.join(loadedSkillRoot, 'suxi-ota-ops', 'SKILL.md');
const loadedLoopSkill = path.join(
  loadedSkillRoot,
  'suxi-ota-pms-collector-operating-loop',
  'SKILL.md',
);

function resolve(file) {
  return path.isAbsolute(file) ? file : path.join(root, file);
}

function exists(file) {
  return fs.existsSync(resolve(file));
}

function read(file) {
  if (!cache.has(file)) {
    cache.set(file, fs.readFileSync(resolve(file), 'utf8'));
  }
  return cache.get(file);
}

function requireFile(file) {
  if (!exists(file)) {
    failures.push(`${file} is missing`);
  }
}

function requireText(file, needle) {
  if (!exists(file) || !read(file).includes(needle)) {
    failures.push(`${file} missing required contract text: ${needle}`);
  }
}

for (const file of [
  projectContract,
  pluginContract,
  loadedContract,
  '.agents/skills/suxi-ota-ops/SKILL.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-ops/SKILL.md',
  loadedOtaSkill,
  '.agents/skills/suxi-ota-pms-collector-operating-loop/SKILL.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-pms-collector-operating-loop/SKILL.md',
  loadedLoopSkill,
  '.agents/skills/suxi-ota-ops/references/ctrip-browser-capture.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-ops/references/ctrip-browser-capture.md',
  '.agents/skills/suxi-ota-ops/references/meituan-browser-capture.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-ops/references/meituan-browser-capture.md',
  evalPath,
]) {
  requireFile(file);
}

if (exists(projectContract) && exists(pluginContract) && read(projectContract) !== read(pluginContract)) {
  failures.push('project and plugin platform collector contracts must be byte-identical');
}
if (exists(projectContract) && exists(loadedContract) && read(projectContract) !== read(loadedContract)) {
  failures.push('project and currently loaded platform collector contracts must be byte-identical');
}

for (const file of [
  '.agents/skills/suxi-ota-ops/SKILL.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-ops/SKILL.md',
  loadedOtaSkill,
]) {
  requireText(file, 'Common Collector Adapter Contract');
  requireText(file, 'references/platform-collector-adapter-contract.md');
  requireText(file, '平台适配层');
}

for (const file of [
  '.agents/skills/suxi-ota-pms-collector-operating-loop/SKILL.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-pms-collector-operating-loop/SKILL.md',
  loadedLoopSkill,
]) {
  requireText(file, '../suxi-ota-ops/references/platform-collector-adapter-contract.md');
  requireText(file, 'platform-specific endpoints');
}

for (const file of [
  '.agents/skills/suxi-ota-ops/references/ctrip-browser-capture.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-ops/references/ctrip-browser-capture.md',
  '.agents/skills/suxi-ota-ops/references/meituan-browser-capture.md',
  'plugins/suxi-os-toolkit/skills/suxi-ota-ops/references/meituan-browser-capture.md',
]) {
  requireText(file, 'platform-collector-adapter-contract.md');
  requireText(file, '本文件只定义');
}

for (const needle of [
  'CollectionScope',
  'FieldFact',
  'CollectorResult',
  'Scope',
  'Session',
  'Identity',
  'Request Plan',
  'Normalize',
  'Quality',
  'Persist And Read Back',
  'Compare And Learn',
  'Deliver',
  '`ctrip`',
  '`meituan`',
  '`dingdandao_pms`',
  '字段路径、单位、日期角色、结算阶段和指标语义',
  '不改公共流程',
  'readback_verified',
  'previous_comparable_ref',
  'sensitive_material_exposed',
]) {
  requireText(projectContract, needle);
}

if (exists(evalPath)) {
  const rows = read(evalPath).split(/\r?\n/).filter((line) => line.trim() !== '');
  if (rows.length < 10) {
    failures.push(`${evalPath} must contain at least 10 eval cases`);
  }
  const ids = new Set();
  for (const [index, line] of rows.entries()) {
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

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

const hash = crypto.createHash('sha256').update(read(projectContract)).digest('hex');
console.log(
  `Platform collector skill contract verification passed. contract_sha256=${hash} loaded_version=${pluginVersion}`,
);
