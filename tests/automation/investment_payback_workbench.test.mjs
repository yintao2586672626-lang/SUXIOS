import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../public/components/system/investment-payback.js', import.meta.url), 'utf8');
const create = (request, environment = {}) => {
    const registry = {};
    let nonce = 0;
    const Vue = {
        ref: value => ({ value }), reactive: value => value,
        computed: getter => ({ get value() { return getter(); } }),
        onMounted: () => {}, watch: () => {},
    };
    vm.runInNewContext(source, { Vue, window: { SUXI_SYSTEM_COMPONENTS: registry, crypto: { randomUUID: () => `test-id-${++nonce}` }, ...environment.window }, document: environment.document, Intl, Date, Number, Object });
    return registry.InvestmentPaybackBody.setup({ request, hotels: [] });
};
const projectDetail = id => ({ project: { id, project_name: `测试项目${id}`, version: 1 }, entries: [], summary: { as_of: '2026-10-01' }, audit_history: [] });
const ok = data => ({ code: 200, data });

test('mainline template registry and runtime render expose the investment payback page', async () => {
    const { loadFrontendTemplateSource } = await import('../../scripts/lib/frontend_template_source.mjs');
    const root = new URL('../../', import.meta.url);
    const { fileURLToPath } = await import('node:url');
    const template = loadFrontendTemplateSource(fileURLToPath(root));
    assert.match(template.templateBuffer.toString(), /investment-payback-view/);
    const manifest = JSON.parse(fs.readFileSync(new URL('../../resources/frontend/templates/manifest.json', import.meta.url), 'utf8'));
    assert.equal(manifest.fragments.filter(row => row.id === 'page-investment-payback').length, 1);
    assert.match(fs.readFileSync(new URL('../../public/app-render.min.js', import.meta.url), 'utf8'), /investment-payback-view/);
});

test('compact ledger columns retain mixed provenance, missing sources, notes and excluded row status', () => {
    const state = create(async () => ok({}));
    const detail = projectDetail(5);
    detail.entries = [
        { id: 1, date: '2026-09-01', kind: 'investment', amount: '1000.00', source: '人工录入', notes: '' },
        { id: 2, date: '2026-09-02', kind: 'recovery', amount: '100.00', source: '人工录入', is_planned: true, notes: '' },
    ];
    state.detail.value = detail;
    const before = JSON.stringify(detail);
    assert.equal(state.ledgerColumns.value.commonSource, '人工录入');
    assert.equal(state.ledgerColumns.value.showSource, false);
    assert.equal(state.ledgerColumns.value.showNotes, false);
    assert.equal(state.entryState(detail.entries[1]), '计划，未计入实际');
    assert.equal(JSON.stringify(detail), before);
    detail.entries[1].source = '人工确认文件导入；来源未独立核验；file=合成验收.csv；sha256=' + 'a'.repeat(64) + '；method=spreadsheet；row=2';
    detail.entries[1].notes = '到账说明';
    assert.equal(state.ledgerColumns.value.commonSource, '');
    assert.equal(state.ledgerColumns.value.showSource, true);
    assert.equal(state.ledgerColumns.value.showNotes, true);
    state.ledgerKind.value = 'recovery';
    assert.equal(state.ledgerColumns.value.commonSource, '表格导入 · 合成验收.csv · 第2行');
    assert.equal(state.ledgerColumns.value.commonSourceFull, detail.entries[1].source);
    detail.entries[1].source = '';
    detail.entries[1].notes = '';
    detail.entries[1].voided_at = '2026-09-03';
    detail.entries[1].void_reason = '重复记录';
    assert.equal(state.ledgerColumns.value.commonSource, '来源未填写');
    assert.equal(state.ledgerColumns.value.showNotes, true);
    assert.equal(state.entryState(detail.entries[1]), '已作废');
    state.ledgerSearch.value = '无匹配';
    assert.equal(state.ledgerColumns.value.commonSource, '');
    assert.equal(state.ledgerColumns.value.showSource, false);
    assert.equal(state.ledgerColumns.value.showNotes, false);
});

test('detail headlines retain an exact yuan value whenever the short ten-thousand display is approximate', () => {
    const state = create(async () => ok({}));
    assert.equal(state.detailAmount('118276.55').text, '约 11.83 万元');
    assert.equal(state.detailAmount('118276.55').exact, '118,276.55 元');
    assert.equal(state.detailAmount('401723.45').exact, '401,723.45 元');
    assert.equal(state.detailAmount('118400.00').text, '11.84 万元');
    assert.equal(state.detailAmount('118400.00').exact, null);
    assert.equal(state.detailAmount('0.01').text, '0.01 元');
    assert.equal(state.detailAmount(null).text, '待录入');
    assert.equal(state.detailAmount('').text, '待录入');
    assert.equal(state.detailAmount('invalid').text, '待核对');
});

