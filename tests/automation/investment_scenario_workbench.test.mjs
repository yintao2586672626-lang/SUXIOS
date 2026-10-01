import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { compileFrontendTemplate } from '../../scripts/lib/frontend_template_build.mjs';

const source = fs.readFileSync(new URL('../../public/components/system/investment-scenario.js', import.meta.url), 'utf8');
const create = (request, project = { id: 1, version: 7, project_name: '测试项目', hotel_id: 80 }, environment = {}) => {
    const registry = {}, events = [];
    const Vue = {
        ref: value => ({ value }), computed: getter => ({ get value() { return getter(); } }),
        watch: () => {}, onUnmounted: () => {},
    };
    vm.runInNewContext(source, { Vue, window: { SUXI_SYSTEM_COMPONENTS: registry }, Intl, Date, Number, Object, JSON, String, Array, ...environment });
    const props = { request, project, ledgerBusy: false };
    return { state: registry.InvestmentScenarioWorkbench.setup(props, { emit: (...args) => events.push(args) }), props, events };
};
const ok = data => ({ code: 200, data });
const saved = (input = null, id = 1, result = null, version = 2) => ({ project_id: id, project_version: 7, scenario_version: version, input, result, content_digest: 'test-content-digest', readback: 'exact' });
const result = input => ({ status: 'partial', model_version: 'test-model', input, annual_rows: [{ year: 1, adr: 200, occupancy: 0.75, revenue: 12000, pretax_cash_proxy: 3000, cumulative_cash_proxy: -7000 }], initial_cash_total: 10000, payback: { status: 'not_reached', operating_years: null, total_years: null, remaining_cash: 7000 }, warnings: [], exclusions: [] });

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
