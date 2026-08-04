import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const automationDir = path.dirname(fileURLToPath(import.meta.url));
const phpPath = path.join(automationDir, 'three-goal-schema-baseline.php');
const runnerPath = path.join(automationDir, 'run-three-goal-e2e-isolated.mjs');
const freshInitializerPath = path.resolve(automationDir, '../../app/service/FreshDatabaseInitializerService.php');
const php = readFileSync(phpPath, 'utf8');
const runner = readFileSync(runnerPath, 'utf8');
const freshInitializer = readFileSync(freshInitializerPath, 'utf8');
const combined = php + '\n' + runner;

const requiredMigrations = [
  '20260803_allow_unquantified_operation_targets.sql',
  '20260803_create_knowledge_promotion_workflow.sql',
  '20260803_create_temporal_forecast_trials.sql',
  '20260803_enforce_single_active_temporal_forecast_trial.sql',
];

test('pins the dedicated local database before configuration or connection side effects', () => {
  assert.match(combined, /hotelx_three_goals_e2e/);
  assert.match(combined, /DB_HOST must be exactly 127\.0\.0\.1/);
  assert.match(combined, /Shared hotelx and legacy hotelx_e2e/);
  assert.match(combined, /SUXI_E2E_ALLOW_SHARED_DB/);
  assert.match(combined, /SUXI_E2E_ALLOW_REMOTE_TEST_DB/);
  assert.match(combined, /SUXI_THREE_GOAL_SCHEMA_EXECUTION_LEASE/);

  const phpMainStart = php.indexOf('function threeGoalMain(array $argv): never');
  const phpMainEnd = php.indexOf('\ntry {\n    threeGoalMain($argv);', phpMainStart);
  assert.ok(phpMainStart > 0 && phpMainEnd > phpMainStart, 'PHP main entry window must be locatable');
  const phpMain = php.slice(phpMainStart, phpMainEnd);
  const phpGate = phpMain.indexOf('threeGoalOfflineSafetyGate($action)');
  const phpInit = phpMain.indexOf('threeGoalHandleInit($args, $catalog)');
  const phpConnection = phpMain.indexOf('$pdo = threeGoalDatabasePdo()');
  assert.ok(phpGate > 0, 'PHP offline safety gate must be invoked in main');
  assert.ok(phpInit > phpGate, 'PHP fresh-initializer path must follow the offline gate');
  assert.ok(phpConnection > phpGate, 'PHP PDO construction must follow the offline gate');
  const beforePhpGate = phpMain.slice(0, phpGate);
  for (const forbiddenBeforeGate of [
    'threeGoalHandleInit(',
    'threeGoalServerPdo(',
    'threeGoalDatabasePdo(',
    'threeGoalSchemaStatus(',
    'threeGoalRunFreshInitializer(',
  ]) {
    assert.ok(
      !beforePhpGate.includes(forbiddenBeforeGate),
      forbiddenBeforeGate + ' must not be reachable before the PHP offline gate',
    );
  }

  const nodeEntryStart = runner.indexOf('function schemaOnlyRun()');
  const nodeEntryEnd = runner.indexOf('\ntry {\n  schemaOnlyRun();', nodeEntryStart);
  assert.ok(nodeEntryStart > 0 && nodeEntryEnd > nodeEntryStart, 'Node entry window must be locatable');
  const nodeEntry = runner.slice(nodeEntryStart, nodeEntryEnd);
  const nodeGate = nodeEntry.indexOf('const safety = offlineSafetyGate()');
  const nodePhp = nodeEntry.indexOf('const php = phpBinary()');
  const nodeDirectory = nodeEntry.indexOf('acquireLock()');
  const nodeHelper = nodeEntry.indexOf("runHelper(php, 'guard')");
  assert.ok(nodeGate > 0, 'Node offline safety gate must be invoked in schemaOnlyRun');
  assert.ok(nodePhp > nodeGate, 'Node executable/config resolution must follow the offline gate');
  assert.ok(nodeDirectory > nodeGate, 'Node runtime side effects must follow the offline gate');
  assert.ok(nodeHelper > nodeGate, 'Node helper execution must follow the offline gate');
});

test('pins the shared 137 catalog and all four three-goal migrations', () => {
  assert.match(combined, /EXPECTED_CATALOG_COUNT = 137/);
  assert.match(php, /THREE_GOAL_EXPECTED_CATALOG_COUNT = 137/);
  for (const migration of requiredMigrations) {
    assert.ok(php.includes(migration), 'PHP helper must pin ' + migration);
    assert.ok(runner.includes(migration), 'Node runner must pin ' + migration);
  }
  assert.match(php, /checksum_mismatch/);
  assert.match(php, /unresolved_failure/);
  assert.match(php, /\['migration', 'filename', 'migration_name', 'name', 'version'\]/);
  assert.match(php, /\['status', 'state', 'execution_kind'\]/);
  assert.match(php, /array_key_exists\(\$version \. '\.sql', \$expected\)/);
  assert.match(runner, /pending/);
  assert.match(runner, /unknown/);
  assert.match(runner, /duplicates/);
});

