import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import * as VueRuntime from 'vue';
import { compileFrontendTemplate } from '../../scripts/lib/frontend_template_build.mjs';

const source = fs.readFileSync(new URL('../../public/components/system/investment-scenario.js', import.meta.url), 'utf8');
const create = (request, project = { id: 1, version: 7, project_name: '测试项目', hotel_id: 80 }, environment = {}) => {
    const registry = {}, events = [];
    const Vue = {
        ...VueRuntime, withDirectives: node => node,
        ref: value => ({ value }), computed: getter => ({ get value() { return getter(); } }),
        watch: () => {}, onUnmounted: () => {},
    };
    const componentSource = environment.compiled ? fs.readFileSync(new URL('../../public/components/system/investment-scenario.min.js', import.meta.url), 'utf8') : source;
    const fixtureWindow = { SUXI_SYSTEM_COMPONENTS: registry, ...(environment.window || {}) };
    vm.runInNewContext(componentSource, { Vue, Intl, Date, Number, Object, JSON, String, Array, ...environment, window: fixtureWindow });
    const props = { request, project, ledgerBusy: false };
    return { state: registry.InvestmentScenarioWorkbench.setup(props, { emit: (...args) => events.push(args) }), props, events, fixtureWindow, component: registry.InvestmentScenarioWorkbench };
};
const ok = data => ({ code: 200, data });
const saved = (input = null, id = 1, result = null, version = 2) => ({ project_id: id, project_version: 7, scenario_version: version, input, result, content_digest: 'test-content-digest', readback: 'exact', capabilities: { procurement_reference: false, actual_consumables_reference: false } });
// Optional-module behavior is exercised with an explicit synthetic server capability.
// These fixtures never load a procurement directory or operating-evidence module.
const createWithReferences = (request, ...args) => create(async (...call) => {
    const reply = await request(...call);
    return reply?.code === 200 && reply.data ? { ...reply, data: { ...reply.data,
        capabilities: { procurement_reference: true, actual_consumables_reference: true } } } : reply;
}, ...args);
const renderedTestIds = ({ state, props, component }, compiled = false) => {
    const render = compiled ? component.render : new Function('Vue', compileFrontendTemplate(component.template))({ ...VueRuntime, withDirectives: node => node });
    const context = new Proxy({}, { get: (_, key) => {
        const value = key in state ? state[key] : props[key];
        return value && typeof value === 'object' && 'value' in value ? value.value : value;
    }, has: (_, key) => key in state || key in props });
    const ids = new Set();
    const visit = node => {
        if (Array.isArray(node)) return node.forEach(visit);
        if (!node || typeof node !== 'object') return;
        if (node.props?.['data-testid']) ids.add(node.props['data-testid']);
        if (typeof node.children === 'string') {
            for (const match of node.children.matchAll(/data-testid="([^"]+)"/g)) ids.add(match[1]);
        } else visit(node.children);
    };
    visit(render(context, []));
    return ids;
};
const result = input => ({ status: 'partial', model_version: 'test-model', input, annual_rows: [{ year: 1, adr: 200, occupancy: 0.75, revenue: 12000, pretax_cash_proxy: 3000, cumulative_cash_proxy: -7000 }], initial_cash_total: 10000, payback: { status: 'not_reached', operating_years: null, total_years: null, remaining_cash: 7000 }, warnings: [], exclusions: [] });
// These catalog entries are synthetic test data, not supplier quotations or hotel facts.
const procurementCatalog = (projectId = 1) => ({
    project_id: projectId, schema_version: 'consumables-procurement-reference-v1', catalog_id: 'consumables-price-list-20261001',
    source_sha256: 'BF8AA1E0290BCF7F6464D6FFA63BDB653C1E11745A816EA53F517E2FC3EBA4F0', usage_policy: 'reference_only',
    source_filename: '合成测试价目.xlsx', quote_effective_date: null, supplier: null, currency: null,
    tiers: [{ id: 'B', label: '合成档位B' }, { id: 'C', label: '合成档位C' }],
    items: [{ id: 'test-liquid', name: '合成测试补液', quotes: [
        { tier_id: 'B', source_cell: 'D23', raw_text: '20kg 150-220', quote_kind: 'range', amount: null, lower: 150, upper: 220 },
        { tier_id: 'C', source_cell: 'E23', raw_text: '可不配', quote_kind: 'optional', amount: null, lower: null, upper: null },
    ], recommendations: [{ tier_id: 'B', source_cell: 'D24', raw_text: '合成布局建议；不代表同SKU' }], interpretation_limits: ['kg与mL不能直接互换；计价单位未知。'] }],
});

for (const compiled of [false, true]) {
    for (const [label, capabilities, procurementAvailable, actualAvailable] of [
        ['unavailable', { procurement_reference: false, actual_consumables_reference: false }, false, false],
        ['legacy missing flags', undefined, false, false],
        ['non-boolean flags', { procurement_reference: 1, actual_consumables_reference: 'true' }, false, false],
        ['procurement only', { procurement_reference: true, actual_consumables_reference: false }, true, false],
        ['actual reference only', { procurement_reference: false, actual_consumables_reference: true }, false, true],
        ['explicit available modules', { procurement_reference: true, actual_consumables_reference: true }, true, true],
    ]) {
        test(`${compiled ? 'compiled' : 'source'} renders optional entries only for ${label} and keeps manual costs usable`, async () => {
            const calls = [];
            const fixture = create(async path => {
                calls.push(path);
                return ok({ ...saved(), capabilities });
            }, undefined, { compiled });
            await fixture.state.loadScenario();
            fixture.state.addConsumable();
            fixture.state.receiveActualCost({ detail: { hotel_id: 80, snapshot_id: 5, content_digest: 'a'.repeat(64),
                business_month: '2026-09', actual_consumables_cost_per_room_night: '3.25' } });
            assert.equal(fixture.state.procurementReferenceAvailable.value, procurementAvailable);
            assert.equal(fixture.state.actualReferenceAvailable.value, actualAvailable);
            const ids = renderedTestIds(fixture, compiled);
            assert.equal(ids.has('scenario-consumables-reference'), procurementAvailable);
            assert.equal(ids.has('scenario-actual-cost-reference'), actualAvailable);
            assert.equal(ids.has('scenario-consumable-add'), true);
            assert.equal(calls.some(path => path.endsWith('/consumables-reference')), false);
            const input = fixture.state.toInput(fixture.state.form.value);
            assert.equal(input.cost_evidence_snapshot_id, null);
            assert.equal(input.cost_evidence_digest, '');
            assert.equal(input.cost_evidence_confirmed, false);
            assert.equal(input.consumables_cost.items.length, 1);
            assert.equal(input.consumables_cost.items[0].procurement_reference, undefined);
        });
    }
}

