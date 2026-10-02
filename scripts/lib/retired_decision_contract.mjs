import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { readRouteContractSource } from './route_contract_source.mjs';
import { extractPhpMethod } from './shared_helpers.mjs';

const contracts = {
  expansion: {
    controller: 'Expansion', service: 'ExpansionService',
    writes: ['marketEvaluation', 'benchmarkModel', 'collaborationEfficiency', 'createExecutionIntent', 'archive', 'clearRecords', 'clearMarketEvaluation'],
    serviceWrites: ['evaluateMarket', 'buildBenchmarkModel', 'improveCollaboration', 'saveRecord', 'archive', 'archiveByType', 'archiveByTypes'],
    history: ['records', 'detail', 'buildProjectReadiness', 'buildExecutionIntentInput', 'attachExecutionTracking'],
    scope: ['applyTenantScope', "where('tenant_id', $tenantId)", "where('created_by', $userId)"],
    retiredFrontend: ['marketEvaluationForm', 'loadMarketEvaluationRecords', 'benchmarkModelForm', 'collaborationProject'],
  },
  transfer: {
    controller: 'TransferDecision', service: 'TransferDecisionService',
    writes: ['pricing', 'timing', 'dashboard', 'createExecutionIntent', 'archive'],
    serviceWrites: ['calculateAssetPricing', 'calculateTransferTiming', 'buildTransferDashboard', 'saveRecord', 'archive'],
    history: ['records', 'detail', 'buildSourcePayload', 'buildDecisionReadiness', 'buildExecutionIntentInput', 'lockExecutionTrackingSource', 'attachExecutionTracking'],
    scope: ['currentTenantTransferRecordQuery', 'normalizeTransferBusinessDate', 'normalizeTransferHotelIds'],
    retiredFrontend: ['loadTransferSource', 'loadTransferRecords', 'transferPricingForm', 'transferTimingForm'],
  },
  strategy: {
    controller: 'StrategySimulation',
    writes: ['simulate', 'createExecutionIntent', 'archive'],
    history: ['records', 'detail', 'formatRecord', 'applyTenantScope'],
    scope: ['SimulationExecutionReadinessService', 'execution_readiness', "where('tenant_id', $tenantId)", 'created_by'],
    retiredFrontend: ['aiStrategyRecords', 'loadStrategyRecords', 'reuseStrategyRecord', 'runStrategySimulation'],
  },
};

export function verifyRetiredDecisionContracts(domain, root = process.cwd()) {
  const contract = contracts[domain];
  assert.ok(contract, `Unknown retired domain: ${domain}`);
  const read = file => fs.readFileSync(path.join(root, file), 'utf8');
  const controller = read(`app/controller/${contract.controller}.php`);
  const route = readRouteContractSource(root);
  const group = route.match(new RegExp(`Route::group\\('api/${domain}', function \\(\\) \\{([\\s\\S]*?)\\}\\)->middleware\\(\\\\app\\\\middleware\\\\Auth::class\\)->middleware\\(\\\\app\\\\middleware\\\\RetiredFeatureReadOnly::class, '[^']+'\\);`));
  assert.ok(group, `${domain} keeps authenticated retired-write middleware`);
  for (const action of ['records', 'detail']) {
    assert.ok(group[1].includes(`'${contract.controller}/${action}'`), `${domain} preserves ${action} route`);
    assert.ok(extractPhpMethod(controller, action), `${domain} preserves ${action} controller`);
  }
  for (const action of contract.writes) {
    assert.equal(extractPhpMethod(controller, action).trim(), 'return $this->retiredWriteResponse();', `${domain}/${action} cannot execute a retired write`);
  }
  const rejection = extractPhpMethod(controller, 'retiredWriteResponse');
  assert.ok(rejection.includes('currentUser') && rejection.includes('401') && rejection.includes('RetiredFeatureReadOnly::response'), `${domain} direct calls keep authentication and retirement boundaries`);
  const service = contract.service ? read(`app/service/${contract.service}.php`) : controller;
  for (const method of contract.history) assert.ok(extractPhpMethod(service, method), `${domain} preserves historical ${method}`);
  for (const needle of contract.scope) assert.ok(service.includes(needle), `${domain} preserves ${needle}`);
  for (const method of contract.serviceWrites ?? []) {
    assert.equal(extractPhpMethod(service, method).trim(), "throw new \\RuntimeException('retired_read_only', 410);", `${domain} service ${method} is side-effect free`);
  }
  if (contract.service) assert.ok(!extractPhpMethod(service, '__construct').includes('new LlmClient'), `${domain} historical reads do not construct an LLM client`);
  if (domain === 'transfer') {
    assert.ok(extractPhpMethod(service, 'records').includes('$this->currentTenantTransferRecordQuery($hotelIds)'), 'transfer records uses the current tenant and selected hotel query');
    const scopedQuery = extractPhpMethod(service, 'currentTenantTransferRecordQuery');
    assert.ok(scopedQuery.includes("whereIn('transfer_record.hotel_id', $hotelIds)"), 'transfer query keeps hotel filtering');
    assert.ok(scopedQuery.includes('tenant_id') && !scopedQuery.includes('isSuperAdmin'), 'every role uses the same current tenant and hotel scope');
  }
  const manifest = JSON.parse(read('resources/frontend/templates/manifest.json'));
  const live = [read('public/app-main.js'), ...manifest.fragments.map(fragment => read(`resources/frontend/templates/${fragment.path}`))].join('\n');
  for (const symbol of contract.retiredFrontend) assert.ok(!new RegExp(`\\b${symbol}\\b`).test(live), `${symbol} is absent from current runtime sources`);
  assert.ok(!manifest.fragments.some(fragment => ['page-ai-strategy', 'page-expansion-market', 'page-transfer-pricing'].includes(fragment.id)), 'retired forms cannot reenter runtime template manifest');
  console.log(`${domain} current retirement and scoped historical-read contract passed.`);
}
