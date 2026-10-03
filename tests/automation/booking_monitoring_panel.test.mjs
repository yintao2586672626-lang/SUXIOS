import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createHash, webcrypto } from 'node:crypto';
import { isReactive, reactive } from 'vue';

const source = readFileSync(new URL('../../public/components/system/booking-monitoring-panel.js', import.meta.url), 'utf8');
const h = (type, props, children) => children === undefined && (Array.isArray(props) || typeof props === 'string')
    ? { type, props: {}, children: props } : { type, props: props || {}, children };
const walk = node => node == null ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(walk) : [])];
const text = tree => walk(tree).filter(node => typeof node === 'string').join(' ') + walk(tree).map(node => typeof node.children === 'string' ? node.children : '').join(' ');
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const fixture = () => ({ code: 200, message: 'TEST-ONLY synthetic', data: {
    contract_version: 'booking_fixed_baseline_monitor.v1', tenant_id: 7, hotel_ids: [80], platform: 'ctrip', business_date: '2026-10-02',
    fixed_time: '09:00', horizon_days: 1, timezone: 'Asia/Shanghai', observation_time: '2026-10-02 09:00:00', baseline_time: '2026-10-01 09:00:00',
    boundaries: { external_write_count: 0 }, status: 'partial', ready_cell_count: 0, cell_count: 1,
    selectable_hotels: [{id:80,tenant_id:7,name:'TEST-ONLY酒店',can_execute:true}],
    room_types: [{ id: 1, hotel_id: 80, name: 'TEST-ONLY大床' }], cells: [{ hotel_id: 80, hotel_name: 'TEST-ONLY酒店', room_type_id: 1, room_type_name: 'TEST-ONLY大床',
        stay_date: '2026-10-03', lead_time_days: 1, status: 'partial', current: { status: 'ready', captured_at: '2026-10-02 09:00:00', quality_status: 'manual_confirmed', on_books_room_nights: 0 },
        baseline: { status: 'missing', captured_at: null }, net_pickup_24h_room_nights: null, room_revenue_delta_24h: null,
        same_lead_time_median_room_nights: null, delta_vs_same_lead_time_median: null, history_coverage: 0, history: [], data_gaps: ['baseline_slot_missing'] }],
} });
const submittedRow = () => ({ hotel_id: 80, room_type_id: 1, platform: 'ctrip', fact_scope: 'ota_channel', stay_date: '2026-10-03', captured_at: '2026-10-02 09:00:00',
    on_books_room_nights: 0, on_books_room_revenue: null, operator_attested: false, source_ref: 'TEST-ONLY-fixture' });
const receipt = (row = submittedRow(), id = 9) => ({ code: 200, data: { contract_version: 'booking_fixed_baseline_monitor.v1', tenant_id: 7, save_status: 'saved_readback_verified',
    readback_verified: true, external_write_count: 0, row_count: 1, snapshots: [{ ...row, id, tenant_id: 7, source_hotel_id: Number(row.hotel_id), contract_version: 'room_type_on_books_snapshot.v1',
        source_method: row.source_method || 'manual_file_import', source_ref_hash: createHash('sha256').update('on-books-source-v1|' + row.source_ref.trim()).digest('hex'),
        quality_status: row.operator_attested ? 'manual_confirmed' : 'unverified', readback_verified: 1, external_write_count: 0,
        captured_at: row.captured_at.replace('T', ' ').replace(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})$/, '$1:00').replace(/^(.*:\d{2})$/, '$1.000000'),
        room_type_name: 'TEST-ONLY大床', cumulative_cancel_room_nights: row.cumulative_cancel_room_nights ?? null,
        gross_booking_room_nights: row.gross_booking_room_nights ?? null, supersedes_snapshot_id: row.supersedes_snapshot_id || null,
        idempotency_key: 'b'.repeat(64), content_digest: 'a'.repeat(64) }] } });
const snapshotRead = saved => ({ code: 200, data: saved.data.snapshots[0] });

function component(request = async () => fixture(), canExecute = true, reactiveRuntime = false) {
    const window = { crypto: webcrypto };
    new Function('window', 'Vue', 'TextEncoder', source)(window, { h }, TextEncoder);
    const definition = window.SUXI_SYSTEM_COMPONENTS.BookingMonitoringPanel;
    const state = { ...definition.data(), hotels: [{ id: 80, name: 'TEST-ONLY酒店' }, { id: 82, name: 'TEST-ONLY酒店82' }],
        selectedHotelId: 80, request, canExecute, selectedIds: ['80'], businessDate: '2026-10-02', horizonDays: '1' };
    const ctx = reactiveRuntime ? reactive(state) : state;
    for (const [key, method] of Object.entries(definition.methods)) ctx[key] = method.bind(ctx);
    for (const [key, getter] of Object.entries(definition.computed)) Object.defineProperty(ctx, key, { get: () => getter.call(ctx) });
    ctx.hotelScope = {tenantId:7,hotelIds:[80],canExecuteByHotel:{80:true},permissionScopeKey:ctx.scopeKey,executionMetadataComplete:true};
    ctx.resetDrafts();
    return { definition, ctx };
}

test('hotel selection rejects the twenty-first hotel without dropping the existing twenty', () => {
    const { ctx, definition } = component();
    ctx.hotels = Array.from({ length: 21 }, (_, index) => ({ id: index + 80, name: `TEST-ONLY酒店${index}` }));
    ctx.hotelScope = { tenantId: 7, hotelIds: ctx.hotels.map(hotel => hotel.id) };
    for (const hotel of ctx.hotels.slice(1, 20)) ctx.toggleHotel(hotel.id, true);
    const before = [...ctx.selectedIds];
    const label = walk(definition.render.call(ctx)).find(node => node.type === 'label' && Array.isArray(node.children) && node.children.includes('TEST-ONLY酒店20'));
    const event = { target: { checked: true } };
    label.children[0].props.onChange(event);
    assert.equal(event.target.checked, false, 'rejected browser checkbox is restored immediately');
    assert.equal(ctx.selectedIds.length, 20);
    assert.deepEqual(ctx.selectedIds, before);
    assert.match(ctx.error, /最多.*20/);
});

test('same-tenant hotel choices come from the service permissions and unavailable scope stays single-hotel', async () => {
    const scoped = fixture();
    scoped.data.selectable_hotels = [{ id: 80, tenant_id: 7, name: 'TEST-ONLY酒店' }, { id: 82, tenant_id: 7, name: 'TEST-ONLY酒店82' }];
    const { ctx } = component(async () => scoped);
    ctx.hotels.push({ id: 81, name: 'TEST-ONLY其他租户，无前端tenant字段' });
    assert.deepEqual(ctx.normalizedHotels.map(hotel => hotel.id), [80]);
    ctx.toggleHotel(81, true);
    assert.deepEqual(ctx.selectedIds, ['80']);
    await ctx.load();
    assert.deepEqual(ctx.normalizedHotels.map(hotel => hotel.id), [80, 82]);
    ctx.toggleHotel(81, true);
    assert.deepEqual(ctx.selectedIds, ['80']);
    ctx.toggleHotel(82, true);
    assert.deepEqual(ctx.selectedIds, ['80', '82']);
    const failed = component(async () => ({ code: 403, message: 'TEST-ONLY tenant scope denied' })).ctx;
    await failed.load();
    assert.deepEqual(failed.selectedIds, ['80']);
    assert.deepEqual(failed.normalizedHotels.map(hotel => hotel.id), [80]);
});