test('unavailable optional references keep rejected inputs visible without a saved event or ledger write', async () => {
    let snapshot = saved(), writes = 0;
    const { state, events } = create(async (path, options) => {
        if (options?.method === 'POST') {
            const input = JSON.parse(options.body).scenario;
            if (input.cost_evidence_snapshot_id !== null || input.cost_evidence_digest !== '' || input.cost_evidence_confirmed !== false) {
                return { code: 422, message: '实际耗材证据尚未接入，请解除引用后使用手工测算' };
            }
            if (input.consumables_cost?.items.some(row => row.procurement_reference)) {
                return { code: 422, message: '采购参考目录尚未接入，请解除引用后使用手工成本' };
            }
            writes++;
            snapshot = saved(input, 1, result(input), 3);
        }
        return ok(snapshot);
    });
    await state.loadScenario(); state.addConsumable();
    state.form.value.cost_evidence_snapshot_id = 5;
    state.form.value.cost_evidence_digest = 'a'.repeat(64);
    state.form.value.cost_evidence_confirmed = true;
    await state.save();
    assert.match(state.error.value, /实际耗材证据尚未接入/);
    assert.equal(state.form.value.cost_evidence_snapshot_id, 5);
    assert.equal(writes, 0); assert.equal(events.filter(row => row[0] === 'saved').length, 0);
    state.form.value.cost_evidence_snapshot_id = null;
    state.form.value.cost_evidence_digest = ''; state.form.value.cost_evidence_confirmed = false;
    state.form.value.consumables_cost.items[0].procurement_reference = { catalog_id: 'synthetic-unavailable-catalog', confirmed_for_scenario: true };
    await state.save();
    assert.match(state.error.value, /采购参考目录尚未接入/);
    assert.equal(state.form.value.consumables_cost.items[0].procurement_reference.catalog_id, 'synthetic-unavailable-catalog');
    assert.equal(writes, 0); assert.equal(events.filter(row => row[0] === 'saved').length, 0);
    delete state.form.value.consumables_cost.items[0].procurement_reference;
    await state.save();
    assert.equal(state.error.value, ''); assert.equal(state.readback.value, 'exact');
    assert.equal(writes, 1); assert.equal(events.filter(row => row[0] === 'saved').length, 1);
});

test('three scenario slots save and reread independently while draft switching requires confirmation', async () => {
    const slots = {}, calls = [];
    const { state } = create(async (path, options) => {
        calls.push(path);
        const key = path.includes('scenario_key=') ? path.split('scenario_key=')[1] : 'base';
        if (options?.method === 'POST') { const body = JSON.parse(options.body); slots[body.scenario_key] = { ...saved(body.scenario, 1, result(body.scenario)), scenario_key: body.scenario_key }; return ok(slots[body.scenario_key]); }
        return ok(slots[key] || { ...saved(), scenario_key: key });
    });
    await state.loadScenario(); state.form.value.scenario_name = '基准'; await state.save();
    await state.switchScenario('conservative'); assert.equal(state.scenarioKey.value, 'conservative'); assert.equal(state.form.value.scenario_name, '');
    state.form.value.scenario_name = '保守草稿'; await state.switchScenario('optimistic');
    assert.equal(state.pendingReplacement.value.kind, 'switch'); assert.equal(state.scenarioKey.value, 'conservative');
    state.cancelReplacement(); await state.save();
    await state.switchScenario('optimistic'); state.form.value.scenario_name = '乐观'; await state.save();
    await state.switchScenario('base'); assert.equal(state.form.value.scenario_name, '基准');
    assert.equal(slots.conservative.input.scenario_name, '保守草稿'); assert.equal(slots.optimistic.input.scenario_name, '乐观');
    assert.ok(calls.some(path => path.endsWith('?scenario_key=conservative')));
});

test('failed or mismatched slot reads keep the current draft and block writes until a valid reread', async () => {
    let fail = false;
    const { state } = create(async path => { if (path.includes('scenario_key=')) { if (fail) throw new Error('合成失败'); return ok({ ...saved(), scenario_key: 'base' }); } return ok(saved()); });
    await state.loadScenario(); state.form.value.scenario_name = '保留输入';
    await state.switchScenario('conservative', true);
    assert.equal(state.scenarioKey.value, 'base'); assert.equal(state.form.value.scenario_name, '保留输入'); assert.equal(state.writeBlocked.value, true);
    assert.match(state.error.value, /方案不一致/); fail = true;
    await state.switchScenario('optimistic', true); assert.match(state.error.value, /合成失败/); assert.equal(state.form.value.scenario_name, '保留输入');
});

test('historical snapshot stays read only and copying to a named slot verifies exact new snapshot', async () => {
    let current;
    const input = { scenario_name: '历史合成', as_of: '2026-10-01' };
    const history = { ...saved(input, 1, result(input)), scenario_event_id: 99, scenario_key: 'conservative', historical_version: true };
    const { state, events } = create(async (path, options) => {
        if (path.endsWith('/history/99/copy')) { const payload = JSON.parse(options.body); current = { ...saved(input, 1, result(input), 3), scenario_key: payload.scenario_key }; return ok(current); }
        if (path.endsWith('/history/99')) return ok(history);
        return ok(current || saved());
    });
    await state.loadScenario(); await state.viewVersion(99);
    assert.equal(state.readOnly.value, true); assert.equal(state.historyViewing.value, true);
    state.copyTarget.value = 'optimistic'; await state.copyVersion();
    assert.equal(state.readOnly.value, false); assert.equal(state.scenarioKey.value, 'optimistic'); assert.equal(state.scenarioVersion.value, 3);
    assert.equal(state.readback.value, 'exact'); assert.equal(events.filter(row => row[0] === 'saved').length, 1);
});

