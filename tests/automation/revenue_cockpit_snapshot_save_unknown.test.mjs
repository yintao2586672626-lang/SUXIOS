import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

// local_synthetic_fixture_only: original production handlers, helper and components; no business database.
const main = readFileSync('public/app-main.js', 'utf8').replaceAll('\r\n', '\n');
const start = main.indexOf('            const saveRevenueCockpitDecisionSnapshot =');
const end = main.indexOf('            const openRevenueCockpitPendingApproval =', start);
assert.ok(start >= 0 && end > start);
const helperContext = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/revenue-cockpit-static.js', 'utf8'), helperContext);
const helper = helperContext.window.SUXI_REVENUE_COCKPIT_STATIC.create({
    revenueAiReasonText: x => String(x || ''), revenueAiIsoDate: x => /^\d{4}-\d{2}-\d{2}$/.test(x),
    resolveRevenueOverviewAsOfDate: () => ({ ok: true, asOfDate: '2026-08-21', contractVersion: 'revenue_overview_as_of_date.v1' }),
    revenueOverviewAsOfDateContractVersion: 'revenue_overview_as_of_date.v1',
});
const canonical = value => Array.isArray(value) ? value.map(canonical) : value && typeof value === 'object'
    ? Object.fromEntries(Object.keys(value).sort().map(k => [k, canonical(value[k])])) : value;
const digest = value => createHash('sha256').update(JSON.stringify(canonical(value))).digest('hex');
const ref = value => ({ value });
function fixture() {
    const model = { contractVersion: 'revenue_daily_cockpit.v2', status: 'ready', tenantId: 10, hotelId: 20,
        businessDate: '2026-08-20', selectedPlatform: 'ctrip', asOfDate: '2026-08-21',
        asOfDateContractVersion: 'revenue_overview_as_of_date.v1', canSaveSnapshot: true,
        sections: [], visibleSections: [], opportunities: Array.from({ length: 8 }, (_, i) => ({
            opportunityKey: `opportunity_${i + 1}`, canCreatePendingApproval: true })) };
    const snapshot = { id: 33, tenant_id: 10, system_hotel_id: 20, contract_version: 'revenue_decision_snapshot.v1',
        persistence_status: 'readback_verified', evidence_identity_status: 'matched_current',
        business_date: model.businessDate, platform: model.selectedPlatform, as_of_date: model.asOfDate,
        as_of_date_contract_version: model.asOfDateContractVersion, visible_model: structuredClone(model),
        visible_model_digest: digest(model), evidence_digest: 'e'.repeat(64), content_digest: 'c'.repeat(64) };
    return { model, snapshot };
}
const response = snapshot => ({ code: 200, data: { found: !!snapshot, snapshot,
    persistence_status: snapshot ? 'readback_verified' : 'not_saved' } });
function harness() {
    const { model, snapshot } = fixture(), calls = [], messages = [], errors = [];
    const ctx = { revenueCockpitSnapshotSaving: ref(false), revenueCockpitSnapshotError: ref(''),
        revenueCockpitSnapshotReadbackStatus: ref('not_saved'), revenueCockpitLiveModel: ref(model),
        revenueCockpitLiveModelDigest: ref(snapshot.visible_model_digest), filterReportHotel: ref(20),
        revenueCockpitDecisionSnapshot: ref(null), revenueCockpitOpportunityApprovals: ref({}),
        revenueCockpitPendingApproval: ref(null), revenueCockpitPendingApprovalError: ref(''),
        revenueCockpitPendingApprovalReadbackStatus: ref('not_saved'), revenueCockpitOpportunityApprovalLoadingKey: ref(''),
        captureRevenueCockpitMutationContext: () => 1, isRevenueCockpitMutationCurrent: () => true,
        resetRevenueCockpitHistory() {}, showToast: message => messages.push(message),
        request: async (url, options = {}) => { calls.push({ url, method: options.method || 'GET' });
            return ctx.respond(url, options); },
        respond: async () => { throw Error('Fixture response lost'); },
    };
    ctx.revenueAiRunCockpitHelper = async (name, input) => {
        try { return await helper[name](input); } catch (error) { errors.push(error); throw error; }
    };
    const handlers = vm.runInNewContext(`(() => { ${main.slice(start, end)} return {
        save: saveRevenueCockpitDecisionSnapshot, create: createRevenueCockpitOpportunityPendingApproval }; })()`, ctx);
    return { ctx, calls, messages, errors, model, snapshot, ...handlers };
}

