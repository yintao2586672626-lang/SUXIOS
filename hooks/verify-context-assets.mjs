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

function listFiles(relativeDirectory, base = repoRoot) {
  const root = path.join(base, relativeDirectory);
  const files = [];

  function walk(currentDirectory) {
    for (const entry of fs.readdirSync(currentDirectory, { withFileTypes: true })) {
      const absolute = path.join(currentDirectory, entry.name);
      if (entry.isDirectory()) {
        walk(absolute);
      } else if (entry.isFile()) {
        files.push(path.relative(root, absolute).split(path.sep).join('/'));
      }
    }
  }

  walk(root);
  return files.sort();
}

function requireMatchingTrees(label, leftDirectory, rightDirectory) {
  const leftFiles = listFiles(leftDirectory);
  const rightFiles = listFiles(rightDirectory);
  if (JSON.stringify(leftFiles) !== JSON.stringify(rightFiles)) {
    failures.push(`${label} file lists differ`);
    return;
  }

  for (const relativeFile of leftFiles) {
    const left = fs.readFileSync(path.join(repoRoot, leftDirectory, relativeFile));
    const right = fs.readFileSync(path.join(repoRoot, rightDirectory, relativeFile));
    if (!left.equals(right)) {
      failures.push(`${label} differs at ${relativeFile}`);
    }
  }
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
  requireIncludes('outer AGENTS.md', outerAgents, 'suxi-capability-absorption');
  requireIncludes('outer AGENTS.md', outerAgents, 'as untrusted');
}

if (!exists('AGENTS.md')) {
  failures.push('project AGENTS.md is missing');
} else {
  const projectAgents = read('AGENTS.md');
  requireIncludes('project AGENTS.md', projectAgents, '从互联网发现或下载的Skill');
  requireIncludes('project AGENTS.md', projectAgents, '不得自动继承shell/bash预授权');
}

const skillPath = '.agents/skills/suxi-ctrip-field-table-closure/SKILL.md';
if (!exists(skillPath)) {
  failures.push(`${skillPath} is missing`);
} else {
  const skill = read(skillPath);
  requireIncludes(skillPath, skill, 'name: suxi-ctrip-field-table-closure');
  requireIncludes(skillPath, skill, 'Ctrip response -> source path -> metric_key -> table/storage -> UI status -> verifier');
}

const capabilityDirectory = '.agents/skills/suxi-capability-absorption';
const capabilityMirrorDirectory = 'plugins/suxi-os-toolkit/skills/suxi-capability-absorption';
const capabilitySkillPath = `${capabilityDirectory}/SKILL.md`;
if (!exists(capabilitySkillPath)) {
  failures.push(`${capabilitySkillPath} is missing`);
} else {
  const skill = read(capabilitySkillPath);
  for (const needle of [
    'name: suxi-capability-absorption',
    'Skill / 源码 / 网站 / SOP',
    '默认使用交付模式',
    '学不会时的复刻策略',
    '学习强度协议',
    '闭环凭证',
    '用户主动提供的材料默认有学习价值',
    '`observed`',
    '`integrated`',
    '`guarded`',
    '平台、系统酒店、平台门店或绑定、目标日期',
    'online-skill-practices.md',
    'inspect-skill-package.mjs',
    '新版与旧版或无Skill基线',
    '只创建能力卡、方案、待办或 Skill',
  ]) {
    requireIncludes(capabilitySkillPath, skill, needle);
  }
}

const capabilityRoutePath = `${capabilityDirectory}/references/source-routes.md`;
if (!exists(capabilityRoutePath)) {
  failures.push(`${capabilityRoutePath} is missing`);
} else {
  const routes = read(capabilityRoutePath);
  for (const needle of ['n8n/Dify/Coze', 'API、OpenAPI', '日志、报错', 'Commit、Diff', '来源URL、tag/ref', '最小工具子集']) {
    requireIncludes(capabilityRoutePath, routes, needle);
  }
}