test('cash plan month generation keeps same-month inputs and percentage loan rate converts without zero-filling', async () => {
    const { state } = create(async () => ok(saved())); await state.loadScenario();
    state.form.value.as_of = '2026-10-01'; state.addCashPlan(); state.form.value.cash_plan.months = '2'; state.generateMonths();
    assert.equal(state.form.value.cash_plan.monthly_inputs[0].operating_net_cash, '');
    state.form.value.cash_plan.monthly_inputs[0].capex_cash = '0'; state.generateMonths();
    assert.equal(state.form.value.cash_plan.monthly_inputs[0].capex_cash, '0'); state.addLoan();
    Object.assign(state.form.value.cash_plan.loans[0], { principal: '1000.01', annual_rate: '12', term_months: '2', funding: 'existing' });
    const input = state.toInput(state.form.value);
    assert.equal(input.cash_plan.loans[0].annual_rate, .12); assert.equal(input.cash_plan.loans[0].principal, 1000.01);
    assert.equal(input.cash_plan.monthly_inputs[0].operating_net_cash, null); assert.equal(input.cash_plan.monthly_inputs[0].capex_cash, 0);
    assert.equal(input.cash_plan.monthly_inputs[1].other_net_cash, null);
});

test('actual consumables reference stays pending until explicit adoption and rejects another hotel', async () => {
    const { state } = createWithReferences(async () => ok(saved())); await state.loadScenario();
    const data = { hotel_id: 81, snapshot_id: 5, content_digest: 'a'.repeat(64), business_month: '2026-09', actual_consumables_cost_per_room_night: '3.25' };
    state.receiveActualCost({ detail: data }); assert.equal(state.actualCostReference.value, null);
    data.hotel_id = 80; state.receiveActualCost({ detail: data }); assert.equal(state.form.value.consumables_cost, null);
    state.adoptActualCost(); const input = state.toInput(state.form.value);
    assert.equal(input.cost_evidence_snapshot_id, 5); assert.equal(input.cost_evidence_confirmed, true);
    assert.equal(input.consumables_cost.items[0].package_price, 3.25); assert.equal(input.consumables_cost.other_variable_cost_per_night, null);
    assert.equal(input.operating_cost_basis, 'occupied_room_night'); assert.equal(state.readback.value, 'exact');
    assert.equal(state.dirty.value, true);
});

for (const change of ['manual_mode', 'disabled_item']) {
    test(`editing an adopted actual-cost reference through ${change} clears its binding before save and exact readback`, async () => {
        let snapshot = saved(), submitted;
        const { state } = createWithReferences(async (path, options) => {
            if (options?.method === 'POST') {
                submitted = JSON.parse(options.body).scenario;
                if (submitted.cost_evidence_snapshot_id !== null || submitted.cost_evidence_digest !== '' || submitted.cost_evidence_confirmed !== false) {
                    return { code: 422, message: '实际耗材引用必须保持独立月度成本计量项' };
                }
                snapshot = saved(submitted, 1, result(submitted), 3);
            }
            return ok(snapshot);
        });
        await state.loadScenario();
        state.receiveActualCost({ detail: { hotel_id: 80, snapshot_id: 5, content_digest: 'a'.repeat(64), business_month: '2026-09', actual_consumables_cost_per_room_night: 3.25 } });
        state.adoptActualCost();
        assert.equal(state.form.value.cost_evidence_snapshot_id, 5);
        const row = state.form.value.consumables_cost.items[0];
        const control = change === 'manual_mode'
            ? source.match(/<select\b[^>]*data-testid="scenario-consumables-mode"[^>]*>/)?.[0]
            : source.match(/<input\b[^>]*:data-testid="'consumable-enabled-' \+ index"[^>]*>/)?.[0];
        const handler = control?.match(/@change="([^"]+)"/)?.[1];
        assert.ok(handler, 'the actual control must clear the adopted reference when its value changes');
        if (change === 'manual_mode') state.form.value.consumables_cost.mode = 'manual';
        else row.enabled = false;
        new Function('state', 'row', `with (state) { ${handler} }`)(state, row);
        assert.equal(state.form.value.cost_evidence_snapshot_id, null);
        assert.equal(state.form.value.cost_evidence_digest, '');
        assert.equal(state.form.value.cost_evidence_confirmed, false);
        state.form.value.scenario_name = '合成编辑后的独立假设';
        await state.save();
        assert.equal(state.error.value, '');
        assert.equal(state.readback.value, 'exact');
        assert.equal(state.scenarioVersion.value, 3);
        assert.equal(submitted.scenario_name, '合成编辑后的独立假设');
        assert.equal(submitted.consumables_cost.mode, change === 'manual_mode' ? 'manual' : 'derived');
        assert.equal(submitted.consumables_cost.items[0].enabled, change !== 'disabled_item');
        assert.equal(state.form.value.consumables_cost.items[0].package_price, '3.25');
        assert.equal(state.form.value.cost_evidence_snapshot_id, null);
    });
}

test('cross-page pending consumables DTO is consumed only by a matching hotel after a successful project read', async () => {
    const data = { hotel_id: 80, snapshot_id: 5, content_digest: 'a'.repeat(64), business_month: '2026-09', actual_consumables_cost_per_room_night: '3.25' };
    const { state, props, fixtureWindow } = createWithReferences(async path => ok(saved(null, path.includes('/projects/2/') ? 2 : 1)), { id: 1, version: 7, hotel_id: 81 }, { window: { SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE: data } });
    await state.loadScenario(); assert.equal(state.actualCostReference.value, null); assert.equal(fixtureWindow.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE, data);
    props.project = { id: 2, version: 7, hotel_id: 80 }; await state.loadScenario({ projectChanged: true });
    assert.equal(fixtureWindow.SUXI_PENDING_ACTUAL_CONSUMABLES_REFERENCE, null); assert.equal(state.actualCostReference.value.snapshot_id, 5);
    assert.equal(state.form.value.consumables_cost, null); assert.equal(state.form.value.cost_evidence_confirmed, false);
});

