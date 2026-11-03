import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const ctx = { window: {}, URLSearchParams };
for (const file of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']) {
    vm.runInNewContext(readFileSync('public/' + file, 'utf8'), ctx);
}
const helpers = ctx.window.SUXI_REVENUE_AI_STATIC;
const model = { tenantId: 10, hotelId: 20, businessDate: '2026-08-20', selectedPlatform: 'meituan' };
const item = id => ({ id, tenant_id: 10, system_hotel_id: 20, business_date: model.businessDate, platform: 'meituan',
    contract_version: 'revenue_decision_snapshot.v1', created_at: '2026-08-20 12:00:00', persistence_status: 'readback_verified',
    evidence_identity_status: 'not_checked', content_digest: 'a'.repeat(64), visible_model_digest: 'b'.repeat(64), evidence_digest: 'c'.repeat(64) });
const history = (items = [item(2), item(1)]) => ({ contract_version: 'revenue_decision_snapshot_history.v1',
    scope: { tenant_id: 10, system_hotel_id: 20, business_date: model.businessDate, platform: 'meituan' },
    items, next_before_id: null, read_only: true, current_evidence_verified: false });
const exact = () => ({ found: true, read_only: true, current_evidence_verified: false, snapshot: {
    ...item(1), as_of_date: '', as_of_date_contract_version: '',
    visible_model: { ...model, contractVersion: 'revenue_daily_cockpit.v2', asOfDate: '', asOfDateContractVersion: '',
        selectedPlatform: 'meituan', visibleSections: [], canCreatePendingApproval: true,
        opportunities: Array.from({ length: 8 }, (_, n) => ({ opportunityKey: `fixture-${n}` })) },
} });
const hotelHistory = () => ({ ...history(), scope_mode: 'saved_hotel_history',
    scope: { tenant_id: 10, system_hotel_id: 20 },
    items: [{ ...item(2), business_date: '2026-08-18', platform: 'ctrip' }, item(1)] });
