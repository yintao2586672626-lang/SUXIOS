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
    vm.runInNewContext(source, { Vue, window: { SUXI_SYSTEM_COMPONENTS: registry, crypto: { randomUUID: () => `test-id-${++nonce}` }, setTimeout: environment.setTimeout || setTimeout, clearTimeout: environment.clearTimeout || clearTimeout, ...environment.window }, document: environment.document, Intl, Date, Number, Object, AbortController, DOMException, setTimeout: environment.setTimeout || setTimeout, clearTimeout: environment.clearTimeout || clearTimeout });
    return registry.InvestmentPaybackBody.setup({ request, hotels: [] });
};
const fixtureToday = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const projectDetail = (id, asOf = fixtureToday) => ({ project: { id, project_name: `测试项目${id}`, version: 1 }, entries: [], summary: { as_of: asOf }, audit_history: [] });
const ok = data => ({ code: 200, data });
const entryReadback = (projectId, input, changes = {}) => ({ ...projectDetail(projectId, input.as_of ?? fixtureToday), entries: [{
    id: input.id || 900, project_id: projectId, tenant_id: 2, client_request_id: input.client_request_id,
    kind: input.kind, amount: String(input.amount), date: input.date, precision: input.precision || 'day',
    is_planned: input.is_planned === true, confirmed_zero: input.confirmed_zero === true, original_entry_id: input.original_entry_id || null,
    source: String(input.source ?? '').trim(), category: String(input.category ?? '').trim(), notes: String(input.notes ?? '').trim(), voided_at: null, ...changes,
}] });
const projectReadback = (id, input, changes = {}) => {
    const project = { ...input, id, version: input.expected_version ? Number(input.expected_version) + 1 : 1, tenant_id: input.tenant_id ?? 2 };
    for (const key of ['project_name', 'investor_name', 'expected_source', 'opening_source', 'notes']) if (key in input) project[key] = String(input[key] ?? '').trim();
    for (const key of ['hotel_id', 'first_invested_on', 'forecast_as_of', 'history_complete_through', 'opening_as_of', 'opening_invested', 'opening_recovered', 'expected_monthly_amount']) if (key in input) project[key] = input[key] == null || input[key] === '' ? null : key === 'hotel_id' ? Number(input[key]) : String(input[key]).trim();
    return { ...projectDetail(id, input.as_of ?? fixtureToday), project: { ...project, ...changes } };
};

test('different saved forecast values cannot replace the project or erase the forecast draft', async () => {
    for (const changes of [{ expected_monthly_amount: '123.46' }, { expected_source: '其他假设' }, { forecast_as_of: '2026-09-30' }]) {
        let input;
        const state = create(async (path, options) => { if (options.method === 'POST') input = JSON.parse(options.body); return ok(projectReadback(5, input, changes)); });
        state.asOf.value = '2026-10-01'; state.detail.value = projectDetail(5);
        state.forecastForm.amount = '123.45'; state.forecastForm.source = '当前假设';
        const before = state.detail.value;
        await state.saveForecast();
        assert.equal(state.detail.value, before);
        assert.equal(state.forecastForm.amount, '123.45'); assert.equal(state.forecastForm.source, '当前假设');
        assert.equal(state.notice.value, ''); assert.match(state.detailError.value, /精确回读未通过/);
    }
});

test('project create readback verifies saved fields and the creation identity', async () => {
    for (const changes of [{ project_name: '其他项目' }, { investor_name: '其他主体' }, { status: 'draft' }, { expected_source: '其他假设' }, { expected_monthly_amount: '600.02' }, { first_invested_on: '2026-09-01' }, { forecast_as_of: '2026-09-30' }, { opening_as_of: '2026-09-30' }, { opening_invested: '1000.02' }, { opening_recovered: '2.02' }, { opening_source: '其他凭据' }, { history_complete_through: '2026-09-30' }, { notes: '其他备注' }, { client_request_id: 'another-creation' }]) {
        let input;
        const state = create(async (path, options) => { if (options.method === 'POST') input = JSON.parse(options.body); return ok(projectReadback(5, input, changes)); });
        state.asOf.value = '2026-10-01'; state.beginProject();
        Object.assign(state.projectForm.value, { project_name: '本次项目', opening_invested: '1000.01', opening_recovered: '2.01', expected_monthly_amount: '600.01', notes: '本次备注' });
        const nonce = state.projectForm.value.client_request_id;
        await state.saveProject();
        assert.equal(state.projectForm.value?.project_name, '本次项目');
        assert.equal(state.projectForm.value?.client_request_id, nonce);
        assert.equal(state.detail.value, null); assert.equal(state.notice.value, '');
        assert.match(state.formError.value, /精确回读未通过/);
    }
});

