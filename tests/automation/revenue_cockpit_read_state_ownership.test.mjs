import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {ref} from 'vue';

const source = readFileSync('public/app-main.js', 'utf8');
const bundleLoader = source.slice(source.indexOf('const loadRevenueAnalysisBundle ='), source.indexOf('const loadRevenueCockpit ='));
const roomLoader = source.slice(source.indexOf('const loadRoomTypes ='), source.indexOf('const saveRoomTypeConfig ='));
const stateFactory = source.slice(source.indexOf('const createRevenueLoadState ='), source.indexOf('const revenueLoadState ='));
const suggestionValidator = source.slice(source.indexOf('const resolvePriceSuggestionListPayload ='), source.indexOf('const loadPriceSuggestions ='));
const forecastValidator = source.slice(source.indexOf('const resolveDemandForecastListPayload ='), source.indexOf('const loadDemandForecasts ='));
const forecastLoader = source.slice(source.indexOf('const loadDemandForecasts ='), source.indexOf('const loadCompetitorAnalysis ='));
const helpers = {window: {}, URLSearchParams};
for (const file of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']) {
    vm.runInNewContext(readFileSync(`public/${file}`, 'utf8'), helpers);
}
const api = helpers.window.SUXI_REVENUE_AI_STATIC;
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
};
const flush = () => new Promise(resolve => setImmediate(resolve));
const overviewFixture = {
    hotel_id: 64, business_date: '2026-09-29', as_of_date: '2026-09-30',
    as_of_date_contract_version: 'revenue_overview_as_of_date.v1', data_status: 'data_missing',
};
const suggestionFixture = (overrides = {}) => ({list: [], pagination: {total: 0, page: 1, page_size: 20, total_page: 0}, ...overrides});
const bundleFixture = (overrides = {}) => ({overview: overviewFixture, room_types: {list: []}, forecasts: {forecasts: []}, price_suggestions: suggestionFixture(), ...overrides});

