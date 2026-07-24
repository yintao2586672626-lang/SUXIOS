import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const auditDate = '2026-07-15';
const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDir, '..');
const qaDir = path.join(projectRoot, 'docs', 'qa');
const matrixPath = path.join(qaDir, `hotel_system_4000_diagnostic_cases_${auditDate}.jsonl`);
const ledgerPath = path.join(qaDir, `hotel_system_4000_execution_evidence_${auditDate}.json`);
const phpunitPath = path.join(projectRoot, 'vendor', 'bin', 'phpunit');
const maxBuffer = 16 * 1024 * 1024;

const batchDefinitions = {
  first: {
    reportStem: 'hotel_system_4000_first_l8_batch_execution',
    groups: [
      {
        start: 1153,
        end: 1160,
        baseCaseId: 'TC-145',
        testFile: 'tests/PlatformDataSyncPreflightL8Test.php',
        testMethod: 'testTc145L8PreflightAndSyncBoundaries',
        datasetName: namedFactorDataset,
      },
      {
        start: 1249,
        end: 1256,
        baseCaseId: 'TC-157',
        testFile: 'tests/OtaCollectionQualityStateL8Test.php',
        testMethod: 'testTc157L8VariantAppliesAllFourFactorsWithoutClaimingHttpAuthorization',
        datasetName: namedFactorDataset,
      },
      {
        start: 1689,
        end: 1696,
        baseCaseId: 'TC-212',
        testFile: 'tests/Tc212OtaChannelScopeL8Test.php',
        testMethod: 'testTc212KeepsRevenueMetricsInsideTheOtaChannelBoundary',
        datasetName: (testCase) => testCase.id,
      },
    ],
  },
  second: {
    reportStem: 'hotel_system_4000_second_l8_batch_execution',
    groups: [
      {
        start: 2137,
        end: 2144,
        baseCaseId: 'TC-268',
        testFile: 'tests/Tc268RevenueAiEvidenceScopeL8Test.php',
        testMethod: 'testTc268KeepsRevenueAiEvidenceInsideTheOtaChannelBoundary',
        datasetName: (testCase) => testCase.id,
      },
      {
        start: 2201,
        end: 2208,
        baseCaseId: 'TC-276',
        testFile: 'tests/Tc276DiagnosisToOperationIntentL8Test.php',
        testMethod: 'testTc276BuildsTruthfulOperationIntentFromDiagnosis',
        datasetName: namedFactorDataset,
      },
      {
        start: 2513,
        end: 2520,
        baseCaseId: 'TC-315',
        testFile: 'tests/Tc315InvestmentFeasibilityL8Test.php',
        testMethod: 'testTc315RequiresAuthorizedCompleteFreshSuccessfulEvidenceWithoutClaimingHttpAcl',
        datasetName: namedFactorDataset,
      },
    ],
  },
  third: {
    reportStem: 'hotel_system_4000_third_l8_batch_execution',
    groups: [
      {
        start: 2257,
        end: 2264,
        baseCaseId: 'TC-283',
        testFile: 'tests/Tc283OperationApprovalL8Test.php',
        testMethod: 'testTc283OperationApprovalRequiresAllFourGuards',
        datasetName: namedFactorDataset,
      },
      {
        start: 2297,
        end: 2304,
        baseCaseId: 'TC-288',
        testFile: 'tests/Tc288OperationTaskStateMachineL8Test.php',
        testMethod: 'testTc288OnlyAllowsCurrentEvidenceBackedTransitionsAndNeverRollsBackTerminalState',
        datasetName: namedFactorDataset,
      },
      {
        start: 2361,
        end: 2368,
        baseCaseId: 'TC-296',
        testFile: 'tests/Tc296ExecutionIdempotencyL8Test.php',
        testMethod: 'testTc296ExecutionSideEffectsAreIdempotent',
        datasetName: namedFactorDataset,
      },
    ],
  },
  fourth: {
    reportStem: 'hotel_system_4000_fourth_l8_batch_execution',
    groups: [
      {
        start: 97,
        end: 104,
        baseCaseId: 'TC-013',
        testFile: 'tests/Tc013PasswordChangeSessionRevocationL8Test.php',
        testMethod: 'testTc013SuccessfulPasswordChangeRevokesEveryPreChangeSession',
        datasetName: namedFactorDataset,
      },
      {
        start: 2153,
        end: 2160,
        baseCaseId: 'TC-270',
        testFile: 'tests/Tc270LlmPiiMinimizationL8Test.php',
        testMethod: 'testTc270MinimizesPiiBeforeLlmMessages',
        datasetName: namedFactorDataset,
      },
      {
        start: 2329,
        end: 2336,
        baseCaseId: 'TC-292',
        testFile: 'tests/Tc292FailedActionRollbackL8Test.php',
        testMethod: 'testTc292FailedActionRequiresCurrentCompleteTraceableCompensationReceipt',
        datasetName: namedFactorDataset,
      },
    ],
  },
  fifth: {
    reportStem: 'hotel_system_4000_fifth_l8_batch_execution',
    groups: [
      {
        start: 1593,
        end: 1600,
        baseCaseId: 'TC-200',
        testFile: 'tests/Tc200ImportAtomicityL8Test.php',
        testMethod: 'testTc200L8ImportAtomicityAndRecovery',
        datasetName: namedFactorDataset,
      },
      {
        start: 1905,
        end: 1912,
        baseCaseId: 'TC-239',
        testFile: 'tests/Tc239ReviewPiiSanitizationL8Test.php',
        testMethod: 'testTc239ReviewPiiSanitizationAndQualityGuards',
        datasetName: namedFactorDataset,
      },
      {
        start: 2449,
        end: 2456,
        baseCaseId: 'TC-307',
        testFile: 'tests/Tc307CashflowSeriesL8Test.php',
        testMethod: 'testTc307PersistsAuditablePeriodicCashflowSeries',
        datasetName: namedFactorDataset,
      },
    ],
  },
  seventh: {
    reportStem: 'hotel_system_4000_seventh_l8_execution',
    groups: [
      {
        start: 825,
        end: 832,
        baseCaseId: 'TC-104',
        testFile: 'evals/seventh-batch/Tc104CredentialCiphertextTamperL8EvalTest.php',
        filterForCase: (testCase) => testCase.id,
      },
      {
        start: 3089,
        end: 3096,
        baseCaseId: 'TC-387',
        testFile: 'evals/seventh-batch/Tc387CorsOriginRestrictionL8EvalTest.php',
        filterForCase: (testCase) => `testDx${Number(testCase.id.replace(/^DX-/u, ''))}`,
      },
      {
        start: 3225,
        end: 3232,
        baseCaseId: 'TC-404',
        testFile: 'evals/seventh-batch/Tc404ExternalDependencyTimeoutL8EvalTest.php',
        filterForCase: (testCase) => `testDx${Number(testCase.id.replace(/^DX-/u, ''))}`,
      },
    ],
  },
};

