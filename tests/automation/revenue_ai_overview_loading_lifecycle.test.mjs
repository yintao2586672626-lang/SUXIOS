import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {computed, createSSRApp, ref} from 'vue';
import * as Vue from 'vue';
import {compile, parse} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';

const main = readFileSync('public/app-main.js', 'utf8');
const loader = main.slice(main.indexOf('            const applyRevenueAiOverviewReadback ='), main.indexOf('            const loadCompassData = async (options = {}) => {'));
const readStates = ['createRevenueLoadState', 'setRevenueLoadState'].map(name => { const a=main.indexOf('            const '+name+' ='); const b=/\r?\n            (?:const|let) /.exec(main.slice(a+1)); assert.ok(a>=0&&b,name); return main.slice(a,a+1+b.index); }).join('\n');
const policy = main.slice(main.indexOf('            const currentPageReadPolicy = ('), main.indexOf('            const cancelPageLoadRequests = ('));
const helpers = {window: {}, URLSearchParams};
for (const file of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']) {
    vm.runInNewContext(readFileSync(`public/${file}`, 'utf8'), helpers);
}
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
};
const flush = () => new Promise(resolve => setImmediate(resolve));
const fixture = (date = '2026-09-29') => ({
    hotel_id: 64, business_date: date, as_of_date: '2026-09-30',
    as_of_date_contract_version: 'revenue_overview_as_of_date.v1', data_status: 'data_missing',
});

function harness({selectedDate = '', contextDate = '2026-09-29'} = {}) {
    const overview = ref(null), selected = ref(selectedDate), businessContextDate = ref(contextDate);
    const state = {overview, selected, businessContextDate, loading: ref(false), error: ref(''), hotel: ref('64'), page: ref('compass'), session: 0};
    const gates = [], requests = [];
    const resolveDate = helpers.window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiBusinessDate;
    const context = vm.createContext({
        Map, URLSearchParams, console: {error() {}}, token: ref('synthetic-fixture'), canUseRevenueAi: () => true,
        captureAuthSession: () => state.session, isAuthSessionCurrent: session => session === state.session,
        filterReportHotel: state.hotel, currentPage: state.page, authContext: ref({tenant_id: 1}), authSessionEpoch: 0, pageRequestGeneration: 0,
        currentBusinessRequestContext: () => ({business_date: businessContextDate.value}),
        revenueAiBusinessDate: computed(() => resolveDate({overview: overview.value, selectedDate: selected.value})), coreOperationsTargetDate: ref(''),
        revenueAiOverview: overview, revenueAiOverviewLoading: state.loading, revenueAiOverviewError: state.error,
        isCompassDataPage: page => page === 'compass',
        ensureRevenueAiStaticReady: () => { const gate = deferred(); gates.push(gate); return gate.promise; },
        revenueAiResolveOverviewRequest: helpers.window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiOverviewRequest,
        revenueAiResolveOverviewResponse: helpers.window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiOverviewResponse,
        request: endpoint => { const read = deferred(); requests.push({...read, endpoint}); return read.promise; },
    });
    context.revenueLoadState=ref({}); vm.runInContext(`${readStates}; revenueLoadState.value=createRevenueLoadState(); let revenueAiOverviewRequestSeq = 0; const revenueAiOverviewRequestPromises = new Map(); ${policy} ${loader}; this.load = loadRevenueAiOverview;`, context);
    return {...state, gates, requests, load: context.load};
}

test('the actual request policy date takes precedence over the date inferred from the view', async () => {
    const h = harness({selectedDate: '2026-09-30', contextDate: '2026-09-29'});
    const pending = h.load(); h.gates[0].resolve(); await flush();
    for (const read of h.requests) read.resolve({code: 200, data: fixture()});
    await pending;
    assert.equal(h.requests.length, 1);
    assert.match(h.requests[0].endpoint, /business_date=2026-09-29/);
    assert.equal(h.overview.value.business_date, '2026-09-29');
    assert.equal(h.loading.value, false);
});