function harness(cockpit) {
    const read = deferred(), roomRead = deferred(), forecastRead = deferred(), hotel = ref('64'), states = ref({});
    let activeRead = read;
    const context = {
        URLSearchParams, Object, String, Number,
        token: ref('synthetic-fixture'),
        priceSuggestionFilter: ref({date: '2026-09-29', end_date: '2026-09-29', status: 0}),
        priceSuggestionPagination: ref({page: 1, page_size: 20}),
        forecastFilter: ref({start_date: '2026-09-29', end_date: '2026-09-29'}),
        competitorFilter: ref({date: '2026-09-29'}),
        revenueAiBusinessDate: ref('2026-09-29'),
        revenueLoadState: states,
        filterReportHotel: hotel, roomTypeConfigForm: ref({}),
        revenueAnalysisBundleRequestSeq: 0, priceSuggestionRequestSeq: 0, revenueAiOverviewRequestSeq: 0,
        revenueAiOverviewRequestPromises: new Map(),
        createEmptyRevenueAnalysisData: () => ({}), createEmptyRevenueDashboard: () => ({}),
        createPriceSuggestionPagination: () => ({page: 1, page_size: 20}),
        createRoomTypeConfigForm: () => ({}), roomTypeConfigSaveReadback: ref(null),
        captureAgentRevenueRequestContext: extra => ({hotelId: hotel.value, ...extra}),
        isAgentRevenueRequestCurrent: captured => captured.hotelId === hotel.value,
        ensureRevenueAiStaticReady: async () => api,
        firstEnabledRoomTypeId: () => 0, formatDate: () => '2026-09-29',
        showToast() {}, resetCompetitorAnalysisView() {},
        revenueAiResolveOverviewResponse: api.resolveRevenueAiOverviewResponse,
        request: endpoint => {
            if (endpoint.startsWith('/agent/demand-forecasts?')) {
                assert.match(endpoint, /hotel_id=64/); return forecastRead.promise;
            }
            if (endpoint.startsWith('/agent/room-types?')) {
                assert.match(endpoint, /hotel_id=64/); return roomRead.promise;
            }
            assert.match(endpoint, /^\/agent\/revenue-bundle\?/);
            assert.match(endpoint, /hotel_id=64/);
            return activeRead.promise;
        },
        loadCompetitorAnalysis: async ({priceResponsePromise}) => {
            await priceResponsePromise;
            context.setRevenueLoadState('competitor', 'empty');
        },
    };
    for (const name of ['revenueAnalysisData', 'revenueDashboard', 'demandForecasts', 'forecastAccuracy', 'highDemandDates',
        'priceSuggestions', 'roomTypeConfigList', 'roomTypeConfigMeta', 'revenueAiOverview', 'revenueAiOverviewError',
        'revenueAiOverviewLoading', 'competitorAnalysisError', 'priceSuggestionReview', 'demandForecastForm', 'competitorPriceForm']) {
        context[name] = ref(name.endsWith('Form') ? {room_type_id: 0} : null);
    }
    context.setRevenueLoadState = (key, status, error = '') => {
        states.value = {...states.value, [key]: {status, error: String(error || '')}};
    };
    vm.createContext(context);
    for (const name of ['applyRoomTypeReadback', 'applyRevenueAiOverviewReadback', 'applyRevenueAnalysisReadback', 'applyRevenueDashboardReadback']) {
        const start = source.indexOf('            const ' + name + ' =');
        const end = /\r?\n            (?:const|let) /.exec(source.slice(start + 1));
        assert.ok(start >= 0 && end, name);
        vm.runInContext(source.slice(start, start + 1 + end.index), context);
    }
    vm.runInContext(`let roomTypesRequestSequence=0; let demandForecastsRequestSequence=0; ${stateFactory}; ${suggestionValidator}; ${forecastValidator}; ${forecastLoader}; ${roomLoader}; ${bundleLoader}; this.load = loadRevenueAnalysisBundle; this.readRooms=loadRoomTypes; this.readForecasts=loadDemandForecasts; this.createStates = createRevenueLoadState;`, context);
    states.value = {...context.createStates(), cockpit: {...cockpit}};
    return {states, hotel, read, roomRead, forecastRead, readForecasts: context.readForecasts, forecasts: context.demandForecasts, readRooms: context.readRooms, roomTypes: context.roomTypeConfigList, roomMeta: context.roomTypeConfigMeta,
        suggestions: context.priceSuggestions, pagination: context.priceSuggestionPagination, overviewLoading: context.revenueAiOverviewLoading,
        nextRead: () => (activeRead = deferred()), load: context.load};
}

for (const oldResult of ['success', 'failure']) {
    test(`an earlier aggregate ${oldResult} cannot replace a newer demand forecast read`, async () => {
        const h = harness({status: 'empty', error: ''}), bundle = h.load({silent: true}); await flush();
        const current = h.readForecasts({silent: true});
        const row = {id: 501, hotel_id: 64, forecast_date: '2026-09-29', predicted_occupancy: 0};
        h.forecastRead.resolve({code: 200, data: {forecasts: [row]}}); await current;
        if (oldResult === 'success') h.read.resolve({code: 200, data: bundleFixture({forecasts: {forecasts: [{...row, predicted_occupancy: 75}]}})});
        else h.read.resolve({code: 503, message: '隔离旧聚合失败'});
        await bundle;
        assert.equal(h.forecasts.value[0]?.predicted_occupancy, 0);
        assert.equal(h.states.value.forecasts.status, 'ready');
    });
}

test('a malformed aggregate forecast list fails that section while preserving valid independent sections', async () => {
    const h = harness({status: 'empty', error: ''}), pending = h.load({silent: true}); await flush();
    h.read.resolve({code: 200, data: bundleFixture({forecasts: {accuracy: {total: 1}}})}); await pending;
    assert.equal(h.states.value.forecasts.status, 'failed');
    assert.match(h.states.value.forecasts.error, /尚未核验/);
    assert.equal(h.forecasts.value.length, 0);
    assert.equal(h.states.value.priceSuggestions.status, 'empty');
    assert.equal(h.states.value.overview.status, 'ready');
    assert.equal(h.states.value.cockpit.status, 'empty');
    assert.equal(h.states.value.bundle.status, 'failed');
});

