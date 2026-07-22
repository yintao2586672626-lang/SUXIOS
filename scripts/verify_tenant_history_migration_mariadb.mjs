import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const projectRoot = resolve(import.meta.dirname, '..');
const migrationPath = resolve(
  projectRoot,
  'database/migrations/20260722_create_tenants_and_decouple_hotel_scope.sql',
);
const mysqlBinary = process.env.MYSQL_BINARY || 'mariadb';
const databaseHost = process.env.DB_HOST || '127.0.0.1';
const databasePort = process.env.DB_PORT || '3306';
const databaseUser = process.env.DB_USER || 'root';
const databasePassword = process.env.DB_PASS || '';
const compatibilitySmokeVersion = process.env.SUXIOS_TENANT_MARIADB_COMPAT_SMOKE || '';
const databaseName = `suxios_tenant_upgrade_test_${process.pid}_${randomBytes(4).toString('hex')}`;

if (!existsSync(migrationPath)) {
  throw new Error(`Tenant migration is missing: ${migrationPath}`);
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
};

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
  (3, 999, 999);
`).join('\n');

const historicalSchema = `
CREATE TABLE \`hotels\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned NOT NULL DEFAULT 0,
  \`name\` varchar(120) NOT NULL,
  \`status\` tinyint unsigned NOT NULL DEFAULT 1,
  \`create_time\` datetime DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;

CREATE TABLE \`users\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  \`hotel_id\` int unsigned DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;

CREATE TABLE \`user_hotel_permissions\` (
  \`id\` int unsigned NOT NULL,
  \`tenant_id\` int unsigned DEFAULT NULL,
  \`user_id\` int unsigned NOT NULL,
  \`hotel_id\` int unsigned NOT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB;

INSERT INTO \`hotels\` (\`id\`, \`tenant_id\`, \`name\`, \`status\`, \`create_time\`) VALUES
  (11, 501, 'Tenant A hotel 1', 1, '2026-05-29 10:00:00'),
  (22, 501, 'Tenant A hotel 2', 1, '2026-05-29 11:00:00');
INSERT INTO \`users\` (\`id\`, \`tenant_id\`, \`hotel_id\`) VALUES
  (101, 11, 11),
  (102, 22, 22);
INSERT INTO \`user_hotel_permissions\` (\`id\`, \`tenant_id\`, \`user_id\`, \`hotel_id\`) VALUES
  (201, 11, 101, 11),
  (202, 22, 102, 22);

${coreTableSql}
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

  const migrationSql = readFileSync(migrationPath, 'utf8');
  runMysql({ database: databaseName, input: migrationSql, label: 'run tenant decoupling migration' });
  runMysql({ database: databaseName, input: migrationSql, label: 'repeat tenant decoupling migration' });

  const tableEvidence = {};
  for (const [table, scopeColumn] of Object.entries(scopeColumns)) {
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
    if (totalRows !== 3 || remappedRows !== 2 || unmatchedRows !== 1 || preservedPrimaryKeys !== 3) {
      throw new Error(
        `${table} remap mismatch: total=${totalRows}, remapped=${remappedRows}, unmatched=${unmatchedRows}, primary_keys=${preservedPrimaryKeys}`,
      );
    }
    tableEvidence[table] = {
      total_rows: totalRows,
      remapped_rows: remappedRows,
      unmatched_rows: unmatchedRows,
      primary_keys_preserved: preservedPrimaryKeys,
    };
  }

  const hotelRows = queryScalar('SELECT COUNT(*) FROM hotels WHERE tenant_id = 501;', 'hotel tenant rows', databaseName);
  const tenantRows = queryScalar('SELECT COUNT(*) FROM tenants WHERE id = 501;', 'tenant foundation row', databaseName);
  const userRows = queryScalar('SELECT COUNT(*) FROM users WHERE tenant_id = 501;', 'user tenant rows', databaseName);
  const permissionRows = queryScalar(
    'SELECT COUNT(*) FROM user_hotel_permissions WHERE tenant_id = 501;',
    'permission tenant rows',
    databaseName,
  );
  const foreignKeyRows = queryScalar(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'hotels' AND CONSTRAINT_NAME = 'fk_hotels_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY';",
    'hotel tenant foreign key',
    databaseName,
  );
  if (hotelRows !== 2 || tenantRows !== 1 || userRows !== 2 || permissionRows !== 2 || foreignKeyRows !== 1) {
    throw new Error(
      `Tenant upgrade mismatch: hotels=${hotelRows}, tenants=${tenantRows}, users=${userRows}, permissions=${permissionRows}, fk=${foreignKeyRows}`,
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
    core_tables: tableEvidence,
    unmatched_rows_preserved: true,
    primary_keys_preserved: true,
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
