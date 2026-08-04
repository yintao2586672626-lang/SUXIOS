import { spawnSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import {
  closeSync,
  existsSync,
  mkdirSync,
  openSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CONTRACT_VERSION = 'suxi.three_goal_schema.v1';
const EXPECTED_DATABASE = 'hotelx_three_goals_e2e';
const EXPECTED_CATALOG_COUNT = 137;
const EXECUTION_LEASE_VALUE = 'approved';
const FIELD_EVIDENCE_BOUNDARY = 'SCHEMA_UNIT_ONLY_NOT_INTEGRATION_NOT_FIELD_VALIDATED';
const REQUIRED_MIGRATIONS = Object.freeze([
  '20260803_allow_unquantified_operation_targets.sql',
  '20260803_create_knowledge_promotion_workflow.sql',
  '20260803_create_temporal_forecast_trials.sql',
  '20260803_enforce_single_active_temporal_forecast_trial.sql',
]);

const currentFile = fileURLToPath(import.meta.url);
const automationDir = path.dirname(currentFile);
const repoRoot = path.resolve(automationDir, '..', '..');
const phpHelper = path.join(automationDir, 'three-goal-schema-baseline.php');
const runtimeRoot = path.join(repoRoot, 'runtime', 'three-goal-e2e');
const ownerMarkerPath = path.join(runtimeRoot, 'schema-owner.json');
const lockPath = path.join(runtimeRoot, 'schema-run.lock');

function truthy(value) {
  return ['1', 'true', 'yes', 'on', 'approved'].includes(
    String(value || '').trim().toLowerCase(),
  );
}

function safeError(error) {
  const raw = error instanceof Error ? error.message : String(error);
  const secrets = [
    process.env.DB_PASSWORD,
    process.env.DB_PASS,
    process.env.MYSQL_PWD,
  ].filter(Boolean);
  let message = raw;
  for (const secret of secrets) {
    message = message.split(secret).join('[REDACTED]');
  }
  return message.replace(/[\r\n\t]+/g, ' ');
}

function fail(message) {
  const error = new Error(message);
  error.name = 'ThreeGoalSchemaContractError';
  throw error;
}

function parseMode(argv) {
  if (argv.length !== 1 || argv[0] !== '--schema-only') {
    fail(
      'Only --schema-only is available. Forecast, XLSX, promotion, all-goal, restore, and UI modes fail closed.',
    );
  }
  return 'schema-only';
}

/**
 * This gate runs before mkdir, lock creation, child processes, or config loads.
 */
function offlineSafetyGate() {
  const suppliedHost = process.env.DB_HOST;
  const suppliedDb = process.env.DB_NAME;
  const suppliedE2eDb = process.env.SUXI_E2E_DB_NAME;

  if (suppliedHost && suppliedHost !== '127.0.0.1') {
    fail('DB_HOST must be exactly 127.0.0.1; remote and ambiguous hosts are refused.');
  }
  for (const supplied of [suppliedDb, suppliedE2eDb]) {
    if (supplied && supplied !== EXPECTED_DATABASE) {
      fail('Existing DB identity conflicts with the dedicated three-goal database.');
    }
  }
  if (suppliedDb === 'hotelx' || suppliedDb === 'hotelx_e2e') {
    fail('Shared hotelx and legacy hotelx_e2e are refused.');
  }
  if (
    truthy(process.env.SUXI_E2E_ALLOW_SHARED_DB)
    || truthy(process.env.SUXI_E2E_ALLOW_REMOTE_TEST_DB)
  ) {
    fail('Shared-database and remote-test overrides are refused.');
  }
  if (process.env.SUXI_THREE_GOAL_SCHEMA_EXECUTION_LEASE !== EXECUTION_LEASE_VALUE) {
    fail('A separate approved schema execution lease is required.');
  }
  if (!existsSync(phpHelper)) {
    fail('The dedicated three-goal PHP helper is missing.');
  }

  return {
    host: '127.0.0.1',
    database: EXPECTED_DATABASE,
    shared_override: false,
    remote_override: false,
    side_effect_started: false,
  };
}

function phpBinary() {
  const configured = process.env.SUXI_PHP_BINARY;
  const candidate = configured || 'C:\\xampp\\php\\php.exe';
  const normalized = path.resolve(candidate);
  if (path.basename(normalized).toLowerCase() !== 'php.exe') {
    fail('SUXI_PHP_BINARY must resolve to php.exe.');
  }
  if (!existsSync(normalized)) {
    fail('Configured php.exe does not exist.');
  }
  return normalized;
}

function childEnvironment() {
  return {
    ...process.env,
    DB_HOST: '127.0.0.1',
    DB_NAME: EXPECTED_DATABASE,
    SUXI_E2E_DB_NAME: EXPECTED_DATABASE,
    SUXI_E2E_DB_OVERRIDE: '1',
    SUXI_E2E_ISOLATED_RUNNER: '1',
    SUXI_THREE_GOAL_SCHEMA_EXECUTION_LEASE: EXECUTION_LEASE_VALUE,
    SUXI_E2E_ALLOW_SHARED_DB: '',
    SUXI_E2E_ALLOW_REMOTE_TEST_DB: '',
  };
}

function parseHelperJson(stdout) {
  const lines = String(stdout || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  if (lines.length !== 1) {
    fail('PHP helper must emit exactly one JSON line.');
  }
  let parsed;
  try {
    parsed = JSON.parse(lines[0]);
  } catch {
    fail('PHP helper output is not valid JSON.');
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    fail('PHP helper JSON must be an object.');
  }
  if (parsed.contract_version !== CONTRACT_VERSION) {
    fail('PHP helper contract version mismatch.');
  }
  if (parsed.database !== EXPECTED_DATABASE) {
    fail('PHP helper database identity mismatch.');
  }
  if (parsed.field_evidence_boundary !== FIELD_EVIDENCE_BOUNDARY) {
    fail('PHP helper field-evidence boundary mismatch.');
  }
  return parsed;
}

function runHelper(php, action, options = {}) {
  const args = [phpHelper, '--action=' + action];
  for (const [name, value] of Object.entries(options)) {
    args.push('--' + name + '=' + value);
  }
  const result = spawnSync(php, args, {
    cwd: repoRoot,
    env: childEnvironment(),
    encoding: 'utf8',
    windowsHide: true,
    timeout: 120000,
    maxBuffer: 4 * 1024 * 1024,
  });

  let payload = null;
  if (result.stdout) {
    payload = parseHelperJson(result.stdout);
  } else if (result.stderr) {
    try {
      payload = parseHelperJson(result.stderr);
    } catch {
      payload = null;
    }
  }
  if (result.error) {
    fail('PHP helper process failed: ' + safeError(result.error));
  }
  if (result.status !== 0 || !payload || payload.ok !== true) {
    const helperMessage = payload && payload.error
      ? String(payload.error)
      : 'no safe helper error was returned';
    fail('PHP helper ' + action + ' failed: ' + safeError(helperMessage));
  }
  return payload;
}

function assertGuardPayload(payload) {
  if (payload.action !== 'guard' || payload.database_action_attempted !== false) {
    fail('Offline guard reported an unexpected side effect.');
  }
  if (!payload.catalog || payload.catalog.count !== EXPECTED_CATALOG_COUNT) {
    fail('Offline guard did not confirm the exact 137 migration catalog.');
  }
  if (
    JSON.stringify(payload.catalog.required_migrations)
    !== JSON.stringify(REQUIRED_MIGRATIONS)
  ) {
    fail('Offline guard did not confirm all four shared 20260803 migrations.');
  }
  if (payload.legacy_demo_is_three_goal_fact !== false) {
    fail('Legacy demo rows must never be classified as three-goal facts.');
  }
}

function assertReadyStatus(payload, expectedAction) {
  if (payload.action !== expectedAction || !payload.status || payload.status.ready !== true) {
    fail(expectedAction + ' did not report ready=true.');
  }
  if (
    payload.status.current !== EXPECTED_CATALOG_COUNT
    || payload.status.required !== EXPECTED_CATALOG_COUNT
  ) {
    fail(expectedAction + ' did not report exact 137/137.');
  }
  for (const key of [
    'pending',
    'unknown',
    'checksum_mismatch',
    'duplicates',
    'unresolved_failure',
  ]) {
    if (!Array.isArray(payload.status[key]) || payload.status[key].length !== 0) {
      fail(expectedAction + ' reported a non-empty schema failure set: ' + key);
    }
  }
}

function relativeRuntimePath(absolutePath) {
  const relative = path.relative(repoRoot, absolutePath).split(path.sep).join('/');
  if (relative.startsWith('../') || path.isAbsolute(relative)) {
    fail('Runtime evidence path escaped the repository.');
  }
  if (!relative.startsWith('runtime/three-goal-e2e/')) {
    fail('Runtime evidence path escaped the dedicated directory.');
  }
  return relative;
}

function createSchemaOnlyLedger(ledgerPath, runId) {
  const ledger = {
    contract_version: CONTRACT_VERSION,
    database: EXPECTED_DATABASE,
    run_id: runId,
    purpose: 'schema_only_no_business_rows',
    run_owned_exact_ids: [],
    foreign_reference_exact_ids: [],
    cleanup_scope: 'exact_primary_keys_and_foreign_references_only',
    broad_hotel_or_date_delete: false,
    truncate: false,
    shared_database_drop: false,
    legacy_demo_is_three_goal_fact: false,
    field_evidence_boundary: FIELD_EVIDENCE_BOUNDARY,
  };
  writeFileSync(ledgerPath, JSON.stringify(ledger, null, 2) + '\n', {
    encoding: 'utf8',
    flag: 'wx',
    mode: 0o600,
  });
}

function sha256File(filePath) {
  return createHash('sha256').update(readFileSync(filePath)).digest('hex').toUpperCase();
}

function acquireLock() {
  mkdirSync(runtimeRoot, { recursive: true, mode: 0o700 });
  let descriptor;
  try {
    descriptor = openSync(lockPath, 'wx', 0o600);
    writeFileSync(
      descriptor,
      JSON.stringify({
        contract_version: CONTRACT_VERSION,
        database: EXPECTED_DATABASE,
        process_id: process.pid,
        started_at: new Date().toISOString(),
        serialized_wip: 1,
      }) + '\n',
      'utf8',
    );
  } catch {
    fail('Another three-goal schema run owns the serial WIP lock.');
  } finally {
    if (descriptor !== undefined) {
      closeSync(descriptor);
    }
  }
}

function releaseLock() {
  if (existsSync(lockPath)) {
    rmSync(lockPath, { force: true });
  }
}

function schemaOnlyRun() {
  const mode = parseMode(process.argv.slice(2));
  const safety = offlineSafetyGate();
  const php = phpBinary();

  acquireLock();
  const runId = 'three-goal-' + randomUUID().toLowerCase();
  const snapshotPath = path.join(runtimeRoot, runId + '-baseline.json');
  const ledgerPath = path.join(runtimeRoot, runId + '-ledger.json');

  try {
    const guard = runHelper(php, 'guard');
    assertGuardPayload(guard);

    const init = runHelper(php, 'init', {
      'run-id': runId,
      'owner-marker': relativeRuntimePath(ownerMarkerPath),
    });
    assertReadyStatus(init, 'init');

    const status = runHelper(php, 'status');
    assertReadyStatus(status, 'status');

    const snapshot = runHelper(php, 'snapshot', {
      'run-id': runId,
      snapshot: relativeRuntimePath(snapshotPath),
    });
    if (snapshot.action !== 'snapshot' || !existsSync(snapshotPath)) {
      fail('Baseline snapshot was not created.');
    }
    if (snapshot.snapshot_sha256 !== sha256File(snapshotPath)) {
      fail('Baseline snapshot SHA-256 readback mismatch.');
    }

    createSchemaOnlyLedger(ledgerPath, runId);
    const clean = runHelper(php, 'assert-clean', {
      'run-id': runId,
      snapshot: relativeRuntimePath(snapshotPath),
      ledger: relativeRuntimePath(ledgerPath),
    });
    assertReadyStatus(clean, 'assert-clean');
    if (
      clean.after_zero !== true
      || clean.protected_and_demo_baseline_unchanged !== true
    ) {
      fail('Schema-only cleanup/protected-baseline assertion failed.');
    }

    process.stdout.write(JSON.stringify({
      ok: true,
      mode,
      contract_version: CONTRACT_VERSION,
      database: EXPECTED_DATABASE,
      catalog: EXPECTED_CATALOG_COUNT,
      required_migrations: REQUIRED_MIGRATIONS,
      run_id: runId,
      created: init.created,
      reused_owned_baseline: init.reused_owned_baseline,
      snapshot_sha256: snapshot.snapshot_sha256,
      ledger_sha256: sha256File(ledgerPath),
      protected_and_demo_baseline_unchanged: true,
      legacy_demo_is_three_goal_fact: false,
      after_zero_definition: 'run_owned_exact_primary_keys_and_foreign_references_only',
      automatic_execution: false,
      ota_write: false,
      external_message: false,
      maturity: 'schema_baseline_verified_not_business_integration',
      field_evidence_boundary: FIELD_EVIDENCE_BOUNDARY,
      safety,
    }) + '\n');
  } finally {
    releaseLock();
  }
}

try {
  schemaOnlyRun();
} catch (error) {
  process.stderr.write(JSON.stringify({
    ok: false,
    contract_version: CONTRACT_VERSION,
    database: EXPECTED_DATABASE,
    fail_closed: true,
    automatic_retry: false,
    error: safeError(error),
    field_evidence_boundary: FIELD_EVIDENCE_BOUNDARY,
  }) + '\n');
  process.exitCode = 1;
}
