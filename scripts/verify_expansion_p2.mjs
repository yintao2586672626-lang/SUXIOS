import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const expansionStaticSource = readFileSync(join(root, 'public/expansion-static-options.js'), 'utf8');

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
      "buildProjectReadiness('market'",
      "buildProjectReadiness('benchmark'",
      "buildProjectReadiness('collaboration'",
    ],
  },
  {
    file: 'app/service/ExpansionService.php',
    contains: [
      'public function buildProjectReadiness(string $recordType, array $input, array $result): array',
      'public function readinessSummaryFromRows(array $rows): array',
      "'project_readiness' => $this->buildProjectReadiness(",
      'public function saveRecord(string $recordType, array $input, array $result, int $userId): int',
      'public function records(int $userId, bool $isSuperAdmin): array',
      'public function detail(int $id, int $userId, bool $isSuperAdmin): array',
      'public function archive(int $id, int $userId, bool $isSuperAdmin): bool',
      "'tenant_id' => $this->tenantIdForUser($userId)",
      'private function applyTenantScope',
      "where('tenant_id', $tenantId)",
      'CREATE TABLE IF NOT EXISTS expansion_records',
      'tenant_id BIGINT UNSIGNED DEFAULT NULL',
      'INDEX idx_expansion_records_tenant_user (tenant_id, created_by, id)',
      'lease_years',
      'rent_free_months',
      'expected_adr',
      'expected_occupancy_rate',
      'primary_customer',
      'secondary_customer',
      'ota_market_penetration_rate',
      'investment_conditions',
    ],
  },
  {
    file: 'database/migrations/20260517_create_expansion_records.sql',
    contains: [
      'CREATE TABLE IF NOT EXISTS `expansion_records`',
      '`tenant_id` BIGINT UNSIGNED DEFAULT NULL',
      '`idx_expansion_records_tenant_user` (`tenant_id`, `created_by`, `id`)',
      '`record_type` VARCHAR(30) NOT NULL',
      '`input_json` JSON DEFAULT NULL',
      '`result_json` JSON DEFAULT NULL',
    ],
  },
  {
    file: 'public/index.html',
    contains: [
      'marketEvaluationCityOptions',
      'marketEvaluationCityTierOptions',
      'filteredMarketEvaluationCityOptions',
      'marketEvaluationConditionFields',
      'marketEvaluationCustomerOptions',
      'marketEvaluationDecorationOptions',
      'v-model="marketEvaluationForm.city_tier"',
      'v-model="marketEvaluationForm.city"',
      'v-for="city in filteredMarketEvaluationCityOptions"',
      'v-for="field in marketEvaluationConditionFields"',
      'primary_customer',
      'secondary_customer',
      'lease_years',
      'rent_free_months',
      'expected_adr',
      'expected_occupancy_rate',
      'competitor_count',
      'ota_market_penetration_rate',
      '四线',
      "request('/expansion/records'",
      'loadExpansionRecords',
      'loadExpansionDetail',
      'reuseExpansionRecord',
      'archiveExpansionRecord',
      'expansionCurrentReadiness',
      'expansionReadinessBadgeClass',
      'expansionReadinessMissingText',
      'project_readiness',
      'market_result',
      'benchmark_result',
      'expansionRecords',
      "requireExpansionStaticOption('buildFeasibilityInputCards')",
      "requireExpansionStaticOption('buildFeasibilityReportCards')",
      "requireExpansionStaticOption('buildFeasibilityAiEmpowerment')",
      "requireExpansionStaticOption('feasibilityDecisionClassForGrade')",
      "requireExpansionStaticOption('stringifyFeasibilityReport')",
      "requireExpansionStaticOption('buildMarketEvaluationAiRiskSuggestions')",
      "requireExpansionStaticOption('buildStrategyScoreCards')",
      "requireExpansionStaticOption('strategyFreshnessLabelForSnapshot')",
      "requireExpansionStaticOption('strategyAiSourceLabelForResult')",
      "requireExpansionStaticOption('strategyAiModelDisplayLabelForSnapshot')",
      "requireExpansionStaticOption('strategyPoiDataSourceLabelForSnapshot')",
      "requireExpansionStaticOption('strategyDataNoticeForSnapshot')",
      "requireExpansionStaticOption('buildStrategyDataSourceRows')",
      "requireExpansionStaticOption('buildStrategyAiEmpowermentCards')",
      'buildFeasibilityInputCards',
      'buildFeasibilityReportCards',
      'buildFeasibilityAiEmpowerment',
      'feasibilityDecisionClassForGrade',
      'stringifyFeasibilityReportText',
      'buildMarketEvaluationAiRiskSuggestions',
      'buildStrategyScoreCards',
      'strategyFreshnessLabelForSnapshot',
      'strategyAiSourceLabelForResult',
      'buildStrategyDataSourceRows',
      'buildStrategyAiEmpowermentCards',
    ],
    absent: [
      'const scores = r.scores || {}',
      'snapshot.ai_search_used && uniqueMissing.some',
      'const evidenceCount = strategyDataSourceRows.value.filter',
      'const normalizeMarketRiskSeverity = (value, index = 0) =>',
      'const inferMarketEvaluationRiskDetail = (item, result, index = 0) =>',
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

  let content = readFileSync(path, 'utf8');
  if (check.file === 'public/index.html') {
    content += '\n' + expansionStaticSource;
  }
  for (const needle of check.contains) {
    if (!content.includes(needle)) {
      failures.push(`${check.file} missing contract: ${needle}`);
    }
  }
  for (const needle of check.absent || []) {
    const targetContent = readFileSync(path, 'utf8');
    if (targetContent.includes(needle)) {
      failures.push(`${check.file} should not inline contract: ${needle}`);
    }
  }
}

try {
  const context = { window: {} };
  vm.runInNewContext(expansionStaticSource, context, {
    filename: 'public/expansion-static-options.js',
  });
  const riskBuilder = context.window.SUXI_EXPANSION_STATIC?.buildMarketEvaluationAiRiskSuggestions;
  if (typeof riskBuilder !== 'function') {
    failures.push('public/expansion-static-options.js missing buildMarketEvaluationAiRiskSuggestions function');
  } else {
    const watchPointSample = riskBuilder({
      result: {
        metrics: { rent_per_room: 2200, rent_per_square: 68 },
        ai_evaluation: {
          watch_points: [
            { metric: 'ADR价格带', threshold: '偏高' },
          ],
        },
      },
      form: {
        expected_adr: 280,
        expected_occupancy_rate: 76,
        competitor_count: 8,
        ota_market_penetration_rate: 61,
      },
    });
    const firstRisk = watchPointSample[0] || {};
    if (!Array.isArray(watchPointSample) || watchPointSample.length !== 1) {
      failures.push('buildMarketEvaluationAiRiskSuggestions should return one watch-point risk');
    }
    if (firstRisk.severity !== 'P0') {
      failures.push('buildMarketEvaluationAiRiskSuggestions should normalize first risk severity to P0');
    }
    if (firstRisk.owner !== '收益管理/投资拓展') {
      failures.push('buildMarketEvaluationAiRiskSuggestions should infer ADR risk owner');
    }
    if (!String(firstRisk.evidence || '').includes('280')) {
      failures.push('buildMarketEvaluationAiRiskSuggestions should keep form evidence in inferred copy');
    }

    const fallbackSample = riskBuilder({
      result: {
        not_recommended_risks: ['租金压力偏高'],
        metrics: { rent_per_room: 2200, rent_per_square: 68 },
      },
      form: {},
    });
    const fallbackRisk = fallbackSample[0] || {};
    if (fallbackRisk.metric !== '风险点' || !fallbackRisk.threshold) {
      failures.push('buildMarketEvaluationAiRiskSuggestions should map not_recommended_risks fallback items');
    }
  }
} catch (error) {
  failures.push(`public/expansion-static-options.js failed runtime validation: ${error.message}`);
}

if (failures.length > 0) {
  console.error(`Expansion P2 contract verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Expansion P2 contract verification passed.');
