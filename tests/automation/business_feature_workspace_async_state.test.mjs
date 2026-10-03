import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { chromium } from 'playwright';
import { parseExpressionAt } from 'acorn';

function workspace(request) {
    const window = {};
    vm.runInNewContext(readFileSync(new URL('../../public/components/system/business-feature-workspace.js', import.meta.url), 'utf8'), { window, Intl, Date, URLSearchParams, crypto: globalThis.crypto });
    const component = window.SUXI_SYSTEM_COMPONENTS.BusinessFeatureWorkspace;
    const state = { hotelId: 80, initialSection: 'configuration', request, $emit() {} };
    Object.assign(state, component.data.call(state));
    Object.defineProperty(state, 'kind', { get: () => component.computed.kind.call(state) });
    for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state);
    state.hotelChanged = component.watch.hotelId.handler.bind(state);
    state.sectionChanged = component.watch.active.bind(state);
    return state;
}
const plain = value => JSON.parse(JSON.stringify(value));
const weekly = (end, id) => ({ code: 200, data: { contract_version: 'weekly_operating_plan.v2', hotel_id: 80, week_end: end, readback_verified: true, snapshot_id: id } });
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

test('weekly references are replaced safely and a failed next lookup clears only the automatic reference', async () => {
    let fail = false;
    const state = workspace(async () => {
        if (fail) throw new Error('合成来源不可访问');
        return weekly(state.review.period_end, 987);
    });
    state.active = 'weekly_review';
    state.review.source_references = ['manual-source#8', 'weekly_operating_plan#786'];
    await state.readWeeklySource();
    assert.deepEqual(plain(state.review.source_references), ['manual-source#8', 'weekly_operating_plan#987']);
    fail = true;
    await state.readWeeklySource();
    assert.equal(state.weeklySource, null);
    assert.deepEqual(plain(state.review.source_references), ['manual-source#8']);
    assert.match(state.error, /不可访问/);
});

test('a late weekly response cannot bind after its review dates change', async () => {
    const response = deferred();
    const state = workspace(() => response.promise);
    state.active = 'weekly_review';
    const end = state.review.period_end;
    const pending = state.readWeeklySource();
    state.review.period_start = '2020-01-01';
    response.resolve(weekly(end, 987));
    await pending;
    assert.equal(state.weeklySource, null);
    assert.deepEqual(plain(state.review.source_references), []);
});

test('an earlier weekly lookup cannot replace a later response for the same period', async () => {
    const first = deferred(), second = deferred();
    let calls = 0;
    const state = workspace(() => (++calls === 1 ? first.promise : second.promise));
    const oldRequest = state.readWeeklySource(), newRequest = state.readWeeklySource();
    second.resolve(weekly(state.review.period_end, 989));
    await newRequest;
    first.resolve(weekly(state.review.period_end, 987));
    await oldRequest;
    assert.deepEqual(plain(state.review.source_references), ['weekly_operating_plan#989']);
});

test('mapping preview discards a response for rows that were edited while waiting', async () => {
    const response = deferred();
    const state = workspace(() => response.promise);
    state.active = 'source_mapping';
    state.displayedRecord = { snapshot_id: 5 };
    state.sourceRows = '[{"amount":100}]';
    const pending = state.previewMapping();
    state.sourceRows = '[{"amount":200}]';
    response.resolve({ code: 200, data: { contract_version: 'business_source_mapping_preview.v1', scope: { hotel_id: 80 }, mapping_snapshot_id: 5, ota_fact_created: false, rows: [{ amount: 100 }] } });
    await pending;
    assert.equal(state.preview, null);
});

test('mapping preview validates the immutable version returned by the server', async () => {
    const state = workspace(async () => ({ code: 200, data: { contract_version: 'business_source_mapping_preview.v1', scope: { hotel_id: 80 }, mapping_snapshot_id: 6, ota_fact_created: false } }));
    state.active = 'source_mapping';
    state.displayedRecord = { snapshot_id: 5 };
    await state.previewMapping();
    assert.equal(state.preview, null);
    assert.match(state.error, /映射预览范围不一致/);
});