test('hotel history can discover saved dates without a current live date or platform', async () => {
    const calls = [];
    const result = await helpers.readRevenueDecisionSnapshotHistory({ hotelId: 20, browseHotel: true,
        request: async (path, options) => { calls.push({ path, options }); return { code: 200, data: hotelHistory() }; } });
    assert.deepEqual(Array.from(result.items, i => i.business_date), ['2026-08-18', '2026-08-20']);
    const params = new URLSearchParams(calls[0].path.split('?')[1]);
    assert.equal(params.get('hotel_history'), '1'); assert.equal(params.get('history'), '1');
    assert.equal(params.has('business_date'), false); assert.equal(params.has('platform'), false);
    assert.equal(calls[0].options.withBusinessContext, false); assert.equal(calls[0].options.method, undefined);
    assert.equal(result.current_evidence_verified, false);
});
test('hotel history still rejects cross-hotel rows invalid saved scope and dishonest boundaries', async () => {
    for (const mutate of [d => { d.items[0].system_hotel_id = 21; }, d => { d.items[0].tenant_id = 11; },
        d => { d.items[0].business_date = ''; }, d => { d.items[0].platform = 'pms'; },
        d => { d.scope.platform = 'meituan'; }, d => { d.scope_mode = ''; },
        d => { d.current_evidence_verified = true; }]) {
        const data = hotelHistory(); mutate(data);
        await assert.rejects(helpers.readRevenueDecisionSnapshotHistory({ hotelId: 20, browseHotel: true,
            request: async () => ({ code: 200, data }) }));
    }
});
test('hotel history pagination and failures remain bounded and explicit', async () => {
    const data = hotelHistory(); data.items = [item(1)];
    const result = await helpers.readRevenueDecisionSnapshotHistory({ hotelId: 20, browseHotel: true, beforeId: 2,
        request: async path => { assert.match(path, /before_id=2/); return { code: 200, data }; } });
    assert.equal(result.items[0].id, 1);
    await assert.rejects(helpers.readRevenueDecisionSnapshotHistory({ hotelId: 20, browseHotel: true,
        request: async () => ({ code: 503, message: 'saved hotel history unavailable' }) }), /unavailable/);
    for (const hotelId of [-1, 1.5, Number.MAX_SAFE_INTEGER + 1]) {
        await assert.rejects(helpers.readRevenueDecisionSnapshotHistory({ hotelId, browseHotel: true,
            request: async () => assert.fail('invalid hotel must not issue a request') }));
    }
});
test('hotel history selection passes the saved item scope instead of empty or current live scope', async () => {
    const source = readFileSync('public/app-main.js', 'utf8');
    const methods = source.slice(source.indexOf('const loadRevenueCockpitHistory ='), source.indexOf('const saveRevenueCockpitDecisionSnapshot ='));
    const refs = { revenueCockpitHistory: { value: hotelHistory() }, revenueCockpitHistoricalSnapshot: { value: null },
        revenueCockpitHistoryLoading: { value: false }, revenueCockpitHistoryError: { value: '' }, revenueCockpitLiveModel: { value: {} } };
    let args;
    const run = new Function(...Object.keys(refs), 'captureRevenueCockpitMutationContext', 'isRevenueCockpitMutationCurrent',
        'revenueAiRunCockpitHelper', 'request', `let revenueCockpitHistorySeq=0; ${methods}; return selectRevenueCockpitHistoricalSnapshot`);
    const select = run(...Object.values(refs), () => ({ hotelId: '20', businessDate: '', platform: '' }), () => true,
        async (name, input) => { args = input; return { id: 2 }; }, () => {});
    await select(2); assert.equal(args.model.businessDate, '2026-08-18'); assert.equal(args.model.selectedPlatform, 'ctrip');
    assert.equal(refs.revenueCockpitHistoricalSnapshot.value.id, 2);
});
test('local_synthetic_fixture_only: history discovery is GET only and preserves bounded saved identities', async () => {
    const requests = [];
    const result = await helpers.readRevenueDecisionSnapshotHistory({ model, request: async (path, options) => {
        requests.push({ path, options }); return { code: 200, data: history() };
    } });
    assert.deepEqual(Array.from(result.items, i => i.id), [2, 1]);
    assert.equal(requests[0].options.method, undefined);
    assert.match(requests[0].path, /history=1/);
    assert.equal(result.current_evidence_verified, false);
});
test('list scope, order, receipt and cursor failures are rejected rather than empty success', async () => {
    for (const mutate of [d => { d.scope.tenant_id = 11; }, d => { d.items[0].platform = 'ctrip'; },
        d => { d.items.reverse(); }, d => { d.items[0].persistence_status = 'saved'; },
        d => { d.current_evidence_verified = true; }, d => { d.next_before_id = 999; },
        d => { d.items[0].content_digest = ''; }]) {
        const data = history(); mutate(data);
        await assert.rejects(helpers.readRevenueDecisionSnapshotHistory({ model, request: async () => ({ code: 200, data }) }));
    }
});
test('HTTP failure is visible and valid empty scope remains explicit', async () => {
    await assert.rejects(helpers.readRevenueDecisionSnapshotHistory({ model, request: async () => ({ code: 503, message: 'fixture unavailable' }) }), /fixture unavailable/);
    const result = await helpers.readRevenueDecisionSnapshotHistory({ model, request: async () => ({ code: 200, data: history([]) }) });
    assert.equal(result.items.length, 0);
});
test('history discovery can use the authorized hotel tenant when current source facts are unavailable', async () => {
    const result = await helpers.readRevenueDecisionSnapshotHistory({ model: { ...model, tenantId: 0 },
        request: async () => ({ code: 200, data: history() }) });
    assert.equal(result.scope.tenant_id, 10);
    const invalid = history(); invalid.items[0].tenant_id = 11;
    await assert.rejects(helpers.readRevenueDecisionSnapshotHistory({ model: { ...model, tenantId: 0 },
        request: async () => ({ code: 200, data: invalid }) }));
});
test('historical exact ID uses saved date contract including legacy missing as-of, never claims current facts', async () => {
    const paths = [];
    const result = await helpers.readRevenueDecisionSnapshotHistoricalExact({ model, item: item(1), request: async path => {
        paths.push(path); return { code: 200, data: exact() };
    } });
    assert.equal(result.id, 1);
    assert.equal(result.evidence_identity_status, 'not_checked');
    assert.match(paths[0], /snapshot_id=1/);
    assert.match(paths[0], /history=1/);
});
test('exact history rejects mismatched IDs, scope, digests or current-evidence relabelling', async () => {
    for (const mutate of [d => { d.snapshot.id = 2; }, d => { d.snapshot.content_digest = 'd'.repeat(64); },
        d => { d.snapshot.evidence_identity_status = 'matched_current'; }, d => { d.snapshot.tenant_id = 11; },
        d => { d.read_only = false; }, d => { d.snapshot.visible_model.businessDate = '2026-08-21'; }]) {
        const data = exact(); mutate(data);
        await assert.rejects(helpers.readRevenueDecisionSnapshotHistoricalExact({ model, item: item(1), request: async () => ({ code: 200, data }) }));
    }
});

