import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const simulationStaticSource = readFileSync(join(root, 'public/simulation-static.js'), 'utf8');

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
      "'tenant_id' => $this->tenantIdForUser($userId)",
      'private function applyTenantScope',
      "where('tenant_id', $tenantId)",
      'private function calculateSimulation(array $input): array',
      'private function buildScenarios(array $input): array',
    ],
  },
  {
    file: 'database/migrations/20260517_create_quant_simulation_records.sql',
    contains: [
      'CREATE TABLE IF NOT EXISTS `quant_simulation_records`',
      '`input_json` JSON DEFAULT NULL',
      '`tenant_id` BIGINT UNSIGNED DEFAULT NULL',
      '`idx_quant_sim_tenant_user` (`tenant_id`, `created_by`, `id`)',
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
      "requireSimulationStatic('buildSimulationInvestmentGroups')",
      "requireSimulationStatic('simulationInvestmentTotalFromGroups')",
      "requireSimulationStatic('simulationInvestmentPerRoom')",
      "requireSimulationStatic('buildSimulationRoomRevenueSegments')",
      "requireSimulationStatic('buildSimulationCostGroups')",
      "requireSimulationStatic('buildSimulationOtaCommissionChannels')",
      "requireSimulationStatic('isSimulationModelAnalysisVisible')",
      "requireSimulationStatic('simulationModelSourceLabel')",
      "requireSimulationStatic('createBenchmarkModelForm')",
      "requireSimulationStatic('createCollaborationProject')",
      "requireSimulationStatic('createTransferPricingForm')",
      "requireSimulationStatic('createTransferTimingForm')",
      "requireSimulationStatic('buildTransferTimingDataCheck')",
      'const hydrateSimulationStaticDefaults = () =>',
      'await ensureSimulationStaticReady();',
    ],
    absent: [
      'const exposure = Math.max(0, toNumber(form.exposure))',
    ],
  },
  {
    file: 'public/simulation-static.js',
    contains: [
      'const createBenchmarkModelForm',
      'const createCollaborationProject',
      'const createTransferPricingForm',
      'const createTransferTimingForm',
      'function buildSimulationInvestmentGroups',
      'function simulationInvestmentTotalFromGroups',
      'function simulationInvestmentPerRoom',
      'function buildSimulationRoomRevenueSegments',
      'function buildSimulationCostGroups',
      'function buildSimulationOtaCommissionChannels',
      'function isSimulationModelAnalysisVisible',
      'function simulationModelSourceLabel',
      'const buildTransferTimingDataCheck',
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
  for (const needle of check.absent || []) {
    if (content.includes(needle)) {
      failures.push(`${check.file} should not contain: ${needle}`);
    }
  }
}

try {
  const context = { window: {} };
  vm.runInNewContext(simulationStaticSource, context, {
    filename: 'public/simulation-static.js',
  });
  const buildTransferTimingDataCheck = context.window.SUXI_SIMULATION_STATIC?.buildTransferTimingDataCheck;
  if (typeof buildTransferTimingDataCheck !== 'function') {
    failures.push('public/simulation-static.js missing buildTransferTimingDataCheck function');
  } else {
    const healthy = buildTransferTimingDataCheck({
      exposure: 12000,
      visitors: 1800,
      conversion_rate: 6.5,
      order_count: 420,
      room_nights: 980,
    });
    if (healthy.status !== '数据口径正常' || healthy.derivedConversionLabel !== '23.3%' || healthy.roomNightPerOrderLabel !== 2.33) {
      failures.push('buildTransferTimingDataCheck should keep healthy transfer timing metrics stable');
    }

    const gap = buildTransferTimingDataCheck({});
    if (gap.status !== '数据断档' || !gap.hasDataGap || gap.hasDataAnomaly) {
      failures.push('buildTransferTimingDataCheck should expose missing transfer timing data as a data gap');
    }

    const anomaly = buildTransferTimingDataCheck({
      order_count: 12,
      room_nights: 18,
    });
    if (anomaly.status !== '疑似采集异常' || !anomaly.hasDataAnomaly) {
      failures.push('buildTransferTimingDataCheck should expose suspected collection anomalies');
    }
  }
} catch (error) {
  failures.push(`public/simulation-static.js failed runtime validation: ${error.message}`);
}

if (failures.length > 0) {
  console.error(`Simulation P2 contract verification failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}

console.log('Simulation P2 contract verification passed.');