test('ledger filtering and stable date sorting retain excluded records and never mutate totals or original entries', () => {
    const state = create(async () => ok({}));
    const detail = projectDetail(5);
    detail.summary = { invested_amount: '520000.00', net_recovered_amount: '401600.00', unrecovered_amount: '118400.00' };
    detail.entries = [
        { id: 1, date: '2026-04-01', kind: 'investment', amount: '520000.00', source: '人工录入', notes: '初始出资' },
        { id: 2, date: '2026-09-01', kind: 'recovery', amount: '30000.00', source: '银行流水', notes: '九月分红' },
        { id: 3, date: '2026-09-29', kind: 'refund', amount: '100.00', source: '合成退款', notes: '调整' },
        { id: 4, date: '2026-09-29', kind: 'recovery', amount: '2000.00', source: '人工录入', voided_at: '2026-09-30', void_reason: '重复账目' },
        { id: 5, date: '2026-10-05', kind: 'recovery', amount: '2000.00', source: '计划', is_planned: true },
    ];
    state.detail.value = detail;
    const before = JSON.stringify(detail);
    assert.deepEqual(Array.from(state.ledgerRows.value, row => row.id), [5, 4, 3, 2, 1]);
    state.ledgerSort.value = 'oldest';
    assert.deepEqual(Array.from(state.ledgerRows.value, row => row.id), [1, 2, 3, 4, 5]);
    state.ledgerKind.value = 'recovery';
    assert.deepEqual(Array.from(state.ledgerRows.value, row => row.id), [2, 4, 5]);
    state.ledgerSearch.value = '银行 30,000';
    assert.deepEqual(Array.from(state.ledgerRows.value, row => row.id), [2]);
    state.ledgerSearch.value = '重复账目';
    assert.deepEqual(Array.from(state.ledgerRows.value, row => row.id), [4]);
    state.ledgerSearch.value = '找不到';
    assert.equal(state.ledgerRows.value.length, 0);
    state.clearLedgerFilters();
    assert.equal(state.ledgerRows.value.length, 5);
    assert.equal(state.ledgerSort.value, 'oldest');
    assert.equal(JSON.stringify(detail), before);
    state.detail.value = null;
    assert.equal(state.ledgerRows.value.length, 0);
});

test('ledger controls stay scoped to the selected project and survive same-project readback', async () => {
    const state = create(async path => ok(projectDetail(Number(path.match(/projects\/(\d+)/)[1]))));
    state.detail.value = projectDetail(5);
    state.ledgerKind.value = 'refund';
    state.ledgerSearch.value = '调整';
    state.ledgerSort.value = 'oldest';
    await state.selectProject(5);
    assert.equal(state.ledgerKind.value, 'refund');
    assert.equal(state.ledgerSearch.value, '调整');
    assert.equal(state.ledgerSort.value, 'oldest');
    await state.selectProject(7);
    assert.equal(state.ledgerKind.value, 'all');
    assert.equal(state.ledgerSearch.value, '');
    assert.equal(state.ledgerSort.value, 'newest');
});

test('imported entries show a readable filename while retaining full source provenance', () => {
    const state = create(async () => ok({}));
    const entry = { source: '人工确认文件导入；来源未独立核验；file=合成验收.png；sha256='+'a'.repeat(64)+'；method=image_ocr；row=3' };
    const before = entry.source;
    assert.equal(state.entrySource(entry), '图片导入 · 合成验收.png · 第3行');
    assert.equal(entry.source, before);
    assert.equal(state.entrySource({ source: '人工实际录入' }), '人工实际录入');
});

test('import opens in selected project and never discards a currently edited entry', async () => {
    const state = create(async () => ok({ list: [], layout: { order: [] } }));
    state.detail.value = projectDetail(5);
    state.entryForm.value = { amount: '123.45', notes: '未保存输入' };
    state.beginImport();
    assert.equal(state.importOpened.value, false);
    assert.equal(state.entryForm.value.amount, '123.45');
    state.entryForm.value = null;
    state.beginImport();
    assert.equal(state.importOpened.value, true);
    assert.equal(state.importProject.value.id, 5);
    assert.equal(state.detail.value.project.id, 5);
});

test('importing into a different selected project reads back the actual saved target', async () => {
    const reads = [];
    const state = create(async path => {
        reads.push(path);
        if (/\/projects\/\d+/.test(path)) return ok(projectDetail(Number(path.match(/\/projects\/(\d+)/)[1])));
        return ok({ list: [], layout: { order: [] } });
    });
    state.detail.value = projectDetail(5);
    state.beginImport();
    await state.importSaved({ imported_count: 2, mode: 'entries', project_ids: [7] });
    assert.equal(state.importOpened.value, false);
    assert.equal(state.detail.value.project.id, 7);
    assert.ok(reads.some(path => path.startsWith('/investment-payback/projects/7')));
    assert.match(state.notice.value, /已导入 2 行/);
});

test('balance display follows surplus, exact recovery, reopened deficit and missing amounts without changing the ledger', () => {
    const state=create(async()=>ok({}));
    const surplus={invested_amount:'480000.00',net_recovered_amount:'493490.00',unrecovered_amount:'0.00',excess_recovered_amount:'13490.00',recovery_percent:102.81};
    const before=JSON.stringify(surplus);
    assert.equal(state.paybackBalance(surplus).label,'回本后盈余');
    assert.equal(state.paybackBalance(surplus).amount,'13490.00');
    assert.equal(state.compactMoney(state.paybackBalance(surplus).amount),'1.349 万元');
    assert.equal(JSON.stringify(surplus),before);
    assert.equal(state.paybackBalance({...surplus,excess_recovered_amount:'0.01'}).amount,'0.01');
    const exact=state.paybackBalance({...surplus,net_recovered_amount:'480000.00',excess_recovered_amount:'0.00',recovery_percent:100});
    assert.equal(exact.label,'已回本');
    assert.equal(exact.covered,true);
    assert.equal(exact.amount,null);
    const reopened=state.paybackBalance({...surplus,state:'reopened',net_recovered_amount:'479999.99',unrecovered_amount:'0.01',excess_recovered_amount:'0.00'});
    assert.equal(reopened.label,'尚未收回');
    assert.equal(reopened.amount,'0.01');
    const missing=state.paybackBalance({invested_amount:null,unrecovered_amount:null,excess_recovered_amount:null});
    assert.equal(missing.covered,false);
    assert.equal(state.money(missing.amount),'待录入');
    assert.equal(state.money(state.paybackBalance(null).amount),'待录入');
    const blank=state.paybackBalance({invested_amount:'480000.00',unrecovered_amount:'',excess_recovered_amount:''});
    assert.equal(blank.covered,false);
    assert.equal(state.money(blank.amount),'待录入');
});

