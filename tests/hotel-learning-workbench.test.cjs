const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const Vue = require('vue');
const artifactPath = path.resolve(__dirname, '../public/components/system/hotel-learning-workbench.min.js');
const artifact = fs.readFileSync(artifactPath, 'utf8');
let nonce = 0;
const windowFixture = { SUXI_SYSTEM_COMPONENTS: {} };
const runtimeEnvironment = { window: windowFixture, Vue, URLSearchParams, crypto: { randomUUID: () => `synthetic-${++nonce}` }, console };
vm.runInNewContext(artifact, runtimeEnvironment);
const component = windowFixture.SUXI_SYSTEM_COMPONENTS.HotelLearningWorkbench;
const sourcePath = path.resolve(__dirname, '../public/components/system/hotel-learning-workbench.js');
const sourceWindow = { SUXI_SYSTEM_COMPONENTS: {} };
vm.runInNewContext(fs.readFileSync(sourcePath, 'utf8'), { ...runtimeEnvironment, window: sourceWindow });
const sourceComponent = sourceWindow.SUXI_SYSTEM_COMPONENTS.HotelLearningWorkbench;
sourceComponent.render = Vue.compile(sourceComponent.template);
delete sourceComponent.template;
const plain = value => JSON.parse(JSON.stringify(value));
const ok = data => ({ code: 200, data });
const kinds = { profile: 'jhira_profile', consumables_reconciliation: 'consumables_actual', investment_target: 'jhira_target', contract_review: 'jhira_contract', ota_scene: 'jhira_ota_scene', market_sample: 'jhira_market', operating_review: 'jhira_review', geo_observation: 'jhira_geo' };
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
function create(mode = 'profile', request, props = {}, implementation = component) {
    const state = { module: 'all', canExecute: true, selectedHotelId: 80, hotels: [{ id: 80, name: '合成测试酒店' }], ...props };
    Object.assign(state, implementation.data.call(state));
    for (const [name, method] of Object.entries(implementation.methods)) state[name] = method.bind(state);
    for (const [name, getter] of Object.entries(implementation.computed)) Object.defineProperty(state, name, { get: () => getter.call(state) });
    state.mode = mode; state.periodMonth = '2026-10'; state.hydrate({});
    state.request = request || (async () => ok(overview(state)));
    return state;
}
function scope(state) { return { tenant_id: 7, ...plain(state.scope), kind: kinds[state.mode] }; }
function overview(state, changes = {}) { return { scope: scope(state), latest: null, history: [], can_execute: true, ...changes }; }
function snapshot(state, changes = {}) { return { snapshot_id: 32, scope: scope(state), inputs: plain(state.inputs()), result: { mode: state.mode, status: 'recorded', source_quality: 'manual_reference' }, content_digest: 'a'.repeat(64), readback_verified: true, ...changes }; }
const fixtures = {
    profile: { fields: [{ key: 'room_count', value: '80', unit: '间', source_ref: '合成房量台账', as_of: '2026-10-01', quality: 'operator_attested' }] },
    consumables_reconciliation: { occupied_room_nights: 100, occupied_room_nights_source_ref: '合成全酒店账', cleaning_count: 80, cleaning_count_source_ref: '合成清洁单', operator_attested: true, denominator_scope: 'whole_hotel', items: [{ id: 'synthetic-item', enabled: true, name: '合成纸巾', unit: 'piece', source_ref: '合成盘点', source_date: '2026-10-01', opening_quantity: 100, purchased_quantity: 30, transfer_in_quantity: 0, closing_quantity: 70, transfer_out_quantity: 0, returned_quantity: 0, written_off_quantity: 0, issued_quantity: 55, book_closing_quantity: 75, issued_quantity_source_ref: '合成领用单', book_closing_quantity_source_ref: '合成库存账', unit_price: 2, budget_unit_price: null, budget_usage_per_room_night: null }] },
    investment_target: { scenario: { scenario_name: '合成测算', as_of: '2026-10-01', source_label: '合成假设', rooms: 80, occupancy_first_year: 0.6, occupancy_mature: 0.75, management_fee_rate: 0.03, adr_growth_rate: 0, operating_cost_growth_rate: 0.02, rent_escalations: [{ year: 3, rate: 0.05 }], cash_adjustments: [{ year: 1, tax_cash: 0, financing_net_cash: -10, maintenance_capex: 0, working_capital_change: 0, deposit_refund: 0, salvage_cash: 0 }] }, request: { solve_for: 'occupancy', target_payback_months: 60, bounds: { lower: 0.1, upper: 1 }, occupancy_shape: 'preserve_ratio' } },
    contract_review: { as_of: '2026-10-01', payback_months: 48, constraints: { contract_start_on: '2026-01-01', contract_end_on: '2036-12-31', contract_source: '合成合同', contract_confirmed: true, target_payback_months: 60 } },
    ota_scene: { scene: { keyword: '合成商圈', location: '合成定位', device: '手机', login_state: '未登录', sort: '推荐', filters: '无', platform_store_id: 'synthetic-store', source_ref: '合成截图', observed_at: '2026-10-01T09:30:00+08:00', check_in: '2026-10-02', check_out: '2026-10-03', page_capacity: 20 }, visibility: 'observed', rank_min: 1, rank_max: 3, observed_through_rank: 20, conversion_rate: 3.5, rate_unit: 'percentage_point', price: 200, price_terms: { room_type: '大床', cancellation: '可取消', breakfast: '双早', guest_count: '2', membership: '无', tax_basis: '含税', payment: '到店付' } },
    market_sample: { sample_ref: '合成样本', model_version: '合成算法v1', comparison_key: 'synthetic-comparison', comparison_attested: true, weights: { traffic: 0.4, conversion: 0.3, revenue: 0.3 }, hotels: [{ platform_store_id: 'synthetic-a', name: '合成甲', traffic: 100, conversion: 5, revenue: 200, comparison_key: 'synthetic-comparison', rate_unit: 'percentage_point' }, { platform_store_id: 'synthetic-b', name: '合成乙', traffic: 200, conversion: 6, revenue: 300, comparison_key: 'synthetic-comparison', rate_unit: 'percentage_point' }] },
    operating_review: { period_start: '2026-10-01', period_end: '2026-10-31', plan: { period_start: '2026-10-01', period_end: '2026-10-31', source_ref: '合成计划', basis: '全酒店', available_room_nights: 100, sold_room_nights: 80, revenue: 8000, operating_cost: 3000, debt_service: 100, project_net_cash: 4900, investor_received_cash: 0 }, actual: { period_start: '2026-10-01', period_end: '2026-10-31', source_ref: '合成实际', basis: '全酒店', available_room_nights: 100, sold_room_nights: 70, revenue: 7000, operating_cost: 3000, debt_service: 100, project_net_cash: 3900, investor_received_cash: 1000 } },
    geo_observation: { question: '合成酒店适合出差吗', model: '合成模型', model_version: '合成v1', region: '合成地区', network: '合成网络', observed_at: '2026-10-01 09:30+08:00', source_ref: '合成回答记录', response_summary: '合成摘要', citations: [{ url: 'https://example.org/synthetic-hotel', fact_consistency: 'unverified' }] },
};

