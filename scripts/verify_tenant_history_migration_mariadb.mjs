import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const projectRoot = resolve(import.meta.dirname, '..');
const tenantFoundationMigrationPath = resolve(
  projectRoot,
  'database/migrations/20260722_create_tenants_and_decouple_hotel_scope.sql',
);
const batchedTenantHistoryRepairMigrationPath = resolve(
  projectRoot,
  'database/migrations/20260722_pre_repair_batched_tenant_history_scope.sql',
);
const tenantHistoryRepairMigrationPath = resolve(
  projectRoot,
  'database/migrations/20260722_repair_remaining_tenant_history_scope.sql',
);
const grantedUserContextRepairMigrationPath = resolve(
  projectRoot,
  'database/migrations/20260723_repair_single_tenant_granted_user_context.sql',
);
const ownerTenantStandardizationMigrationPath = resolve(
  projectRoot,
  'database/migrations/20260723_standardize_self_owned_hotel_tenants.sql',
);
const vip021OwnerTenantStandardizationMigrationPath = resolve(
  projectRoot,
  'database/migrations/20260723_standardize_vip021_self_owned_tenant.sql',
);
const mysqlBinary = process.env.MYSQL_BINARY || 'mariadb';
const databaseHost = process.env.DB_HOST || '127.0.0.1';
const databasePort = process.env.DB_PORT || '3306';
const databaseUser = process.env.DB_USER || 'root';
const databasePassword = process.env.DB_PASS || '';
const compatibilitySmokeVersion = process.env.SUXIOS_TENANT_MARIADB_COMPAT_SMOKE || '';
const databaseName = `suxios_tenant_upgrade_test_${process.pid}_${randomBytes(4).toString('hex')}`;

for (const migrationPath of [
  tenantFoundationMigrationPath,
  batchedTenantHistoryRepairMigrationPath,
  tenantHistoryRepairMigrationPath,
  grantedUserContextRepairMigrationPath,
  ownerTenantStandardizationMigrationPath,
  vip021OwnerTenantStandardizationMigrationPath,
]) {
  if (!existsSync(migrationPath)) {
    throw new Error(`Tenant migration is missing: ${migrationPath}`);
  }
}
if (!/^suxios_tenant_upgrade_test_[a-z0-9_]+$/i.test(databaseName)) {
  throw new Error(`Unsafe dedicated database name: ${databaseName}`);
}

function runMysql({ database = '', input, label }) {
  const args = [
    '--protocol=TCP',
    `--host=${databaseHost}`,
    `--port=${databasePort}`,
    `--user=${databaseUser}`,
    '--connect-timeout=10',
    '--batch',
    '--skip-column-names',
    '--raw',
  ];
  if (database !== '') args.push(database);

  const result = spawnSync(mysqlBinary, args, {
    cwd: projectRoot,
    env: { ...process.env, MYSQL_PWD: databasePassword },
    input,
    encoding: 'utf8',
    windowsHide: true,
    timeout: 120000,
  });
  if (result.error) {
    throw new Error(`${label} failed to start: ${result.error.message}`);
  }
  if (result.status !== 0) {
    const details = `${result.stdout || ''}\n${result.stderr || ''}`.trim().slice(-4000);
    throw new Error(`${label} failed with exit ${result.status}: ${details}`);
  }
  return String(result.stdout || '').trim();
}

function queryScalar(sql, label, database = '') {
  const output = runMysql({ database, input: `${sql}\n`, label });
  const value = Number(output.split(/\s+/).filter(Boolean).at(-1));
  if (!Number.isFinite(value)) {
    throw new Error(`${label} returned a non-numeric value: ${output}`);
  }
  return value;
}