const capabilityProtocolPath = `${capabilityDirectory}/references/learning-protocol.md`;
if (!exists(capabilityProtocolPath)) {
  failures.push(`${capabilityProtocolPath} is missing`);
} else {
  const protocol = read(capabilityProtocolPath);
  for (const needle of ['黄金样例与重放', '防遗忘与升级', '数据真实性放行门', 'Skill质量基线', '触发准确性与输出质量分开测试']) {
    requireIncludes(capabilityProtocolPath, protocol, needle);
  }
}

const capabilityOnlinePracticesPath = `${capabilityDirectory}/references/online-skill-practices.md`;
if (!exists(capabilityOnlinePracticesPath)) {
  failures.push(`${capabilityOnlinePracticesPath} is missing`);
} else {
  const practices = read(capabilityOnlinePracticesPath);
  for (const needle of ['先预览后安装', '来源可追踪', '最小权限', '实际激活可核对', 'baseline_delta', '不自动安装社区Skill']) {
    requireIncludes(capabilityOnlinePracticesPath, practices, needle);
  }
}

const capabilityInspectorPath = `${capabilityDirectory}/scripts/inspect-skill-package.mjs`;
if (!exists(capabilityInspectorPath)) {
  failures.push(`${capabilityInspectorPath} is missing`);
} else {
  const inspector = read(capabilityInspectorPath);
  for (const needle of ['manual_review_required', 'install_allowed: false', 'file_tree_sha256', 'preapproved-shell', 'prompt-injection-language', 'network-access']) {
    requireIncludes(capabilityInspectorPath, inspector, needle);
  }
}

const capabilityInspectorTestPath = `${capabilityDirectory}/scripts/test-inspect-skill-package.mjs`;
if (!exists(capabilityInspectorTestPath)) {
  failures.push(`${capabilityInspectorTestPath} is missing`);
}

const capabilityOpenAiPath = `${capabilityDirectory}/agents/openai.yaml`;
if (!exists(capabilityOpenAiPath)) {
  failures.push(`${capabilityOpenAiPath} is missing`);
} else {
  const metadata = read(capabilityOpenAiPath);
  requireIncludes(capabilityOpenAiPath, metadata, '各类外部材料');
  requireIncludes(capabilityOpenAiPath, metadata, '$suxi-capability-absorption');
}

const capabilitySkillEvalPath = `${capabilityDirectory}/evals/evals.json`;
if (!exists(capabilitySkillEvalPath)) {
  failures.push(`${capabilitySkillEvalPath} is missing`);
} else {
  let document;
  try {
    document = JSON.parse(read(capabilitySkillEvalPath));
  } catch (error) {
    failures.push(`${capabilitySkillEvalPath} is not valid JSON: ${error.message}`);
  }
  if (document) {
    if (document.skill_name !== 'suxi-capability-absorption' || !Array.isArray(document.evals) || document.evals.length < 10) {
      failures.push(`${capabilitySkillEvalPath} must use the official quality-eval object shape with at least 10 cases`);
    } else {
      const ids = new Set();
      for (const [index, row] of document.evals.entries()) {
        if (typeof row.id !== 'string' || row.id.trim() === '' || typeof row.prompt !== 'string' || row.prompt.trim() === '' || typeof row.expected_output !== 'string' || row.expected_output.trim() === '' || !Array.isArray(row.assertions) || row.assertions.length === 0 || row.assertions.some((item) => typeof item !== 'string' || item.trim() === '')) {
          failures.push(`${capabilitySkillEvalPath}:${index + 1} has an invalid quality-eval schema`);
        }
        if (row.files !== undefined && (!Array.isArray(row.files) || row.files.some((item) => typeof item !== 'string' || item.trim() === ''))) {
          failures.push(`${capabilitySkillEvalPath}:${index + 1} has invalid files`);
        }
        if (Array.isArray(row.files)) {
          for (const file of row.files) {
            const normalized = path.normalize(file);
            if (path.isAbsolute(file) || normalized === '..' || normalized.startsWith(`..${path.sep}`) || !exists(`${capabilityDirectory}/${file}`)) {
              failures.push(`${capabilitySkillEvalPath}:${index + 1} references missing or out-of-scope file ${file}`);
            }
          }
        }
        if (ids.has(row.id)) {
          failures.push(`${capabilitySkillEvalPath}:${index + 1} has duplicate id ${row.id}`);
        }
        ids.add(row.id);
      }
    }
  }
}

