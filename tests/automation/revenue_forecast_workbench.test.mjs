import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../../public/components/revenue/forecast-decision-workbench.js', import.meta.url), 'utf8');
const sandbox = { window: {}, URLSearchParams };
vm.runInNewContext(source, sandbox);
const create = sandbox.window.SUXI_FORECAST_WORKBENCH.createController;
const scope = { hotel_id: 90001, platform: 'ctrip', platform_store_id: 'synthetic-store', room_scope: 'synthetic-room' };
const response = () => ({ code: 200, data: { replay: { scope }, scenario: null } });
const deferred = () => { let resolve, reject; const promise = new Promise((r, j) => { resolve = r; reject = j; }); return { promise, resolve, reject }; };
const historyResponse = (page = 1, total = 0, requestedScope = scope) => ({ code: 200, data: {
    scope: requestedScope, page, page_size: 50, total, total_pages: Math.max(1, Math.ceil(total / 50)),
    items: Array.from({ length: Math.min(50, total - (page - 1) * 50) }, (_, i) => ({ id: ((page - 1) * 50 + i + 1).toString(16).padStart(64, '0'), status: 'readback_verified' })),
} });

test('input changes invalidate delayed results and old finally cannot clear a new request', async () => {
    const first = deferred(); const second = deferred(); let calls = 0;
    const c = create(() => (++calls === 1 ? first.promise : second.promise));
    const a = c.action('preview', scope);
    c.invalidate(); const b = c.action('preview', scope);
    first.resolve(response()); await a;
    assert.equal(c.state.busy, true); assert.equal(c.state.result, null);
    second.resolve(response()); await b;
    assert.equal(c.state.busy, false); assert.equal(c.state.result.replay.scope.hotel_id, 90001);
});

test('duplicate submissions run once and failed request can recover', async () => {
    const pending = deferred(); let calls = 0;
    const c = create(() => { calls++; return pending.promise; });
    const a = c.action('save', scope); await c.action('save', scope);
    assert.equal(calls, 1);
    pending.resolve({ code: 500, message: 'synthetic save failure' }); await a;
    assert.equal(c.state.savedId, ''); assert.match(c.state.error, /failure/);
    const recovery = create(async () => response()); await recovery.action('preview', scope);
    assert.equal(recovery.state.error, '');
});

test('cross scope responses and absent readback evidence cannot become saved results', async () => {
    const c = create(async () => ({ code: 200, data: { id: 'fake', readback_verified: false, payload: { scope, result: response().data } } }));
    await c.action('save', scope); assert.equal(c.state.result, null); assert.notEqual(c.state.error, '');
    const other = create(async () => ({ code: 200, data: { replay: { scope: { ...scope, hotel_id: 90002 } } } }));
    await other.action('preview', scope); assert.equal(other.state.result, null);
});

test('late history cannot reappear after scope changes', async () => {
    const pending = deferred(); const c = create(() => pending.promise);
    const history = c.history(scope); c.invalidate(true);
    pending.resolve(historyResponse(1, 1)); await history;
    assert.equal(c.state.history.length, 0);
    assert.equal(c.state.historyPagination, null);
});

test('history distinguishes loading, confirmed empty, error and a new scope', async () => {
    const pending = deferred();
    const c = create(() => pending.promise);
    assert.equal(c.state.historyStatus, 'idle');
    const request = c.history(scope);
    assert.equal(c.state.historyStatus, 'loading');
    pending.resolve(historyResponse());
    await request;
    assert.equal(c.state.historyStatus, 'empty');
    assert.equal(c.state.historyPagination.total, 0);
    assert.equal(c.state.historyPagination.total_pages, 1);
    c.invalidate(true);
    assert.equal(c.state.historyStatus, 'idle');
    const failed = create(async () => ({ code: 403, message: 'synthetic denied' }));
    await failed.history(scope);
    assert.equal(failed.state.historyStatus, 'error');
    assert.match(failed.state.historyError, /denied/);
});

test('all 52 history documents remain discoverable and the older exact ID is read', async () => {
    const urls = [];
    const c = create(async url => {
        urls.push(url);
        const parsed = new URL(url, 'http://synthetic.invalid');
        if (parsed.pathname.endsWith('/plans')) return historyResponse(Number(parsed.searchParams.get('page')), 52);
        return { code: 200, data: { id: parsed.pathname.split('/').pop(), readback_verified: true, payload: { scope, input: { evidence: {} }, result: response().data } } };
    });
    await c.history(scope);
    const firstIds = c.state.history.map(item => item.id);
    assert.equal(firstIds.length, 50);
    assert.equal(c.state.historyPagination.page, 1);
    assert.equal(c.state.historyPagination.total_pages, 2);
    await c.history(scope, 2);
    assert.equal(c.state.history.length, 2);
    assert.equal(c.state.historyPagination.page, 2);
    assert.equal(new Set([...firstIds, ...c.state.history.map(item => item.id)]).size, 52);
    const olderId = c.state.history[1].id;
    await c.action('read', scope, olderId);
    assert.equal(c.state.savedId, olderId);
    assert.match(urls[1], /page=2/);
    assert.match(urls[2], new RegExp(`/plans/${olderId}\\?`));
});

