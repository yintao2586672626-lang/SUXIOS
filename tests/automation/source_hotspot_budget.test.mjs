import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
  inspectSourceHotspotBudget,
  SOURCE_HOTSPOT_BUDGETS,
  sourceLineCount,
} from '../../scripts/lib/source_hotspot_budget.mjs';
import { SOURCE_CONCERN_PATHS } from '../../scripts/lib/source_aggregate.mjs';

test('source line counting is newline-style independent', () => {
  assert.equal(sourceLineCount('one\ntwo\n'), 2);
  assert.equal(sourceLineCount('one\r\ntwo\r\n'), 2);
  assert.equal(sourceLineCount('one\rtwo'), 2);
  assert.equal(sourceLineCount(''), 0);
});

test('hotspot budget reports missing and oversized sources', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-budget-'));
  try {
    fs.mkdirSync(path.join(root, 'app'), { recursive: true });
    fs.writeFileSync(path.join(root, 'app', 'small.php'), "one\ntwo\n", 'utf8');
    const result = inspectSourceHotspotBudget(root, [
      { path: 'app/small.php', max_lines: 1, boundary: 'fixture' },
      { path: 'app/missing.php', max_lines: 1, boundary: 'fixture' },
    ]);

    assert.deepEqual(result.failures.map((item) => item.reason), [
      'line_ratchet_exceeded',
      'missing',
    ]);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('known debt is reported while its explicit no-growth ratchet remains enforceable', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-ratchet-'));
  try {
    fs.mkdirSync(path.join(root, 'app'), { recursive: true });
    fs.writeFileSync(path.join(root, 'app', 'known.php'), "one\ntwo\nthree\n", 'utf8');
    const passing = inspectSourceHotspotBudget(root, [
      { path: 'app/known.php', max_lines: 2, ratchet_max_lines: 3, boundary: 'fixture debt' },
    ]);
    assert.deepEqual(passing.failures, []);
    assert.equal(passing.debts[0].debt_lines, 1);

    fs.appendFileSync(path.join(root, 'app', 'known.php'), 'four\n', 'utf8');
    const growing = inspectSourceHotspotBudget(root, [
      { path: 'app/known.php', max_lines: 2, ratchet_max_lines: 3, boundary: 'fixture debt' },
    ]);
    assert.equal(growing.failures[0].reason, 'line_ratchet_exceeded');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('automatic discovery rejects a new oversized source absent from the reviewed budget list', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-discovery-'));
  try {
    fs.mkdirSync(path.join(root, 'app'), { recursive: true });
    fs.writeFileSync(path.join(root, 'app', 'unreviewed.php'), "one\ntwo\nthree\n", 'utf8');
    const result = inspectSourceHotspotBudget(root, [], {
      roots: ['app'],
      extensions: ['.php'],
      max_lines: 2,
    });
    assert.equal(result.failures[0].reason, 'unbudgeted_hotspot');
    assert.equal(result.failures[0].path, 'app/unreviewed.php');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('default discovery rejects an unbudgeted 4800-line app service', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hotspot-default-discovery-'));
  try {
    fs.mkdirSync(path.join(root, 'app', 'service'), { recursive: true });
    fs.writeFileSync(
      path.join(root, 'app', 'service', 'UnreviewedLargeService.php'),
      'line\n'.repeat(4_800),
      'utf8',
    );
    const result = inspectSourceHotspotBudget(root, []);
    assert.deepEqual(result.failures, [{
      path: 'app/service/UnreviewedLargeService.php',
      reason: 'unbudgeted_hotspot',
      actual_lines: 4_800,
      discovery_max_lines: 4_000,
    }]);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('every hotspot parent and extracted concern has a committed budget', () => {
  const budgetPaths = new Set(SOURCE_HOTSPOT_BUDGETS.map((item) => item.path));
  for (const [parent, concerns] of Object.entries(SOURCE_CONCERN_PATHS)) {
    assert.equal(budgetPaths.has(parent), true, `missing parent budget: ${parent}`);
    for (const concern of concerns) {
      assert.equal(budgetPaths.has(concern), true, `missing concern budget: ${concern}`);
    }
  }
});

test('one source concern registry drives Node, PHP, and the real operation trait boundary', () => {
  const registry = JSON.parse(fs.readFileSync('rules/source-concern-contract-registry.json', 'utf8'));
  assert.equal(registry.schema_version, 'suxios.source_concern_registry.v1');
  assert.deepEqual(
    Object.fromEntries(Object.entries(SOURCE_CONCERN_PATHS).map(([parent, members]) => [parent, [...members]])),
    registry.aggregates,
  );

  const operationSource = fs.readFileSync('app/service/OperationManagementService.php', 'utf8');
  const actualOperationTraits = [...operationSource.matchAll(/^\s+use \\app\\service\\operation\\([A-Za-z0-9_]+);\s*$/gmu)]
    .map((match) => `app/service/operation/${match[1]}.php`);
  assert.deepEqual(
    registry.aggregates['app/service/OperationManagementService.php'],
    actualOperationTraits,
  );

  const phpAggregate = fs.readFileSync('tests/Support/SourceAggregate.php', 'utf8');
  assert.match(phpAggregate, /source-concern-contract-registry\.json/);
  assert.doesNotMatch(phpAggregate, /match \(\$relativePath\)/);
});

test('current source hotspots stay within reviewed no-growth ratchets and expose target debt', () => {
  const result = inspectSourceHotspotBudget(process.cwd());
  assert.deepEqual(result.failures, []);
  assert.ok(result.debts.length > 0);
  assert.ok(result.debts.some((item) => item.path === 'app/service/OtaLocalCollectorService.php'));
  assert.ok(result.debts.some((item) => item.path === 'app/service/RevenueAiOverviewService.php'));
});

test('operation parent ratchet closes immediately after persistence extraction', () => {
  const budget = SOURCE_HOTSPOT_BUDGETS.find((item) => item.path === 'app/service/OperationManagementService.php');
  assert.ok(budget);
  assert.equal(
    budget.ratchet_max_lines,
    sourceLineCount(fs.readFileSync('app/service/OperationManagementService.php', 'utf8')),
  );
  assert.match(budget.boundary, /persistence concerns were extracted/);
});

test('platform-data hotspot extractions stay wired and close each prior overage', () => {
  const service = fs.readFileSync('app/service/PlatformDataSyncService.php', 'utf8');
  const registry = fs.readFileSync('app/service/PlatformDataCollectionDefinitionRegistry.php', 'utf8');
  assert.doesNotMatch(service, /private const (?:COLLECTION_RESOURCE|NORMALIZED_FIELD_FACT)_DEFINITIONS/);
  assert.match(service, /PlatformDataCollectionDefinitionRegistry::collectionResources\(\)/);
  assert.match(service, /PlatformDataCollectionDefinitionRegistry::normalizedFieldFactsFor\(\$dataType\)/);
  assert.match(registry, /private const COLLECTION_RESOURCE_DEFINITIONS = \[/);
  assert.match(registry, /private const NORMALIZED_FIELD_FACT_DEFINITIONS = \[/);

  const expectedAggregateMembers = [
    'app/service/PlatformDataCollectionDefinitionRegistry.php',
    'app/service/concern/PlatformSyncTaskReadbackConcern.php',
    'app/service/concern/PlatformDataImportParsingConcern.php',
  ];
  for (const member of expectedAggregateMembers) {
    assert.ok(SOURCE_CONCERN_PATHS['app/service/PlatformDataSyncService.php'].includes(member));
  }
  assert.ok(
    SOURCE_CONCERN_PATHS['app/controller/Agent.php']
      .includes('app/controller/concern/AgentOtaDiagnosisReadbackConcern.php'),
  );

  const extractedProcessTests = [
    'testBrowserProfileCaptureOutputPathsAreRunUniqueWithinTheSameSecond',
    'testBrowserProfileAdaptersNeverPromoteOutputFromAFailedCollectorProcess',
    'testBrowserProfileProcessDiagnosticsSuppressCredentialBearingLines',
  ];
  const integrationTest = fs.readFileSync('tests/PlatformDataSyncServiceTest.php', 'utf8');
  const processSafetyTest = fs.readFileSync(
    'tests/PlatformDataSyncBrowserProfileProcessSafetyTest.php',
    'utf8',
  );
  for (const method of extractedProcessTests) {
    assert.doesNotMatch(integrationTest, new RegExp(method));
    assert.match(processSafetyTest, new RegExp(method));
  }

  const result = inspectSourceHotspotBudget(process.cwd());
  const formerlyFailing = new Set([
    'app/service/PlatformDataSyncService.php',
    'tests/PlatformDataSyncServiceTest.php',
    'app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php',
    'app/service/concern/PlatformSyncTaskConcern.php',
    'app/service/concern/PlatformDataPersistenceConcern.php',
  ]);
  assert.deepEqual(
    result.failures.filter((item) => formerlyFailing.has(item.path)),
    [],
  );
});

test('route and verifier scripts are governed instead of living outside source discovery', () => {
  const routeBudget = SOURCE_HOTSPOT_BUDGETS.find((item) => item.path === 'route/app.php');
  assert.ok(routeBudget);
  assert.equal(
    routeBudget.ratchet_max_lines,
    sourceLineCount(fs.readFileSync('route/app.php', 'utf8')),
    'route/app.php ratchet must close immediately after a domain extraction',
  );
  assert.ok(routeBudget.ratchet_max_lines < 800);
  assert.ok(SOURCE_HOTSPOT_BUDGETS.some((item) => item.path === 'scripts/verify_e2e_contracts.mjs'));
  const result = inspectSourceHotspotBudget(process.cwd());
  assert.ok(result.discovery.roots.includes('route'));
  assert.ok(result.discovery.roots.includes('scripts'));
  assert.equal(
    result.failures.some((item) => item.path === 'route/app.php' && item.reason === 'unbudgeted_hotspot'),
    false,
  );
  assert.equal(
    result.failures.some((item) => item.path === 'scripts/verify_e2e_contracts.mjs' && item.reason === 'unbudgeted_hotspot'),
    false,
  );
});