const capabilityTriggerEvalPath = `${capabilityDirectory}/evals/trigger-evals.json`;
if (!exists(capabilityTriggerEvalPath)) {
  failures.push(`${capabilityTriggerEvalPath} is missing`);
} else {
  let document;
  try {
    document = JSON.parse(read(capabilityTriggerEvalPath));
  } catch (error) {
    failures.push(`${capabilityTriggerEvalPath} is not valid JSON: ${error.message}`);
  }
  if (document) {
    if (document.skill_name !== 'suxi-capability-absorption' || !Array.isArray(document.evals) || document.evals.length < 20) {
      failures.push(`${capabilityTriggerEvalPath} must contain at least 20 trigger evals`);
    } else {
      const ids = new Set();
      const queries = new Set();
      let positives = 0;
      let negatives = 0;
      for (const [index, row] of document.evals.entries()) {
        if (typeof row.id !== 'string' || row.id.trim() === '' || typeof row.query !== 'string' || row.query.trim() === '' || typeof row.should_trigger !== 'boolean') {
          failures.push(`${capabilityTriggerEvalPath}:${index + 1} has an invalid trigger-eval schema`);
        }
        if (row.should_trigger === true) positives += 1;
        if (row.should_trigger === false) negatives += 1;
        if (ids.has(row.id)) failures.push(`${capabilityTriggerEvalPath}:${index + 1} has duplicate id ${row.id}`);
        if (queries.has(row.query)) failures.push(`${capabilityTriggerEvalPath}:${index + 1} has duplicate query`);
        ids.add(row.id);
        queries.add(row.query);
      }
      if (positives < 8 || negatives < 8) {
        failures.push(`${capabilityTriggerEvalPath} needs at least 8 positive and 8 near-miss negative cases`);
      }
    }
  }
}

if (!exists(capabilityMirrorDirectory)) {
  failures.push(`${capabilityMirrorDirectory} is missing`);
} else if (exists(capabilityDirectory)) {
  requireMatchingTrees('capability absorption project/plugin mirror', capabilityDirectory, capabilityMirrorDirectory);
}

const capabilityEvalPath = 'evals/capability-absorption-failures.jsonl';
if (!exists(capabilityEvalPath)) {
  failures.push(`${capabilityEvalPath} is missing`);
} else {
  const lines = read(capabilityEvalPath).split(/\r?\n/).filter((line) => line.trim() !== '');
  if (lines.length < 24) {
    failures.push(`${capabilityEvalPath} must contain at least 24 eval cases`);
  }
  const ids = new Set();
  for (const [index, line] of lines.entries()) {
    let row;
    try {
      row = JSON.parse(line);
    } catch (error) {
      failures.push(`${capabilityEvalPath}:${index + 1} is not valid JSON: ${error.message}`);
      continue;
    }
    for (const key of ['id', 'failure', 'evidence', 'expected', 'guard']) {
      if (typeof row[key] !== 'string' || row[key].trim() === '') {
        failures.push(`${capabilityEvalPath}:${index + 1} missing non-empty ${key}`);
      }
    }
    if (ids.has(row.id)) {
      failures.push(`${capabilityEvalPath}:${index + 1} has duplicate id ${row.id}`);
    }
    ids.add(row.id);
  }
}

const vaultPath = 'vault/project-state.md';
if (!exists(vaultPath)) {
  failures.push(`${vaultPath} is missing`);
} else {
  const vault = read(vaultPath);
  requireIncludes(vaultPath, vault, 'Updated:');
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