const main = readFileSync('public/app-main.js', 'utf8');
const methods = main.slice(main.indexOf('const loadRevenueCockpitHistory ='), main.indexOf('const saveRevenueCockpitDecisionSnapshot ='));
function appFixture(response) {
    const refs = { revenueCockpitHistory: { value: history() }, revenueCockpitHistoricalSnapshot: { value: { id: 88 } },
        revenueCockpitHistoryLoading: { value: false }, revenueCockpitHistoryError: { value: '' }, authContext: { value: { tenantId: 10 } },
        revenueCockpitLiveModel: { value: model } };
    let scopeCurrent = true;
    const scope = { hotelId: '20', businessDate: model.businessDate, platform: model.selectedPlatform };
    const run = new Function(...Object.keys(refs), 'captureRevenueCockpitMutationContext', 'isRevenueCockpitMutationCurrent',
        'revenueAiRunCockpitHelper', 'request', `let revenueCockpitHistorySeq=0; ${methods}; return {loadRevenueCockpitHistory,selectRevenueCockpitHistoricalSnapshot}`);
    const handlers = run(...Object.values(refs), () => scope, () => scopeCurrent, async () => response(), () => {});
    return { ...refs, ...handlers, invalidate: () => { scopeCurrent = false; } };
}
test('history request clears old preview and stale replies cannot repopulate a changed scope', async () => {
    let complete;
    const app = appFixture(() => new Promise(resolve => { complete = resolve; }));
    const pending = app.loadRevenueCockpitHistory();
    assert.equal(app.revenueCockpitHistoricalSnapshot.value, null);
    assert.equal(app.revenueCockpitHistory.value, null);
    app.invalidate(); complete(history()); await pending;
    assert.equal(app.revenueCockpitHistory.value, null);
});
test('failed exact selection discards old preview, leaves discoverable list and permits readonly recovery', async () => {
    let failed = true;
    const app = appFixture(() => { if (failed) throw Error('fixture exact unavailable'); return exact().snapshot; });
    await app.selectRevenueCockpitHistoricalSnapshot(1);
    assert.equal(app.revenueCockpitHistoricalSnapshot.value, null);
    assert.match(app.revenueCockpitHistoryError.value, /fixture exact unavailable/);
    assert.equal(app.revenueCockpitHistory.value.items.length, 2);
    failed = false; await app.selectRevenueCockpitHistoricalSnapshot(1);
    assert.equal(app.revenueCockpitHistoricalSnapshot.value.id, 1);
    assert.equal(app.revenueCockpitHistoryError.value, '');
});
test('historical rendering has readonly evidence and no operating action controls', () => {
    vm.runInNewContext(readFileSync('public/components/system/app-main-components.js', 'utf8'), ctx);
    const h = (tag, props, children) => ({ tag, props, children });
    const component = ctx.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue: { defineAsyncComponent: value => value }, h }).RevenueCockpitSnapshotHistory;
    const emitted = [];
    const rendered = component.render.call({ history: history(), snapshot: exact().snapshot, loading: false,
        error: '', disabled: false, $emit: (...args) => emitted.push(args) });
    const nodes = [];
    const walk = node => { if (!node || typeof node !== 'object') return; nodes.push(node); if (Array.isArray(node.children)) node.children.forEach(walk); };
    walk(rendered);
    nodes.find(n => n.props?.['data-testid'] === 'revenue-cockpit-history-select-1').props.onClick();
    assert.deepEqual(emitted, [['select', 1]]);
    assert.equal(nodes.filter(n => /pending|approve|save-snapshot/.test(n.props?.['data-testid'] || '')).length, 0);
    assert.match(JSON.stringify(rendered), /当前证据未重新核验/);
});