test('malformed tenant hotel choices cannot authorize mixed tenants or clear the selected hotel', async () => {
    for (const choices of [[{ id: 80, tenant_id: 8 }], [{ id: 82, tenant_id: 7 }], 'malformed']) {
        const data = fixture(); data.data.selectable_hotels = choices;
        const { ctx } = component(async () => data);
        await ctx.load();
        assert.equal(ctx.overview, null);
        assert.deepEqual(ctx.selectedIds, ['80']);
        assert.deepEqual(ctx.normalizedHotels.map(hotel => hotel.id), [80]);
    }
});

test('global hotel changes reset tenant choices and lost permissions fall back to one actual hotel', async () => {
    const { ctx, definition } = component();
    ctx.hotels.push({ id: 81, name: 'TEST-ONLY other tenant' });
    ctx.hotelScope = { tenantId: 7, hotelIds: [80, 82] };
    ctx.request = async () => ({ code: 403, message: 'TEST-ONLY unavailable' });
    definition.watch.selectedHotelId.handler.call(ctx, 81, 80);
    assert.equal(ctx.hotelScope, null);
    assert.deepEqual(ctx.selectedIds, ['81']);
    assert.deepEqual(ctx.normalizedHotels.map(hotel => hotel.id), [81]);
    await Promise.resolve();
    ctx.hotels = [{ id: 82, name: 'TEST-ONLY newly remaining hotel' }];
    definition.watch.hotels.handler.call(ctx);
    assert.deepEqual(ctx.selectedIds, ['82']);
    assert.deepEqual(ctx.normalizedHotels.map(hotel => hotel.id), [82]);
});

test('a response with fifth-decimal saved content cannot validate a rounded four-decimal receipt', async () => {
    const row = { ...submittedRow(), on_books_room_nights: 0 };
    const saved = receipt(row); saved.data.snapshots[0].on_books_room_nights = 0.00001;
    const { ctx } = component(async () => saved);
    await ctx.saveRows([row]);
    assert.equal(ctx.receipt, null);
    assert.equal(ctx.pendingReceipt, null);
    assert.match(ctx.error, /回读.*不匹配/);
});

test('bulk overprecision in every metric is rejected before POST and positive tiny values never become confirmed zero', async () => {
    for (const field of ['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights']) {
        for (const value of [0.00001, '0.00001', '10.12345']) {
            let calls = 0;
            const { ctx } = component(async () => { calls++; return receipt(); });
            ctx.importText = JSON.stringify([{ ...submittedRow(), operator_attested: true, [field]: value }]);
            await ctx.saveImport();
            assert.equal(calls, 0, `${field}=${value}`);
            assert.match(ctx.error, /最多四位小数/);
            assert.equal(ctx.receipt, null);
        }
    }
});

test('each metric at the compatible maximum is posted and independently read without rounding', async () => {
    let saved;
    const posts = [];
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') { posts.push(JSON.parse(options.body)); saved = receipt(posts[0].rows[0]); return saved; }
        return url.includes('/snapshots/') ? snapshotRead(saved) : fixture();
    });
    const row = { ...submittedRow(), on_books_room_nights: '9999999999.9999', on_books_room_revenue: '9999999999.9999',
        cumulative_cancel_room_nights: '9999999999.9999', gross_booking_room_nights: '9999999999.9999' };
    await ctx.saveRows([row]);
    assert.equal(ctx.error, '');
    assert.match(ctx.notice, /精确回读1条/);
    for (const field of ['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights']) {
        assert.equal(posts[0].rows[0][field], '9999999999.9999');
        assert.equal(Number(ctx.receipt.snapshots[0][field]), 9999999999.9999);
    }
});

test('out-of-range form and file metrics show a field limit before any POST', async () => {
    for (const [field, formField, label] of [['on_books_room_nights', 'rooms', '在手间夜'], ['on_books_room_revenue', 'revenue', '房费'],
        ['cumulative_cancel_room_nights', 'cancelled', '累计取消间夜'], ['gross_booking_room_nights', 'gross', '累计毛预订间夜']]) {
        for (const kind of ['form', 'file']) {
            let calls = 0;
            const { ctx } = component(async () => { calls++; return receipt(); });
            if (kind === 'form') {
                Object.assign(ctx.form, { hotelId: '80', roomTypeId: '1', stayDate: '2026-10-03', capturedAt: '2026-10-02T09:00',
                    rooms: '1', sourceRef: 'TEST-ONLY limit source', [formField]: '10000000000' });
                await ctx.saveForm();
            } else {
                ctx.importText = JSON.stringify([{ ...submittedRow(), [field]: 1e10 }]);
                await ctx.saveImport();
            }
            assert.equal(calls, 0, `${field} ${kind}`);
            assert.ok(ctx.error.includes(label), `${field}: ${ctx.error}`);
            assert.match(ctx.error, /9,999,999,999\.9999/);
        }
    }
});

test('a receipt beyond a field limit cannot claim exact readback', async () => {
    const saved = receipt(); saved.data.snapshots[0].on_books_room_revenue = 1e14;
    const { ctx } = component(async () => saved);
    await ctx.saveRows([submittedRow()]);
    assert.equal(ctx.receipt, null);
    assert.equal(ctx.pendingReceipt, null);
    assert.match(ctx.error, /回读.*不匹配/);
});

test('single form submission is manual entry while a one-row file stays manual file import', async () => {
    for (const kind of ['form', 'file']) {
        let saved;
        const posts = [];
        const { ctx } = component(async (url, options) => {
            if (options?.method === 'POST') { posts.push(JSON.parse(options.body)); saved = receipt(posts[0].rows[0]); return saved; }
            return url.includes('/snapshots/') ? snapshotRead(saved) : fixture();
        });
        if (kind === 'form') {
            Object.assign(ctx.form, { hotelId: '80', roomTypeId: '1', stayDate: '2026-10-03', capturedAt: '2026-10-02T09:00', rooms: '0', sourceRef: 'TEST-ONLY manual source' });
            await ctx.saveForm();
        } else {
            ctx.importText = JSON.stringify([{ ...submittedRow(), source_method: 'authorized_api_export' }]);
            await ctx.saveImport();
        }
        const sourceMethod = kind === 'form' ? 'manual_entry' : 'manual_file_import';
        assert.equal(posts[0].rows[0].source_method, sourceMethod);
        assert.equal(ctx.receipt.snapshots[0].source_method, sourceMethod);
        assert.match(ctx.notice, /精确回读1条/);
    }
});

test('runtime render exposes exact scope, true zero, missing baseline, legal import and corrections', async () => {
    const { definition, ctx } = component();
    await ctx.load();
    const tree = definition.render.call(ctx);
    assert.ok(walk(tree).some(node => node.props?.['data-testid'] === 'booking-monitor-matrix'));
    assert.match(text(tree), /24h净新增/);
    assert.match(text(tree), /基线快照：未取得/);
    assert.match(text(tree), /未取得/);
    assert.ok(walk(tree).some(node => node.type === 'td' && node.children === '0'), 'real zero remains visible');
    assert.ok(walk(tree).some(node => node.type === 'textarea' && /未知字段保留null/.test(node.props.placeholder)));
    assert.ok(walk(tree).some(node => node.props?.['data-testid'] === 'booking-monitor-form'));
    assert.equal(ctx.form.capturedAt, '', 'fixed time must never be silently substituted for actual capture');
});

