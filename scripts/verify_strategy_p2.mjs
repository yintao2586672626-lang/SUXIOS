import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

const checks = [
  {
    file: 'route/app.php',
    contains: [
      "Route::post('/simulate', 'StrategySimulation/simulate')",
      "Route::delete('/records/:id', 'StrategySimulation/archive')",
      "Route::get('/records/:id', 'StrategySimulation/detail')",
      "Route::get('/records', 'StrategySimulation/records')",
    ],
  },
  {
    file: 'app/controller/StrategySimulation.php',
    contains: [
      'public function records(): Response',
      'public function detail(int $id): Response',
      'public function archive(int $id): Response',
      'private function formatRecord(array $row, bool $withDetail): array',
      "->whereNull('deleted_at')",
      "->where('created_by', (int)($this->currentUser->id ?? 0))",
    ],
  },
  {
    file: 'public/index.html',
    contains: [
      "request('/strategy/records'",
      'aiStrategyRecords',
      'aiStrategyRecordId',
      'loadStrategyRecords',
      'loadStrategyDetail',
      'reuseStrategyRecord',
      'archiveStrategyRecord',
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
  console.error(`Strategy P2 contract verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Strategy P2 contract verification passed.');