test('a partial project or forecast write reply is confirmed by one scoped reread', async () => {
    for (const mode of ['project', 'forecast']) {
        let input, writes = 0, reads = 0;
        const state = create(async (path, options) => {
            if (options.method === 'POST') { writes++; input = JSON.parse(options.body); const response = projectReadback(5, input); delete response.project.expected_source; return ok(response); }
            if (path.includes('/projects/5?')) { reads++; return ok(projectReadback(5, input)); }
            return ok({ list: [] });
        });
        state.asOf.value = '2026-10-01';
        if (mode === 'project') { state.beginProject(); state.projectForm.value.project_name = '补读项目'; await state.saveProject(); assert.equal(state.projectForm.value, null); }
        else { state.detail.value = projectDetail(5); state.forecastForm.amount = '123.40'; state.forecastForm.source = ' 规范假设 '; await state.saveForecast(); }
        assert.equal(state.detail.value.project.expected_source, String(input.expected_source).trim());
        assert.equal(state.notice.value.includes('已保存并读回'), true);
        assert.equal(writes, 1); assert.equal(reads, 1);
    }
});

test('a wrong project edit retains its draft while a changed opening basis accepts cleared completeness', async () => {
    const existing = { id: 5, version: 4, tenant_id: 2, hotel_id: null, basis: 'investor_cash', currency: 'CNY', project_name: '原项目', investor_name: '本人', status: 'operating', forecast_as_of: '2026-10-01', opening_as_of: '2026-09-01', opening_invested: '1000.00', opening_recovered: '0.00', opening_source: '原始凭据', first_invested_on: null, history_complete_through: '2026-10-01', expected_monthly_amount: null, expected_source: '', notes: '' };
    for (const correct of [false, true]) {
        let input;
        const state = create(async (path, options) => {
            if (options.method === 'POST') input = JSON.parse(options.body);
            return path.includes('/projects?') ? ok({ list: [] }) : ok(projectReadback(5, input, { history_complete_through: null, ...(correct ? {} : { opening_source: '未采用本次凭据' }) }));
        });
        state.asOf.value = '2026-10-01'; state.detail.value = { ...projectDetail(5, '2026-10-01'), project: existing }; state.beginProject(existing);
        state.projectForm.value.opening_source = '新核对凭据';
        await state.saveProject();
        if (correct) {
            assert.equal(state.projectForm.value, null); assert.equal(state.detail.value.project.opening_source, '新核对凭据');
            assert.equal(state.detail.value.project.history_complete_through, null); assert.equal(state.formError.value, '');
        } else {
            assert.equal(state.projectForm.value?.opening_source, '新核对凭据'); assert.equal(state.detail.value.project.opening_source, '原始凭据');
            assert.match(state.formError.value, /精确回读未通过/); assert.equal(state.notice.value, '');
        }
    }
});

test('forecast clearing preserves explicit null and server text normalization including nonbreaking spaces', async () => {
    const source = '\u00a0人工假设\u00a0';
    let input;
    const state = create(async (path, options) => {
        if (options.method === 'POST') { input = JSON.parse(options.body); return ok(projectReadback(5, input, { expected_monthly_amount: null, expected_source: source })); }
        return ok({ list: [] });
    });
    state.asOf.value = '2026-10-01'; state.detail.value = projectDetail(5, '2026-10-01');
    state.forecastForm.amount = ''; state.forecastForm.source = ' ' + source + ' ';
    await state.saveForecast();
    assert.equal(state.detail.value.project.expected_monthly_amount, null);
    assert.equal(state.detail.value.project.expected_source, source); assert.equal(state.detailError.value, '');
    assert.match(state.notice.value, /已保存并读回/);
});

