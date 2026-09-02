import assert from 'node:assert/strict';
import {
  appendFileSync,
  cpSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const projectSkillsRoot = path.join(repoRoot, '.agents', 'skills');
const pluginRoot = path.join(repoRoot, 'plugins', 'suxi-os-toolkit');
const pluginSkillsRoot = path.join(pluginRoot, 'skills');
const governedSkills = [
  'suxi-product-decision',
  'suxi-test-guard',
  'suxi-user-research',
];
const implicitInvocationBySkill = new Map([
  ['suxi-product-decision', false],
  ['suxi-test-guard', true],
  ['suxi-user-research', false],
]);
const requiredQualityEvalIdsBySkill = new Map([
  ['suxi-product-decision', [
    'choose-trust-closure-over-polish-or-unauthorized-write',
    'close-call-without-authoritative-evidence-is-blocked',
    'competitor-screenshot-is-hypothesis-not-product-fact',
    'small-sample-behavior-stays-in-scope',
    'approved-direction-becomes-minimal-spec',
    'delivery-request-cannot-stop-at-a-brief',
    'external-write-prerequisites-remain-visible',
    'product-report-does-not-force-artifact-or-fabricate-data',
    'unprovided-acceptance-numbers-stay-pending',
  ]],
  ['suxi-test-guard', [
    'no-invented-nfr-thresholds',
    'blocked-is-not-pass',
    'approved-oracle-is-not-rewritten',
    'flaky-rerun-is-nonpassing',
    'page-claim-needs-page-evidence',
    'planned-checks-remain-not-run',
    'read-only-check-does-not-authorize-edits',
    'bounded-local-logic-pass',
  ]],
  ['suxi-user-research', [
    'plan-without-fake-findings',
    'synthesize-conflicting-evidence',
    'research-cannot-change-metric-truth',
    'external-research-actions-require-authorization',
    'research-plan-does-not-authorize-outreach',
    'retest-comparability',
    'screenshot-is-not-user-evidence',
  ]],
]);
const minimumTriggerExamplesPerClass = 8;
const requiredFiles = [
  'SKILL.md',
  'agents/openai.yaml',
  'evals/evals.json',
  'evals/trigger-evals.json',
];
const additionalRequiredFilesBySkill = new Map([
  ['suxi-product-decision', [
    'evals/behavior-evals.json',
    'references/decision-evidence-contract.md',
    'references/behavior-eval-provenance.md',
    'references/source-provenance.md',
  ]],
  ['suxi-test-guard', [
    'evals/behavior-evals.json',
    'references/behavior-eval-provenance.md',
  ]],
  ['suxi-user-research', [
    'evals/behavior-evals.json',
    'references/behavior-eval-provenance.md',
  ]],
]);

function normalizeRelative(filePath) {
  return filePath.split(path.sep).join('/');
}

function inspectTree(root) {
  const entries = [];
  const files = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const entryPath = path.join(directory, entry.name);
      const relativePath = normalizeRelative(path.relative(root, entryPath));
      if (entry.isSymbolicLink()) {
        throw new Error(`${relativePath} must not be a symbolic link or junction`);
      }
      if (entry.isDirectory()) {
        entries.push(`directory:${relativePath}`);
        visit(entryPath);
      } else if (entry.isFile()) {
        entries.push(`file:${relativePath}`);
        files.push(relativePath);
      } else {
        throw new Error(`${relativePath} has an unsupported filesystem type`);
      }
    }
  };
  visit(root);
  return {
    entries: entries.sort(),
    files: files.sort(),
  };
}

function readUtf8(filePath) {
  return readFileSync(filePath, 'utf8');
}

function readJson(filePath) {
  return JSON.parse(readUtf8(filePath));
}