test('history rejects inconsistent pagination and clears previously displayed rows and totals', async () => {
    const malformed = [
        data => { delete data.page; },
        data => { data.page = '1'; },
        data => { data.page = 2; },
        data => { data.page_size = '50'; },
        data => { data.page_size = 51; },
        data => { delete data.total; },
        data => { data.total = '52'; },
        data => { data.total = -1; },
        data => { data.total = 52.5; },
        data => { data.total = Number.MAX_SAFE_INTEGER + 1; },
        data => { data.total_pages = '2'; },
        data => { data.total_pages = 1; },
        data => { data.items.pop(); },
        data => { data.items = []; },
        data => { data.items[0].id = 'invalid'; },
        data => { data.items[1].id = data.items[0].id; },
        data => { data.scope = { ...scope, room_scope: 'another-room' }; },
    ];
    for (const alter of malformed) {
        let next = historyResponse(1, 52);
        const c = create(async () => next);
        await c.history(scope);
        assert.equal(c.state.historyStatus, 'ready');
        next = historyResponse(1, 52); alter(next.data);
        await c.history(scope);
        assert.equal(c.state.historyStatus, 'error');
        assert.equal(c.state.history.length, 0);
        assert.equal(c.state.historyPagination, null);
        assert.notEqual(c.state.historyError, '');
    }
    const beyondLast = create(async () => ({ code: 200, data: { ...historyResponse().data, page: 2 } }));
    await beyondLast.history(scope, 2);
    assert.equal(beyondLast.state.historyStatus, 'error');
});

test('invalid requested pages never issue a request and storage errors preserve operation guidance', async () => {
    let calls = 0;
    const c = create(async () => { calls++; return historyResponse(); });
    for (const page of [0, -1, 1.5, '2', NaN, Number.MAX_SAFE_INTEGER + 1, {}]) {
        await c.history(scope, page);
        assert.equal(c.state.historyStatus, 'error');
        assert.equal(c.state.historyPagination, null);
    }
    assert.equal(calls, 0);
    const message = '历史方案读取失败；尚未取得历史列表，请重试或联系维护人员检查存储。';
    const failed = create(async () => ({ code: 500, message }));
    await failed.history(scope);
    assert.equal(failed.state.historyError, message);
});

test('late page success or failure cannot replace a newer page or scope', async () => {
    for (const failFirst of [false, true]) {
        const first = deferred(); const second = deferred(); let calls = 0;
        const c = create(() => ++calls === 1 ? first.promise : second.promise);
        const a = c.history(scope, 1);
        const b = c.history(scope, 2);
        second.resolve(historyResponse(2, 52)); await b;
        if (failFirst) first.reject(new Error('late synthetic failure')); else first.resolve(historyResponse(1, 52));
        await a;
        assert.equal(c.state.historyStatus, 'ready');
        assert.equal(c.state.historyPagination.page, 2);
        assert.equal(c.state.history.length, 2);
        assert.equal(c.state.historyError, '');
    }
    const pending = deferred(); let calls = 0;
    const otherScope = { ...scope, platform_store_id: 'another-store' };
    const c = create(() => ++calls === 1 ? pending.promise : Promise.resolve(historyResponse(1, 0, otherScope)));
    const old = c.history(scope, 2);
    c.invalidate(true); await c.history(otherScope);
    pending.resolve(historyResponse(2, 52)); await old;
    assert.equal(c.state.historyStatus, 'empty');
    assert.equal(c.state.historyPagination.total, 0);
    assert.equal(c.state.history.length, 0);
});

test('history read rejects another document in the same scope and accepts the requested document', async () => {
    const requestedId = 'a'.repeat(64);
    let returnedId = 'b'.repeat(64);
    const c = create(async () => ({ code: 200, data: { id: returnedId, readback_verified: true, payload: { scope, input: { evidence: {} }, result: response().data } } }));
    await c.action('read', scope, requestedId);
    assert.equal(c.state.result, null);
    assert.equal(c.state.savedId, '');
    assert.notEqual(c.state.error, '');
    returnedId = requestedId;
    await c.action('read', scope, requestedId);
    assert.equal(c.state.savedId, requestedId);
    assert.equal(c.state.error, '');
});

test('saved result requires a content ID and a boolean verified flag', async () => {
    for (const [id, readback_verified] of [['', true], ['bad-id', true], ['a'.repeat(64), 'false']]) {
        const c = create(async () => ({ code: 200, data: { id, readback_verified, payload: { scope, result: response().data } } }));
        await c.action('save', scope);
        assert.equal(c.state.result, null);
        assert.equal(c.state.savedId, '');
    }
});

test('protected summary read stays restricted and malformed full read fails closed', async () => {
    const id = 'a'.repeat(64);
    let redacted = true;
    const c = create(async () => ({ code: 200, redacted, data: { id, readback_verified: true, payload: { scope, input: {}, result: response().data } } }));
    await c.action('read', scope, id);
    assert.equal(c.state.inputRestricted, true);
    assert.equal(c.state.savedId, id);
    c.invalidate();
    assert.equal(c.state.inputRestricted, false);
    redacted = false;
    await c.action('read', scope, id);
    assert.equal(c.state.savedId, '');
    assert.equal(c.state.result, null);
    assert.match(c.state.error, /原始输入缺失/);
});

test('editing scenario inputs preserves pending history for the same scope', async () => {
    const pending = deferred(); const c = create(() => pending.promise);
    const history = c.history(scope); c.invalidate();
    pending.resolve(historyResponse(1, 1)); await history;
    assert.equal(c.state.history[0].id, '1'.padStart(64, '0'));
    assert.equal(c.state.historyPagination.total, 1);
});

test('synthetic template labels evidence and never fabricates a verified status', () => {
    const input = sandbox.window.SUXI_FORECAST_WORKBENCH.syntheticEvidence({ ...scope, tenant_id: 9001 });
    assert.equal(input.source_kind, 'synthetic'); assert.equal(input.observations.length, 243);
    assert.equal(input.observations[0].business_date, '2026-01-01');
    assert.equal(input.observations[242].business_date, '2026-08-31');
});
