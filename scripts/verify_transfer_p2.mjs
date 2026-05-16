import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

function extractPhpMethod(content, methodName) {
  const marker = `function ${methodName}(`;
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

const checks = [
  {
    file: 'route/app.php',
    contains: [
      "Route::get('/source', 'TransferDecision/source')",
      "Route::get('/records/:id', 'TransferDecision/detail')",
      "Route::delete('/records/:id', 'TransferDecision/archive')",
      "Route::get('/records', 'TransferDecision/records')",
    ],
  },
  {
    file: 'app/controller/TransferDecision.php',
    contains: [
      'public function source(): Response',
      'public function records(): Response',
      'public function detail(int $id): Response',
      'public function archive(int $id): Response',
      "saveRecord('pricing'",
      "saveRecord('timing'",
      "saveRecord('dashboard'",
    ],
  },
  {
    file: 'app/service/TransferDecisionService.php',
    contains: [
      'public function buildSourcePayload(array $hotelIds, ?int $hotelId, string $date): array',
      'public function saveRecord(string $recordType, array $input, array $result, array $snapshot, int $hotelId, int $userId): int',
      'public function records(array $hotelIds, int $userId, bool $isSuperAdmin): array',
      'public function detail(int $id, array $hotelIds, int $userId, bool $isSuperAdmin): array',
      'public function archive(int $id, array $hotelIds, int $userId, bool $isSuperAdmin): bool',
      'CREATE TABLE IF NOT EXISTS transfer_records',
      'daily_reports',
      'online_daily_data',
    ],
  },
  {
    file: 'database/migrations/20260517_create_transfer_records.sql',
    contains: [
      'CREATE TABLE IF NOT EXISTS `transfer_records`',
      '`record_type` VARCHAR(30) NOT NULL',
      '`snapshot_json` JSON DEFAULT NULL',
      '`input_json` JSON DEFAULT NULL',
      '`result_json` JSON DEFAULT NULL',
    ],
  },
  {
    file: 'public/index.html',
    contains: [
      "request('/transfer/source",
      "request('/transfer/records'",
      'loadTransferSource',
      'loadTransferRecords',
      'loadTransferDetail',
      'reuseTransferRecord',
      'archiveTransferRecord',
      'ensureTransferHotelSelected',
      'transferRecords',
    ],
  },
];

const failures = [];
for (const check of checks) {
  const path = join(root, check.file);
  if (!existsSync(path)) {
    failures.push(`${check.file} missing`);
    continue;
  }

  const content = readFileSync(path, 'utf8');
  for (const needle of check.contains) {
    if (!content.includes(needle)) {
      failures.push(`${check.file} missing contract: ${needle}`);
    }
  }
}

const servicePath = join(root, 'app/service/TransferDecisionService.php');
if (existsSync(servicePath)) {
  const serviceContent = readFileSync(servicePath, 'utf8');
  const recordsBody = extractPhpMethod(serviceContent, 'records');
  if (!/->whereIn\(\s*'hotel_id'\s*,\s*\$hotelIds\s*\)/.test(recordsBody)) {
    failures.push('TransferDecisionService::records must apply the resolved hotel_id scope');
  }
  if (/if\s*\(\s*!\$isSuperAdmin\s*\)\s*\{[^}]*->whereIn\(\s*'hotel_id'\s*,\s*\$hotelIds\s*\)/s.test(recordsBody)) {
    failures.push('TransferDecisionService::records must not skip hotel_id filtering for super admins');
  }

  const controllerPath = join(root, 'app/controller/TransferDecision.php');
  const controllerContent = existsSync(controllerPath) ? readFileSync(controllerPath, 'utf8') : '';
  const recordHotelIdBody = extractPhpMethod(controllerContent, 'recordHotelId');
  if (recordHotelIdBody.includes('$hotelIds[0] ?? 0')) {
    failures.push('TransferDecision::recordHotelId must not silently attach manual records to the first permitted hotel');
  }
  if (!recordHotelIdBody.includes('InvalidArgumentException')) {
    failures.push('TransferDecision::recordHotelId must require a selected hotel when no single hotel can be inferred');
  }
}

if (failures.length > 0) {
  console.error(`Transfer P2 contract verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Transfer P2 contract verification passed.');
