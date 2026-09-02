import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  assertImplicitEvalDocument,
  buildCatalogPlan,
  deriveOutcome,
  normalizeCatalogRoute,
  scorePredictions,
} from '../../scripts/evaluate_codex_skill_routes.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const evaluatorPath = path.join(repoRoot, 'scripts', 'evaluate_codex_skill_routes.mjs');

function fixture() {
  const document = {
    skill_name: 'target',
    evals: [
      { id: 'P-001', query: 'target query', should_trigger: true },
      { id: 'N-001', query: 'other query', should_trigger: false, expected_route: 'other' },
      { id: 'N-002', query: 'missing query', should_trigger: false, expected_route: 'missing' },
    ],
  };
  const candidates = [
    { catalogRoute: 'bundle:target', normalizedRoute: 'target' },
    { catalogRoute: 'other', normalizedRoute: 'other' },
  ];
  const plan = buildCatalogPlan(document, candidates);
  const score = scorePredictions([
    { id: 'P-001', catalog_route: 'bundle:target' },
    { id: 'N-001', catalog_route: 'other' },
    { id: 'N-002', catalog_route: 'none' },
  ], plan);
  return { candidates, document, plan, score };
}

test('normalizes only an unambiguous requested namespaced route', () => {
  const requested = new Set(['target', 'other']);
  assert.equal(normalizeCatalogRoute('bundle:target', requested), 'target');
  assert.equal(normalizeCatalogRoute('none', requested), 'none');
  assert.equal(normalizeCatalogRoute('bundle:unknown', requested), null);
});

test('separates current-catalog accuracy from eval-case catalog coverage', () => {
  const { plan, score } = fixture();
  assert.equal(score.currentCatalogAccuracyPercent, 100);
  assert.equal(score.mismatches.length, 0);
  assert.equal(plan.gaps.length, 1);
  assert.equal(plan.evalCaseCatalogCoveragePercent, 66.67);
});

test('uses a nonzero default exit for catalog gaps and preserves explicit allowance', () => {
  const { plan, score } = fixture();
  const run = { completedToolItems: 0, protocolToolEvents: 0, score };
  const strict = deriveOutcome([run, run], plan, false);
  const allowed = deriveOutcome([run, run], plan, true);
  assert.equal(strict.status, 'incomplete_catalog');
  assert.equal(strict.exitCode, 2);
  assert.equal(allowed.status, 'pass_with_catalog_gaps');
  assert.equal(allowed.exitCode, 0);
});

test('rejects prediction drift between independent runs', () => {
  const { plan, score } = fixture();
  const changedScore = {
    ...score,
    normalizedPredictions: score.normalizedPredictions.map((row) => (
      row.id === 'P-001' ? { ...row, route: 'other' } : row
    )),
  };
  const first = { completedToolItems: 0, protocolToolEvents: 0, score };
  const second = { completedToolItems: 0, protocolToolEvents: 0, score: changedScore };
  const outcome = deriveOutcome([first, second], plan, true);
  assert.equal(outcome.status, 'failed');
  assert.equal(outcome.stableAcrossRuns, false);
});

test('keeps explicit-only exclusions out of implicit route scoring', () => {
  assert.doesNotThrow(() => assertImplicitEvalDocument({
    skill_name: 'target',
    evals: [{
      id: 'N-001',
      query: 'choose one product direction',
      should_trigger: false,
      expected_route: 'none',
      excluded_explicit_route: 'suxi-product-decision',
    }],
  }));
  assert.throws(
    () => assertImplicitEvalDocument({
      skill_name: 'target',
      evals: [{
        id: 'P-001',
        query: '$suxi-product-decision choose one product direction',
        should_trigger: true,
      }],
    }),
    /cannot score explicit Skill mention/u,
  );
});

test('CLI self-test and help stay offline and expose the strict controls', () => {
  const selfTest = spawnSync(process.execPath, [evaluatorPath, '--self-test'], {
    cwd: repoRoot,
    encoding: 'utf8',
    timeout: 10_000,
    windowsHide: true,
  });
  assert.equal(selfTest.status, 0, selfTest.stderr);
  const payload = JSON.parse(selfTest.stdout);
  assert.equal(payload.status, 'passed');
  assert.equal(payload.cases.length, 10);
  assert.ok(payload.cases.every((row) => row.passed));

  const help = spawnSync(process.execPath, [evaluatorPath, '--help'], {
    cwd: repoRoot,
    encoding: 'utf8',
    timeout: 10_000,
    windowsHide: true,
  });
  assert.equal(help.status, 0, help.stderr);
  for (const option of ['--eval', '--runs', '--allow-catalog-gaps', '--self-test']) {
    assert.ok(help.stdout.includes(option), `help must include ${option}`);
  }
});