test('compiled artifact exposes a runtime render function and each host starts in its first available mode', () => {
    assert.equal(typeof component.render, 'function');
    assert.equal(component.template, undefined);
    for (const [module, expected] of Object.entries({ knowledge: ['profile', 'geo_observation'], operations: ['consumables_reconciliation', 'operating_review'], investment: ['investment_target', 'contract_review'], ota: ['ota_scene', 'market_sample'] })) {
        const state = create('profile', null, { module });
        assert.equal(component.data.call(state).mode, expected[0]);
        assert.deepEqual(plain(state.availableModes.map(row => row.id)), expected);
    }
});

for (const [mode, input] of Object.entries(fixtures)) test(`compiled ${mode}: hydrate, edit inputs, and normalized readback retain the mode contract`, () => {
    const state = create(mode);
    state.hydrate(input);
    const first = plain(state.inputs());
    state.hydrate(first);
    assert.deepEqual(plain(state.inputs()), first);
    assert.equal(state.scope.platform, ['ota_scene', 'market_sample'].includes(mode) ? 'ctrip' : 'whole_hotel');
    if (mode === 'profile') assert.deepEqual(first, input);
    if (mode === 'contract_review') { assert.deepEqual(first, input); assert.equal(first.forward_result, undefined); }
    if (mode === 'consumables_reconciliation') { assert.equal(first.items[0].enabled, true); assert.equal(first.operator_attested, true); assert.equal(first.items[0].purchased_quantity, 30); assert.equal(first.items[0].budget_unit_price, null); }
    if (mode === 'investment_target') { assert.equal(state.form.scenario.occupancy_first_year, 60); assert.equal(state.form.request.bounds.upper, 100); assert.equal(first.request.bounds.upper, 1); assert.equal(first.scenario.rent_escalations[0].rate, 0.05); assert.equal(first.scenario.reference_example, false); assert.equal(first.project_id, undefined); }
    if (mode === 'ota_scene') { assert.equal(state.fields.find(row => row.path === 'scene.observed_at').type, 'datetime-local'); assert.equal(first.scene.observed_at, '2026-10-01T09:30:00'); }
    if (mode === 'market_sample') { assert.equal(state.form.weights.traffic, 40); assert.equal(first.hotels[0].comparison_key, first.comparison_key); assert.equal(first.rate_unit, 'percentage_point'); assert.equal(first.comparison_attested, true); }
    if (mode === 'operating_review') { assert.equal(first.plan.period_start, first.period_start); assert.equal(first.actual.period_end, first.period_end); assert.equal(first.plan.investor_received_cash, 0); }
    if (mode === 'geo_observation') { assert.equal(state.fields.find(row => row.path === 'observed_at').type, 'datetime-local'); assert.equal(first.observed_at, '2026-10-01T09:30'); }
});

test('compiled booleans remain booleans for checkboxes, true zero stays zero and blanks stay missing', () => {
    const state = create('consumables_reconciliation');
    state.addRow(state.groups[0]);
    assert.equal(state.form.items[0].enabled, true);
    state.form.operator_attested = true;
    state.form.items[0].unit_price = '0';
    let input = state.inputs();
    assert.equal(input.operator_attested, true);
    assert.equal(input.items[0].enabled, true);
    assert.equal(input.items[0].unit_price, 0);
    assert.equal(input.items[0].opening_quantity, null);
    state.form.items[0].enabled = false;
    state.form.operator_attested = false;
    input = state.inputs();
    assert.equal(input.items[0].enabled, false);
    assert.equal(input.operator_attested, false);
    assert.equal(state.display(false), '否'); assert.equal(state.display(0), '0'); assert.equal(state.display(null), '未取得');
});

test('market samples require manual scope confirmation and nonfinite numeric input fails visibly', () => {
    const state = create('market_sample'); state.hydrate(fixtures.market_sample);
    state.form.comparison_attested = false;
    assert.throws(() => state.inputs(), /同口径/);
    state.form.comparison_attested = true; state.form.weights.traffic = 'Infinity';
    assert.throws(() => state.inputs(), /有效数字/);
    state.hydrate({ ...fixtures.market_sample, hotels: [] });
    assert.equal(state.form.comparison_attested, false);
});

test('overview execute permission overrides the host prop and gates both preview and save', async () => {
    let posts = 0; const state = create();
    state.request = async () => { posts++; return ok({}); };
    state.overview = overview(state, { can_execute: false });
    assert.equal(state.effectiveCanExecute, false);
    await state.calculate(false); await state.calculate(true);
    assert.equal(posts, 0);
    state.canExecute = false; state.overview = overview(state, { can_execute: true });
    assert.equal(state.effectiveCanExecute, true);
    state.overview = null; assert.equal(state.effectiveCanExecute, false);
});

test('overview rejects a mismatched scope or malformed permission instead of replacing current scope', async () => {
    const state = create(); const wrong = overview(state); wrong.scope.hotel_id = 81;
    state.request = async () => ok(wrong); await state.load();
    assert.match(state.error, /范围不一致/); assert.equal(state.overview, null);
    state.request = async () => ok(overview(state, { can_execute: 1 })); await state.load();
    assert.match(state.error, /权限未取得/); assert.equal(state.overview, null);
    state.request = async () => ok(overview(state, { can_execute: false })); await state.load();
    assert.equal(state.overview.can_execute, false); assert.equal(state.busy, false);
});

test('save performs POST then exact GET and exports the same readback object', async () => {
    const state = create('contract_review'); state.hydrate(fixtures.contract_review);
    const calls = []; let saved;
    state.request = async (url, options) => {
        calls.push([url, options]);
        if (options?.method === 'POST') { const body = JSON.parse(options.body); assert.deepEqual(body.inputs, fixtures.contract_review); saved = snapshot(state); return ok(saved); }
        if (url.includes('/snapshots/')) return ok(saved);
        return ok(overview(state, { latest: saved, history: [saved] }));
    };
    await state.calculate(true);
    assert.equal(calls.length, 3); assert.match(calls[0][0], /\/snapshots$/); assert.match(calls[1][0], /\/snapshots\/32\?/); assert.match(calls[2][0], /\/overview\?/);
    assert.equal(state.record, saved); assert.equal(state.saved, saved); assert.equal(state.dirty, false); assert.equal(state.saveKey, null);
    assert.match(state.notice, /精确回读一致/); assert.equal(state.history.length, 1);
    const csv = state.csv(); assert.match(csv, /"snapshot_id","32"/); assert.match(csv, /"scope.hotel_id","80"/); assert.match(csv, /"scope.period_month","2026-10"/); assert.match(csv, /"inputs.constraints.contract_source","合成合同"/);
});

test('failed exact readback preserves an idempotency key and unchanged retry reuses it', async () => {
    const state = create(); state.hydrate(fixtures.profile);
    const keys = []; let reads = 0; const saved = snapshot(state);
    state.request = async (url, options) => {
        if (options?.method === 'POST') { keys.push(JSON.parse(options.body).idempotency_key); return ok(saved); }
        if (url.includes('/snapshots/')) { if (++reads === 1) throw new Error('合成回读中断'); return ok(saved); }
        return ok(overview(state));
    };
    await state.calculate(true);
    assert.match(state.error, /合成回读中断/); assert.equal(state.saved, null); assert.equal(state.resultCurrent, false); assert.ok(state.saveKey); assert.equal(state.busy, false);
    await state.calculate(true);
    assert.equal(keys.length, 2); assert.equal(keys[0], keys[1]); assert.equal(state.saved, saved); assert.equal(state.error, '');
});