test('procurement reference loads only on demand and requires explicit item and tier without replacing a draft', async () => {
    const calls = [];
    const { state, events } = createWithReferences(async path => { calls.push(path); return ok(path.endsWith('/consumables-reference') ? procurementCatalog() : saved()); });
    await state.loadScenario();
    state.form.value.operating_cost_per_night = '17';
    assert.equal(calls.length, 1);
    await state.loadProcurementReference();
    assert.equal(calls[1], '/investment-payback/projects/1/scenario/consumables-reference');
    assert.equal(state.procurementItemId.value, '');
    assert.equal(state.procurementTierId.value, '');
    state.addProcurementConsumable();
    assert.equal(state.form.value.consumables_cost, null);
    state.procurementItemId.value = 'test-liquid';
    state.addProcurementConsumable();
    assert.equal(state.form.value.consumables_cost, null);
    state.procurementTierId.value = 'B';
    state.addProcurementConsumable();
    const input = state.toInput(state.form.value), row = input.consumables_cost.items[0];
    assert.equal(input.operating_cost_per_night, 17);
    assert.equal(input.consumables_cost.mode, 'manual');
    for (const field of ['package_price', 'package_quantity', 'usage_quantity']) assert.equal(row[field], null);
    assert.equal(row.as_of, ''); // Empty date is normalized to null by the server.
    assert.equal(row.unit, '');
    assert.equal(row.procurement_reference.confirmed_for_scenario, false);
    assert.equal(row.procurement_reference.raw_text, undefined); // Only server canonicalizes the snapshot.
    assert.equal(state.procurementDetails(state.form.value.consumables_cost.items[0]).raw_text, '20kg 150-220');
    assert.equal(state.dirty.value, true);
    assert.equal(events.some(event => event[0] === 'busy-change' && event[1] === true), true);
    await state.loadProcurementReference();
    assert.equal(calls.length, 2); // Cached catalog does not reload on repeated expansion.
});

test('optional procurement advice does not become a free item and existing derived mode is retained', async () => {
    const { state } = createWithReferences(async path => ok(path.endsWith('/consumables-reference') ? procurementCatalog() : saved()));
    await state.loadScenario(); state.addConsumable();
    state.form.value.consumables_cost.mode = 'derived';
    await state.loadProcurementReference();
    state.procurementItemId.value = 'test-liquid'; state.procurementTierId.value = 'C'; state.addProcurementConsumable();
    const input = state.toInput(state.form.value), row = input.consumables_cost.items[1];
    assert.equal(input.consumables_cost.mode, 'derived');
    assert.equal(row.package_price, null);
    assert.equal(state.procurementDetails(state.form.value.consumables_cost.items[1]).raw_text, '可不配');
    assert.equal(state.selectedProcurementRecommendations.value.length, 0);
});

test('procurement failure preserves inputs and retries while rejecting a different catalog identity', async () => {
    let attempts = 0;
    const { state } = createWithReferences(async path => {
        if (!path.endsWith('/consumables-reference')) return ok(saved());
        if (++attempts === 1) throw new Error('合成读取失败');
        return ok(attempts === 2 ? { ...procurementCatalog(), source_sha256: 'wrong' } : procurementCatalog());
    });
    await state.loadScenario(); state.form.value.rooms = '11';
    await state.loadProcurementReference();
    assert.match(state.procurementError.value, /请重试.*输入已保留/);
    assert.equal(state.form.value.rooms, '11'); assert.equal(state.busy.value, false);
    await state.loadProcurementReference();
    assert.equal(state.procurementCatalog.value, null);
    assert.match(state.procurementError.value, /来源身份/);
    await state.loadProcurementReference();
    assert.equal(state.procurementError.value, ''); assert.equal(state.procurementCatalog.value.project_id, 1);
});

test('late procurement responses cannot populate a different project and mismatched scoped responses are rejected', async () => {
    let resolveCatalog;
    const { state, props } = createWithReferences(async path => path.endsWith('/consumables-reference')
        ? new Promise(resolve => { resolveCatalog = resolve; })
        : ok(saved(null, path.includes('/projects/2/') ? 2 : 1)));
    await state.loadScenario(); const pending = state.loadProcurementReference();
    assert.equal(state.busy.value, true);
    props.project = { id: 2, version: 7 }; await state.loadScenario({ projectChanged: true });
    resolveCatalog(ok(procurementCatalog(1))); await pending;
    assert.equal(state.procurementCatalog.value, null); assert.equal(state.procurementLoading.value, false);
    assert.equal(state.procurementItemId.value, ''); assert.equal(state.procurementOpen.value, false);
    props.request = async () => ok(procurementCatalog(1));
    await state.loadProcurementReference();
    assert.equal(state.procurementCatalog.value, null); assert.match(state.procurementError.value, /项目不一致/);
});

