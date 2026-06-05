import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();
const ECC_COMMIT = 'bc8e12bb80c904a5a9864797ef1fd1212aa82f3d';
const failures = [];

function readText(relativePath) {
  const fullPath = path.join(ROOT, relativePath);
  if (!fs.existsSync(fullPath)) {
    failures.push(`missing required file: ${relativePath}`);
    return '';
  }
  return fs.readFileSync(fullPath, 'utf8');
}

function readJson(relativePath) {
  const text = readText(relativePath);
  if (!text) {
    return null;
  }
  try {
    return JSON.parse(text);
  } catch (error) {
    failures.push(`invalid JSON in ${relativePath}: ${error.message}`);
    return null;
  }
}

function assert(condition, message) {
  if (!condition) {
    failures.push(message);
  }
}

const vendorRoot = '.agents/vendor/everything-claude-code';
for (const requiredPath of [
  `${vendorRoot}/README.md`,
  `${vendorRoot}/LICENSE`,
  `${vendorRoot}/.codex/AGENTS.md`,
  `${vendorRoot}/.codex/config.toml`,
  `${vendorRoot}/.codex-plugin/plugin.json`,
  `${vendorRoot}/.agents/skills/everything-claude-code/SKILL.md`,
  `${vendorRoot}/.agents/plugins/marketplace.json`,
  `${vendorRoot}/.suxios-source.json`,
  '.agents/skills/ecc-codex-adapter/SKILL.md',
  '.agents/skills/ecc-codex-adapter/agents/openai.yaml',
]) {
  assert(fs.existsSync(path.join(ROOT, requiredPath)), `missing required file: ${requiredPath}`);
}

const sourceMeta = readJson(`${vendorRoot}/.suxios-source.json`);
if (sourceMeta) {
  assert(sourceMeta.source_commit === ECC_COMMIT, 'ECC source commit does not match pinned adapter commit');
  assert(
    sourceMeta.codex_policy === 'reference_only_do_not_execute_claude_hooks_directly',
    'ECC source policy must keep Claude hooks reference-only',
  );
}

const eccPlugin = readJson(`${vendorRoot}/.codex-plugin/plugin.json`);
if (eccPlugin) {
  assert(eccPlugin.name === 'ecc', 'ECC Codex plugin name must be ecc');
  assert(eccPlugin.skills === './skills/', 'ECC plugin should reference root skills directory');
}

const marketplace = readJson('.agents/plugins/marketplace.json');
if (marketplace) {
  const eccEntry = Array.isArray(marketplace.plugins)
    ? marketplace.plugins.find((plugin) => plugin?.name === 'ecc')
    : null;
  assert(Boolean(eccEntry), 'local marketplace must expose ecc plugin entry');
  if (eccEntry) {
    assert(eccEntry.source?.source === 'local', 'ecc marketplace entry must use local source');
    assert(
      eccEntry.source?.path === './.agents/vendor/everything-claude-code',
      'ecc marketplace entry must point at the downloaded ECC root',
    );
    assert(eccEntry.policy?.installation === 'AVAILABLE', 'ecc plugin must be available, not installed by default');
  }
}

const adapterSkill = readText('.agents/skills/ecc-codex-adapter/SKILL.md');
assert(adapterSkill.includes('Do not run ECC installers'), 'adapter skill must prohibit implicit ECC installer execution');
assert(adapterSkill.includes('Project `AGENTS.md` is higher priority than ECC'), 'adapter skill must preserve AGENTS priority');

const agents = readText('AGENTS.md');
assert(agents.includes('.agents/skills/ecc-codex-adapter/SKILL.md'), 'AGENTS.md must route ECC/Codex adaptation to adapter skill');

if (failures.length > 0) {
  console.error(`ECC Codex adapter verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('ECC Codex adapter verification passed.');
