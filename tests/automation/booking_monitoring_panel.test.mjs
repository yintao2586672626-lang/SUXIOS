import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createHash, webcrypto } from 'node:crypto';

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
    room_types: [{ id: 1, hotel_id: 80, name: 'TEST-ONLY大床' }], cells: [{ hotel_id: 80, hotel_name: 'TEST-ONLY酒店', room_type_id: 1, room_type_name: 'TEST-ONLY大床',
        stay_date: '2026-10-03', lead_time_days: 1, status: 'partial', current: { status: 'ready', captured_at: '2026-10-02 09:00:00', quality_status: 'manual_confirmed', on_books_room_nights: 0 },
        baseline: { status: 'missing', captured_at: null }, net_pickup_24h_room_nights: null, room_revenue_delta_24h: null,
        same_lead_time_median_room_nights: null, delta_vs_same_lead_time_median: null, history_coverage: 0, history: [], data_gaps: ['baseline_slot_missing'] }],
} });
const submittedRow = () => ({ hotel_id: 80, room_type_id: 1, platform: 'ctrip', fact_scope: 'ota_channel', stay_date: '2026-10-03', captured_at: '2026-10-02 09:00:00',
    on_books_room_nights: 0, on_books_room_revenue: null, operator_attested: false, source_ref: 'TEST-ONLY-fixture' });
const receipt = (row = submittedRow(), id = 9) => ({ code: 200, data: { contract_version: 'booking_fixed_baseline_monitor.v1', tenant_id: 7, save_status: 'saved_readback_verified',
    readback_verified: true, external_write_count: 0, row_count: 1, snapshots: [{ ...row, id, tenant_id: 7, source_hotel_id: Number(row.hotel_id), contract_version: 'room_type_on_books_snapshot.v1',
        source_method: 'manual_file_import', source_ref_hash: createHash('sha256').update('on-books-source-v1|' + row.source_ref.trim()).digest('hex'),
        quality_status: row.operator_attested ? 'manual_confirmed' : 'unverified', readback_verified: 1, external_write_count: 0,
        captured_at: row.captured_at.replace('T', ' ').replace(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})$/, '$1:00').replace(/^(.*:\d{2})$/, '$1.000000'),
        room_type_name: 'TEST-ONLY大床', cumulative_cancel_room_nights: row.cumulative_cancel_room_nights ?? null,
        gross_booking_room_nights: row.gross_booking_room_nights ?? null, supersedes_snapshot_id: row.supersedes_snapshot_id || null,
        idempotency_key: 'b'.repeat(64), content_digest: 'a'.repeat(64) }] } });
const snapshotRead = saved => ({ code: 200, data: saved.data.snapshots[0] });

function component(request = async () => fixture(), canExecute = true) {
    const window = { crypto: webcrypto };
    new Function('window', 'Vue', 'TextEncoder', source)(window, { h }, TextEncoder);
    const definition = window.SUXI_SYSTEM_COMPONENTS.BookingMonitoringPanel;
    const ctx = { ...definition.data(), hotels: [{ id: 80, name: 'TEST-ONLY酒店' }, { id: 82, name: 'TEST-ONLY酒店82' }],
        selectedHotelId: 80, request, canExecute, selectedIds: ['80'], businessDate: '2026-10-02', horizonDays: '1' };
    for (const [key, method] of Object.entries(definition.methods)) ctx[key] = method.bind(ctx);
    for (const [key, getter] of Object.entries(definition.computed)) Object.defineProperty(ctx, key, { get: () => getter.call(ctx) });
    ctx.resetDrafts();
    return { definition, ctx };
}