test('a pending and successful analysis bundle preserves the cockpit verified empty result', async () => {
    const h = harness({status: 'empty', error: ''});
    const pending = h.load({silent: true});
    assert.equal(h.states.value.cockpit.status, 'empty');
    const model = api.buildRevenueCockpitModel({overview: null, loadStatus: h.states.value.cockpit.status});
    assert.equal(model.status, 'empty');
    assert.doesNotMatch(model.summary, /合同/);
    await flush(); h.read.resolve({code: 200, data: bundleFixture()}); await pending;
    assert.equal(h.states.value.cockpit.status, 'empty');
    assert.equal(h.states.value.bundle.status, 'ready');
});

test('an independent cockpit failure survives a successful bundle without failing that bundle', async () => {
    const h = harness({status: 'failed', error: '当前酒店合同校验失败'});
    const pending = h.load({silent: true}); await flush();
    h.read.resolve({code: 200, data: bundleFixture()}); await pending;
    assert.equal(h.states.value.cockpit.status, 'failed');
    assert.equal(h.states.value.cockpit.error, '当前酒店合同校验失败');
    assert.equal(h.states.value.bundle.status, 'ready');
});

test('a ready cockpit cannot mask a malformed bundle response as available data', async () => {
    const h = harness({status: 'ready', error: ''});
    const pending = h.load({silent: true}); await flush();
    h.read.resolve({code: 200, data: {overview: null}}); await pending;
    assert.equal(h.states.value.cockpit.status, 'ready');
    assert.equal(h.states.value.bundle.status, 'failed');
});

test('a failed bundle leaves the cockpit failure evidence intact', async () => {
    const h = harness({status: 'failed', error: '当前酒店合同校验失败'});
    const pending = h.load({silent: true}); await flush();
    h.read.resolve({code: 503, message: '隔离 fixture 读取失败'}); await pending;
    assert.equal(h.states.value.cockpit.error, '当前酒店合同校验失败');
    assert.equal(h.states.value.bundle.status, 'failed');
});

test('a failed aggregate read settles the pending saved suggestion list instead of leaving it loading', async () => {
    const h = harness({status: 'empty', error: ''});
    const pending = h.load({silent: true}); await flush();
    assert.equal(h.states.value.priceSuggestions.status, 'loading');
    h.read.resolve({code: 503, message: '隔离 fixture 聚合读取失败'}); await pending;
    for (const key of ['priceSuggestions', 'roomTypes', 'dashboard', 'forecasts', 'overview']) {
        assert.equal(h.states.value[key].status, 'failed', `${key} must finish its pending read`);
        assert.equal(h.states.value[key].error, '隔离 fixture 聚合读取失败');
    }
    assert.equal(h.states.value.cockpit.status, 'empty');
});

test('bundle failure preserves a settled independent competitor result and its actual error', async () => {
    const h = harness({status: 'empty', error: ''});
    const pending = h.load({silent: true}); await flush();
    h.states.value = {...h.states.value, competitor: {status: 'failed', error: '竞品读取的独立错误'}};
    h.read.resolve({code: 503, message: '隔离 fixture 聚合读取失败'}); await pending;
    assert.equal(h.states.value.competitor.error, '竞品读取的独立错误');
    assert.equal(h.states.value.priceSuggestions.status, 'failed');
});

test('retrying the same failed scope transitions its saved suggestion list to a verified empty result', async () => {
    const h = harness({status: 'empty', error: ''});
    const failed = h.load({silent: true}); await flush();
    h.read.resolve({code: 503, message: '隔离 fixture 聚合读取失败'}); await failed;
    assert.equal(h.states.value.priceSuggestions.status, 'failed');
    const retryRead = h.nextRead(), retry = h.load({silent: true}); await flush();
    assert.equal(h.states.value.priceSuggestions.status, 'loading');
    retryRead.resolve({code: 200, data: bundleFixture()});
    await retry;
    assert.equal(h.states.value.priceSuggestions.status, 'empty');
    assert.equal(h.states.value.priceSuggestions.error, '');
    assert.equal(h.states.value.bundle.status, 'ready');
    assert.equal(h.states.value.cockpit.status, 'empty');
});