test('procurement canonical snapshots save and read back without mutable aliasing or losing disabled rows and CSV provenance', async () => {
    let snapshot;
    const catalog = procurementCatalog();
    const canonicalReference = { catalog_id: catalog.catalog_id, source_sha256: catalog.source_sha256, item_id: 'test-liquid', tier_id: 'B', confirmed_for_scenario: true,
        item_name: '合成测试补液', tier_label: '合成档位B', source_cell: 'D23', raw_text: '20kg 150-220', quote_effective_date: null,
        recommendations: catalog.items[0].recommendations, interpretation_limits: catalog.items[0].interpretation_limits, usage_policy: 'reference_only' };
    const { state } = createWithReferences(async (path, options) => {
        if (path.endsWith('/consumables-reference')) return ok(catalog);
        if (options?.method === 'POST') {
            const input = JSON.parse(options.body).scenario;
            input.consumables_cost.items[1].procurement_reference = JSON.parse(JSON.stringify(canonicalReference));
            snapshot = saved(input, 1, { ...result(input), consumables_cost: { status: 'ready', items: input.consumables_cost.items, consumables_per_night: 0.9 } });
        }
        return ok(snapshot || saved());
    });
    await state.loadScenario(); state.addConsumable(); state.form.value.consumables_cost.items[0].enabled = false;
    await state.loadProcurementReference(); state.procurementItemId.value = 'test-liquid'; state.procurementTierId.value = 'B'; state.addProcurementConsumable();
    Object.assign(state.form.value.consumables_cost.items[1], { package_price: '30', package_quantity: '500', usage_quantity: '10', unit: 'ml', as_of: '2026-10-02' });
    state.form.value.consumables_cost.items[1].procurement_reference.confirmed_for_scenario = true;
    await state.save();
    assert.equal(state.readback.value, 'exact'); assert.equal(state.dirty.value, false);
    const row = state.form.value.consumables_cost.items[1];
    assert.equal(row.as_of, '2026-10-02'); assert.equal(row.procurement_reference.quote_effective_date, null);
    assert.equal(state.form.value.consumables_cost.items[0].enabled, false);
    const csv = state.buildCsv();
    for (const text of ['原档位报价', '20kg 150-220', 'D23', catalog.source_sha256, '合成布局建议', '已确认本方案参数']) assert.ok(csv.includes(text));
    const submitted = state.toInput(state.form.value);
    submitted.consumables_cost.items[1].procurement_reference.recommendations[0].raw_text = '提交对象修改';
    assert.equal(row.procurement_reference.recommendations[0].raw_text, '合成布局建议；不代表同SKU');
    state.clearProcurementConfirmation(row);
    assert.equal(row.procurement_reference.confirmed_for_scenario, false);
    assert.equal(state.result.value.input.consumables_cost.items[1].procurement_reference.confirmed_for_scenario, true);
    assert.equal(state.stale.value, true); assert.equal(state.buildCsv(), '');
});

test('procurement references respect read authority, shared busy state and the 100-row bound', async () => {
    let requests = 0;
    const { state, props } = createWithReferences(async path => { requests++; return ok(path.endsWith('/consumables-reference') ? procurementCatalog() : saved()); });
    state.writeBlocked.value = true;
    await state.loadProcurementReference(); assert.equal(requests, 0);
    await state.loadScenario(); props.ledgerBusy = true;
    await state.loadProcurementReference(); assert.equal(requests, 1);
    props.ledgerBusy = false; props.project.archived_at = '2026-10-02';
    await state.loadProcurementReference(); assert.equal(requests, 1);
    delete props.project.archived_at;
    await state.loadProcurementReference(); state.procurementItemId.value = 'test-liquid'; state.procurementTierId.value = 'B';
    for (let count = 0; count < 100; count++) state.addConsumable();
    state.addProcurementConsumable();
    assert.equal(state.form.value.consumables_cost.items.length, 100);
    assert.equal(state.form.value.consumables_cost.items.some(row => row.procurement_reference), false);
});

test('compiled procurement defaults remain strict booleans and preserve pending unknown fields', async () => {
    const { state } = createWithReferences(async path => ok(path.endsWith('/consumables-reference') ? procurementCatalog() : saved()), undefined, { compiled: true });
    await state.loadScenario(); await state.loadProcurementReference();
    state.procurementItemId.value = 'test-liquid'; state.procurementTierId.value = 'B'; state.addProcurementConsumable();
    const row = state.toInput(state.form.value).consumables_cost.items[0];
    assert.equal(row.enabled, true); assert.equal(row.procurement_reference.confirmed_for_scenario, false); assert.equal(row.package_price, null);
});

test('compiled production component preserves enabled and reference flags as API booleans', () => {
    const { state } = create(async () => ok(saved()), undefined, { compiled: true });
    state.addConsumable();
    assert.equal(state.toInput(state.form.value).consumables_cost.items[0].enabled, true);
    assert.equal(state.toInput(state.form.value).reference_example, false);
    state.form.value.consumables_cost.items[0].enabled = false;
    assert.equal(state.toInput(state.form.value).consumables_cost.items[0].enabled, false);
});

test('consumable rows remain unknown until filled, save with conversion and edit without losing disabled rows', async () => {
    let snapshot;
    const { state } = create(async (path, options) => {
        if (options?.method === 'POST') {
            const input = JSON.parse(options.body).scenario;
            snapshot = saved(input, 1, { ...result(input), consumables_cost: { status: 'ready', items: input.consumables_cost.items, consumables_per_night: 0.9, known_subtotal_per_night: 0.9 }, effective_operating_cost_per_night: 1.9 });
            return ok(snapshot);
        }
        return ok(snapshot || saved());
    });
    await state.loadScenario();
    assert.equal(state.toInput(state.form.value).consumables_cost, null);
    state.form.value.operating_cost_per_night = '9';
    state.addConsumable();
    let input = state.toInput(state.form.value);
    assert.equal(input.consumables_cost.mode, 'manual');
    assert.equal(input.consumables_cost.items[0].package_price, null);
    assert.equal(input.consumables_cost.other_variable_cost_per_night, null);
    Object.assign(state.form.value.consumables_cost.items[0], { name: '合成洗液', package_price: '30', package_quantity: '500', unit: 'ml', usage_quantity: '10', usage_basis: 'guest_night', occurrences_per_occupied_night: '1.5', source_label: '合成测试', as_of: '2026-10-01' });
    state.form.value.consumables_cost.mode = 'derived';
    state.form.value.consumables_cost.other_variable_cost_per_night = '1';
    state.addConsumable();
    state.form.value.consumables_cost.items[1].enabled = false;
    await state.save();
    assert.equal(state.readback.value, 'exact');
    assert.equal(state.form.value.operating_cost_per_night, '9');
    assert.equal(state.form.value.consumables_cost.items[0].occurrences_per_occupied_night, '1.5');
    assert.equal(state.form.value.consumables_cost.items[1].enabled, false);
    assert.match(state.buildCsv(), /易耗品采用方式/);
    state.form.value.consumables_cost.items[0].usage_quantity = '11';
    assert.equal(state.stale.value, true);
    assert.equal(state.buildCsv(), '');
    await state.save();
    assert.equal(state.form.value.consumables_cost.items[0].usage_quantity, '11');
    assert.equal(state.stale.value, false);
});

