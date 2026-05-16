import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

function readText(relativePath) {
  const absolutePath = join(root, relativePath);
  const buffer = readFileSync(absolutePath);
  if (buffer[0] === 0xff && buffer[1] === 0xfe) {
    return buffer.toString('utf16le');
  }
  return buffer.toString('utf8');
}

function extractPhpMethod(content, methodName) {
  const marker = `public function ${methodName}(`;
  const methodIndex = content.indexOf(marker);
  if (methodIndex === -1) {
    return '';
  }

  const bodyStart = content.indexOf('{', methodIndex);
  if (bodyStart === -1) {
    return '';
  }

  let depth = 0;
  for (let i = bodyStart; i < content.length; i += 1) {
    const char = content[i];
    if (char === '{') depth += 1;
    if (char === '}') depth -= 1;
    if (depth === 0) {
      return content.slice(bodyStart + 1, i);
    }
  }

  return '';
}

const failures = [];

const servicePath = 'app/service/TransferDecisionService.php';
if (!existsSync(join(root, servicePath))) {
  failures.push(`${servicePath} missing`);
} else {
  const serviceContent = readText(servicePath);
  const recordsBody = extractPhpMethod(serviceContent, 'records');
  if (!/->whereIn\(\s*'hotel_id'\s*,\s*\$hotelIds\s*\)/.test(recordsBody)) {
    failures.push('Transfer records must apply the resolved hotel_id scope');
  }
  if (/if\s*\(\s*!\$isSuperAdmin\s*\)\s*\{[^}]*->whereIn\(\s*'hotel_id'\s*,\s*\$hotelIds\s*\)/s.test(recordsBody)) {
    failures.push('Transfer records must not skip hotel_id filtering for super admins');
  }
}

const initFull = readText('database/init_full.sql');
if (/SOURCE\s+\.\/database\/hotel_admin_mysql\.sql;/i.test(initFull)) {
  failures.push('database/init_full.sql must not replay the legacy hotel_admin_mysql baseline after the full dump');
}

const dumpBuilder = readText('scripts/build_hotelx_full_dump.ps1');
if (dumpBuilder.includes('"database/hotel_admin_mysql.sql"')) {
  failures.push('build_hotelx_full_dump.ps1 must not concatenate the legacy hotel_admin_mysql baseline into the full dump');
}

if (failures.length > 0) {
  console.error(`Non-security review failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Non-security review passed.');
