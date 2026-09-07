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
const deferred = () => { let resolve; const promise = new Promise(r => resolve = r); return { promise, resolve }; };

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
    pending.resolve({ code: 200, data: { scope, items: [{ id: 'old' }] } }); await history;
    assert.equal(c.state.history.length, 0);
});

test('history distinguishes loading, confirmed empty, error and a new scope', async () => {
    const pending = deferred();
    const c = create(() => pending.promise);
    assert.equal(c.state.historyStatus, 'idle');
    const request = c.history(scope);
    assert.equal(c.state.historyStatus, 'loading');
    pending.resolve({ code: 200, data: { scope, items: [] } });
    await request;
    assert.equal(c.state.historyStatus, 'empty');
    c.invalidate(true);
    assert.equal(c.state.historyStatus, 'idle');
    const failed = create(async () => ({ code: 403, message: 'synthetic denied' }));
    await failed.history(scope);
    assert.equal(failed.state.historyStatus, 'error');
    assert.match(failed.state.historyError, /denied/);
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
    pending.resolve({ code: 200, data: { scope, items: [{ id: 'same-scope' }] } }); await history;
    assert.equal(c.state.history[0].id, 'same-scope');
});

test('synthetic template labels evidence and never fabricates a verified status', () => {
    const input = sandbox.window.SUXI_FORECAST_WORKBENCH.syntheticEvidence({ ...scope, tenant_id: 9001 });
    assert.equal(input.source_kind, 'synthetic'); assert.equal(input.observations.length, 243);
    assert.equal(input.observations[0].business_date, '2026-01-01');
    assert.equal(input.observations[242].business_date, '2026-08-31');
});
