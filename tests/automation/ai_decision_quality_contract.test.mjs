import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';
const read = (path) => fs.readFileSync(path, 'utf8');

test('user-facing AI recommendation backends apply the decision quality contract', () => {
  for (const path of [
    'app/controller/Agent.php',
    'app/service/AiDailyReportService.php',
    'app/service/ExpansionService.php',
    'app/service/FeasibilityReportService.php',
    'app/service/OpeningService.php',
    'app/service/QuantSimulationService.php',
    'app/service/RevenuePricingRecommendationService.php',
    'app/service/RevenueResearchService.php',
    'app/service/TransferDecisionService.php',
  ]) {
    assert.match(read(path), /AiDecisionQualityService/, `${path} must apply AiDecisionQualityService`);
  }

  const contract = read('app/service/AiDecisionQualityService.php');
  for (const key of ['priority', 'data_basis', 'expected_effect', 'risk', 'generic_talk_rejected']) {
    assert.match(contract, new RegExp(`['"]${key}['"]`), `quality contract must expose ${key}`);
  }
  assert.match(contract, /CONTRACT_VERSION\s*=\s*['"]ai_recommendation_quality\.v2['"]/);
  assert.match(contract, /can_create_execution_intent['"]\]\s*=\s*\$executionReady/);
  assert.match(contract, /if\s*\(!\$executionReady\)/);
  assert.match(contract, /human_confirmation_required['"]\s*=>\s*true/);
  assert.match(contract, /当前建议不得执行/);
  assert.match(read('public/components/system/app-main-components.js'), /data-testid': 'ai-decision-quality-blocked'[\s\S]*质量门禁：不合格，不可执行/);
});

test('active AI decision surfaces show basis priority action effect and risk', () => {
  const templates = [
    'resources/frontend/templates/fragments/02-page-ai-simulation.html',
    'resources/frontend/templates/fragments/13-page-opening-overview.html',
    'resources/frontend/templates/fragments/16-page-ai-daily-report.html',
    'resources/frontend/templates/fragments/19-page-revenue-research-center.html',
    'resources/frontend/templates/fragments/24-page-ctrip-ebooking.html',
    'resources/frontend/templates/fragments/27-page-agent-center.html',
  ];

  for (const path of templates) {
    const source = read(path);
    assert.match(source, /优先级|\.priority/, `${path} must display priority`);
    assert.match(source, /建议动作|action/, `${path} must display a concrete action`);
    assert.match(source, /ai-decision-quality-details|数据依据|data_basis|dataBasis/, `${path} must display evidence`);
    assert.match(source, /ai-decision-quality-details|预期效果|expected_effect|expectedEffect/, `${path} must display expected effect`);
    assert.match(source, /ai-decision-quality-details|风险|risk/, `${path} must display risk`);
  }

  assert.match(read('resources/frontend/templates/fragments/13-page-opening-overview.html'), /openingOverview\.ai_recommendations/);
  assert.match(read('resources/frontend/templates/fragments/19-page-revenue-research-center.html'), /decision_recommendations/);

  const otaStatic = read('public/ota-diagnosis-static.js');
  for (const key of ['dataBasisText', 'expectedEffectText', 'riskText', 'priority']) {
    assert.match(otaStatic, new RegExp(key), `OTA diagnosis action rows must expose ${key}`);
  }
  assert.match(read('resources/frontend/templates/fragments/27-page-agent-center.html'), /item\.decision_recommendation/);
});

test('retired strategy and feasibility have no active AI surface or generator and retain authenticated 410 protection', () => {
  const entry = read('public/app-main.js');
  const manifest = JSON.parse(read('resources/frontend/templates/manifest.json'));
  for (const id of ['page-ai-strategy', 'page-ai-feasibility', 'page-market-evaluation', 'page-benchmark-model', 'page-asset-pricing']) assert.equal(manifest.fragments.some(fragment => fragment.id === id), false, id);
  assert.doesNotMatch(entry, /handleStrategy|handleFeasibility|aiStrategyResult|aiFeasibilityResult|loadExpansionStaticOptions/);
  const routes = readRouteContractSource();
  const strategyStart = routes.indexOf("Route::group('api/strategy'");
  const strategyEnd = routes.indexOf('\n', routes.indexOf('})->middleware', strategyStart));
  assert.match(routes.slice(strategyStart, strategyEnd), /Auth::class\)->middleware\(\\app\\middleware\\RetiredFeatureReadOnly::class/);
  for (const line of routes.split('\n').filter(line => /Route::(?:post|delete)\('\/feasibility-report/.test(line))) assert.match(line, /RetiredFeatureReadOnly::class/);
  const strategy = read('app/controller/StrategySimulation.php');
  assert.match(strategy, /public function simulate\(\): Response\s*\{\s*return \$this->retiredWriteResponse\(\);/);
  assert.match(strategy, /RetiredFeatureReadOnly::response\('战略推演'\)/);
  const middleware = read('app/middleware/RetiredFeatureReadOnly.php');
  assert.match(middleware, /'code' => 410/);
  assert.match(middleware, /'history_preserved' => true/);
});

test('feasibility historical evidence retains tenant hotel source financial and recommendation quality boundaries', () => {
  const source = read('app/service/FeasibilityReportService.php');
  for (const text of ['applyTenantScope', "where('tenant_id', $tenantId)", "where('created_by', $userId)", 'feasibility report hotel scope missing', 'feasibility report hotel scope conflict', 'feasibility report hotel scope mismatch', 'source_snapshot_digest', 'source_scope', 'normalizeReportFinancialScenarios', 'financialScenarioDataGaps', 'metric_truth', 'input_truth_context', 'ota_truth_context', 'recommendation_quality', 'AiDecisionQualityService']) assert.ok(source.includes(text), text);
  assert.match(source, /throw new \\RuntimeException\('retired_read_only', 410\)/);
});
