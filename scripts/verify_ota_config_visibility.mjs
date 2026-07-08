import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const sourceFiles = [
  path.join(root, 'app', 'controller', 'OnlineData.php'),
  path.join(root, 'app', 'controller', 'concern', 'OtaConfigConcern.php'),
  path.join(root, 'app', 'controller', 'concern', 'OnlineDataRequestConcern.php'),
  path.join(root, 'app', 'controller', 'concern', 'MeituanConfigConcern.php'),
];
const source = sourceFiles.map((sourcePath) => fs.readFileSync(sourcePath, 'utf8')).join('\n');

const checks = [
  {
    name: 'shared visibility helper exists',
    pass: /private function filterOtaConfigListForCurrentUser\s*\(/.test(source),
  },
  {
    name: 'visibility checks owner user_id',
    pass: /user_id[\s\S]{0,220}currentUser->id/.test(source),
  },
  {
    name: 'visibility checks permitted hotel ids',
    pass: /getPermittedHotelIds\s*\(\)/.test(source)
      && /system_hotel_id/.test(source)
      && /hotel_id/.test(source),
  },
  {
    name: 'visibility accepts legacy hotel_id-only owner mapping',
    pass: /foreach\s*\(\s*\[\s*['"]system_hotel_id['"]\s*,\s*['"]hotel_id['"]\s*\]\s+as\s+\$hotelIdField\s*\)/.test(source),
  },
  {
    name: 'ctrip list uses shared visibility helper',
    pass: /getCtripConfigList[\s\S]*filterOtaConfigListForCurrentUser\s*\(\$list\)/.test(source),
  },
  {
    name: 'meituan list uses shared visibility helper',
    pass: /getMeituanConfigList[\s\S]*filterOtaConfigListForCurrentUser\s*\(\$list\)/.test(source),
  },
  {
    name: 'ctrip sync update accepts hotel-visible existing config',
    pass: /saveCtripConfig[\s\S]*isOtaConfigVisibleToCurrentUser\s*\(\$list\[\$id\]\)/.test(source),
  },
  {
    name: 'meituan sync update accepts hotel-visible existing config',
    pass: /saveMeituanConfigItem[\s\S]*isOtaConfigVisibleToCurrentUser\s*\(\$list\[\$id\]\)/.test(source),
  },
  {
    name: 'meituan super admin edit preserves existing owner',
    pass: /saveMeituanConfigItem[\s\S]*\$originalConfig\s*=\s*is_array\(\$list\[\$id\]\s*\?\?\s*null\)[\s\S]*\$userId\s*=\s*\$this->currentUser->isSuperAdmin\(\)\s*\?\s*\(\$originalConfig\['user_id'\]\s*\?\?\s*null\)\s*:\s*\$this->currentUser->id/.test(source),
  },
  {
    name: 'meituan config save refuses unsafe JSON encoding',
    pass: /saveMeituanConfigItem[\s\S]*json_encode\(\$list,\s*JSON_UNESCAPED_UNICODE\)[\s\S]*\$encoded\s*===\s*false[\s\S]*配置保存失败/.test(source)
      && !/JSON_INVALID_UTF8_SUBSTITUTE/.test(source),
  },
];

const failed = checks.filter(check => !check.pass);
if (failed.length > 0) {
  console.error('OTA config visibility verification failed:');
  failed.forEach(check => console.error(`- ${check.name}`));
  process.exit(1);
}

console.log('OTA config visibility verification passed');
