import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';
import { compileFrontendTemplate } from '../../scripts/lib/frontend_template_build.mjs';
import { frontendTemplateManifestFragments } from '../../scripts/lib/frontend_template_source.mjs';

// Synthetic acceptance only: fresh headless Chromium, precompiled target fragment,
// real Vue runtime and original local navigation functions. All data loaders are
// fixture spies; no live API, database, browser profile or shared build is used.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => readFileSync(path.join(root, relative), 'utf8');
const fragmentPath = 'resources/frontend/templates/fragments/19c-page-operating-finance.html';
const fragment = read(fragmentPath);
const main = read('public/app-main.js');
const compiled = compileFrontendTemplate(fragment);

function between(source, start, end) {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first + start.length);
    assert.ok(first >= 0 && last > first, `Missing original navigation boundary: ${start}`);
    return source.slice(first, last);
}

const ctripOpen = between(main, 'const openCtripManualTab = (tab) => {', 'const openMeituanManualTab =');
const meituanOpen = between(main, 'const openMeituanStoredDataTab = (tab) => {', 'const meituanDownloadData =');
const meituanDownload = between(main, 'const switchToMeituanDownloadCenter = () => {', 'const openMeituanStoredBusinessDate =');
const meituanPrepare = between(main, 'const prepareMeituanStoredDataViewForScopeChange = () => {', 'const currentOnlineDataListScope =');
const meituanFilter = between(main, 'const meituanStoredDataTypesByTab = Object.freeze({', 'const queryMeituanStoredData =');
const syncHotel = between(main, 'const syncUnifiedHotelContexts = (hotelId, previousHotelId', 'const adoptUnifiedHotelFromPage =');
const setHotel = main.match(/const setHotel = value => \{ filterReportHotel\.value = String\(value \|\| ''\); \};/)?.[0];
assert.ok(setHotel, 'Existing setHotel must be retained by the fixture');

const targets = [
    { label: '投资回本与多情景', page: 'investment-payback' },
    { label: '酒店实际经营数据', page: 'pms-operating-data' },
    { label: '携程广告', page: 'ctrip-ebooking', tab: 'ctrip-ads', calls: ['loadConfigList', 'applyHotelConfig', 'syncAdsConfig', 'loadSupplementalSnapshot'] },
    { label: '美团已存广告', page: 'meituan-ebooking', tab: 'meituan-download', download: 'ads', calls: ['loadStoredData'] },
    { label: '携程竞对价格', page: 'ctrip-ebooking', tab: 'ctrip-market-competition', calls: ['ensureCompetitionHotel', 'loadCompetitionWorkspace'] },
    { label: '经营目标与预算', page: 'operating-targets' },
    { label: '执行证据与效果复盘', page: 'ops-track' },
];

test('owner hub compiles without unclosed Vue elements and stays in its registered complete fragment', () => {
    assert.match(compiled, /return function render/);
    assert.match(fragment, /data-testid="owner-operations-hub"/);
    const manifest = JSON.parse(read('resources/frontend/templates/manifest.json'));
    const entry = manifest.fragments.find(item => item.id === 'page-operating-finance');
    const registration = frontendTemplateManifestFragments().find(item => item.id === entry?.id);
    assert.deepEqual(entry, registration);
    assert.equal(entry.path, 'fragments/19c-page-operating-finance.html');
    assert.ok(fragment.trimStart().startsWith(entry.anchor),
        'The reverse-migration anchor must start at the hub wrapper so its header is not assigned to the previous fragment');
    assert.equal((fragment.match(/<operating-finance-control-center\b/g) || []).length, 1);
});