const scopeColumns = {
  daily_reports: 'hotel_id',
  monthly_tasks: 'hotel_id',
  online_daily_data: 'system_hotel_id',
  operation_logs: 'hotel_id',
  platform_data_sources: 'system_hotel_id',
  platform_data_sync_tasks: 'system_hotel_id',
  platform_data_raw_records: 'system_hotel_id',
  platform_data_sync_logs: 'system_hotel_id',
  agent_configs: 'hotel_id',
  agent_logs: 'hotel_id',
  demand_forecasts: 'hotel_id',
  price_suggestions: 'hotel_id',
  agent_tasks: 'hotel_id',
  knowledge_categories: 'hotel_id',
  knowledge_base: 'hotel_id',
  room_types: 'hotel_id',
  devices: 'hotel_id',
  energy_consumption: 'hotel_id',
  competitor_analysis: 'hotel_id',
  agent_work_orders: 'hotel_id',
  agent_conversations: 'hotel_id',
  energy_benchmarks: 'hotel_id',
  energy_saving_suggestions: 'hotel_id',
  maintenance_plans: 'hotel_id',
  hotel_field_templates: 'hotel_id',
  competitor_hotel: 'store_id',
  competitor_price_log: 'store_id',
  opening_projects: 'hotel_id',
  operation_alerts: 'hotel_id',
  operation_action_tracks: 'hotel_id',
  operation_execution_intents: 'hotel_id',
  operation_execution_tasks: 'hotel_id',
  transfer_records: 'hotel_id',
  complaint_rooms: 'hotel_id',
  complaint_feedbacks: 'hotel_id',
  field_mappings: 'hotel_id',
  ai_model_call_logs: 'hotel_id',
};

const userScopeColumns = {
  login_logs: 'user_id',
  quant_simulation_records: 'created_by',
  expansion_records: 'created_by',
  strategy_simulation_records: 'created_by',
  feasibility_reports: 'created_by',
};

const ownerTenantOnlyTables = [
  'ai_daily_reports',
  'ai_report_generation_tasks',
  'ai_report_human_reviews',
  'ai_report_input_cache',
  'analysis_reference_set_versions',
  'competitor_device',
  'online_data_correction_ledger',
  'ota_credentials',
  'ota_credential_audit_logs',
  'ota_ctrip_capture_gaps',
  'ota_ctrip_capture_runs',
  'ota_ctrip_entity_snapshots',
  'ota_ctrip_metric_facts',
  'ota_profile_bindings',
  'temporal_forecast_snapshots',
];

const largeRepairFixtureTable = 'agent_tasks';
const largeRepairFixtureRows = 1005;

const coreTableSql = Object.entries(scopeColumns).map(([table, scopeColumn]) => `
CREATE TABLE \`${table}\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  \`${scopeColumn}\` int unsigned DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;
INSERT INTO \`${table}\` (\`id\`, \`tenant_id\`, \`${scopeColumn}\`) VALUES
  (1, 11, 11),
  (2, 22, 22),
  (3, 999, 999),
  (4, 213, 213);
`).join('\n');

const largeRepairFixtureSql = `
INSERT INTO \`${largeRepairFixtureTable}\` (\`id\`, \`tenant_id\`, \`hotel_id\`) VALUES
${Array.from({ length: largeRepairFixtureRows }, (_, index) => `  (${1000 + index}, 11, 11)`).join(',\n')};
`;

const userScopedTableSql = Object.entries(userScopeColumns).map(([table, scopeColumn]) => `
CREATE TABLE \`${table}\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  \`${scopeColumn}\` int unsigned DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;
INSERT INTO \`${table}\` (\`id\`, \`tenant_id\`, \`${scopeColumn}\`) VALUES
  (1, 11, 101),
  (2, 22, 102),
  (3, 999, 103),
  (4, 213, 163);
`).join('\n');

const ownerTenantOnlyTableSql = ownerTenantOnlyTables.map((table) => `
CREATE TABLE \`${table}\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;
INSERT INTO \`${table}\` (\`id\`, \`tenant_id\`) VALUES
  (1, 213);
`).join('\n');

