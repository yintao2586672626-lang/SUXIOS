import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

function extractMethod(content, methodName) {
  const marker = `function ${methodName}(`;
  const start = content.indexOf(marker);
  if (start === -1) return '';
  const bodyStart = content.indexOf('{', start);
  if (bodyStart === -1) return '';
  let depth = 0;
  for (let i = bodyStart; i < content.length; i += 1) {
    if (content[i] === '{') depth += 1;
    if (content[i] === '}') depth -= 1;
    if (depth === 0) return content.slice(bodyStart + 1, i);
  }
  return '';
}

const checks = [
  {
    file: 'route/app.php',
    contains: [
      "Route::post('/feasibility-report/generate', 'Agent/feasibilityReportGenerate')",
      "Route::delete('/feasibility-report/:id', 'Agent/feasibilityReportArchive')",
      "Route::get('/feasibility-report/detail/:id', 'Agent/feasibilityReportDetail')",
      "Route::get('/feasibility-report/list', 'Agent/feasibilityReportList')",
    ],
  },
  {
    file: 'app/service/FeasibilityReportService.php',
    contains: [
      'public function regenerate(int $id, int $userId, bool $isSuperAdmin): ?array',
      'public function detail(int $id, int $userId, bool $isSuperAdmin): ?array',
      'public function list(int $page = 1, int $pageSize = 10, int $userId = 0, bool $isSuperAdmin = false): array',
      'public function archive(int $id, int $userId, bool $isSuperAdmin): bool',
      "->where('created_by', $userId)",
    ],
  },
  {
    file: 'public/index.html',
    contains: [
      'aiFeasibilityRecords',
      'loadFeasibilityRecords',
      'loadFeasibilityDetail',
      'reuseFeasibilityRecord',
      'archiveFeasibilityRecord',
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

const agentPath = join(root, 'app/controller/Agent.php');
if (existsSync(agentPath)) {
  const agentContent = readFileSync(agentPath, 'utf8');
  for (const method of ['feasibilityReportGenerate', 'feasibilityReportDetail', 'feasibilityReportRegenerate', 'feasibilityReportList', 'feasibilityReportArchive']) {
    const body = extractMethod(agentContent, method);
    if (!body) failures.push(`Agent::${method} missing`);
    if (body.includes('$this->checkAdmin();')) {
      failures.push(`Agent::${method} must not require super admin`);
    }
    if (!body.includes('$this->checkLogin();')) {
      failures.push(`Agent::${method} must require login`);
    }
  }
}

if (failures.length > 0) {
  console.error(`Feasibility loop verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Feasibility loop verification passed.');