test('four-decimal metrics render nonzero differences while actual zero and missing stay distinct', async () => {
    const data = fixture();
    Object.assign(data.data.cells[0], { net_pickup_24h_room_nights: 0.0001, room_revenue_delta_24h: -0.0001,
        same_lead_time_median_room_nights: 1.0001, delta_vs_same_lead_time_median: 0.0001 });
    data.data.cells[0].current.on_books_room_nights = 1.0001;
    const { definition, ctx } = component(async () => data);
    await ctx.load();
    assert.equal(ctx.number(0.0001), '0.0001');
    assert.equal(ctx.number(-0.0001), '-0.0001');
    assert.equal(ctx.number(0), '0');
    assert.equal(ctx.number(null), '未取得');
    assert.equal(ctx.number(undefined), '未取得');
    const rendered = text(definition.render.call(ctx));
    assert.match(rendered, /1\.0001/);
    assert.match(rendered, /0\.0001/);
    assert.match(rendered, /-0\.0001/);
});

test('gross booking counter reset renders the repair reason with unknown pickup', async () => {
    const data = fixture();
    Object.assign(data.data.cells[0], { status: 'not_comparable', data_gaps: ['gross_booking_counter_reset_or_mismatch'],
        net_pickup_24h_room_nights: null, gross_pickup_24h_room_nights: null, room_revenue_delta_24h: null });
    const { definition, ctx } = component(async () => data);
    await ctx.load();
    const rendered = text(definition.render.call(ctx));
    assert.match(rendered, /累计毛预订.*重置.*重建基线/);
    assert.match(rendered, /未取得/);
    assert.doesNotMatch(rendered, /快照数据需核对/);
});

test('view-only state renders no save form and cannot issue writes', async () => {
    const requested = [];
    const { definition, ctx } = component(async (...args) => { requested.push(args); return fixture(); }, false);
    assert.ok(!walk(definition.render.call(ctx)).some(node => node.props?.['data-testid'] === 'booking-monitor-form'));
    await ctx.saveRows([submittedRow()]);
    assert.equal(requested.length, 0);
});

test('baseline readiness renders required capture and stay dates without presenting missing as zero',async()=>{
    const data=fixture();data.data.baseline_readiness={status:'incomplete',complete_cell_count:0,cell_count:1};
    data.data.cells[0].baseline_readiness={status:'incomplete',gaps:[{role:'history_week_1',stay_date:'2026-09-26',target_time:'2026-09-25 09:00:00',status:'missing'}]};
    const {definition,ctx}=component(async()=>data);await ctx.load();const tree=definition.render.call(ctx);
    assert.match(text(tree),/24小时及四周历史齐备：0\/1格/);
    assert.match(text(tree),/入住 2026-09-26；采集 2026-09-25 09:00:00：未取得/);
    assert.equal(ctx.statusText('not_due'),'尚未到采集时点');
});

test('wrong hotel, date, fixed-time or timezone responses fail closed and clear prior data', async () => {
    for (const change of [{ hotel_ids: [82] }, { business_date: '2026-10-01' }, { fixed_time: '08:00' }, { timezone: 'UTC' }]) {
        const invalid = fixture(); Object.assign(invalid.data, change);
        const { ctx } = component(async () => invalid);
        ctx.overview = fixture().data;
        await ctx.load();
        assert.equal(ctx.overview, null);
        assert.match(ctx.error, /范围不匹配/);
    }
});

test('late old-scope read response and failure cannot replace newer-scope result', async () => {
    const old = deferred();
    const current = deferred();
    let count = 0;
    const { ctx } = component(() => (++count === 1 ? old.promise : current.promise));
    const first = ctx.load();
    ctx.platform = 'meituan';
    const second = ctx.load();
    const latest = fixture(); latest.data.platform = 'meituan';
    current.resolve(latest); await second;
    old.reject(new Error('TEST-ONLY old failure')); await first;
    assert.equal(ctx.overview.platform, 'meituan');
    assert.equal(ctx.error, '');
    assert.equal(ctx.loading, false);
});

test('save reads exact receipt then reloads current data without overwriting receipt quality', async () => {
    const calls = [];
    const { ctx } = component(async (url, options) => { calls.push({ url, options }); return options?.method === 'POST' ? receipt() : url.includes('/snapshots/') ? snapshotRead(receipt()) : fixture(); });
    await ctx.saveRows([submittedRow()]);
    assert.equal(calls.length, 3);
    assert.deepEqual(JSON.parse(calls[0].options.body).rows, [{ ...submittedRow(), source_method: 'manual_file_import' }]);
    assert.equal(ctx.receipt.snapshots[0].quality_status, 'unverified');
    assert.equal(ctx.overview.business_date, '2026-10-02');
    assert.match(ctx.notice, /精确回读1条/);
    assert.equal(ctx.saving, false);
});

test('scope switch after POST keeps its original receipt and preserves the new draft without a second POST', async () => {
    const pending = deferred();const calls=[];const saved=receipt();
    const { ctx } = component(async(url,options)=>{
        calls.push({url,options});return options?.method==='POST'?pending.promise:snapshotRead(saved);
    });
    const saving = ctx.saveRows([submittedRow()]);
    while(!calls.some(call=>call.options?.method==='POST'))await new Promise(setImmediate);
    ctx.selectedIds = ['82']; ctx.resetDrafts();
    ctx.form.rooms='77';ctx.form.sourceRef='TEST-ONLY new hotel draft';
    await ctx.saveRows([{...submittedRow(),hotel_id:82}]);assert.equal(ctx.saving,true);
    pending.resolve(saved); await saving;
    assert.equal(ctx.receipt.snapshots[0].hotel_id,80);assert.equal(ctx.receiptScope.platform,'ctrip');
    assert.deepEqual(ctx.receiptScope.hotelIds,[80]);assert.equal(calls.filter(call=>call.options?.method==='POST').length,1);
    assert.match(calls.at(-1).url,/snapshots\/9\?hotel_id=80/);
    assert.equal(ctx.notice, '');
    assert.equal(ctx.form.hotelId, '82');
    assert.equal(ctx.form.sourceRef, 'TEST-ONLY new hotel draft');assert.equal(ctx.form.rooms,'77');
});

test('malformed or other-scope imports cannot issue requests', async () => {
    let calls = 0;
    const { ctx } = component(async () => { calls++; return receipt(); });
    ctx.importText = '{ broken'; await ctx.saveImport();
    assert.equal(calls, 0);
    ctx.importText = JSON.stringify([{ ...submittedRow(), hotel_id: 82 }]); await ctx.saveImport();
    assert.equal(calls, 0); assert.match(ctx.error, /当前选择不一致/);
    ctx.importText = JSON.stringify([{ ...submittedRow(), platform: 'meituan' }]); await ctx.saveImport();
    assert.equal(calls, 0);
});

test('missing amounts stay null and exact microsecond capture survives correction readback', async () => {
    const calls = [];
    const original = receipt({ ...submittedRow(), captured_at: '2026-10-02 09:00:00.100000' });
    let correction;
    const { ctx } = component(async (url, options) => {
        calls.push({ url, options });
        if (url.includes('/snapshots/9?')) return snapshotRead(original);
        if (options?.method === 'POST') { correction = receipt(JSON.parse(options.body).rows[0], 10); return correction; }
        if (url.includes('/snapshots/10?')) return snapshotRead(correction);
        return fixture();
    });
    ctx.form.correctionId = '9';
    await ctx.loadCorrection();
    assert.equal(ctx.form.correctionCapturedAt, '2026-10-02 09:00:00.100000');
    assert.equal(ctx.form.sourceRef, '');
    ctx.form.sourceRef = 'TEST-ONLY-correction';
    await ctx.saveForm();
    const body = JSON.parse(calls.find(call => call.options?.method === 'POST').options.body);
    assert.equal(body.rows[0].captured_at, '2026-10-02 09:00:00.100000');
    assert.equal(body.rows[0].on_books_room_nights, 0);
    assert.equal(body.rows[0].on_books_room_revenue, null);
    assert.equal(body.rows[0].supersedes_snapshot_id, 9);
    assert.match(ctx.notice, /精确回读1条/);
});

