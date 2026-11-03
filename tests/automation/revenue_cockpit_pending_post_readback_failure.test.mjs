import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

// local_synthetic_fixture_only: original handlers/helpers/components, no database or external writes.
const main = readFileSync('public/app-main.js', 'utf8').replaceAll('\r\n', '\n');
const begin = main.indexOf('            const createRevenueCockpitOpportunityPendingApproval =');
const end = main.indexOf('            const openRevenueCockpitPendingApproval =', begin);
const restoreBegin = main.indexOf('const restoredAction = await revenueAiRunCockpitHelper(');
const restoreStart = main.lastIndexOf('try {', restoreBegin);
const restoreEnd = main.indexOf("\n                    }\n                    setRevenueLoadState('cockpit', 'ready');", restoreBegin);
assert.ok(begin >= 0 && end > begin && restoreStart >= 0 && restoreEnd > restoreStart);
const context = { window: {}, URLSearchParams };
for (const p of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']) {
    vm.runInNewContext(readFileSync('public/' + p, 'utf8'), context);
}
const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const card = { opportunityKey: 'traffic_entry_shortage', title: 'Fixture observation',
    recommendedCheckAction: 'Fixture review only', canCreatePendingApproval: true };
const model = { tenantId: 10, businessDate: '2026-08-20', selectedPlatform: 'ctrip', opportunities: [card] };
const snapshot = { id: 11, tenant_id: 10, system_hotel_id: 20, business_date: model.businessDate,
    platform: model.selectedPlatform, content_digest: 'a'.repeat(64), visible_model_digest: 'b'.repeat(64),
    evidence_identity_status: 'matched_current', visible_model: model };
const intent = { id: 21, tenant_id: 10, hotel_id: 20, platform: 'ctrip', date_start: model.businessDate,
    date_end: model.businessDate, status: 'pending_approval', tasks: [], source_module: 'revenue_cockpit_action',
    object_type: 'operation_checklist', action_type: 'human_reviewed_operating_check', fact_integrity_status: 'verified',
    target_value: { action_card: { contract_version: 'operation_action_card.v1', content_digest: 'c'.repeat(64),
        action: { title: card.title, description: 'Fixture review only' }, metric_contract: { target_type: 'observation' },
        trace: { decision_snapshot_id: 11, decision_snapshot_digest: snapshot.content_digest,
            opportunity_key: card.opportunityKey, opportunity_digest: 'd'.repeat(64) } } } };
const payload = { status: 'pending_approval', execution_intent: intent, execution_task_count: 0,
    persistence_status: 'readback_verified', execution_task_created: false, external_action_triggered: false,
    snapshot, opportunity: { opportunity_key: card.opportunityKey, recommendation_digest: 'd'.repeat(64),
        title: card.title, action_text: 'Fixture review only' } };
const ref = value => ({ value });
function harness(readback = async () => ({ code: 503, message: 'Fixture exact read unavailable' }), saved = payload) {
    const calls = [], messages = [], errors = [];
    const ctx = { revenueCockpitDecisionSnapshot: ref(snapshot), revenueCockpitLiveModelDigest: ref(snapshot.visible_model_digest),
        revenueCockpitSnapshotError: ref(''), revenueCockpitSnapshotReadbackStatus: ref('matched_current'),
        revenueCockpitPendingApprovalError: ref(''), revenueCockpitPendingApprovalReadbackStatus: ref('idle'),
        revenueCockpitOpportunityApprovalLoadingKey: ref(''), revenueCockpitOpportunityApprovals: ref({}),
        revenueCockpitPendingApproval: ref(null), revenueCockpitLiveModel: ref(model), hotelId: 20,
        captureRevenueCockpitMutationContext: () => 1, isRevenueCockpitMutationCurrent: () => true,
        isCurrentRequest: () => true, showToast: message => messages.push(message),
        saveRevenueCockpitDecisionSnapshot: async () => { throw Error('unexpected extra snapshot save'); },
        request: async (url, opts = {}) => {
            calls.push({ url, method: opts.method || 'GET' });
            return opts.method === 'POST' ? { code: 200, data: saved } : readback(url);
        },
    };
    ctx.revenueAiRunCockpitHelper = async (name, args) => {
        try { return await helpers[name](args); } catch (error) { errors.push(error); throw error; }
    };
    const create = vm.runInNewContext(`(() => { ${main.slice(begin, end)} return createRevenueCockpitOpportunityPendingApproval; })()`, ctx);
    const restore = () => vm.runInNewContext(`(async () => { ${main.slice(restoreStart, restoreEnd)} })()`, ctx);
    return { ctx, calls, messages, errors, create, restore };
}
const manifest = () => ({ code: 200, data: { cockpit_scope: { tenant_id: 10, hotel_id: 20,
    business_date: model.businessDate, platform: 'ctrip' }, found: true, status: 'pending_approval',
    persistence_status: 'readback_verified', execution_intent: intent, execution_intents: [intent],
    execution_intent_count: 1, execution_task_count: 0 } });

