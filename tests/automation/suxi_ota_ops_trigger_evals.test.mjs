import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const projectSkill = path.join(repoRoot, '.agents', 'skills', 'suxi-ota-ops');
const pluginSkill = path.join(repoRoot, 'plugins', 'suxi-os-toolkit', 'skills', 'suxi-ota-ops');
const projectEvalPath = path.join(projectSkill, 'evals', 'trigger-evals.json');
const pluginEvalPath = path.join(pluginSkill, 'evals', 'trigger-evals.json');

function readJson(filePath) {
  return JSON.parse(readFileSync(filePath, 'utf8'));
}

function frontmatterDescription(skillRoot) {
  const markdown = readFileSync(path.join(skillRoot, 'SKILL.md'), 'utf8');
  const frontmatter = markdown.match(/^---\r?\n([\s\S]*?)\r?\n---/u)?.[1] || '';
  const value = frontmatter.match(/^description:\s*(.+)$/mu)?.[1]?.trim() || '';
  return value.replace(/^["']|["']$/gu, '');
}

test('suxi-ota-ops trigger evals are two-sided, traceable, and distribution-identical', () => {
  const projectBytes = readFileSync(projectEvalPath);
  const pluginBytes = readFileSync(pluginEvalPath);
  assert.ok(projectBytes.equals(pluginBytes), 'project and plugin trigger eval files must match');

  const document = readJson(projectEvalPath);
  assert.equal(document.skill_name, 'suxi-ota-ops');
  assert.ok(Array.isArray(document.evals));
  assert.equal(document.evals.length, 20);

  const ids = document.evals.map((row) => row.id);
  const queries = document.evals.map((row) => row.query);
  assert.equal(new Set(ids).size, ids.length, 'trigger eval ids must be unique');
  assert.equal(new Set(queries).size, queries.length, 'trigger eval queries must be unique');

  for (const row of document.evals) {
    assert.equal(typeof row.query, 'string');
    assert.ok(row.query.trim());
    assert.equal(typeof row.should_trigger, 'boolean');
    assert.equal(typeof row.reason, 'string');
    assert.ok(row.reason.trim());
    if (!row.should_trigger) {
      assert.equal(typeof row.expected_route, 'string');
      assert.ok(row.expected_route.trim());
    }
  }

  assert.equal(document.evals.filter((row) => row.should_trigger).length, 10);
  assert.equal(document.evals.filter((row) => !row.should_trigger).length, 10);
});

test('suxi-ota-ops keeps its discriminating collection terms at the front of the description', () => {
  const projectDescription = frontmatterDescription(projectSkill);
  const pluginDescription = frontmatterDescription(pluginSkill);
  assert.equal(pluginDescription, projectDescription);

  const leadingText = projectDescription.slice(0, 40);
  for (const requiredTerm of ['携程/美团 OTA 数据采集', '导入补数', 'Profile 登录排障']) {
    assert.ok(
      leadingText.includes(requiredTerm),
      `front-loaded description must retain ${requiredTerm}`,
    );
  }
});

test('suxi-ota-ops near-miss cases cover adjacent SUXIOS routes rather than generic negatives only', () => {
  const document = readJson(projectEvalPath);
  const routes = new Set(
    document.evals
      .filter((row) => !row.should_trigger)
      .map((row) => row.expected_route),
  );
  for (const route of [
    'suxi-ota-revenue-semantic-layer',
    'suxi-ctrip-field-table-closure',
    'suxi-dashboard-ui',
    'suxi-ai-report',
    'suxi-test-guard',
    'scrapling',
    'suxi-investment-calculation',
    'none',
  ]) {
    assert.ok(routes.has(route), `near-miss routes must include ${route}`);
  }
});