test('restoring a saved historical review clears auxiliary evidence from the previous version', async () => {
    const state = workspace(async () => ({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'source_mapping' }, snapshot_id: 5, readback_verified: true, inputs: { field_mapping: {}, source_references: ['manual-source#5'] } } }));
    state.active = 'source_mapping';
    state.preview = { mapping_snapshot_id: 6 };
    state.weeklySource = { snapshot_id: 987 };
    await state.restore(5);
    assert.equal(state.preview, null);
    assert.equal(state.weeklySource, null);
    assert.deepEqual(plain(state.review.source_references), ['manual-source#5']);
});

test('switching hotels clears mapping and source drafts even when the new hotel cannot be loaded', async () => {
    const state = workspace(async () => { throw new Error('new hotel unavailable'); });
    state.active = 'source_mapping';
    state.mappingText = '{"hotel_id":"old-hotel-column"}';
    state.sourceRows = '[{"hotel_id":80,"guest":"synthetic-old-guest"}]';
    state.pendingSave = { key: 'old-request', signature: 'old-scope' };
    state.hotelId = 81;
    state.hotelChanged();
    assert.equal(state.mappingText, '{}');
    assert.equal(state.sourceRows, '[]');
    assert.equal(state.pendingSave, null);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.overview, null);
    assert.match(state.error, /new hotel unavailable/);
});

test('opening a mapping section with no saved version cannot reuse another section draft', async () => {
    const state = workspace(async () => ({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'source_mapping' }, catalog: [], latest: null, history: [] } }));
    state.mappingText = '{"hotel_id":"old-column"}';
    state.sourceRows = '[{"hotel_id":80}]';
    state.active = 'source_mapping';
    state.sectionChanged();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.mappingText, '{}');
    assert.equal(state.sourceRows, '[]');
    assert.deepEqual(plain(state.review.field_mapping), {});
    assert.equal(state.displayedRecord, null);
});

test('clearing the hotel cancels an in-flight read and removes all saved scope state', async () => {
    const response = deferred();
    const state = workspace(() => response.promise);
    const pending = state.load();
    state.overview = { history: [{ snapshot_id: 5 }] };
    state.displayedRecord = { snapshot_id: 5, scope: { hotel_id: 80 } };
    state.latestId = 5;
    state.notice = 'hotel 80 saved';
    state.hotelId = 0;
    state.hotelChanged();
    assert.equal(state.busy, false);
    assert.equal(state.overview, null);
    assert.equal(state.displayedRecord, null);
    assert.equal(state.latestId, 0);
    assert.equal(state.notice, '');
    response.resolve({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'configuration' }, latest: { snapshot_id: 5 } } });
    await pending;
    assert.equal(state.busy, false);
    assert.equal(state.displayedRecord, null);
    assert.match(state.error, /有效酒店/);
});

test('saved configuration and review receipts do not overwrite newer reactive drafts', async () => {
    for (const kind of ['configuration', 'weekly_review', 'source_mapping']) {
        const response = deferred(); let submitted;
        const state = workspace(async (_url, options) => { submitted = JSON.parse(options.body); return response.promise; });
        state.canExecute = true; state.active = kind; state.overview = { history: [] };
        state.configuration = { booking_horizon_days: 7 }; state.review = { title: 'synthetic submitted', field_mapping: {} }; state.mappingText = '{}';
        const pending = state.save();
        kind === 'configuration' ? state.configuration.booking_horizon_days = 14 : state.review.title = 'synthetic later draft';
        if (kind === 'source_mapping') state.mappingText = '{"hotel_id":"synthetic later column"}';
        response.resolve({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind }, snapshot_id: 1, content_digest: 'a'.repeat(64), readback_verified: true, inputs: submitted.inputs } });
        await pending;
        assert.equal(state.latestId, 1); assert.equal(state.displayedRecord.snapshot_id, 1); assert.equal(state.overview.history.length, 1);
        if (kind === 'configuration') { assert.equal(state.configuration.booking_horizon_days, 14); assert.equal(state.applied.booking_horizon_days, 7); assert.equal(state.displayedRecord.inputs.booking_horizon_days, 7); }
        else { assert.equal(state.review.title, 'synthetic later draft'); assert.equal(state.displayedRecord.inputs.title, 'synthetic submitted'); }
        if (kind === 'source_mapping') assert.equal(state.mappingText, '{"hotel_id":"synthetic later column"}');
    }
});

