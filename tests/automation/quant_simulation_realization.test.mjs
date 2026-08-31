import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const staticSource = readFileSync('public/simulation-static.js', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/02-page-ai-simulation.html', 'utf8');
const appMainComponents = readFileSync('public/components/system/app-main-components.js', 'utf8');
const appMainComponentsLoader = readFileSync('public/components/system/app-main-components-loader.js', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');
const backend = readFileSync('app/service/QuantSimulationService.php', 'utf8');
const sandbox = { console, window: {}, localStorage: { getItem: () => null, setItem: () => {} } };
vm.runInNewContext(`${staticSource}\nthis.__api = window.SUXI_SIMULATION_STATIC;`, sandbox);
const api = sandbox.__api;

test('quant simulation is findable and requires an explicit permitted hotel', () => {
  const staticHash = createHash('sha256').update(staticSource).digest('hex').slice(0, 10);
  assert.match(systemStatic, /name:\s*'智算·量化模拟',\s*path:\s*'ai-simulation'/);
  assert.match(template, /data-testid="simulation-hotel-selector"/);
  assert.match(template, /v-model:hotel-id="aiSimulationParams\.hotel_id"/);
  assert.match(template, /:hotel-valid="simulationHotelSelectionValid"/);
  assert.match(appMainComponents, /示例假设 · 未验证/);
  assert.match(appMainComponents, /disabled: this\.loading \|\| !this\.hotelValid/);
  assert.match(staticSource, /function simulationHotelSelectionIsPermitted\(/);
  assert.match(appMain, /simulationStatic\.value\?\.simulationHotelSelectionIsPermitted/);
  assert.match(staticSource, /请选择当前账号可访问的酒店后再运行量化模拟/);
  assert.match(appMain, /runSimulationCalculationUiFlow/);
  assert.match(appMain, new RegExp(`simulationStaticScriptVersion = '[^']*-h${staticHash}'`));
  assert.match(appMain, /watch\(operationHotelOptions, clearInvalidSimulationHotel/);
  assert.match(staticSource, /const hotelId = Number\(payloadInput\.hotel_id \|\| 0\)/);
  assert.match(staticSource, /hotel_id: hotelId,[\s\S]*project_name:[\s\S]*input: payloadInput/);
});

test('extracted simulation UI flow preserves the permitted-hotel and loading contract', async () => {
  const validInput = { ...api.defaultSimulationInput, hotel_id: 80 };
  assert.equal(api.simulationHotelSelectionIsPermitted(validInput, [{ id: 80 }]), true);
  assert.equal(api.simulationHotelSelectionIsPermitted(validInput, [{ id: 81 }]), false);

  let rejectedInput = null;
  let rejectedSave = null;
  let requestCount = 0;
  const rejected = await api.runSimulationCalculationUiFlow({
    input: validInput,
    hotels: [{ id: 81 }],
    setInput: value => { rejectedInput = value; },
    saveInput: value => { rejectedSave = value; },
    request: async () => { requestCount += 1; },
  });
  assert.equal(rejected, null);
  assert.equal(rejectedInput.hotel_id, '');
  assert.equal(rejectedSave.hotel_id, '');
  assert.equal(requestCount, 0);

  const loadingStates = [];
  let applied = null;
  let recordsLoaded = 0;
  const completed = await api.runSimulationCalculationUiFlow({
    input: validInput,
    hotels: [{ id: 80 }],
    projectName: '权限范围内模拟',
    setLoading: value => { loadingStates.push(value); },
    request: async () => {
      requestCount += 1;
      return { code: 200, data: { id: 901, hotel_id: 80 } };
    },
    applyRecord: value => { applied = value; },
    loadRecords: async () => { recordsLoaded += 1; },
  });
  assert.equal(completed.id, 901);
  assert.equal(applied.id, 901);
  assert.equal(recordsLoaded, 1);
  assert.deepEqual(loadingStates, [true, false]);
});

test('extracted simulation hydration restores results or refreshes the input-only state', () => {
  const restored = {};
  assert.equal(api.hydrateSimulationState({
    enabled: true,
    loadState: () => ({ input: { hotel_id: 80 }, result: { monthlyRevenue: 1 }, scenarios: [{ monthlyRevenue: 1 }], modelAnalysis: { summary: 'ok' } }),
    setInput: value => { restored.input = value; },
    setResult: value => { restored.result = value; },
    setScenarios: value => { restored.scenarios = value; },
    setRiskHints: value => { restored.risks = value; },
    setModelAnalysis: value => { restored.analysis = value; },
  }), true);
  assert.equal(restored.input.hotel_id, 80);
  assert.equal(restored.result.monthlyRevenue, 1);
  assert.equal(restored.scenarios.length, 1);
  assert.equal(restored.analysis.summary, 'ok');

  let refreshed = false;
  api.hydrateSimulationState({
    enabled: true,
    loadState: () => ({ input: { hotel_id: '' }, result: null, scenarios: null }),
    refresh: force => { refreshed = force === true; },
  });
  assert.equal(refreshed, true);
});

test('example status and hotel identity survive normalization without becoming facts', () => {
  assert.equal(api.defaultSimulationInput.hotel_id, '');
  assert.equal(api.defaultSimulationInput.input_source_status, 'example_prefill_unverified');
  const normalized = api.normalizeSimulationInput({
    ...api.defaultSimulationInput,
    hotel_id: 80,
  });
  assert.equal(normalized.hotel_id, 80);
  assert.equal(normalized.input_source_status, 'example_prefill_unverified');
  assert.match(backend, /\$hotelId > 0 \? null : 'target_hotel_missing'/);
  assert.match(backend, /'mode' => 'hotel_scoped'/);
  assert.match(backend, /'mode' => 'legacy_read_only'/);
  assert.match(backend, /'mutation_allowed' => false/);
  assert.match(template, /v-if="canArchiveSim\(record\)"/);
  assert.match(template, /@click="archiveSim\(record\)"/);
  assert.match(template, /history-simulation-archive-\$\{record\.id\}/);
  assert.match(appMain, /const canArchiveSim = record => record\?\.access_policy\?\.mode !== 'legacy_read_only'/);
  assert.match(staticSource, /record\?\.access_policy\?\.mutation_allowed === false[\s\S]*只读保留[\s\S]*return false/);
  assert.match(backend, /'readback_verified' => \$recordId > 0/);
  assert.match(backend, /user_input_source_unverified/);
});

test('legacy read-only simulations never offer or submit archive mutation', async () => {
  let confirmationCount = 0;
  let requestCount = 0;
  let toast = null;
  const result = await api.runSimulationArchiveFlow({
    record: {
      id: 91,
      access_policy: { mode: 'legacy_read_only', mutation_allowed: false },
    },
    confirmAction: () => { confirmationCount += 1; return true; },
    request: async () => { requestCount += 1; return { code: 200 }; },
    showToast: (message, type) => { toast = { message, type }; },
  });

  assert.equal(result, false);
  assert.equal(confirmationCount, 0);
  assert.equal(requestCount, 0);
  assert.equal(toast?.type, 'warning');
  assert.match(toast?.message || '', /只读保留/);
});

test('quant simulation can create only a human pending-approval task with exact source identity', () => {
  assert.match(appMain, /const createSimulationExecutionIntent = async/);
  assert.match(staticSource, /\/simulation\/records\/\$\{recordId\}\/execution-intent/);
  assert.match(staticSource, /String\(intent\?\.status \|\| ''\) !== 'pending_approval'/);
  assert.match(staticSource, /Number\(intent\?\.source_record_id \|\| 0\) !== recordId/);
  assert.match(template, /createSimulationExecutionIntent\(record\)/);
  assert.match(staticSource, /转待审批任务/);
  assert.match(staticSource, /executionIntentIdFromRecord\(record\)/);
});

test('startup facade exposes every extracted closeout component', async () => {
  const runtimeWindow = { SUXI_ONLINE_DATA_COMPONENTS: {}, SUXI_SYSTEM_COMPONENTS: {} };
  const h = () => ({});
  const Vue = { h, defineAsyncComponent: loader => ({ loader }) };
  new Function('window', appMainComponents)(runtimeWindow);
  new Function('window', 'document', appMainComponentsLoader)(runtimeWindow, {});
  const facade = runtimeWindow.SUXI_APP_MAIN_COMPONENTS.create({ Vue, h });
  for (const key of ['OperatingNetworkReplicationList', 'MeituanSearchKeywordWorkbench', 'SimulationHeroActions']) {
    assert.equal(typeof facade[key]?.loader, 'function', `${key} must be lazy-exported by the startup facade`);
    assert.equal((await facade[key].loader())?.name, key);
  }
});
