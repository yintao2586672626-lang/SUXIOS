import { existsSync, readFileSync } from 'node:fs';

const backendFiles = [
  'app/controller/OnlineData.php',
  'app/controller/concern/OtaConfigConcern.php',
  'app/controller/concern/MeituanConfigConcern.php',
];
const backend = backendFiles
  .filter((file) => existsSync(file))
  .map((file) => readFileSync(file, 'utf8'))
  .join('\n');
const frontend = readFileSync('public/index.html', 'utf8');
const onlineDataRequest = readFileSync('app/controller/concern/OnlineDataRequestConcern.php', 'utf8');

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
    ok: backend.includes("normalizeStoredOtaConfigList('system_configs', 'ctrip_config_list', $list, 'ctrip')"),
  },
  {
    name: 'Meituan list read normalizes historic configs',
    ok: backend.includes("normalizeStoredOtaConfigList('system_config', 'meituan_config_list', $list, 'meituan')"),
  },
  {
    name: 'Ctrip save path requires an explicit immutable system hotel binding',
    ok: onlineDataRequest.includes('strictPositiveOtaConfigHotelId')
      && onlineDataRequest.includes('resolveOnlineDataSystemHotelId($requestedHotelId)')
      && onlineDataRequest.includes('不允许变更已有凭据的系统酒店绑定'),
  },
  {
    name: 'legacy Ctrip bookmark save path is disabled',
    ok: /saveCtripConfigByBookmark\(\): Response[\s\S]*?checkPermission\(\)[\s\S]*?410[\s\S]*?\n\s*}/.test(onlineDataRequest)
      && !/saveCtripConfigByBookmark\(\): Response[\s\S]*?saveCtripConfigPayload\(/.test(onlineDataRequest),
  },
  {
    name: 'Meituan save path requires an explicit immutable system hotel binding',
    ok: backend.includes('meituanRequestedHotelId($requestData)')
      && backend.includes("strictOtaConfigBoundHotelId($originalConfig, 'Meituan')")
      && backend.includes('不允许变更已有凭据的系统酒店绑定'),
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