test('failed project save preserves values and request identity for safe retry', async () => {
    const inputs = [];
    let attempts = 0;
    const state = create(async (path, options) => {
        if (options.method === 'POST') {
            inputs.push(JSON.parse(options.body));
            assert.equal(options.withBusinessContext, false);
            if (++attempts === 1) throw new Error('测试保存失败');
            return ok(projectDetail(3));
        }
        return ok({ list: [], pagination: { total: 0 } });
    });
    state.beginProject();
    state.projectForm.value.project_name = '明确标注的测试项目';
    state.projectForm.value.investor_name = '测试主体';
    await state.saveProject();
    assert.equal(state.formError.value, '测试保存失败');
    assert.equal(state.projectForm.value.project_name, '明确标注的测试项目');
    await state.saveProject();
    assert.equal(inputs[0].client_request_id, inputs[1].client_request_id);
    assert.equal(state.detail.value.project.id, 3);
    assert.equal(state.entryForm.value, null);
});

test('new project can save current cumulative balances in one request without inventing history', async () => {
    const saved = [];
    const state = create(async (path, options) => {
        if (options.method === 'POST') { saved.push({ path, input: JSON.parse(options.body) }); return ok(projectDetail(3)); }
        return ok({ list: [] });
    });
    state.beginProject();
    Object.assign(state.projectForm.value, { project_name: '累计余额测试', opening_invested: '1200000.01', opening_recovered: '250000.02' });
    await state.saveProject();
    assert.equal(saved.length, 1);
    assert.equal(saved[0].path, '/investment-payback/projects');
    assert.equal(saved[0].input.opening_invested, '1200000.01');
    assert.equal(saved[0].input.opening_recovered, '250000.02');
    assert.equal(saved[0].input.opening_as_of, state.asOf.value);
    assert.equal(saved[0].input.opening_source, '人工录入累计余额');
    assert.equal(saved[0].input.history_complete_through, '');
    assert.equal(saved[0].input.first_invested_on, '');
    assert.equal(state.entryForm.value, null);
});

test('name-only creation keeps missing amounts missing and rejects a half-filled opening balance', async () => {
    const saved = [];
    const state = create(async (path, options) => {
        if (options.method === 'POST') { saved.push(JSON.parse(options.body)); return ok(projectDetail(1)); }
        return ok({ list: [] });
    });
    state.beginProject();
    state.projectForm.value.project_name = '尚无金额测试';
    state.projectForm.value.opening_invested = '100.00';
    const nonce = state.projectForm.value.client_request_id;
    await state.saveProject();
    assert.equal(saved.length, 0);
    assert.match(state.formError.value, /请同时填写/);
    assert.equal(state.projectForm.value.client_request_id, nonce);
    state.projectForm.value.opening_invested = '';
    await state.saveProject();
    assert.equal(saved[0].opening_as_of, '');
    assert.equal(saved[0].opening_source, '');
    assert.equal(saved[0].opening_invested, '');
    assert.equal(saved[0].opening_recovered, '');
});

test('collapsed project metadata and existing opening balances survive a simple edit', async () => {
    let saved;
    const state = create(async (path, options) => {
        if (options.method === 'POST') { saved = JSON.parse(options.body); return ok(projectDetail(7)); }
        return ok({list: []});
    });
    const existing = { id: 7, version: 4, project_name: '编辑前', investor_name: '测试投资公司', hotel_id: 12, opening_as_of: '2026-09-01', opening_invested: '15000.10', opening_recovered: '-50.20', opening_source: '测试核对表', expected_monthly_amount: '2500.01', expected_source: '测试预测', forecast_as_of: '2026-08-31', history_complete_through: '2026-09-30', first_invested_on: '2026-01-02', notes: '保留测试备注' };
    state.beginProject(existing);
    state.projectForm.value.project_name = '编辑后';
    await state.saveProject();
    for (const field of ['investor_name', 'hotel_id', 'opening_as_of', 'opening_invested', 'opening_recovered', 'opening_source', 'expected_monthly_amount', 'expected_source', 'forecast_as_of', 'history_complete_through', 'first_invested_on', 'notes']) assert.equal(saved[field], existing[field], field);
    assert.equal(saved.expected_version, 4);
});

