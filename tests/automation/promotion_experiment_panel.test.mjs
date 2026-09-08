import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';

const source = readFileSync(new URL('../../public/components/revenue/promotion-experiment-panel.js', import.meta.url), 'utf8');
const walk = v => [v, ...(Array.isArray(v?.children) ? v.children.flatMap(walk) : [])];
function mount(request) {
    const unmounts = [];
    const window = { Vue: { ...Vue, onBeforeUnmount(callback) { unmounts.push(callback); } }, crypto: { randomUUID } };
    vm.runInNewContext(source, { window, URLSearchParams, setTimeout, JSON });
    const props = Vue.reactive({ hotelId: 701, request });
    const render = window.SUXI_SYSTEM_COMPONENTS.PromotionExperimentPanel.setup(props);
    const by = id => walk(render()).find(v => v?.props?.['data-testid'] === id);
    const fill = (id, value) => by('pe-' + id).props.onInput({ target: { value } });
    for (const [key, value] of Object.entries({ platform_store_id: 'SYNTHETIC', period_start: '2026-08-10', period_end: '2026-08-11' })) fill(key, value);
    return { props, by, fill, unmount: () => unmounts.forEach(callback => callback()) };
}
const deferred = () => { let resolve, reject; const promise = new Promise((r, j) => { resolve = r; reject = j; }); return { resolve, reject, promise }; };
const scope = store => ({ tenant_id: 700, system_hotel_id: 701, platform: 'ctrip', platform_store_id: store, period_start: '2026-08-10', period_end: '2026-08-11' });

function pendingImport(m, target = { value: 'SYNTHETIC selected file' }) {
    const reading = deferred();
    target.files = [{ size: 100, text: () => reading.promise }];
    const done = m.by('pe-import-file').props.onChange({ target });
    return { ...reading, done, target };
}
const importJson = campaign => JSON.stringify([{ ...scope('SYNTHETIC'), campaign_id: campaign, spend: 123 }]);
function savedVersion(input = { records: [] }) {
    const s = scope('SYNTHETIC');
    return { code: 200, data: { scope: s, input: { ...input, scope: s }, experiment_key: 'SAVED', version_no: 1, readback_status: 'exact',
        result: { schema_version: 'promotion_experiment.v1', scope: s,
            accounting: { scope: s, coverage: { amount_label: 'SYNTHETIC', missing_dates: [] }, maturity: {}, totals: {}, platform_attribution: {}, book_return: {}, deduplication: {}, calculation: [], missing_fields: [] },
            incrementality: {}, next_experiment: [] } } };
}

test('pending import cannot alter a same-scope history read or its restored experiment', async t => {
    for (const completion of ['during read', 'after read']) await t.test(completion, async () => {
        const read = deferred(); let submitted;
        const m = mount(async (path, options) => {
            if (options.method === 'POST') { submitted = JSON.parse(options.body); return { code: 503, message: 'SYNTHETIC capture only' }; }
            if (path.includes('/versions/')) return read.promise;
            return { code: 200, data: { scope: scope('SYNTHETIC'), items: [{ id: 1, name: 'SAVED', version_no: 1 }] } };
        });
        await m.by('pe-history').props.onClick();
        const old = pendingImport(m);
        const restoring = m.by('pe-read-1').props.onClick();
        if (completion === 'during read') { old.resolve(importJson('OLD')); await old.done; assert.equal(m.by('pe-save').props.disabled, true); }
        read.resolve(savedVersion()); await restoring;
        if (completion === 'after read') { old.resolve(importJson('OLD')); await old.done; }
        assert.equal(m.by('pe-remove-0'), undefined);
        assert.equal(m.by('pe-download').props.disabled, false);
        assert.match(m.by('pe-notice').children, /精确回读/);
        await m.by('pe-save').props.onClick();
        assert.equal(submitted.experiment_key, 'SAVED');
        assert.equal(submitted.expected_version, 1);
        assert.deepEqual(submitted.input.records, []);
    });
});

test('pending file success or failure cannot replace a save result', async t => {
    for (const outcome of ['success', 'failure']) await t.test(outcome, async () => {
        const m = mount(async (path, options) => savedVersion(JSON.parse(options.body).input));
        const old = pendingImport(m);
        await m.by('pe-save').props.onClick();
        if (outcome === 'success') old.resolve(importJson('OLD'));
        else old.reject(new Error('SYNTHETIC old file failed'));
        await old.done;
        assert.equal(m.by('pe-remove-0'), undefined);
        assert.equal(m.by('pe-error'), undefined);
        assert.equal(m.by('pe-download').props.disabled, false);
        assert.match(m.by('pe-notice').children, /保存并精确回读/);
        assert.equal(old.target.value, 'SYNTHETIC selected file');
    });
});