test('loaded deleted-room correction keeps a historical option and saves only its original scope', async () => {
    const data = fixture(); data.data.room_types = [];
    const original = receipt({ ...submittedRow(), captured_at: '2026-10-02 09:00:00.100000' });
    let saved;
    const calls = [];
    const { definition, ctx } = component(async (url, options) => {
        calls.push({ url, options });
        if (url.includes('/snapshots/9?')) return snapshotRead(original);
        if (options?.method === 'POST') { saved = receipt(JSON.parse(options.body).rows[0], 10); return saved; }
        if (url.includes('/snapshots/10?')) return snapshotRead(saved);
        return data;
    });
    await ctx.load();
    assert.equal(ctx.selectedRoomTypes.length, 0, 'history cells alone cannot authorize a new room option');
    ctx.form.correctionId = '9'; await ctx.loadCorrection();
    let tree = definition.render.call(ctx);
    let roomSelect = walk(tree).find(node => node.type === 'select' && node.props['aria-label'] === '房型（0保留汇总）');
    const historical = roomSelect.children.find(node => node.props.value === '1');
    assert.ok(historical, 'loaded retired room remains a selectable value');
    assert.match(historical.children, /TEST-ONLY大床.*历史房型/);
    assert.equal(roomSelect.props.value, '1');
    ctx.form.rooms = '1.0002'; ctx.form.sourceRef = 'TEST-ONLY retired room correction';
    await ctx.saveForm();
    assert.equal(ctx.error, '');
    const submitted = JSON.parse(calls.find(call => call.options?.method === 'POST').options.body).rows[0];
    assert.deepEqual([submitted.hotel_id, submitted.room_type_id, submitted.platform, submitted.stay_date, submitted.captured_at, submitted.supersedes_snapshot_id],
        [80, 1, 'ctrip', '2026-10-03', '2026-10-02 09:00:00.100000', 9]);
    assert.equal(submitted.on_books_room_nights, 1.0002);
    assert.equal(submitted.on_books_room_revenue, null);
    assert.equal(submitted.cumulative_cancel_room_nights, null);
    assert.equal(submitted.gross_booking_room_nights, null);
    assert.match(ctx.notice, /精确回读1条/);

    const loaded = { ...ctx.form };
    for (const change of [{ correctionId: '' }, { correctionId: '99' }, { hotelId: '82' }, { roomTypeId: '2' }]) {
        ctx.form = { ...loaded, ...change };
        assert.ok(!ctx.selectedRoomTypes.some(room => Number(room.id) === 1), JSON.stringify(change));
    }
    ctx.form = loaded;
    tree = definition.render.call(ctx);
    const hotelSelect = walk(tree).find(node => node.type === 'select' && node.props['aria-label'] === '快照酒店');
    hotelSelect.props.onChange({ target: { value: '82' } });
    assert.equal(ctx.form.correctionId, '');
    assert.ok(!ctx.selectedRoomTypes.some(room => Number(room.id) === 1));
    ctx.resetDrafts();
    assert.equal(ctx.selectedRoomTypes.length, 0);
});

test('four-decimal imported snapshots can be corrected without rounding any saved metric', async () => {
    let saved;
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') { saved = receipt(JSON.parse(options.body).rows[0], 10); return saved; }
        return url.includes('/snapshots/') ? snapshotRead(saved) : fixture();
    });
    ctx.correct(receipt({ ...submittedRow(), on_books_room_nights: 10.1234, on_books_room_revenue: 500.1234,
        cumulative_cancel_room_nights: 0.0001, gross_booking_room_nights: 12.3456 }).data.snapshots[0]);
    ctx.form.sourceRef = 'TEST-ONLY corrected source';
    await ctx.saveForm();
    assert.equal(ctx.error, '');
    assert.equal(ctx.receipt.snapshots[0].on_books_room_nights, 10.1234);
    assert.equal(ctx.receipt.snapshots[0].on_books_room_revenue, 500.1234);
    assert.equal(ctx.receipt.snapshots[0].cumulative_cancel_room_nights, 0.0001);
    assert.equal(ctx.receipt.snapshots[0].gross_booking_room_nights, 12.3456);
});

test('changed capture, numeric, source, scope or missing identity in save receipts cannot claim exact readback', async () => {
    for (const change of [{ captured_at: '2026-10-02 10:00:00.000000' }, { on_books_room_nights: 17 }, { on_books_room_nights: null }, { on_books_room_nights: false },
        { source_ref_hash: 'c'.repeat(64) }, { fact_scope: 'whole_hotel' }, { source_hotel_id: 82 }, { tenant_id: 8 },
        { content_digest: null }, { id: null }, { supersedes_snapshot_id: 7 }]) {
        const invalid = receipt(); Object.assign(invalid.data.snapshots[0], change);
        const { ctx } = component(async (url, options) => options?.method === 'POST' ? invalid : fixture());
        await ctx.saveRows([submittedRow()]);
        assert.equal(ctx.receipt, null, JSON.stringify(change));
        assert.equal(ctx.notice, '', JSON.stringify(change));
        assert.match(ctx.error, /回读.*不匹配|指纹|回读.*不完整/, JSON.stringify(change));
    }
});

test('independent snapshot readback preserves null, zero and local microseconds and rejects changed saved content', async () => {
    const submitted = { ...submittedRow(), captured_at: '2026-10-02T09:00', on_books_room_nights: '0.0000', on_books_room_revenue: '',
        cumulative_cancel_room_nights: '0', gross_booking_room_nights: null, operator_attested: true };
    const saved = receipt({ ...submitted, on_books_room_nights: 0, on_books_room_revenue: null, cumulative_cancel_room_nights: 0 });
    for (const change of [null, { content_digest: 'd'.repeat(64) }, { on_books_room_revenue: 99 }, { captured_at: '2026-10-02 09:00:00.000001' }]) {
        const reread = snapshotRead(saved); reread.data = { ...reread.data, ...(change || {}) };
        const { ctx } = component(async (url, options) => options?.method === 'POST' ? saved : url.includes('/snapshots/') ? reread : fixture());
        await ctx.saveRows([submitted]);
        if (change) { assert.equal(ctx.receipt, null); assert.match(ctx.error, /回读.*不匹配/); }
        else { assert.equal(ctx.error, ''); assert.match(ctx.notice, /精确回读1条/); assert.equal(ctx.receipt.snapshots[0].on_books_room_nights, 0); assert.equal(ctx.receipt.snapshots[0].on_books_room_revenue, null); }
    }
});

test('late correction read cannot overwrite edits made to the current correction draft', async () => {
    const old=deferred();const {ctx}=component(()=>old.promise,true,true);
    ctx.form.correctionId='9';const pending=ctx.loadCorrection();
    ctx.form.rooms='77';ctx.form.sourceRef='TEST-ONLY new source';ctx.form.attested=true;
    const edited=JSON.parse(JSON.stringify(ctx.form));
    old.resolve(snapshotRead(receipt()));await pending;
    assert.deepEqual(ctx.form,edited);assert.match(ctx.notice,/当前草稿已修改.*保留当前输入.*重新回读/);assert.equal(ctx.error,'');
});