test('a validated receipt may hydrate a previously unknown date without leaving the view loading', async () => {
    const h = harness(), pending = h.load(); h.gates[0].resolve(); await flush();
    assert.equal(h.loading.value, true);
    h.requests[0].resolve({code: 200, data: fixture()}); await pending;
    assert.equal(h.overview.value.business_date, '2026-09-29');
    assert.equal(h.overview.value.data_status, 'data_missing');
    assert.equal(h.loading.value, false);
});

test('changing the requested business date discards the obsolete receipt and settles its loading state', async () => {
    const h = harness({selectedDate: '2026-09-29', contextDate: '2026-09-29'}), pending = h.load(); h.gates[0].resolve(); await flush();
    h.businessContextDate.value = '2026-10-01';
    h.requests[0].resolve({code: 200, data: fixture()}); await pending;
    assert.equal(h.overview.value, null);
    assert.equal(h.loading.value, false);
    const retry = h.load(); h.gates[1].resolve(); await flush();
    assert.match(h.requests[1].endpoint, /business_date=2026-10-01/);
    h.requests[1].resolve({code: 200, data: fixture('2026-10-01')}); await retry;
    assert.equal(h.overview.value.business_date, '2026-10-01');
    assert.equal(h.loading.value, false);
});

test('an older receipt cannot clear the loading state owned by a newer refresh', async () => {
    const h = harness(), old = h.load(); h.gates[0].resolve(); await flush();
    const newer = h.load({force: true}); h.gates[1].resolve(); await flush();
    h.requests[0].resolve({code: 200, data: fixture()}); await old;
    assert.equal(h.loading.value, true); assert.equal(h.overview.value, null);
    h.requests[1].resolve({code: 200, data: fixture()}); await newer;
    assert.equal(h.loading.value, false); assert.equal(h.overview.value.business_date, '2026-09-29');
});

test('a newer lazy-helper failure settles the loading state superseded from an older read', async () => {
    const h = harness(), old = h.load(); h.gates[0].resolve(); await flush();
    const newer = h.load({force: true}); h.gates[1].reject(new Error('synthetic lazy failure')); await newer;
    assert.equal(h.error.value, 'synthetic lazy failure'); assert.equal(h.loading.value, false);
    h.requests[0].resolve({code: 200, data: fixture()}); await old;
    assert.equal(h.overview.value, null); assert.equal(h.error.value, 'synthetic lazy failure');
});

const template = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
const findDateNotice = nodes => {
    for (const node of nodes) {
        if (node.type === 1 && node.props.some(prop => prop.type === 6 && prop.name === 'data-testid' && prop.value?.content === 'revenue-cockpit-date-difference')) return node;
        const nested = node.children && findDateNotice(node.children);
        if (nested) return nested;
    }
    return null;
};
const noticeNode = findDateNotice(parse(template).children);
assert.ok(noticeNode);
const noticeRender = new Function('Vue', compile(noticeNode.loc.source, {mode: 'function', prefixIdentifiers: true}).code)(Vue);
const renderNotice = (loading, model = {}) => renderToString(createSSRApp({
    data: () => ({revenueCockpitLoading: loading, revenueCockpitModel: model}), render: noticeRender,
}));

test('a settled blocked view cannot claim that its unavailable business date is still being read', async () => {
    const html = await renderNotice(false, {status: 'blocked', dateNotice: ''});
    assert.doesNotMatch(html, /正在确定最近严格可用日期/);
    assert.match(html, /当前范围尚无可验证业务日期/);
    assert.match(html, /未取得.*合同未取得/);
});
test('the date notice describes active reading only while its request is pending', async () => {
    assert.match(await renderNotice(true), /正在确定最近严格可用日期/);
});
test('an explicit business-date notice and as-of identity retain their original display', async () => {
    const html = await renderNotice(false, {dateNotice: '当前业务日 2026-09-29', asOfDate: '2026-09-30', asOfDateContractVersion: 'revenue_overview_as_of_date.v1'});
    assert.match(html, /当前业务日 2026-09-29/); assert.match(html, /2026-09-30/); assert.match(html, /revenue_overview_as_of_date.v1/);
});
