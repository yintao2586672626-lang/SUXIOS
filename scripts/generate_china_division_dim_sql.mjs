import fs from 'node:fs';
import path from 'node:path';

const args = new Map();
for (const arg of process.argv.slice(2)) {
  const [key, value] = arg.split('=');
  if (key && value) args.set(key.replace(/^--/, ''), value);
}

const inputDir = args.get('input-dir');
const output = args.get('output') || 'database/migrations/20260603_create_china_division_dim.sql';
const root = process.cwd();

if (!inputDir) {
  throw new Error('Usage: node scripts/generate_china_division_dim_sql.mjs --input-dir=<raw-dist-dir> [--output=<sql-file>]');
}

function readJson(name) {
  const file = path.join(inputDir, `${name}.json`);
  if (!fs.existsSync(file)) {
    throw new Error(`Missing source file: ${file}`);
  }
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function sql(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "''");
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const provinces = readJson('provinces');
const cities = readJson('cities');
const areas = readJson('areas');

const provinceCodes = new Set(provinces.map((item) => item.code));
const cityCodes = new Set(cities.map((item) => item.code));

assert(provinces.length === 31, `Expected 31 provinces, got ${provinces.length}`);
assert(cities.length === 342, `Expected 342 cities, got ${cities.length}`);
assert(areas.length === 2978, `Expected 2978 districts, got ${areas.length}`);

const rows = [];

for (const item of provinces) {
  assert(/^\d{2}$/.test(item.code), `Invalid province code: ${item.code}`);
  rows.push({
    code: item.code,
    name: item.name,
    level: 'province',
    parentCode: '',
  });
}

for (const item of cities) {
  assert(/^\d{4}$/.test(item.code), `Invalid city code: ${item.code}`);
  assert(provinceCodes.has(item.provinceCode), `City ${item.code} has missing province ${item.provinceCode}`);
  rows.push({
    code: item.code,
    name: item.name,
    level: 'city',
    parentCode: item.provinceCode,
  });
}

for (const item of areas) {
  assert(/^\d{6}$/.test(item.code), `Invalid district code: ${item.code}`);
  assert(cityCodes.has(item.cityCode), `District ${item.code} has missing city ${item.cityCode}`);
  assert(provinceCodes.has(item.provinceCode), `District ${item.code} has missing province ${item.provinceCode}`);
  rows.push({
    code: item.code,
    name: item.name,
    level: 'district',
    parentCode: item.cityCode,
  });
}

const source = 'modood/Administrative-divisions-of-China';
const sourceVersion = '2023-statistical-division-code';
const sourceCutoffDate = '2023-06-30';

const lines = [
  '-- China administrative division dimension.',
  '-- Source: modood/Administrative-divisions-of-China dist/provinces.json, dist/cities.json, dist/areas.json.',
  '-- Scope: province/city/district only. Do not use as OTA operating facts or whole-hotel metrics.',
  '-- Source status: upstream project no longer updates; data cutoff date is 2023-06-30.',
  '-- Row counts: provinces=31, cities=342, districts=2978, total=3351.',
  '',
  'CREATE TABLE IF NOT EXISTS `dim_china_divisions` (',
  '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,',
  "  `code` VARCHAR(12) NOT NULL COMMENT '行政区划代码：省2位/市4位/区县6位',",
  "  `name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '行政区划名称',",
  "  `level` ENUM('province','city','district') NOT NULL COMMENT '层级：province/city/district',",
  "  `parent_code` VARCHAR(12) NOT NULL DEFAULT '' COMMENT '父级行政区划代码，省级为空',",
  "  `source` VARCHAR(120) NOT NULL DEFAULT 'modood/Administrative-divisions-of-China' COMMENT '数据来源',",
  "  `source_version` VARCHAR(80) NOT NULL DEFAULT '2023-statistical-division-code' COMMENT '来源版本',",
  "  `source_cutoff_date` DATE NOT NULL DEFAULT '2023-06-30' COMMENT '来源数据截止日期',",
  '  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'当前版本是否有效\',',
  '  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
  '  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,',
  '  PRIMARY KEY (`id`),',
  '  UNIQUE KEY `uk_dim_china_divisions_code` (`code`),',
  '  KEY `idx_dim_china_divisions_parent` (`parent_code`, `level`),',
  '  KEY `idx_dim_china_divisions_level_name` (`level`, `name`)',
  ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='中国省市区县三级行政区划维表';",
  '',
  'INSERT INTO `dim_china_divisions` (`code`,`name`,`level`,`parent_code`,`source`,`source_version`,`source_cutoff_date`,`is_active`) VALUES',
  ...rows.map((row, index) => (
    `('${sql(row.code)}','${sql(row.name)}','${row.level}','${sql(row.parentCode)}','${source}','${sourceVersion}','${sourceCutoffDate}',1)${index === rows.length - 1 ? '' : ','}`
  )),
  'ON DUPLICATE KEY UPDATE',
  '  `name` = VALUES(`name`),',
  '  `level` = VALUES(`level`),',
  '  `parent_code` = VALUES(`parent_code`),',
  '  `source` = VALUES(`source`),',
  '  `source_version` = VALUES(`source_version`),',
  '  `source_cutoff_date` = VALUES(`source_cutoff_date`),',
  '  `is_active` = VALUES(`is_active`),',
  '  `updated_at` = CURRENT_TIMESTAMP;',
  '',
];

const outputPath = path.join(root, output);
fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${lines.join('\n')}\n`, 'utf8');
console.log(`Generated ${output} with ${rows.length} rows.`);