test('late correction draft guidance cannot overwrite a newer notice, error, sequence or scope', async () => {
    for (const change of [ctx => { ctx.notice = 'TEST-ONLY newer notice'; }, ctx => { ctx.error = 'TEST-ONLY current error'; },
        ctx => { ctx.correctionReadSeq++; }, ctx => { ctx.platform = 'meituan'; }]) {
        const old = deferred(); const { ctx } = component(() => old.promise);
        ctx.form.correctionId = '9'; const reading = ctx.loadCorrection();
        ctx.form.rooms = '77'; change(ctx); const notice = ctx.notice, error = ctx.error;
        old.resolve(snapshotRead(receipt())); await reading;
        assert.equal(ctx.form.rooms, '77'); assert.equal(ctx.notice, notice); assert.equal(ctx.error, error);
    }
});

test('late failed correction replies preserve an edited draft without publishing success guidance', async () => {
    for (const failure of ['network', 'business', 'identity']) {
        const old = deferred(); const { ctx } = component(() => old.promise);
        ctx.form.correctionId = '9'; const reading = ctx.loadCorrection(); ctx.form.rooms = '77';
        if (failure === 'network') old.reject(new Error('TEST-ONLY delayed unavailable'));
        else if (failure === 'business') old.resolve({ code: 503, message: 'TEST-ONLY delayed service failure' });
        else old.resolve({ code: 200, data: { ...snapshotRead(receipt()).data, hotel_id: 82 } });
        await reading;
        assert.equal(ctx.form.rooms, '77'); assert.equal(ctx.notice, ''); assert.equal(ctx.error, '');
    }
});

test('late correction errors and earlier duplicate reads cannot replace the latest loaded draft', async () => {
    for(const changedId of [false,true]) {
        const old=deferred();let count=0;
        const latest=receipt(submittedRow(),changedId?10:9);
        const {ctx}=component(()=>++count===1?old.promise:Promise.resolve(snapshotRead(latest)));
        ctx.form.correctionId='9';const first=ctx.loadCorrection();
        if(changedId)ctx.form.correctionId='10';
        await ctx.loadCorrection();const current=structuredClone(ctx.form);const notice=ctx.notice;
        old.reject(new Error('TEST-ONLY obsolete correction unavailable'));await first;
        assert.deepEqual(ctx.form,current);assert.equal(ctx.notice,notice);assert.equal(ctx.error,'');
    }
});

test('late file success or failure preserves manual JSON draft edits', async () => {
    for(const failed of [false,true]) {
        const old=deferred();const {ctx}=component();
        const pending=ctx.readFile({target:{files:[{name:'TEST-ONLY old.json',size:1,text:()=>old.promise}]}});
        const current=JSON.stringify([submittedRow()]);ctx.importText=current;
        ctx.importedFileName='TEST-ONLY manually edited';ctx.error='TEST-ONLY current draft error';
        if(failed)old.reject(new Error('TEST-ONLY obsolete file unreadable'));else old.resolve('[]');
        await pending;
        assert.equal(ctx.importText,current);assert.equal(ctx.importedFileName,'TEST-ONLY manually edited');
        assert.equal(ctx.error,'TEST-ONLY current draft error');
    }
});

test('only the latest selected JSON file may replace the import draft', async () => {
    for(const failed of [false,true]) {
        const old=deferred();const {ctx}=component();
        const first=ctx.readFile({target:{files:[{name:'TEST-ONLY slow-A.json',size:1,text:()=>old.promise}]}});
        const current=JSON.stringify([submittedRow()]);
        await ctx.readFile({target:{files:[{name:'TEST-ONLY latest-B.json',size:1,text:async()=>current}]}});
        if(failed)old.reject(new Error('TEST-ONLY obsolete file error'));else old.resolve('[]');
        await first;
        assert.equal(ctx.importText,current);assert.equal(ctx.importedFileName,'TEST-ONLY latest-B.json');assert.equal(ctx.error,'');
    }
});

test('cancelled file reads release their own chooser value without clearing a newer selection', async () => {
    const {ctx}=component();const old=deferred();
    const firstFile={name:'TEST-ONLY cancelled.json',size:1,text:()=>old.promise};
    const target={files:[firstFile],value:'TEST-ONLY cancelled.json'};
    const first=ctx.readFile({target});ctx.resetDrafts();old.resolve('[]');await first;
    assert.equal(target.value,'');assert.equal(ctx.importText,'');

    const older=deferred();const latest=deferred();
    const oldFile={name:'TEST-ONLY A.json',size:1,text:()=>older.promise};
    const newFile={name:'TEST-ONLY B.json',size:1,text:()=>latest.promise};
    target.files=[oldFile];target.value=oldFile.name;const previous=ctx.readFile({target});
    target.files=[newFile];target.value=newFile.name;const next=ctx.readFile({target});
    older.resolve('[]');await previous;assert.equal(target.value,newFile.name);
    const current=JSON.stringify([submittedRow()]);latest.resolve(current);await next;
    assert.equal(target.value,'');assert.equal(ctx.importText,current);
});

test('matching overview envelopes reject foreign or malformed cells before rendering while retaining valid old responses', async () => {
    for(const change of [data=>{data.cells[0].hotel_id=82;},data=>{data.cells[0].stay_date='2026-10-04';},
        data=>{data.cells=[null];},data=>{data.cells[0].current='TEST-ONLY malformed slot';},data=>{data.cells[0].history=[null];},
        data=>{data.cells[0].baseline_readiness={gaps:[null]};},data=>{data.room_types=[null];}]) {
        const malformed=fixture();change(malformed.data);
        const {ctx,definition}=component(async()=>malformed);ctx.overview=fixture().data;
        await ctx.load();assert.equal(ctx.overview,null);assert.match(ctx.error,/范围不匹配|数据格式/);
        assert.doesNotThrow(()=>definition.render.call(ctx));
    }
    const {ctx,definition}=component();await ctx.load();
    assert.equal(ctx.error,'');assert.ok(ctx.overview);assert.doesNotThrow(()=>definition.render.call(ctx));
    assert.match(text(definition.render.call(ctx)),/旧响应尚未记录四周基线齐备状态/);
    const legacy=fixture();legacy.data.cells[0].current=null;legacy.data.cells[0].baseline=null;delete legacy.data.cells[0].history;
    ctx.request=async()=>legacy;await ctx.load();
    assert.equal(ctx.error,'');assert.ok(ctx.overview);assert.doesNotThrow(()=>definition.render.call(ctx));
    assert.match(text(definition.render.call(ctx)),/未取得/);
});

test('failed JSON file selection preserves the existing valid draft and file name', async () => {
    const failures = [
        { name: 'TEST-ONLY malformed.json', size: 1, text: async () => '{broken' },
        { name: 'TEST-ONLY oversized.json', size: 262145, text: async () => '[]' },
        { name: 'TEST-ONLY unreadable.json', size: 1, text: async () => { throw new Error('TEST-ONLY file read failed'); } },
    ];
    for (const file of failures) {
        const { ctx } = component();
        const draft = JSON.stringify([submittedRow()]);
        ctx.importText = draft; ctx.importedFileName = 'TEST-ONLY existing-good.json';
        const target = { files: [file], value: file.name };
        await ctx.readFile({ target });
        assert.equal(ctx.importText, draft, file.name);
        assert.equal(ctx.importedFileName, 'TEST-ONLY existing-good.json', file.name);
        assert.ok(ctx.error, file.name);
        assert.equal(target.value, '', file.name);
    }
});