test('unlinked creation and a permitted draft unlink normalize hotel identity without accepting zero or bad IDs', async () => {
    for (const editing of [false, true]) {
        let writes = 0;
        const state = create(async (path, options) => {
            if (options.method === 'POST') { writes++; const input = JSON.parse(options.body); return ok(projectReadback(5, input, { hotel_id: null, history_complete_through: null })); }
            return ok({ list: [] });
        });
        state.asOf.value = '2026-10-01';
        state.beginProject(editing ? { ...projectReadback(5, { project_name: '草稿解绑', investor_name: '本人', hotel_id: 80, status: 'draft', basis: 'investor_cash', currency: 'CNY', forecast_as_of: '2026-10-01' }).project, id: 5 } : undefined);
        state.projectForm.value.project_name = '无绑定项目'; state.projectForm.value.hotel_id = '';
        await state.saveProject();
        assert.equal(state.projectForm.value, null); assert.equal(state.detail.value.project.hotel_id, null); assert.equal(writes, 1);
    }
    for (const hotel of [0, '0', 'oops', '080']) {
        let writes = 0;
        const state = create(async () => { writes++; return ok(projectDetail(5)); });
        state.beginProject(); state.projectForm.value.project_name = '保留错误酒店草稿'; state.projectForm.value.hotel_id = hotel;
        await state.saveProject();
        assert.equal(writes, 0); assert.equal(state.projectForm.value.hotel_id, hotel);
        assert.match(state.formError.value, /读回范围或格式不一致/);
    }
});

test('a legacy blank hotel scope agrees with a normalized null on a readonly response', async () => {
    const state = create(async () => ok({ ...projectDetail(5), project: { ...projectDetail(5).project, hotel_id: null } }));
    state.projects.value = [{ id: 5, hotel_id: '' }];
    await state.selectProject(5);
    assert.equal(state.detail.value.project.hotel_id, null); assert.equal(state.detailError.value, '');
});

test('normalizing a legacy opening source can legitimately clear its old history confirmation', async () => {
    const existing = { ...projectReadback(5, { id: 5, project_name: '旧项目', investor_name: '本人', hotel_id: null, basis: 'investor_cash', currency: 'CNY', status: 'operating', forecast_as_of: '2026-10-01', opening_as_of: '2026-09-01', opening_invested: '1000.00', opening_recovered: '0.00', opening_source: '原凭据', history_complete_through: '2026-10-01' }).project, opening_source: ' 原凭据 ' };
    const state = create(async (path, options) => options.method === 'POST' ? ok(projectReadback(5, JSON.parse(options.body), { history_complete_through: null })) : ok({ list: [] }));
    state.asOf.value = '2026-10-01'; state.beginProject(existing); state.projectForm.value.project_name = '更正名称';
    await state.saveProject();
    assert.equal(state.projectForm.value, null); assert.equal(state.detail.value.project.opening_source, '原凭据');
    assert.equal(state.detail.value.project.history_complete_through, null); assert.equal(state.formError.value, '');
});

test('an empty post and reread cannot claim an entry was saved or discard its draft', async () => {
    let writes = 0, reads = 0;
    const state = create(async (path, options) => { options.method === 'POST' ? writes++ : reads++; return ok(projectDetail(5, '2026-10-01')); });
    state.asOf.value = '2026-10-01'; state.detail.value = projectDetail(5); state.beginEntry('recovery'); state.entryForm.value.amount = '123.45';
    const identity = state.entryForm.value.client_request_id;
    await state.saveEntry();
    assert.equal(state.entryForm.value?.amount, '123.45');
    assert.equal(state.entryForm.value?.client_request_id, identity);
    assert.equal(state.notice.value, '');
    assert.match(state.formError.value, /精确回读未通过/);
    assert.equal(writes, 1); assert.equal(reads, 1);
});