test('overview sums exact cents only for known amounts and progress keeps the actual signed ratio', () => {
    const state = create(async () => ok({list: []}));
    state.projects.value = [
        {id: 1, project_name:'待录入', summary:{invested_amount:null, net_recovered_amount:null}},
        {id: 2, project_name:'已录入甲', summary:{invested_amount:'100000.01', net_recovered_amount:'100.02'}},
        {id: 3, project_name:'已录入乙', summary:{invested_amount:'1200000.02', net_recovered_amount:'-50.01'}},
    ];
    assert.equal(state.overview.value.investment.amount, '1300000.03');
    assert.equal(state.overview.value.recovery.amount, '50.01');
    assert.equal(state.overview.value.investment.known, 2);
    assert.equal(state.overview.value.missing, 1);
    assert.equal(state.progressWidth(-5), 0);
    assert.equal(state.progressWidth(125), 100);
    assert.equal(state.compactMoney(null), '待录入');
    assert.match(state.compactMoney('100000.01'), /10\.000001/);
    assert.match(state.forecastBrief({status:'trial', remaining_months:2.5, whole_months:3}), /3.*试算/);
    assert.match(state.forecastBrief({status:'non_positive'}), /无法测算/);
    state.search.value = '已录入甲';
    assert.equal(state.overview.value.count, 1);
    assert.equal(state.overview.value.investment.amount, '100000.01');
});

test('leaving the detail cancels pending reads and quick entry stays on its requested project', async () => {
    let resolve;
    const state = create(() => new Promise(done => { resolve = done; }));
    const pending = state.quickEntry(1, 'recovery');
    assert.equal(state.detailLoading.value, true);
    state.closeDetail();
    resolve(ok(projectDetail(1)));
    await pending;
    assert.equal(state.detail.value, null);
    assert.equal(state.detailLoading.value, false);
    assert.equal(state.entryForm.value, null);
});

test('project comparison separates each project gap from surplus and never changes saved card order', () => {
    const state = create(async () => ok({list: []}));
    state.projects.value = [
        {id: 1, project_name:'盈余项目', summary:{invested_amount:'100.00', net_recovered_amount:'150.01', unrecovered_amount:'0.00', excess_recovered_amount:'50.01', recovery_percent:'150.01'}},
        {id: 2, project_name:'待补项目', summary:{invested_amount:null, net_recovered_amount:null, unrecovered_amount:null, excess_recovered_amount:null, recovery_percent:null}},
        {id: 3, project_name:'缺口项目', summary:{invested_amount:'200.03', net_recovered_amount:'0.02', unrecovered_amount:'200.01', excess_recovered_amount:'0.00', recovery_percent:'0.01'}},
        {id: 4, project_name:'刚好覆盖项目', summary:{invested_amount:'10.00', net_recovered_amount:'10.00', unrecovered_amount:'0.00', excess_recovered_amount:'0.00', recovery_percent:'100'}},
    ];
    assert.equal(state.overview.value.gap.amount, '200.01');
    assert.equal(state.overview.value.excess.amount, '50.01');
    assert.equal(state.overview.value.gap.known, 3);
    assert.equal(state.overview.value.covered, 2);
    assert.deepEqual(Array.from(state.comparisonRows.value, row => row.id), [3, 1, 4, 2]);
    state.comparisonSort.value = 'progress';
    assert.deepEqual(Array.from(state.comparisonRows.value, row => row.id), [1, 4, 3, 2]);
    state.comparisonSort.value = 'manual';
    assert.deepEqual(Array.from(state.comparisonRows.value, row => row.id), [1, 2, 3, 4]);
    assert.deepEqual(Array.from(state.projects.value, row => row.id), [1, 2, 3, 4]);
    state.search.value = '缺口';
    assert.equal(state.comparisonRows.value.length, 1);
    assert.equal(state.overview.value.excess.amount, '0.00');
});

test('cumulative timeline preserves opening scope and shows an added investment and refund reopening a gap', () => {
    const state = create(async () => ok({list: []}));
    state.detail.value = {
        project:{id:1, opening_as_of:'2026-09-30', opening_invested:'480000.00', opening_recovered:'493490.00'},
        summary:{as_of:'2026-10-01', invested_amount:'500000.00', net_recovered_amount:'488490.00'},
        entries:[
            {date:'2026-09-01', precision:'day', kind:'recovery', amount:'493490.00'},
            {date:'2026-10-01', precision:'day', kind:'investment', amount:'20000.00'},
            {date:'2026-10-01', precision:'day', kind:'refund', amount:'5000.00'},
            {date:'2026-10-01', precision:'month', kind:'recovery', amount:'9000.00'},
            {date:'2026-10-01', precision:'day', kind:'recovery', amount:'9000.00', is_planned:true},
            {date:'2026-10-01', precision:'day', kind:'recovery', amount:'9000.00', voided_at:'2026-10-01'},
        ],
    };
    const timeline = state.cumulativeTimeline.value;
    assert.equal(timeline.available, true);
    assert.equal(timeline.rows.length, 2);
    assert.equal(timeline.rows[0].excess, '13490.00');
    assert.equal(timeline.rows[1].investment, '500000.00');
    assert.equal(timeline.rows[1].recovery, '488490.00');
    assert.equal(timeline.rows[1].gap, '11510.00');
    assert.equal(timeline.rows[1].excess, '0.00');
    assert.match(timeline.investmentPath, /H.* V/);
    assert.match(timeline.recoveryPath, /H.* V/);
    assert.doesNotMatch(timeline.recoveryPath, / L/);
});

