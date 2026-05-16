import { readFileSync } from 'node:fs';

const backend = readFileSync('app/controller/OnlineData.php', 'utf8');
const frontend = readFileSync('public/index.html', 'utf8');

const checks = [
  {
    name: 'backend has hotel match normalizer',
    ok: backend.includes('normalizeOtaConfigHotelBinding'),
  },
  {
    name: 'backend has persisted list normalizer',
    ok: backend.includes('normalizeStoredOtaConfigList'),
  },
  {
    name: 'Ctrip list read normalizes historic configs',
    ok: /normalizeStoredOtaConfigList\(\s*['"]system_configs['"]\s*,\s*\$key\s*,\s*\$list\s*,\s*['"]ctrip['"]/.test(backend),
  },
  {
    name: 'Meituan list read normalizes historic configs',
    ok: /normalizeStoredOtaConfigList\(\s*['"]system_config['"]\s*,\s*\$key\s*,\s*\$list\s*,\s*['"]meituan['"]/.test(backend),
  },
  {
    name: 'Ctrip save path binds hotel by config name',
    ok: /saveCtripConfig\(\)[\s\S]*normalizeOtaConfigHotelBinding\(\$config,\s*['"]ctrip['"]\)/.test(backend),
  },
  {
    name: 'Ctrip bookmark path binds hotel by config name',
    ok: /saveCtripConfigByBookmark\(\)[\s\S]*normalizeOtaConfigHotelBinding\(\$config,\s*['"]ctrip['"]\)/.test(backend),
  },
  {
    name: 'Meituan save path binds hotel by config name',
    ok: /saveMeituanConfigItem\(\)[\s\S]*normalizeOtaConfigHotelBinding\(\$config,\s*['"]meituan['"]\)/.test(backend),
  },
  {
    name: 'hotel management still matches by persisted hotel ids',
    ok: frontend.includes('findCtripConfigByHotelId') && frontend.includes('item.hotel_id || item.system_hotel_id'),
  },
];

const failed = checks.filter((item) => !item.ok);

for (const check of checks) {
  console.log(`${check.ok ? 'PASS' : 'FAIL'} ${check.name}`);
}

if (failed.length > 0) {
  process.exit(1);
}