const batchArgument = process.argv.find((argument) => argument.startsWith('--batch='));
const batchName = batchArgument ? batchArgument.slice('--batch='.length).trim() : 'first';
const batchDefinition = batchDefinitions[batchName];
if (!batchDefinition) {
  throw new Error(`Unknown diagnostic L8 batch: ${batchName}. Expected one of: ${Object.keys(batchDefinitions).join(', ')}`);
}
const groups = batchDefinition.groups;
const batchPath = path.join(qaDir, `${batchDefinition.reportStem}_${auditDate}.json`);
const runner = `node:scripts/run_hotel_diagnostic_l8_batch.mjs/phpunit-per-case/batch=${batchName}`;
const evidenceRef = `docs/qa/${batchDefinition.reportStem}_${auditDate}.json`;

function caseId(number) {
  return `DX-${String(number).padStart(4, '0')}`;
}

function namedFactorDataset(testCase) {
  const factors = testCase.factors ?? {};
  const completeness = factors.data_completeness === 'missing_required'
    ? 'missing'
    : factors.data_completeness;
  return [
    testCase.id,
    factors.actor_scope,
    completeness,
    factors.freshness,
    factors.upstream_state,
  ].join(' ');
}

function readMatrix() {
  if (!existsSync(matrixPath)) {
    throw new Error(`Diagnostic matrix is missing: ${path.relative(projectRoot, matrixPath)}`);
  }

  const byId = new Map();
  const lines = readFileSync(matrixPath, 'utf8').split(/\r?\n/);
  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index].trim();
    if (!line) continue;

    let testCase;
    try {
      testCase = JSON.parse(line);
    } catch (error) {
      throw new Error(`Invalid matrix JSONL at line ${index + 1}: ${error.message}`);
    }

    const id = String(testCase?.id ?? '').trim();
    if (!id) throw new Error(`Matrix line ${index + 1} has no case id`);
    if (byId.has(id)) throw new Error(`Matrix contains duplicate case id: ${id}`);
    byId.set(id, testCase);
  }
  return byId;
}