test('POST success followed by unavailable or mismatched exact GET visibly blocks repeated creation', async () => {
    const cases = [async () => ({ code: 503, message: 'Fixture 503' }), async () => { throw Error('Fixture network read failure'); },
        ...[{ id: 22 }, { hotel_id: 21 }, { tenant_id: 11 }, { platform: 'meituan' }, { date_start: '2026-08-21' }]
            .map(change => async () => ({ code: 200, data: { ...intent, ...change } }))];
    for (const read of cases) {
        const h = harness(read);
        assert.equal(await h.create(card), null);
        assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
        assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'error');
        assert.match(h.ctx.revenueCockpitPendingApprovalError.value, /精确回读.*刷新/);
        assert.equal(h.errors[0].pendingApprovalReadbackUnverified, true);
        assert.equal(h.ctx.revenueCockpitOpportunityApprovalLoadingKey.value, '');
        await h.create(card);
        assert.equal(h.calls.filter(c => c.method === 'POST').length, 1);
    }
});

test('an incomplete POST 200 receipt is unverified and cannot be retried as an unsaved action', async () => {
    const h = harness(undefined, { ...payload, execution_task_created: undefined });
    await h.create(card);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'error');
    assert.equal(h.calls.length, 1);
    await h.create(card);
    assert.equal(h.calls.length, 1);
});

test('original readonly restore clears the failed create gate and recovers the same saved ID', async () => {
    const h = harness();
    await h.create(card);
    const postCount = h.calls.filter(c => c.method === 'POST').length;
    h.ctx.request = async url => { h.calls.push({ url, method: 'GET' });
        return url.startsWith('/revenue-ai/cockpit/pending-approval') ? manifest() : { code: 200, data: intent }; };
    for (let refresh = 0; refresh < 2; refresh++) {
        await h.restore();
        assert.equal(h.ctx.revenueCockpitPendingApproval.value.id, 21);
        assert.equal(h.ctx.revenueCockpitOpportunityApprovals.value[card.opportunityKey].id, 21);
        assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'readback_verified');
        assert.equal(h.ctx.revenueCockpitPendingApprovalError.value, '');
    }
    assert.equal(h.calls.filter(c => c.method === 'POST').length, postCount);
});

test('wrong ID during refresh retains the failure gate instead of promoting the POST receipt', async () => {
    const h = harness(); await h.create(card);
    h.ctx.request = async url => url.startsWith('/revenue-ai/cockpit/pending-approval')
        ? manifest() : { code: 200, data: { ...intent, id: 22 } };
    await h.restore();
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'error');
    assert.match(h.ctx.revenueCockpitPendingApprovalError.value, /身份不一致/);
});

test('a changed scope during the exact GET cannot contaminate the new scope error state', async () => {
    const h = harness(async () => { h.ctx.isRevenueCockpitMutationCurrent = () => false; throw Error('Fixture stale scope'); });
    await h.create(card);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'idle');
    assert.equal(h.ctx.revenueCockpitPendingApprovalError.value, '');
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
});

test('an independently verified action remains intact while the newly saved action needs readback', async () => {
    const h = harness(), healthy = { ...intent, id: 20 };
    h.ctx.revenueCockpitPendingApproval.value = healthy;
    h.ctx.revenueCockpitOpportunityApprovals.value = { independent: healthy };
    await h.create(card);
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, healthy);
    assert.equal(h.ctx.revenueCockpitOpportunityApprovals.value.independent, healthy);
    assert.equal(h.ctx.revenueCockpitOpportunityApprovals.value[card.opportunityKey], undefined);
});