test('an incomplete post response can confirm one exact record through a scoped reread', async () => {
    let input, writes = 0, reads = 0;
    const state = create(async (path, options) => {
        if (options.method === 'POST') { input = JSON.parse(options.body); writes++; return ok(projectDetail(5, input.as_of)); }
        if (path.includes('/projects/5?')) { reads++; return ok(entryReadback(5, input)); }
        return ok({ list: [] });
    });
    state.asOf.value = '2026-10-01'; state.detail.value = projectDetail(5); state.beginEntry('refund');
    Object.assign(state.entryForm.value, { amount: '123.45', original_entry_id: '7', notes: ' 同项目退款 ', source: ' 人工确认 ', category: ' 冲回 ' });
    await state.saveEntry();
    assert.equal(state.entryForm.value, null); assert.equal(state.detail.value.entries[0].original_entry_id, '7');
    assert.match(state.notice.value, /已保存并读回/);
    assert.equal(writes, 1); assert.equal(reads, 1);
});

test('matching record identity cannot confirm a different amount, date, type or provenance', async () => {
    for (const changes of [{ amount: '123.46' }, { date: '2026-09-30' }, { precision: 'month' }, { kind: 'investment' }, { source: '其他来源' }, { category: '其他类别' }, { notes: '其他原因' }, { is_planned: true }, { confirmed_zero: true }, { original_entry_id: 8 }, { voided_at: '2026-10-01' }]) {
        const state = create(async (path, options) => ok(entryReadback(5, JSON.parse(options.body), changes)));
        state.asOf.value = '2026-10-01'; state.detail.value = projectDetail(5); state.beginEntry('refund');
        Object.assign(state.entryForm.value, { amount: '123.45', original_entry_id: 7, notes: '退款核对', source: '人工来源', category: '冲回' });
        await state.saveEntry();
        assert.equal(state.entryForm.value?.amount, '123.45');
        assert.equal(state.notice.value, '');
        assert.match(state.formError.value, /精确回读未通过/);
    }
});

test('an edited record is confirmed by its ID, with cents normalized exactly', async () => {
    const state = create(async (path, options) => ok(entryReadback(5, JSON.parse(options.body), { amount: '0.00', version: 4 })));
    state.asOf.value = '2026-10-01'; state.detail.value = projectDetail(5);
    state.beginEntry('recovery', { id: 7, version: 3, kind: 'recovery', amount: '0', confirmed_zero: true, date: '2026-10-01', precision: 'day', source: '人工核对', notes: '', category: '', is_planned: false });
    await state.saveEntry();
    assert.equal(state.entryForm.value, null);
    assert.equal(state.detail.value.entries[0].id, 7);
    assert.match(state.notice.value, /已保存并读回/);
});

test('a save response from another cutoff or cash scope preserves the entry draft', async () => {
    for (const changes of [{ as_of: '2026-09-30' }, { basis: 'hotel_profit' }, { currency: 'USD' }]) {
        const state = create(async () => ok({ ...projectDetail(5), summary: { as_of: '2026-10-01', basis: 'investor_cash', currency: 'CNY', ...changes } }));
        state.asOf.value = '2026-10-01';
        state.detail.value = projectDetail(5); state.beginEntry('recovery'); state.entryForm.value.amount = '123.45';
        const identity = state.entryForm.value.client_request_id;
        await state.saveEntry();
        assert.equal(state.entryForm.value?.amount, '123.45');
        assert.equal(state.entryForm.value?.client_request_id, identity);
        assert.match(state.formError.value, /读回范围或格式不一致/);
        assert.equal(state.notice.value, '');
    }
});

test('matching project ID cannot admit another tenant, hotel or entry project scope', async () => {
    const known = { ...projectDetail(5, '2026-10-01'), project: { ...projectDetail(5).project, tenant_id: 2, hotel_id: 80 } };
    for (const changes of [{ project: { tenant_id: 3 } }, { project: { hotel_id: 81 } }, { entries: [{ id: 8, project_id: 6, tenant_id: 2 }] }, { entries: [{ id: 8, project_id: 5, tenant_id: 3 }] }]) {
        const reply = { ...known, ...changes, project: { ...known.project, ...changes.project } };
        const state = create(async () => ok(reply)); state.asOf.value = '2026-10-01';
        state.detail.value = known; state.beginEntry('recovery'); state.entryForm.value.amount = '1.00';
        await state.saveEntry();
        assert.equal(state.detail.value.project.tenant_id, 2);
        assert.equal(state.detail.value.project.hotel_id, 80);
        assert.equal(state.entryForm.value?.amount, '1.00');
        assert.match(state.formError.value, /读回范围或格式不一致/);
    }
});

