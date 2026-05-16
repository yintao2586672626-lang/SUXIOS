import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

const checks = [
  {
    file: 'route/app.php',
    contains: [
      "Route::put('/projects/:id', 'Opening/updateProject')",
      "Route::delete('/projects/:id', 'Opening/archiveProject')",
    ],
  },
  {
    file: 'app/controller/Opening.php',
    contains: [
      'public function updateProject(int $id): Response',
      'public function archiveProject(int $id): Response',
    ],
  },
  {
    file: 'app/service/OpeningService.php',
    contains: [
      'public function updateProject(int $projectId, array $input, array $hotelIds): array',
      'public function archiveProject(int $projectId, array $hotelIds): bool',
      "->where('status', '<>', 'archived')",
    ],
  },
  {
    file: 'app/controller/OperationManagement.php',
    contains: [
      '$this->service->markAlertsRead($ids, $hotelIds)',
    ],
  },
  {
    file: 'app/service/OperationManagementService.php',
    contains: [
      'public function markAlertsRead(array $ids, array $hotelIds): int',
      'private function persistRuleAlerts(array $alerts): array',
      "->whereIn('hotel_id', $hotelIds)",
    ],
  },
  {
    file: 'public/index.html',
    contains: [
      'hotel_id:',
      'saveOpeningProject',
      'archiveOpeningProject',
      '/opening/projects/${selectedOpeningProjectId.value}',
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
  console.error(`P1 contract verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('P1 contract verification passed.');
