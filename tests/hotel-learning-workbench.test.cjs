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
const plain = value => JSON.parse(JSON.stringify(value));
const ok = data => ({ code: 200, data });
const kinds = { profile: 'jhira_profile', consumables_reconciliation: 'consumables_actual', investment_target: 'jhira_target', contract_review: 'jhira_contract', ota_scene: 'jhira_ota_scene', market_sample: 'jhira_market', operating_review: 'jhira_review', geo_observation: 'jhira_geo' };
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
function create(mode = 'profile', request, props = {}) {
    const state = { module: 'all', canExecute: true, selectedHotelId: 80, hotels: [{ id: 80, name: '合成测试酒店' }], ...props };
    Object.assign(state, component.data.call(state));
    for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state);
    for (const [name, getter] of Object.entries(component.computed)) Object.defineProperty(state, name, { get: () => getter.call(state) });
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
    market_sample: { sample_ref: '合成样本', model_version: '合成算法v1', comparison_key: 'synthetic-comparison', weights: { traffic: 0.4, conversion: 0.3, revenue: 0.3 }, hotels: [{ platform_store_id: 'synthetic-a', name: '合成甲', traffic: 100, conversion: 5, revenue: 200, comparison_key: 'synthetic-comparison', rate_unit: 'percentage_point' }, { platform_store_id: 'synthetic-b', name: '合成乙', traffic: 200, conversion: 6, revenue: 300, comparison_key: 'synthetic-comparison', rate_unit: 'percentage_point' }] },
    operating_review: { period_start: '2026-10-01', period_end: '2026-10-31', plan: { source_ref: '合成计划', basis: '全酒店', available_room_nights: 100, sold_room_nights: 80, revenue: 8000, operating_cost: 3000, debt_service: 100, project_net_cash: 4900, investor_received_cash: 0 }, actual: { source_ref: '合成实际', basis: '全酒店', available_room_nights: 100, sold_room_nights: 70, revenue: 7000, operating_cost: 3000, debt_service: 100, project_net_cash: 3900, investor_received_cash: 1000 } },
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
function mount(props) {
    const node = (type, text = '') => ({ type, tagName: type.toUpperCase(), text, children: [], props: {}, parent: null, listeners: {}, addEventListener(name, listener) { this.listeners[name] = listener; }, get options() { return this.children.filter(child => child.type === 'option'); } });
    const detach = child => { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1); };
    const renderer = Vue.createRenderer({ createElement: type => node(type), createText: text => node('#text', text), createComment: text => node('#comment', text),
        setText: (target, text) => { target.text = text; }, setElementText: (target, text) => { target.text = text; target.children = []; },
        parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] || null,
        patchProp: (target, key, old, value) => { target.props[key] = value; if (key === 'value') { target.value = value; target._value = value; } },
        insert: (child, parent, anchor) => { detach(child); const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(child); else parent.children.splice(index, 0, child); child.parent = parent; },
        remove: detach,
    });
    const container = node('root'); const app = renderer.createApp(component, props); const state = app.mount(container);
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