test('scenario save refreshes the project version while keeping the visible scenario and cash form', async () => {
    const registry = {};
    const Vue = { ref: value => ({ value }), reactive: value => value, computed: getter => ({ get value() { return getter(); } }), onMounted: () => {}, watch: () => {} };
    const parent = fs.readFileSync(new URL('../../public/components/system/investment-payback.js', import.meta.url), 'utf8');
    vm.runInNewContext(parent, { Vue, window: { SUXI_SYSTEM_COMPONENTS: registry }, Intl, Date, Number, Object });
    const state = registry.InvestmentPaybackBody.setup({ request: async path => ok(path.includes('/projects/7?') ? { project: { id: 7, version: 8 }, entries: [], summary: {} } : { list: [], pagination: { total: 0 } }), hotels: [] });
    state.detail.value = { project: { id: 7, version: 7 }, entries: [], summary: {} };
    state.scenarioOpened.value = true;
    const entry = { amount: '500.00', notes: '仍在录入的资金记录' };
    state.entryForm.value = entry;
    await state.scenarioSaved({ project_id: 7 });
    assert.equal(state.detail.value.project.version, 8);
    assert.equal(state.scenarioOpened.value, true);
    assert.equal(state.entryForm.value, entry);
});

test('ending shortfall remains visible when the first recovery is followed by a cash deficit', () => {
    const { state } = create(async () => ok(saved()));
    state.result.value = { totals: { ending_cumulative_cash_proxy: -500 }, payback: { operating_years: 1, remaining_cash: 0 } };
    assert.equal(state.endingUnrecovered.value, 500);
    state.result.value.totals.ending_cumulative_cash_proxy = 200;
    assert.equal(state.endingUnrecovered.value, 0);
    state.result.value = null;
    assert.equal(state.endingUnrecovered.value, null);
});

test('new and old projects start blank without implicit Qingyuan numbers or invented zeros', async () => {
    const { state } = create(async () => ok(saved()));
    await state.loadScenario();
    for (const key of ['rooms', 'adr_first_year', 'occupancy_first_year', 'renovation_cash', 'management_fee_rate']) assert.equal(state.form.value[key], '');
    const input = state.toInput(state.form.value);
    assert.equal(input.rooms, null);
    assert.equal(input.renovation_cash, null);
    assert.equal(input.reference_example, false);
    assert.equal(state.result.value, null);
});

test('reference numbers load only on explicit action and new blank clears provenance and result', async () => {
    const calls = [];
    const { state } = create(async path => {
        calls.push(path);
        return ok(path.includes('reference-example') ? { input: { rooms: 122, occupancy_first_year: 0.75, management_fee_rate: 0.035, source_label: '清远原表（2022）' } } : saved());
    });
    await state.loadScenario();
    assert.equal(calls.length, 1);
    await state.loadReference();
    assert.equal(state.form.value.rooms, '122');
    assert.equal(state.form.value.occupancy_first_year, '75');
    assert.equal(state.form.value.management_fee_rate, '3.5');
    assert.equal(state.form.value.reference_example, true);
    assert.match(state.notice.value, /2022.*非本酒店事实/);
    state.result.value = result(state.toInput(state.form.value));
    state.newBlank();
    assert.equal(state.form.value.rooms, '122');
    assert.equal(state.pendingReplacement.value.kind, 'blank');
    await state.confirmReplacement();
    assert.equal(state.form.value.rooms, '');
    assert.equal(state.form.value.source_label, '');
    assert.equal(state.form.value.reference_example, false);
    assert.equal(state.result.value, null);
});

test('percentage inputs convert to ratios while empty cash adjustments stay unknown', async () => {
    const { state } = create(async () => ok(saved()));
    await state.loadScenario();
    state.form.value.occupancy_first_year = '75';
    state.form.value.management_fee_rate = '3.5';
    state.addEscalation(); state.form.value.rent_escalations[0] = { year: '7', rate: '8' };
    state.addAdjustment(); state.form.value.cash_adjustments[0].year = '1';
    state.form.value.cash_adjustments[0].tax_cash = '0';
    const input = state.toInput(state.form.value);
    assert.equal(input.occupancy_first_year, 0.75);
    assert.equal(input.management_fee_rate, 0.035);
    assert.equal(input.rent_escalations[0].rate, 0.08);
    assert.equal(input.cash_adjustments[0].tax_cash, 0);
    assert.equal(input.cash_adjustments[0].financing_net_cash, null);
});

test('preview is not a save, retains input and becomes stale after editing', async () => {
    const calls = [];
    const { state } = create(async (path, options) => {
        calls.push({ path, options });
        if (options.method === 'POST') {
            assert.equal(options.withBusinessContext, false);
            const scenario = JSON.parse(options.body).scenario;
            return ok(saved(scenario, 1, result(scenario)));
        }
        return ok(saved());
    });
    await state.loadScenario();
    state.form.value.adr_first_year = '200';
    await state.preview();
    assert.match(calls[1].path, /\/scenario\/preview$/);
    assert.equal(state.stale.value, false);
    assert.match(state.buildCsv(), /12000/);
    state.form.value.adr_first_year = '201';
    assert.equal(state.stale.value, true);
    assert.equal(state.buildCsv(), '');
    assert.equal(state.form.value.adr_first_year, '201');
});

test('save rereads the same snapshot and emits saved only after exact verification', async () => {
    const calls = [];
    let snapshot = saved();
    const { state, events } = create(async (path, options) => {
        calls.push({ path, options });
        if (options.method === 'POST') {
            const payload = JSON.parse(options.body);
            assert.equal(payload.expected_version, 7);
            snapshot = saved(payload.scenario, 1, result(payload.scenario), 3);
            snapshot.project_version = 8;
        }
        return ok(snapshot);
    });
    await state.loadScenario();
    state.form.value.scenario_name = '可继续编辑的方案';
    await state.save();
    assert.equal(calls.length, 3);
    assert.equal(calls[2].options.method, undefined);
    assert.equal(state.readback.value, 'exact');
    assert.equal(state.scenarioVersion.value, 3);
    assert.equal(state.dirty.value, false);
    assert.equal(events.filter(event => event[0] === 'saved').length, 1);
    state.form.value.scenario_name = '修改后的方案';
    assert.equal(state.dirty.value, true);
    assert.equal(state.stale.value, true);
});