for (const [label, mutate, message] of [
    ['digest', data => { data.content_digest = 'b'.repeat(64); }, /摘要不一致/],
    ['ID', data => { data.snapshot_id = 33; }, /ID不一致/],
    ['hotel', data => { data.scope.hotel_id = 81; }, /范围不一致/],
    ['month', data => { data.scope.period_month = '2026-09'; }, /范围不一致/],
    ['platform', data => { data.scope.platform = 'ctrip'; }, /范围不一致/],
    ['mode', data => { data.result.mode = 'geo_observation'; }, /模式不一致/],
    ['kind', data => { data.scope.kind = 'jhira_geo'; }, /类型不一致/],
    ['readback flag', data => { data.readback_verified = 1; }, /未核对/],
]) test(`exact save readback rejects mismatched ${label}`, async () => {
    const state = create(); const saved = snapshot(state); const bad = plain(saved); mutate(bad);
    state.request = async (url, options) => ok(options?.method === 'POST' ? saved : bad);
    await state.calculate(true);
    assert.match(state.error, message); assert.equal(state.saved, null); assert.equal(state.resultCurrent, false); assert.equal(state.busy, false);
});

test('editing invalidates saved results and retry identity; exporting a stale result is blocked', async () => {
    const state = create('contract_review'); state.hydrate(fixtures.contract_review);
    state.record = snapshot(state); state.saved = state.record; state.saveKey = 'old-key';
    state.setField(state.fields.find(row => row.path === 'payback_months'), '55');
    assert.equal(state.dirty, true); assert.equal(state.saved, null); assert.equal(state.saveKey, null); assert.equal(state.resultCurrent, false); assert.equal(state.csv(), '');
});

test('old consumables_actual snapshots without mode remain readable and editable', async () => {
    const state = create('consumables_reconciliation'); state.hydrate(fixtures.consumables_reconciliation);
    const legacy = snapshot(state); delete legacy.scope.mode; delete legacy.result.mode; delete legacy.inputs.cleaning_count; delete legacy.inputs.cleaning_count_source_ref;
    state.request = async () => ok(legacy); await state.restore(32);
    assert.equal(state.error, ''); assert.equal(state.saved, legacy); assert.equal(state.form.items[0].id, 'synthetic-item'); assert.equal(state.inputs().cleaning_count, null); assert.equal(state.inputs().denominator_scope, 'whole_hotel');
    state.setField(state.fields.find(row => row.path === 'cleaning_count'), '90');
    assert.equal(state.saved, null); assert.equal(state.inputs().cleaning_count, 90);
});

test('restoring history asks before replacing edited fields and restores only on confirmation', async () => {
    let calls = 0; const state = create(); state.hydrate(fixtures.profile); const saved = snapshot(state);
    state.form.fields[0].value = '草稿'; state.edit(); state.request = async () => { calls++; return ok(saved); };
    await state.restore(32); assert.equal(calls, 0); assert.equal(state.restoreRequested, 32); assert.equal(state.form.fields[0].value, '草稿');
    await state.restore(32, true); assert.equal(calls, 1); assert.equal(state.form.fields[0].value, '80'); assert.equal(state.saved, saved);
});

test('old overview responses cannot restore data or permission after a scope reset', async () => {
    const pending = deferred(); const state = create(); const old = overview(state, { can_execute: true, history: [{ snapshot_id: 99 }] });
    state.request = () => pending.promise; const oldRequest = state.load();
    state.hotelId = 81; state.request = async () => ok(overview(state, { can_execute: false })); state.resetScope();
    await Promise.resolve(); await Promise.resolve(); pending.resolve(ok(old)); await oldRequest;
    assert.equal(state.hotelId, 81); assert.equal(state.overview.scope.hotel_id, 81); assert.equal(state.effectiveCanExecute, false); assert.equal(state.history.length, 0);
});

test('an old POST cannot start a readback or install a saved result after switching platform', async () => {
    const pending = deferred(); const state = create('ota_scene'); state.hydrate(fixtures.ota_scene); const old = snapshot(state); let calls = 0;
    state.request = () => { calls++; return pending.promise; }; const saving = state.calculate(true);
    state.platform = 'meituan'; state.request = async () => ok(overview(state)); state.resetScope();
    pending.resolve(ok(old)); await saving;
    assert.equal(calls, 1); assert.equal(state.record, null); assert.equal(state.saved, null); assert.equal(state.scope.platform, 'meituan');
});

test('editing while preview is pending rejects its late response and retains the new input', async () => {
    const pending = deferred(); const state = create('contract_review'); state.hydrate(fixtures.contract_review); const old = snapshot(state);
    state.request = () => pending.promise; const calculating = state.calculate(false);
    state.setField(state.fields.find(row => row.path === 'payback_months'), '55'); pending.resolve(ok(old)); await calculating;
    assert.equal(state.form.payback_months, '55'); assert.equal(state.record, null); assert.equal(state.dirty, true); assert.equal(state.busy, false);
});

test('preview validates returned scope and a business error can be retried without changing inputs', async () => {
    const state = create(); state.hydrate(fixtures.profile); let count = 0;
    state.request = async () => { count++; if (count === 1) return { code: 422, message: '合成来源缺项' }; const data = snapshot(state); if (count === 2) data.scope.hotel_id = 81; return ok(data); };
    await state.calculate(false); assert.match(state.error, /合成来源缺项/); assert.equal(state.form.fields[0].value, '80');
    await state.calculate(false); assert.match(state.error, /范围不一致/); assert.equal(state.record, null);
    await state.calculate(false); assert.equal(state.error, ''); assert.equal(state.resultCurrent, true); assert.equal(state.saved, null);
});

test('result presentation selects business values, formats solver units and preserves missing data', () => {
    const state = create('investment_target'); state.hydrate(fixtures.investment_target);
    state.record = snapshot(state, { result: { mode: 'investment_target', status: 'solved', solved_value: 0.75, request: { solve_for: 'occupancy', target_payback_months: 60 }, target_reached: true, reason: 'bounded_minimum_feasible', solver_version: 'internal', contract_version: 'internal', external_write_authorized: false, forward_result: { annual_rows: Array.from({ length: 100 }, () => ({ revenue: 999 })), scenario_payback: { total_years: 5, operating_years: 4.5 } }, missing_fields: ['scenario.working_capital_cash'] } });
    let rows = plain(state.resultRows); assert.equal(rows[0][1], '75%'); assert.match(rows[0][0], /入住率/); assert.ok(rows.length < 15); assert.ok(!JSON.stringify(rows).includes('internal')); assert.ok(!JSON.stringify(rows).includes('999')); assert.match(JSON.stringify(rows), /初始营运资金/);
    state.record.result.request.solve_for = 'adr'; state.record.result.solved_value = 220.25;
    rows = plain(state.resultRows); assert.equal(rows[0][1], '220.25 元/间夜');
    state.record.result.solved_value = null; assert.equal(state.resultRows[0][1], null); assert.equal(state.display(state.resultRows[0][1]), '未取得');
    assert.equal(state.gapLabel('cash_adjustments.1.tax_cash'), '第1运营年 · 税费支出（元）');
});