test('duplicate projects and wrong-cutoff lists never double the displayed investment', async () => {
    for (const list of [[{ id: 5 }, { id: '5' }], [{ id: 5, summary: { as_of: '2026-09-30' } }], [{ id: 5, summary: { basis: 'hotel_profit' } }], [{ id: 5, currency: 'USD' }]]) {
        const state = create(async () => ok({ list, pagination: { total: list.length } })); state.asOf.value = '2026-10-01';
        await state.refreshProjects();
        assert.equal(state.projects.value.length, 0);
        assert.match(state.listError.value, /读回范围或格式不一致/);
        assert.equal(state.overview.value.investment.amount, null);
    }
});

test('opening a known card validates its hotel even after the detail is cleared for loading', async () => {
    const card = { id: 5, tenant_id: 2, hotel_id: 80 };
    const state = create(async () => ok({ ...projectDetail(5, '2026-10-01'), project: { ...card, hotel_id: 81 } }));
    state.asOf.value = '2026-10-01'; state.projects.value = [card];
    await state.selectProject(5);
    assert.equal(state.detail.value, null);
    assert.match(state.detailError.value, /读回范围或格式不一致/);
});

test('a next page from another tenant preserves the loaded accounting scope', async () => {
    const first = { id: 5, tenant_id: 2, summary: { as_of: '2026-10-01', invested_amount: '100.01' } };
    const second = { ...first, id: 6, tenant_id: 3 };
    const state = create(async path => ok({ list: [path.includes('&page=2') ? second : first], pagination: { total: 2 }, layout: { order: [5, 6] } }));
    state.asOf.value = '2026-10-01'; await state.refreshProjects(); await state.loadMore();
    assert.equal(state.projects.value.length, 1);
    assert.equal(state.overview.value.investment.amount, '100.01');
    assert.match(state.listError.value, /读回范围或格式不一致/);
});

test('a list refresh cannot silently replace the known tenant scope', async () => {
    const state = create(async () => ok({ list: [{ id: 6, tenant_id: 3 }], pagination: { total: 1 } }));
    state.projects.value = [{ id: 5, tenant_id: 2 }]; await state.refreshProjects();
    assert.equal(state.projects.value.length, 0);
    assert.match(state.listError.value, /读回范围或格式不一致/);
});

test('a duplicate on the next page keeps the first page and cannot be appended twice', async () => {
    const first = { id: 5, tenant_id: 2, summary: { as_of: '2026-10-01', invested_amount: '100.01' } };
    const state = create(async () => ok({ list: [first], pagination: { total: 2 }, layout: { order: [5] } })); state.asOf.value = '2026-10-01';
    await state.refreshProjects(); await state.loadMore();
    assert.equal(state.projects.value.length, 1);
    assert.equal(state.overview.value.investment.amount, '100.01');
    assert.match(state.listError.value, /读回范围或格式不一致/);
    assert.equal(state.hasMore.value, true);
});

test('scope readback remains compatible with older responses and ignores a late cutoff read', async () => {
    let resolveOld;
    const state = create(path => path.includes('2026-09-30') ? new Promise(resolve => { resolveOld = resolve; }) : Promise.resolve(ok({ ...projectDetail(5), summary: { as_of: '2026-10-01' } })));
    state.asOf.value = '2026-09-30'; const old = state.selectProject(5);
    state.asOf.value = '2026-10-01'; await state.selectProject(5);
    resolveOld(ok({ ...projectDetail(5), summary: { as_of: '2026-09-30', basis: 'investor_cash', currency: 'CNY' } })); await old;
    assert.equal(state.detail.value.summary.as_of, '2026-10-01');
    assert.equal(state.detailError.value, '');
});

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