test('cumulative timeline uses recorded event dates only and exact cents including negative net recovery', () => {
    const state = create(async () => ok({list: []}));
    state.detail.value = {
        project:{id:1},
        summary:{as_of:'2026-09-30', invested_amount:'100.01', net_recovered_amount:'-0.01'},
        entries:[
            {date:'2026-04-01', precision:'day', kind:'investment', amount:'100.01'},
            {date:'2026-09-01', precision:'month', kind:'recovery', amount:'0.02'},
            {date:'2026-09-30', precision:'day', kind:'refund', amount:'0.03'},
        ],
    };
    const timeline = state.cumulativeTimeline.value;
    assert.equal(timeline.available, true);
    assert.equal(timeline.rows.length, 2);
    assert.equal(timeline.rows[1].date, '2026-09-30');
    assert.equal(timeline.rows[1].label, '2026-09（含按月记录）');
    assert.equal(timeline.rows[1].recovery, '-0.01');
    assert.equal(timeline.rows[1].gap, '100.02');
    assert.ok(timeline.rows[1].recoveryY > timeline.zeroY);
    assert.equal(timeline.rows[0].x, 45);
    assert.equal(timeline.rows[1].x, 555);
});

test('a partial or conflicting ledger cannot manufacture a cumulative chart', () => {
    const state = create(async () => ok({list: []}));
    assert.equal(state.cumulativeTimeline.value.available, false);
    state.detail.value = {project:{id:1}, summary:{as_of:'2026-10-01', invested_amount:null, net_recovered_amount:null}, entries:[]};
    assert.equal(state.cumulativeTimeline.value.available, false);
    state.detail.value = {project:{id:1}, summary:{as_of:'2026-10-01', invested_amount:'100.00', net_recovered_amount:'10.00'}, entries:[{date:'2026-09-01', precision:'day', kind:'investment', amount:'100.00'}]};
    assert.equal(state.cumulativeTimeline.value.available, false);
    assert.equal(state.cumulativeTimeline.value.matches, false);
    assert.equal(state.cumulativeTimeline.value.rows.length, 0);
});

test('a late project response cannot replace the currently selected project', async () => {
    let resolveFirst;
    const state = create(path => path.includes('/projects/1?') ? new Promise(resolve => { resolveFirst = resolve; }) : Promise.resolve(ok(projectDetail(2))));
    const first = state.selectProject(1);
    await state.selectProject(2);
    resolveFirst(ok(projectDetail(1)));
    await first;
    assert.equal(state.detail.value.project.id, 2);
});

test('changing cutoff cancels an older in-flight project read before the new list returns', async () => {
    let resolveDetail, resolveList;
    const state = create(path => path.includes('/projects/1?')
        ? new Promise(resolve => { resolveDetail = resolve; })
        : new Promise(resolve => { resolveList = resolve; }));
    state.asOf.value = '2026-09-30';
    const pendingDetail = state.selectProject(1);
    state.asOf.value = '2026-10-01';
    const pendingCutoff = state.changeAsOf();
    resolveDetail(ok({ ...projectDetail(1), summary: { as_of: '2026-09-30' } }));
    await pendingDetail;
    const ignoredOldScope = state.detail.value === null;
    resolveList(ok({ list: [], layout: { order: [] } }));
    await pendingCutoff;
    assert.equal(ignoredOldScope, true, 'old cutoff detail must stay hidden while the new scope loads');
    assert.equal(state.detail.value, null);
    assert.equal(state.detailLoading.value, false);
});

test('a cutoff refresh cannot reopen the old project after the user selects another project', async () => {
    let resolveList;
    const state = create(path => path.includes('/projects?')
        ? new Promise(resolve => { resolveList = resolve; })
        : Promise.resolve(ok(projectDetail(Number(path.match(/\/projects\/(\d+)/)[1])))));
    state.detail.value = projectDetail(1);
    state.asOf.value = '2026-10-01';
    const pendingCutoff = state.changeAsOf();
    await state.selectProject(2);
    resolveList(ok({ list: [], layout: { order: [] } }));
    await pendingCutoff;
    assert.equal(state.detail.value.project.id, 2);
});

test('a late project read cannot overwrite a newly created project', async () => {
    let resolveRead;
    const state = create(async (path, options) => {
        if (path.includes('/projects/1?')) return new Promise(resolve => { resolveRead = resolve; });
        if (options.method === 'POST') return ok(projectDetail(3));
        return ok({list: []});
    });
    const pending = state.selectProject(1);
    state.beginProject();
    state.projectForm.value.project_name = '新建迟到读取测试';
    await state.saveProject();
    resolveRead(ok(projectDetail(1)));
    await pending;
    assert.equal(state.detail.value.project.id, 3);
    assert.equal(state.detailLoading.value, false);
});

test('refund and confirmed-zero inputs preserve explicit types and precision', async () => {
    const saved = [];
    const state = create(async (path, options) => {
        if (options.method === 'POST') { saved.push(JSON.parse(options.body)); return ok(projectDetail(1)); }
        return ok({ list: [] });
    });
    state.detail.value = projectDetail(1);
    state.beginEntry('refund');
    state.entryForm.value.amount = '100.01';
    state.entryForm.value.notes = '测试退款来源与原因';
    await state.saveEntry();
    assert.equal(saved[0].kind, 'refund');
    assert.equal(saved[0].amount, '100.01');
    state.beginEntry('recovery');
    state.entryForm.value.amount = '0';
    state.entryForm.value.confirmed_zero = true;
    state.entryForm.value.precision = 'month';
    state.changePrecision();
    await state.saveEntry();
    assert.equal(saved[1].confirmed_zero, true);
    assert.match(saved[1].date, /^\d{4}-\d{2}$/);
    state.beginEntry('recovery');
    state.entryForm.value.confirmed_zero = true;
    state.entryForm.value.amount = '150.01';
    await state.saveEntry();
    assert.equal(saved[2].confirmed_zero, false);
});

