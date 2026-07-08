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
];

const failed = checks.filter(check => !check.pass);
if (failed.length > 0) {
  console.error('OTA config visibility verification failed:');
  failed.forEach(check => console.error(`- ${check.name}`));
  process.exit(1);
}

console.log('OTA config visibility verification passed');
