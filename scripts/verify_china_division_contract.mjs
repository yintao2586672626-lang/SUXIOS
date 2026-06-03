import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const failures = [];
const passes = [];

const migrationPath = 'database/migrations/20260603_create_china_division_dim.sql';
const docPath = 'docs/china_division_dim.md';
const initPath = 'database/init_full.sql';
const expectedCounts = {
  province: 31,
  city: 342,
  district: 2978,
};

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function exists(relativePath) {
  return fs.existsSync(path.join(root, relativePath));
}

function fail(message) {
  failures.push(message);
}

function pass(message) {
  passes.push(message);
}

function requireFile(relativePath) {
  if (!exists(relativePath)) {
    fail(`${relativePath} is missing`);
    return false;
  }
  pass(`${relativePath} exists`);
  return true;
}

function requireText(source, needle, label) {
  if (!source.includes(needle)) {
    fail(`missing ${label}: ${needle}`);
    return;
  }
  pass(label);
}

function requireNoText(source, needle, label) {
  if (source.includes(needle)) {
    fail(`forbidden ${label}: ${needle}`);
    return;
  }
  pass(label);
}

function parseSeedRows(source) {
  const rowPattern = /^\('([^']+)','([^']+)','(province|city|district)','([^']*)','([^']+)','([^']+)','([^']+)',1\),?$/gm;
  const rows = [];
  let match;
  while ((match = rowPattern.exec(source)) !== null) {
    rows.push({
      code: match[1],
      name: match[2],
      level: match[3],
      parentCode: match[4],
      source: match[5],
      sourceVersion: match[6],
      sourceCutoffDate: match[7],
    });
  }
  return rows;
}

if (requireFile(initPath)) {
  requireText(
    read(initPath),
    'SOURCE ./database/migrations/20260603_create_china_division_dim.sql;',
    'full init imports china division dim migration'
  );
}

if (requireFile(migrationPath)) {
  const migration = read(migrationPath);
  for (const needle of [
    'CREATE TABLE IF NOT EXISTS `dim_china_divisions`',
    '`code` VARCHAR(12) NOT NULL',
    "`level` ENUM('province','city','district') NOT NULL",
    '`parent_code` VARCHAR(12) NOT NULL DEFAULT',
    '`source` VARCHAR(120) NOT NULL DEFAULT',
    '`source_version` VARCHAR(80) NOT NULL DEFAULT',
    '`source_cutoff_date` DATE NOT NULL',
    '`is_active` TINYINT(1) NOT NULL DEFAULT 1',
    'UNIQUE KEY `uk_dim_china_divisions_code` (`code`)',
    'KEY `idx_dim_china_divisions_parent` (`parent_code`, `level`)',
  ]) {
    requireText(migration, needle, `migration contains ${needle}`);
  }

  for (const forbidden of [
    'streets.json',
    'villages.json',
    "'street'",
    "'village'",
    "'town'",
  ]) {
    requireNoText(migration, forbidden, `non-three-level source ${forbidden}`);
  }

  const rows = parseSeedRows(migration);
  const byCode = new Map(rows.map((row) => [row.code, row]));
  const counts = rows.reduce((acc, row) => {
    acc[row.level] = (acc[row.level] || 0) + 1;
    return acc;
  }, {});
  const expectedTotal = Object.values(expectedCounts).reduce((sum, value) => sum + value, 0);

  if (rows.length !== expectedTotal) {
    fail(`seed row count must be ${expectedTotal}, got ${rows.length}`);
  } else {
    pass(`seed row count is ${expectedTotal}`);
  }

  for (const [level, expected] of Object.entries(expectedCounts)) {
    if ((counts[level] || 0) !== expected) {
      fail(`${level} count must be ${expected}, got ${counts[level] || 0}`);
    } else {
      pass(`${level} count is ${expected}`);
    }
  }

  for (const row of rows) {
    if (row.source !== 'modood/Administrative-divisions-of-China') {
      fail(`${row.code} has unexpected source ${row.source}`);
    }
    if (row.sourceVersion !== '2023-statistical-division-code') {
      fail(`${row.code} has unexpected source version ${row.sourceVersion}`);
    }
    if (row.sourceCutoffDate !== '2023-06-30') {
      fail(`${row.code} has unexpected source cutoff date ${row.sourceCutoffDate}`);
    }
    if (row.level === 'province') {
      if (!/^\d{2}$/.test(row.code) || row.parentCode !== '') {
        fail(`province ${row.code} must use 2-digit code and empty parent`);
      }
    }
    if (row.level === 'city') {
      if (!/^\d{4}$/.test(row.code) || !/^\d{2}$/.test(row.parentCode) || !byCode.has(row.parentCode)) {
        fail(`city ${row.code} must use 4-digit code and existing province parent`);
      }
    }
    if (row.level === 'district') {
      if (!/^\d{6}$/.test(row.code) || !/^\d{4}$/.test(row.parentCode) || !byCode.has(row.parentCode)) {
        fail(`district ${row.code} must use 6-digit code and existing city parent`);
      }
    }
  }
}

if (requireFile(docPath)) {
  const doc = read(docPath);
  for (const needle of [
    '只引入省/市/区县三级',
    '不是 OTA 经营事实源',
    '不进入 ADR、出租率、RevPAR、收益预测公式',
    'source_cutoff_date',
    '2023-06-30',
    'missing / ambiguous / stale_source',
  ]) {
    requireText(doc, needle, `doc contains ${needle}`);
  }
}

if (failures.length) {
  console.error('China division contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log(`China division contract verification passed (${passes.length} checks).`);