test('readback mismatch preserves edited inputs and blocks duplicate overwrite', async () => {
    let reads = 0, writes = 0;
    const { state, events } = create(async (path, options) => {
        if (options.method === 'POST') { writes++; return ok(saved(JSON.parse(options.body).scenario)); }
        if (++reads === 1) return ok(saved());
        return ok({ ...saved({ rooms: 999 }), content_digest: 'different-digest' });
    });
    await state.loadScenario(); state.form.value.rooms = '122';
    await state.save();
    assert.equal(state.form.value.rooms, '122');
    assert.equal(state.writeBlocked.value, true);
    assert.match(state.error.value, /精确回读未通过/);
    await state.save();
    assert.equal(writes, 1);
    assert.equal(events.filter(event => event[0] === 'saved').length, 0);
});

test('422 validation failure stays visible and 409 conflict never retries automatically', async () => {
    let code = 422, writes = 0;
    const { state } = create(async (path, options) => options.method === 'POST' ? (writes++, { code, message: code === 422 ? '入住率超出范围' : '项目版本冲突' }) : ok(saved()));
    await state.loadScenario(); state.form.value.occupancy_first_year = '175';
    await state.save();
    assert.match(state.error.value, /422.*入住率超出范围/);
    assert.equal(state.form.value.occupancy_first_year, '175');
    assert.equal(state.writeBlocked.value, false);
    code = 409; await state.save();
    assert.match(state.error.value, /409.*未自动重试覆盖/);
    assert.equal(state.writeBlocked.value, true);
    await state.save(); assert.equal(writes, 2);
});

test('late reads from a previous project cannot fill the current project', async () => {
    let resolveFirst;
    const { state, props } = create(path => path.includes('/projects/1/') ? new Promise(resolve => { resolveFirst = resolve; }) : Promise.resolve(ok(saved({ rooms: 80 }, 2))));
    const first = state.loadScenario();
    props.project = { id: 2, version: 1, project_name: '另一个项目' };
    await state.loadScenario();
    resolveFirst(ok(saved({ rooms: 122 }, 1))); await first;
    assert.equal(state.form.value.rooms, '80');
    assert.equal(state.loading.value, false);
});

test('the same form cannot issue concurrent saves and archived projects remain read only', async () => {
    let resolveWrite, writes = 0;
    const { state, props } = create(async (path, options) => {
        if (options.method === 'POST') { writes++; return new Promise(resolve => { resolveWrite = resolve; }); }
        return ok(saved());
    });
    await state.loadScenario(); const first = state.save(); await state.save();
    assert.equal(writes, 1);
    resolveWrite({ code: 422, message: '输入不完整' }); await first;
    props.project.archived_at = '2026-10-01'; await state.save(); await state.preview(); await state.loadReference(); state.newBlank();
    assert.equal(writes, 1);
    assert.equal(state.readOnly.value, true);
});

test('read failure is visible and cannot become an editable blank success', async () => {
    const { state } = create(async () => { throw new Error('网络读取失败'); });
    await state.loadScenario();
    assert.match(state.error.value, /测算读取失败.*网络读取失败/);
    assert.equal(state.writeBlocked.value, true);
    assert.equal(state.result.value, null);
});

test('replacement asks before losing a draft and cancellation makes no request', async () => {
    const calls = [];
    const { state } = create(async path => { calls.push(path); return ok(path.includes('reference-example') ? { input: { rooms: 153 } } : saved()); });
    await state.loadScenario(); state.form.value.scenario_name = '保留的经营草稿';
    await state.loadReference();
    assert.equal(calls.length, 1);
    assert.equal(state.pendingReplacement.value.kind, 'reference');
    state.cancelReplacement();
    assert.equal(state.form.value.scenario_name, '保留的经营草稿');
    await state.loadScenario();
    assert.equal(calls.length, 1);
    assert.equal(state.pendingReplacement.value.kind, 'reload');
    state.cancelReplacement();
    await state.loadReference(); await state.confirmReplacement();
    assert.equal(calls.length, 2);
    assert.equal(state.form.value.rooms, '153');
    assert.equal(state.pendingReplacement.value, null);
});

test('failed draft reload retains inputs and results until a successful exact read', async () => {
    let fail = false;
    const input = { scenario_name: '已保存方案', rooms: 80 };
    const { state } = create(async () => { if (fail) throw new Error('暂时断网'); return ok(saved(input, 1, result(input))); });
    await state.loadScenario(); state.form.value.rooms = '81';
    fail = true; await state.loadScenario(); await state.confirmReplacement();
    assert.equal(state.form.value.rooms, '81');
    assert.equal(state.result.value.annual_rows.length, 1);
    assert.equal(state.dirty.value, true);
    assert.equal(state.writeBlocked.value, true);
    assert.match(state.error.value, /当前输入已保留/);
    fail = false; await state.loadScenario(); await state.confirmReplacement();
    assert.equal(state.form.value.rooms, '80');
    assert.equal(state.dirty.value, false);
    assert.equal(state.writeBlocked.value, false);
});

test('fixed cost appears only for its applicable basis without deleting an earlier value', async () => {
    const { state } = create(async () => ok(saved({ fixed_annual_operating_cost: 900, operating_cost_basis: 'fixed_variable' })));
    await state.loadScenario();
    const group = state.groups.find(group => group.name === '经营假设');
    assert.ok(state.visibleFields(group).some(field => field.key === 'fixed_annual_operating_cost'));
    state.form.value.operating_cost_basis = 'occupied_room_night';
    assert.equal(state.visibleFields(group).some(field => field.key === 'fixed_annual_operating_cost'), false);
    assert.equal(state.toInput(state.form.value).fixed_annual_operating_cost, 900);
    state.result.value = { missing_fields: ['rooms'], annual_rows: [{ year: 1, cash_adjustments_missing: ['tax_cash', 'deposit_refund'] }] };
    assert.equal(state.cashAdjustmentGaps.value[0].count, 2);
    assert.equal(state.groupMissing(group), 0); // Earlier result is stale; do not label new inputs with old gaps.
});