test('unavailable independent reads report service failure rather than content mismatch and remain recoverable', async () => {
    const saved = receipt(submittedRow(), 41); let unavailable = true; let posts = 0;
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') { posts++; return saved; }
        if (url.includes('/snapshots/')) return unavailable ? { code: 503, message: 'TEST-ONLY unavailable', data: null } : snapshotRead(saved);
        return fixture();
    });
    await ctx.saveRows([submittedRow()]);
    assert.equal(ctx.receipt, null); assert.equal(ctx.pendingReceipt.data.snapshots[0].id, 41);
    assert.match(ctx.error, /暂时无法回读/); assert.doesNotMatch(ctx.error, /不匹配/);
    unavailable = false; await ctx.retryPendingReadback();
    assert.equal(ctx.pendingReceipt, null); assert.equal(ctx.receipt.snapshots[0].id, 41); assert.equal(posts, 1);
});

test('acknowledged saves retain pending IDs and recover with GET only against captured values while preserving edits', async () => {
    const row = submittedRow();
    const saved = receipt(row, 41); const canonical = structuredClone(saved);
    const calls = []; let failRead = true;
    const { ctx, definition } = component(async (url, options) => {
        calls.push({ url, options });
        if (options?.method === 'POST') return saved;
        if (url.includes('/snapshots/')) { if (failRead) throw new Error('TEST-ONLY unavailable readback'); return snapshotRead(canonical); }
        return fixture();
    });
    await ctx.saveRows([row]);
    assert.equal(ctx.receipt, null); assert.equal(ctx.notice, '');
    assert.equal(ctx.pendingReceipt.data.snapshots[0].id, 41);
    const pendingTree = definition.render.call(ctx);
    assert.match(text(pendingTree), /快照#41.*待独立回读/);
    assert.ok(walk(pendingTree).some(node => node.props?.['data-testid'] === 'booking-monitor-retry-readback'));
    assert.ok(!walk(pendingTree).some(node => node.props?.['data-testid'] === 'booking-monitor-save-receipt'));
    assert.doesNotMatch(text(pendingTree), /追加更正|已保存并精确回读/);
    const edited = { ...ctx.form, rooms: '77', sourceRef: 'TEST-ONLY next edit' };
    ctx.form = edited; ctx.importText = 'TEST-ONLY edited import'; ctx.importedFileName = 'TEST-ONLY edited.json';
    row.on_books_room_nights = 77; saved.data.snapshots[0].content_digest = 'c'.repeat(64);
    await ctx.saveRows([submittedRow()]);
    assert.equal(calls.filter(call => call.options?.method === 'POST').length, 1, 'unresolved pending IDs cannot be replaced by another POST');
    failRead = false;
    const retry = walk(definition.render.call(ctx)).find(node => node.props?.['data-testid'] === 'booking-monitor-retry-readback');
    await retry.props.onClick();
    assert.equal(ctx.pendingReceipt, null); assert.equal(ctx.error, '');
    assert.equal(ctx.receipt.snapshots[0].id, 41); assert.equal(ctx.receipt.snapshots[0].on_books_room_nights, 0);
    assert.equal(ctx.receipt.snapshots[0].content_digest, 'a'.repeat(64));
    assert.match(ctx.notice, /精确回读1条/);
    assert.deepEqual(ctx.form, edited); assert.equal(ctx.importText, 'TEST-ONLY edited import');
    assert.equal(ctx.importedFileName, 'TEST-ONLY edited.json');
    assert.equal(calls.filter(call => call.options?.method === 'POST').length, 1);
    assert.equal(calls.filter(call => call.url.includes('/snapshots/')).length, 2);
});

test('pending readback retries reject any changed identity, content or saved fingerprint and retain the acknowledged IDs', async () => {
    for (const change of [{ id: 42 }, { tenant_id: 8 }, { hotel_id: 82 }, { platform: 'meituan' }, { stay_date: '2026-10-04' },
        { captured_at: '2026-10-02 09:00:00.000001' }, { source_ref_hash: 'c'.repeat(64) }, { on_books_room_nights: 1 },
        { on_books_room_revenue: 0 }, { supersedes_snapshot_id: 1 }, { room_type_name: 'TEST-ONLY changed room' },
        { content_digest: 'c'.repeat(64) }, { idempotency_key: 'c'.repeat(64) }, { readback_verified: 0 }]) {
        const saved = receipt(submittedRow(), 41); let reads = 0; let posts = 0;
        const { ctx } = component(async (url, options) => {
            if (options?.method === 'POST') { posts++; return saved; }
            if (url.includes('/snapshots/')) {
                if (++reads === 1) throw new Error('TEST-ONLY first read unavailable');
                return { code: 200, data: { ...saved.data.snapshots[0], ...change } };
            }
            return fixture();
        });
        await ctx.saveRows([submittedRow()]);
        const pending = ctx.pendingReceipt;
        await ctx.retryPendingReadback();
        assert.equal(ctx.receipt, null, JSON.stringify(change)); assert.equal(ctx.notice, '', JSON.stringify(change));
        assert.equal(ctx.pendingReceipt, pending, JSON.stringify(change));
        assert.equal(ctx.pendingReceipt.data.snapshots[0].id, 41, JSON.stringify(change));
        assert.match(ctx.error, /回读.*不匹配/, JSON.stringify(change)); assert.equal(posts, 1);
    }
});

test('a partial batch read failure retains every pending ID until the complete batch passes GET-only recovery', async () => {
    const rows = Array.from({ length: 12 }, (_, index) => ({ ...submittedRow(), source_ref: `TEST-ONLY batch-${index}`, on_books_room_nights: index }));
    const saved = receipt(rows[0], 41);
    saved.data.row_count = rows.length; saved.data.snapshots = rows.map((row, index) => receipt(row, 41 + index).data.snapshots[0]);
    let failRead = true; let posts = 0; const requestedIds = [];
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') { posts++; return saved; }
        const match = /\/snapshots\/(\d+)\?/.exec(url);
        if (match) {
            const id = Number(match[1]); requestedIds.push(id);
            if (failRead && id === 52) throw new Error('TEST-ONLY last batch read unavailable');
            return { code: 200, data: saved.data.snapshots.find(snapshot => snapshot.id === id) };
        }
        return fixture();
    });
    ctx.importText = JSON.stringify(rows); const draft = ctx.importText;
    await ctx.saveImport();
    assert.equal(ctx.receipt, null); assert.equal(ctx.pendingReceipt.data.snapshots.length, 12);
    assert.deepEqual(ctx.pendingReceipt.data.snapshots.map(snapshot => snapshot.id), Array.from({ length: 12 }, (_, index) => 41 + index));
    assert.equal(ctx.importText, draft); assert.equal(ctx.notice, '');
    failRead = false; await ctx.retryPendingReadback();
    assert.equal(ctx.pendingReceipt, null); assert.equal(ctx.receipt.snapshots.length, 12);
    assert.match(ctx.notice, /精确回读12条/); assert.equal(ctx.importText, draft);
    assert.equal(posts, 1); assert.equal(requestedIds.length, 24);
});