test('latest file selection owns the import draft even when an older read succeeds or fails late', async () => {
    for (const failOld of [false, true]) {
        const old = deferred();
        const { ctx } = component();
        const event = (name, read) => ({ target: { files: [{ name, size: 10, text: read }], value: name } });
        const pending = ctx.readFile(event('old.json', () => old.promise));
        const latest = JSON.stringify({ rows: [submittedRow()], marker: 'latest' });
        await ctx.readFile(event('latest.json', async () => latest));
        if (failOld) old.reject(new Error('old file failure')); else old.resolve('{"marker":"old"}');
        await pending;
        assert.equal(ctx.importText, latest);
        assert.equal(ctx.importedFileName, 'latest.json');
        assert.equal(ctx.error, '');
    }
});

test('returning to the same scope cannot revive a file selected before the draft reset', async () => {
    const old = deferred();
    const { ctx } = component();
    const pending = ctx.readFile({ target: { files: [{ name: 'old.json', size: 10, text: () => old.promise }], value: 'old.json' } });
    ctx.selectedIds = ['82']; ctx.resetDrafts();
    ctx.selectedIds = ['80']; ctx.resetDrafts();
    old.resolve('{"marker":"old"}'); await pending;
    assert.equal(ctx.importText, '');
    assert.equal(ctx.importedFileName, '');
    assert.equal(ctx.error, '');
});

test('correction response and errors cannot overwrite a different editing hotel within a multi-hotel scope', async () => {
    for (const failOld of [false, true]) {
        const old = deferred();
        const { ctx } = component(() => old.promise);
        ctx.selectedIds = ['80', '82']; ctx.form.correctionId = '9';
        const pending = ctx.loadCorrection();
        ctx.form.hotelId = '82'; ctx.form.sourceRef = 'new-hotel-draft';
        if (failOld) old.reject(new Error('old hotel failure')); else old.resolve(snapshotRead(receipt()));
        await pending;
        assert.equal(ctx.form.hotelId, '82');
        assert.equal(ctx.form.sourceRef, 'new-hotel-draft');
        assert.equal(ctx.error, '');
        assert.equal(ctx.notice, '');
    }
});

test('latest correction read owns the draft and is not cleared by an older request error', async () => {
    const old = deferred();
    const { ctx } = component(url => url.includes('/snapshots/9?') ? old.promise : Promise.resolve(snapshotRead(receipt(submittedRow(), 10))));
    ctx.form.correctionId = '9';
    const pending = ctx.loadCorrection();
    ctx.form.correctionId = '10'; await ctx.loadCorrection();
    const latestForm = structuredClone(ctx.form), latestNotice = ctx.notice;
    old.reject(new Error('old correction failure')); await pending;
    assert.deepEqual(ctx.form, latestForm);
    assert.equal(ctx.notice, latestNotice);
    assert.equal(ctx.error, '');
});

test('correction reads preserve newer fields in the same hotel draft on both success and failure', async () => {
    for (const failOld of [false, true]) {
        const old = deferred();
        const { ctx } = component(() => old.promise);
        ctx.form.correctionId = '9';
        const pending = ctx.loadCorrection();
        ctx.form.sourceRef = 'new-source'; ctx.form.rooms = '99';
        const draft = structuredClone(ctx.form);
        if (failOld) old.reject(new Error('obsolete error')); else old.resolve(snapshotRead(receipt()));
        await pending;
        assert.deepEqual(ctx.form, draft);
        assert.equal(ctx.error, '');
        if (!failOld) assert.match(ctx.notice, /草稿/);
    }
});

