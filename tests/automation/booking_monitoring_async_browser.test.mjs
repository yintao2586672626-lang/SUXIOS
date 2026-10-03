import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';
import { createHash } from 'node:crypto';

// Real mounted production components, synthetic GET-only responses, no account or business writes.
const source = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
function bookingOverviewFixture(url) {
    const params = new URL(url, 'http://synthetic.invalid').searchParams;
    const ids = params.get('hotel_ids').split(',').map(Number);
    const hotels = [80, 82].map(id => ({ id, tenant_id: 7, name: `TEST-ONLY hotel${id}`, can_execute: true }));
    const rooms = hotels.filter(hotel => ids.includes(hotel.id)).map(hotel => ({ id: hotel.id === 80 ? 1 : 2, hotel_id: hotel.id, name: `TEST-ONLY room${hotel.id}` }));
    const businessDate = params.get('business_date'), fixedTime = params.get('fixed_time'), horizon = Number(params.get('horizon_days'));
    const dateAfter = days => { const date = new Date(businessDate + 'T00:00:00Z'); date.setUTCDate(date.getUTCDate() + days); return date.toISOString().slice(0, 10); };
    const cells = rooms.flatMap(room => Array.from({ length: horizon }, (_, index) => ({ hotel_id: room.hotel_id,
        hotel_name: `TEST-ONLY hotel${room.hotel_id}`, room_type_id: room.id, room_type_name: room.name,
        stay_date: dateAfter(index + 1), lead_time_days: index + 1, status: 'blocked',
        current: { status: 'missing', captured_at: null, on_books_room_nights: null }, baseline: { status: 'missing', captured_at: null },
        net_pickup_24h_room_nights: null, room_revenue_delta_24h: null, same_lead_time_median_room_nights: null,
        delta_vs_same_lead_time_median: null, history_coverage: 0, history: [], data_gaps: ['current_slot_missing', 'baseline_slot_missing'] })));
    return { code: 200, message: 'TEST-ONLY GET fixture', data: { contract_version: 'booking_fixed_baseline_monitor.v1', tenant_id: 7,
        hotel_ids: ids, selectable_hotels: hotels, platform: params.get('platform'), business_date: businessDate, fixed_time: fixedTime,
        horizon_days: horizon, timezone: 'Asia/Shanghai', observation_time: `${businessDate} ${fixedTime}:00`, baseline_time: `${dateAfter(-1)} ${fixedTime}:00`,
        boundaries: { external_write_count: 0 }, status: 'blocked', ready_cell_count: 0, cell_count: cells.length, cells, room_types: rooms } };
}
function correctionFixture(rooms) {
    const sourceRef = 'TEST-ONLY persisted correction source';
    const content = { contract_version: 'room_type_on_books_snapshot.v1', tenant_id: 7, hotel_id: 80, source_hotel_id: 80,
        platform: 'ctrip', fact_scope: 'ota_channel', room_type_id: 1, room_type_name: 'TEST-ONLY room80',
        stay_date: '2026-10-04', captured_at: '2026-10-03 09:00:00.000000', on_books_room_nights: rooms,
        on_books_room_revenue: null, cumulative_cancel_room_nights: null, gross_booking_room_nights: null,
        source_method: 'manual_file_import', source_ref_hash: createHash('sha256').update('on-books-source-v1|' + sourceRef).digest('hex'),
        quality_status: 'manual_confirmed', readback_verified: 1, supersedes_snapshot_id: null };
    const digestContent = Object.fromEntries(Object.entries(content).filter(([key]) => key !== 'hotel_id').sort(([left], [right]) => left.localeCompare(right)));
    const canonical = JSON.stringify(digestContent).replace(/("on_books_room_nights":)(\d+)(?=[,}])/, '$1$2.0');
    return { code: 200, data: { ...content, id: 9, external_write_count: 0, idempotency_key: 'b'.repeat(64),
        content_digest: createHash('sha256').update(canonical).digest('hex'), created_by: 1,
        created_at: '2026-10-03 09:00:01', evidence_ref: 'hotel_room_type_on_books_snapshots#9' } };
}