test('new input and unmount discard stale file success, error and cleanup', async t => {
    for (const change of ['input', 'unmount']) for (const outcome of ['success', 'failure']) await t.test(change + ' / ' + outcome, async () => {
        const m = mount(async () => ({ code: 503 }));
        const old = pendingImport(m);
        if (change === 'input') m.fill('name', 'NEW INPUT'); else m.unmount();
        if (outcome === 'success') old.resolve(importJson('OLD')); else old.reject(new Error('SYNTHETIC old file failed'));
        await old.done;
        assert.equal(m.by('pe-remove-0'), undefined);
        assert.equal(m.by('pe-error'), undefined);
        assert.equal(old.target.value, 'SYNTHETIC selected file');
    });
});

test('a newer import keeps ownership when the earlier file succeeds or fails', async t => {
    for (const outcome of ['success', 'failure']) await t.test(outcome, async () => {
        let submitted;
        const m = mount(async (path, options) => { submitted = JSON.parse(options.body); return { code: 503 }; });
        const old = pendingImport(m);
        const fresh = pendingImport(m, old.target);
        if (outcome === 'success') old.resolve(importJson('OLD')); else old.reject(new Error('SYNTHETIC old file failed'));
        await old.done;
        assert.equal(m.by('pe-remove-0'), undefined);
        assert.equal(m.by('pe-error'), undefined);
        assert.equal(fresh.target.value, 'SYNTHETIC selected file');
        fresh.resolve(importJson('NEW')); await fresh.done;
        assert.equal(fresh.target.value, '');
        assert.ok(m.by('pe-remove-0'));
        await m.by('pe-save').props.onClick();
        assert.deepEqual(submitted.input.records.map(r => r.campaign_id), ['NEW']);
    });
});

test('promotion panel rejects late scope results without clearing newer request loading', async () => {
    const first = deferred(), second = deferred(); let calls = 0;
    const m = mount(() => ++calls === 1 ? first.promise : second.promise);
    const one = m.by('pe-history').props.onClick();
    m.fill('platform_store_id', 'OTHER');
    const two = m.by('pe-history').props.onClick();
    first.resolve({ code: 200, data: { scope: scope('SYNTHETIC'), items: [], truncated: false } }); await one;
    assert.equal(m.by('pe-history').props.disabled, true);
    assert.equal(m.by('pe-notice'), undefined);
    second.resolve({ code: 200, data: { scope: scope('OTHER'), items: [], truncated: false } }); await two;
    assert.equal(m.by('pe-history').props.disabled, false);
    assert.match(m.by('pe-notice').children, /尚无实验版本/);
});

test('promotion panel blocks duplicate submits and reuses an uncertain-save idempotency key', async () => {
    const pending = deferred(); const bodies = []; let calls = 0;
    const m = mount((p, o) => { bodies.push(JSON.parse(o.body)); calls++; return calls === 1 ? pending.promise : Promise.reject(new Error('SYNTHETIC response lost')); });
    const first = m.by('pe-save').props.onClick();
    await m.by('pe-save').props.onClick(); assert.equal(calls, 1);
    pending.resolve({ code: 503, message: 'SYNTHETIC response lost' }); await first;
    await m.by('pe-save').props.onClick();
    assert.equal(calls, 2);
    assert.equal(bodies[0].idempotency_key, bodies[1].idempotency_key);
    m.fill('name', 'SYNTHETIC revised plan'); await m.by('pe-save').props.onClick();
    assert.notEqual(bodies[1].idempotency_key, bodies[2].idempotency_key);
});

test('promotion panel rejects mismatched response dates and requires recovery', async () => {
    let mismatch = true;
    const m = mount(async () => ({ code: 200, data: { scope: { ...scope('SYNTHETIC'), period_end: mismatch ? '2026-08-12' : '2026-08-11' }, items: [] } }));
    await m.by('pe-history').props.onClick(); assert.match(m.by('pe-error').children, /返回范围/);
    mismatch = false; await m.by('pe-history').props.onClick(); assert.equal(m.by('pe-error'), undefined);
});

test('manual daily record preserves its own subperiod and unknown costs before server validation', async () => {
    let submitted;
    const m = mount(async (p, options) => { submitted = JSON.parse(options.body); return { code: 503, message: 'SYNTHETIC validation boundary' }; });
    const matching = id => walk(m.by('promotion-experiment-panel')).filter(v => v?.props?.['data-testid'] === id);
    m.fill('attribution_window_days', '7');
    for (const [key, value] of Object.entries({ campaign_id: 'SYNTHETIC-DAILY', collected_at: '2026-08-18T10:00:00+08:00', source_ref: 'SYNTHETIC report', spend: '0' })) m.fill(key, value);
    matching('pe-period_start')[1].props.onInput({ target: { value: '2026-08-10' } });
    matching('pe-period_end')[1].props.onInput({ target: { value: '2026-08-10' } });
    m.by('pe-add-row').props.onClick();
    assert.ok(m.by('pe-remove-0'));
    assert.equal(m.by('pe-error'), undefined);
    await m.by('pe-save').props.onClick();
    assert.equal(submitted.input.records[0].period_end, '2026-08-10');
    assert.equal(submitted.scope.period_end, '2026-08-11');
    assert.equal(submitted.input.records[0].commission, null);
    assert.equal(submitted.input.records[0].spend, '0');
});