test('a pending file read cannot replace or clear manually edited import text', async () => {
    for (const failOld of [false, true]) {
        const old = deferred();
        const { ctx } = component();
        const pending = ctx.readFile({ target: { files: [{ name: 'pending.json', size: 10, text: () => old.promise }], value: 'pending.json' } });
        ctx.importText = '{"marker":"manual-latest"}';
        if (failOld) old.reject(new Error('obsolete error')); else old.resolve('{"marker":"old"}');
        await pending;
        assert.equal(ctx.importText, '{"marker":"manual-latest"}');
        assert.equal(ctx.error, '');
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

test('view-only state renders no save form and cannot issue writes', async () => {
    const requested = [];
    const { definition, ctx } = component(async (...args) => { requested.push(args); return fixture(); }, false);
    assert.ok(!walk(definition.render.call(ctx)).some(node => node.props?.['data-testid'] === 'booking-monitor-form'));
    await ctx.saveRows([submittedRow()]);
    assert.equal(requested.length, 0);
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
    assert.deepEqual(JSON.parse(calls[0].options.body).rows, [submittedRow()]);
    assert.equal(ctx.receipt.snapshots[0].quality_status, 'unverified');
    assert.equal(ctx.overview.business_date, '2026-10-02');
    assert.match(ctx.notice, /精确回读1条/);
    assert.equal(ctx.saving, false);
});

test('scope switch during save discards old receipt and preserves new blank draft', async () => {
    const pending = deferred();
    const { ctx } = component(() => pending.promise);
    const saving = ctx.saveRows([submittedRow()]);
    ctx.selectedIds = ['82']; ctx.resetDrafts();
    pending.resolve(receipt()); await saving;
    assert.equal(ctx.receipt, null);
    assert.equal(ctx.notice, '');
    assert.equal(ctx.form.hotelId, '82');
    assert.equal(ctx.form.sourceRef, '');
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

test('late correction reads cannot overwrite a changed hotel, room, draft or newer same-ID read', async () => {
    for (const change of [ctx => { ctx.form.hotelId = '82'; }, ctx => { ctx.form.roomTypeId = '2'; },
        ctx => { ctx.form.sourceRef = 'TEST-ONLY newly edited source'; }, ctx => { ctx.resetDrafts(); ctx.form.correctionId = '9'; }]) {
        const pending = deferred();
        const { ctx } = component(() => pending.promise);
        ctx.selectedIds = ['80', '82']; ctx.form.correctionId = '9';
        const reading = ctx.loadCorrection();
        change(ctx);
        const current = { ...ctx.form };
        pending.resolve(snapshotRead(receipt())); await reading;
        assert.deepEqual(ctx.form, current, 'the in-flight read must preserve the newer draft');
    }
    const first = deferred(), second = deferred(); let calls = 0;
    const { ctx } = component(() => ++calls === 1 ? first.promise : second.promise);
    ctx.form.correctionId = '9';
    const oldRead = ctx.loadCorrection(), newRead = ctx.loadCorrection();
    second.resolve(snapshotRead(receipt({ ...submittedRow(), on_books_room_nights: 12 }))); await newRead;
    first.resolve(snapshotRead(receipt({ ...submittedRow(), on_books_room_nights: 4 }))); await oldRead;
    assert.equal(ctx.form.rooms, '12');
});

test('file reads preserve the newest file, manual edit and reset draft when old reads finish late', async () => {
    const fileEvent = (pending, name) => ({ target: { value: name, files: [{ size: 20, name, text: () => pending.promise }] } });
    const first = deferred(), second = deferred();
    const { ctx } = component();
    const oldRead = ctx.readFile(fileEvent(first, 'old.json'));
    const newRead = ctx.readFile(fileEvent(second, 'new.json'));
    second.resolve('[{"TEST-ONLY":"new"}]'); await newRead;
    first.resolve('[{"TEST-ONLY":"old"}]'); await oldRead;
    assert.equal(ctx.importedFileName, 'new.json');
    assert.equal(ctx.importText, '[{"TEST-ONLY":"new"}]');
    for (const change of [current => { current.importText = '[{"TEST-ONLY":"manual"}]'; }, current => current.resetDrafts()]) {
        const pending = deferred(); const reading = ctx.readFile(fileEvent(pending, 'late.json'));
        change(ctx); const expected = ctx.importText;
        pending.resolve('[{"TEST-ONLY":"late"}]'); await reading;
        assert.equal(ctx.importText, expected);
    }
});

test('changing snapshot hotel or room clears prior measurements, source and attestation', () => {
    for (const label of ['快照酒店', '房型（0保留汇总）']) {
        const { definition, ctx } = component();
        ctx.selectedIds = ['80', '82']; ctx.overview = fixture().data;
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

test('overflowing manual numbers cannot serialize into a missing amount and issue a save', async () => {
    let calls = 0;
    const { ctx } = component(async () => { calls++; return fixture(); });
    Object.assign(ctx.form, { hotelId: '80', roomTypeId: '1', capturedAt: '2026-10-02T09:00', rooms: '1', sourceRef: 'TEST-ONLY source', revenue: '9'.repeat(400) });
    await ctx.saveForm();
    assert.equal(calls, 0); assert.match(ctx.error, /非负数|数值/);
});

test('snapshot exact readback rejects fractional drift and empty string substituted for null', async () => {
    for (const change of [{ on_books_room_nights: 0.00001 }, { on_books_room_revenue: '' }]) {
        const invalid = receipt(); Object.assign(invalid.data.snapshots[0], change);
        const { ctx } = component(async (url, options) => options?.method === 'POST' ? invalid : url.includes('/snapshots/') ? snapshotRead(invalid) : fixture());
        await ctx.saveRows([submittedRow()]);
        assert.equal(ctx.receipt, null); assert.match(ctx.error, /回读.*不匹配/);
    }
});

test('nested overview hotels and dates must match the selected scope', async () => {
    for (const change of [{ hotel_id: 82 }, { stay_date: '2026-10-04' }, { lead_time_days: 2 }]) {
        const invalid = fixture(); Object.assign(invalid.data.cells[0], change);
        const { ctx } = component(async () => invalid);
        await ctx.load();
        assert.equal(ctx.overview, null); assert.match(ctx.error, /范围不匹配/);
    }
});

test('delayed authorized hotel list follows the current main-page hotel instead of its first item', async () => {
    const { definition, ctx } = component(async () => {
        const response = fixture(); response.data.hotel_ids = [82]; response.data.cells[0].hotel_id = 82;
        response.data.room_types[0].hotel_id = 82; return response;
    });
    ctx.selectedIds = []; ctx.hotels = []; ctx.selectedHotelId = 82;
    definition.watch.selectedHotelId.handler.call(ctx, 82, undefined);
    ctx.hotels = [{ id: 80, name: 'TEST-ONLY first hotel' }, { id: 82, name: 'TEST-ONLY selected hotel' }];
    definition.watch.hotels.handler.call(ctx);
    assert.deepEqual(ctx.selectedIds, ['82']); assert.equal(ctx.form.hotelId, '82');
    await ctx.load(); assert.equal(ctx.overview.hotel_ids[0], 82);
});

test('independent readback failure retains the draft and a retry can verify the same saved snapshot', async () => {
    let failReadback = true, posts = 0;
    const saved = receipt();
    const { ctx } = component(async (url, options) => {
        if (options?.method === 'POST') { posts++; return saved; }
        if (url.includes('/snapshots/')) {
            if (failReadback) throw new Error('TEST-ONLY temporary read failure');
            return snapshotRead(saved);
        }
        return fixture();
    });
    Object.assign(ctx.form, { roomTypeId: '1', capturedAt: '2026-10-02T09:00', rooms: '0', sourceRef: 'TEST-ONLY-fixture' });
    const draft = { ...ctx.form };
    await ctx.saveForm();
    assert.equal(ctx.receipt, null); assert.equal(ctx.saving, false); assert.deepEqual(ctx.form, draft);
    assert.match(ctx.error, /保存已响应.*精确回读未通过/);
    failReadback = false; await ctx.saveForm();
    assert.equal(posts, 2); assert.equal(ctx.error, ''); assert.equal(ctx.receipt.snapshots[0].id, 9);
    assert.match(ctx.notice, /精确回读1条/);
});