test('an obsolete bundle response cannot overwrite a new hotel cockpit result', async () => {
    const h = harness({status: 'empty', error: ''});
    const pending = h.load({silent: true}); await flush(); h.hotel.value = '7';
    h.states.value = {...h.states.value, cockpit: {status: 'failed', error: '酒店7的当前读取失败'}};
    h.read.resolve({code: 200, data: {overview: overviewFixture}}); await pending;
    assert.equal(h.states.value.cockpit.error, '酒店7的当前读取失败');
});

test('a missing aggregate room list fails only that section without discarding valid suggestion results', async () => {
    const h = harness({status: 'empty', error: ''}), pending = h.load({silent: true}); await flush();
    h.read.resolve({code: 200, data: bundleFixture({room_types: {input_scope: 'manual_pricing_configuration'}})}); await pending;
    assert.equal(h.states.value.roomTypes.status, 'failed');
    assert.match(h.states.value.roomTypes.error, /房型配置列表.*未核验/);
    assert.equal(h.roomTypes.value.length, 0); assert.equal(Object.keys(h.roomMeta.value).length, 0);
    assert.equal(h.states.value.overview.status, 'ready'); assert.equal(h.states.value.priceSuggestions.status, 'empty');
    assert.equal(h.states.value.bundle.status, 'failed'); assert.equal(h.states.value.cockpit.status, 'empty');
});

test('a valid aggregate room list retains exact manual data and source metadata', async () => {
    const h = harness({status: 'empty', error: ''}), pending = h.load({silent: true}); await flush();
    const row = {id: 501, hotel_id: 64, name: '隔离验收房型', min_price: '218.20'};
    h.read.resolve({code: 200, data: bundleFixture({room_types: {list: [row],
        input_scope: 'manual_pricing_configuration', evidence_status: 'operator_provided', auto_write_ota: false}})}); await pending;
    assert.deepEqual(h.roomTypes.value[0], row); assert.equal(h.roomMeta.value.evidence_status, 'operator_provided');
    assert.equal(h.states.value.roomTypes.status, 'ready'); assert.equal(h.states.value.bundle.status, 'ready');
});

for (const oldResult of ['success', 'failure']) {
    test(`an earlier aggregate ${oldResult} cannot replace a newer room configuration readback`, async () => {
        const h = harness({status: 'empty', error: ''}), bundle = h.load({silent: true}); await flush();
        const current = h.readRooms({silent: true}), row = {id: 501, hotel_id: 64, min_price: '218.20'};
        h.roomRead.resolve({code: 200, data: {list: [row], evidence_status: 'operator_provided'}}); await current;
        if (oldResult === 'success') h.read.resolve({code: 200, data: bundleFixture({room_types: {list: [{...row, min_price: '219.20'}]}})});
        else h.read.resolve({code: 503, message: '隔离旧聚合失败'});
        await bundle;
        assert.equal(h.roomTypes.value[0]?.min_price, '218.20'); assert.equal(h.states.value.roomTypes.status, 'ready');
        assert.equal(h.roomMeta.value.evidence_status, 'operator_provided');
    });
}

