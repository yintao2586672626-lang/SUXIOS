import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

// Real mounted production components, synthetic GET-only responses, no account or business writes.
const source = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('mounted booking imports and correction drafts reject stale replies; generated bridge keeps exact cents', async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], network = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => { network.push(route.request().url()); return route.abort(); });
    try {
        await page.setContent('<div id="app"></div>');
        await page.addScriptTag({ content: source('node_modules/vue/dist/vue.global.prod.js') });
        await page.addScriptTag({ content: source('public/components/system/operating-finance-control-center.min.js') });
        await page.addScriptTag({ content: source('public/components/system/booking-monitoring-panel.js') });
        await page.evaluate(() => {
            const originalText = File.prototype.text;
            File.prototype.text = function () {
                if (this.name === 'old.json') return new Promise(resolve => { window.finishOldFile = resolve; });
                return originalText.call(this);
            };
            const request = async url => {
                window.syntheticRequests.push(url);
                if (url.includes('/snapshots/9?')) return new Promise(resolve => { window.finishOldCorrection = resolve; });
                const params = new URL(url, 'http://synthetic.invalid').searchParams;
                return { code: 200, data: { contract_version: 'booking_fixed_baseline_monitor.v1',
                    hotel_ids: params.get('hotel_ids').split(',').map(Number), platform: params.get('platform'),
                    business_date: params.get('business_date'), fixed_time: params.get('fixed_time'),
                    horizon_days: Number(params.get('horizon_days')), timezone: 'Asia/Shanghai',
                    boundaries: { external_write_count: 0 }, status: 'empty', cells: [],
                    room_types: [{ id: 1, hotel_id: 80, name: 'TEST-ONLY room80' }, { id: 2, hotel_id: 82, name: 'TEST-ONLY room82' }] } };
            };
            window.syntheticRequests = [];
            const booking = Vue.ref();
            Vue.createApp({ setup: () => () => Vue.h('main', [
                Vue.h(window.SUXI_SYSTEM_COMPONENTS.BookingMonitoringPanel, { ref: booking, request,
                    selectedHotelId: 80, canExecute: true, hotels: [{ id: 80, name: 'TEST-ONLY hotel80' }, { id: 82, name: 'TEST-ONLY hotel82' }] }),
                Vue.h(window.SUXI_SYSTEM_COMPONENTS.InvestmentOperatingBridgePanel, { bridge: {
                    contract_version: 'investment_operating_bridge.v1', status: 'ready',
                    totals: { actual_invested: '92233720368547758.07', net_actual_recovered: '0.00', unrecovered: '0.00', excess_return: '0.00' } } }),
            ]) }).mount('#app');
            window.bookingContext = booking;
        });
        await page.locator('summary').filter({ hasText: '保存或导入真实快照' }).click();
        await page.waitForSelector('input[type="file"]');
        const file = page.locator('input[type="file"]');
        await file.setInputFiles({ name: 'old.json', mimeType: 'application/json', buffer: Buffer.from('{"marker":"old"}') });
        await page.waitForFunction(() => typeof window.finishOldFile === 'function');
        await file.setInputFiles({ name: 'latest.json', mimeType: 'application/json', buffer: Buffer.from('{"marker":"latest"}') });
        await page.waitForFunction(() => window.bookingContext.value.importedFileName === 'latest.json');
        await page.evaluate(() => window.finishOldFile('{"marker":"old"}'));
        await page.evaluate(() => Vue.nextTick());
        assert.equal(await page.locator('textarea').inputValue(), '{"marker":"latest"}');
        await page.getByLabel('TEST-ONLY hotel82', { exact: true }).check();
        await page.getByLabel('更正原快照ID（选填）').fill('9');
        await page.getByRole('button', { name: '按ID回读并载入更正', exact: true }).click();
        await page.waitForFunction(() => typeof window.finishOldCorrection === 'function');
        await page.getByLabel('快照酒店', { exact: true }).selectOption('82');
        await page.getByLabel('更正原快照ID（选填）').fill('9');
        await page.getByLabel('授权来源引用或文件指纹').fill('new-hotel-draft');
        await page.evaluate(() => window.finishOldCorrection({ code: 200, data: {
            contract_version: 'room_type_on_books_snapshot.v1', id: 9, hotel_id: 80, platform: 'ctrip',
            external_write_count: 0, readback_verified: 1, room_type_id: 1, stay_date: '2026-10-04',
            captured_at: '2026-10-03 09:00:00.000000', on_books_room_nights: 12,
        } }));
        await page.evaluate(() => Vue.nextTick());
        assert.equal(await page.getByLabel('快照酒店', { exact: true }).inputValue(), '82');
        assert.equal(await page.getByLabel('授权来源引用或文件指纹').inputValue(), 'new-hotel-draft');
        await page.getByLabel('快照酒店', { exact: true }).selectOption('80');
        await page.getByLabel('更正原快照ID（选填）').fill('9');
        await page.getByRole('button', { name: '按ID回读并载入更正', exact: true }).click();
        await page.getByLabel('授权来源引用或文件指纹').fill('newer-same-hotel-draft');
        await page.getByLabel('在手间夜（必填，实际0可填写）').fill('99');
        await page.evaluate(() => window.finishOldCorrection({ code: 200, data: {
            contract_version: 'room_type_on_books_snapshot.v1', id: 9, hotel_id: 80, platform: 'ctrip',
            external_write_count: 0, readback_verified: 1, room_type_id: 1, stay_date: '2026-10-04',
            captured_at: '2026-10-03 09:00:00.000000', on_books_room_nights: 3,
        } }));
        await page.evaluate(() => Vue.nextTick());
        assert.equal(await page.getByLabel('授权来源引用或文件指纹').inputValue(), 'newer-same-hotel-draft');
        assert.equal(await page.getByLabel('在手间夜（必填，实际0可填写）').inputValue(), '99');
        assert.match(await page.locator('[data-testid="booking-monitor-receipt-notice"]').innerText(), /草稿/);
        assert.match(await page.locator('[data-metric="actual_invested"]').innerText(), /¥92,233,720,368,547,758\.07/);
        const requests = await page.evaluate(() => window.syntheticRequests);
        assert.ok(requests.some(url => url.includes('/snapshots/9?hotel_id=80')));
        assert.ok(requests.every(url => url.startsWith('/booking-monitoring/overview?') || url.startsWith('/booking-monitoring/snapshots/')));
        assert.deepEqual(errors, []);
        assert.deepEqual(network, []);
    } finally { await browser.close(); }
});