function selectedCases(matrixById) {
  const selected = [];
  const signatures = new Set();

  for (const group of groups) {
    const absoluteTestFile = path.join(projectRoot, group.testFile);
    if (!existsSync(absoluteTestFile)) {
      throw new Error(`Required L8 test file is not ready: ${group.testFile}`);
    }

    for (let number = group.start; number <= group.end; number += 1) {
      const id = caseId(number);
      const testCase = matrixById.get(id);
      if (!testCase) throw new Error(`Matrix case is missing: ${id}`);
      if (testCase.base_case_id !== group.baseCaseId) {
        throw new Error(`${id} expected ${group.baseCaseId}, received ${testCase.base_case_id ?? '(missing)'}`);
      }

      const signature = String(testCase.scenario_signature ?? '').trim();
      if (!/^sha256:[a-f0-9]{64}$/u.test(signature)) {
        throw new Error(`${id} has an invalid scenario_signature`);
      }
      if (signatures.has(signature)) {
        throw new Error(`Selected cases contain duplicate scenario_signature: ${signature}`);
      }
      signatures.add(signature);

      const filter = typeof group.filterForCase === 'function'
        ? group.filterForCase(testCase)
        : `${group.testMethod}@${group.datasetName(testCase)}`;
      selected.push({
        id,
        baseCaseId: group.baseCaseId,
        scenarioSignature: signature,
        factors: testCase.factors,
        testFile: group.testFile,
        filter,
      });
    }
  }

  const expectedCount = groups.reduce((total, group) => total + group.end - group.start + 1, 0);
  if (selected.length !== expectedCount) {
    throw new Error(`Expected ${expectedCount} selected L8 cases, received ${selected.length}`);
  }
  return selected;
}

function resolvePhpBinary() {
  const configured = String(process.env.SUXI_PHP_BIN ?? '').trim();
  if (configured) return configured;

  const xamppPhp = 'C:\\xampp\\php\\php.exe';
  if (process.platform === 'win32' && existsSync(xamppPhp)) return xamppPhp;
  return 'php';
}

function parsePhpunitSummary(stdout, stderr) {
  const output = `${stdout}\n${stderr}`;
  const detailed = [...output.matchAll(/Tests:\s*(\d+),\s*Assertions:\s*(\d+)([^\r\n]*)/gu)].at(-1);
  if (detailed) {
    const tail = detailed[3] ?? '';
    return {
      parsed: true,
      tests: Number(detailed[1]),
      assertions: Number(detailed[2]),
      failures: parseNamedCount(tail, 'Failures'),
      errors: parseNamedCount(tail, 'Errors'),
      skipped: parseNamedCount(tail, 'Skipped'),
      incomplete: parseNamedCount(tail, 'Incomplete'),
      risky: parseNamedCount(tail, 'Risky'),
      warnings: parseNamedCount(tail, 'Warnings'),
    };
  }

  const ok = [...output.matchAll(/OK \((\d+) tests?,\s*(\d+) assertions?\)/gu)].at(-1);
  if (ok) {
    return {
      parsed: true,
      tests: Number(ok[1]),
      assertions: Number(ok[2]),
      failures: 0,
      errors: 0,
      skipped: 0,
      incomplete: 0,
      risky: 0,
      warnings: 0,
    };
  }

  return {
    parsed: false,
    tests: null,
    assertions: null,
    failures: null,
    errors: null,
    skipped: null,
    incomplete: null,
    risky: null,
    warnings: null,
  };
}

function parseNamedCount(text, name) {
  const match = text.match(new RegExp(`${name}:\\s*(\\d+)`, 'u'));
  return match ? Number(match[1]) : 0;
}