async function mountFixture(page) {
    await page.setContent('<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"></head><body><main id="fixture-main"><div id="app"></div></main></body></html>');
    await page.addStyleTag({ content: read('public/tailwind.min.css') });
    await page.addStyleTag({ content: read('public/style.min.css') });
    await page.addStyleTag({ content: 'body { margin: 0; } #fixture-main { padding: 12px; }' });
    await page.addScriptTag({ content: read('public/vue.runtime.global.prod.js') });
    await page.addScriptTag({ content: read('public/ctrip-static.js') });
    await page.addScriptTag({ content: read('public/meituan-static.js') });
    await page.addScriptTag({ content: `
        window.__hubRender = (function(Vue) { ${compiled} })(Vue);
        (() => {
            const { ref, watch, h, nextTick } = Vue;
            const currentPage = ref('operating-finance');
            const filterReportHotel = ref('80');
            const selectedCtripHotelId = ref('80');
            const meituanForm = ref({ hotelId: '80' });
            const onlineDataTab = ref('data-health');
            const downloadCenterTab = ref('overview');
            const onlineDataFilter = ref({ hotel_id: '80', source: 'ctrip', start_date: '2026-10-01', end_date: '2026-10-01' });
            const onlineDataPage = ref(1);
            const onlineDataPagination = ref({ page: 1 });
            const onlineDataListLoading = ref(false);
            const onlineDataListError = ref('synthetic old error');
            const onlineDataList = ref([{ source: 'ctrip', hotel_id: 80 }]);
            const onlineDataQualitySummary = ref({ status: 'synthetic-old' });
            const onlineDataListSnapshotScope = ref('synthetic-old-scope');
            let onlineDataListSnapshotKey = 'synthetic-old-key';
            let onlineDataListActiveRequestKey = 'synthetic-old-request';
            let onlineDataListSnapshotSession = { fixture: true };
            const calls = [];
            let deferred = [];
            const record = (name, detail = {}) => calls.push({ name, hotel: filterReportHotel.value, ...detail });
            const managerCapabilityRequest = () => { throw new Error('Live requests are forbidden in this fixture'); };
            const runCtripManualTabSwitch = window.SUXI_CTRIP_STATIC.runCtripManualTabSwitch;
            const requireMeituanStatic = name => window.SUXI_MEITUAN_STATIC[name];
            const deferUiTask = callback => deferred.push(callback);
            const loadCtripConfigList = async () => record('loadConfigList', { platformHotel: selectedCtripHotelId.value });
            const applyCtripHotelConfig = async () => record('applyHotelConfig', { platformHotel: selectedCtripHotelId.value });
            const syncCtripAdsDirectConfig = async () => record('syncAdsConfig', { platformHotel: selectedCtripHotelId.value });
            const loadCollectionReliability = async () => record('loadSupplementalSnapshot', { platformHotel: selectedCtripHotelId.value });
            const ensureCtripPublicProfileHotelSelected = async () => record('ensureCompetitionHotel', { platformHotel: selectedCtripHotelId.value });
            const loadCtripCompetitionWorkspace = async () => record('loadCompetitionWorkspace', { platformHotel: selectedCtripHotelId.value });
            const scheduleDataHealthPanelRefresh = async () => record('loadDataHealth');
            const MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS = 100;
            const noop = () => {};
            const clearCtripEbookingModuleCardsReadyTimer = noop;
            const clearCtripEbookingSecondaryPanelsReadyTimer = noop;
            const clearCtripEbookingDeepPanelsReadyTimer = noop;
            const clearCtripEbookingBusinessDetailsReadyTimer = noop;
            const ctripEbookingModuleCardsReady = ref(false);
            const ctripEbookingSecondaryPanelsReady = ref(false);
            const ctripEbookingDeepPanelsReady = ref(false);
            const ctripEbookingBusinessDetailsReady = ref(false);
            const ctripEbookingDiagnosticsPanelsReady = ref(false);
            const resolveMeituanTemporalHotelId = () => meituanForm.value.hotelId;
            const today = '2026-10-02', thirtyDaysAgo = '2026-09-02', formatDate = value => value;
            const scheduleDownloadCenterTabLoad = (tab, options) => record('loadStoredData', {
                tab, source: options.source, filter: { ...onlineDataFilter.value }, platformHotel: meituanForm.value.hotelId,
            });
            ${meituanFilter}
            ${meituanPrepare}
            ${meituanDownload}
            ${meituanOpen}
            ${ctripOpen}
            const reportHotelOptionExists = id => ['80', '81'].includes(id);
            let unifiedHotelContextSyncing = false;
            const resetUnifiedHotelScopedResults = noop;
            const unifiedHotelContextBindings = [
                { read: () => selectedCtripHotelId.value, write: value => { selectedCtripHotelId.value = value; } },
                { read: () => meituanForm.value.hotelId, write: value => { meituanForm.value.hotelId = value; } },
            ];
            ${syncHotel}
            ${setHotel}
            watch(filterReportHotel, (hotel, previous) => syncUnifiedHotelContexts(hotel, previous), { immediate: true, flush: 'sync' });
            const app = Vue.createApp({
                components: {
                    OperatingFinanceControlCenter: {
                        props: ['hotels', 'request', 'selectedHotelId', 'canExecute'],
                        emits: ['update:selected-hotel-id'],
                        setup(props, { emit }) {
                            return () => h('section', { 'data-testid': 'synthetic-finance-child' }, [
                                h('p', { 'data-testid': 'synthetic-selected-hotel' }, 'Synthetic hotel ' + props.selectedHotelId),
                                h('button', { type: 'button', onClick: () => emit('update:selected-hotel-id', '81') }, '模拟切换酒店81'),
                            ]);
                        },
                    },
                },
                setup() {
                    return { currentPage, filterReportHotel, hotels: [{ id: 80, name: '模拟一店' }, { id: 81, name: '模拟二店' }],
                        managerCapabilityRequest, operationFinanceCanExecute: true, setHotel, openCtripManualTab, openMeituanStoredDataTab };
                },
                render: window.__hubRender,
            });
            app.config.errorHandler = error => { throw error; };
            app.mount('#app');
            window.__hub = {
                reset: async hotel => { setHotel(String(hotel)); currentPage.value = 'operating-finance'; onlineDataTab.value = 'data-health'; downloadCenterTab.value = 'overview'; calls.length = 0; deferred = [];
                    onlineDataListLoading.value = false; onlineDataListError.value = 'synthetic old error'; onlineDataList.value = [{ source: 'ctrip', hotel_id: 80 }];
                    onlineDataQualitySummary.value = { status: 'synthetic-old' }; onlineDataPagination.value = { page: 1, total: 5 };
                    onlineDataListSnapshotScope.value = 'synthetic-old-scope'; onlineDataListSnapshotKey = 'synthetic-old-key';
                    onlineDataListActiveRequestKey = 'synthetic-old-request'; onlineDataListSnapshotSession = { fixture: true }; await nextTick(); },
                flush: async () => { while (deferred.length) { const tasks = deferred; deferred = []; for (const task of tasks) await task(); } await nextTick(); },
                snapshot: () => ({ page: currentPage.value, hotel: filterReportHotel.value, ctripHotel: selectedCtripHotelId.value,
                    meituanHotel: meituanForm.value.hotelId, tab: onlineDataTab.value, download: downloadCenterTab.value, calls: [...calls],
                    stored: { loading: onlineDataListLoading.value, error: onlineDataListError.value, list: onlineDataList.value,
                        quality: onlineDataQualitySummary.value, total: onlineDataPagination.value.total, scope: onlineDataListSnapshotScope.value,
                        key: onlineDataListSnapshotKey, requestKey: onlineDataListActiveRequestKey, session: onlineDataListSnapshotSession } }),
            };
        })();
    ` });
}