function frontmatterField(markdown, field) {
  const frontmatter = markdown.match(/^---\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/u)?.[1] || '';
  const value = frontmatter.match(new RegExp(`^${field}:\\s*(.+)$`, 'mu'))?.[1]?.trim() || '';
  return value.replace(/^["']|["']$/gu, '');
}

function assertNonEmptyString(value, context) {
  assert.equal(typeof value, 'string', `${context} must be a string`);
  assert.ok(value.trim(), `${context} must be non-empty`);
  return value.trim();
}

function assertUniqueNonEmptyField(rows, field, context, pluralLabel) {
  const values = rows.map((row, index) => {
    assert.ok(
      row && typeof row === 'object' && !Array.isArray(row),
      `${context}[${index}] must be an object`,
    );
    return assertNonEmptyString(row[field], `${context}[${index}].${field}`);
  });
  assert.equal(new Set(values).size, values.length, `${context} ${pluralLabel} must be unique`);
}

function assertSkillParity(skillName, projectSkill, pluginSkill) {
  const projectTree = inspectTree(projectSkill);
  const pluginTree = inspectTree(pluginSkill);

  assert.deepEqual(pluginTree.entries, projectTree.entries, `${skillName} file trees must match`);
  for (const relativeFile of projectTree.files) {
    const projectBytes = readFileSync(path.join(projectSkill, relativeFile));
    const pluginBytes = readFileSync(path.join(pluginSkill, relativeFile));
    assert.ok(
      projectBytes.equals(pluginBytes),
      `${skillName}/${relativeFile} must match the plugin-distribution copy`,
    );
  }
}

function assertEvalContract(skillName, skillRoot) {
  const skillTree = inspectTree(skillRoot);
  const skillRequiredFiles = [
    ...requiredFiles,
    ...(additionalRequiredFilesBySkill.get(skillName) || []),
  ];
  for (const relativeFile of skillRequiredFiles) {
    assert.ok(skillTree.files.includes(relativeFile), `${skillName} is missing ${relativeFile}`);
  }

  const quality = readJson(path.join(skillRoot, 'evals', 'evals.json'));
  const triggers = readJson(path.join(skillRoot, 'evals', 'trigger-evals.json'));
  assert.equal(quality.skill_name, skillName);
  assert.equal(triggers.skill_name, skillName);
  assert.ok(Array.isArray(quality.evals) && quality.evals.length > 0);
  assert.ok(Array.isArray(triggers.evals) && triggers.evals.length > 0);
  assertUniqueNonEmptyField(quality.evals, 'id', `${skillName} quality eval`, 'ids');
  assertUniqueNonEmptyField(triggers.evals, 'id', `${skillName} trigger eval`, 'ids');
  const requiredQualityEvalIds = requiredQualityEvalIdsBySkill.get(skillName);
  assert.ok(Array.isArray(requiredQualityEvalIds), `${skillName} needs a governed quality-eval contract`);
  const qualityEvalIds = new Set(quality.evals.map((row) => row.id));
  for (const requiredId of requiredQualityEvalIds) {
    assert.ok(qualityEvalIds.has(requiredId), `${skillName} is missing required quality eval ${requiredId}`);
  }

  for (const row of quality.evals) {
    assertNonEmptyString(row.prompt, `${skillName}/${row.id} prompt`);
    assertNonEmptyString(row.expected_output, `${skillName}/${row.id} expected_output`);
    assert.ok(Array.isArray(row.assertions) && row.assertions.length > 0, `${skillName}/${row.id} needs assertions`);
    row.assertions.forEach((item, index) => {
      assertNonEmptyString(item, `${skillName}/${row.id} assertion[${index}]`);
    });
  }

  assertUniqueNonEmptyField(triggers.evals, 'query', `${skillName} trigger eval`, 'queries');
  for (const row of triggers.evals) {
    assert.equal(
      typeof row.should_trigger,
      'boolean',
      `${skillName}/${row.id} should_trigger must be boolean`,
    );
  }
  const positive = triggers.evals.filter((row) => row.should_trigger === true);
  const negative = triggers.evals.filter((row) => row.should_trigger === false);
  if (implicitInvocationBySkill.get(skillName) === false) {
    for (const row of positive) {
      assert.ok(
        row.query.includes(`$${skillName}`),
        `${skillName}/${row.id} explicit-only positive trigger eval must mention $${skillName}`,
      );
    }
  }
  assert.ok(
    positive.length >= minimumTriggerExamplesPerClass,
    `${skillName} needs at least ${minimumTriggerExamplesPerClass} positive trigger evals`,
  );
  assert.ok(
    negative.length >= minimumTriggerExamplesPerClass,
    `${skillName} needs at least ${minimumTriggerExamplesPerClass} negative trigger evals`,
  );
}

test('governed SUXIOS skills have exact project and plugin-distribution parity', () => {
  const pluginManifest = readJson(path.join(pluginRoot, '.codex-plugin', 'plugin.json'));
  assert.equal(pluginManifest.skills, './skills/');

  for (const skillName of governedSkills) {
    const projectSkill = path.join(projectSkillsRoot, skillName);
    const pluginSkill = path.join(pluginSkillsRoot, skillName);
    assertSkillParity(skillName, projectSkill, pluginSkill);
  }
});

test('skill parity guard rejects distribution drift in an isolated copy', () => {
  const skillName = governedSkills[0];
  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-skill-contract-'));
  const projectSkill = path.join(tempRoot, 'project', skillName);
  const pluginSkill = path.join(tempRoot, 'plugin', skillName);

  try {
    cpSync(path.join(projectSkillsRoot, skillName), projectSkill, { recursive: true });
    cpSync(path.join(projectSkillsRoot, skillName), pluginSkill, { recursive: true });
    appendFileSync(path.join(pluginSkill, 'SKILL.md'), '\n# injected distribution drift\n');

    assert.throws(
      () => assertSkillParity(skillName, projectSkill, pluginSkill),
      /must match the plugin-distribution copy/u,
    );

    writeFileSync(
      path.join(pluginSkill, 'SKILL.md'),
      readFileSync(path.join(projectSkill, 'SKILL.md')),
    );
    mkdirSync(path.join(pluginSkill, 'empty-directory-drift'));
    assert.throws(
      () => assertSkillParity(skillName, projectSkill, pluginSkill),
      /file trees must match/u,
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('governed SUXIOS skills expose consistent identity and UI invocation metadata', () => {
  for (const skillName of governedSkills) {
    const skillRoot = path.join(projectSkillsRoot, skillName);
    const skillMarkdown = readUtf8(path.join(skillRoot, 'SKILL.md'));
    const declaredName = frontmatterField(skillMarkdown, 'name');
    const description = frontmatterField(skillMarkdown, 'description');
    const openaiYaml = readUtf8(path.join(skillRoot, 'agents', 'openai.yaml'));

    assert.equal(declaredName, skillName, `${skillName} frontmatter name must match its folder`);
    assert.ok(description, `${skillName} must have a non-empty frontmatter description`);
    assert.match(openaiYaml, new RegExp(`\\$${skillName}(?:\\s|["'])`, 'u'));
    const expectedImplicitInvocation = implicitInvocationBySkill.get(skillName);
    assert.equal(typeof expectedImplicitInvocation, 'boolean');
    assert.match(
      openaiYaml,
      new RegExp(
        `^\\s*allow_implicit_invocation:\\s*${expectedImplicitInvocation}\\s*$`,
        'mu',
      ),
    );
  }
});

test('governed SUXIOS skill evals are traceable, strictly typed, unique, and sufficiently two-sided', () => {
  for (const skillName of governedSkills) {
    const skillRoot = path.join(projectSkillsRoot, skillName);
    assertEvalContract(skillName, skillRoot);
  }
});

test('product decision guard rejects missing provenance and its golden decision case', () => {
  const skillName = 'suxi-product-decision';
  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-product-decision-contract-'));
  const skillRoot = path.join(tempRoot, skillName);
  const resetSkillCopy = () => {
    rmSync(skillRoot, { recursive: true, force: true });
    cpSync(path.join(projectSkillsRoot, skillName), skillRoot, { recursive: true });
  };

  try {
    resetSkillCopy();
    rmSync(path.join(skillRoot, 'references', 'source-provenance.md'));
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      /is missing references\/source-provenance\.md/u,
    );

    resetSkillCopy();
    const qualityPath = path.join(skillRoot, 'evals', 'evals.json');
    const quality = readJson(qualityPath);
    quality.evals = quality.evals.filter(
      (row) => row.id !== 'choose-trust-closure-over-polish-or-unauthorized-write',
    );
    writeFileSync(qualityPath, `${JSON.stringify(quality, null, 2)}\n`);
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      /is missing required quality eval choose-trust-closure-over-polish-or-unauthorized-write/u,
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('eval guard rejects missing cases, duplicate ids, coerced strings, duplicate queries, and one-sided triggers', () => {
  const skillName = 'suxi-user-research';
  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-skill-eval-'));
  const skillRoot = path.join(tempRoot, skillName);
  const resetSkillCopy = () => {
    rmSync(skillRoot, { recursive: true, force: true });
    cpSync(path.join(projectSkillsRoot, skillName), skillRoot, { recursive: true });
  };

  try {
    resetSkillCopy();
    const qualityPath = path.join(skillRoot, 'evals', 'evals.json');
    const missingCaseQuality = readJson(qualityPath);
    missingCaseQuality.evals = missingCaseQuality.evals.filter(
      (row) => row.id !== 'research-plan-does-not-authorize-outreach',
    );
    writeFileSync(qualityPath, `${JSON.stringify(missingCaseQuality, null, 2)}\n`);
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      /is missing required quality eval research-plan-does-not-authorize-outreach/u,
    );

    resetSkillCopy();
    const quality = readJson(qualityPath);
    quality.evals[1].id = quality.evals[0].id;
    writeFileSync(qualityPath, `${JSON.stringify(quality, null, 2)}\n`);
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      /quality eval ids must be unique/u,
    );

    resetSkillCopy();
    const coercedQuality = readJson(qualityPath);
    coercedQuality.evals[0].prompt = 123;
    writeFileSync(qualityPath, `${JSON.stringify(coercedQuality, null, 2)}\n`);
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      /prompt must be a string/u,
    );

    resetSkillCopy();
    const triggerPath = path.join(skillRoot, 'evals', 'trigger-evals.json');
    const triggers = readJson(triggerPath);
    triggers.evals[1].query = triggers.evals[0].query;
    writeFileSync(triggerPath, `${JSON.stringify(triggers, null, 2)}\n`);
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      /trigger eval queries must be unique/u,
    );

    resetSkillCopy();
    const implicitPositiveTriggers = readJson(triggerPath);
    const explicitPositive = implicitPositiveTriggers.evals.find(
      (row) => row.should_trigger === true,
    );
    explicitPositive.query = explicitPositive.query.replace(`$${skillName}`, '').trim();
    writeFileSync(triggerPath, `${JSON.stringify(implicitPositiveTriggers, null, 2)}\n`);
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      /explicit-only positive trigger eval must mention \$suxi-user-research/u,
    );

    resetSkillCopy();
    const oneSidedTriggers = readJson(triggerPath);
    const positives = oneSidedTriggers.evals.filter((row) => row.should_trigger === true);
    positives.slice(3).forEach((row) => {
      row.should_trigger = false;
    });
    writeFileSync(triggerPath, `${JSON.stringify(oneSidedTriggers, null, 2)}\n`);
    assert.throws(
      () => assertEvalContract(skillName, skillRoot),
      new RegExp(`needs at least ${minimumTriggerExamplesPerClass} positive trigger evals`, 'u'),
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});