const parentScopedTableSql = `
CREATE TABLE \`operation_execution_evidence\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  \`task_id\` int unsigned DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;
INSERT INTO \`operation_execution_evidence\` (\`id\`, \`tenant_id\`, \`task_id\`) VALUES
  (1, 11, 1),
  (2, 22, 2),
  (3, 999, 3),
  (4, 213, 4);
`;

const historicalSchema = `
CREATE TABLE \`hotels\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned NOT NULL DEFAULT 0,
  \`name\` varchar(120) NOT NULL,
  \`status\` tinyint unsigned NOT NULL DEFAULT 1,
  \`owner_user_id\` int unsigned NOT NULL DEFAULT 0,
  \`created_by\` int unsigned NOT NULL DEFAULT 0,
  \`create_time\` datetime DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;

CREATE TABLE \`users\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  \`username\` varchar(50) NOT NULL,
  \`status\` tinyint unsigned NOT NULL DEFAULT 1,
  \`hotel_id\` int unsigned DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;

CREATE TABLE \`user_hotel_permissions\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  \`user_id\` int unsigned NOT NULL,
  \`hotel_id\` int unsigned NOT NULL,
  \`scope_type\` varchar(20) NOT NULL DEFAULT 'granted',
  \`status\` varchar(20) NOT NULL DEFAULT 'active',
  \`created_by\` int unsigned NOT NULL DEFAULT 0,
  \`is_primary\` tinyint unsigned NOT NULL DEFAULT 0,
  \`expires_at\` datetime DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;

INSERT INTO \`hotels\` (
  \`id\`,
  \`tenant_id\`,
  \`name\`,
  \`status\`,
  \`owner_user_id\`,
  \`created_by\`,
  \`create_time\`
) VALUES
  (11, 501, 'Tenant A hotel 1', 1, 0, 0, '2026-05-29 10:00:00'),
  (22, 501, 'Tenant A hotel 2', 1, 0, 0, '2026-05-29 11:00:00'),
  (33, 502, 'Tenant B hotel', 1, 0, 0, '2026-05-29 12:00:00'),
  (137, 137, 'VIP016 primary', 1, 163, 163, '2026-06-01 10:00:00'),
  (213, 213, 'VIP016 second', 1, 163, 163, '2026-06-02 10:00:00'),
  (132, 132, 'VIP019 primary', 1, 166, 166, '2026-06-01 10:00:00'),
  (135, 135, 'VIP019 second', 1, 166, 166, '2026-06-02 10:00:00'),
  (131, 131, 'VIP021 disabled legacy primary', 0, 168, 168, '2026-06-01 10:00:00'),
  (133, 133, 'VIP021 second', 1, 168, 168, '2026-06-02 10:00:00'),
  (182, 182, 'VIP021 third', 1, 168, 168, '2026-06-03 10:00:00');
INSERT INTO \`users\` (\`id\`, \`tenant_id\`, \`username\`, \`status\`, \`hotel_id\`) VALUES
  (101, 11, 'legacy_101', 1, 11),
  (102, 22, 'legacy_102', 1, 22),
  (103, 999, 'legacy_103', 1, 999),
  (104, NULL, 'legacy_104', 1, NULL),
  (105, NULL, 'legacy_105', 1, NULL),
  (163, NULL, 'VIP016', 1, NULL),
  (166, NULL, 'VIP019', 1, NULL),
  (168, NULL, 'VIP021', 1, NULL);
INSERT INTO \`user_hotel_permissions\` (
  \`id\`,
  \`tenant_id\`,
  \`user_id\`,
  \`hotel_id\`,
  \`scope_type\`,
  \`status\`,
  \`created_by\`,
  \`is_primary\`,
  \`expires_at\`
) VALUES
  (201, 11, 101, 11, 'granted', 'active', 0, 0, NULL),
  (202, 22, 102, 22, 'granted', 'active', 0, 0, NULL),
  (203, NULL, 104, 11, 'granted', 'active', 0, 0, NULL),
  (204, NULL, 105, 11, 'granted', 'active', 0, 0, NULL),
  (205, NULL, 105, 33, 'granted', 'active', 0, 0, NULL),
  (206, 137, 163, 137, 'owner', 'active', 163, 1, NULL),
  (207, 213, 163, 213, 'owner', 'active', 163, 1, NULL),
  (208, 132, 166, 132, 'owner', 'active', 166, 1, NULL),
  (209, 135, 166, 135, 'owner', 'active', 166, 1, NULL),
  (210, 131, 168, 131, 'owner', 'active', 168, 1, NULL),
  (211, 133, 168, 133, 'owner', 'active', 168, 1, NULL),
  (212, 182, 168, 182, 'owner', 'active', 168, 1, NULL);

${coreTableSql}
${largeRepairFixtureSql}
${userScopedTableSql}
${parentScopedTableSql}
${ownerTenantOnlyTableSql}
`;