test('synthetic Chromium accepts the owner hub catalog, original navigation, hotel scope and responsive layout', { timeout: 60_000 }, async t => {
    const browser = await chromium.launch({ headless: true });
    t.after(() => browser.close());
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [];
    const attemptedRequests = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await page.route('**/*', route => { attemptedRequests.push(route.request().url()); return route.abort(); });
    await mountFixture(page);

    await t.test('all 31 source modules appear exactly once with disclosure states', async () => {
        const ids = await page.locator('[data-source-module]').evaluateAll(rows => rows.map(row => Number(row.dataset.sourceModule)));
        assert.equal(ids.length, 31);
        assert.deepEqual([...ids].sort((a, b) => a - b), Array.from({ length: 31 }, (_, i) => i + 1));
        const ranks = await page.locator('[data-source-module] td:first-child').allTextContents();
        assert.deepEqual(ranks.map(Number), Array.from({ length: 31 }, (_, i) => i + 1));
        const disclosure = page.locator('[data-testid="changqing-capability-catalog"]');
        assert.equal(await disclosure.evaluate(node => node.open), false);
        await disclosure.locator('summary').click();
        assert.equal(await disclosure.evaluate(node => node.open), true);
        assert.match(await disclosure.textContent(), /候选和规划项仍有待验收/);
        assert.match(await disclosure.textContent(), /交班小结/);
        await disclosure.locator('summary').click();
        assert.equal(await disclosure.evaluate(node => node.open), false);
    });

    await t.test('seven buttons call existing navigation and preserve hotels 80 and 81', async () => {
        for (const hotel of ['80', '81']) {
            for (const target of targets) {
                await page.evaluate(hotel => window.__hub.reset(hotel), hotel);
                const entry = page.locator('[data-testid="owner-operations-hub"] > section').filter({ has: page.getByRole('heading', { name: '经营与自投项目', exact: true }) });
                assert.equal(await entry.getByRole('button').count(), 7);
                await page.getByRole('button', { name: target.label, exact: true }).click();
                await page.evaluate(() => window.__hub.flush());
                const state = await page.evaluate(() => window.__hub.snapshot());
                assert.equal(state.page, target.page, target.label);
                assert.equal(state.hotel, hotel, target.label);
                assert.equal(state.ctripHotel, hotel, target.label);
                assert.equal(state.meituanHotel, hotel, target.label);
                if (target.tab) assert.equal(state.tab, target.tab, target.label);
                if (target.download) assert.equal(state.download, target.download, target.label);
                assert.deepEqual(state.calls.map(call => call.name), target.calls || [], target.label);
                for (const call of state.calls) {
                    assert.equal(call.hotel, hotel, target.label);
                    assert.equal(call.platformHotel, hotel, target.label);
                }
                if (target.download) {
                    assert.deepEqual(state.stored, { loading: true, error: '', list: [], quality: null, total: null,
                        scope: '', key: '', requestKey: '', session: {} });
                    assert.equal(state.calls[0].filter.hotel_id, hotel);
                    assert.equal(state.calls[0].filter.source, 'meituan');
                    assert.equal(state.calls[0].filter.data_types, 'advertising');
                    assert.equal(state.calls[0].tab, 'ads');
                }
                assert.equal(await page.locator('[data-testid="owner-operations-hub"]').count(), 0);
            }
        }
    });

    await t.test('the retained finance child updates shared hotel selection before a platform jump', async () => {
        await page.evaluate(() => window.__hub.reset('80'));
        await page.getByRole('button', { name: '模拟切换酒店81', exact: true }).click();
        assert.equal(await page.locator('[data-testid="synthetic-selected-hotel"]').textContent(), 'Synthetic hotel 81');
        await page.getByRole('button', { name: '美团已存广告', exact: true }).click();
        await page.evaluate(() => window.__hub.flush());
        const state = await page.evaluate(() => window.__hub.snapshot());
        assert.equal(state.hotel, '81');
        assert.equal(state.calls[0].filter.hotel_id, '81');
    });

    await t.test('320px and desktop keep both folded and expanded catalogs inside the page width', async () => {
        for (const width of [320, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => window.__hub.reset('81'));
            for (const open of [false, true]) {
                const disclosure = page.locator('[data-testid="changqing-capability-catalog"]');
                if (open) await disclosure.locator('summary').click();
                const layout = await page.evaluate(() => {
                    const doc = document.documentElement;
                    const hub = document.querySelector('[data-testid="owner-operations-hub"]');
                    const tableScroller = document.querySelector('[data-testid="changqing-capability-catalog"] table').parentElement;
                    return { viewport: doc.clientWidth, pageWidth: doc.scrollWidth, bodyWidth: document.body.scrollWidth,
                        hubRight: hub.getBoundingClientRect().right, scrollerWidth: tableScroller.clientWidth,
                        tableWidth: tableScroller.scrollWidth, scrollerOverflow: getComputedStyle(tableScroller).overflowX };
                });
                assert.ok(layout.pageWidth <= layout.viewport + 1, JSON.stringify({ width, open, ...layout }));
                assert.ok(layout.bodyWidth <= layout.viewport + 1, JSON.stringify({ width, open, ...layout }));
                assert.ok(layout.hubRight <= layout.viewport + 1, JSON.stringify({ width, open, ...layout }));
                if (open) {
                    assert.equal(layout.scrollerOverflow, 'auto');
                    assert.ok(layout.scrollerWidth > 0 && layout.scrollerWidth < width);
                    if (width === 320) assert.ok(layout.tableWidth > layout.scrollerWidth, 'Wide catalog must scroll inside its own container');
                }
            }
        }
    });

    assert.deepEqual(errors, [], 'No Vue runtime or page errors are allowed');
    assert.deepEqual(attemptedRequests, [], 'The synthetic fixture must make no network request');
});
