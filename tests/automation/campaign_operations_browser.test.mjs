import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import vm from 'node:vm';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const panel = path.join(root, 'public/components/system/campaign-operations-panel.js');
const vue = path.join(root, 'public/vue.runtime.global.prod.js');

async function mount(page, initialTab = 'video') {
    // An isolated synthetic fixture: no authenticated browser or real API/DB.
    await page.route('http://campaign.test/**', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><div id="app"></div>' }));
    await page.goto('http://campaign.test/');
    await page.addScriptTag({ path: vue });
    await page.addStyleTag({ path: path.join(root, 'public/tailwind.min.css') });
    await page.addScriptTag({ path: path.join(root, 'public/components/system/campaign-local-media.js') });
    await page.addScriptTag({ path: path.join(root, 'public/components/system/campaign-marketing-weekly.js') });
    await page.addScriptTag({ path: panel });
    await page.evaluate(initialTab => {
        window.fixtureRows = [];
        window.fixtureCalls = [];
        const h = Vue.h;
        const request = async (url, options = {}) => {
            window.fixtureCalls.push({ url, body: options.body });
            const parsed = new URL(url, location.origin);
            const hotel = Number(parsed.searchParams.get('hotel_id') || 11);
            const date = parsed.searchParams.get('business_date');
            if (url.includes('/overview')) {
                if (window.deferNextOverview) { window.deferNextOverview = false; await new Promise(resolve => { window.releaseOverview = resolve; }); }
                return { code: 200, data: { tenant_id: hotel === 11 ? 101 : 102, hotel_id: hotel, business_date: date, records: window.fixtureRows.filter(row => row.hotel_id === hotel && row.business_date === date), previous_handover: null, total: 0, data_status: 'empty' } };
            }
            if (options.method === 'POST') {
                const input = JSON.parse(options.body);
                if (window.rejectSave) return { code: 503, message: 'synthetic 保存失败：表未就绪' };
                const row = { id: window.fixtureRows.length + 1, tenant_id: input.hotel_id === 11 ? 101 : 102, hotel_id: input.hotel_id, kind: input.kind, record_key: input.record_key, business_date: input.business_date, source_label: input.source_label, version_no: 1, payload: input.payload, schema_version: 'campaign_operations.v1', data_status: 'unverified' };
                if (row.kind === 'handover') row.payload = { ...row.payload, items: row.payload.new_items, acknowledged_by: null };
                window.fixtureRows.push(row);
                return { code: 200, data: { request_status: 'saved_and_readback_verified', record: row, reused: false } };
            }
            const id = Number(/records\/(\d+)/.exec(url)?.[1]);
            const row = window.fixtureRows.find(row => row.id === id && row.hotel_id === hotel);
            if (!row) return { code: 404, message: 'synthetic 当前酒店记录不存在' };
            if (window.deferRecordReads) await new Promise((resolve, reject) => { window.pendingRecordReads.push({ id, resolve, reject }); });
            if (url.includes('/artifact')) {
                const content = `<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1440"><rect width="1080" height="1440" fill="${row.payload.brand_color}"/><text x="40" y="80" fill="white">synthetic ${row.payload.title} #${row.id} v${row.version_no}</text></svg>`;
                return { code: 200, data: { ...row, content, mime_type: 'image/svg+xml', filename: `poster-${id}-v1.svg` } };
            }
            return { code: 200, data: row };
        };
        window.fixturePanel = Vue.createApp({ render: () => h(window.SUXI_SYSTEM_COMPONENTS.CampaignOperationsPanel, { ref: 'panel', hotels: [{ id: 11, name: 'synthetic 酒店A' }, { id: 12, name: 'synthetic 酒店B' }], selectedHotelId: 11, canExecute: true, request, initialTab, onNavigate: payload => { window.lastNavigate = payload; } }) }).mount('#app');
    }, initialTab);
    await page.getByText('当前酒店/日期没有此类已保存记录。', { exact: true }).waitFor();
}

test('synthetic campaign scope changes clear unsaved handover and closure drafts', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage(); await mount(page, 'shift');
        await page.getByLabel('未结事项', { exact: true }).fill('synthetic first-hotel item');
        await page.getByLabel('责任人', { exact: true }).fill('synthetic first-hotel owner');
        await page.getByLabel('截止时间（上海）', { exact: true }).fill('2026-10-02T18:00');
        await page.evaluate(() => { window.fixturePanel.$refs.panel.closureEvidence = { sameItem: 'synthetic first-hotel evidence' }; });
        await page.locator('header select').selectOption('12');
        await page.waitForFunction(() => window.fixturePanel.$refs.panel.overview?.hotel_id === 12);
        assert.equal(await page.getByLabel('未结事项', { exact: true }).inputValue(), '');
        assert.equal(await page.getByLabel('责任人', { exact: true }).inputValue(), '');
        assert.deepEqual(await page.evaluate(() => window.fixturePanel.$refs.panel.closureEvidence), {});
        await page.getByLabel('未结事项', { exact: true }).fill('synthetic old-date item');
        await page.evaluate(() => { window.fixturePanel.$refs.panel.closureEvidence = { sameItem: 'synthetic old-date evidence' }; });
        await page.locator('header input[type=date]').fill('2026-09-01');
        await page.waitForFunction(() => window.fixturePanel.$refs.panel.overview?.business_date === '2026-09-01');
        assert.equal(await page.getByLabel('未结事项', { exact: true }).inputValue(), '');
        assert.deepEqual(await page.evaluate(() => window.fixturePanel.$refs.panel.closureEvidence), {});
    } finally { await browser.close(); }
});

