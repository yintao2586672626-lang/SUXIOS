import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');

test('CI provisions the project MariaDB dialect and runs the host-client fresh database concurrency gate', () => {
  const workflow = read('.github/workflows/php.yml');
  const packageJson = JSON.parse(read('package.json'));

  assert.match(workflow, /services:\s+mysql:/);
  assert.match(workflow, /image:\s+mariadb:10\.11/);
  assert.match(workflow, /sudo apt-get install -y mariadb-client/);
  assert.match(workflow, /MYSQL_BINARY:\s*mariadb/);
  assert.doesNotMatch(workflow, /verify-oracle-mysql:/);
  assert.doesNotMatch(workflow, /image:\s+mysql:8\.4/);
  assert.doesNotMatch(workflow, /MYSQL_DOCKER_CONTAINER_ID/);
  assert.match(workflow, /SUXI_CI_MYSQL_VERIFY:\s*['"]1['"]/);
  assert.match(workflow, /npm run verify:mysql-fresh-concurrency/);
  assert.match(workflow, /verify:\s+runs-on:\s+ubuntu-latest\s+timeout-minutes:\s+45/);
  assert.match(workflow, /Verify fresh database, repeatable migration, and 8-way concurrency\s+timeout-minutes:\s+10/);
  assert.equal(
    packageJson.scripts['verify:mysql-fresh-concurrency'],
    'node scripts/verify_mysql_fresh_migration_concurrency.mjs',
  );
});

test('fresh database verifier is gated, repeats the migration, and launches exactly eight workers', () => {
  const verifier = read('scripts/verify_mysql_fresh_migration_concurrency.mjs');
  const worker = read('scripts/mysql_execution_intent_concurrency_worker.php');
  const loginWorker = read('scripts/mysql_login_rate_limiter_concurrency_worker.php');
  const databaseConfig = read('config/database.php');
  const initialization = read('database/init_full.sql');
  const baselineMigrations = new Set(Array.from(
    initialization.matchAll(/^SOURCE\s+\.\/database\/migrations\/([^;]+);\s*$/gmi),
    match => match[1],
  ));
  const catalogMigrations = readdirSync('database/migrations')
    .filter(name => name.endsWith('.sql'))
    .sort();
  const pendingAfterFrozenBaseline = catalogMigrations.filter(name => !baselineMigrations.has(name));

  assert.match(verifier, /SUXI_CI_MYSQL_VERIFY/);
  assert.match(verifier, /dedicated .+_(?:test|testing|e2e)/i);
  assert.match(initialization, /SOURCE \.\/database\/migrations\/20260716_add_execution_intent_idempotency_key\.sql;/);
  assert.ok(pendingAfterFrozenBaseline.includes('20260722_add_hotels_city.sql'));
  assert.ok(pendingAfterFrozenBaseline.includes('20260722_create_schema_versions.sql'));
  assert.ok(pendingAfterFrozenBaseline.includes('20260722_create_tenants_and_decouple_hotel_scope.sql'));
  assert.ok(pendingAfterFrozenBaseline.includes('20260722_harden_schema_version_governance.sql'));
  assert.ok(pendingAfterFrozenBaseline.includes('20260722_track_frozen_baseline_sources.sql'));
  assert.ok(pendingAfterFrozenBaseline.length >= 5);
  assert.doesNotMatch(initialization, /20260722_(?:add_hotels_city|create_schema_versions|create_tenants_and_decouple_hotel_scope|harden_schema_version_governance|track_frozen_baseline_sources)\.sql/);
  assert.doesNotMatch(verifier, /undeclaredTrackedMigrations/);
  assert.doesNotMatch(verifier, /migration list does not match tracked database\/migrations/);
  assert.match(verifier, /const migrationPaths = diskMigrationFiles\.map/);
  assert.match(verifier, /catalogPendingMigrationFiles/);
  assert.match(verifier, /freshInitializerPath = join\(projectRoot, 'scripts', 'init_database\.php'\)/);
  assert.match(verifier, /recoveryVerifierPath = join\(projectRoot, 'scripts', 'verify_mysql_fresh_initializer_recovery\.php'\)/);
  assert.match(verifier, /runFreshInitializer\(\)/);
  assert.match(verifier, /runFreshInitializerRecoveryVerifier\(\)/);
  assert.match(verifier, /failed_database_removed/);
  assert.match(verifier, /temporary_databases_remaining/);
  assert.match(verifier, /runSchemaVersionService\('SchemaVersionService no-op after fresh initialization'\)/);
  assert.match(verifier, /runSchemaVersionService\('SchemaVersionService no-op after repeated migrations'\)/);
  assert.match(verifier, /assertSchemaVersionCatalog\('Fresh initialization'\)/);
  assert.match(verifier, /assertSchemaVersionCatalog\('Repeated migrations'\)/);
  assert.match(verifier, /assertBaselineSourceCatalog\('Fresh initialization'\)/);
  assert.match(verifier, /assertBaselineSourceCatalog\('Repeated migrations'\)/);
  assert.match(verifier, /execution_kind FROM schema_versions/);
  assert.match(verifier, /freshInitBaselineAdoptedMigrationFiles/);
  assert.match(verifier, /20260723_validate_owner_tenant_bootstrap_targets\.sql/);
  assert.match(verifier, /stored\.executionKind !== evidence\.executionKind/);
  assert.match(verifier, /const repeatableMigrationPaths = migrationPaths\.filter/);
  assert.match(verifier, /freshInitBaselineAdoptedMigrationFiles\.has\(basename\(migrationPath\)\)/);
  assert.match(verifier, /schema_baseline_sources/);
  assert.match(verifier, /unresolved migration failures/);
  assert.match(verifier, /assertGovernedCompatibilityColumns\('Fresh initialization'\)/);
  assert.match(verifier, /assertGovernedCompatibilityColumns\('Repeated migrations'\)/);
  assert.match(verifier, /SELECT `city` FROM `hotels`/);
  assert.match(verifier, /SELECT `login_count` FROM `users`/);
  assert.match(verifier, /governed_column_queries_ok:\s*true/);
  assert.match(verifier, /schema_versions_registered:\s*schemaVersionsAfterRepeats/);
  assert.match(verifier, /schema_versions_required:\s*diskMigrationFiles\.length/);
  assert.match(verifier, /baseline_sources_required:\s*declaredBaselineSources\.length/);
  assert.match(verifier, /baseline_source_checksums_verified:\s*true/);
  assert.match(verifier, /for \(const \[index, migrationPath\] of repeatableMigrationPaths\.entries\(\)\)/);
  assert.match(verifier, /migrationRuns\s*=\s*2/);
  assert.match(verifier, /workerCount\s*=\s*8/);
  assert.match(verifier, /loginWorkerCount\s*=\s*16/);
  assert.match(verifier, /unique_intent_ids/);
  assert.match(verifier, /database_rows/);
  assert.match(verifier, /migration_files:\s*migrationPaths\.length/);
  assert.match(verifier, /idx_online_daily_history_date_id/);
  assert.match(verifier, /ai_report_generation_tasks/);
  assert.match(verifier, /login_support_contact/);
  assert.match(verifier, /ota_competition_pulse_reference/);
  assert.match(verifier, /const allWorkers = Promise\.all\(workerPromises\)/);
  assert.match(verifier, /Promise\.race\(\[waitForReady\(barrierDirectory, workerCount\), workerEarlyExit\]\)/);
  assert.match(verifier, /timeout:\s*mysqlCommandTimeoutMs/);
  assert.match(verifier, /withTimeout\(allWorkers, workerCompletionTimeoutMs, 'concurrency workers'\)/);
  assert.match(verifier, /child\.kill\('SIGTERM'\)/);
  assert.match(verifier, /child\.kill\('SIGKILL'\)/);
  assert.match(verifier, /await stopChildren\(\)/);
  assert.doesNotMatch(verifier, /MYSQL_DOCKER_CONTAINER_ID/);
  assert.doesNotMatch(verifier, /mysqlSpawnPrefix/);
  assert.match(verifier, /requires the project MariaDB dialect/);
  assert.doesNotMatch(verifier, /label:\s*'fresh init_full\.sql import'/);
  assert.match(verifier, /energyBenchmarkSeedCountsAfterInit/);
  assert.match(verifier, /energyBenchmarkSeedCountsAfterRepeats/);
  assert.match(verifier, /expectedEnergyBenchmarkKeys\s*=\s*\['1:1', '2:1', '3:1'\]/);
  assert.match(verifier, /JSON\.stringify\(energyBenchmarkSeedCountsAfterRepeats\) !== JSON\.stringify\(energyBenchmarkSeedCountsAfterInit\)/);
  assert.match(verifier, /energy_benchmark_seed_stable:\s*true/);
  assert.match(verifier, /login_allowed:\s*loginAllowed/);
  assert.match(verifier, /login_missing_table_fail_closed:\s*true/);
  assert.match(databaseConfig, /\$databaseConfigValue = static function/);
  assert.match(databaseConfig, /if \(\$e2eDatabaseOverride\)[\s\S]*getenv\(\$name\)/);
  assert.match(databaseConfig, /'password'\s*=>\s*\$databaseConfigValue\('DB_PASS', ''\)/);

  assert.match(worker, /SUXI_E2E_DB_OVERRIDE/);
  assert.match(worker, /createExecutionIntent\(/);
  assert.match(worker, /trustedExpansionSource:\s*true/);
  assert.match(loginWorker, /SUXI_E2E_DB_OVERRIDE/);
  assert.match(loginWorker, /new LoginRateLimiter\(\)/);
  assert.match(loginWorker, /consumeAttempt\(\$ip, \$username\)/);
});

test('fresh database verifier rejects missing gates, unsafe names, and remote hosts before starting a client', () => {
  const run = env => spawnSync(process.execPath, ['scripts/verify_mysql_fresh_migration_concurrency.mjs'], {
    cwd: process.cwd(),
    env: { ...process.env, ...env },
    encoding: 'utf8',
    windowsHide: true,
  });
  const missingGate = run({
    SUXI_CI_MYSQL_VERIFY: '',
    SUXI_CI_MYSQL_DB_NAME: 'suxi_contract_e2e',
  });
  assert.notEqual(missingGate.status, 0);
  assert.match(`${missingGate.stdout}\n${missingGate.stderr}`, /SUXI_CI_MYSQL_VERIFY=1 is required/);

  const unsafeName = run({
    SUXI_CI_MYSQL_VERIFY: '1',
    SUXI_CI_MYSQL_DB_NAME: 'hotelx',
    DB_HOST: '127.0.0.1',
  });
  assert.notEqual(unsafeName.status, 0);
  assert.match(`${unsafeName.stdout}\n${unsafeName.stderr}`, /dedicated database name/i);

  const remoteHost = run({
    SUXI_CI_MYSQL_VERIFY: '1',
    SUXI_CI_MYSQL_DB_NAME: 'suxi_contract_e2e',
    DB_HOST: '192.0.2.10',
    SUXI_E2E_ALLOW_REMOTE_TEST_DB: '',
  });
  assert.notEqual(remoteHost.status, 0);
  assert.match(`${remoteHost.stdout}\n${remoteHost.stderr}`, /refused a non-loopback host/i);
});
