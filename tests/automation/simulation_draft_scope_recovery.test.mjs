import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash } from 'node:crypto';

// Original consumer helpers, synthetic saved records and memory-only storage.
// No HTTP, PHP calculation, real browser storage, credentials or database writes.
const sourceUrl = new URL('../../public/simulation-static.js', import.meta.url);
const source = fs.readFileSync(sourceUrl, 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
function harness() {
    const memory = new Map(), failedWrites = new Set(), failedRemovals = new Set();
    const sandbox = { window: {}, console, localStorage: {
        getItem: key => memory.get(key) ?? null,
        setItem: (key, value) => { if (failedWrites.has(key)) throw new Error('Synthetic quota'); memory.set(key, String(value)); },
        removeItem: key => { if (failedRemovals.has(key)) throw new Error('Synthetic remove failure'); memory.delete(key); },
    } };
    vm.runInNewContext(source, sandbox);
    return { api: sandbox.window.SUXI_SIMULATION_STATIC, memory, failedWrites, failedRemovals };
}
function legacyInput(api, hotelId = 7) {
    return { ...clone(api.defaultSimulationInput), hotel_id: hotelId, input_source_status: 'manual_unverified', operatingScenario: null };
}
function saved(input, id = 201) {
    return { id, project_name: '合成单月方案', input: clone(input), result: { monthlyRevenue: 123 }, scenarios: [],
        truth_context: { hotel_id: input.hotel_id, tenant_id: 70, status: 'unverified', persistence: { readback_verified: true } } };
}

for (const key of ['roomCount', 'monthlyRent', 'baseRentCost', 'weekdayDays', 'decorationHardCost', 'ctripCommissionRate']) {
    test(`same-hotel confirmed single-month save rejects changed ${key} instead of replacing the current input`, async () => {
        const { api } = harness(), input = legacyInput(api), row = saved(input), applied = [], notices = [];
        row.input[key] += 1;
        const response = await api.runSimulationCalculationUiFlow({ input, hotels: [{ id: 7 }], projectName: row.project_name,
            request: async () => ({ code: 200, data: row }), applyRecord: value => applied.push(value),
            loadRecords: async () => true, showToast: (message, type) => notices.push({ message, type }),
        });
        assert.equal(response === null, true, 'A response for different input cannot be an exact saved readback');
        assert.deepEqual(applied, []); assert.equal(notices.length, 1); assert.equal(notices[0].type, 'error');
    });
}

test('legacy single-month save rejects mismatched hotel or unconfirmed readback before changing the current draft', async t => {
    const { api } = harness(), input = legacyInput(api);
    for (const mutate of [
        row => { row.truth_context.hotel_id = 8; },
        row => { row.input.hotel_id = 8; },
        row => { row.input.system_hotel_id = 8; },
        row => { row.truth_context.persistence.readback_verified = false; },
        row => { row.truth_context.persistence.readback_verified = 1; },
        row => { row.id = null; },
    ]) {
        const row = saved(input); mutate(row);
        const applied = [], notices = [], loading = [], reads = [];
        const response = await api.runSimulationCalculationUiFlow({ input, hotels: [{ id: 7 }], projectName: row.project_name,
            request: async () => ({ code: 200, data: row }), applyRecord: value => applied.push(value),
            loadRecords: async () => reads.push('history'), setLoading: value => loading.push(value),
            showToast: (message, type) => notices.push({ message, type }),
        });
        assert.equal(response, null, 'A mismatched or unconfirmed record cannot become a successful saved single-month result');
        assert.deepEqual(applied, []); assert.deepEqual(reads, []); assert.deepEqual(loading, [true, false]);
        assert.equal(notices.length, 1); assert.equal(notices[0].type, 'error');
    }
    t.diagnostic(JSON.stringify({ source: sourceUrl.pathname, sha256: createHash('sha256').update(source).digest('hex'), synthetic: true }));
});

test('legacy single-month save recovery accepts the same hotel and confirmed saved record', async () => {
    const { api } = harness(), input = legacyInput(api), row = saved(input), applied = [], notices = [];
    const response = await api.runSimulationCalculationUiFlow({ input, hotels: [{ id: 7 }], projectName: row.project_name,
        request: async () => ({ code: 200, data: row }), applyRecord: value => applied.push(value),
        loadRecords: async () => true, showToast: (message, type) => notices.push({ message, type }),
    });
    assert.equal(response.id, 201); assert.equal(applied.length, 1); assert.equal(notices.length, 1);
    assert.match(notices[0].message, /精确回读/);
});

test('a stale retired seed cannot overwrite a newer saved independent draft after reloading', () => {
    const { api, memory } = harness(), input = legacyInput(api);
    Object.assign(input, { roomCount: 17, monthlyRent: 1234, baseRentCost: 1234 });
    api.simulationStateStorage.saveInputOnly(input);
    memory.set('suxios_simulation_seed', JSON.stringify({ hotel_id: 8, roomCount: 88, monthlyRent: 8888, baseRentCost: 8888 }));
    const loaded = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
    assert.equal(loaded.input.hotel_id, 7); assert.equal(loaded.input.roomCount, 17); assert.equal(loaded.input.monthlyRent, 1234);
    assert.equal(loaded.result, null); assert.equal(loaded.scenarios, null);
    const live = { input: null, result: null };
    api.hydrateSimulationState({ enabled: true, loadState: () => loaded, setInput: value => { live.input = value; },
        refresh: () => { live.result = null; }, clearRecord: () => {} });
    assert.equal(live.input.hotel_id, 7); assert.equal(live.result, null);
});

test('a seed still initializes a first draft when there is no saved independent input', () => {
    const { api, memory } = harness();
    memory.set('suxios_simulation_seed', JSON.stringify({ hotel_id: 7, roomCount: 17, monthlyRent: 1234, baseRentCost: 1234 }));
    const loaded = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
    assert.equal(loaded.input.hotel_id, 7); assert.equal(loaded.input.roomCount, 17); assert.equal(loaded.input.monthlyRent, 1234);
    assert.equal(loaded.result, null); assert.equal(loaded.scenarios, null);
});

test('an existing draft without a hotel stays unbound and a corrupt retired seed is ignored', () => {
    const { api, memory } = harness(), input = legacyInput(api, '');
    input.roomCount = 19;
    api.simulationStateStorage.save(input, { monthlyRevenue: 0 }, [{ monthlyRevenue: 0 }]);
    memory.set('suxios_simulation_seed', '{damaged obsolete seed');
    const loaded = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
    assert.equal(loaded.input.hotel_id, ''); assert.equal(loaded.input.roomCount, 19);
    assert.equal(loaded.result.monthlyRevenue, 0); assert.equal(loaded.scenarios[0].monthlyRevenue, 0);
});

test('orphaned results cannot be attached to an initial migrated seed or an absent input', () => {
    const { api, memory } = harness();
    memory.set('suxios_simulation_result', JSON.stringify({ monthlyRevenue: 99999 }));
    memory.set('suxios_simulation_scenarios', JSON.stringify([{ monthlyRevenue: 99999 }]));
    memory.set('suxios_simulation_model_analysis', JSON.stringify({ summary: 'OLD RESULT MUST NOT BECOME CURRENT' }));
    for (const seed of [null, { hotel_id: 8, roomCount: 88 }]) {
        memory.set('suxios_simulation_seed', JSON.stringify(seed));
        const loaded = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
        assert.equal(loaded.result, null); assert.equal(loaded.scenarios, null); assert.equal(loaded.modelAnalysis, null);
    }
});

test('damaged saved input and inaccessible storage do not restore an old result as current', () => {
    const { api, memory } = harness();
    memory.set('suxios_simulation_input', '{damaged independent input');
    memory.set('suxios_simulation_seed', JSON.stringify({ hotel_id: 8, roomCount: 88 }));
    const loaded = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
    assert.equal(loaded.input.hotel_id, ''); assert.equal(loaded.result, null); assert.equal(loaded.scenarios, null);
    const sandbox = { window: {}, localStorage: { getItem() { throw new Error('Synthetic storage unavailable'); } } };
    vm.runInNewContext(source, sandbox);
    const blockedApi = sandbox.window.SUXI_SIMULATION_STATIC;
    const blocked = blockedApi.simulationStateStorage.load(blockedApi.defaultSimulationInput, blockedApi.normalizeSimulationInput, blockedApi.normalizeSimulationModelAnalysis);
    assert.equal(blocked.input.hotel_id, ''); assert.equal(blocked.result, null); assert.equal(blocked.scenarios, null);
});

test('partial storage failures never attach the old hotel result to a new input and errors remain observable', () => {
    for (const failedKey of ['suxios_simulation_input', 'suxios_simulation_result', 'suxios_simulation_scenarios']) {
        const { api, failedWrites } = harness(), oldInput = legacyInput(api, 7), newInput = legacyInput(api, 8);
        api.simulationStateStorage.save(oldInput, { monthlyRevenue: 7 }, [{ monthlyRevenue: 7 }]);
        failedWrites.add(failedKey);
        assert.throws(() => api.simulationStateStorage.save(newInput, { monthlyRevenue: 8 }, [{ monthlyRevenue: 8 }]), /Synthetic quota/);
        const loaded = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
        assert.equal(loaded.input.hotel_id, failedKey === 'suxios_simulation_input' ? 7 : 8);
        assert.equal(loaded.result, null); assert.equal(loaded.scenarios, null); assert.equal(loaded.modelAnalysis, null);
        failedWrites.clear();
        api.simulationStateStorage.save(newInput, { monthlyRevenue: 0 }, [{ monthlyRevenue: 0 }]);
        const recovered = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
        assert.equal(recovered.input.hotel_id, 8); assert.equal(recovered.result.monthlyRevenue, 0); assert.equal(recovered.scenarios[0].monthlyRevenue, 0);
    }
});

test('an input-only draft clears old result before writing and aborts new input when invalidation fails', () => {
    const { api, failedWrites, failedRemovals } = harness(), oldInput = legacyInput(api, 7), newInput = legacyInput(api, 8);
    api.simulationStateStorage.save(oldInput, { monthlyRevenue: 7 }, [{ monthlyRevenue: 7 }]);
    failedRemovals.add('suxios_simulation_result');
    assert.throws(() => api.simulationStateStorage.saveInputOnly(newInput), /Synthetic remove failure/);
    const retained = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
    assert.equal(retained.input.hotel_id, 7); assert.equal(retained.result.monthlyRevenue, 7);
    failedRemovals.clear(); failedWrites.add('suxios_simulation_input');
    assert.throws(() => api.simulationStateStorage.saveInputOnly(newInput), /Synthetic quota/);
    const incomplete = api.simulationStateStorage.load(api.defaultSimulationInput, api.normalizeSimulationInput, api.normalizeSimulationModelAnalysis);
    assert.equal(incomplete.input.hotel_id, 7); assert.equal(incomplete.result, null); assert.equal(incomplete.scenarios, null);
});

test('formal four-decimal normalization and zero input survive exact readback without an unchanged-object shortcut', async () => {
    const { api } = harness(), input = legacyInput(api);
    Object.assign(input, { decorationHardCost: '123.45678', signageDesignCost: '0.00015', decorationInvestment: 10000, baseRentCost: '0', monthlyRent: 1,
        furnitureInvestment: 99999, input_source_status: ' manual_unverified ', discarded_note: 'not a persisted input field' });
    const row = saved(input);
    delete row.input.discarded_note;
    row.input.decorationHardCost = 123.4568; row.input.signageDesignCost = 0.0002;
    row.input.baseRentCost = 0; row.input.input_source_status = 'manual_unverified';
    for (const group of [...api.buildSimulationInvestmentGroups(row.input), ...api.buildSimulationCostGroups(row.input)]) {
        row.input[group.totalKey] = Math.round(group.total * 10000) / 10000;
    }
    row.input.adr = 264.95; row.input.occupancyRate = 76.53;
    const applied = [];
    const response = await api.runSimulationCalculationUiFlow({ input, hotels: [{ id: 7 }], projectName: row.project_name,
        request: async () => ({ code: 200, data: row }), applyRecord: value => applied.push(value), loadRecords: async () => true });
    assert.equal(response.id, 201); assert.equal(applied.length, 1); assert.equal(applied[0].input.baseRentCost, 0);
    assert.equal(applied[0].input.decorationHardCost, 123.4568); assert.equal(applied[0].input.adr, 264.95);
});

test('missing zero-valued input fields are rejected instead of filled by normalization', async () => {
    const { api } = harness(), input = legacyInput(api), row = saved(input), applied = [];
    input.roomConsumableCost = 0; row.input.roomConsumableCost = 0;
    row.input.consumableCost = row.input.cleaningSuppliesCost + row.input.linenReplacementCost;
    delete row.input.roomConsumableCost;
    const response = await api.runSimulationCalculationUiFlow({ input, hotels: [{ id: 7 }], projectName: row.project_name,
        request: async () => ({ code: 200, data: row }), applyRecord: value => applied.push(value), loadRecords: async () => true });
    assert.equal(response, null); assert.deepEqual(applied, []);
});