test('business result rows keep consumables and contract gaps understandable without implementation flags', () => {
    const cost = create('consumables_reconciliation'); cost.hydrate(fixtures.consumables_reconciliation);
    cost.record = snapshot(cost, { result: { status: 'partial', items: [{ id: 'synthetic-item', name: '合成纸巾', unit: 'piece', consumed_quantity: null }], missing_items: ['whole_hotel_room_nights_source_missing', 'synthetic-item:source_evidence'], reconciliation: { status: 'partial', issued_cost: null, missing_items: ['cleaning_count_missing_or_zero'] }, contract_version: 'internal' } });
    const text = JSON.stringify(plain(cost.resultRows)); assert.match(text, /全酒店已售间夜来源缺失/); assert.match(text, /合成纸巾 · 库存来源与有效资料日期/); assert.match(text, /清洁次数缺失或为零/); assert.ok(!text.includes('internal'));
    const contract = create('contract_review'); contract.hydrate(fixtures.contract_review);
    contract.record = snapshot(contract, { result: { status: 'partial', calendar: { contract_status: 'unverified', contract_missing_fields: ['contract_source', 'contract_confirmed'], contract_safety_days: null }, contract_version: 'internal' } });
    const rows = plain(contract.resultRows); assert.equal(rows.find(([label]) => label === '合同安全垫（天）')[1], null); assert.match(JSON.stringify(rows), /合同来源/); assert.ok(!JSON.stringify(rows).includes('internal'));
});

test('CSV keeps scope and exact returned strings while escaping spreadsheet formula prefixes', () => {
    const state = create(); state.record = snapshot(state, { result: { mode: 'profile', status: 'partial', source_ref: '=SUM(1,2)', quoted: '合成"文本', missing: null, true_zero: 0 } });
    const csv = state.csv(); assert.ok(csv.startsWith('\ufeff')); assert.match(csv, /"result.source_ref","'=SUM\(1,2\)"/); assert.match(csv, /"result.quoted","合成""文本"/); assert.match(csv, /"result.missing","未取得"/); assert.match(csv, /"result.true_zero","0"/);
});

test('CSV download attaches its anchor, clicks the exact result blob and revokes the URL after a delay', async () => {
    const state = create(); state.record = snapshot(state); state.saved = state.record;
    const events = []; let blob; let scheduled;
    const anchor = { click() { events.push('click'); }, remove() { events.push('remove'); } };
    Object.assign(runtimeEnvironment, { Blob, URL: { createObjectURL(value) { blob = value; return 'blob:synthetic-csv'; }, revokeObjectURL(value) { events.push(['revoke', value]); } },
        document: { createElement(type) { assert.equal(type, 'a'); return anchor; }, body: { appendChild(value) { assert.equal(value, anchor); events.push('append'); } } },
        setTimeout(callback, delay) { scheduled = { callback, delay }; },
    });
    try {
        state.exportCsv(); assert.deepEqual(events, ['append', 'click', 'remove']); assert.equal(anchor.href, 'blob:synthetic-csv'); assert.equal(anchor.download, '酒店业务-profile-2026-10-32.csv');
        assert.equal(await blob.text(), state.csv().replace(/^\ufeff/, '')); assert.ok(scheduled.delay >= 1000); scheduled.callback(); assert.deepEqual(events.at(-1), ['revoke', 'blob:synthetic-csv']);
    } finally { for (const key of ['Blob', 'URL', 'document', 'setTimeout']) delete runtimeEnvironment[key]; }
});

// Mount the compiled render with the real Vue runtime to catch strict-checkbox and watcher regressions.
function mount(props, implementation = component) {
    const previousDocument = globalThis.Document; const previousShadowRoot = globalThis.ShadowRoot;
    if (!previousDocument) globalThis.Document = class SyntheticDocument {};
    if (!previousShadowRoot) globalThis.ShadowRoot = class SyntheticShadowRoot {};
    const node = (type, text = '') => ({ type, tagName: type.toUpperCase(), text, children: [], props: {}, parent: null, listeners: {}, addEventListener(name, listener) { this.listeners[name] = listener; }, getRootNode() { let root = this; while (root.parent) root = root.parent; return root; }, get options() { return this.children.filter(child => child.type === 'option'); } });
    const detach = child => { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1); };
    const renderer = Vue.createRenderer({ createElement: type => node(type), createText: text => node('#text', text), createComment: text => node('#comment', text),
        setText: (target, text) => { target.text = text; }, setElementText: (target, text) => { target.text = text; target.children = []; },
        parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] || null,
        patchProp: (target, key, old, value) => { target.props[key] = value; if (key === 'value') { target.value = value; target._value = value; } },
        insert: (child, parent, anchor) => { detach(child); const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(child); else parent.children.splice(index, 0, child); child.parent = parent; },
        remove: detach,
    });
    const container = node('root'); const app = renderer.createApp(implementation, props); const state = app.mount(container);
    const unmount = app.unmount.bind(app); app.unmount = () => { try { unmount(); } finally { if (!previousDocument) delete globalThis.Document; if (!previousShadowRoot) delete globalThis.ShadowRoot; } };
    const all = () => { const out = []; const visit = current => { out.push(current); current.children.forEach(visit); }; visit(container); return out; };
    return { app, state, all };
}
const settle = async () => { for (let i = 0; i < 4; i++) { await Promise.resolve(); await Vue.nextTick(); } };
test('real Vue compiled render applies service permission to form and both actions, and scope watcher clears saved data', async () => {
    const requests = [];
    const mounted = mount({ module: 'investment', selectedHotelId: 80, canExecute: true, hotels: [], request: async url => {
        const params = Object.fromEntries(new URL('http://fixture.test' + url).searchParams); requests.push(params);
        return ok({ scope: { ...params, kind: kinds[params.mode] }, latest: null, history: [], can_execute: false });
    } });
    try {
        await settle(); const { state, all } = mounted;
        assert.equal(state.mode, 'investment_target'); assert.equal(state.effectiveCanExecute, false);
        assert.ok(all().some(item => item.type === 'fieldset' && item.props.disabled === true));
        for (const caption of ['预览计算', '保存新版本并核对']) assert.ok(all().some(item => item.type === 'button' && item.text === caption && item.props.disabled === true), caption);
        state.record = { result: { status: 'recorded' } }; state.saved = { snapshot_id: 22 };
        state.hotelId = 81; await settle();
        assert.equal(state.record, null); assert.equal(state.saved, null); assert.equal(Number(requests.at(-1).hotel_id), 81);
        state.mode = 'contract_review'; await settle();
        state.hydrate(fixtures.contract_review); await Vue.nextTick();
        assert.ok(all().some(item => item.type === 'input' && item.props.type === 'checkbox' && item.props.checked === true));
    } finally { mounted.app.unmount(); }
});

test('review restores editable source periods and never aligns mismatched or missing history silently', () => {
    const state = create('operating_review'); const input = plain(fixtures.operating_review);
    input.actual.period_start = '2026-09-01'; input.actual.period_end = '2026-09-30'; input.actual.basis = '渠道范围';
    state.hydrate(input);
    assert.equal(state.inputs().actual.period_start, '2026-09-01');
    assert.equal(state.inputs().actual.period_end, '2026-09-30');
    assert.equal(state.inputs().actual.basis, '渠道范围'); assert.equal(state.inputs().actual.revenue, 7000);
    for (const side of ['plan', 'actual']) for (const key of ['period_start', 'period_end']) assert.equal(state.fields.find(field => field.path === side + '.' + key)?.type, 'date');
    state.setField(state.fields.find(field => field.path === 'period_start'), '2026-11-01');
    assert.equal(state.inputs().plan.period_start, '2026-10-01'); assert.equal(state.inputs().actual.period_start, '2026-09-01');
    state.setField(state.fields.find(field => field.path === 'actual.period_start'), '2026-10-01');
    assert.equal(state.inputs().actual.period_start, '2026-10-01');
    delete input.plan.period_start; delete input.plan.period_end; delete input.actual.period_start; delete input.actual.period_end;
    state.hydrate(input);
    for (const side of ['plan', 'actual']) { assert.equal(state.inputs()[side].period_start, ''); assert.equal(state.inputs()[side].period_end, ''); }
    state.resetScope();
    for (const side of ['plan', 'actual']) { assert.equal(state.form[side].period_start, '2026-10-01'); assert.equal(state.form[side].period_end, '2026-10-31'); }
});