for (const [name, payload] of [
    ['missing payload', undefined], ['missing list', suggestionFixture({list: undefined})],
    ['non-array list', suggestionFixture({list: {}})], ['missing pagination', suggestionFixture({pagination: undefined})],
    ['foreign page', suggestionFixture({pagination: {total: 0, page: 2, page_size: 20, total_page: 0}})],
    ['foreign hotel', suggestionFixture({query_scope: {hotel_id: 7, platform: 'ctrip', start_date: '2026-09-29', end_date: '2026-09-29'}})],
    ['foreign stay date', suggestionFixture({query_scope: {hotel_id: 64, platform: 'ctrip', start_date: '2026-10-01', end_date: '2026-10-01'}})],
    ['foreign platform', suggestionFixture({query_scope: {hotel_id: 64, platform: 'meituan', start_date: '2026-09-29', end_date: '2026-09-29'}})],
    ['foreign end date', suggestionFixture({query_scope: {hotel_id: 64, platform: 'ctrip', start_date: '2026-09-29', end_date: '2026-10-01'}})],
]) {
    test(`aggregate ${name} cannot become a verified empty saved suggestion list`, async () => {
        const h = harness({status: 'empty', error: ''}), pending = h.load({silent: true}); await flush();
        h.read.resolve({code: 200, data: bundleFixture({price_suggestions: payload})}); await pending;
        assert.equal(h.states.value.priceSuggestions.status, 'failed'); assert.equal(h.suggestions.value.length, 0);
        assert.match(h.states.value.priceSuggestions.error, /尚未核验|范围不一致/);
        assert.equal(h.states.value.roomTypes.status, 'empty'); assert.equal(h.states.value.overview.status, 'ready');
        assert.equal(h.states.value.bundle.status, 'failed'); assert.equal(h.states.value.cockpit.status, 'empty');
    });
}

test('aggregate legacy numeric pagination and exact saved values use the same contract as direct reads', async () => {
    const h = harness({status: 'empty', error: ''}), pending = h.load({silent: true}); await flush();
    const row = {id: 401, hotel_id: 64, suggestion_date: '2026-09-29', suggested_price: '328.50', persistence: {readback_verified: true}};
    h.read.resolve({code: 200, data: bundleFixture({price_suggestions: suggestionFixture({list: [row],
        pagination: {total: '60', page: '1', page_size: '20', total_page: '3'}})})}); await pending;
    assert.deepEqual(h.suggestions.value[0], row); assert.equal(h.states.value.priceSuggestions.status, 'ready');
    assert.equal(h.pagination.value.total, 60); assert.equal(h.pagination.value.page, 1);
    assert.equal(h.pagination.value.page_size, 20); assert.equal(h.pagination.value.total_page, 3);
    assert.equal(h.states.value.bundle.status, 'ready');
});

test('a malformed third-page aggregate receipt preserves its requested page and finishes loading', async () => {
    const h = harness({status: 'empty', error: ''}); h.pagination.value.page = 3;
    const pending = h.load({silent: true}); await flush();
    h.read.resolve({code: 200, data: bundleFixture({price_suggestions: undefined})}); await pending;
    assert.equal(h.states.value.priceSuggestions.status, 'failed'); assert.equal(h.pagination.value.page, 3);
    assert.equal(h.overviewLoading.value, false); assert.equal(h.states.value.bundle.status, 'failed');
});

test('a verified same-scope aggregate empty receipt remains empty without fabricating saved values', async () => {
    const h = harness({status: 'empty', error: ''}), pending = h.load({silent: true}); await flush();
    h.read.resolve({code: 200, data: bundleFixture({price_suggestions: suggestionFixture({query_scope: {
        hotel_id: 64, platform: 'ctrip', start_date: '2026-09-29', end_date: '2026-09-29', metric_scope: 'ota_channel_price_recommendation',
    }})})}); await pending;
    assert.equal(h.states.value.priceSuggestions.status, 'empty'); assert.equal(h.pagination.value.total, 0);
    assert.equal(h.states.value.priceSuggestions.error, ''); assert.equal(h.overviewLoading.value, false);
});

test('an explicitly unread cockpit offers refresh without claiming a missing response contract', () => {
    const model = api.buildRevenueCockpitModel({overview: null, loadStatus: 'not_loaded'});
    assert.equal(model.status, 'not_loaded');
    assert.match(model.summary, /尚未读取/);
    assert.match(model.dateNotice, /尚未读取/);
    assert.doesNotMatch(model.summary, /合同|暂无.*事实/);
    assert.equal(model.canSaveSnapshot, false);
    assert.equal(model.canCreatePendingApproval, false);
    assert.equal(model.canAskQuestion, false);
    assert.equal(model.visibleSections.length, 0);
});