test('adjusted ending deficit is separate from first recovery and stays in the CSV', async () => {
    const input = { scenario_name: '后期再亏损' };
    const output = { ...result(input), scenario_payback: { operating_years: 1, total_years: 1 }, totals: { ending_cumulative_cash_proxy: 100, ending_cumulative_scenario_cashflow: -1234 } };
    const { state } = create(async () => ok(saved(input, 1, output)));
    await state.loadScenario();
    assert.equal(state.adjustedEndingUnrecovered.value, 1234);
    assert.equal(state.endingUnrecovered.value, 0);
    assert.match(state.buildCsv(), /期末完整调整后未收回（元）","1234/);
});

test('a preview export never claims an older saved version belongs to the new result', async () => {
    const { state } = create(async (path, options) => {
        if (path.endsWith('/preview')) { const input = JSON.parse(options.body).scenario; return ok({ project_id: 1, result: result(input) }); }
        return ok(saved({ scenario_name: '已存方案' }, 1, result({ scenario_name: '已存方案' }), 9));
    });
    await state.loadScenario(); state.form.value.scenario_name = '新预览方案'; await state.preview();
    assert.match(state.buildCsv(), /"结果状态","预览，未保存"/);
    assert.match(state.buildCsv(), /"本结果保存版本",""/);
    assert.match(state.buildCsv(), /"项目已存测算版本","9"/);
});

test('historical snapshots stay identified until the user requests a current-model preview', async () => {
    const input = { scenario_name: '历史方案' };
    const { state } = create(async path => ok(path.endsWith('/preview') ? { project_id: 1, result: result(input) } : { ...saved(input, 1, result(input)), model_status: 'historical_snapshot' }));
    await state.loadScenario();
    assert.equal(state.historicalModel.value, true);
    assert.match(state.buildCsv(), /"模型状态","历史快照"/);
    await state.preview();
    assert.equal(state.historicalModel.value, false);
    assert.match(state.buildCsv(), /"模型状态","当前模型"/);
    assert.equal(state.readback.value, '');
});

test('blocked downloads retain exact CSV and unavailable clipboard offers manual recovery', async () => {
    const input = { scenario_name: '导出方案' };
    const { state } = create(async () => ok(saved(input, 1, result(input))), undefined, { URL: { createObjectURL: () => { throw new Error('blocked'); } }, Blob: class {}, navigator: {} });
    await state.loadScenario(); const expected = state.buildCsv();
    state.exportCsv();
    assert.equal(state.exportContent.value, expected);
    assert.match(state.exportMessage.value, /未能启动下载/);
    await state.copyCsv();
    assert.match(state.exportMessage.value, /手动复制/);
    state.form.value.scenario_name = '已修改';
    assert.equal(state.stale.value, true);
    assert.equal(state.buildCsv(), '');
    await state.copyCsv();
    assert.match(state.exportMessage.value, /手动复制/);
});

test('clipboard completion after project change cannot claim the new project was exported', async () => {
    let resolveCopy, copied;
    const input = { scenario_name: '旧项目导出' };
    const { state, props } = create(async path => ok(saved(input, path.includes('/projects/2/') ? 2 : 1, result(input))), undefined, { URL: { createObjectURL: () => { throw new Error('blocked'); } }, Blob: class {}, navigator: { clipboard: { writeText: text => { copied = text; return new Promise(resolve => { resolveCopy = resolve; }); } } } });
    await state.loadScenario(); state.exportCsv();
    const pending = state.copyCsv();
    assert.equal(copied, state.buildCsv());
    props.project = { id: 2, version: 1, project_name: '新项目' };
    await state.loadScenario(); resolveCopy(); await pending;
    assert.equal(state.exportContent.value, '');
    assert.equal(state.exportMessage.value, '');
});

test('embedded browsers copy the visible CSV through the selection command before falling back', async () => {
    let selected = false, focused = false;
    const input = { scenario_name: '复制兼容性' };
    const { state } = create(async () => ok(saved(input, 1, result(input))), undefined, {
        URL: { createObjectURL: () => { throw new Error('blocked'); } }, Blob: class {},
        document: { execCommand: command => { assert.equal(command, 'copy'); assert.ok(selected && focused); return true; } },
        navigator: { clipboard: { writeText: () => { throw new Error('Should use the supported selection path'); } } },
    });
    await state.loadScenario(); state.exportCsv();
    state.exportTextarea.value = { focus: () => { focused = true; }, select: () => { selected = true; } };
    await state.copyCsv();
    assert.match(state.exportMessage.value, /复制请求已发出/);
});

test('CSV uses displayed results and preserves scope, provenance and formula safety', async () => {
    const input = { scenario_name: '=HYPERLINK("bad")', as_of: '2026-10-01', source_label: '=2+2', occupancy_first_year: 0.75 };
    const { state } = create(async () => ok(saved(input, 1, result(input))), { id: 1, version: 7, project_name: '=2+2', hotel_id: 80 });
    await state.loadScenario();
    const csv = state.buildCsv();
    assert.match(csv, /scenario_assumption/);
    assert.match(csv, /test-content-digest/);
    assert.match(csv, /"'=2\+2"/);
    assert.match(csv, /"12000"/);
    assert.match(csv, /"0.75"/);
    assert.match(csv, /"-7000"/);
});

test('template compiles, mounts in the ledger, and keeps missing/proxy/readback boundaries visible', () => {
    const start = source.indexOf('        template: `') + '        template: `'.length;
    const end = source.indexOf('\n        `,', start);
    assert.match(compileFrontendTemplate(source.slice(start, end)), /return function render/);
    assert.match(source, /inputs_missing/);
    assert.match(source, /scenario-result-stale/);
    assert.match(source, /scenario_assumption/);
    assert.match(source, /scenario-adjusted-payback/);
    const parent = fs.readFileSync(new URL('../../public/components/system/investment-payback.js', import.meta.url), 'utf8');
    assert.match(parent, /InvestmentScenarioWorkbench\b[^>]*:key="detail\.project\.id"/);
    assert.match(parent, /scenario_saved: '保存经营测算'/);
    const loader = fs.readFileSync(new URL('../../public/components/system/business-closure-loader.js', import.meta.url), 'utf8');
    assert.ok(loader.indexOf("loadScript('investment-scenario.min.js") < loader.indexOf("loadScript('investment-payback.min.js"));
});