function classifyExecution(spawnError, exitCode, summary) {
  if (spawnError) {
    return { status: 'blocked', reason: `phpunit_start_failed:${spawnError.code ?? spawnError.name}` };
  }
  if (!summary.parsed) {
    return { status: 'blocked', reason: 'phpunit_summary_parse_failed' };
  }
  if (summary.tests !== 1) {
    return { status: 'blocked', reason: `phpunit_expected_exactly_one_test_received_${summary.tests}` };
  }
  if ((summary.failures ?? 0) > 0) {
    return { status: 'fail', reason: `phpunit_assertion_failures:${summary.failures}` };
  }
  for (const issue of ['errors', 'skipped', 'incomplete', 'risky', 'warnings']) {
    if ((summary[issue] ?? 0) > 0) {
      return { status: 'blocked', reason: `phpunit_${issue}:${summary[issue]}` };
    }
  }
  if (exitCode !== 0) {
    return {
      status: 'blocked',
      reason: `phpunit_nonzero_exit_without_assertion_failure:${exitCode ?? 'null'}`,
    };
  }
  return { status: 'partial', reason: 'phpunit_exactly_one_test_passed_automated_scope_only' };
}

function displayCommand(executable, args) {
  return [executable, ...args].map((part) => {
    const value = String(part);
    return /^[A-Za-z0-9_./:\\=-]+$/u.test(value)
      ? value
      : `"${value.replaceAll('"', '\\"')}"`;
  }).join(' ');
}

function normalizeLocalExecutionText(value) {
  let normalized = String(value ?? '');
  const rootVariants = [projectRoot, projectRoot.replaceAll('\\', '/')];
  for (const rootVariant of rootVariants) {
    normalized = normalized.replaceAll(rootVariant, '<project>');
  }

  return normalized.replaceAll('<project>\\', '<project>/');
}

function executeCase(testCase, phpBinary) {
  const args = [
    path.relative(projectRoot, phpunitPath).replaceAll('\\', '/'),
    '--colors=never',
    '--filter',
    testCase.filter,
    testCase.testFile.replaceAll('\\', '/'),
  ];
  const startedAt = new Date().toISOString();
  const result = spawnSync(phpBinary, args, {
    cwd: projectRoot,
    encoding: 'utf8',
    maxBuffer,
    windowsHide: true,
    shell: false,
  });
  const finishedAt = new Date().toISOString();
  const stdout = normalizeLocalExecutionText(result.stdout);
  const stderr = normalizeLocalExecutionText(result.stderr);
  const summary = parsePhpunitSummary(stdout, stderr);
  const classification = classifyExecution(result.error, result.status, summary);
  const portableExecutable = path.basename(phpBinary) || 'php';

  return {
    case_id: testCase.id,
    base_case_id: testCase.baseCaseId,
    scenario_signature: testCase.scenarioSignature,
    factors: testCase.factors,
    test_file: testCase.testFile,
    filter: testCase.filter,
    command: {
      executable: portableExecutable,
      args,
      display: displayCommand(portableExecutable, args),
    },
    started_at: startedAt,
    finished_at: finishedAt,
    exit_code: Number.isInteger(result.status) ? result.status : null,
    signal: result.signal ?? null,
    spawn_error: result.error
      ? {
          name: result.error.name,
          code: result.error.code ?? null,
          message: normalizeLocalExecutionText(result.error.message),
        }
      : null,
    phpunit: summary,
    test_count_exactly_one: summary.tests === 1,
    status: classification.status,
    classification_reason: classification.reason,
    stdout,
    stderr,
  };
}

function loadLedger() {
  if (!existsSync(ledgerPath)) {
    return { schema_version: 1, audit_date: auditDate, records: [] };
  }

  const ledger = JSON.parse(readFileSync(ledgerPath, 'utf8'));
  if (ledger?.schema_version !== 1 || ledger?.audit_date !== auditDate || !Array.isArray(ledger?.records)) {
    throw new Error('Existing execution ledger has an incompatible schema or audit date');
  }

  const ids = new Set();
  for (const record of ledger.records) {
    const id = String(record?.case_id ?? '').trim();
    if (!id) throw new Error('Existing execution ledger contains an empty case_id');
    if (ids.has(id)) throw new Error(`Existing execution ledger contains duplicate case_id: ${id}`);
    ids.add(id);
  }
  return ledger;
}