test('mounted campaign edit keeps the last selected record and its draft when an older read succeeds or fails', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage(); await mount(page, 'campaign');
        await page.evaluate(async () => {
            const panel = window.fixturePanel.$refs.panel;
            window.fixtureRows = [1, 2].map(id => ({ id, hotel_id: 11, business_date: panel.businessDate, kind: 'marketing', record_key: `synthetic_record_${id}`, source_label: 'synthetic', version_no: 1, schema_version: 'campaign_operations.v1', payload: { title: `synthetic title ${id}` } }));
            await panel.load(); window.deferRecordReads = true; window.pendingRecordReads = [];
        });
        const reads = page.getByRole('button', { name: '回读并编辑新版本', exact: true });
        await reads.nth(0).click(); await reads.nth(1).click();
        await page.waitForFunction(() => window.pendingRecordReads.length === 2);
        await page.evaluate(() => window.pendingRecordReads[1].resolve());
        await page.waitForFunction(() => window.fixturePanel.$refs.panel.form.expected_id === 2);
        await page.locator('input[name="title"]').fill('synthetic latest selected draft');
        await page.evaluate(() => window.pendingRecordReads[0].resolve());
        await new Promise(resolve => setTimeout(resolve, 50));
        assert.equal(await page.evaluate(() => window.fixturePanel.$refs.panel.form.expected_id), 2);
        assert.equal(await page.locator('input[name="title"]').inputValue(), 'synthetic latest selected draft');
        await reads.nth(0).click(); await reads.nth(1).click();
        await page.waitForFunction(() => window.pendingRecordReads.length === 4);
        await page.evaluate(() => window.pendingRecordReads[3].resolve());
        await page.waitForFunction(() => window.fixturePanel.$refs.panel.form.expected_id === 2);
        await page.evaluate(() => window.pendingRecordReads[2].reject(new Error('synthetic old read failed')));
        await new Promise(resolve => setTimeout(resolve, 50));
        assert.equal(await page.evaluate(() => window.fixturePanel.$refs.panel.error), '');
        // A deliberate new-record choice invalidates any older edit response.
        await reads.nth(0).click(); await page.getByRole('button', { name: '新记录', exact: true }).click();
        await page.evaluate(() => window.pendingRecordReads[4].resolve());
        await new Promise(resolve => setTimeout(resolve, 50));
        assert.equal(await page.evaluate(() => window.fixturePanel.$refs.panel.form.expected_id), 0);
    } finally { await browser.close(); }
});

