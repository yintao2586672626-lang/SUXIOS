import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

const checks = [
  {
    file: 'route/app.php',
    contains: [
      "Route::get('/records/:id', 'Expansion/detail')",
      "Route::delete('/records/:id', 'Expansion/archive')",
      "Route::get('/records', 'Expansion/records')",
    ],
  },
  {
    file: 'app/controller/Expansion.php',
    contains: [
      'public function records(): Response',
      'public function detail(int $id): Response',
      'public function archive(int $id): Response',
      "saveRecord('market'",
      "saveRecord('benchmark'",
      "saveRecord('collaboration'",
    ],
  },
  {
    file: 'app/service/ExpansionService.php',
    contains: [
      'public function saveRecord(string $recordType, array $input, array $result, int $userId): int',
      'public function records(int $userId, bool $isSuperAdmin): array',
      'public function detail(int $id, int $userId, bool $isSuperAdmin): array',
      'public function archive(int $id, int $userId, bool $isSuperAdmin): bool',
      'CREATE TABLE IF NOT EXISTS expansion_records',
    ],
  },
  {
    file: 'database/migrations/20260517_create_expansion_records.sql',
    contains: [
      'CREATE TABLE IF NOT EXISTS `expansion_records`',
      '`record_type` VARCHAR(30) NOT NULL',
      '`input_json` JSON DEFAULT NULL',
      '`result_json` JSON DEFAULT NULL',
    ],
  },
  {
    file: 'public/index.html',
    contains: [
      "request('/expansion/records'",
      'loadExpansionRecords',
      'loadExpansionDetail',
      'reuseExpansionRecord',
      'archiveExpansionRecord',
      'expansionRecords',
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

if (failures.length > 0) {
  console.error(`Expansion P2 contract verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Expansion P2 contract verification passed.');