test('scope changes during pending GET recovery retain the old receipt or its pending error without changing the new draft', async () => {
    for (const failed of [false, true]) {
        const saved = receipt(submittedRow(), 41); const old = deferred(); let reads = 0; const calls = [];
        const { ctx } = component(async (url, options) => {
            calls.push({ url, options });
            if (options?.method === 'POST') return saved;
            if (++reads === 1) throw new Error('TEST-ONLY initial read failed');
            return old.promise;
        });
        await ctx.saveRows([submittedRow()]);
        const retry = ctx.retryPendingReadback();
        ctx.selectedIds = ['82']; ctx.platform = 'meituan'; ctx.resetDrafts();
        const draft = structuredClone(ctx.form);
        if (failed) old.reject(new Error('TEST-ONLY obsolete recovery failed')); else old.resolve(snapshotRead(saved));
        await retry;
        if(failed){assert.equal(ctx.receipt,null);assert.equal(ctx.pendingReceipt.data.snapshots[0].id,41);assert.match(ctx.pendingReceipt.error,/obsolete recovery failed/);}
        else {assert.equal(ctx.pendingReceipt,null);assert.equal(ctx.receipt.snapshots[0].id,41);assert.deepEqual(ctx.receiptScope.hotelIds,[80]);}
        assert.equal(ctx.notice, ''); assert.equal(ctx.error, ''); assert.equal(ctx.saving, false);
        assert.deepEqual(ctx.form, draft);
        assert.equal(calls.filter(call => call.options?.method === 'POST').length, 1);
    }
});

test('unacknowledged or invalid save responses never create pending recovery IDs', async () => {
    for (const response of [null, { code: 422, message: 'TEST-ONLY rejected row' }, (() => {
        const invalid = receipt(); invalid.data.snapshots[0].hotel_id = 82; return invalid;
    })()]) {
        const { ctx } = component(async () => { if (response === null) throw new Error('TEST-ONLY POST disconnected'); return response; });
        await ctx.saveRows([submittedRow()]);
        assert.equal(ctx.receipt, null); assert.equal(ctx.pendingReceipt, null);
        assert.equal(ctx.notice, ''); assert.ok(ctx.error);
        assert.equal(Boolean(ctx.writeAttempt),response?.code!==422,'only explicit rejection can release the captured POST attempt');
    }
});

test('editing while an acknowledged GET is in flight preserves captured values and cannot unlock another POST',async()=>{
    const old=deferred();const saved=receipt();let posts=0;let reads=0;
    const {ctx}=component(async(url,options)=>{
        if(options?.method==='POST'){posts++;return saved;}
        if(url.includes('/snapshots/')){reads++;return old.promise;}
        return fixture();
    });
    const saving=ctx.saveRows([submittedRow()]);while(!reads)await new Promise(setImmediate);
    ctx.form.rooms='77';ctx.form.sourceRef='TEST-ONLY edited source';ctx.importText='TEST-ONLY new import';
    await ctx.saveRows([submittedRow()]);assert.equal(posts,1);assert.equal(ctx.saving,true);
    old.resolve(snapshotRead(saved));await saving;
    assert.equal(ctx.receipt.snapshots[0].on_books_room_nights,0);assert.equal(ctx.form.rooms,'77');
    assert.equal(ctx.form.sourceRef,'TEST-ONLY edited source');assert.equal(ctx.importText,'TEST-ONLY new import');
});

test('unknown POST retries its original raw rows after scope changes and edits, never the new draft',async()=>{
    const bodies=[];const saved=receipt();const {ctx,definition}=component(async(url,options)=>{
        if(options?.method==='POST'){bodies.push({body:options.body,hotelId:options.businessContext.hotelId});if(bodies.length===1)throw new TypeError('Failed to fetch');return saved;}
        return url.includes('/snapshots/')?snapshotRead(saved):fixture();
    });
    const row=submittedRow();await ctx.saveRows([row]);assert.equal(ctx.writeAttempt.status,'unconfirmed');
    ctx.selectedIds=['82'];ctx.platform='meituan';ctx.resetDrafts();ctx.form.rooms='77';ctx.importText='TEST-ONLY new import';row.on_books_room_nights=99;
    ctx.hotelScope={tenantId:7,hotelIds:[80,82],canExecuteByHotel:{80:true,82:true},permissionScopeKey:ctx.scopeKey,executionMetadataComplete:true};
    await ctx.saveRows([{...submittedRow(),hotel_id:82,platform:'meituan'}]);assert.equal(bodies.length,1);
    const retry=walk(definition.render.call(ctx)).find(node=>node.props?.['data-testid']==='booking-monitor-retry-submit');
    assert.ok(retry);await retry.props.onClick();
    assert.equal(bodies.length,2);assert.deepEqual(bodies[0],bodies[1]);assert.equal(JSON.parse(bodies[1].body).rows[0].on_books_room_nights,0);
    assert.equal(ctx.receipt.snapshots[0].hotel_id,80);assert.equal(ctx.receiptScope.platform,'ctrip');
    assert.equal(ctx.form.rooms,'77');assert.equal(ctx.importText,'TEST-ONLY new import');
});

test('a lost response followed by explicit retry rejection keeps the original unknown attempt',async()=>{
    let posts=0;const {ctx}=component(async()=>{posts++;if(posts===1)throw new TypeError('Failed to fetch');return {code:403,message:'TEST-ONLY permission revoked'};});
    await ctx.saveRows([submittedRow()]);const attempt=ctx.writeAttempt;await ctx.retryWriteAttempt();
    assert.equal(posts,2);assert.equal(ctx.writeAttempt,attempt);assert.equal(ctx.writeAttempt.status,'unconfirmed');
    assert.equal(ctx.pendingReceipt,null);assert.equal(ctx.receipt,null);assert.match(ctx.error,/permission revoked/);
});

for(const flag of [undefined,false,1,'true'])test('missing or non-boolean execution permission '+String(flag)+' keeps the view matrix but disables all writes',async()=>{
    const scoped=fixture();scoped.data.selectable_hotels[0].can_execute=flag;
    const calls=[];const {ctx,definition}=component(async(url,options)=>{calls.push({url,options});return scoped;});
    await ctx.load();assert.ok(ctx.overview);assert.deepEqual(ctx.normalizedHotels.map(hotel=>hotel.id),[80]);assert.deepEqual(ctx.writableHotels,[]);
    const tree=definition.render.call(ctx);assert.ok(walk(tree).some(node=>node.props?.['data-testid']==='booking-monitor-matrix'));
    assert.ok(!walk(tree).some(node=>node.props?.['data-testid']==='booking-monitor-form'));assert.match(text(tree),/执行权限/);
    await ctx.saveRows([submittedRow()]);assert.equal(calls.filter(call=>call.options?.method==='POST').length,0);assert.match(ctx.error,/执行权限/);
});

test('mixed view-only and executable hotels remain visible while form and whole-batch import require executable hotels',async()=>{
    const scoped=fixture();scoped.data.hotel_ids=[80,82];
    scoped.data.selectable_hotels=[{id:80,tenant_id:7,name:'TEST-ONLY酒店',can_execute:false},{id:82,tenant_id:7,name:'TEST-ONLY酒店82',can_execute:true}];
    scoped.data.cells.push({...scoped.data.cells[0],hotel_id:82,hotel_name:'TEST-ONLY酒店82',room_type_id:2});
    const calls=[];let saved;const {ctx,definition}=component(async(url,options)=>{
        calls.push({url,options});if(options?.method==='POST'){saved=receipt(JSON.parse(options.body).rows[0]);return saved;}
        return url.includes('/snapshots/')?snapshotRead(saved):scoped;
    });
    ctx.selectedIds=['80','82'];await ctx.load();
    assert.deepEqual(ctx.normalizedHotels.map(hotel=>hotel.id),[80,82]);assert.deepEqual(ctx.writableHotels.map(hotel=>hotel.id),[82]);
    const tree=definition.render.call(ctx);const select=walk(tree).find(node=>node.type==='select'&&node.props?.['aria-label']==='快照酒店');
    assert.deepEqual(select.children.filter(node=>node.type==='option'&&node.props.value).map(node=>node.props.value),['82']);
    assert.match(text(tree),/TEST-ONLY酒店/);assert.match(text(tree),/TEST-ONLY酒店82/);
    ctx.importText=JSON.stringify([submittedRow(),{...submittedRow(),hotel_id:82}]);await ctx.saveImport();
    assert.equal(calls.filter(call=>call.options?.method==='POST').length,0);assert.match(ctx.error,/执行权限/);
    await ctx.saveRows([{...submittedRow(),hotel_id:82}]);assert.equal(calls.filter(call=>call.options?.method==='POST').length,1);
    assert.equal(calls.find(call=>call.options?.method==='POST').options.businessContext.hotelId,82);
    assert.equal(ctx.receipt.snapshots[0].hotel_id,82);
});