test('failed list reads stay visibly failed instead of presenting an empty success', async () => {
    const state = create(async () => { throw new Error('测试读取失败'); });
    await state.refreshProjects();
    assert.equal(state.listError.value, '测试读取失败');
    assert.equal(state.loading.value, false);
    assert.match(source, /项目列表读取失败/);
});

test('month precision does not invent a day and new records start with blank amounts', () => {
    const state = create(async () => ok({list: []}));
    state.beginEntry('recovery');
    state.entryForm.value.amount = '500';
    state.entryForm.value.precision = 'month';
    state.changePrecision();
    state.entryForm.value.precision = 'day';
    state.changePrecision();
    assert.equal(state.entryForm.value.date, '');
    state.beginEntry('investment');
    assert.equal(state.entryForm.value.amount, '');
});

test('projects beyond the first page remain reachable without losing earlier projects', async () => {
    const calls = [];
    const state = create(async path => {
        calls.push(path);
        const next = path.includes('&page=2');
        return ok({ list: next ? [{id: 1}] : [{id: 2}], pagination: {total: 2} });
    });
    await state.refreshProjects();
    assert.equal(state.hasMore.value, true);
    await state.loadMore();
    assert.deepEqual(Array.from(state.projects.value, row => row.id), [2, 1]);
    assert.equal(state.hasMore.value, false);
    assert.equal(calls.length, 2);
});

test('the investment-payback workbench keeps source, scope and forecast boundaries visible', () => {
    assert.match(source, /投资人实收口径/);
    assert.match(source, /人工录入，来源未独立核验/);
    assert.match(source, /未录入月份不补零/);
    assert.match(source, /first_payback/);
    assert.match(source, /non_positive/);
    assert.match(source, /withBusinessContext: false/);
});

test('accounting history displays the actual before and after amounts', () => {
    const state = create(async () => ok({list: []}));
    const audit = {entry_id: 2, payload: {before: {kind: 'recovery', amount: '600000.00', date:'2026-09-30', precision:'day'}, after: {kind:'recovery', amount:'550000.00', date:'2026-09-30', precision:'day'}}};
    assert.match(state.auditChange(audit), /600,000\.00 元.*→.*550,000\.00 元/);
    assert.equal(state.auditLabel('entry_voided'), '作废资金记录');
});

test('monthly accounting excludes opening overlaps, plans and records after the cutoff', () => {
    const state = create(async () => ok({list: []}));
    state.asOf.value = '2026-10-01';
    state.detail.value = {...projectDetail(1), project: {id:1, opening_as_of:'2026-08-31'}, entries:[
        {kind:'recovery',amount:'100.00',date:'2026-08',precision:'month'},
        {kind:'recovery',amount:'200.00',date:'2026-09-30',precision:'day'},
        {kind:'refund',amount:'30.00',date:'2026-09-30',precision:'day'},
        {kind:'recovery',amount:'400.00',date:'2026-10',precision:'month'},
        {kind:'investment',amount:'999.00',date:'2026-09-01',precision:'day',is_planned:true},
    ]};
    assert.equal(state.monthlyRows.value.length, 1);
    assert.equal(state.monthlyRows.value[0].month, '2026-09');
    assert.equal(state.monthlyRows.value[0].recovery, '170.00');
    assert.equal(state.monthlyRows.value[0].investment, null);
    assert.equal(state.entryState(state.detail.value.entries[0]), '期初范围覆盖或重叠，未叠加');
    assert.equal(state.entryState(state.detail.value.entries[3]), '截至日之后，未计入本次汇总');
});

const cardProjects = () => [
    {id:1,project_name:'显示甲',version:4,summary:{invested_amount:'1200000.01',net_recovered_amount:'300000.02'}},
    {id:2,project_name:'隐藏乙',version:3,summary:{invested_amount:null,net_recovered_amount:null}},
    {id:3,project_name:'显示丙',version:2,summary:{invested_amount:'100000.03',net_recovered_amount:'24000.04'}},
    {id:4,project_name:'隐藏丁',version:1,summary:{invested_amount:'500000.05',net_recovered_amount:'0.00'}},
];

test('card order automatically persists and is restored on a fresh workbench without changing accounting', async () => {
    const list = cardProjects();
    let order = [];
    const writes = [];
    const request = async (path, options) => {
        if (options.method === 'POST') {
            assert.equal(path, '/investment-payback/layout');
            assert.equal(options.withBusinessContext, false);
            const input = JSON.parse(options.body);
            assert.deepEqual(Object.keys(input).sort(), ['as_of','order']);
            order = input.order;
            writes.push(order);
            return ok({order});
        }
        return ok({list,layout:{order},pagination:{total:4}});
    };
    const state = create(request);
    await state.refreshProjects();
    assert.equal(state.orderReady.value, true);
    await state.moveCard(4,1);
    assert.deepEqual(writes, [[4,1,2,3]]);
    assert.deepEqual(Array.from(state.projects.value, row=>row.id), [4,1,2,3]);
    assert.equal(state.orderNotice.value, '顺序已自动保存');
    for (const project of state.projects.value) assert.equal(project, list.find(row=>row.id===project.id));
    const reopened = create(request);
    await reopened.refreshProjects();
    assert.deepEqual(Array.from(reopened.projects.value,row=>row.id), [4,1,2,3]);
    assert.equal(reopened.overview.value.investment.amount, '1800000.09');
    assert.equal(reopened.projects.value.find(row=>row.id===2).summary.invested_amount, null);
});