test('uses the real fresh initializer signature and delegates orphan cleanup', () => {
  assert.match(
    freshInitializer,
    /public static function initialize\(array \$config, string \$root\): array/,
  );
  assert.match(
    php,
    /FreshDatabaseInitializerService::initialize\(\s*\$config,\s*threeGoalRepoRoot\(\)\s*\)/s,
  );
  for (const field of ['type', 'hostname', 'hostport', 'database', 'username', 'password', 'charset']) {
    assert.ok(php.includes(`'${field}' =>`), `fresh initializer config omits ${field}`);
  }
  assert.doesNotMatch(php, /ReflectionClass|threeGoalResolveCallableArgs/);
  assert.doesNotMatch(php, /\$server->exec\(\s*['"]CREATE DATABASE/i);
  assert.match(freshInitializer, /if \(!\$exists\)[^]*CREATE DATABASE/);
  assert.match(
    freshInitializer,
    /catch \(Throwable \$exception\)[^]*if \(!\$exists\)[^]*dropCreatedDatabase\(\$server, \$database\)/,
  );
  assert.match(freshInitializer, /finally \{\s*self::releaseLock\(\$server, \$lockName\);/);
  assert.match(freshInitializer, /DROP DATABASE IF EXISTS/);
  assert.match(php, /\$initializerReturned = false;/);
  assert.match(php, /\$initiallyUnownedAndAbsent = !\$exists && \$marker === null;/);
  assert.match(php, /if \(\$initiallyUnownedAndAbsent && \$initializerReturned\)/);
  assert.match(
    php,
    /\$server->exec\('DROP DATABASE IF EXISTS `hotelx_three_goals_e2e`'\);/,
  );
  assert.match(php, /if \(threeGoalDatabaseExists\(\$server\)\)/);
  assert.match(php, /if \(is_link\(\$markerPath\)\)/);
  assert.match(php, /is_file\(\$markerPath\) && !unlink\(\$markerPath\)/);
  assert.match(php, /Exact post-initializer cleanup completed/);
});

test('opens only the schema-only serial WIP and fails other goal modes closed', () => {
  assert.match(runner, /argv\.length !== 1 \|\| argv\[0\] !== '--schema-only'/);
  assert.match(runner, /Forecast, XLSX, promotion, all-goal, restore, and UI modes fail closed/);
  assert.match(runner, /schema-run\.lock/);
  assert.match(runner, /serialized_wip: 1/);
  assert.doesNotMatch(runner, /Promise\.all\s*\(/);
});

test('separates protected system baseline from legacy demo non-facts', () => {
  for (const table of [
    'roles',
    'knowledge_units',
    'knowledge_chunks',
    'tenants',
    'users',
    'hotels',
    'online_daily_data',
    'monthly_tasks',
    'operation_logs',
  ]) {
    assert.ok(php.includes("'" + table + "'"), 'baseline classification must include ' + table);
  }
  assert.match(php, /legacy_demo_non_three_goal_fact/);
  assert.match(combined, /legacy_demo_is_three_goal_fact/);
  assert.match(combined, /legacy_demo_is_three_goal_fact[^]*false/);
  assert.match(php, /Protected\/demo baseline row state drifted/);
});

test('defines after-zero only through exact run-owned IDs and foreign references', () => {
  assert.match(combined, /run_owned_exact_ids/);
  assert.match(combined, /foreign_reference_exact_ids/);
  assert.match(combined, /exact_primary_keys_and_foreign_references_only/);
  assert.match(php, /threeGoalAssertExactLedgerZero/);
  assert.match(php, /A business run may not use an empty exact-ID ledger/);
  assert.match(php, /Ledger values must be unique and bounded/);
  assert.match(runner, /schema_only_no_business_rows/);
});

test('contains no broad destructive cleanup or shared-database restore path', () => {
  assert.doesNotMatch(
    combined,
    /(?:->exec|->query|->prepare)\s*\(\s*['"][^'"]*\bTRUNCATE(?:\s+TABLE)?\s+[\x60A-Za-z_]/i,
  );
  assert.match(runner, /truncate:\s*false/);
  const dedicatedDrops = combined.match(/\bDROP\s+DATABASE\b/gi) || [];
  assert.equal(dedicatedDrops.length, 1, 'only the exact dedicated failed-init cleanup may drop a database');
  assert.doesNotMatch(runner, /\bDROP\s+DATABASE\b/i);
  assert.doesNotMatch(combined, /DROP DATABASE IF EXISTS[^\n]*\.|DROP DATABASE IF EXISTS[^\n]*\$/i);
  assert.match(combined, /DROP DATABASE IF EXISTS `hotelx_three_goals_e2e`/);
  assert.doesNotMatch(combined, /\bDELETE\s+FROM\b/i);
  assert.doesNotMatch(combined, /--restore\b/);
  assert.match(combined, /restore_requires_new_exclusive_db_lease/);
  assert.match(combined, /no in-place migration is allowed/);
});

test('keeps evidence and error output credential-safe', () => {
  assert.match(php, /\[REDACTED\]/);
  assert.match(runner, /\[REDACTED\]/);
  assert.match(combined, /DB_PASSWORD/);
  assert.match(combined, /MYSQL_PWD/);
  assert.doesNotMatch(combined, /password:\s*process\.env/);
  assert.doesNotMatch(combined, /console\.log\s*\(\s*process\.env/);
  assert.match(php, /JSON_INVALID_UTF8_SUBSTITUTE/);
});

test('denies integration and field maturity claims', () => {
  assert.match(
    combined,
    /SCHEMA_UNIT_ONLY_NOT_INTEGRATION_NOT_FIELD_VALIDATED/,
  );
  assert.match(
    runner,
    /schema_baseline_verified_not_business_integration/,
  );
  assert.match(combined, /automatic_execution/);
  assert.match(combined, /ota_write/);
  assert.match(combined, /external_message/);
});
