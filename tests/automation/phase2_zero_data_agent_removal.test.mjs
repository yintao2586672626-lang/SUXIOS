import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const html = readFileSync('public/index.html', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const agentController = readFileSync('app/controller/Agent.php', 'utf8');
const readinessService = readFileSync('app/service/AgentClosureReadinessService.php', 'utf8');
const businessClosureService = readFileSync('app/service/BusinessClosureOverviewService.php', 'utf8');
const systemStaticSource = readFileSync('public/system-static.js', 'utf8');

test('Agent toolbox navigation only exposes modules backed by retained data flows', () => {
  const sandbox = {
    window: {},
    document: {
      querySelector: () => null,
      createElement: () => ({ dataset: {}, addEventListener: () => {} }),
      head: { appendChild: () => {} },
    },
    Promise,
    Error,
    setTimeout,
    clearTimeout,
  };
  vm.runInNewContext(systemStaticSource, sandbox, { filename: 'public/system-static.js' });
  assert.deepEqual(
    Array.from(sandbox.window.SUXI_SYSTEM_STATIC.agentTabs, tab => tab.key),
    ['overview', 'revenue', 'logs'],
  );
  assert.doesNotMatch(html, /agentTab === ['"](?:staff|asset)['"]/);
});

test('zero-data Agent routes and frontend requests are removed while core Agent routes remain', () => {
  const removedSegments = [
    'work-orders',
    'work-order-stats',
    'conversations',
    'conversation-stats',
    'staff-dashboard',
    'devices',
    'device-stats',
    'energy-data',
    'energy-benchmarks',
    'energy-suggestions',
    'maintenance-plans',
    'maintenance-reminders',
    'asset-dashboard',
    'tasks',
  ];
  for (const segment of removedSegments) {
    const escaped = segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    assert.doesNotMatch(routes, new RegExp(`Route::(?:get|post)\\('\\/${escaped}`));
    assert.doesNotMatch(html, new RegExp(`\\/agent\\/${escaped}`));
  }
  assert.match(routes, /Route::post\('\/ota-diagnosis', 'Agent\/otaDiagnosis'\)/);
  assert.match(routes, /Route::get\('\/price-suggestions', 'Agent\/priceSuggestions'\)/);
  assert.match(routes, /Route::get\('\/logs', 'Agent\/logs'\)/);
});

test('zero-data Agent implementation models are deleted but database migrations remain', () => {
  const removedModels = [
    'AgentWorkOrder',
    'AgentConversation',
    'AgentTask',
    'Device',
    'DeviceCategory',
    'DeviceMaintenance',
    'EnergyConsumption',
    'EnergyBenchmark',
    'EnergySavingSuggestion',
    'MaintenancePlan',
  ];
  for (const model of removedModels) {
    assert.equal(existsSync(`app/model/${model}.php`), false, `${model} model must be removed`);
    assert.doesNotMatch(agentController, new RegExp(`\\b${model}\\b`));
    assert.doesNotMatch(readinessService, new RegExp(`\\b${model}\\b`));
  }
  assert.equal(existsSync('app/model/AgentLog.php'), true);
  assert.match(agentController, /public function logs\(\): Response/);
  assert.match(agentController, /public function otaDiagnosis\(\): Response/);
  assert.equal(existsSync('database/migrations/20250402_create_agent_tables.sql'), true);
  assert.equal(existsSync('database/migrations/20250402_enhance_agent_tables.sql'), true);
});

test('active business closure no longer reads retired or frozen module tables', () => {
  const retiredSignals = [
    'staffServiceSignal',
    'assetMaintenanceSignal',
    'transferInvestmentSignal',
    'expansionSignal',
    'openingSignal',
    'strategySimulationSignal',
    'feasibilityReportSignal',
  ];
  for (const signal of retiredSignals) {
    assert.doesNotMatch(businessClosureService, new RegExp(`\\b${signal}\\b`));
  }

  const retiredTables = [
    'agent_work_orders',
    'agent_conversations',
    'devices',
    'energy_saving_suggestions',
    'maintenance_plans',
    'transfer_records',
    'expansion_records',
    'opening_projects',
    'strategy_records',
    'quant_simulation_records',
    'feasibility_reports',
  ];
  for (const table of retiredTables) {
    assert.doesNotMatch(businessClosureService, new RegExp(`['\"]${table}['\"]`));
  }
});

test('Agent config only writes the retained revenue agent and logs keep history without empty asset filter', () => {
  assert.match(agentController, /'agent_type'\s*=>\s*'require\|integer\|in:2'/);
  assert.doesNotMatch(agentController, /AgentConfig::AGENT_TYPE_STAFF\s*=>/);
  assert.doesNotMatch(agentController, /AgentConfig::AGENT_TYPE_ASSET\s*=>/);
  assert.match(html, /<option value="1">智能员工<\/option>/);
  assert.doesNotMatch(html, /<option value="3">资产运维<\/option>/);
});