test('investment restoration preserves canonical provenance, cash plan and contract constraints while merging visible edits', () => {
    const state = create('investment_target'); const input = plain(fixtures.investment_target);
    Object.assign(input.scenario, { source_ref: 'docs/knowledge/synthetic-source.json', source_sha256: 'c'.repeat(64), reference_example: true,
        cash_plan: { start_month: '2026-10', months: 1, opening_liquidity: 5000, source_label: '合成现金假设', loans: [{ id: 'synthetic-loan', name: '合成贷款', principal: 3000, funding: 'new', annual_rate: 0.06, start_month: '2026-10', term_months: 12, method: 'annuity' }], monthly_inputs: [{ month: '2026-10', operating_net_cash: 1000, capex_cash: 0, other_net_cash: 0 }] },
        decision_constraints: { target_payback_months: 60, contract_start_on: '2026-01-01', contract_end_on: '2036-12-31', contract_source: '合成合同来源', contract_confirmed: true } });
    const original = plain(input); state.hydrate(input);
    state.setField(state.fields.find(field => field.path === 'scenario.rooms'), '90');
    state.setField(state.fields.find(field => field.path === 'scenario.occupancy_first_year'), '65');
    state.setField(state.fields.find(field => field.path === 'request.target_payback_months'), '72');
    const next = plain(state.inputs());
    assert.equal(next.scenario.rooms, 90); assert.equal(next.scenario.occupancy_first_year, 0.65);
    assert.equal(next.scenario.source_ref, original.scenario.source_ref); assert.equal(next.scenario.source_sha256, original.scenario.source_sha256);
    assert.equal(next.scenario.reference_example, true); assert.deepEqual(next.scenario.cash_plan, original.scenario.cash_plan);
    assert.deepEqual(next.scenario.decision_constraints, { ...original.scenario.decision_constraints, target_payback_months: 72 });
    assert.deepEqual(input, original); state.hydrate(next); assert.deepEqual(plain(state.inputs()), next);
});

test('market restoration only retains an explicit valid attestation and every scene or sample edit clears it', () => {
    const state = create('market_sample'); const legacy = plain(fixtures.market_sample); delete legacy.comparison_attested;
    state.hydrate(legacy); assert.equal(state.form.comparison_attested, false); assert.throws(() => state.inputs(), /人工核对/);
    for (const flag of [false, 1, 'true', null]) { state.hydrate({ ...fixtures.market_sample, comparison_attested: flag }); assert.equal(state.form.comparison_attested, false); }
    state.hydrate(fixtures.market_sample); assert.equal(state.form.comparison_attested, true);
    state.setField(state.fields.find(field => field.path === 'comparison_key'), 'synthetic-new-scene');
    assert.equal(state.form.comparison_attested, false); assert.throws(() => state.inputs(), /人工核对/);
    state.setField(state.fields.find(field => field.path === 'comparison_attested'), true);
    assert.equal(state.inputs().comparison_attested, true); assert.ok(state.inputs().hotels.every(row => row.comparison_key === 'synthetic-new-scene'));
    for (const path of ['sample_ref', 'rate_unit', 'weights.traffic']) {
        state.hydrate(fixtures.market_sample); state.setField(state.fields.find(field => field.path === path), state.value(path)); assert.equal(state.form.comparison_attested, false, path);
    }
    state.hydrate(fixtures.market_sample); state.form.hotels[0].traffic = '200'; state.edit(); assert.equal(state.form.comparison_attested, false);
    state.hydrate(fixtures.market_sample); state.addRow(state.groups[0]); assert.equal(state.form.comparison_attested, false);
    state.hydrate(fixtures.market_sample); state.removeRow(state.groups[0], 0); assert.equal(state.form.comparison_attested, false);
    const mismatch = plain(fixtures.market_sample); mismatch.hotels[0].comparison_key = 'other-scene'; state.hydrate(mismatch); assert.equal(state.form.comparison_attested, false);
});

test('only typed status fields are translated; business strings and inherited object names remain exact', () => {
    const state = create('profile');
    for (const value of ['unknown', 'partial', 'constructor', 'toString', '__proto__']) {
        assert.equal(state.display(value), value); assert.equal(state.displayStatus(value), value === 'unknown' ? '未取得' : value === 'partial' ? '部分资料' : value);
    }
    assert.equal(state.label('constructor'), 'constructor');
    state.record = snapshot(state, { result: { mode: 'profile', fields: [{ key: 'constructor', value: 'unknown', source_ref: 'partial', as_of: '2026-10-01', status: 'unverified' }] } });
    const rows = plain(state.resultRows); assert.equal(rows[1][1], 'unknown'); assert.match(rows[2][1], /^partial · 2026-10-01 · 未核对$/);
    state.mode = 'ota_scene'; state.record = { inputs: {}, result: { scene: { keyword: 'unknown', location: 'constructor', source_ref: 'partial' }, visibility: 'unknown' } };
    const ota = plain(state.resultRows); assert.equal(ota.find(([label]) => label === '搜索关键词')[1], 'unknown'); assert.equal(ota.find(([label]) => label === '观察可见状态')[1], '未取得');
});

test('excluded consumable results are clearly distinguished from missing included rows', () => {
    const state = create('consumables_reconciliation'); state.hydrate(fixtures.consumables_reconciliation);
    state.record = snapshot(state, { result: { mode: state.mode, items: [{ name: '合成停用纸巾', enabled: false, status: 'excluded', unit: 'piece', consumed_quantity: null, issued_quantity: 55, counted_minus_book_closing_quantity: null }, { name: '合成缺项纸巾', enabled: true, status: 'partial', unit: 'constructor', consumed_quantity: null }] } });
    const rows = plain(state.resultRows);
    assert.match(rows.find(([label]) => label === '合成停用纸巾')[1], /^未纳入本期；/);
    assert.match(rows.find(([label]) => label === '合成缺项纸巾')[1], /^部分资料；/);
    assert.ok(!JSON.stringify(rows).includes('native code')); assert.ok(JSON.stringify(rows).includes('constructor'));
});