test('search-hidden cards keep their slots and unloaded order survives a subset reorder', async () => {
    let input;
    const state = create(async (path, options) => {
        if (options.method === 'POST') { input = JSON.parse(options.body); return ok({order:[9,...input.order,8]}); }
        return ok({list:cardProjects(),layout:{order:[9,1,2,3,4,8]},pagination:{total:6}});
    });
    await state.refreshProjects();
    state.search.value = '显示';
    await state.moveCard(3,1);
    assert.deepEqual(input.order,[3,2,1,4]);
    assert.deepEqual(Array.from(state.rows.value,row=>row.id),[3,1]);
    assert.equal(state.hasMore.value,true);
});

test('an unconfirmed order save rolls back its display and cannot overwrite a second drag', async () => {
    let rejectSave;
    let writes = 0;
    let reads = 0;
    const state = create(async (path, options) => {
        if (options.method === 'POST') { writes++; return new Promise((resolve,reject)=>{rejectSave=reject;}); }
        reads++;
        return ok({list:cardProjects(),layout:{order:reads===1?[]:[4,1,2,3]},pagination:{total:4}});
    });
    await state.refreshProjects();
    const first = state.moveCard(4,1);
    assert.equal(state.orderSaving.value,true);
    await state.moveCard(2,1);
    await state.refreshProjects();
    assert.equal(writes,1);
    assert.equal(reads,1);
    rejectSave(new Error('模拟响应丢失'));
    await first;
    assert.deepEqual(Array.from(state.projects.value,row=>row.id),[1,2,3,4]);
    assert.match(state.orderError.value,/保存未确认.*模拟响应丢失/);
    assert.equal(state.orderReady.value,false);
    assert.equal(state.orderSaving.value,false);
    await state.refreshProjects();
    assert.deepEqual(Array.from(state.projects.value,row=>row.id),[4,1,2,3]);
    assert.equal(state.orderError.value,'');
});

test('missing layout and mismatched readback cannot be reported as a saved card order', async () => {
    const state = create(async (path, options) => options.method==='POST' ? ok({order:[1,2,3,4]}) : ok({list:cardProjects()}));
    await state.refreshProjects();
    assert.equal(state.projects.value.length,4);
    assert.equal(state.orderReady.value,false);
    assert.match(state.orderError.value,/顺序读取失败/);
    state.orderReady.value=true;
    await state.moveCard(4,1);
    assert.match(state.orderError.value,/读回的顺序与拖动结果不一致/);
    assert.equal(state.orderNotice.value,'');
    assert.deepEqual(Array.from(state.projects.value,row=>row.id),[1,2,3,4]);
});

test('pointer drag saves only on a valid release and cancellation does not save', async () => {
    const writes = [];
    const target = {dataset:{projectId:'1'},closest:()=>target};
    const control = {setPointerCapture:()=>{},hasPointerCapture:()=>true,releasePointerCapture:()=>{}};
    const event = (x,y) => ({pointerId:1,pointerType:'touch',isPrimary:true,button:0,clientX:x,clientY:y,currentTarget:control,preventDefault:()=>{}});
    const state = create(async (path, options) => {
        if(options.method==='POST') {const input=JSON.parse(options.body);writes.push(input.order);return ok({order:input.order});}
        return ok({list:cardProjects(),layout:{order:[]}});
    }, {document:{elementsFromPoint:()=>[{closest:()=>target}]},window:{requestAnimationFrame:()=>1,cancelAnimationFrame:()=>{}}});
    await state.refreshProjects();
    state.startCardDrag(event(10,10),4);
    state.moveCardDrag(event(11,11));
    await state.finishCardDrag(event(11,11));
    assert.equal(writes.length,0);
    state.startCardDrag(event(10,10),4);
    state.moveCardDrag(event(80,90));
    assert.equal(state.dropTarget.value,1);
    assert.equal(state.cardDragStyle(4).pointerEvents,'none');
    state.cancelCardDrag();
    await state.finishCardDrag(event(80,90));
    assert.equal(writes.length,0);
    state.startCardDrag(event(10,10),4);
    state.moveCardDrag(event(80,90));
    await state.finishCardDrag(event(80,90));
    assert.deepEqual(writes,[[4,1,2,3]]);
    assert.equal(state.cardDrag.value,null);
});

test('keyboard card movement observes boundaries and also saves automatically', async () => {
    let writes=0;
    const state=create(async (path,options)=> {
        if(options.method==='POST') {writes++;return ok({order:JSON.parse(options.body).order});}
        return ok({list:cardProjects(),layout:{order:[]}});
    });
    await state.refreshProjects();
    let prevented=0;
    await state.moveCardByKey({key:'ArrowLeft',preventDefault:()=>prevented++},1);
    assert.equal(writes,0);
    await state.moveCardByKey({key:'ArrowUp',preventDefault:()=>prevented++},3);
    assert.deepEqual(Array.from(state.projects.value,row=>row.id),[1,3,2,4]);
    assert.equal(writes,1);
    assert.equal(prevented,2);
});