test('booking browser GET fixtures retain strict tenant, executable hotels and original correction identity', () => {
    for (const ids of [[80], [80, 82], [82]]) {
        const response = bookingOverviewFixture('/booking-monitoring/overview?' + new URLSearchParams({ hotel_ids: ids.join(','),
            platform: 'ctrip', business_date: '2026-10-03', fixed_time: '09:00', horizon_days: '7' }));
        assert.deepEqual(response.data.hotel_ids, ids); assert.equal(response.data.tenant_id, 7);
        assert.deepEqual(response.data.selectable_hotels.map(hotel => [hotel.id, hotel.tenant_id, hotel.can_execute]), [[80, 7, true], [82, 7, true]]);
        assert.ok(response.data.room_types.every(room => ids.includes(room.hotel_id)));
        assert.ok(response.data.cells.every(cell => ids.includes(cell.hotel_id) && cell.current.on_books_room_nights === null));
        assert.equal(response.data.cell_count, ids.length * 7); assert.equal(response.data.boundaries.external_write_count, 0);
    }
    for (const rooms of [12, 3]) {
        const { data } = correctionFixture(rooms);
        assert.equal(data.tenant_id, 7); assert.equal(data.id, 9); assert.equal(data.hotel_id, 80); assert.equal(data.source_hotel_id, 80);
        assert.equal(data.on_books_room_nights, rooms); assert.equal(data.room_type_name, 'TEST-ONLY room80');
        assert.equal(data.readback_verified, 1); assert.equal(data.external_write_count, 0);
        for (const field of ['source_ref_hash', 'content_digest', 'idempotency_key']) assert.match(data[field], /^[a-f0-9]{64}$/);
    }
});

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
        await page.addScriptTag({ content: 'window.bookingOverviewFixture = ' + bookingOverviewFixture.toString() + ';' });
        await page.evaluate(() => {
            const originalText = File.prototype.text;
            File.prototype.text = function () {
                if (this.name === 'old.json') return new Promise(resolve => { window.finishOldFile = resolve; });
                return originalText.call(this);
            };
            const request = async url => {
                window.syntheticRequests.push(url);
                if (url.includes('/snapshots/9?')) return new Promise(resolve => { window.finishOldCorrection = resolve; });
                return window.bookingOverviewFixture(url);
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
        await page.waitForFunction(() => window.bookingContext.value.canWriteHotel(80));
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
        await page.waitForFunction(() => !window.bookingContext.value.loading && window.bookingContext.value.canWriteHotel(80) && window.bookingContext.value.canWriteHotel(82));
        const importSummary = page.locator('summary').filter({ hasText: '保存或导入真实快照' });
        if (await importSummary.evaluate(element => !element.parentElement.open)) await importSummary.click();
        await page.getByLabel('更正原快照ID（选填）').fill('9');
        await page.getByRole('button', { name: '按ID回读并载入更正', exact: true }).click();
        await page.waitForFunction(() => typeof window.finishOldCorrection === 'function');
        await page.getByLabel('快照酒店', { exact: true }).selectOption('82');
        await page.getByLabel('更正原快照ID（选填）').fill('9');
        await page.getByLabel('授权来源引用或文件指纹').fill('new-hotel-draft');
        await page.evaluate(response => window.finishOldCorrection(response), correctionFixture(12));
        await page.evaluate(() => Vue.nextTick());
        assert.equal(await page.getByLabel('快照酒店', { exact: true }).inputValue(), '82');
        assert.equal(await page.getByLabel('授权来源引用或文件指纹').inputValue(), 'new-hotel-draft');
        await page.getByLabel('快照酒店', { exact: true }).selectOption('80');
        await page.getByLabel('更正原快照ID（选填）').fill('9');
        await page.evaluate(() => { window.finishOldCorrection = null; });
        await page.getByRole('button', { name: '按ID回读并载入更正', exact: true }).click();
        await page.waitForFunction(() => typeof window.finishOldCorrection === 'function');
        await page.getByLabel('授权来源引用或文件指纹').fill('newer-same-hotel-draft');
        await page.getByLabel('在手间夜（必填，实际0可填写）').fill('99');
        await page.evaluate(response => window.finishOldCorrection(response), correctionFixture(3));
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