test('lost or rejected snapshot POST is unknown and blocks both original mutation handlers', async () => {
    for (const respond of [async () => { throw Error('Fixture response lost'); },
        async () => ({ code: 503, message: 'Fixture unavailable' })]) {
        const h = harness(); h.ctx.respond = respond;
        assert.equal(await h.create(h.model.opportunities[0]), null);
        assert.match(h.ctx.revenueCockpitSnapshotError.value, /保存结果未确认.*只读核对/);
        assert.equal(h.errors[0].decisionSnapshotReadbackUnverified, true);
        assert.equal(h.ctx.revenueCockpitSnapshotReadbackStatus.value, 'error');
        assert.match(h.messages[0], /保存结果未确认/);
        await h.save(); await h.create(h.model.opportunities[0]);
        assert.equal(h.calls.filter(c => c.method === 'POST').length, 1);
        assert.equal(h.ctx.revenueCockpitDecisionSnapshot.value, null);
        assert.equal(h.ctx.revenueCockpitPendingApproval.value, null);
    }
});

test('POST receipt or subsequent exact read failure cannot promote the saved snapshot', async () => {
    for (const failure of ['receipt', 'unavailable', 'wrong_id']) {
        const h = harness();
        h.ctx.respond = async (url, options) => options.method === 'POST'
            ? response(failure === 'receipt' ? { id: 33 } : h.snapshot)
            : failure === 'unavailable' ? { code: 503, message: 'Fixture exact GET unavailable' }
                : response({ ...h.snapshot, id: 34 });
        await h.save();
        assert.equal(h.ctx.revenueCockpitDecisionSnapshot.value, null);
        assert.match(h.ctx.revenueCockpitSnapshotError.value, /保存结果未确认/);
        await h.save(); await h.create(h.model.opportunities[0]);
        assert.equal(h.calls.filter(c => c.method === 'POST').length, 1);
    }
});

test('readonly restore reconciles persisted and never-saved outcomes without a POST', async () => {
    for (const persisted of [true, false]) {
        const h = harness(); await h.save();
        const postCount = h.calls.filter(c => c.method === 'POST').length;
        h.ctx.respond = async () => response(persisted ? h.snapshot : null);
        const restored = await helper.restoreRevenueDecisionSnapshotWithReadback({ request: h.ctx.request, model: h.model, hotelId: 20 });
        assert.equal(restored.ok, true);
        assert.equal(restored.snapshot?.id || null, persisted ? 33 : null);
        assert.equal(restored.status, persisted ? 'matched_current' : 'not_saved');
        assert.equal(h.calls.filter(c => c.method === 'POST').length, postCount);
        assert.ok(h.calls.filter(c => c.method === 'GET').every(c => c.url.includes('hotel_id=20')
            && c.url.includes('platform=ctrip') && c.url.includes('business_date=2026-08-20')));
    }
});

test('a late failed snapshot POST after scope replacement cannot set the new scope error', async () => {
    const h = harness(); let reject;
    h.ctx.respond = () => new Promise((resolve, fail) => { reject = fail; });
    const pending = h.save();
    h.ctx.isRevenueCockpitMutationCurrent = () => false;
    h.ctx.revenueCockpitSnapshotSaving.value = false;
    reject(Error('Fixture previous hotel response lost')); await pending;
    assert.equal(h.ctx.revenueCockpitSnapshotError.value, '');
    assert.equal(h.ctx.revenueCockpitSnapshotReadbackStatus.value, 'not_saved');
    assert.equal(h.ctx.revenueCockpitSnapshotSaving.value, false);
});

