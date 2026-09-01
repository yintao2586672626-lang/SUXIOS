import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const skillName = 'suxi-ota-revenue-semantic-layer';
const projectSkill = path.join(repoRoot, '.agents', 'skills', skillName);
const pluginSkill = path.join(repoRoot, 'plugins', 'suxi-os-toolkit', 'skills', skillName);
const projectEvalPath = path.join(
  projectSkill,
  'evals',
  'trigger-evals.json',
);
const pluginEvalPath = path.join(
  pluginSkill,
  'evals',
  'trigger-evals.json',
);

function readJson(filePath) {
  return JSON.parse(readFileSync(filePath, 'utf8'));
}

function frontmatterDescription(skillRoot) {
  const markdown = readFileSync(path.join(skillRoot, 'SKILL.md'), 'utf8');
  const frontmatter = markdown.match(/^---\r?\n([\s\S]*?)\r?\n---/u)?.[1] || '';
  const value = frontmatter.match(/^description:\s*(.+)$/mu)?.[1]?.trim() || '';
  return value.replace(/^["']|["']$/gu, '');
}

test('OTA revenue semantic trigger evals are two-sided and byte-identical', () => {
  const projectBytes = readFileSync(projectEvalPath);
  const pluginBytes = readFileSync(pluginEvalPath);
  assert.ok(projectBytes.equals(pluginBytes), 'project and plugin trigger eval files must match');

  const document = readJson(projectEvalPath);
  assert.equal(document.skill_name, skillName);
  assert.ok(Array.isArray(document.evals));
  assert.equal(document.evals.length, 20);

  const ids = document.evals.map((row) => row.id);
  const queries = document.evals.map((row) => row.query);
  const boundaries = document.evals.map((row) => row.boundary);
  assert.equal(new Set(ids).size, ids.length, 'trigger eval ids must be unique');
  assert.equal(new Set(queries).size, queries.length, 'trigger eval queries must be unique');
  assert.equal(new Set(boundaries).size, boundaries.length, 'each case must name a unique boundary');

  for (const row of document.evals) {
    assert.equal(typeof row.query, 'string');
    assert.ok(row.query.trim());
    assert.equal(typeof row.should_trigger, 'boolean');
    assert.equal(typeof row.boundary, 'string');
    assert.ok(row.boundary.trim());
    assert.equal(typeof row.reason, 'string');
    assert.ok(row.reason.trim());
    if (!row.should_trigger) {
      assert.equal(typeof row.expected_route, 'string');
      assert.ok(row.expected_route.trim());
    }
    if (Object.hasOwn(row, 'excluded_explicit_route')) {
      assert.equal(row.should_trigger, false);
      assert.equal(row.expected_route, 'none');
      assert.equal(typeof row.excluded_explicit_route, 'string');
      assert.ok(row.excluded_explicit_route.trim());
    }
  }

  assert.equal(document.evals.filter((row) => row.should_trigger).length, 10);
  assert.equal(document.evals.filter((row) => !row.should_trigger).length, 10);
});

test('positive cases cover the semantic layer decision boundaries', () => {
  const document = readJson(projectEvalPath);
  const boundaries = new Set(
    document.evals
      .filter((row) => row.should_trigger)
      .map((row) => row.boundary),
  );
  for (const boundary of [
    'verified_fact_metrics',
    'metric_definition',
    'source_precedence',
    'pricing_advisory',
    'canonical_query_path',
    'data_gap',
    'channel_scope',
    'execution_evidence',
    'accounting_fact_boundary',
    'decision_evidence_scope',
  ]) {
    assert.ok(boundaries.has(boundary), `positive boundaries must include ${boundary}`);
  }
});

test('model-visible prefix names revenue analysis, pricing, and investment evidence', () => {
  const projectDescription = frontmatterDescription(projectSkill);
  const pluginDescription = frontmatterDescription(pluginSkill);
  assert.equal(pluginDescription, projectDescription);

  const effectivePrefixFloor = projectDescription.slice(0, 24);
  for (const term of ['OTA收益分析', '定价', '投资转让证据']) {
    assert.ok(
      effectivePrefixFloor.includes(term),
      `the first 24 characters must retain ${term}`,
    );
  }
});

test('implicit product choice excludes the explicit-only product decision Skill', () => {
  const document = readJson(projectEvalPath);
  const row = document.evals.find((candidate) => candidate.id === 'N-008');
  assert.ok(row);
  assert.equal(row.should_trigger, false);
  assert.equal(row.expected_route, 'none');
  assert.equal(row.excluded_explicit_route, 'suxi-product-decision');
  assert.ok(!row.query.includes('$suxi-product-decision'));
});

test('near-miss cases cover adjacent SUXIOS routes', () => {
  const document = readJson(projectEvalPath);
  const routes = new Set(
    document.evals
      .filter((row) => !row.should_trigger)
      .map((row) => row.expected_route),
  );
  for (const route of [
    'suxi-ota-ops',
    'suxi-ctrip-field-table-closure',
    'suxi-ai-report',
    'suxi-dashboard-ui',
    'suxi-test-guard',
    'suxi-investment-calculation',
    'scrapling',
    'suxi-capability-absorption',
    'none',
  ]) {
    assert.ok(routes.has(route), `near-miss routes must include ${route}`);
  }
});