let databaseCreated = false;
try {
  const serverVersion = runMysql({ input: 'SELECT VERSION();\n', label: 'MariaDB version lookup' })
    .split(/\r?\n/)
    .filter(Boolean)
    .at(-1) || 'unknown';
  const isMariaDb1011 = /^10\.11\..*MariaDB/i.test(serverVersion);
  const isExplicitMariaDb104Smoke = compatibilitySmokeVersion === '10.4'
    && /^10\.4\..*MariaDB/i.test(serverVersion);
  if (!isMariaDb1011 && !isExplicitMariaDb104Smoke) {
    throw new Error(`Tenant history verifier requires MariaDB 10.11, received ${serverVersion}`);
  }

  const existingDatabaseCount = queryScalar(
    `SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '${databaseName}';`,
    'dedicated database absence check',
  );
  if (existingDatabaseCount !== 0) {
    throw new Error(`Dedicated database already exists and will not be overwritten: ${databaseName}`);
  }

  runMysql({
    input: `CREATE DATABASE \`${databaseName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n`,
    label: 'create dedicated tenant upgrade database',
  });
  databaseCreated = true;
  runMysql({ database: databaseName, input: historicalSchema, label: 'seed historical tenant schema' });

  const migrationSql = [
    tenantFoundationMigrationPath,
    batchedTenantHistoryRepairMigrationPath,
    tenantHistoryRepairMigrationPath,
    grantedUserContextRepairMigrationPath,
    ownerTenantStandardizationMigrationPath,
    vip021OwnerTenantStandardizationMigrationPath,
  ]
    .map((migrationPath) => readFileSync(migrationPath, 'utf8'))
    .join('\n');
  runMysql({ database: databaseName, input: migrationSql, label: 'run tenant decoupling migration' });
  runMysql({ database: databaseName, input: migrationSql, label: 'repeat tenant decoupling migration' });

  const tableEvidence = {};
  for (const [table, scopeColumn] of Object.entries(scopeColumns)) {
    const extraFixtureRows = table === largeRepairFixtureTable ? largeRepairFixtureRows : 0;
    const expectedTotalRows = 4 + extraFixtureRows;
    const expectedRemappedRows = 2 + extraFixtureRows;
    const totalRows = queryScalar(`SELECT COUNT(*) FROM \`${table}\`;`, `${table} total rows`, databaseName);
    const remappedRows = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`${scopeColumn}\` IN (11, 22) AND \`tenant_id\` = 501;`,
      `${table} remapped rows`,
      databaseName,
    );
    const unmatchedRows = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`${scopeColumn}\` = 999 AND \`tenant_id\` = 999;`,
      `${table} unmatched row preservation`,
      databaseName,
    );
    const preservedPrimaryKeys = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`id\` IN (1, 2, 3);`,
      `${table} primary key preservation`,
      databaseName,
    );
    const ownerTenantRows = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`id\` = 4 AND \`${scopeColumn}\` = 213 AND \`tenant_id\` = 137;`,
      `${table} owner tenant consolidation`,
      databaseName,
    );
    if (totalRows !== expectedTotalRows
      || remappedRows !== expectedRemappedRows
      || unmatchedRows !== 1
      || preservedPrimaryKeys !== 3
      || ownerTenantRows !== 1) {
      throw new Error(
        `${table} remap mismatch: total=${totalRows}, remapped=${remappedRows}, unmatched=${unmatchedRows}, primary_keys=${preservedPrimaryKeys}, owner_tenant=${ownerTenantRows}`,
      );
    }
    tableEvidence[table] = {
      total_rows: totalRows,
      remapped_rows: remappedRows,
      unmatched_rows: unmatchedRows,
      primary_keys_preserved: preservedPrimaryKeys,
      owner_tenant_rows: ownerTenantRows,
    };
  }

  for (const [table, scopeColumn] of Object.entries(userScopeColumns)) {
    const totalRows = queryScalar(`SELECT COUNT(*) FROM \`${table}\`;`, `${table} total rows`, databaseName);
    const remappedRows = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`${scopeColumn}\` IN (101, 102) AND \`tenant_id\` = 501;`,
      `${table} remapped rows`,
      databaseName,
    );
    const unmatchedRows = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`${scopeColumn}\` = 103 AND \`tenant_id\` = 999;`,
      `${table} unmatched row preservation`,
      databaseName,
    );
    const preservedPrimaryKeys = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`id\` IN (1, 2, 3);`,
      `${table} primary key preservation`,
      databaseName,
    );
    const ownerTenantRows = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`id\` = 4 AND \`${scopeColumn}\` = 163 AND \`tenant_id\` = 137;`,
      `${table} owner tenant consolidation`,
      databaseName,
    );
    if (totalRows !== 4
      || remappedRows !== 2
      || unmatchedRows !== 1
      || preservedPrimaryKeys !== 3
      || ownerTenantRows !== 1) {
      throw new Error(
        `${table} remap mismatch: total=${totalRows}, remapped=${remappedRows}, unmatched=${unmatchedRows}, primary_keys=${preservedPrimaryKeys}, owner_tenant=${ownerTenantRows}`,
      );
    }
    tableEvidence[table] = {
      total_rows: totalRows,
      remapped_rows: remappedRows,
      unmatched_rows: unmatchedRows,
      primary_keys_preserved: preservedPrimaryKeys,
      owner_tenant_rows: ownerTenantRows,
    };
  }

  for (const table of ownerTenantOnlyTables) {
    const totalRows = queryScalar(`SELECT COUNT(*) FROM \`${table}\`;`, `${table} total rows`, databaseName);
    const ownerTenantRows = queryScalar(
      `SELECT COUNT(*) FROM \`${table}\` WHERE \`id\` = 1 AND \`tenant_id\` = 137;`,
      `${table} owner tenant consolidation`,
      databaseName,
    );
    if (totalRows !== 1 || ownerTenantRows !== 1) {
      throw new Error(`${table} owner tenant mismatch: total=${totalRows}, owner_tenant=${ownerTenantRows}`);
    }
    tableEvidence[table] = {
      total_rows: totalRows,
      remapped_rows: 0,
      unmatched_rows: 0,
      primary_keys_preserved: 1,
      owner_tenant_rows: ownerTenantRows,
    };
  }

  const evidenceRows = queryScalar(
    'SELECT COUNT(*) FROM operation_execution_evidence WHERE task_id IN (1, 2) AND tenant_id = 501;',
    'operation execution evidence remapped rows',
    databaseName,
  );
  const unmatchedEvidenceRows = queryScalar(
    'SELECT COUNT(*) FROM operation_execution_evidence WHERE task_id = 3 AND tenant_id = 999;',
    'operation execution evidence unmatched row preservation',
    databaseName,
  );
  const ownerTenantEvidenceRows = queryScalar(
    'SELECT COUNT(*) FROM operation_execution_evidence WHERE task_id = 4 AND tenant_id = 137;',
    'operation execution evidence owner tenant consolidation',
    databaseName,
  );
  if (evidenceRows !== 2 || unmatchedEvidenceRows !== 1 || ownerTenantEvidenceRows !== 1) {
    throw new Error(
      `operation_execution_evidence remap mismatch: remapped=${evidenceRows}, unmatched=${unmatchedEvidenceRows}, owner_tenant=${ownerTenantEvidenceRows}`,
    );
  }
  tableEvidence.operation_execution_evidence = {
    total_rows: 4,
    remapped_rows: evidenceRows,
    unmatched_rows: unmatchedEvidenceRows,
    primary_keys_preserved: 3,
    owner_tenant_rows: ownerTenantEvidenceRows,
  };

  const hotelRows = queryScalar('SELECT COUNT(*) FROM hotels WHERE tenant_id = 501;', 'hotel tenant rows', databaseName);
  const tenantRows = queryScalar('SELECT COUNT(*) FROM tenants WHERE id = 501;', 'tenant foundation row', databaseName);
  const userRows = queryScalar('SELECT COUNT(*) FROM users WHERE tenant_id = 501;', 'user tenant rows', databaseName);
  const permissionRows = queryScalar(
    'SELECT COUNT(*) FROM user_hotel_permissions WHERE tenant_id = 501;',
    'permission tenant rows',
    databaseName,
  );
  const repairedGrantedUserRows = queryScalar(
    'SELECT COUNT(*) FROM users WHERE id = 104 AND tenant_id = 501 AND hotel_id = 11;',
    'single-tenant granted user repair',
    databaseName,
  );
  const ambiguousGrantedUserRows = queryScalar(
    'SELECT COUNT(*) FROM users WHERE id = 105 AND tenant_id IS NULL AND hotel_id IS NULL;',
    'cross-tenant granted user preservation',
    databaseName,
  );
  const consolidatedOwnerUsers = queryScalar(
    `SELECT COUNT(*) FROM users WHERE
      (id = 163 AND tenant_id = 137 AND hotel_id = 137)
      OR (id = 166 AND tenant_id = 132 AND hotel_id = 132)
      OR (id = 168 AND tenant_id = 133 AND hotel_id = 133);`,
    'self-owned portfolio user consolidation',
    databaseName,
  );
  const consolidatedOwnerHotels = queryScalar(
    `SELECT COUNT(*) FROM hotels WHERE
      (owner_user_id = 163 AND created_by = 163 AND tenant_id = 137)
      OR (owner_user_id = 166 AND created_by = 166 AND tenant_id = 132)
      OR (owner_user_id = 168 AND created_by = 168 AND tenant_id = 133);`,
    'self-owned portfolio hotel consolidation',
    databaseName,
  );
  const consolidatedOwnerPermissions = queryScalar(
    `SELECT COUNT(*)
     FROM user_hotel_permissions
     WHERE scope_type = 'owner'
       AND status = 'active'
       AND (
         (user_id = 163 AND tenant_id = 137 AND hotel_id IN (137, 213))
         OR (user_id = 166 AND tenant_id = 132 AND hotel_id IN (132, 135))
         OR (user_id = 168 AND tenant_id = 133 AND hotel_id IN (131, 133, 182))
       );`,
    'self-owned portfolio permission consolidation',
    databaseName,
  );
  const canonicalOwnerPermissions = queryScalar(
    `SELECT COUNT(*)
     FROM user_hotel_permissions
     WHERE is_primary = 1
       AND (
         (user_id = 163 AND hotel_id = 137 AND tenant_id = 137)
         OR (user_id = 166 AND hotel_id = 132 AND tenant_id = 132)
         OR (user_id = 168 AND hotel_id = 133 AND tenant_id = 133)
       );`,
    'self-owned portfolio primary hotel normalization',
    databaseName,
  );
  const disabledSourceTenants = queryScalar(
    'SELECT COUNT(*) FROM tenants WHERE id IN (213, 135, 131, 182) AND status = 0;',
    'merged source tenant retirement',
    databaseName,
  );
  const remainingSourceOwnershipRows = queryScalar(
    `SELECT
      (SELECT COUNT(*) FROM hotels WHERE tenant_id IN (213, 135, 131, 182))
      + (SELECT COUNT(*) FROM users WHERE tenant_id IN (213, 135, 131, 182))
      + (SELECT COUNT(*) FROM user_hotel_permissions WHERE tenant_id IN (213, 135, 131, 182));`,
    'retired source tenant ownership rows',
    databaseName,
  );
  const foreignKeyRows = queryScalar(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'hotels' AND CONSTRAINT_NAME = 'fk_hotels_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY';",
    'hotel tenant foreign key',
    databaseName,
  );
  if (hotelRows !== 2
    || tenantRows !== 1
    || userRows !== 3
    || permissionRows !== 4
    || repairedGrantedUserRows !== 1
    || ambiguousGrantedUserRows !== 1
    || consolidatedOwnerUsers !== 3
    || consolidatedOwnerHotels !== 7
    || consolidatedOwnerPermissions !== 7
    || canonicalOwnerPermissions !== 3
    || disabledSourceTenants !== 4
    || remainingSourceOwnershipRows !== 0
    || foreignKeyRows !== 1) {
    throw new Error(
      `Tenant upgrade mismatch: hotels=${hotelRows}, tenants=${tenantRows}, users=${userRows}, permissions=${permissionRows}, repaired_grant=${repairedGrantedUserRows}, ambiguous_grant=${ambiguousGrantedUserRows}, owner_users=${consolidatedOwnerUsers}, owner_hotels=${consolidatedOwnerHotels}, owner_permissions=${consolidatedOwnerPermissions}, primary_permissions=${canonicalOwnerPermissions}, disabled_sources=${disabledSourceTenants}, remaining_sources=${remainingSourceOwnershipRows}, fk=${foreignKeyRows}`,
    );
  }

  process.stdout.write(`${JSON.stringify({
    ok: true,
    engine: serverVersion,
    verification_mode: isMariaDb1011
      ? 'mariadb-10.11-strict'
      : 'mariadb-10.4-compatibility-smoke',
    mariadb_10_11_strict: isMariaDb1011,
    compatibility_smoke: isExplicitMariaDb104Smoke,
    database: databaseName,
    hotels_same_tenant: hotelRows,
    tenant_rows: tenantRows,
    user_rows_remapped: userRows,
    permission_rows_remapped: permissionRows,
    single_tenant_granted_user_repaired: repairedGrantedUserRows === 1,
    cross_tenant_granted_user_preserved: ambiguousGrantedUserRows === 1,
    self_owned_portfolio_users_consolidated: consolidatedOwnerUsers,
    self_owned_portfolio_hotels_consolidated: consolidatedOwnerHotels,
    self_owned_portfolio_permissions_preserved: consolidatedOwnerPermissions,
    self_owned_portfolio_primary_hotels: canonicalOwnerPermissions,
    retired_source_tenants: disabledSourceTenants,
    retired_source_ownership_rows: remainingSourceOwnershipRows,
    core_tables: tableEvidence,
    unmatched_rows_preserved: true,
    primary_keys_preserved: true,
    large_repair_fixture_rows: largeRepairFixtureRows,
    migration_runs: 2,
  })}\n`);
} finally {
  if (databaseCreated) {
    runMysql({
      input: `DROP DATABASE IF EXISTS \`${databaseName}\`;\n`,
      label: 'drop dedicated tenant upgrade database',
    });
  }
}
