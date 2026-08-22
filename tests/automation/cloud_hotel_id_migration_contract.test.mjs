import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relative) => readFileSync(path.join(repoRoot, relative), 'utf8');

const registry = read('scripts/cloud_hotel_id_column_registry.php');
const inspector = read('scripts/inspect_cloud_hotel_id_dependencies.php');
const migration = read('scripts/migrate_cloud_hotel_id.php');
const hotelDataMergeService = read('app/service/HotelDataMergeService.php');
const hotelCascadeDeletionService = read('app/service/HotelCascadeDeletionService.php');
const installer = read('deploy/systemd/install_cloud_three_source_hourly.sh');
const runtimeEnvExample = read('deploy/systemd/dingdandao-collector.env.example');
const mariaDbVerifier = read('scripts/verify_cloud_hotel_id_migration_mariadb.php');

const phpBinary = [
  process.env.PHP_BINARY,
  process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : null,
  'php',
].find((candidate) => candidate && (candidate === 'php' || existsSync(candidate)));

function runPhp(code, ...args) {
  return execFileSync(phpBinary, ['-r', code, ...args], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  }).trim();
}

function tupleBlock(variableName, classification, semantic) {
  const startMarker = `$${variableName} = [`;
  const start = registry.indexOf(startMarker);
  assert.ok(start >= 0, `${startMarker} registry block must exist`);
  const end = registry.indexOf('\n    ];', start);
  assert.ok(end > start, `${startMarker} registry block must be closed`);
  return [...registry.slice(start, end).matchAll(/\['([^']+)', '([^']+)', '([^']+)'\]/g)]
    .map((match) => ({
      table: match[1],
      column: match[2],
      alias: match[3],
      semantic,
      classification,
    }));
}

const positiveEntries = tupleBlock(
  'positive',
  'positive_system_hotel_id',
  'system_hotel_id',
);
const negativeEntries = tupleBlock(
  'negative',
  'negative_non_system_hotel_id',
  'non_system_hotel_id',
);
const derivedEntries = tupleBlock(
  'derived',
  'derived_readonly_system_hotel_id',
  'derived_system_hotel_id',
);
const registryEntries = [...positiveEntries, ...negativeEntries, ...derivedEntries];
const keyOf = ({ table, column }) => `${table}.${column}`;
const positiveKeys = new Set(positiveEntries.map(keyOf));
const negativeKeys = new Set(negativeEntries.map(keyOf));
const derivedKeys = new Set(derivedEntries.map(keyOf));

function declaredSqlColumns() {
  const databaseDirectory = path.join(repoRoot, 'database');
  const migrationDirectory = path.join(repoRoot, 'database', 'migrations');
  const sqlFiles = [
    ...readdirSync(databaseDirectory)
      .filter((name) => name.endsWith('.sql'))
      .sort()
      .map((name) => path.join(databaseDirectory, name)),
    ...readdirSync(migrationDirectory)
      .filter((name) => name.endsWith('.sql'))
      .sort()
      .map((name) => path.join(migrationDirectory, name)),
  ];
  const declared = new Set();
  const tablePattern = '(?:`([^`]+)`|([A-Za-z0-9_]+))';

  for (const sqlFile of sqlFiles) {
    const sql = readFileSync(sqlFile, 'utf8');
    const createPattern = new RegExp(
      `CREATE\\s+TABLE(?:\\s+IF\\s+NOT\\s+EXISTS)?\\s+${tablePattern}\\s*\\(([\\s\\S]*?)\\)\\s*(?:ENGINE\\b|DEFAULT\\s+CHARSET\\b|;)`,
      'gi',
    );
    for (const create of sql.matchAll(createPattern)) {
      const table = create[1] || create[2];
      const body = create[3];
      for (const column of body.matchAll(/^\s*`([^`]+)`\s+[A-Za-z]/gm)) {
        declared.add(`${table}.${column[1]}`);
      }
    }

    const alterPattern = new RegExp(
      `ALTER\\s+TABLE\\s+${tablePattern}([\\s\\S]*?);`,
      'gi',
    );
    for (const alter of sql.matchAll(alterPattern)) {
      const table = alter[1] || alter[2];
      const body = alter[3];
      for (const column of body.matchAll(/ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/gi)) {
        declared.add(`${table}.${column[1]}`);
      }
    }
  }
  return declared;
}

test('both scripts consume one explicit positive/negative/derived hotel-column registry', () => {
  assert.match(registry, /suxios\.cloud_hotel_id_column_registry\.v2/);
  assert.match(inspector, /require_once __DIR__ .*cloud_hotel_id_column_registry\.php/);
  assert.match(migration, /require_once __DIR__ .*cloud_hotel_id_column_registry\.php/);
  assert.match(inspector, /cloudHotelIdColumnRegistry\(\)/);
  assert.match(inspector, /cloudHotelIdPositiveColumnRegistry\(\)/);
  assert.match(migration, /cloudHotelIdPositiveColumnRegistry\(\)/);
  assert.match(registry, /CLOUD_HOTEL_ID_COLUMN_REQUIRED = 'required'/);
  assert.match(registry, /CLOUD_HOTEL_ID_COLUMN_IF_PRESENT = 'if_present'/);
  assert.match(registry, /\$table === 'hotels' && \$column === 'id'[\s\S]*?CLOUD_HOTEL_ID_COLUMN_REQUIRED[\s\S]*?CLOUD_HOTEL_ID_COLUMN_IF_PRESENT/);

  const keys = registryEntries.map(keyOf);
  assert.ok(keys.length > 80, 'registry must cover the authoritative system-hotel surface');
  assert.equal(new Set(keys).size, keys.length, 'registry keys must be unique across both classes');
  assert.equal([...positiveKeys].filter((key) => negativeKeys.has(key)).length, 0);
  assert.equal([...derivedKeys].filter((key) => positiveKeys.has(key) || negativeKeys.has(key)).length, 0);
});

test('authoritative merge/delete relations and exact newer table aliases are positive', () => {
  const mergePlans = [...hotelDataMergeService.matchAll(
    /\['table' => '([^']+)', 'column' => '([^']+)'/g,
  )].map((match) => `${match[1]}.${match[2]}`);
  const deletionStart = hotelCascadeDeletionService.indexOf('private const HOTEL_RELATIONS = [');
  const deletionEnd = hotelCascadeDeletionService.indexOf('\n    ];', deletionStart);
  assert.ok(deletionStart >= 0 && deletionEnd > deletionStart);
  const deletionRelations = [...hotelCascadeDeletionService.slice(deletionStart, deletionEnd).matchAll(
    /\['([^']+)', '([^']+)'\]/g,
  )].map((match) => `${match[1]}.${match[2]}`);
  assert.ok(mergePlans.length >= 40, 'HotelDataMergeService baseline must be parsed');
  assert.ok(deletionRelations.length >= 50, 'HotelCascadeDeletionService baseline must be parsed');
  for (const key of [...mergePlans, ...deletionRelations]) {
    assert.ok(positiveKeys.has(key), `authoritative system relation missing from registry: ${key}`);
  }

  for (const key of [
    'ai_model_call_logs.hotel_id',
    'manager_capability_score_snapshots.hotel_id',
    'manager_capability_case_followups.hotel_id',
    'hotel_operating_sop_replications.source_hotel_id',
    'hotel_operating_sop_replications.target_hotel_id',
    'hotel_operating_sop_replication_reviews.source_hotel_id',
    'hotel_operating_sop_replication_reviews.target_hotel_id',
  ]) {
    assert.ok(positiveKeys.has(key), `exact positive alias missing: ${key}`);
  }
  for (const wrongKey of [
    'manager_capability_case_scores.hotel_id',
    'manager_capability_followups.hotel_id',
    'operating_sop_replications.source_hotel_id',
    'operating_replication_reviews.source_hotel_id',
  ]) {
    assert.ok(!positiveKeys.has(wrongKey), `obsolete table alias must not be registered: ${wrongKey}`);
  }
});

test('every positive, negative and derived registry declaration exists in tracked SQL schema', () => {
  const declared = declaredSqlColumns();
  const missing = registryEntries.map(keyOf).filter((key) => !declared.has(key));
  assert.deepEqual(missing, [], `registry entries missing from schema declarations: ${missing.join(', ')}`);
});

test('every tracked hotel_id-pattern and store_id DDL candidate is explicitly classified', () => {
  const declared = declaredSqlColumns();
  const candidates = [...declared].filter((key) => {
    const separator = key.lastIndexOf('.');
    const table = key.slice(0, separator);
    const column = key.slice(separator + 1);
    return (table === 'hotels' && column === 'id')
      || column === 'store_id'
      || /(^|_)hotel_id$/.test(column);
  });
  const classified = new Set([...positiveKeys, ...negativeKeys, ...derivedKeys]);
  const unclassified = candidates.filter((key) => !classified.has(key)).sort();
  assert.deepEqual(
    unclassified,
    [],
    `tracked hotel identity DDL candidates missing explicit classification: ${unclassified.join(', ')}`,
  );
});

test('the four proven competitor store_id aliases are positive system-hotel IDs', () => {
  for (const table of [
    'competitor_hotel',
    'competitor_price_log',
    'competitor_wechat_robot',
    'competitor_device',
  ]) {
    assert.deepEqual(
      positiveEntries.find((entry) => entry.table === table && entry.column === 'store_id'),
      {
        table,
        column: 'store_id',
        semantic: 'system_hotel_id',
        alias: 'legacy_store_id',
        classification: 'positive_system_hotel_id',
      },
    );
  }
});

test('known OTA/provider hotel IDs are negative and never auto-migrated', () => {
  for (const key of [
    'online_daily_data.hotel_id',
    'competitor_price_log.ota_hotel_id',
    'ota_ctrip_capture_runs.ota_hotel_id',
    'dingdandao_pms_integrations.provider_hotel_id',
  ]) {
    assert.ok(negativeKeys.has(key), `negative identifier classification missing: ${key}`);
    assert.ok(!positiveKeys.has(key), `negative identifier must not be positive: ${key}`);
  }
  assert.match(registry, /registered_negative_non_system_hotel_column/);
  assert.match(migration, /CLOUD_HOTEL_ID_COLUMN_NEGATIVE[\s\S]*?continue;/);
});

test('active_system_hotel_id is derived read-only system identity, not a negative external ID', () => {
  const key = 'ota_local_collector_account_hotels.active_system_hotel_id';
  assert.ok(derivedKeys.has(key));
  assert.ok(!positiveKeys.has(key));
  assert.ok(!negativeKeys.has(key));
  assert.match(registry, /'semantic' => 'derived_system_hotel_id'/);
  assert.match(registry, /'source_column' => 'system_hotel_id'/);
  assert.match(migration, /CLOUD_HOTEL_ID_COLUMN_DERIVED[\s\S]*?derived_system_hotel_column_not_readonly_generated[\s\S]*?continue;/);
  assert.match(migration, /postcommit_derived_column_mismatch/);
  assert.match(migration, /readonly_generated_source_identity_subset_verified/);
  assert.match(migration, /'derived_readonly_system_hotel_columns' => array_values\(\$derivedAudit\)/);
  assert.match(inspector, /invalid_derived_readonly_columns/);
});

test('discovery includes every store_id and an unregistered future store_id fails closed', () => {
  for (const source of [inspector, migration]) {
    assert.match(source, /c\.COLUMN_NAME=\\'store_id\\'/);
  }
  assert.match(registry, /'automatic_migration_eligible' => false/);
  assert.match(registry, /unregistered_store_id_requires_review/);
  assert.match(inspector, /unregistered_store_id_policy' => 'fail_closed_review_required_never_auto_migrate'/);
  assert.match(inspector, /exit\(\$auditStatus === 'ok' \? 0 : 3\)/);

  const unknownStoreBranch = migration.slice(
    migration.indexOf("if ($column === 'store_id')"),
    migration.indexOf('if (!$numericCompatible)'),
  );
  assert.match(unknownStoreBranch, /unregistered_store_id_column_requires_review/);
  assert.match(unknownStoreBranch, /throw new RuntimeException/);
  assert.doesNotMatch(unknownStoreBranch, /UPDATE/);
});

test('optional positive tables are updated only when discovery placed them in preflight', () => {
  const apply = migration.slice(migration.indexOf('$lockAcquired = false;'));
  assert.doesNotMatch(migration, /planned_column_missing/);
  assert.equal((migration.match(/foreach \(\$preflight as \$key => \$entry\)/g) || []).length, 2);
  assert.doesNotMatch(apply, /foreach \(cloudHotelIdMigrationPlan\(\) as \$entry\)/);
  assert.match(migration, /function cloudHotelIdMigrationApplyRelational[\s\S]*?foreach \(\$preflight as \$key => \$entry\)[\s\S]*?UPDATE /);
  assert.match(migration, /function cloudHotelIdMigrationAssertRelationalPostflight[\s\S]*?foreach \(\$preflight as \$key => \$entry\)/);
  assert.match(apply, /cloudHotelIdMigrationApplyRelational\(\$preflight, \$fromHotelId, \$toHotelId\)/);
  assert.match(apply, /cloudHotelIdMigrationAssertRelationalPostflight\(/);
  assert.match(migration, /if \(isset\(\$preflight\['hotels\.id'\]\)\)[\s\S]*?\$preflight\['hotels\.id'\] = \$hotelPrimaryKey/);
  assert.match(migration, /\$entry\['presence'\] === CLOUD_HOTEL_ID_COLUMN_REQUIRED[\s\S]*?required_positive_column_missing/);
});

test('apply holds its dedicated named lock through serializable commit and new-connection audit', () => {
  const apply = migration.slice(migration.indexOf('$lockAcquired = false;'));
  const orderedMarkers = [
    '$lockConnection = Db::connect(null, true)',
    'cloudHotelIdMigrationAcquireLock($lockConnection)',
    'cloudHotelIdMigrationAssertExternalRuntimePrepared($toHotelId)',
    "Db::execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')",
    'Db::startTrans()',
    'cloudHotelIdMigrationAssertIdentity(',
    'cloudHotelIdMigrationPreflight(',
    'cloudHotelIdMigrationJsonPreflight(',
    'cloudHotelIdMigrationApplyRelational(',
    'cloudHotelIdMigrationApplyMutableJson(',
    'cloudHotelIdMigrationAssertRelationalPostflight(',
    'cloudHotelIdMigrationAssertJsonReadback(',
    'Db::commit()',
    'cloudHotelIdMigrationPostCommitAudit(',
    '} catch (Throwable $exception)',
    '} finally {',
    'cloudHotelIdMigrationReleaseLock($lockConnection)',
  ];
  let previous = -1;
  for (const marker of orderedMarkers) {
    const current = apply.indexOf(marker);
    assert.ok(current > previous, `${marker} must occur after the previous apply step`);
    previous = current;
  }
  assert.match(migration, /SELECT GET_LOCK\(\?, \?\) AS lock_acquired/);
  assert.match(migration, /SELECT RELEASE_LOCK\(\?\) AS lock_released/);
  assert.match(migration, /Db::connect\(null, true\)[\s\S]*?postcommit_new_connection_not_proven/);
  assert.match(migration, /named_migration_lock_held_through_postcommit_audit/);
  assert.match(migration, /named_migration_lock_release_failed/);
});

test('apply requires an explicit operator all-writer pause attestation before app loading', () => {
  const maintenanceCheck = migration.indexOf('maintenance_write_pause_confirmation_required');
  const appLoad = migration.indexOf('require $autoload;');
  assert.match(migration, /--maintenance-write-pause-confirmed=ALL_WRITERS_PAUSED/);
  assert.match(migration, /CLOUD_HOTEL_ID_MIGRATION_MAINTENANCE_CONFIRMATION = 'ALL_WRITERS_PAUSED'/);
  assert.match(migration, /all_writer_quiescence_operator_attested/);
  assert.match(migration, /all_writer_quiescence_programmatically_verified' => false/);
  assert.match(migration, /operator_attestation_plus_dingdandao_unit_checks_only/);
  assert.ok(maintenanceCheck >= 0 && appLoad > maintenanceCheck);
});

test('contract v2 reports truthful DB and external-runtime completion boundaries', () => {
  assert.match(migration, /suxios\.cloud_hotel_id_migration\.v2/);
  assert.match(migration, /'automatic_update_scope' => 'explicit_registry_only'/);
  assert.match(migration, /'transaction_isolation' => 'SERIALIZABLE'/);
  assert.match(migration, /identity_and_preflight_rechecked_inside_apply_transaction/);
  assert.match(migration, /postflight_verified_before_commit/);
  assert.match(migration, /postcommit_new_connection_full_registry_audit_required/);
  assert.match(migration, /stop_suxios_dingdandao_collection_timer_and_service_before_database_write/);
  assert.match(migration, /exact_env_hotel_id_readback_before_database_write/);
  assert.match(migration, /restart_and_verify_timer_only_after_database_commit/);
  assert.match(migration, /'status' => 'database_migrated_external_runtime_restart_required'/);
  assert.match(migration, /'database_migration_status' => 'postcommit_new_connection_full_registry_audit_passed'/);
  assert.match(migration, /'completion_gate' => 'external_runtime_config_blocked'/);
  assert.doesNotMatch(migration, /migrated_and_readback_verified/);
});

test('Dingdandao runtime example and installer fail closed on hotel ID drift', () => {
  assert.match(runtimeEnvExample, /^SUXIOS_DINGDANDAO_HOTEL_ID=80$/m);
  assert.doesNotMatch(runtimeEnvExample, /^SUXIOS_DINGDANDAO_HOTEL_ID=5$/m);
  assert.match(installer, /read_exact_dingdandao_hotel_id/);
  assert.match(installer, /EXPECTED_SYSTEM_HOTEL_ID=.*DINGDANDAO_ENV_EXAMPLE/);
  assert.match(installer, /ACTUAL_SYSTEM_HOTEL_ID=.*DINGDANDAO_ENV/);
  assert.match(installer, /ACTUAL_SYSTEM_HOTEL_ID.*!=.*EXPECTED_SYSTEM_HOTEL_ID/);
  assert.match(installer, /external_runtime_config_blocked: dingdandao env hotel id mismatch/);
  assert.match(installer, /runtime_readback_verified=1/);

  const readbackCheck = installer.indexOf('ACTUAL_SYSTEM_HOTEL_ID=');
  const verifyOrInstallBranch = installer.indexOf('if [[ $INSTALL -ne 1 ]]');
  const unitInstall = installer.indexOf('install -o root -g root -m 0644');
  assert.ok(readbackCheck >= 0 && verifyOrInstallBranch > readbackCheck);
  assert.ok(unitInstall > readbackCheck);
});

test('apply validates target env and stopped Dingdandao units before transaction opening', () => {
  assert.match(migration, /CLOUD_HOTEL_ID_MIGRATION_RUNTIME_ENV = '\/etc\/suxios\/dingdandao-collector\.env'/);
  assert.match(migration, /suxios-dingdandao-collection\.timer/);
  assert.match(migration, /suxios-dingdandao-collection\.service/);
  assert.match(migration, /external_runtime_config_blocked:dingdandao_hotel_id_mismatch/);
  assert.match(migration, /external_runtime_config_blocked:unit_not_stopped/);

  const apply = migration.slice(migration.indexOf('$lockAcquired = false;'));
  const runtimeReadback = apply.indexOf('cloudHotelIdMigrationAssertExternalRuntimePrepared($toHotelId)');
  const transaction = apply.indexOf("Db::execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')");
  assert.ok(runtimeReadback >= 0 && transaction > runtimeReadback);
});

test('JSON policy registry declarations exist in tracked SQL and active locations are explicit', () => {
  const policies = JSON.parse(runPhp(
    'require $argv[1]; echo json_encode(cloudHotelIdJsonPolicyRegistry(), JSON_THROW_ON_ERROR);',
    path.join(repoRoot, 'scripts', 'cloud_hotel_id_column_registry.php'),
  ));
  const declared = declaredSqlColumns();
  assert.ok(policies.length >= 20, 'JSON policy registry must cover active config and immutable evidence');
  const missing = policies
    .map((policy) => `${policy.table}.${policy.column}`)
    .filter((key) => !declared.has(key));
  assert.deepEqual(missing, [], `JSON policy locations missing from tracked SQL: ${missing.join(', ')}`);

  const systemConfig = policies.find((entry) => entry.table === 'system_configs'
    && entry.column === 'config_value');
  assert.equal(systemConfig.policy, 'mutable_active_config');
  assert.equal(systemConfig.selector, 'config_key_allowlist');
  assert.deepEqual(systemConfig.row_keys, ['ctrip_config_list', 'meituan_config_list']);
  assert.deepEqual(systemConfig.identity_keys, ['system_hotel_id', 'hotel_id']);

  const platformConfig = policies.find((entry) => entry.table === 'platform_data_sources'
    && entry.column === 'config_json');
  assert.equal(platformConfig.policy, 'mutable_active_config');
  assert.equal(platformConfig.selector, 'system_hotel_scope');
  assert.deepEqual(platformConfig.identity_keys, [
    'system_hotel_id',
    'collector_hotel_id',
    'source_system_hotel_id',
    'destination_system_hotel_id',
  ]);
  assert.deepEqual(platformConfig.non_system_keys, ['hotel_id']);
  assert.match(registry, /\['platform_data_raw_records', 'raw_payload'\]/);
});

test('mutable JSON transform migrates canonical int/string IDs and preserves external IDs', () => {
  const registryPath = path.join(repoRoot, 'scripts', 'cloud_hotel_id_column_registry.php');
  const systemInput = {
    system_hotel_id: 5,
    hotel_id: '5',
    platform_hotel_id: '5',
    nested: [{ hotel_id: '5' }],
  };
  const systemProjection = JSON.parse(runPhp(
    '$v=json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);'
      + '$k=json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);'
      + 'require $argv[1]; echo json_encode(cloudHotelIdTransformMutableJsonValue($v,5,80,$k), JSON_THROW_ON_ERROR);',
    registryPath,
    JSON.stringify(systemInput),
    JSON.stringify(['system_hotel_id', 'hotel_id']),
  ));
  assert.equal(systemProjection.from_count, 3);
  assert.equal(systemProjection.to_count, 0);
  assert.equal(systemProjection.transformed.system_hotel_id, 80);
  assert.equal(systemProjection.transformed.hotel_id, '80');
  assert.equal(systemProjection.transformed.nested[0].hotel_id, '80');
  assert.equal(systemProjection.transformed.platform_hotel_id, '5');

  const platformInput = {
    collector_hotel_id: 5,
    system_hotel_id: '5',
    source_system_hotel_id: '5',
    hotel_id: '5',
    platform_hotel_id: '5',
  };
  const platformProjection = JSON.parse(runPhp(
    '$v=json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);'
      + '$k=json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);'
      + 'require $argv[1]; echo json_encode(cloudHotelIdTransformMutableJsonValue($v,5,80,$k), JSON_THROW_ON_ERROR);',
    registryPath,
    JSON.stringify(platformInput),
    JSON.stringify([
      'system_hotel_id',
      'collector_hotel_id',
      'source_system_hotel_id',
      'destination_system_hotel_id',
    ]),
  ));
  assert.equal(platformProjection.transformed.collector_hotel_id, 80);
  assert.equal(platformProjection.transformed.system_hotel_id, '80');
  assert.equal(platformProjection.transformed.source_system_hotel_id, '80');
  assert.equal(platformProjection.transformed.hotel_id, '5');
  assert.equal(platformProjection.transformed.platform_hotel_id, '5');
});

test('mutable JSON source plus target identity fails closed while a target-only row is a no-op', () => {
  const output = JSON.parse(runPhp(
    'define("CLOUD_HOTEL_ID_MIGRATION_LIBRARY_ONLY", true); require $argv[1];'
      + '$p=cloudHotelIdJsonPolicyRegistryIndex()["system_configs.config_value"];'
      + '$both=json_encode(["system_hotel_id"=>5,"hotel_id"=>"80"], JSON_THROW_ON_ERROR);'
      + '$target=json_encode(["system_hotel_id"=>80], JSON_THROW_ON_ERROR);'
      + '$error=""; try { cloudHotelIdMigrationPrepareMutableJsonEntry($p,"config_key","ctrip_config_list",["config_key"=>"ctrip_config_list"],$both,5,80); }'
      + ' catch (Throwable $e) { $error=$e->getMessage(); }'
      + '$noop=cloudHotelIdMigrationPrepareMutableJsonEntry($p,"config_key","ctrip_config_list",["config_key"=>"ctrip_config_list"],$target,5,80);'
      + 'echo json_encode(["error"=>$error,"noop"=>$noop], JSON_THROW_ON_ERROR);',
    path.join(repoRoot, 'scripts', 'migrate_cloud_hotel_id.php'),
  ));
  assert.match(output.error, /mutable_active_config_target_id_already_present/);
  assert.deepEqual(output.noop, []);
});

test('inspector separates active mutable, immutable evidence, and unknown JSON references', () => {
  assert.match(inspector, /cloudHotelIdJsonPolicyRegistryIndex\(\)/);
  assert.match(inspector, /system_configs[\s\S]*?config_value[\s\S]*?explicit_policy_location/);
  assert.match(inspector, /\$platformPolicy = \$jsonPolicyIndex\['platform_data_sources\.config_json'\]/);
  assert.match(inspector, /dependencyInspectMutableJsonEntry/);
  assert.match(inspector, /mutable_active_config_target_id_already_present/);
  assert.match(inspector, /mutable_active_config_references/);
  assert.match(inspector, /mutable_active_config_errors/);
  assert.match(inspector, /preserved_immutable_evidence_references/);
  assert.match(inspector, /blocking_unknown_json_references/);
  assert.match(inspector, /multiset_sha256/);
  assert.match(inspector, /\$hasMigrationWork \? 'migration_required' : 'ok'/);
  assert.match(inspector, /'raw_json_values_disclosed' => false/);
  assert.doesNotMatch(inspector, /historical_json_policy/);
});

test('inspector and migrator both fail closed when foreign keys reference hotels.id', () => {
  assert.match(migration, /cloudHotelIdMigrationForeignKeysToHotels\(\) !== \[\][\s\S]*?foreign_keys_to_hotels_require_explicit_review/);
  assert.match(migration, /postcommit_foreign_keys_to_hotels_require_explicit_review/);
  assert.match(migration, /foreign_keys_to_hotels_verified_absent/);
  assert.match(inspector, /\$hasBlockingCondition =[\s\S]*?\|\| \$foreignKeys !== \[\]/);
  assert.match(inspector, /foreign_keys_to_hotels/);
});

test('migration uses policy-specific CAS/readback and preserves immutable raw multiset digests', () => {
  assert.match(migration, /AND BINARY [\s\S]*?=BINARY \?/);
  assert.match(migration, /cloudHotelIdTransformMutableJsonValue\([\s\S]*?\$entry\['identity_keys'\]/);
  assert.match(migration, /mutable_active_config_readback_mismatch/);
  assert.match(migration, /immutable_evidence_multiset_digest_changed/);
  assert.match(migration, /if \(\$candidate\['policy'\] === CLOUD_HOTEL_ID_JSON_MUTABLE_ACTIVE\)[\s\S]*?continue;/);
  assert.match(migration, /\$currentJsonPreflight = cloudHotelIdMigrationJsonPreflight\(\$fromHotelId, \$toHotelId, false\)/);
  assert.match(migration, /postcommit_mutable_json_source_reference_reappeared/);
  assert.match(migration, /postcommit_immutable_json_reference_set_changed/);
  assert.match(migration, /unknown_non_historical_json_reference_requires_review/);
  assert.match(migration, /raw_json_values_disclosed' => false/);
});

test('hotels auto-increment must remain strictly above the renamed target ID', () => {
  assert.match(migration, /function cloudHotelIdMigrationAssertHotelsAutoIncrementAbove/);
  assert.match(migration, /hotels_auto_increment_not_above_target/);
  assert.match(migration, /cloudHotelIdMigrationAssertRelationalPostflight[\s\S]*?cloudHotelIdMigrationAssertHotelsAutoIncrementAbove\(\$toHotelId\)/);
  assert.match(migration, /postcommit_hotel_identity_mismatch[\s\S]*?cloudHotelIdMigrationAssertHotelsAutoIncrementAbove\(\$toHotelId\)/);
  assert.match(migration, /hotels_auto_increment_above_target_verified/);
  assert.match(mariaDbVerifier, /hotels_auto_increment_above_80/);
});

test('disposable MariaDB verifier is opt-in, local-only, cleanup-proven, and receipt-safe', () => {
  assert.match(mariaDbVerifier, /SUXI_CLOUD_HOTEL_ID_MARIADB_VERIFY/);
  assert.match(mariaDbVerifier, /\$host !== '127\.0\.0\.1'/);
  assert.match(mariaDbVerifier, /suxios_cloud_hotel_id_[\s\S]*?_e2e/);
  assert.match(mariaDbVerifier, /DROP DATABASE IF EXISTS/);
  assert.match(mariaDbVerifier, /information_schema\.SCHEMATA/);
  assert.match(mariaDbVerifier, /cleanup_remaining/);
  assert.match(mariaDbVerifier, /unknown_active_json_reference_plan_and_inspector_blocked/);
  assert.match(mariaDbVerifier, /mutable_source_target_collision_blocked/);
  assert.match(mariaDbVerifier, /immutable_json_raw_digest_preserved/);
  assert.match(mariaDbVerifier, /new_connection_postcommit_audit_verified/);
  assert.match(mariaDbVerifier, /credentials_disclosed.*false/s);
  assert.match(mariaDbVerifier, /raw_json_values_disclosed.*false/s);
});