test('a failed or malformed cockpit stays blocked even if an unread state is supplied', () => {
    const failed = api.buildRevenueCockpitModel({overview: null, loadStatus: 'not_loaded', error: '范围读取失败'});
    assert.equal(failed.status, 'blocked'); assert.equal(failed.summary, '范围读取失败');
    const malformed = api.buildRevenueCockpitModel({overview: {}, loadStatus: 'not_loaded'});
    assert.equal(malformed.status, 'blocked'); assert.match(malformed.summary, /合同/);
});

function hotelSwitchHarness() {
    const hotel = ref(''), ticks = [], reads = [];
    const defaults = source.slice(source.indexOf('let suppressNextReportHotelDashboardRefresh ='), source.indexOf('watch(compassHotelOptions'));
    const watcher = source.slice(source.indexOf('watch(filterReportHotel, (newHotelId, previousHotelId) => {'), source.indexOf('watch(weatherLocationName'));
    const context = {
        String, Array, Object, filterReportHotel: hotel, user: ref({id: 1, hotel_id: 64}), token: ref('synthetic-fixture'),
        hotelListSnapshotReady: ref(true), compassHotelOptions: ref([{id: 7}, {id: 64}]),
        currentPage: ref('agent-center'), agentTab: ref('revenue'), revenueAgentTab: ref('analysis'),
        resolveDefaultReportHotelId: () => '64', reportHotelOptionExists: id => ['7', '64'].includes(String(id)),
        readDualOtaWorkbenchPreferences: () => ({}), showToast() {},
        nextTick: callback => { if (callback) ticks.push(callback); return Promise.resolve(); },
        watch: (_, callback) => { context.hotelWatcher = callback; },
        resetAgentCenterClientState() {}, clearPostFetchRefreshTimers() {}, clearPlatformProfileLoginTimers() {},
        persistDualOtaWorkbenchPreferences() {}, isCompassDataPage: () => false,
        loadRevenueCockpit: options => reads.push({hotel: hotel.value, ...options}),
    };
    vm.createContext(context);
    vm.runInContext(`let dualOtaSuppressHotelSearchRecord = false; ${defaults} ${watcher}; this.applyDefault = applyDefaultReportHotel;`, context);
    const drain = async () => { while (ticks.length) await ticks.shift()(); };
    const switchHotel = id => { const previous = hotel.value; hotel.value = id; context.hotelWatcher(id, previous); };
    return {hotel, reads, drain, switchHotel, applyDefault: context.applyDefault, notifyRestore: () => context.hotelWatcher(hotel.value, '')};
}

test('default restoration before watcher registration cannot suppress a later user hotel switch', async () => {
    const h = hotelSwitchHarness();
    h.applyDefault({suppressDashboardRefresh: true});
    assert.equal(h.hotel.value, '64');
    // At startup the default may be assigned before the watch is registered.
    await h.drain(); h.switchHotel('7'); await h.drain();
    assert.equal(h.reads.length, 1);
    assert.equal(h.reads[0].hotel, '7');
    assert.equal(h.reads[0].reloadScope, true);
    assert.equal(h.reads[0].resetContext, true);
});

test('the default restore watch still suppresses its duplicate read and later hotel changes read once', async () => {
    const h = hotelSwitchHarness();
    h.applyDefault({suppressDashboardRefresh: true}); h.notifyRestore(); await h.drain();
    assert.equal(h.reads.length, 0);
    h.switchHotel('7'); await h.drain();
    assert.equal(h.reads.length, 1); assert.equal(h.reads[0].hotel, '7');
});

test('ordinary hotel changes without suppressed restoration keep their normal read behavior', async () => {
    const h = hotelSwitchHarness(); h.switchHotel('64'); await h.drain();
    assert.equal(h.reads.length, 1); assert.equal(h.reads[0].hotel, '64');
});