test('nonzero sub-micro quantities never display as zero while ordinary numbers retain the existing precision', () => {
    const state = create('consumables_reconciliation'); state.hydrate(fixtures.consumables_reconciliation);
    assert.equal(state.display(1e-7), '1e-7'); assert.equal(state.display(-1e-7), '-1e-7');
    assert.equal(state.display(0), '0'); assert.equal(state.display(-0), '0');
    assert.equal(state.display(1234.56789129), '1,234.567891');
    state.record = snapshot(state, { result: { mode: state.mode, status: 'calculated', actual_consumed_cost: 100000, items: [{ name: '合成微量耗材', enabled: true, status: 'calculated', unit: 'ml', unit_price: 1e12, consumed_quantity: 1e-7, issued_quantity: 1e-7, counted_minus_book_closing_quantity: 0 }] } });
    const row = plain(state.resultRows).find(([label]) => label === '合成微量耗材');
    assert.match(row[1], /库存消耗 1e-7；领用 1e-7；盘点减账面 0（毫升）/);
    assert.equal(state.display(state.resultRows.find(([label]) => label === '库存平衡消耗成本（元）')[1]), '100,000');
    assert.equal(state.record.result.items[0].consumed_quantity, 1e-7); assert.match(state.csv(), /"result.items.0.consumed_quantity","1e-7"/);
});

for (const [mode, input] of Object.entries(fixtures)) test(`compiled ${mode}: preview and save submit identical inputs; exact readback and CSV retain the returned version`, async () => {
    const state = create(mode); state.hydrate(input); const expected = plain(state.inputs()); const submitted = []; let saved;
    state.request = async (url, options) => {
        if (options?.method === 'POST') {
            const body = JSON.parse(options.body); submitted.push(body); assert.deepEqual(body.inputs, expected);
            const data = snapshot(state, { snapshot_id: 77, inputs: plain(body.inputs), result: { mode, status: 'partial', source_ref: 'unknown', true_zero: 0, missing: null } });
            if (url.endsWith('/snapshots')) saved = data;
            return ok(data);
        }
        if (url.includes('/snapshots/77?')) return ok(saved);
        return ok(overview(state, { latest: saved, history: saved ? [saved] : [] }));
    };
    await state.calculate(false); assert.equal(state.error, ''); assert.equal(state.saved, null); assert.equal(state.resultCurrent, true);
    const preview = plain(state.record.inputs); await state.calculate(true);
    assert.equal(state.error, ''); assert.equal(submitted.length, 2); assert.deepEqual(submitted[0].inputs, submitted[1].inputs);
    assert.deepEqual(plain(state.record.inputs), preview); assert.equal(state.record, saved); assert.equal(state.saved, saved);
    assert.deepEqual(plain(state.inputs()), expected); assert.equal(state.history[0], saved);
    const csv = state.csv(); assert.match(csv, /"snapshot_id","77"/); assert.match(csv, /"result.source_ref","unknown"/); assert.match(csv, /"result.true_zero","0"/); assert.match(csv, /"result.missing","未取得"/);
    state.edit(); assert.equal(state.saved, null); assert.equal(state.resultCurrent, false); assert.equal(state.csv(), '');
});

for (const [mode, input] of Object.entries(fixtures)) test(`compiled ${mode}: failed preview and interrupted save readback retry without changing inputs or retry identity`, async () => {
    const state = create(mode); state.hydrate(input); const expected = plain(state.inputs()); let previews = 0; let reads = 0; const keys = []; const saved = snapshot(state);
    state.request = async (url, options) => {
        if (options?.method === 'POST') {
            const body = JSON.parse(options.body); assert.deepEqual(body.inputs, expected);
            if (url.endsWith('/preview')) { if (++previews === 1) return { code: 422, message: '合成待核对缺项' }; return ok(saved); }
            keys.push(body.idempotency_key); return ok(saved);
        }
        if (url.includes('/snapshots/')) { if (++reads === 1) throw new Error('合成回读中断'); return ok(saved); }
        return ok(overview(state));
    };
    await state.calculate(false); assert.match(state.error, /合成待核对/); assert.equal(state.resultCurrent, false);
    await state.calculate(false); assert.equal(state.error, ''); assert.equal(state.resultCurrent, true);
    await state.calculate(true); assert.match(state.error, /合成回读中断/); assert.equal(state.saved, null); assert.equal(state.csv(), '');
    await state.calculate(true); assert.equal(state.error, ''); assert.equal(state.saved, saved); assert.equal(keys[0], keys[1]); assert.deepEqual(plain(state.inputs()), expected);
});

test('real Vue review restoration renders each original period and permits correcting only the chosen side', async () => {
    const mounted = mount({ module: 'all', selectedHotelId: 80, canExecute: true, hotels: [], request: async url => {
        const params = Object.fromEntries(new URL('http://fixture.test' + url).searchParams);
        return ok({ scope: { ...params, kind: kinds[params.mode] }, latest: null, history: [], can_execute: true });
    } });
    try {
        await settle(); mounted.state.mode = 'operating_review'; await settle();
        const input = plain(fixtures.operating_review); input.actual.period_start = '2026-09-01'; input.actual.period_end = '2026-09-30'; mounted.state.hydrate(input); await Vue.nextTick();
        const dates = mounted.all().filter(node => node.type === 'input' && node.props.type === 'date');
        assert.equal(dates.length, 6); assert.deepEqual(dates.map(node => node.props.value), ['2026-10-01', '2026-10-31', '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30']);
        dates[4].props.onInput({ target: { value: '2026-10-01' } }); await Vue.nextTick();
        assert.equal(mounted.state.inputs().actual.period_start, '2026-10-01'); assert.equal(mounted.state.inputs().actual.period_end, '2026-09-30'); assert.equal(mounted.state.inputs().plan.period_start, '2026-10-01');
    } finally { mounted.app.unmount(); }
});

test('real Vue drops reversed overview and exact readback responses across rapid hotel, month, platform and mode changes', async () => {
    const overviews = []; const readback = deferred(); let posted; let postCount = 0;
    const mounted = mount({ module: 'all', selectedHotelId: 80, canExecute: false, hotels: [], request: async (url, options) => {
        const params = Object.fromEntries(new URL('http://fixture.test' + url).searchParams);
        if (options?.method === 'POST') {
            postCount++; const body = JSON.parse(options.body); posted = { snapshot_id: 32, scope: { ...body, kind: kinds[body.mode] }, inputs: body.inputs, result: { mode: body.mode, status: 'recorded' }, content_digest: 'a'.repeat(64), readback_verified: true }; return ok(posted);
        }
        if (url.includes('/snapshots/')) return readback.promise;
        const pending = deferred(); overviews.push({ pending, data: { scope: { ...params, kind: kinds[params.mode] }, latest: null, history: [{ snapshot_id: 90 + overviews.length }], can_execute: params.hotel_id === '80' } }); return pending.promise;
    } });
    try {
        await settle(); const state = mounted.state;
        overviews.at(-1).pending.resolve(ok(overviews.at(-1).data)); await settle();
        state.hydrate(fixtures.profile); const saving = state.calculate(true); await settle(); assert.equal(postCount, 1);
        state.hotelId = 81; await settle(); state.periodMonth = '2026-11'; await settle(); state.mode = 'ota_scene'; await settle(); state.platform = 'meituan'; await settle(); state.mode = 'market_sample'; await settle();
        const latest = overviews.at(-1); latest.pending.resolve(ok(latest.data)); await settle();
        for (const item of overviews.slice(1, -1).reverse()) { item.pending.resolve(ok(item.data)); await settle(); }
        readback.resolve(ok(posted)); await saving; await settle();
        assert.equal(state.overview.scope.hotel_id, '81'); assert.equal(state.overview.scope.period_month, '2026-11'); assert.equal(state.overview.scope.platform, 'meituan'); assert.equal(state.overview.scope.mode, 'market_sample');
        assert.equal(state.effectiveCanExecute, false); assert.deepEqual(plain(state.history), latest.data.history); assert.equal(state.saved, null); assert.equal(state.record, null); assert.equal(state.csv(), ''); assert.equal(state.busy, false);
    } finally { mounted.app.unmount(); }
});

