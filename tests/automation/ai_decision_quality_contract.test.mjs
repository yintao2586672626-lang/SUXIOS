import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(path, 'utf8');

test('user-facing AI recommendation backends apply the decision quality contract', () => {
  for (const path of [
    'app/controller/Agent.php',
    'app/controller/StrategySimulation.php',
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
  assert.match(read('public/app-main.js'), /data-testid': 'ai-decision-quality-blocked'[\s\S]*质量门禁：不合格，不可执行/);
});

test('AI decision surfaces show basis priority action effect and risk', () => {
  const templates = [
    'resources/frontend/templates/fragments/01-page-ai-strategy.html',
    'resources/frontend/templates/fragments/02-page-ai-simulation.html',
    'resources/frontend/templates/fragments/03-page-ai-feasibility.html',
    'resources/frontend/templates/fragments/04-page-market-evaluation.html',
    'resources/frontend/templates/fragments/05-page-benchmark-model.html',
    'resources/frontend/templates/fragments/09-page-asset-pricing.html',
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

  assert.match(read('resources/frontend/templates/fragments/01-page-ai-strategy.html'), /ai_evaluation\.recommendations/);
  assert.match(read('resources/frontend/templates/fragments/13-page-opening-overview.html'), /openingOverview\.ai_recommendations/);
  assert.match(read('resources/frontend/templates/fragments/19-page-revenue-research-center.html'), /decision_recommendations/);

  const otaStatic = read('public/ota-diagnosis-static.js');
  for (const key of ['dataBasisText', 'expectedEffectText', 'riskText', 'priority']) {
    assert.match(otaStatic, new RegExp(key), `OTA diagnosis action rows must expose ${key}`);
  }
  assert.match(read('resources/frontend/templates/fragments/27-page-agent-center.html'), /item\.decision_recommendation/);
});