test('legacy overview without execution metadata stays viewable but cannot authorize a write',async()=>{
    const scoped=fixture();delete scoped.data.selectable_hotels;let posts=0;
    const {ctx,definition}=component(async(url,options)=>{if(options?.method==='POST')posts++;return scoped;});
    await ctx.load();assert.ok(ctx.overview);assert.deepEqual(ctx.writableHotels,[]);
    assert.ok(walk(definition.render.call(ctx)).some(node=>node.props?.['data-testid']==='booking-monitor-matrix'));
    await ctx.saveRows([submittedRow()]);assert.equal(posts,0);assert.match(ctx.error,/执行权限/);
});

test('an acknowledged readback remains GET-only when global execution permission is lost',async()=>{
    const saved=receipt();let fail=true;let posts=0;let reads=0;
    const {ctx}=component(async(url,options)=>{
        if(options?.method==='POST'){posts++;return saved;}
        if(url.includes('/snapshots/')){reads++;if(fail)throw new Error('TEST-ONLY first GET failed');return snapshotRead(saved);}
        return fixture();
    });
    await ctx.saveRows([submittedRow()]);ctx.canExecute=false;fail=false;await ctx.retryPendingReadback();
    assert.equal(posts,1);assert.equal(reads,2);assert.equal(ctx.pendingReceipt,null);assert.equal(ctx.receipt.snapshots[0].id,9);
    assert.deepEqual(ctx.writableHotels,[]);
});

test('Vue reactive form save posts and independently verifies the mounted write attempt', async () => {
    const saved = receipt({ ...submittedRow(), source_method: 'manual_entry' });
    let posts = 0; let reads = 0;
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') { posts++; return saved; }
        if (url.includes('/snapshots/')) { reads++; return snapshotRead(saved); }
        return fixture();
    }, true, true);
    Object.assign(ctx.form, { hotelId: '80', roomTypeId: '1', stayDate: '2026-10-03', capturedAt: '2026-10-02T09:00:00',
        rooms: '0', sourceRef: 'TEST-ONLY-fixture' });
    assert.equal(isReactive(ctx), true); assert.equal(isReactive(ctx.form), true);
    await ctx.saveForm();
    assert.equal(posts, 1); assert.equal(reads, 1); assert.equal(ctx.saving, false);
    assert.equal(ctx.writeAttempt, null); assert.equal(ctx.pendingReceipt, null);
    assert.equal(ctx.receipt.snapshots[0].id, 9); assert.equal(ctx.receiptScope.platform, 'ctrip');
});

test('Vue reactive pending receipt retains attempt identity and retries only the original GET after scope edits', async () => {
    const saved = receipt(); let fail = true; let posts = 0; let reads = 0;
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') { posts++; return saved; }
        if (url.includes('/snapshots/')) { reads++; if (fail) throw new Error('TEST-ONLY reactive GET failed'); return snapshotRead(saved); }
        return fixture();
    }, true, true);
    await ctx.saveRows([submittedRow()]);
    assert.equal(posts, 1); assert.equal(ctx.saving, false);
    assert.equal(isReactive(ctx.writeAttempt), true); assert.equal(isReactive(ctx.pendingReceipt), true);
    assert.equal(ctx.pendingReceipt.attempt, ctx.writeAttempt); assert.equal(ctx.writeAttempt.status, 'readback_failed');
    ctx.platform = 'meituan'; ctx.resetDrafts(); ctx.form.rooms = '77';
    fail = false; await ctx.retryPendingReadback();
    assert.equal(posts, 1); assert.equal(reads, 2); assert.equal(ctx.saving, false);
    assert.equal(ctx.pendingReceipt, null); assert.equal(ctx.writeAttempt, null);
    assert.equal(ctx.receiptScope.platform, 'ctrip'); assert.equal(ctx.form.rooms, '77');
});

test('Vue reactive unknown POST recovery replays the captured body and clears the mounted attempt', async () => {
    const saved = receipt(); const bodies = [];
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') {
            bodies.push(options.body); if (bodies.length === 1) throw new TypeError('Failed to fetch'); return saved;
        }
        return url.includes('/snapshots/') ? snapshotRead(saved) : fixture();
    }, true, true);
    await ctx.saveRows([submittedRow()]);
    assert.equal(bodies.length, 1); assert.equal(ctx.saving, false); assert.equal(ctx.writeAttempt.status, 'unconfirmed');
    ctx.form.rooms = '77'; await ctx.retryWriteAttempt();
    assert.equal(bodies.length, 2); assert.equal(bodies[1], bodies[0]);
    assert.equal(ctx.saving, false); assert.equal(ctx.writeAttempt, null); assert.equal(ctx.pendingReceipt, null);
    assert.equal(ctx.receipt.snapshots[0].id, 9); assert.equal(ctx.form.rooms, '77');
});

test('changing snapshot hotel or room clears prior measurements, source and attestation', () => {
    for (const label of ['快照酒店', '房型（0保留汇总）']) {
        const { definition, ctx } = component();
        ctx.selectedIds = ['80', '82']; ctx.overview = fixture().data;
        ctx.hotelScope = {tenantId:7,hotelIds:[80,82],canExecuteByHotel:{80:true,82:true},permissionScopeKey:ctx.scopeKey,executionMetadataComplete:true};
        ctx.correct(receipt({ ...submittedRow(), on_books_room_nights: 10 }).data.snapshots[0]);
        ctx.form.sourceRef = 'TEST-ONLY original source'; ctx.form.attested = true;
        const control = walk(definition.render.call(ctx)).find(node => node.type === 'select' && node.props['aria-label'] === label);
        control.props.onChange({ target: { value: label === '快照酒店' ? '82' : '2' } });
        assert.equal(ctx.form.rooms, ''); assert.equal(ctx.form.sourceRef, ''); assert.equal(ctx.form.attested, false);
        assert.equal(ctx.form.correctionId, ''); assert.equal(ctx.form.correctionCapturedAt, '');
        assert.equal(ctx.form.hotelId, label === '快照酒店' ? '82' : '80');
        assert.equal(ctx.form.roomTypeId, label === '快照酒店' ? '0' : '2');
    }
});

test('editing a loaded correction ID requires a fresh read before saving', async () => {
    let calls = 0;
    const { definition, ctx } = component(async () => { calls++; return fixture(); });
    ctx.correct(receipt().data.snapshots[0]); ctx.form.sourceRef = 'TEST-ONLY new source';
    const input = walk(definition.render.call(ctx)).find(node => node.type === 'input' && node.props.value === '9');
    input.props.onInput({ target: { value: '10' } });
    await ctx.saveForm();
    assert.equal(calls, 0); assert.match(ctx.error, /回读.*更正/);
});