test('round2 source: consumables editing invalidates the existing quantity and source attestation until explicitly confirmed again', () => {
    const state = create('consumables_reconciliation', null, {}, sourceComponent);
    const confirmation = state.fields.find(field => field.path === 'operator_attested');
    for (const field of state.fields.filter(field => field.path !== 'operator_attested')) {
        state.hydrate(fixtures.consumables_reconciliation);
        state.setField(field, field.type === 'number' ? 101 : '合成修改来源');
        assert.equal(state.inputs().operator_attested, false, field.path);
        state.setField(confirmation, true); assert.equal(state.inputs().operator_attested, true, field.path);
        state.setField(confirmation, false); assert.equal(state.inputs().operator_attested, false, field.path);
    }
    for (const field of state.groups[0].fields) {
        state.hydrate(fixtures.consumables_reconciliation);
        state.form.items[0][field.path] = field.type === 'number' ? 101 : field.type === 'checkbox' ? false : '合成修改'; state.edit();
        assert.equal(state.inputs().operator_attested, false, field.path);
    }
    for (const action of [() => state.addRow(state.groups[0]), () => state.removeRow(state.groups[0], 0)]) {
        state.hydrate(fixtures.consumables_reconciliation); action(); assert.equal(state.inputs().operator_attested, false);
    }
});

test('round2 source: editing contract text withdraws its old confirmation while independent payback assumptions retain it', () => {
    const state = create('contract_review', null, {}, sourceComponent);
    const confirmation = state.fields.find(field => field.path === 'constraints.contract_confirmed');
    for (const path of ['constraints.contract_start_on', 'constraints.contract_end_on', 'constraints.contract_source']) {
        state.hydrate(fixtures.contract_review);
        state.setField(state.fields.find(field => field.path === path), path.endsWith('source') ? '合成新合同' : '2026-11-01');
        assert.equal(state.inputs().constraints.contract_confirmed, false, path);
        state.setField(confirmation, true); assert.equal(state.inputs().constraints.contract_confirmed, true, path);
        state.setField(confirmation, false); assert.equal(state.inputs().constraints.contract_confirmed, false, path);
    }
    for (const path of ['as_of', 'payback_months', 'constraints.target_payback_months']) {
        state.hydrate(fixtures.contract_review);
        state.setField(state.fields.find(field => field.path === path), path === 'as_of' ? '2026-11-01' : 72);
        assert.equal(state.inputs().constraints.contract_confirmed, true, path);
    }
});

test('round2 source: real Vue consumable controls clear old attestation, and the confirmation checkbox can explicitly restore it', async () => {
    const mounted = mount({ module: 'operations', selectedHotelId: 80, canExecute: true, hotels: [], request: async url => {
        const params = Object.fromEntries(new URL('http://fixture.test' + url).searchParams);
        return ok({ scope: { ...params, kind: kinds[params.mode] }, latest: null, history: [], can_execute: true });
    } }, sourceComponent);
    try {
        await settle(); const { state, all } = mounted;
        state.hydrate(fixtures.consumables_reconciliation); await Vue.nextTick();
        const row = all().find(node => node.type === 'fieldset' && node.props.class === 'hl-row');
        const input = row.children.flatMap(node => node.children).flatMap(node => node.children).find(node => node.type === 'input' && node.props.type === 'number');
        assert.ok(input); input.value = '101'; input.listeners.input?.({ target: input }); input.props.onInput({ target: input }); await Vue.nextTick();
        assert.equal(state.inputs().items[0].opening_quantity, 101); assert.equal(state.inputs().operator_attested, false);
        const check = all().find(node => node.type === 'input' && node.props.type === 'checkbox' && node.props.onChange);
        check.props.onChange({ target: { checked: true } }); await Vue.nextTick(); assert.equal(state.inputs().operator_attested, true);
        const source = all().find(node => node.type === 'input' && node.props.type === 'text' && node.props.value === '合成全酒店账');
        source.props.onInput({ target: { value: '合成新全酒店账' } }); await Vue.nextTick(); assert.equal(state.inputs().operator_attested, false);
    } finally { mounted.app.unmount(); }
});

test('round2 source: real Vue contract inputs withdraw old source confirmation and preserve confirmation for unrelated target edits', async () => {
    const mounted = mount({ module: 'investment', selectedHotelId: 80, canExecute: true, hotels: [], request: async url => {
        const params = Object.fromEntries(new URL('http://fixture.test' + url).searchParams);
        return ok({ scope: { ...params, kind: kinds[params.mode] }, latest: null, history: [], can_execute: true });
    } }, sourceComponent);
    try {
        await settle(); const { state, all } = mounted; state.mode = 'contract_review'; await settle(); state.hydrate(fixtures.contract_review); await Vue.nextTick();
        const source = all().find(node => node.type === 'input' && node.props.value === '合成合同');
        source.props.onInput({ target: { value: '合成新合同' } }); await Vue.nextTick(); assert.equal(state.inputs().constraints.contract_confirmed, false);
        const check = all().find(node => node.type === 'input' && node.props.type === 'checkbox');
        assert.equal(check.props.checked, false); check.props.onChange({ target: { checked: true } }); await Vue.nextTick(); assert.equal(state.inputs().constraints.contract_confirmed, true);
        const target = all().find(node => node.type === 'input' && node.props.type === 'number' && Number(node.props.value) === 60);
        target.props.onInput({ target: { value: '72' } }); await Vue.nextTick(); assert.equal(state.inputs().constraints.target_payback_months, 72); assert.equal(state.inputs().constraints.contract_confirmed, true);
    } finally { mounted.app.unmount(); }
});

for (const mode of ['consumables_reconciliation', 'contract_review']) test(`round2 source: ${mode} restoration, edits and interrupted save retries preserve explicit confirmation state`, async () => {
    const state = create(mode, null, {}, sourceComponent); state.hydrate(fixtures[mode]); const old = snapshot(state); let saved; const posts = []; let reads = 0;
    state.request = async (url, options) => {
        if (options?.method === 'POST') { const body = JSON.parse(options.body); posts.push(body); saved = snapshot(state, { inputs: body.inputs }); return ok(saved); }
        if (url.includes('/snapshots/')) { if (!saved) return ok(old); if (++reads === 1) throw new Error('合成回读中断'); return ok(saved); }
        return ok(overview(state, { history: [saved || old] }));
    };
    await state.restore(32); assert.equal(state.error, '');
    const path = mode === 'consumables_reconciliation' ? 'occupied_room_nights_source_ref' : 'constraints.contract_source';
    const confirmed = value => mode === 'consumables_reconciliation' ? value.operator_attested : value.constraints.contract_confirmed;
    state.setField(state.fields.find(field => field.path === path), '合成新的来源'); assert.equal(confirmed(state.inputs()), false);
    const expected = plain(state.inputs()); await state.calculate(true); assert.match(state.error, /合成回读中断/); await state.calculate(true);
    assert.equal(state.error, ''); assert.equal(state.saved, saved); assert.deepEqual(posts.map(body => body.inputs), [expected, expected]);
    assert.equal(posts[0].idempotency_key, posts[1].idempotency_key); assert.equal(confirmed(state.inputs()), false); assert.equal(confirmed(state.record.inputs), false);
    const confirmation = mode === 'consumables_reconciliation' ? 'operator_attested' : 'constraints.contract_confirmed';
    state.setField(state.fields.find(field => field.path === confirmation), true); assert.equal(confirmed(state.inputs()), true);
    await state.calculate(true); assert.equal(state.error, ''); assert.equal(confirmed(state.record.inputs), true);
    state.setField(state.fields.find(field => field.path === confirmation), false); await state.calculate(true); assert.equal(state.error, ''); assert.equal(confirmed(state.record.inputs), false);
});

