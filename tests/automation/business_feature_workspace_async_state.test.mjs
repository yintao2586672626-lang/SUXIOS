import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

function workspace(request) {
    const window = {};
    vm.runInNewContext(readFileSync(new URL('../../public/components/system/business-feature-workspace.js', import.meta.url), 'utf8'), { window, Intl, Date, URLSearchParams, crypto: globalThis.crypto });
    const component = window.SUXI_SYSTEM_COMPONENTS.BusinessFeatureWorkspace;
    const state = { hotelId: 80, initialSection: 'configuration', request, $emit() {} };
    Object.assign(state, component.data.call(state));
    Object.defineProperty(state, 'kind', { get: () => component.computed.kind.call(state) });
    for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state);
    state.hotelChanged = component.watch.hotelId.handler.bind(state);
    state.sectionChanged = component.watch.active.bind(state);
    return state;
}
const plain = value => JSON.parse(JSON.stringify(value));
const weekly = (end, id) => ({ code: 200, data: { contract_version: 'weekly_operating_plan.v2', hotel_id: 80, week_end: end, readback_verified: true, snapshot_id: id } });
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

test('weekly references are replaced safely and a failed next lookup clears only the automatic reference', async () => {
    let fail = false;
    const state = workspace(async () => {
        if (fail) throw new Error('合成来源不可访问');
        return weekly(state.review.period_end, 987);
    });
    state.active = 'weekly_review';
    state.review.source_references = ['manual-source#8', 'weekly_operating_plan#786'];
    await state.readWeeklySource();
    assert.deepEqual(plain(state.review.source_references), ['manual-source#8', 'weekly_operating_plan#987']);
    fail = true;
    await state.readWeeklySource();
    assert.equal(state.weeklySource, null);
    assert.deepEqual(plain(state.review.source_references), ['manual-source#8']);
    assert.match(state.error, /不可访问/);
});

test('a late weekly response cannot bind after its review dates change', async () => {
    const response = deferred();
    const state = workspace(() => response.promise);
    state.active = 'weekly_review';
    const end = state.review.period_end;
    const pending = state.readWeeklySource();
    state.review.period_start = '2020-01-01';
    response.resolve(weekly(end, 987));
    await pending;
    assert.equal(state.weeklySource, null);
    assert.deepEqual(plain(state.review.source_references), []);
});

test('an earlier weekly lookup cannot replace a later response for the same period', async () => {
    const first = deferred(), second = deferred();
    let calls = 0;
    const state = workspace(() => (++calls === 1 ? first.promise : second.promise));
    const oldRequest = state.readWeeklySource(), newRequest = state.readWeeklySource();
    second.resolve(weekly(state.review.period_end, 989));
    await newRequest;
    first.resolve(weekly(state.review.period_end, 987));
    await oldRequest;
    assert.deepEqual(plain(state.review.source_references), ['weekly_operating_plan#989']);
});

test('mapping preview discards a response for rows that were edited while waiting', async () => {
    const response = deferred();
    const state = workspace(() => response.promise);
    state.active = 'source_mapping';
    state.displayedRecord = { snapshot_id: 5 };
    state.sourceRows = '[{"amount":100}]';
    const pending = state.previewMapping();
    state.sourceRows = '[{"amount":200}]';
    response.resolve({ code: 200, data: { contract_version: 'business_source_mapping_preview.v1', scope: { hotel_id: 80 }, mapping_snapshot_id: 5, ota_fact_created: false, rows: [{ amount: 100 }] } });
    await pending;
    assert.equal(state.preview, null);
});

test('mapping preview validates the immutable version returned by the server', async () => {
    const state = workspace(async () => ({ code: 200, data: { contract_version: 'business_source_mapping_preview.v1', scope: { hotel_id: 80 }, mapping_snapshot_id: 6, ota_fact_created: false } }));
    state.active = 'source_mapping';
    state.displayedRecord = { snapshot_id: 5 };
    await state.previewMapping();
    assert.equal(state.preview, null);
    assert.match(state.error, /映射预览范围不一致/);
});

test('restoring a saved historical review clears auxiliary evidence from the previous version', async () => {
    const state = workspace(async () => ({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'source_mapping' }, snapshot_id: 5, readback_verified: true, inputs: { field_mapping: {}, source_references: ['manual-source#5'] } } }));
    state.active = 'source_mapping';
    state.preview = { mapping_snapshot_id: 6 };
    state.weeklySource = { snapshot_id: 987 };
    await state.restore(5);
    assert.equal(state.preview, null);
    assert.equal(state.weeklySource, null);
    assert.deepEqual(plain(state.review.source_references), ['manual-source#5']);
});

test('switching hotels clears mapping and source drafts even when the new hotel cannot be loaded', async () => {
    const state = workspace(async () => { throw new Error('new hotel unavailable'); });
    state.active = 'source_mapping';
    state.mappingText = '{"hotel_id":"old-hotel-column"}';
    state.sourceRows = '[{"hotel_id":80,"guest":"synthetic-old-guest"}]';
    state.pendingSave = { key: 'old-request', signature: 'old-scope' };
    state.hotelId = 81;
    state.hotelChanged();
    assert.equal(state.mappingText, '{}');
    assert.equal(state.sourceRows, '[]');
    assert.equal(state.pendingSave, null);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.overview, null);
    assert.match(state.error, /new hotel unavailable/);
});

test('opening a mapping section with no saved version cannot reuse another section draft', async () => {
    const state = workspace(async () => ({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'source_mapping' }, catalog: [], latest: null, history: [] } }));
    state.mappingText = '{"hotel_id":"old-column"}';
    state.sourceRows = '[{"hotel_id":80}]';
    state.active = 'source_mapping';
    state.sectionChanged();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.mappingText, '{}');
    assert.equal(state.sourceRows, '[]');
    assert.deepEqual(plain(state.review.field_mapping), {});
    assert.equal(state.displayedRecord, null);
});

test('clearing the hotel cancels an in-flight read and removes all saved scope state', async () => {
    const response = deferred();
    const state = workspace(() => response.promise);
    const pending = state.load();
    state.overview = { history: [{ snapshot_id: 5 }] };
    state.displayedRecord = { snapshot_id: 5, scope: { hotel_id: 80 } };
    state.latestId = 5;
    state.notice = 'hotel 80 saved';
    state.hotelId = 0;
    state.hotelChanged();
    assert.equal(state.busy, false);
    assert.equal(state.overview, null);
    assert.equal(state.displayedRecord, null);
    assert.equal(state.latestId, 0);
    assert.equal(state.notice, '');
    response.resolve({ code: 200, data: { contract_version: 'business_workspace.v1', scope: { hotel_id: 80, kind: 'configuration' }, latest: { snapshot_id: 5 } } });
    await pending;
    assert.equal(state.busy, false);
    assert.equal(state.displayedRecord, null);
    assert.match(state.error, /有效酒店/);
});
