import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { extractPhpMethod, readText } from './lib/shared_helpers.mjs';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

const failures = [];

const servicePath = 'app/service/TransferDecisionService.php';
if (!existsSync(join(root, servicePath))) {
  failures.push(`${servicePath} missing`);
} else {
  const serviceContent = readText(servicePath, root);
  const recordsBody = extractPhpMethod(serviceContent, 'records');
  if (!/->whereIn\(\s*'hotel_id'\s*,\s*\$hotelIds\s*\)/.test(recordsBody)) {
    failures.push('Transfer records must apply the resolved hotel_id scope');
  }
  if (/if\s*\(\s*!\$isSuperAdmin\s*\)\s*\{[^}]*->whereIn\(\s*'hotel_id'\s*,\s*\$hotelIds\s*\)/s.test(recordsBody)) {
    failures.push('Transfer records must not skip hotel_id filtering for super admins');
  }
}

const initFull = readText('database/init_full.sql', root);
if (/SOURCE\s+\.\/hotelx_dump\.sql;/i.test(initFull)) {
  failures.push('database/init_full.sql must not depend on ignored local hotelx_dump.sql');
}
if (!/SOURCE\s+\.\/database\/hotel_admin_mysql\.sql;/i.test(initFull)) {
  failures.push('database/init_full.sql must use the tracked sanitized baseline database/hotel_admin_mysql.sql');
}

const dumpBuilder = readText('scripts/build_hotelx_full_dump.ps1', root);
if (dumpBuilder.includes('"hotelx_dump.sql"')) {
  failures.push('build_hotelx_full_dump.ps1 must not concatenate ignored local hotelx_dump.sql into the full dump');
}

if (failures.length > 0) {
  console.error(`Non-security review failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Non-security review passed.');
