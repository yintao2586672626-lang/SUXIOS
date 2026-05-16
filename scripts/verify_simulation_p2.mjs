import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

const checks = [
  {
    file: 'route/app.php',
    contains: [
      "Route::post('/calculate', 'Simulation/calculate')",
      "Route::delete('/records/:id', 'Simulation/archive')",
      "Route::get('/records/:id', 'Simulation/detail')",
      "Route::get('/records', 'Simulation/records')",
    ],
  },
  {
    file: 'app/controller/Simulation.php',
    contains: [
      'class Simulation extends Base',
      'public function calculate(): Response',
      'public function records(): Response',
      'public function detail(int $id): Response',
      'public function archive(int $id): Response',
    ],
  },
  {
    file: 'app/service/QuantSimulationService.php',
    contains: [
      'class QuantSimulationService',
      'public function calculateAndSave(array $payload, int $userId): array',
      'public function records(int $userId, bool $isSuperAdmin): array',
      'public function detail(int $id, int $userId, bool $isSuperAdmin): array',
      'public function archive(int $id, int $userId, bool $isSuperAdmin): bool',
      'private function calculateSimulation(array $input): array',
      'private function buildScenarios(array $input): array',
    ],
  },
  {
    file: 'database/migrations/20260517_create_quant_simulation_records.sql',
    contains: [
      'CREATE TABLE IF NOT EXISTS `quant_simulation_records`',
      '`input_json` JSON DEFAULT NULL',
      '`result_json` JSON DEFAULT NULL',
      '`scenarios_json` JSON DEFAULT NULL',
      '`risk_hints_json` JSON DEFAULT NULL',
    ],
  },
  {
    file: 'public/index.html',
    contains: [
      "request('/simulation/calculate'",
      'loadSimulationRecords',
      'loadSimulationDetail',
      'reuseSimulationRecord',
      'archiveSimulationRecord',
      'aiSimulationRecords',
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
  console.error(`Simulation P2 contract verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Simulation P2 contract verification passed.');
