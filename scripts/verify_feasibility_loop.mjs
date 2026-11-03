import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readRouteContractSource } from './lib/route_contract_source.mjs';

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
      'public function regenerate(int $id, int $userId, bool $isSuperAdmin, array $updatedInput = []): ?array',
      'public function detail(int $id, int $userId, bool $isSuperAdmin): ?array',
      'public function list(int $page = 1, int $pageSize = 10, int $userId = 0, bool $isSuperAdmin = false): array',
      'public function archive(int $id, int $userId, bool $isSuperAdmin): bool',
      'public function buildFeasibilityReadiness(array $input, array $snapshot, array $report): array',
      'public function readinessSummaryFromRows(array $rows): array',
      '$tenantId = $this->tenantIdForUser($userId)',
      "'feasibility_readiness' => $readiness",
      'private function applyTenantScope',
      "where('tenant_id', $tenantId)",
      'public function executionHotelId(array $record): int',
      'public function assertExecutionHotelMatches(array $record, int $hotelId): int',
      'feasibility report hotel scope missing',
      'feasibility report hotel scope conflict',
      'feasibility report hotel scope mismatch',
      "'source_snapshot_digest' => SourceBackedExecutionIntentIdentityService::snapshotDigest('feasibility_report'",
      'private function financialScenariosReady',
      'private function normalizeReportFinancialScenarios',
      "'metric_truth'",
      "'truth_context'",
      "'input_truth_context'",
      "'ota_truth_context'",
      "'source_scope' => $this->feasibilitySourceScope($snapshot)",
      "'recommendation_quality'",
      'AiDecisionQualityService',
      'private function tenantIdForUser',
      "DatabaseSchemaRequirement::assertTableColumns('feasibility_reports'",
      "'id', 'tenant_id', 'project_name'",
      "->where('created_by', $userId)",
    ],
  },
  {
    file: 'app/service/DatabaseSchemaRequirement.php',
    contains: [
      'Read-only schema assertions for business services',
      'run php think db:migrate',
    ],
  },
  {
    file: 'app/model/FeasibilityReport.php',
    contains: [
      "'tenant_id' => 'integer'",
    ],
  },
  {
    file: 'database/migrations/20260511_create_missing_business_tables.sql',
    contains: [
      '`tenant_id` INT UNSIGNED DEFAULT NULL',
      '`idx_feasibility_reports_tenant_user` (`tenant_id`, `created_by`, `id`)',
      '`idx_competitor_hotel_tenant_store` (`tenant_id`, `store_id`, `status`)',
    ],
  },
  {
    file: 'resources/frontend/app-template.html',
    absent: [
      'aiFeasibilityRecords',
      'loadFeasibilityDetail',
      'reuseFeasibilityRecord',
      'archiveFeasibilityRecord',
      'aiFeasibilityReadiness',
      'feasibilityReadinessBadgeClass',
    ],
  },
  {
    file: 'public/app-main.js',
    absent: [
      'loadFeasibilityRecords',
      'loadFeasibilityDetail',
      'reuseFeasibilityRecord',
      'archiveFeasibilityRecord',
      'aiFeasibilityReadiness',
      'feasibilityReadinessBadgeClass',
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

  const content = check.file === 'route/app.php' ? readRouteContractSource(root) : readFileSync(path, 'utf8');
  for (const needle of check.contains || []) {
    if (!content.includes(needle)) {
      failures.push(`${check.file} missing contract: ${needle}`);
    }
  }
  for (const needle of check.absent || []) {
    if (content.includes(needle)) failures.push(`${check.file} retains retired UI contract: ${needle}`);
  }
}

const routes = readRouteContractSource(root);
const feasibilityWrites = routes.split('\n').filter(line => /Route::(?:post|delete|put|patch)\('\/feasibility-report/.test(line));
if (feasibilityWrites.length !== 4) failures.push('all four retired feasibility mutations must remain registered for deterministic 410 responses');
for (const line of feasibilityWrites) if (!line.includes('RetiredFeatureReadOnly::class')) failures.push('feasibility mutation lacks retired middleware: ' + line.trim());
const retiredMiddleware = readFileSync(join(root, 'app/middleware/RetiredFeatureReadOnly.php'), 'utf8');
for (const needle of ["'code' => 410", "'status' => 'retired_read_only'", "'history_preserved' => true"]) if (!retiredMiddleware.includes(needle)) failures.push('retired response contract missing: ' + needle);
const feasibilityService = readFileSync(join(root, 'app/service/FeasibilityReportService.php'), 'utf8');
for (const method of ['generate', 'regenerate', 'archive']) {
  const body = extractMethod(feasibilityService, method);
  if (!body.includes("throw new \\RuntimeException('retired_read_only', 410)")) failures.push('retired service mutation must fail before generation or persistence: ' + method);
}

const agentPath = join(root, 'app/controller/Agent.php');
if (existsSync(agentPath)) {
  const agentContent = readFileSync(agentPath, 'utf8');
  for (const method of ['feasibilityReportGenerate', 'feasibilityReportDetail', 'feasibilityReportRegenerate', 'feasibilityReportList', 'feasibilityReportArchive', 'createFeasibilityExecutionIntent']) {
    const body = extractMethod(agentContent, method);
    if (!body) failures.push(`Agent::${method} missing`);
    if (body.includes('$this->checkAdmin();')) {
      failures.push(`Agent::${method} must not require super admin`);
    }
    const isHistoricalRead = ['feasibilityReportDetail', 'feasibilityReportList'].includes(method);
    if (isHistoricalRead && !body.includes('$this->checkLogin();')) {
      failures.push(`Agent::${method} must require login`);
    }
    if (!isHistoricalRead && (!body.includes('if (!$this->currentUser)') || !body.includes("$this->error('请先登录', 401)") || !body.includes("RetiredFeatureReadOnly::response('可研报告')"))) failures.push(`Agent::${method} must return 401 before its retired 410 response`);
    if (isHistoricalRead && !body.includes('$this->currentUser->isSuperAdmin()')) failures.push(`Agent::${method} must pass its authenticated owner/super-admin scope to history service`);
  }
}

if (failures.length > 0) {
  console.error(`Feasibility loop verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Feasibility loop verification passed.');