test('all native directory destinations retain menu, finance and OTA-specific navigation dispatch', () => {
    const catalogSource = readFileSync('app/service/BusinessFeatureCatalog.php', 'utf8');
    const rows = [...catalogSource.matchAll(/\[(\d+),'([^']*)','([^']*)','([^']*)',(\d)\]/g)].map(row => ({ module_id: Number(row[1]), target: row[3], tab: row[4], enabled: true }));
    assert.equal(rows.length, 31);
    const handler = /@business-navigate="([^"]+)"/.exec(readFileSync('resources/frontend/templates/fragments/19c-page-operating-finance.html', 'utf8'))[1].replaceAll('&amp;', '&');
    const dispatch = new Function('$event', `with(this){${handler}}`);
    const native = rows.filter(row => !['guests', 'campaigns', 'review', 'source'].includes(row.target));
    assert.equal(native.length, 16);
    for (const row of native) {
        const events = [], calls = [], state = workspace(async () => {});
        state.applied = { preferred_platform: 'ctrip' }; state.$emit = (name, event) => events.push([name, plain(event)]);
        state.execute(row);
        if (row.target === 'finance') { assert.equal(events[0][0], 'finance-tab'); assert.equal(events[0][1].tab, row.tab); continue; }
        const parent = { currentPage: 'operating-finance', handleMenuClick: item => calls.push(['menu', item]), openCtripManualTab: tab => calls.push(['ctrip', tab]), openMeituanManualTab: tab => calls.push(['meituan', tab]), openMeituanStoredDataTab: tab => calls.push(['meituan_stored', tab]) };
        dispatch.call(parent, events[0][1]);
        row.target === 'ctrip-ebooking' ? assert.deepEqual(calls, [['ctrip', row.tab]]) : assert.deepEqual(calls, [['menu', { path: row.target, tab: row.tab }]]);
    }
    for (const [moduleId, expected] of [[26, ['meituan_stored', 'ads']], [17, ['meituan', 'meituan-review-match']]]) {
        const events = [], calls = [], state = workspace(async () => {});
        state.applied = { preferred_platform: 'meituan' }; state.$emit = (_name, event) => events.push(plain(event)); state.execute(rows.find(row => row.module_id === moduleId));
        const parent = { currentPage: '', openMeituanStoredDataTab: tab => calls.push(['meituan_stored', tab]), openMeituanManualTab: tab => calls.push(['meituan', tab]) };
        dispatch.call(parent, events[0]); assert.deepEqual(calls, [expected]); assert.equal(parent.currentPage, 'meituan-ebooking');
    }
});

