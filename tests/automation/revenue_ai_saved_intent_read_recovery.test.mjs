import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// local_synthetic_fixture_only: original functions and exact-ID reader, private response seams; no business writes.
const helpers = { window: {}, URLSearchParams };
for (const file of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js', 'data-health-static.js', 'operation-static.js']) {
    vm.runInNewContext(fs.readFileSync(`public/${file}`, 'utf8'), helpers);
}
const ai = helpers.window.SUXI_REVENUE_AI_STATIC, op = helpers.window.SUXI_OPERATION_STATIC;
const main = fs.readFileSync('public/app-main.js', 'utf8');
const section = (a, b) => main.slice(main.indexOf(a), main.indexOf(b, main.indexOf(a)));
const original = section('            const revenueAiReviewIntentKey =', '            const revenueAiIsReviewActionLoading')
    + section('            const submitRevenueAiReviewAction = async', '            const openRevenueAiReviewItem = async');
const fixture = fs.readFileSync('tests/automation/revenue_ai_async_result_scope.test.mjs', 'utf8');
const definitions = fixture.slice(fixture.indexOf('const source ='), fixture.indexOf('async function run('));
const values = {};
vm.runInNewContext(definitions + ';this.overview=overview;this.intent=intent;', values);
const fields = { platform: 'ctrip', execution_date: '2099-10-08', room_type_key: 'fixture-room', rate_plan_key: 'fixture-rate' };
function harness() {
    const events = [], overview = structuredClone(values.overview);
    const state = { epoch: 1, date: '2026-08-20', saved: { ...structuredClone(values.intent), evidence: { source_business_date: '2026-08-20' } }, form: { intent_id: '90' }, get: null, formHook: null };
    const ctx = {
        revenueAiOverview: { value: overview }, revenueAiOverviewError: { value: '' }, revenueAiOverviewLoading: { value: false },
        revenueAiActionRows: { value: ai.buildRevenueAiActionRows({ overview }) }, filterReportHotel: { value: '20' }, currentPage: { value: 'compass' },
        operationFilters: { value: { hotel_id: '' } }, revenueAiExecutionFocus: { value: null },
        revenueAiReviewSubmittedIntents: { value: {} }, revenueAiReviewIntentOpeningKey: { value: '' }, revenueAiReviewSubmittedIntentSession: null,
        captureAuthSession: () => state.epoch, isAuthSessionCurrent: epoch => epoch === state.epoch, currentPageReadPolicy: () => ({ businessDate: state.date }),
        ensureRevenueAiStaticReady: async () => {}, nextTick: async () => {}, loadOperationActions: async () => true,
        showToast: (message, level) => events.push({ kind: 'toast', message, level }), setRevenueAiReviewActionLoading: () => {},
        collectPriceExecutionIntentFields: async () => fields, window: { confirm: () => true },
        openWorkflowFormDialog: async config => { events.push({ kind: 'form', config }); if (state.formHook) state.formHook(); return state.form; },
        request: async () => { events.push({ kind: 'create_response_seam', id: 90 }); return { code: 200, message: 'fixture saved', data: { execution_intent: { id: 90 }, operation_task_created: false } }; },
        loadRevenueAiOverview: async () => {},
        revenueAiResolveOverviewResponse: ai.resolveRevenueAiOverviewResponse, revenueAiResolveReviewActionDraft: ai.resolveRevenueAiReviewActionDraft,
        revenueAiBuildReviewConfirmText: ai.buildRevenueAiReviewConfirmText, revenueAiBuildReviewRequestBody: ai.buildRevenueAiReviewRequestBody,
        revenueAiValidateApprovedPrice: ai.validateRevenueAiApprovedPrice, revenueAiResolveExecutionAction: ai.resolveRevenueAiExecutionAction,
        revenueAiBuildExecutionIntentOpenRow: ai.buildRevenueAiExecutionIntentOpenRow,
    };
    ctx.readOperationExecutionIntent = (id, hotel) => op.readOperationExecutionIntent(async endpoint => {
        events.push({ kind: 'GET', endpoint });
        if (state.get) return state.get();
        return { code: 200, data: state.saved };
    }, id, hotel);
    vm.runInNewContext(original + ';this.submit=submitRevenueAiReviewAction;this.read=readRevenueAiReviewSubmittedIntent;this.get=getRevenueAiReviewSubmittedIntent;', ctx);
    const item = ctx.revenueAiActionRows.value[0].reviewQueueItems[0];
    return { ctx, state, events, item, result: () => ctx.get(item) };
}
test('accepted response ID survives exact read failures and correction uses only the original GET', async () => {
    for (const message of ['HTTP503', 'network disconnected', 'permission denied']) {
        const h = harness(); h.state.get = () => { throw Error(message); };
        await h.ctx.submit(h.item, 'execution_intent');
        assert.equal(h.result().id, 90); assert.equal(h.result().readback, 'failed'); assert.equal(h.result().reason, message);
        assert.match(h.events.at(-1).message, /#90.*精确回读未通过/); assert.equal(h.ctx.revenueAiExecutionFocus.value, null);
        h.state.get = null; await h.ctx.read(h.item);
        assert.equal(h.result().readback, 'verified'); assert.equal(h.ctx.revenueAiExecutionFocus.value.intentId, 90);
        assert.equal(h.events.filter(e => e.kind === 'create_response_seam').length, 1);
        assert.equal(h.events.filter(e => e.kind === 'form').length, 0);
        assert.equal(h.events.filter(e => e.kind === 'GET').every(e => e.endpoint === '/operation/execution-intents/90?hotel_id=20&system_hotel_id=20'), true);
    }
});
test('reload has no guessed ID and uses an empty original-ID form; cancel and invalid IDs create no record', async () => {
    for (const value of [null, { intent_id: '' }, { intent_id: '0' }, { intent_id: '-1' }, { intent_id: '1.5' }, { intent_id: '9007199254740992' }]) {
        const h = harness(); h.state.form = value; await h.ctx.read(h.item);
        assert.equal(h.events[0].config.fields[0].value, ''); assert.equal(h.result(), null);
        assert.equal(h.events.some(e => ['GET', 'create_response_seam'].includes(e.kind)), false);
        assert.equal(h.ctx.revenueAiReviewIntentOpeningKey.value, '');
    }
    const h = harness(); await h.ctx.read(h.item);
    assert.equal(h.result().id, 90); assert.equal(h.result().readback, 'verified'); assert.equal(h.ctx.currentPage.value, 'ops-track');
    assert.equal(h.events.some(e => e.kind === 'create_response_seam'), false);
});
test('read-only lookup requires exact hotel ID, source suggestion and source business day, not execution day', async () => {
    for (const mutate of [r => { r.hotel_id = 21; }, r => { r.id = 91; }, r => { r.source_module = 'other'; }, r => { r.source_record_id = 13; }, r => { r.evidence.source_business_date = '2026-08-21'; }, r => { r.evidence = {}; }, r => { r.evidence.source_business_date = r.date_start; }]) {
        const h = harness(); mutate(h.state.saved); await h.ctx.read(h.item);
        assert.equal(h.result().readback, 'failed'); assert.equal(h.result().id, 90); assert.equal(h.ctx.revenueAiExecutionFocus.value, null);
        assert.equal(h.events.some(e => e.kind === 'create_response_seam'), false);
    }
    const h = harness(); await h.ctx.read(h.item); assert.equal(h.result().readback, 'verified');
});
test('a mistyped manual ID can be corrected in the original readonly form without reload or creation', async () => {
    const h = harness(); h.state.form = { intent_id: '89' }; h.state.get = () => ({ code: 404, message: 'fixture not found' });
    await h.ctx.read(h.item); assert.equal(h.result().id, 89); assert.equal(h.result().readback, 'failed');
    h.state.form = { intent_id: '90' }; h.state.get = null; await h.ctx.read(h.item);
    assert.equal(h.events.filter(e => e.kind === 'form')[1].config.fields[0].value, '89');
    assert.equal(h.result().id, 90); assert.equal(h.result().readback, 'verified');
    assert.equal(h.events.filter(e => e.kind === 'GET')[0].endpoint, '/operation/execution-intents/89?hotel_id=20&system_hotel_id=20');
    assert.equal(h.events.filter(e => e.kind === 'GET')[1].endpoint, '/operation/execution-intents/90?hotel_id=20&system_hotel_id=20');
    assert.equal(h.events.some(e => e.kind === 'create_response_seam'), false);
});
test('known handoff fields remain binding on recovery and missing legacy evidence stays unknown', async () => {
    for (const mutate of [r => { r.platform = 'meituan'; }, r => { r.date_start = '2099-10-09'; }, r => { r.date_end = '2099-10-09'; }, r => { r.target_value.room_type_key = 'other'; }, r => { r.target_value.rate_plan_key = 'other'; }]) {
        const h = harness(); h.state.get = () => { throw Error('503'); }; await h.ctx.submit(h.item, 'execution_intent');
        h.state.get = null; mutate(h.state.saved); await h.ctx.read(h.item);
        assert.equal(h.result().readback, 'failed'); assert.match(h.result().reason, /原人工交接表单/); assert.equal(h.ctx.revenueAiExecutionFocus.value, null);
    }
});
test('auth changes hide retained IDs and readonly availability does not depend on write buttons', async () => {
    const h = harness(); h.state.get = () => { throw Error('503'); }; await h.ctx.submit(h.item, 'execution_intent');
    h.state.epoch++; assert.equal(h.result(), null);
    h.item.actionButtons = []; h.item.canCreateExecutionIntent = false; h.state.get = null;
    await h.ctx.read(h.item); assert.equal(h.result().readback, 'verified'); assert.equal(h.events.filter(e => e.kind === 'form').length, 1);
    assert.equal(h.events.filter(e => e.kind === 'create_response_seam').length, 1);
});
test('scope and session changes during ID form or GET cannot navigate or publish the result', async () => {
    for (const phase of ['form', 'GET']) for (const mutate of [h => { h.ctx.filterReportHotel.value = '21'; }, h => { h.state.date = '2026-08-21'; }, h => { h.state.epoch++; }, h => { h.ctx.currentPage.value = 'other'; }, h => { h.ctx.revenueAiOverview.value = structuredClone(h.ctx.revenueAiOverview.value); }]) {
        const h = harness();
        if (phase === 'form') h.state.formHook = () => mutate(h);
        else h.state.get = () => { mutate(h); return { code: 200, data: h.state.saved }; };
        await h.ctx.read(h.item); assert.equal(h.result(), null); assert.equal(h.ctx.revenueAiExecutionFocus.value, null);
        assert.equal(h.events.some(e => e.kind === 'create_response_seam'), false);
        assert.equal(h.events.filter(e => e.kind === 'GET').length, phase === 'GET' ? 1 : 0);
    }
});
test('returned records remain readonly even after status changes and permission refusal preserves the ID', async () => {
    for (const status of ['pending_approval', 'approved', 'completed', 'cancelled']) {
        const h = harness(); h.state.saved.status = status; await h.ctx.read(h.item);
        assert.equal(h.result().readback, 'verified'); assert.equal(h.ctx.revenueAiExecutionFocus.value.intentId, 90);
        assert.equal(h.events.some(e => e.kind === 'create_response_seam'), false);
    }
    for (const code of [401, 403]) {
        const h = harness(); h.state.get = () => ({ code, message: `permission${code}` }); await h.ctx.read(h.item);
        assert.equal(h.result().id, 90); assert.equal(h.result().readback, 'failed'); assert.match(h.result().reason, /permission/);
        assert.equal(h.ctx.revenueAiExecutionFocus.value, null); assert.equal(h.events.some(e => e.kind === 'create_response_seam'), false);
    }
});