test('campaign edit ignores success and failure after unmount', async () => {
    const text = await fs.readFile(panel, 'utf8'), context = { window: { crypto: globalThis.crypto }, Intl, Date };
    vm.runInNewContext(text, context);
    const component = context.window.SUXI_SYSTEM_COMPONENTS.CampaignOperationsPanel;
    for (const failure of [false, true]) {
        let resolve, reject;
        const result = new Promise((done, fail) => { resolve = done; reject = fail; });
        const state = { ...component.data(), hotelId: '11', businessDate: '2026-10-02', request: () => result };
        for (const [key, value] of Object.entries(component.methods)) state[key] = value.bind(state);
        const waiting = state.edit({ id: 1 }); component.beforeUnmount.call(state);
        failure ? reject(new Error('synthetic old failure')) : resolve({ code: 200, data: { id: 1, hotel_id: 11, business_date: '2026-10-02', schema_version: 'campaign_operations.v1', payload: {} } });
        await waiting; assert.equal(state.saved, null); assert.equal(state.error, '');
    }
});

test('synthetic campaign tab switch during overview does not strand loading', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage(); await mount(page, 'shift');
        await page.evaluate(() => { window.deferNextOverview = true; void window.fixturePanel.$refs.panel.load(); });
        await page.waitForFunction(() => !!window.releaseOverview);
        await page.getByRole('button', { name: '节日海报', exact: true }).click();
        await page.evaluate(() => window.releaseOverview());
        await page.waitForFunction(() => !window.fixturePanel.$refs.panel.loading);
        assert.equal(await page.evaluate(() => window.fixturePanel.$refs.panel.loading), false);
        assert.equal(await page.getByRole('button', { name: '保存并回读', exact: true }).isEnabled(), true);
    } finally { await browser.close(); }
});

test('campaign record cards distinguish legacy content checks from verified immutable metadata', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage(); await mount(page, 'campaign');
        await page.evaluate(async () => {
            const date = window.fixturePanel.$refs.panel.businessDate;
            const row = { tenant_id: 101, hotel_id: 11, source_hotel_id: 11, kind: 'marketing', record_key: 'synthetic-integrity', business_date: date, source_label: 'synthetic-source', version_no: 1, schema_version: 'campaign_operations.v1', payload: { title: 'synthetic-card', views: null, reservations: null, effective_leads: null, actual_arrivals: null, actual_room_nights: null, actual_revenue: null } };
            window.fixtureRows = [{ ...row, id: 1, integrity_status: 'legacy_content_only' }, { ...row, id: 2, integrity_status: 'immutable_metadata_verified' }, { ...row, id: 3 }];
            await window.fixturePanel.$refs.panel.load();
        });
        await page.locator('article[data-record-id="1"]').waitFor();
        assert.match(await page.locator('article[data-record-id="1"]').innerText(), /旧版本可回读；原始酒店、版本号及记录人尚不能独立校验/);
        assert.match(await page.locator('article[data-record-id="2"]').innerText(), /保存内容、原始酒店、版本号与记录人一致；经营结果仍待核对/);
        assert.match(await page.locator('article[data-record-id="3"]').innerText(), /未返回原始酒店、版本号与记录人的校验结果/);
    } finally { await browser.close(); }
});

