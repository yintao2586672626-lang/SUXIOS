import { createHash, randomBytes } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(fileURLToPath(new URL('..', import.meta.url)));
const migrationDirectory = join(projectRoot, 'database', 'migrations');
const initializationPath = join(projectRoot, 'database', 'init_full.sql');
const workerPath = join(projectRoot, 'scripts', 'mysql_execution_intent_concurrency_worker.php');
const loginWorkerPath = join(projectRoot, 'scripts', 'mysql_login_rate_limiter_concurrency_worker.php');
const migrationRuns = 2;
const workerCount = 8;
const loginWorkerCount = 16;
const mysqlCommandTimeoutMs = 120000;
const workerCompletionTimeoutMs = 60000;
const workerOutputLimitBytes = 1024 * 1024;
const dedicatedDatabasePattern = /(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/i;
const loopbackHosts = new Set(['127.0.0.1', 'localhost', '::1', '[::1]']);
if (process.env.SUXI_CI_MYSQL_VERIFY !== '1') {
  throw new Error('SUXI_CI_MYSQL_VERIFY=1 is required for this destructive dedicated-database verifier');
}

const databaseHost = (process.env.DB_HOST || '127.0.0.1').trim().toLowerCase();
if (!loopbackHosts.has(databaseHost) && process.env.SUXI_E2E_ALLOW_REMOTE_TEST_DB !== '1') {
  throw new Error('Fresh database verifier refused a non-loopback host');
}

const generatedDatabaseName = `suxi_ci_${process.pid}_${randomBytes(4).toString('hex')}_e2e`;
const databaseName = (process.env.SUXI_CI_MYSQL_DB_NAME || generatedDatabaseName).trim();
if (!dedicatedDatabasePattern.test(databaseName)) {
  throw new Error('A dedicated database name ending in _test, _testing, or _e2e is required');
}
if (!/^[a-zA-Z0-9_-]{1,64}$/.test(databaseName)) {
  throw new Error('Dedicated database name contains unsupported characters');
}

const initializationSql = readFileSync(initializationPath, 'utf8');
const declaredMigrationFiles = Array.from(
  initializationSql.matchAll(/^SOURCE\s+\.\/database\/migrations\/([^;]+);\s*$/gmi),
  match => match[1],
);
const diskMigrationFiles = readdirSync(migrationDirectory)
  .filter(name => name.toLowerCase().endsWith('.sql'))
  .sort();
const trackedMigrationResult = spawnSync('git', ['ls-files', '--', 'database/migrations/*.sql'], {
  cwd: projectRoot,
  encoding: 'utf8',
  windowsHide: true,
});
const trackedMigrationFiles = trackedMigrationResult.status === 0
  ? String(trackedMigrationResult.stdout || '')
      .split(/\r?\n/)
      .map(value => value.trim().split(/[\\/]/).at(-1) || '')
      .filter(Boolean)
  : diskMigrationFiles;
const declaredSet = new Set(declaredMigrationFiles);
const diskSet = new Set(diskMigrationFiles);
const missingDeclaredMigrations = declaredMigrationFiles.filter(name => !diskSet.has(name));
const undeclaredTrackedMigrations = trackedMigrationFiles.filter(name => !declaredSet.has(name));
if (missingDeclaredMigrations.length > 0 || undeclaredTrackedMigrations.length > 0) {
  throw new Error('database/init_full.sql migration list does not match tracked database/migrations');
}
const ignoredUntrackedMigrationFiles = diskMigrationFiles.filter(
  name => !declaredSet.has(name) && !trackedMigrationFiles.includes(name),
);
const migrationPaths = declaredMigrationFiles.map(name => join(migrationDirectory, name));

const mysqlBinary = process.env.MYSQL_BINARY || process.env.SUXI_MYSQL || 'mysql';
const phpBinary = process.env.PHP_BINARY || 'php';
const databasePort = (process.env.DB_PORT || '3306').trim();
const databaseUser = (process.env.DB_USER || 'root').trim();
const mysqlEnvironment = { ...process.env, MYSQL_PWD: process.env.DB_PASS || '' };
const connectionArguments = [
  '--protocol=tcp',
  `--host=${databaseHost}`,
  `--port=${databasePort}`,
  `--user=${databaseUser}`,
  '--default-character-set=utf8mb4',
  '--batch',
  '--skip-column-names',
];
const children = [];
let databaseCreated = false;
let barrierDirectory = '';

function runMysql({ database = '', input = '', label }) {
  const args = [...connectionArguments];
  if (database !== '') args.push(`--database=${database}`);
  const result = spawnSync(mysqlBinary, args, {
    cwd: projectRoot,
    env: mysqlEnvironment,
    input,
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
    timeout: mysqlCommandTimeoutMs,
    killSignal: 'SIGKILL',
    windowsHide: true,
  });
  if (result.error) {
    if (result.error.code === 'ETIMEDOUT') {
      throw new Error(`${label} timed out after ${mysqlCommandTimeoutMs}ms`);
    }
    throw new Error(`${label} could not start the database client: ${result.error.message}`);
  }
  if (result.status !== 0) {
    const detail = String(result.stderr || result.stdout || '').trim().slice(-4000);
    throw new Error(`${label} failed${detail ? `: ${detail}` : ''}`);
  }
  return String(result.stdout || '').trim();
}

function queryScalar(sql, label) {
  const output = runMysql({ database: databaseName, input: `${sql.trim()}\n`, label });
  const value = Number(output.split(/\s+/).filter(Boolean).at(-1));
  if (!Number.isFinite(value)) throw new Error(`${label} did not return a number`);
  return value;
}

const expectedEnergyBenchmarkKeys = ['1:1', '2:1', '3:1'];

function readEnergyBenchmarkSeedCounts(label) {
  const output = runMysql({
    database: databaseName,
    input: `SELECT CONCAT(energy_type, ':', benchmark_type), COUNT(*)
      FROM energy_benchmarks
      WHERE hotel_id = 0
        AND (energy_type, benchmark_type) IN ((1, 1), (2, 1), (3, 1))
      GROUP BY energy_type, benchmark_type
      ORDER BY energy_type, benchmark_type;\n`,
    label,
  });
  const counts = Object.fromEntries(expectedEnergyBenchmarkKeys.map(key => [key, 0]));
  for (const line of output.split(/\r?\n/).filter(Boolean)) {
    const [key, rawCount, ...unexpected] = line.split('\t');
    if (unexpected.length > 0 || !Object.hasOwn(counts, key) || !/^\d+$/.test(rawCount || '')) {
      throw new Error(`${label} returned an unexpected row: ${line}`);
    }
    counts[key] = Number(rawCount);
  }
  return counts;
}

function assertExpectedEnergyBenchmarkSeeds(counts, label) {
  const invalid = Object.entries(counts).filter(([, count]) => count !== 1);
  if (invalid.length > 0) {
    throw new Error(`${label} expected one row for each default key, got ${JSON.stringify(counts)}`);
  }
}

function spawnWorker(index, commonEnvironment) {
  const child = spawn(phpBinary, [workerPath], {
    cwd: projectRoot,
    env: { ...process.env, ...commonEnvironment, SUXI_CI_WORKER_INDEX: String(index) },
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  children.push(child);
  let stdout = '';
  let stderr = '';
  child.stdout.setEncoding('utf8');
  child.stderr.setEncoding('utf8');
  child.stdout.on('data', chunk => { stdout = `${stdout}${chunk}`.slice(-workerOutputLimitBytes); });
  child.stderr.on('data', chunk => { stderr = `${stderr}${chunk}`.slice(-workerOutputLimitBytes); });
  return new Promise((resolveWorker, rejectWorker) => {
    child.once('error', rejectWorker);
    child.once('close', code => {
      if (code !== 0) {
        rejectWorker(new Error(`concurrency worker ${index} failed: ${(stderr || stdout).trim().slice(-2000)}`));
        return;
      }
      const lines = stdout.trim().split(/\r?\n/).filter(Boolean);
      try {
        const result = JSON.parse(lines.at(-1) || '{}');
        if (!Number.isInteger(result.intent_id) || result.intent_id <= 0) {
          throw new Error('intent_id is missing');
        }
        resolveWorker(result);
      } catch (error) {
        rejectWorker(new Error(`concurrency worker ${index} returned invalid JSON: ${error.message}`));
      }
    });
  });
}

function spawnLoginWorker(index, commonEnvironment) {
  const child = spawn(phpBinary, [loginWorkerPath], {
    cwd: projectRoot,
    env: { ...process.env, ...commonEnvironment, SUXI_CI_WORKER_INDEX: String(index) },
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  children.push(child);
  let stdout = '';
  let stderr = '';
  child.stdout.setEncoding('utf8');
  child.stderr.setEncoding('utf8');
  child.stdout.on('data', chunk => { stdout = `${stdout}${chunk}`.slice(-workerOutputLimitBytes); });
  child.stderr.on('data', chunk => { stderr = `${stderr}${chunk}`.slice(-workerOutputLimitBytes); });
  return new Promise((resolveWorker, rejectWorker) => {
    child.once('error', rejectWorker);
    child.once('close', code => {
      if (code !== 0) {
        rejectWorker(new Error(`login concurrency worker ${index} failed: ${(stderr || stdout).trim().slice(-2000)}`));
        return;
      }
      const lines = stdout.trim().split(/\r?\n/).filter(Boolean);
      try {
        const result = JSON.parse(lines.at(-1) || '{}');
        if (typeof result.allowed !== 'boolean' || !Number.isInteger(result.worker)) {
          throw new Error('allowed/worker result is missing');
        }
        resolveWorker(result);
      } catch (error) {
        rejectWorker(new Error(`login concurrency worker ${index} returned invalid JSON: ${error.message}`));
      }
    });
  });
}

async function waitForReady(directory, expected) {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    const count = readdirSync(directory).filter(name => /^ready_\d+$/.test(name)).length;
    if (count === expected) return;
    await new Promise(resolveWait => setTimeout(resolveWait, 25));
  }
  throw new Error(`Only ${readdirSync(directory).filter(name => name.startsWith('ready_')).length}/${expected} workers reached the concurrency barrier`);
}

async function stopChildren() {
  const waitForClose = async (targets, timeoutMs) => {
    if (targets.length === 0) return;
    let timeoutHandle;
    const timeout = new Promise(resolveTimeout => {
      timeoutHandle = setTimeout(resolveTimeout, timeoutMs);
    });
    const closed = Promise.allSettled(targets.map(child => (
      child.exitCode === null
        ? new Promise(resolveClose => child.once('close', resolveClose))
        : Promise.resolve()
    )));
    await Promise.race([closed, timeout]);
    clearTimeout(timeoutHandle);
  };

  const active = children.filter(child => child.exitCode === null);
  for (const child of active) child.kill('SIGTERM');
  await waitForClose(active, 5000);

  const stubborn = active.filter(child => child.exitCode === null);
  for (const child of stubborn) child.kill('SIGKILL');
  await waitForClose(stubborn, 2000);
}

async function withTimeout(promise, timeoutMs, label) {
  let timeoutHandle;
  const timeout = new Promise((_, rejectTimeout) => {
    timeoutHandle = setTimeout(
      () => rejectTimeout(new Error(`${label} timed out after ${timeoutMs}ms`)),
      timeoutMs,
    );
  });
  try {
    return await Promise.race([promise, timeout]);
  } finally {
    clearTimeout(timeoutHandle);
  }
}

try {
  for (const path of [initializationPath, workerPath, loginWorkerPath, ...migrationPaths]) {
    if (!existsSync(path)) throw new Error(`Required verifier input is missing: ${path}`);
  }

  runMysql({
    input: `CREATE DATABASE \`${databaseName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n`,
    label: 'create dedicated database',
  });
  databaseCreated = true;

  const serverVersion = runMysql({
    input: 'SELECT VERSION();\n',
    label: 'database server version lookup',
  }).split(/\r?\n/).filter(Boolean).at(-1) || 'unknown';
  if (!/mariadb/i.test(serverVersion)) {
    throw new Error(`Fresh database verifier requires the project MariaDB dialect, received ${serverVersion}`);
  }

  runMysql({
    database: databaseName,
    input: readFileSync(initializationPath),
    label: 'fresh init_full.sql import',
  });

  const tableCount = queryScalar(
    `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${databaseName}' AND table_type = 'BASE TABLE';`,
    'fresh table count',
  );
  if (tableCount < 82) throw new Error(`Fresh initialization created only ${tableCount} tables; expected at least 82`);

  const hotelId = queryScalar('SELECT MIN(id) FROM hotels WHERE status = 1;', 'seed hotel lookup');
  if (hotelId <= 0) throw new Error('Fresh initialization did not create an enabled hotel');

  const energyBenchmarkSeedCountsAfterInit = readEnergyBenchmarkSeedCounts(
    'energy benchmark default seed count after fresh initialization',
  );
  assertExpectedEnergyBenchmarkSeeds(energyBenchmarkSeedCountsAfterInit, 'Fresh initialization');

  const legacyRecordId = 800000000 + Number.parseInt(randomBytes(3).toString('hex'), 16);
  runMysql({
    database: databaseName,
    input: `INSERT INTO operation_execution_intents
      (tenant_id, idempotency_key, source_module, source_record_id, hotel_id, platform, object_type, action_type, date_start, status, created_by)
      VALUES (${hotelId}, NULL, 'expansion', ${legacyRecordId}, ${hotelId}, 'investment', 'expansion', 'expansion_post_decision_tracking', '2026-07-16', 'pending_approval', 1);\n`,
    label: 'insert legacy expansion intent',
  });

  for (let run = 1; run <= migrationRuns; run += 1) {
    for (const [index, migrationPath] of migrationPaths.entries()) {
      runMysql({
        database: databaseName,
        input: readFileSync(migrationPath),
        label: `migration repeat pass ${run}/${migrationRuns} file ${index + 1}/${migrationPaths.length}`,
      });
    }
  }

  const energyBenchmarkSeedCountsAfterRepeats = readEnergyBenchmarkSeedCounts(
    'energy benchmark default seed count after repeated migrations',
  );
  assertExpectedEnergyBenchmarkSeeds(energyBenchmarkSeedCountsAfterRepeats, 'Repeated migrations');
  if (JSON.stringify(energyBenchmarkSeedCountsAfterRepeats) !== JSON.stringify(energyBenchmarkSeedCountsAfterInit)) {
    throw new Error(
      `Repeated migrations changed energy benchmark seed counts: before=${JSON.stringify(energyBenchmarkSeedCountsAfterInit)}, after=${JSON.stringify(energyBenchmarkSeedCountsAfterRepeats)}`,
    );
  }

  const idempotencyColumnCount = queryScalar(
    `SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '${databaseName}' AND table_name = 'operation_execution_intents' AND column_name = 'idempotency_key';`,
    'idempotency column count',
  );
  const uniqueIndexCount = queryScalar(
    `SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = '${databaseName}' AND table_name = 'operation_execution_intents' AND index_name = 'uniq_operation_exec_intent_idempotency' AND non_unique = 0;`,
    'idempotency unique index count',
  );
  const backfilledRows = queryScalar(
    `SELECT COUNT(*) FROM operation_execution_intents WHERE source_record_id = ${legacyRecordId} AND idempotency_key = 'expansion:v1:${legacyRecordId}';`,
    'legacy idempotency backfill count',
  );
  if (idempotencyColumnCount !== 1 || uniqueIndexCount !== 1 || backfilledRows !== 1) {
    throw new Error(`Migration contract mismatch: column=${idempotencyColumnCount}, unique_index=${uniqueIndexCount}, backfilled=${backfilledRows}`);
  }
  const paginationIndexCount = queryScalar(
    `SELECT COUNT(*) FROM (
      SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS indexed_columns
      FROM information_schema.statistics
      WHERE table_schema = '${databaseName}' AND table_name = 'online_daily_data'
        AND index_name = 'idx_online_daily_history_date_id'
      GROUP BY index_name
      HAVING indexed_columns = 'data_date,id'
    ) AS matching_index;`,
    'online data pagination index contract',
  );
  const aiTaskTableCount = queryScalar(
    `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${databaseName}'
      AND table_name IN ('ai_report_generation_tasks', 'ai_report_input_cache');`,
    'AI report task table contract',
  );
  const aiTrustColumnCount = queryScalar(
    `SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '${databaseName}' AND (
      (table_name = 'online_daily_data' AND column_name IN ('readback_verified', 'readback_verified_at'))
      OR (table_name = 'ai_daily_reports' AND column_name IN ('input_fingerprint', 'prompt_version', 'cache_hit_count'))
    );`,
    'AI report trusted input column contract',
  );
  const obsoleteRegistrationCount = queryScalar(
    "SELECT COUNT(*) FROM system_config WHERE config_key = 'enable_registration';",
    'obsolete registration setting removal',
  );
  const loginSupportCount = queryScalar(
    "SELECT COUNT(*) FROM system_config WHERE config_key = 'login_support_contact' AND CHAR_LENGTH(TRIM(config_value)) > 0;",
    'login support contact contract',
  );
  const otaKnowledgeUnitCount = queryScalar(
    `SELECT COUNT(*) FROM knowledge_units WHERE source IN (
      'ota_public_page_diagnosis_reference',
      'ota_operation_sop_reference',
      'ota_competition_pulse_reference'
    );`,
    'OTA external knowledge seed contract',
  );
  if (paginationIndexCount !== 1
    || aiTaskTableCount !== 2
    || aiTrustColumnCount !== 5
    || obsoleteRegistrationCount !== 0
    || loginSupportCount !== 1
    || otaKnowledgeUnitCount !== 3) {
    throw new Error(
      `Migration matrix postcondition mismatch: pagination_index=${paginationIndexCount}, ai_tables=${aiTaskTableCount}, ai_trust_columns=${aiTrustColumnCount}, registration=${obsoleteRegistrationCount}, login_support=${loginSupportCount}, ota_knowledge=${otaKnowledgeUnitCount}`,
    );
  }
  runMysql({
    database: databaseName,
    input: `DELETE FROM operation_execution_intents WHERE source_record_id = ${legacyRecordId};\n`,
    label: 'remove legacy migration fixture',
  });

  const sourceRecordId = 900000000 + Number.parseInt(randomBytes(3).toString('hex'), 16);
  barrierDirectory = mkdtempSync(join(tmpdir(), 'suxi-mysql-concurrency-'));
  const workerEnvironment = {
    APP_ENV: 'testing',
    APP_DEBUG: '0',
    DB_TYPE: 'mysql',
    DB_HOST: databaseHost,
    DB_PORT: databasePort,
    DB_USER: databaseUser,
    DB_PASS: process.env.DB_PASS || '',
    DB_NAME: databaseName,
    SUXI_CI_MYSQL_VERIFY: '1',
    SUXI_E2E_DB_OVERRIDE: '1',
    SUXI_E2E_DB_NAME: databaseName,
    SUXI_CI_HOTEL_ID: String(hotelId),
    SUXI_CI_SOURCE_RECORD_ID: String(sourceRecordId),
    SUXI_CI_BARRIER_DIR: barrierDirectory,
  };
  if (process.env.SUXI_E2E_ALLOW_REMOTE_TEST_DB === '1') {
    workerEnvironment.SUXI_E2E_ALLOW_REMOTE_TEST_DB = '1';
  }
  const workerPromises = Array.from({ length: workerCount }, (_, index) => spawnWorker(index + 1, workerEnvironment));
  const allWorkers = Promise.all(workerPromises);
  const workerEarlyExit = allWorkers.then(
    () => { throw new Error('Concurrency workers exited before the barrier was released'); },
    error => { throw error; },
  );
  await Promise.race([waitForReady(barrierDirectory, workerCount), workerEarlyExit]);
  writeFileSync(join(barrierDirectory, 'go'), 'go', { flag: 'wx' });
  const workerResults = await withTimeout(allWorkers, workerCompletionTimeoutMs, 'concurrency workers');

  const intentIds = workerResults.map(result => Number(result.intent_id));
  const uniqueIntentIds = new Set(intentIds);
  const databaseRows = queryScalar(
    `SELECT COUNT(*) FROM operation_execution_intents WHERE idempotency_key = 'expansion:v1:${sourceRecordId}';`,
    'concurrent database row count',
  );
  const storedIntentId = queryScalar(
    `SELECT MIN(id) FROM operation_execution_intents WHERE idempotency_key = 'expansion:v1:${sourceRecordId}';`,
    'concurrent stored intent id',
  );
  if (workerResults.length !== workerCount || uniqueIntentIds.size !== 1 || databaseRows !== 1 || !uniqueIntentIds.has(storedIntentId)) {
    throw new Error(`Concurrency contract mismatch: workers=${workerResults.length}, unique_intent_ids=${uniqueIntentIds.size}, database_rows=${databaseRows}`);
  }

  rmSync(barrierDirectory, { recursive: true, force: true });
  barrierDirectory = mkdtempSync(join(tmpdir(), 'suxi-login-rate-concurrency-'));
  const loginIp = `198.18.${randomBytes(1)[0]}.${randomBytes(1)[0]}`;
  const loginUsername = `ci-login-${randomBytes(6).toString('hex')}`;
  const loginWorkerEnvironment = {
    ...workerEnvironment,
    SUXI_CI_LOGIN_IP: loginIp,
    SUXI_CI_LOGIN_USERNAME: loginUsername,
    SUXI_CI_BARRIER_DIR: barrierDirectory,
  };
  const loginWorkerPromises = Array.from(
    { length: loginWorkerCount },
    (_, index) => spawnLoginWorker(index + 1, loginWorkerEnvironment),
  );
  const allLoginWorkers = Promise.all(loginWorkerPromises);
  const loginWorkerEarlyExit = allLoginWorkers.then(
    () => { throw new Error('Login concurrency workers exited before the barrier was released'); },
    error => { throw error; },
  );
  await Promise.race([waitForReady(barrierDirectory, loginWorkerCount), loginWorkerEarlyExit]);
  writeFileSync(join(barrierDirectory, 'go'), 'go', { flag: 'wx' });
  const loginWorkerResults = await withTimeout(
    allLoginWorkers,
    workerCompletionTimeoutMs,
    'login concurrency workers',
  );
  const loginAllowed = loginWorkerResults.filter(result => result.allowed === true).length;
  const loginDenied = loginWorkerResults.length - loginAllowed;
  const normalizedUsername = loginUsername.trim().toLowerCase();
  const subjectHashes = {
    ip: createHash('sha256').update(loginIp.trim()).digest('hex'),
    username: createHash('sha256').update(normalizedUsername).digest('hex'),
    identity: createHash('sha256').update(`${loginIp.trim()}\0${normalizedUsername}`).digest('hex'),
  };
  const loginCounts = Object.fromEntries(Object.entries(subjectHashes).map(([scope, subjectHash]) => [
    scope,
    queryScalar(
      `SELECT COALESCE(MAX(attempt_count), 0) FROM login_rate_limit_counters WHERE scope_type = '${scope}' AND subject_hash = '${subjectHash}';`,
      `login ${scope} concurrent count`,
    ),
  ]));
  if (loginWorkerResults.length !== loginWorkerCount
      || loginAllowed !== 10
      || loginDenied !== loginWorkerCount - 10
      || Object.values(loginCounts).some(count => count !== 10)
  ) {
    throw new Error(`Login limiter concurrency mismatch: allowed=${loginAllowed}, denied=${loginDenied}, counts=${JSON.stringify(loginCounts)}`);
  }

  runMysql({
    database: databaseName,
    input: 'DROP TABLE login_rate_limit_counters;\n',
    label: 'drop login limiter table for fail-closed proof',
  });
  const missingTableProbe = spawnSync(phpBinary, [loginWorkerPath], {
    cwd: projectRoot,
    env: {
      ...process.env,
      ...loginWorkerEnvironment,
      SUXI_CI_WORKER_INDEX: '999',
      SUXI_CI_SKIP_BARRIER: '1',
    },
    encoding: 'utf8',
    timeout: workerCompletionTimeoutMs,
    windowsHide: true,
  });
  const missingTableOutput = `${missingTableProbe.stdout || ''}\n${missingTableProbe.stderr || ''}`;
  if (missingTableProbe.status === 0 || !/login_rate_limit_counters/i.test(missingTableOutput)) {
    throw new Error(`Missing login limiter table did not fail closed: ${missingTableOutput.trim().slice(-2000)}`);
  }

  process.stdout.write(`${JSON.stringify({
    ok: true,
    engine: `${serverVersion} / MariaDB project dialect`,
    database: databaseName,
    fresh_tables: tableCount,
    migration_files: migrationPaths.length,
    ignored_untracked_migration_files: ignoredUntrackedMigrationFiles.length,
    migration_runs: migrationRuns,
    migration_backfilled_rows: backfilledRows,
    energy_benchmark_seed_counts: energyBenchmarkSeedCountsAfterRepeats,
    energy_benchmark_seed_stable: true,
    workers: workerResults.length,
    unique_intent_ids: uniqueIntentIds.size,
    database_rows: databaseRows,
    intent_id: storedIntentId,
    login_workers: loginWorkerResults.length,
    login_allowed: loginAllowed,
    login_denied: loginDenied,
    login_counts: loginCounts,
    login_missing_table_fail_closed: true,
  })}\n`);
} finally {
  await stopChildren();
  if (barrierDirectory !== '') rmSync(barrierDirectory, { recursive: true, force: true });
  if (databaseCreated) {
    runMysql({ input: `DROP DATABASE IF EXISTS \`${databaseName}\`;\n`, label: 'drop dedicated database' });
  }
}