test('round2 source: legitimate profile formula-like text stays literal in CSV while numeric negatives and zero retain their quantity', () => {
    const state = create('profile', null, {}, sourceComponent);
    const texts = ['=SUM(1,2)', '+1+2', '-1+2', '@SUM(1,2)', '\t=SUM(1,2)', '\r=SUM(1,2)', '合成"引文\n下一行'];
    state.hydrate({ fields: texts.map((value, index) => ({ key: 'synthetic_' + index, value, unit: '', source_ref: value, as_of: '2026-10-01', quality: 'manual_reference' })) });
    state.record = snapshot(state, { result: { mode: 'profile', status: 'partial', debt: -10, true_zero: 0, missing: null } });
    const csv = state.csv();
    for (let index = 0; index < texts.length; index++) {
        const text = texts[index]; const expected = (/^[=+\-@\t\r]/.test(text) ? "'" : '') + text.replaceAll('"', '""');
        assert.ok(csv.includes('"inputs.fields.' + index + '.value","' + expected + '"'));
        assert.ok(csv.includes('"inputs.fields.' + index + '.source_ref","' + expected + '"'));
        assert.equal(state.record.inputs.fields[index].value, text);
    }
    assert.match(csv, /"result.debt","-10"/); assert.match(csv, /"result.true_zero","0"/); assert.match(csv, /"result.missing","未取得"/);
});

test('round2 source: market confirmation still requires explicit user action and every sample edit withdraws it', () => {
    const state = create('market_sample', null, {}, sourceComponent); state.hydrate(fixtures.market_sample);
    const confirmation = state.fields.find(field => field.path === 'comparison_attested');
    state.setField(state.fields.find(field => field.path === 'sample_ref'), '合成新样本来源');
    assert.equal(state.form.comparison_attested, false); assert.throws(() => state.inputs(), /人工核对/);
    state.setField(confirmation, true); assert.equal(state.inputs().comparison_attested, true);
    state.form.hotels[0].revenue = 201; state.edit({ target: { value: '201' } });
    assert.equal(state.form.comparison_attested, false); assert.throws(() => state.inputs(), /人工核对/);
    state.setField(confirmation, true); state.addRow(state.groups[0]); assert.equal(state.form.comparison_attested, false);
    state.setField(confirmation, true); state.removeRow(state.groups[0], 2); assert.equal(state.form.comparison_attested, false);
});

for (const mode of ['consumables_reconciliation', 'contract_review']) test(`round2 source: ${mode} old false and missing confirmation never becomes true during restoration or editing`, () => {
    const state = create(mode, null, {}, sourceComponent);
    for (const missing of [false, true]) {
        const input = plain(fixtures[mode]);
        if (mode === 'consumables_reconciliation') { if (missing) delete input.operator_attested; else input.operator_attested = false; }
        else { if (missing) delete input.constraints.contract_confirmed; else input.constraints.contract_confirmed = false; }
        state.hydrate(input);
        const confirmed = value => mode === 'consumables_reconciliation' ? value.operator_attested : value.constraints.contract_confirmed;
        assert.equal(confirmed(state.inputs()), false);
        const path = mode === 'consumables_reconciliation' ? 'occupied_room_nights_source_ref' : 'payback_months';
        state.setField(state.fields.find(field => field.path === path), mode === 'contract_review' ? 72 : '合成修改来源');
        assert.equal(confirmed(state.inputs()), false);
    }
});

test('round2 source: numeric assignment rejects nonzero underflow and percent underflow while true zero, blanks and scientific notation remain exact', () => {
    const state = create('consumables_reconciliation', null, {}, sourceComponent);
    const quantity = { label: '合成数量', type: 'number', percent: false }; const percentage = { label: '合成入住率', type: 'number', percent: true };
    for (const raw of ['1e-999', '-1e-999', '1e999', '-1e999']) assert.throws(() => state.number(raw, quantity), /有效数字|不可计算/, raw);
    for (const raw of ['1e-323', '-1e-323', '1e-999']) assert.throws(() => state.number(raw, percentage), /有效数字|不可计算/, raw);
    for (const raw of ['0', '-0', '0e-999', '0e999']) assert.equal(state.number(raw, quantity) === 0, true, raw);
    for (const raw of ['', '  ', null, undefined]) assert.equal(state.number(raw, quantity), null);
    assert.equal(state.number('1e-7', quantity), 1e-7); assert.equal(state.number('-1e-7', quantity), -1e-7);
    assert.equal(state.number('6e1', percentage), 0.6); assert.equal(state.number('1e-100', percentage), 1e-102);
});

for (const place of ['top', 'row']) test(`round2 source: real Vue ${place} numeric control never posts underflow or overflow as zero/null`, async () => {
    let posts = 0;
    const mounted = mount({ module: 'all', selectedHotelId: 80, canExecute: true, hotels: [], request: async (url, options) => {
        if (options?.method === 'POST') { posts++; return { code: 422, message: '合成后端收到请求' }; }
        const params = Object.fromEntries(new URL('http://fixture.test' + url).searchParams);
        return ok({ scope: { ...params, kind: kinds[params.mode] }, latest: null, history: [], can_execute: true });
    } }, sourceComponent);
    try {
        await settle(); const { state, all } = mounted;
        for (const [mode, raw, percent] of [['consumables_reconciliation', '1e-999', false], ['consumables_reconciliation', '1e999', false], ['investment_target', '1e-323', true]]) {
            state.mode = mode; await settle(); state.hydrate(fixtures[mode]); await Vue.nextTick();
            let input;
            if (place === 'row') {
                const row = all().find(node => node.type === 'fieldset' && node.props.class === 'hl-row');
                const numbers = row.children.flatMap(node => node.children).flatMap(node => node.children).filter(node => node.type === 'input' && node.props.type === 'number');
                input = numbers[percent ? 1 : 0];
            } else input = all().find(node => node.type === 'input' && node.props.type === 'number' && (!percent || Number(node.props.value) === 60));
            assert.ok(input); input.value = raw; input.listeners.input?.({ target: input }); input.props.onInput({ target: input }); await Vue.nextTick();
            assert.equal(input.props.value, raw); const label = input.parent.children.find(node => node.type === 'span')?.text; assert.ok(label);
            await state.calculate(false); assert.equal(posts, 0, place + ':' + raw); assert.match(state.error, /有效数字|不可计算/); assert.ok(state.error.includes(label)); assert.equal(state.busy, false); assert.equal(state.saved, null);
            await state.calculate(true); assert.equal(posts, 0, place + ':save:' + raw); assert.match(state.error, /有效数字|不可计算/);
            input.value = '0'; input.listeners.input?.({ target: input }); input.props.onInput({ target: input }); await Vue.nextTick();
            assert.doesNotThrow(() => state.inputs());
        }
    } finally { mounted.app.unmount(); }
});