function ledgerRecord(execution) {
  const boundary = 'Automated per-case PHPUnit evidence only; complete HTTP, database persistence/readback, UI, and real OTA collection evidence were not executed.';
  const notes = execution.status === 'partial'
    ? `${boundary} A successful automated result remains partial and must not be promoted to pass.`
    : `${boundary} ${execution.classification_reason}.`;
  const record = {
    case_id: execution.case_id,
    scenario_signature: execution.scenario_signature,
    status: execution.status,
    executed_at: execution.finished_at,
    runner,
    evidence_ref: `${evidenceRef}#case=${execution.case_id}`,
    assertions: [
      `phpunit_filter=${execution.filter}`,
      `phpunit_tests=${execution.phpunit.tests ?? 'unparsed'}`,
      `phpunit_assertions=${execution.phpunit.assertions ?? 'unparsed'}`,
      `http_db_ui_real_ota_coverage=not_executed`,
    ],
    output_summary: {
      parsed: execution.phpunit.parsed,
      tests: execution.phpunit.tests,
      assertions: execution.phpunit.assertions,
      failures: execution.phpunit.failures,
      errors: execution.phpunit.errors,
      skipped: execution.phpunit.skipped,
      incomplete: execution.phpunit.incomplete,
      risky: execution.phpunit.risky,
      warnings: execution.phpunit.warnings,
      classification_reason: execution.classification_reason,
    },
    notes,
  };
  if (Number.isInteger(execution.exit_code)) record.exit_code = execution.exit_code;
  return record;
}

function recordOrder(left, right) {
  return Number(String(left.case_id).replace(/^DX-/u, ''))
    - Number(String(right.case_id).replace(/^DX-/u, ''));
}

function writeJson(filePath, value) {
  writeFileSync(filePath, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function main() {
  const matrixById = readMatrix();
  const cases = selectedCases(matrixById);
  if (!existsSync(phpunitPath)) throw new Error(`PHPUnit entry is missing: ${path.relative(projectRoot, phpunitPath)}`);

  const phpBinary = resolvePhpBinary();
  const executions = cases.map((testCase) => executeCase(testCase, phpBinary));
  const statusCounts = executions.reduce((counts, execution) => {
    counts[execution.status] = (counts[execution.status] ?? 0) + 1;
    return counts;
  }, {});

  const batchReport = {
    schema_version: 1,
    audit_date: auditDate,
    batch: batchName,
    generated_at: new Date().toISOString(),
    runner,
    source_matrix: path.relative(projectRoot, matrixPath).replaceAll('\\', '/'),
    execution_ledger: path.relative(projectRoot, ledgerPath).replaceAll('\\', '/'),
    execution_boundary: {
      automated_scope: 'Each case is independently executed with one PHPUnit --filter invocation and must parse as exactly one test.',
      status_policy: 'Successful automated cases are partial; assertion failures are fail; process, zero/multiple tests, errors, skips, incomplete tests, risky tests, warnings, and parse failures are blocked.',
      not_covered: ['complete HTTP flow', 'database persistence/readback', 'UI flow', 'real OTA collection'],
      no_suite_propagation: true,
    },
    totals: {
      requested_cases: cases.length,
      independently_invoked_cases: executions.length,
      exactly_one_test: executions.filter((execution) => execution.test_count_exactly_one).length,
      statuses: statusCounts,
    },
    cases: executions,
  };
  writeJson(batchPath, batchReport);

  const ledger = loadLedger();
  const executedIds = new Set(executions.map((execution) => execution.case_id));
  const mergedRecords = ledger.records
    .filter((record) => !executedIds.has(String(record.case_id)))
    .concat(executions.map(ledgerRecord))
    .sort(recordOrder);
  writeJson(ledgerPath, {
    schema_version: 1,
    audit_date: auditDate,
    records: mergedRecords,
  });

  process.stdout.write(`${JSON.stringify({
    batch_report: path.relative(projectRoot, batchPath).replaceAll('\\', '/'),
    execution_ledger: path.relative(projectRoot, ledgerPath).replaceAll('\\', '/'),
    totals: batchReport.totals,
  }, null, 2)}\n`);

  if (executions.some((execution) => execution.status === 'fail' || execution.status === 'blocked')) {
    process.exitCode = 1;
  }
}

export { classifyExecution, normalizeLocalExecutionText, parsePhpunitSummary };

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url))) {
  main();
}