test('dialog tab boundaries exclude inputs disabled by their enclosing fieldset', () => {
    const focused = [];
    const disabledInput = { offsetParent: {}, matches: () => true, focus: () => focused.push('disabled') };
    const summary = { offsetParent: {}, matches: () => false, focus: () => focused.push('summary') };
    const document = { activeElement: summary };
    const state = create(async () => ok({}), { document });
    let prevented = false;
    state.dialogKey({ key: 'Tab', shiftKey: false, currentTarget: { querySelectorAll: () => [disabledInput, summary] }, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(focused, ['summary']);
    const dialog = { querySelectorAll: () => [disabledInput, summary] };
    document.activeElement = dialog;
    state.dialogKey({ key: 'Tab', shiftKey: true, currentTarget: dialog, preventDefault() {} });
    assert.deepEqual(focused, ['summary', 'summary']);
    const emptyDialog = { querySelectorAll: () => [disabledInput], focus: () => focused.push('dialog') };
    state.dialogKey({ key: 'Tab', currentTarget: emptyDialog, preventDefault() {} });
    assert.deepEqual(focused, ['summary', 'summary', 'dialog']);
});

test('closing a changed entry keeps its input until the user explicitly discards it', () => {
    const state = create(async () => ok({}));
    state.detail.value = projectDetail(5);
    state.beginEntry('recovery');
    state.entryForm.value.amount = '123.45';
    state.entryForm.value.notes = '正在核对，不应被误关闭';
    const identity = state.entryForm.value.client_request_id;
    state.dialogKey({ key: 'Escape', preventDefault() {} });
    assert.equal(state.entryForm.value?.amount, '123.45');
    assert.equal(state.discardPrompt.value, true);
    state.continueForms();
    assert.equal(state.discardPrompt.value, false);
    assert.equal(state.entryForm.value.client_request_id, identity);
    state.closeForms(); state.discardForms();
    assert.equal(state.entryForm.value, null);
});

test('project edits need explicit discard, while unchanged forms close without a prompt', () => {
    const state = create(async () => ok({}));
    state.beginProject(); state.closeForms();
    assert.equal(state.projectForm.value, null);
    state.beginProject(); state.projectForm.value.project_name = '录入中的项目';
    state.closeForms();
    assert.equal(state.projectForm.value?.project_name, '录入中的项目');
    assert.equal(state.discardPrompt.value, true);
    state.saving.value = true; state.discardForms();
    assert.equal(state.projectForm.value?.project_name, '录入中的项目');
    state.saving.value = false; state.discardForms();
    assert.equal(state.projectForm.value, null);
});

const deadlineClock = () => {
    const active = new Set();
    return { window: { setTimeout: callback => { active.add(callback); return callback; }, clearTimeout: callback => active.delete(callback) }, expire: () => { assert.equal(active.size, 1, 'a pending request must have a bounded deadline'); [...active][0](); }, active };
};

test('an unresponsive refresh releases loading and ignores a response arriving after timeout', async () => {
    const clock = deadlineClock();
    let resolveLate, requestSignal;
    const state = create((path, options) => { requestSignal = options.signal; return new Promise(resolve => { resolveLate = resolve; }); }, clock);
    const pending = state.refreshProjects();
    clock.expire(); await pending;
    assert.equal(state.loading.value, false);
    assert.equal(requestSignal.aborted, true);
    assert.match(state.listError.value, /读取超时/);
    resolveLate(ok({list:[{id:99}],layout:{order:[99]}})); await Promise.resolve();
    assert.equal(state.projects.value.length, 0);
    assert.equal(clock.active.size, 0);
});

test('an unresponsive save retains inputs and request identity, then retries once with the same identity', async () => {
    const clock = deadlineClock(), writes = [];
    let resolveLate;
    const state = create((path, options) => {
        if (options.method !== 'POST') return Promise.resolve(ok({list:[]}));
        writes.push(JSON.parse(options.body));
        return writes.length === 1 ? new Promise(resolve => { resolveLate = resolve; }) : Promise.resolve(ok(entryReadback(5, writes.at(-1))));
    }, clock);
    state.detail.value = projectDetail(5); state.beginEntry('recovery'); state.entryForm.value.amount = '123.45';
    const identity = state.entryForm.value.client_request_id;
    const pending = state.saveEntry(); await state.saveEntry(); assert.equal(writes.length, 1);
    clock.expire(); await pending;
    assert.equal(state.saving.value, false); assert.equal(state.entryForm.value.amount, '123.45');
    assert.equal(state.entryForm.value.client_request_id, identity); assert.match(state.formError.value, /保存结果尚未确认/);
    await state.saveEntry();
    assert.equal(writes.length, 2); assert.equal(writes[1].client_request_id, identity);
    assert.equal(state.entryForm.value, null);
    assert.equal(state.detail.value.entries[0].client_request_id, identity);
    assert.match(state.notice.value, /已保存并读回/);
    resolveLate(ok(projectDetail(99))); await Promise.resolve();
    assert.equal(state.detail.value.project.id, 5); assert.equal(clock.active.size, 0);
});

test('a mismatched save response cannot close the entry form or install another project', async () => {
    const state = create(async (path, options) => options.method === 'POST' ? ok(projectDetail(99)) : ok({list:[]}));
    state.detail.value = projectDetail(5); state.beginEntry('recovery'); state.entryForm.value.amount = '123.45';
    await state.saveEntry();
    assert.equal(state.detail.value.project.id, 5);
    assert.equal(state.entryForm.value.amount, '123.45');
    assert.match(state.formError.value, /读回范围或格式不一致/);
    assert.equal(state.saving.value, false);
});

test('an unexpected project response cannot replace the requested ledger', async () => {
    const state = create(async () => ok(projectDetail(99)));
    await state.selectProject(5);
    assert.equal(state.detail.value, null);
    assert.match(state.detailError.value, /读回范围或格式不一致/);
    assert.equal(state.detailLoading.value, false);
});

test('all ledger writes retain the selected accounting cutoff, including retry and void', async () => {
    const writes = [];
    const state = create(async (path, options) => {
        if (options.method === 'POST') { const input = JSON.parse(options.body); writes.push(input); return ok(path.endsWith('/entries') ? entryReadback(5, input) : path === '/investment-payback/projects' ? projectReadback(5, input) : projectDetail(5, input.as_of)); }
        return ok({ list: [] });
    });
    state.asOf.value = '2026-08-31';
    state.detail.value = projectDetail(5);
    state.beginEntry('recovery'); state.entryForm.value.amount = '125.01';
    await state.saveEntry();
    state.forecastForm.amount = '1500.00'; await state.saveForecast();
    assert.equal(state.detailError.value, '');
    assert.equal(state.detail.value.project.expected_monthly_amount, '1500.00');
    state.openConfirmation('void', { id: 2, version: 1 }); state.confirmation.reason = '合成测试'; await state.confirmAction();
    state.beginProject({ id: 5, version: 1, project_name: '合成测试' }); await state.saveProject();
    assert.equal(writes.length, 4);
    for (const write of writes) assert.equal(write.as_of, '2026-08-31');
    assert.equal(state.formError.value, '');
    assert.equal(state.detail.value.summary.as_of, '2026-08-31');
});

test('monthly cash totals remain exact beyond the Number safe-integer range and reject invalid values', () => {
    const state = create(async () => ok({}));
    const detail = projectDetail(5);
    detail.entries = Array.from({ length: 101 }, (_, id) => ({ id, date: '2026-09-01', kind: 'recovery', amount: '999999999999.99' }));
    detail.entries.push({ id: 102, date: '2026-09-01', kind: 'refund', amount: '0.01' });
    state.asOf.value = '2026-10-01'; state.detail.value = detail;
    assert.equal(state.monthlyRows.value[0].recovery, '100999999999998.98');
    assert.equal(state.money('100999999999998.99'), '100,999,999,999,998.99 元');
    assert.equal(state.compactMoney('100999999999998.99'), '10,099,999,999.999899 万元');
    assert.equal(state.detailAmount('100999999999998.99').exact, '100,999,999,999,998.99 元');
    detail.entries.push({ id: 103, date: '2026-09-01', kind: 'recovery', amount: 'bad' });
    assert.equal(state.monthlyRows.value[0].recovery, null);
    assert.equal(state.monthlyRows.value[0].recovery_invalid, true);
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
            return ok(projectReadback(3, inputs.at(-1)));
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
    assert.equal(state.projectForm.value, null);
    assert.equal(state.detail.value.project.project_name, '明确标注的测试项目');
    assert.equal(state.entryForm.value, null);
});

test('new project can save current cumulative balances in one request without inventing history', async () => {
    const saved = [];
    const state = create(async (path, options) => {
        if (options.method === 'POST') { const input = JSON.parse(options.body); saved.push({ path, input }); return ok(projectReadback(3, input)); }
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
    assert.equal(state.projectForm.value, null);
    assert.equal(state.detail.value.project.opening_invested, '1200000.01');
    assert.equal(state.detail.value.project.opening_recovered, '250000.02');
});

test('name-only creation keeps missing amounts missing and rejects a half-filled opening balance', async () => {
    const saved = [];
    const state = create(async (path, options) => {
        if (options.method === 'POST') { const input = JSON.parse(options.body); saved.push(input); return ok(projectReadback(1, input)); }
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
    assert.equal(state.projectForm.value, null);
    assert.equal(state.detail.value.project.opening_invested, null);
    assert.equal(state.detail.value.project.opening_recovered, null);
});

test('collapsed project metadata and existing opening balances survive a simple edit', async () => {
    let saved;
    const state = create(async (path, options) => {
        if (options.method === 'POST') { saved = JSON.parse(options.body); return ok(projectReadback(7, saved)); }
        return ok({list: []});
    });
    const existing = { id: 7, version: 4, project_name: '编辑前', investor_name: '测试投资公司', hotel_id: 12, opening_as_of: '2026-09-01', opening_invested: '15000.10', opening_recovered: '-50.20', opening_source: '测试核对表', expected_monthly_amount: '2500.01', expected_source: '测试预测', forecast_as_of: '2026-08-31', history_complete_through: '2026-09-30', first_invested_on: '2026-01-02', notes: '保留测试备注' };
    state.beginProject(existing);
    state.projectForm.value.project_name = '编辑后';
    await state.saveProject();
    for (const field of ['investor_name', 'hotel_id', 'opening_as_of', 'opening_invested', 'opening_recovered', 'opening_source', 'expected_monthly_amount', 'expected_source', 'forecast_as_of', 'history_complete_through', 'first_invested_on', 'notes']) assert.equal(saved[field], existing[field], field);
    assert.equal(saved.expected_version, 4);
    assert.equal(state.projectForm.value, null);
    assert.equal(state.detail.value.project.project_name, '编辑后');
});

test('forecast duration displays a small positive period without claiming zero months and supports old responses', () => {
    const state = create(async () => ok({}));
    assert.equal(state.forecastRemainingText({ status: 'ready', remaining_months: 0, remaining_months_exact: 1 / 60, whole_months: 1 }), '少于0.1个月');
    assert.equal(state.forecastRemainingText({ status: 'trial', remaining_months: 0, whole_months: 1 }), '少于0.1个月');
    assert.equal(state.forecastRemainingText({ status: 'ready', remaining_months: 3.5, whole_months: 4 }), '约3.5个月');
    assert.equal(state.forecastRemainingText({ status: 'already_recovered', remaining_months: null }), '');
    assert.equal(state.forecastRemainingText({ status: 'non_positive', remaining_months: null }), '');
    assert.ok(source.includes('forecastRemainingText(summary.forecast)'));
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
        : Promise.resolve(ok(projectDetail(Number(path.match(/\/projects\/(\d+)/)[1]), '2026-10-01'))));
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
        if (options.method === 'POST') return ok(projectReadback(3, JSON.parse(options.body)));
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
        if (options.method === 'POST') { const input = JSON.parse(options.body); saved.push(input); return ok(entryReadback(1, input)); }
        return ok({ list: [] });
    });
    state.detail.value = projectDetail(1);
    state.beginEntry('refund');
    state.entryForm.value.amount = '100.01';
    state.entryForm.value.notes = '测试退款来源与原因';
    await state.saveEntry();
    assert.equal(saved[0].kind, 'refund');
    assert.equal(saved[0].amount, '100.01');
    assert.equal(state.entryForm.value, null);
    assert.equal(state.detail.value.entries[0].kind, 'refund');
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