test('original template blocks snapshot and pending buttons while showing the uncertain result', async () => {
    const h = harness(); await h.save();
    const fragment = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
    const saveExpression = fragment.match(/<button[^>]*@click="saveRevenueCockpitDecisionSnapshot"[^>]*:disabled="([^"]+)"/)[1];
    const blockedExpression = fragment.match(/:readback-blocked="([^"]+)"/)[1];
    for (const expression of [saveExpression, blockedExpression]) {
        assert.ok(readFileSync('resources/frontend/app-template.html', 'utf8').includes(expression),
            'assembled production template uses the same original fragment binding');
        assert.equal(vm.runInNewContext(expression, { revenueCockpitSnapshotSaving: false,
            revenueCockpitModel: { canSaveSnapshot: true }, revenueCockpitSnapshotReadbackStatus: 'error',
            revenueCockpitSnapshotError: h.ctx.revenueCockpitSnapshotError.value,
            revenueCockpitPendingApprovalReadbackStatus: 'not_saved', revenueCockpitPendingApprovalError: '' }), true);
    }
    const c = { window: {} }; vm.runInNewContext(readFileSync('public/components/system/app-main-components.js', 'utf8'), c);
    const components = c.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue, h: (tag, props, children) => ({ tag, props, children }) });
    const status = components.RevenueCockpitSnapshotStatus.render.call({ snapshot: null, status: 'error',
        error: h.ctx.revenueCockpitSnapshotError.value, $emit() {} });
    assert.match(JSON.stringify(status), /保存结果未确认/);
    const tree = components.RevenueCockpitOpportunityDetails.render.call({ card: h.model.opportunities[0],
        intent: null, loadingKey: '', snapshotSaving: false, readbackBlocked: true, $emit() {} });
    const flat = node => !node || typeof node !== 'object' ? [] : [node,
        ...(Array.isArray(node.children) ? node.children.flatMap(flat) : flat(node.children))];
    assert.equal(flat(tree).find(n => n.tag === 'button').props.disabled, true);
});

test('pre-write invalid facts do not become an unknown persisted outcome', async () => {
    const h = harness(); h.model.canSaveSnapshot = false;
    await h.save();
    assert.equal(h.calls.length, 0);
    assert.equal(h.ctx.revenueCockpitSnapshotError.value, '');
    assert.equal(h.ctx.revenueCockpitSnapshotReadbackStatus.value, 'not_saved');
});

test('original refresh cannot skip readonly snapshot reconciliation while the outcome is uncertain', () => {
    const begin = main.indexOf('            const loadRevenueCockpit =');
    const prefix = main.slice(main.indexOf('                const reloadScope =', begin),
        main.indexOf('                const requestSeq =', begin));
    for (const status of ['error', 'loading']) {
        const ignore = vm.runInNewContext(`(() => { ${prefix} return ignoreSavedSnapshot; })()`, {
            options: { ignoreSavedSnapshot: true }, revenueCockpitSnapshotError: ref(''),
            revenueCockpitSnapshotReadbackStatus: ref(status),
        });
        assert.equal(ignore, false);
    }
});

test('readonly reconciliation in flight cannot reopen either mutation entry', async () => {
    const h = harness(); h.ctx.revenueCockpitSnapshotReadbackStatus.value = 'loading';
    await h.save(); await h.create(h.model.opportunities[0]);
    assert.equal(h.calls.length, 0);
    const fragment = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
    for (const expression of [fragment.match(/<button[^>]*@click="saveRevenueCockpitDecisionSnapshot"[^>]*:disabled="([^"]+)"/)[1],
        fragment.match(/:readback-blocked="([^"]+)"/)[1]]) {
        assert.equal(vm.runInNewContext(expression, { revenueCockpitSnapshotSaving: false,
            revenueCockpitModel: { canSaveSnapshot: true }, revenueCockpitSnapshotReadbackStatus: 'loading',
            revenueCockpitSnapshotError: '', revenueCockpitPendingApprovalReadbackStatus: 'not_saved',
            revenueCockpitPendingApprovalError: '' }), true);
    }
});