test('mounted directory uses real menu alias navigation and keeps reactive draft separate from the saved configuration', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.route('http://127.0.0.1:43139/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><div id="app"></div>' }));
        await page.goto('http://127.0.0.1:43139/');
        await page.addScriptTag({ path: 'public/vue.runtime.global.prod.js' }); await page.addScriptTag({ path: 'public/components/system/business-feature-workspace.js' });
        const fragment = readFileSync('resources/frontend/templates/fragments/19c-page-operating-finance.html', 'utf8');
        const handler = /@business-navigate="([^"]+)"/.exec(fragment)[1].replaceAll('&amp;', '&');
        const main = readFileSync('public/app-main.js', 'utf8'), offset = main.indexOf('(item) =>', main.indexOf('const handleMenuClick ='));
        const menu = parseExpressionAt(main, offset, { ecmaVersion: 'latest' });
        await page.evaluate(({ handler, menu }) => {
            const config = { booking_horizon_days: 7, modules: [{ module_id: 18, enabled: true, phase: 1, rank: 1 }] };
            const eventHandler = new Function('$event', `with(this){${handler}}`);
            window.workspaceFixture = Vue.createApp({
                data: () => ({ currentPage: 'operating-finance', agentTab: '', revenueAgentTab: '', submitted: null, receiptPending: null }),
                methods: {
                    handleMenuClick(item) {
                        const ref = name => ({ get value() { return window.workspaceFixture[name]; }, set value(value) { window.workspaceFixture[name] = value; } });
                        document.documentElement.dataset.suxiRenderPhase = 'full';
                        const navigate = new Function('currentPage', 'agentTab', 'revenueAgentTab', 'guardSuperAdminPageAccess', 'nextTick', 'loadRevenueCockpit', `return (${menu})`)(ref('currentPage'), ref('agentTab'), ref('revenueAgentTab'), () => true, Vue.nextTick, () => {});
                        navigate(item);
                    },
                },
                render() { return this.currentPage === 'operating-finance' ? Vue.h(window.SUXI_SYSTEM_COMPONENTS.BusinessFeatureWorkspace, {
                    ref: 'workspace', hotelId: 80, canExecute: true,
                    request: async (_url, options) => {
                        if (options.method === 'POST') { this.submitted = JSON.parse(options.body); return new Promise(resolve => { this.receiptPending = resolve; }); }
                        return { code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'configuration' }, catalog: [{ module_id: 18, name: '收益驾驶舱', target: 'trusted-revenue-analysis', phase: 1, rank: 1 }], latest: { snapshot_id: 1, readback_verified: true, inputs: config }, history: [] } };
                    }, onNavigate: event => eventHandler.call(this, event),
                }) : Vue.h('p', { 'data-testid': 'current-page' }, this.currentPage); },
            }).mount('#app');
        }, { handler, menu: main.slice(menu.start, menu.end) });
        await page.getByTestId('business-configuration-rows').waitFor();
        await page.getByRole('button', { name: '保存设置并应用', exact: true }).click();
        await page.waitForFunction(() => !!window.workspaceFixture.receiptPending);
        // The current UI hides inputs while saving. This checks later reactive-state changes, without claiming an ordinary visible input path.
        await page.evaluate(() => {
            const panel = window.workspaceFixture.$refs.workspace; panel.configuration.booking_horizon_days = 14;
            window.workspaceFixture.receiptPending({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'configuration' }, snapshot_id: 2, readback_verified: true, content_digest: 'a'.repeat(64), inputs: window.workspaceFixture.submitted.inputs } });
        });
        await page.waitForFunction(() => !window.workspaceFixture.$refs.workspace.busy);
        assert.equal(await page.getByLabel('预订未来天数', { exact: true }).inputValue(), '14');
        assert.deepEqual(await page.evaluate(() => ({ saved: window.workspaceFixture.$refs.workspace.applied.booking_horizon_days, receipt: window.workspaceFixture.$refs.workspace.displayedRecord.inputs.booking_horizon_days })), { saved: 7, receipt: 7 });
        await page.getByRole('button', { name: '按批次执行', exact: true }).click();
        await page.locator('[data-execute-module="18"]').click();
        await page.waitForFunction(() => window.workspaceFixture.currentPage !== 'operating-finance');
        assert.deepEqual(await page.evaluate(() => ({ page: window.workspaceFixture.currentPage, agentTab: window.workspaceFixture.agentTab, revenueTab: window.workspaceFixture.revenueAgentTab })), { page: 'agent-center', agentTab: 'revenue', revenueTab: 'analysis' });
    } finally { await browser.close(); }
});