test('original template and components expose the exact-read failure and disable the create button', async () => {
    const h = harness(); await h.create(card);
    const componentContext = { window: {} };
    vm.runInNewContext(readFileSync('public/components/system/app-main-components.js', 'utf8'), componentContext);
    const components = componentContext.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue, h: (tag, props, children) => ({ tag, props, children }) });
    const prop = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8').match(/:readback-blocked="([^"]+)"/)[1];
    const blocked = vm.runInNewContext(prop, { revenueCockpitPendingApprovalReadbackStatus: h.ctx.revenueCockpitPendingApprovalReadbackStatus.value,
        revenueCockpitSnapshotError: h.ctx.revenueCockpitSnapshotError.value,
        revenueCockpitSnapshotReadbackStatus: h.ctx.revenueCockpitSnapshotReadbackStatus.value,
        revenueCockpitPendingApprovalError: h.ctx.revenueCockpitPendingApprovalError.value });
    const tree = components.RevenueCockpitOpportunityDetails.render.call({ card, intent: null, loadingKey: '', snapshotSaving: false, readbackBlocked: blocked, $emit() {} });
    const flat = node => !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(flat) : flat(node.children))];
    assert.equal(flat(tree).find(n => n.tag === 'button').props.disabled, true);
    assert.match(JSON.stringify(tree), /禁止重复送审/);
    const failure = components.RevenueCockpitActionRestoreStatus.render.call({ intent: null, status: 'error', error: h.ctx.revenueCockpitPendingApprovalError.value, $emit() {} });
    assert.match(JSON.stringify(failure), /精确回读.*刷新/);
});

test('successful exact readback still returns the original pending ID without another snapshot save', async () => {
    const h = harness(async () => ({ code: 200, data: intent }));
    assert.equal((await h.create(card)).id, 21);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'readback_verified');
    assert.equal(h.ctx.revenueCockpitPendingApprovalError.value, '');
});

test('a lost POST response is an unknown save outcome and repeated clicks cannot send another mutation', async () => {
    const h = harness();
    h.ctx.request = async (url, opts = {}) => {
        h.calls.push({ url, method: opts.method || 'GET' });
        throw Error('Fixture POST response lost');
    };
    await h.create(card);
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'error');
    assert.match(h.ctx.revenueCockpitPendingApprovalError.value, /保存结果未确认.*刷新/);
    assert.doesNotMatch(h.ctx.revenueCockpitPendingApprovalError.value, /已保存|未保存/);
    await h.create(card);
    assert.deepEqual(h.calls.map(c => c.method), ['POST']);
});

test('a non-success POST receipt keeps an unknown outcome and retains the underlying denial reason', async () => {
    for (const code of [503, 403, 401, 0]) {
        const h = harness();
        h.ctx.request = async (url, opts = {}) => {
            h.calls.push({ url, method: opts.method || 'GET' });
            return { code, message: `Fixture response ${code}` };
        };
        await h.create(card);
        assert.match(h.ctx.revenueCockpitPendingApprovalError.value, /保存结果未确认.*Fixture response/);
        assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
        await h.create(card);
        assert.equal(h.calls.length, 1);
    }
});

test('readonly reconciliation after a lost POST restores the persisted original ID without a retry', async () => {
    const h = harness();
    h.ctx.request = async (url, opts = {}) => {
        h.calls.push({ url, method: opts.method || 'GET' }); throw Error('Fixture saved but response lost');
    };
    await h.create(card);
    h.ctx.request = async url => { h.calls.push({ url, method: 'GET' });
        return url.startsWith('/revenue-ai/cockpit/pending-approval') ? manifest() : { code: 200, data: intent }; };
    await h.restore();
    assert.equal(h.ctx.revenueCockpitPendingApproval.value.id, 21);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'readback_verified');
    assert.equal(h.ctx.revenueCockpitPendingApprovalError.value, '');
    assert.deepEqual(h.calls.map(c => c.method), ['POST', 'GET', 'GET']);
});

test('readonly reconciliation distinguishes a request that never saved from a persisted action', async () => {
    const h = harness();
    h.ctx.request = async () => { throw Error('Fixture request never persisted'); };
    await h.create(card);
    h.ctx.request = async url => { h.calls.push({ url, method: 'GET' }); return { code: 200, data: {
        cockpit_scope: { tenant_id: 10, hotel_id: 20, business_date: model.businessDate, platform: 'ctrip' },
        found: false, status: 'not_saved', persistence_status: 'not_saved', execution_intent: null, execution_intent_count: 0,
    } }; };
    await h.restore();
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'not_saved');
    assert.equal(h.ctx.revenueCockpitPendingApprovalError.value, '');
    assert.deepEqual(h.calls.map(c => c.method), ['GET']);
});

test('a scope change while POST is in flight cannot write its unknown outcome into the new scope', async () => {
    const h = harness();
    h.ctx.request = async () => { h.ctx.isRevenueCockpitMutationCurrent = () => false;
        throw Error('Fixture old-scope POST response lost'); };
    await h.create(card);
    assert.equal(h.ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'idle');
    assert.equal(h.ctx.revenueCockpitPendingApprovalError.value, '');
    assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
});