test('card body opens the matching ledger while text selection, controls and dragging do not navigate', async () => {
    const reads=[];
    let selectedText='';
    const state=create(async(path)=>{reads.push(path);return ok(projectDetail(2));}, {window:{getSelection:()=>({toString:()=>selectedText})}});
    const plain={target:{closest:()=>null}};
    await state.openCard({target:{closest:()=>({})}},2);
    selectedText='正在复制金额';
    await state.openCard(plain,2);
    selectedText='';
    state.cardDrag.value={id:2,active:true};
    await state.openCard(plain,2);
    assert.equal(reads.length,0);
    state.cardDrag.value=null;
    await state.openCard(plain,2);
    assert.equal(reads.length,1);
    assert.match(reads[0],/^\/investment-payback\/projects\/2\?as_of=/);
    assert.equal(state.detail.value.project.id,2);
});

test('pointer drag auto-scrolls the application scroll container and cancellation stops scrolling', async () => {
    let frame;
    let cancelled=0;
    let windowScrolls=0;
    const scrollHost={scrollHeight:1600,clientHeight:600,scrollTop:30,parentElement:null,getBoundingClientRect:()=>({top:80,bottom:680}),scrollBy:(x,y)=>{scrollHost.scrollTop+=y;}};
    const control={parentElement:{scrollHeight:44,clientHeight:44,parentElement:scrollHost},setPointerCapture:()=>{}};
    const target={dataset:{projectId:'1'},closest:()=>target};
    const state=create(async()=>ok({list:cardProjects(),layout:{order:[]}}),{document:{elementsFromPoint:()=>[{closest:()=>target}]},window:{getComputedStyle:()=>({overflowY:'auto'}),requestAnimationFrame:callback=>{frame=callback;return 7;},cancelAnimationFrame:()=>cancelled++,scrollBy:()=>windowScrolls++}});
    await state.refreshProjects();
    state.startCardDrag({pointerId:1,pointerType:'touch',isPrimary:true,currentTarget:control,clientX:100,clientY:300},4);
    state.moveCardDrag({pointerId:1,clientX:100,clientY:660,preventDefault:()=>{}});
    frame();
    assert.equal(scrollHost.scrollTop,42);
    assert.equal(windowScrolls,0);
    assert.equal(state.cardDragStyle(4).transform,'translate(0px, 372px)');
    state.cancelCardDrag();
    frame();
    assert.equal(scrollHost.scrollTop,42);
    assert.equal(cancelled,1);
});

test('admin delete includes voided records, saves an explicit version and reads back the recalculated ledger', async () => {
    const entry={id:15,version:3,kind:'recovery',amount:'100.01',date:'2026-09-30',precision:'day',source:'模拟来源',voided_at:'2026-10-01 08:00:00'};
    const written=[];
    const before={...projectDetail(1),can_delete_entries:true,entries:[entry]};
    const after={...before,project:{...before.project,version:2},entries:[],summary:{invested_amount:'1000.00',net_recovered_amount:'0.00'}};
    const state=create(async(path,options)=>{
        if(options.method==='POST'){written.push({path,input:JSON.parse(options.body)});return ok(after);}
        return ok({list:cardProjects(),layout:{order:[]}});
    });
    state.detail.value=before;
    state.openConfirmation('delete',entry);
    assert.equal(state.confirmation.type,'delete');
    await state.confirmAction();
    assert.equal(written.length,1);
    assert.equal(written[0].path,'/investment-payback/projects/1/entries/15/delete');
    assert.equal(written[0].input.expected_version,3);
    assert.equal(written[0].input.reason,'管理员主动删除');
    assert.equal(state.detail.value.entries.length,0);
    assert.equal(state.detail.value.summary.net_recovered_amount,'0.00');
    assert.equal(state.confirmation.type,'');
    assert.match(state.notice.value,/已删除并读回/);
    assert.match(state.auditChange({event_type:'entry_deleted',payload:{before:entry,after:{delete_reason:'管理员主动删除'}}}),/100\.01 元.*2026-09-30.*模拟来源/);
    assert.equal(state.auditLabel('entry_deleted'),'管理员删除资金记录');
});

test('regular users have no delete confirmation and failed admin deletion retains the record for correction', async () => {
    const entry={id:8,version:2,kind:'recovery',amount:'200.00'};
    const state=create(async()=>{throw new Error('关联退款仍引用此记录');});
    state.detail.value={...projectDetail(1),can_delete_entries:false,entries:[entry]};
    state.openConfirmation('delete',entry);
    assert.equal(state.confirmation.type,'');
    state.detail.value.can_delete_entries=true;
    state.openConfirmation('delete',entry);
    state.confirmation.reason='模拟误录';
    await state.confirmAction();
    assert.equal(state.detail.value.entries[0],entry);
    assert.equal(state.confirmation.type,'delete');
    assert.equal(state.confirmation.reason,'模拟误录');
    assert.match(state.formError.value,/关联退款/);
    state.closeForms();
    assert.equal(state.confirmation.type,'');
});