test('synthetic CampaignOperationsPanel saves source, downloads SVG and produces a playable WebM', { timeout: 45000 }, async () => {
    const browser = await chromium.launch({ headless: true });
    const temp = await fs.mkdtemp(path.join(os.tmpdir(), 'campaign-browser-'));
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await mount(page);
        await page.locator('input[name="source_label"]').fill('synthetic 隔离制作单');
        await page.locator('input[name="hotel_name"]').fill('synthetic 测试酒店');
        await page.locator('input[name="title"]').fill('隔离节日文字画面');
        await page.locator('textarea[name="copy"]').fill('synthetic 文案第一行\n第二行完整展示');
        await page.locator('textarea[name="material_notes"]').fill('synthetic 仅自制文字，无实拍或音乐');
        await page.locator('input[name="duration_seconds"]').fill('3');
        await page.getByRole('button', { name: '保存并回读', exact: true }).click();
        await page.locator('article[data-record-id="1"]').waitFor();
        const row = await page.evaluate(() => window.fixtureRows[0]);
        assert.equal(row.kind, 'video_brief'); assert.equal(row.source_label, 'synthetic 隔离制作单');
        const webmEvent = page.waitForEvent('download', { timeout: 15000 });
        await page.getByRole('button', { name: '生成并下载WebM', exact: true }).click();
        const webm = await webmEvent;
        const webmPath = path.join(temp, webm.suggestedFilename()); await webm.saveAs(webmPath);
        const bytes = await fs.readFile(webmPath);
        assert.ok(bytes.length > 2000, `WebM too small: ${bytes.length}`);
        assert.deepEqual([...bytes.subarray(0, 4)], [0x1a, 0x45, 0xdf, 0xa3]);
        assert.match(webm.suggestedFilename(), /hotel-11-video-1-v1\.webm$/);
        const playback = await page.evaluate(async data => {
            const binary = atob(data); const bytes = Uint8Array.from(binary, char => char.charCodeAt(0));
            const video = document.createElement('video'); video.muted = true; video.src = URL.createObjectURL(new Blob([bytes], { type: 'video/webm' })); document.body.append(video);
            await new Promise((resolve, reject) => { video.onloadeddata = resolve; video.onerror = () => reject(new Error('WebM decode failed')); });
            await video.play(); await new Promise(resolve => setTimeout(resolve, 600));
            const state = { width: video.videoWidth, height: video.videoHeight, currentTime: video.currentTime, readyState: video.readyState };
            video.pause(); URL.revokeObjectURL(video.src); video.remove(); return state;
        }, bytes.toString('base64'));
        assert.equal(playback.width, 1280); assert.equal(playback.height, 720); assert.ok(playback.currentTime > 0);
        await page.getByRole('button', { name: '节日海报', exact: true }).click();
        await page.locator('input[name="source_label"]').fill('synthetic 海报来源');
        await page.locator('input[name="hotel_name"]').fill('synthetic 测试酒店');
        await page.locator('input[name="title"]').fill('隔离海报');
        await page.locator('textarea[name="copy"]').fill('synthetic 海报完整文案');
        await page.locator('textarea[name="material_notes"]').fill('synthetic 自制文字');
        await page.getByRole('button', { name: '保存并回读', exact: true }).click();
        await page.locator('article[data-record-id="2"]').waitFor();
        const svgEvent = page.waitForEvent('download'); await page.getByRole('button', { name: '下载SVG海报', exact: true }).click();
        const svg = await svgEvent; const svgPath = path.join(temp, svg.suggestedFilename()); await svg.saveAs(svgPath);
        assert.match(await fs.readFile(svgPath, 'utf8'), /synthetic 隔离海报 #2 v1/);
        await page.getByRole('button', { name: '日报与记录核对', exact: true }).click();
        await page.getByRole('button', { name: '打开每日事实录入', exact: true }).click();
        assert.deepEqual(await page.evaluate(() => window.lastNavigate), { page: 'operating-targets' });
        await page.setViewportSize({ width: 320, height: 900 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'narrow page overflow');
        await page.getByRole('button', { name: '营销作品数据', exact: true }).click();
        await page.evaluate(() => { window.rejectSave = true; });
        await page.locator('input[name="source_label"]').fill('synthetic 手工数据');
        await page.getByRole('button', { name: '保存并回读', exact: true }).click();
        await page.getByRole('alert').filter({ hasText: 'synthetic 保存失败' }).waitFor();
        assert.equal(await page.evaluate(() => window.fixtureRows.length), 2);
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({ evidence: 'synthetic browser only', webmBytes: bytes.length, webmPlayback: playback, svgDownloaded: true, responsive320: true, saveErrorVisible: true }));
    } finally {
        await browser.close();
        assert.ok(path.resolve(temp).startsWith(path.resolve(os.tmpdir()) + path.sep) && path.basename(temp).startsWith('campaign-browser-'));
        await fs.rm(temp, { recursive: true, force: true });
    }
});
