import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// local_synthetic_fixture_only: deferred response seams, no POST, DB or approvals.
const helpers = { window: {}, URLSearchParams };
for (const file of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js', 'data-health-static.js', 'operation-static.js']) {
    vm.runInNewContext(fs.readFileSync(`public/${file}`, 'utf8'), helpers);
}
const ai = helpers.window.SUXI_REVENUE_AI_STATIC;
const op = helpers.window.SUXI_OPERATION_STATIC;
const main = fs.readFileSync('public/app-main.js', 'utf8');
const section = (start, end) => main.slice(main.indexOf(start), main.indexOf(end, main.indexOf(start)));
const original = section('            const revenueAiReviewIntentKey =', '            const openRevenueAiExecutionItem = async')
    + section('            const openRevenueAiExecutionItem = async', '            const revenueAiIsReviewActionLoading')
    + section('            const submitRevenueAiReviewAction = async', '            const openRevenueAiReviewItem = async');
const source = {
    id: 12, hotel_id: 20, status: 'approved', suggestion_date: '2026-08-20', suggested_price: 110,
    auto_write_ota: false, can_transfer_to_operation_task: true,
    trusted_decision: {
        contract_version: 'revenue_ai_trusted_decision.v1', store: { hotel_id: 20 }, platform: { key: 'ctrip' }, date: { value: '2026-08-20' },
        sources: { status: 'verified' }, data_quality: { status: 'verified' }, metric_formula: { status: 'calculable' }, gaps: [],
        human_confirmation: { can_confirm: false, can_transfer_to_operation_task: true },
    },
    action_entry: { target_filter: { hotel_id: 20, date: '2026-08-20' }, allowed_endpoints: { execution_intent: '/api/revenue-ai/price-suggestions/12/execution-intent' }, manual_actions: ['create_execution_intent'] },
};
const overview = { hotel_id: 20, business_date: '2026-08-20', as_of_date: '2026-08-20', as_of_date_contract_version: 'revenue_overview_as_of_date.v1', actions: [{ key: 'pricing_review', status: 'pending_review', review_queue: { recent_items: [source] } }] };
const intent = { id: 90, hotel_id: 20, source_module: 'price_suggestion', source_record_id: 12, platform: 'ctrip', date_start: '2099-10-08', date_end: '2099-10-08', target_value: { room_type_key: 'fixture-room', rate_plan_key: 'fixture-rate' }, status: 'pending_approval', blocked_reason: '', tasks: [] };

async function run(stage, change = () => {}, loaded = true) {
    let signal, release;
    const reached = new Promise(resolve => { signal = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    const wait = async name => { if (stage === name) { signal(); await gate; } };
    const events = [];
    const rows = ai.buildRevenueAiActionRows({ overview });
    let readyCalls = 0;
    const ctx = {
        fixtureSession: 1, fixtureDate: '2026-08-20',
        revenueAiReviewSubmittedIntents: { value: {} }, revenueAiReviewIntentOpeningKey: { value: '' }, revenueAiReviewSubmittedIntentSession: null,
        revenueAiOverview: { value: overview }, revenueAiOverviewError: { value: '' }, revenueAiOverviewLoading: { value: false }, revenueAiActionRows: { value: rows },
        currentPage: { value: 'compass' }, filterReportHotel: { value: '20' }, operationFilters: { value: { hotel_id: '' } }, revenueAiExecutionFocus: { value: null },
        captureAuthSession: () => ctx.fixtureSession, isAuthSessionCurrent: value => value === ctx.fixtureSession,
        currentPageReadPolicy: () => ({ businessDate: ctx.fixtureDate }),
        ensureRevenueAiStaticReady: async () => { if (++readyCalls === 2) await wait('open_ready'); },
        nextTick: async () => wait('navigation_tick'),
        loadOperationActions: async () => { events.push({ kind: 'operation_load' }); await wait('navigation_load'); return typeof loaded === 'object' ? loaded.value : loaded; },
        showToast: (message, level) => events.push({ kind: 'toast', message, level }),
        setRevenueAiReviewActionLoading: (item, action, loading) => events.push({ kind: 'loading', loading }),
        collectPriceExecutionIntentFields: async () => ({ platform: 'ctrip', execution_date: '2099-10-08', room_type_key: 'fixture-room', rate_plan_key: 'fixture-rate' }),
        window: { confirm: () => true },
        revenueAiResolveOverviewResponse: ai.resolveRevenueAiOverviewResponse, revenueAiResolveReviewActionDraft: ai.resolveRevenueAiReviewActionDraft,
        revenueAiBuildReviewConfirmText: ai.buildRevenueAiReviewConfirmText, revenueAiBuildReviewRequestBody: ai.buildRevenueAiReviewRequestBody,
        revenueAiValidateApprovedPrice: ai.validateRevenueAiApprovedPrice, revenueAiResolveExecutionAction: ai.resolveRevenueAiExecutionAction,
        revenueAiBuildExecutionIntentOpenRow: ai.buildRevenueAiExecutionIntentOpenRow,
        request: async () => { events.push({ kind: 'fixture_response', intentId: 90 }); await wait('response'); return { code: 200, message: 'fixture pending saved', data: { execution_intent: { id: 90 }, operation_task_created: false } }; },
        readOperationExecutionIntent: (id, hotel) => op.readOperationExecutionIntent(async endpoint => { events.push({ kind: 'exact_get', endpoint }); await wait('exact_get'); return { code: 200, data: intent }; }, id, hotel),
        loadRevenueAiOverview: async () => events.push({ kind: 'overview_refresh' }),
    };
    vm.runInNewContext(`${original};this.submit=submitRevenueAiReviewAction`, ctx);
    const pending = ctx.submit(rows[0].reviewQueueItems[0], 'execution_intent');
    await reached;
    change(ctx);
    const changedPage = ctx.currentPage.value;
    release();
    await pending;
    return { events, ctx, changedPage };
}
const originChanges = {
    hotel: ctx => { ctx.filterReportHotel.value = '21'; },
    date: ctx => { ctx.fixtureDate = '2026-08-21'; },
    session: ctx => { ctx.fixtureSession = 2; },
    page: ctx => { ctx.currentPage.value = 'data-health'; },
    overview: ctx => { ctx.revenueAiOverview.value = structuredClone(overview); },
    loading: ctx => { ctx.revenueAiOverviewLoading.value = true; },
    error: ctx => { ctx.revenueAiOverviewError.value = 'fixture read failure'; },
};
const destinationChanges = {
    session: originChanges.session, page: originChanges.page,
    hotel: ctx => { ctx.operationFilters.value.hotel_id = '21'; },
    focus: ctx => { ctx.revenueAiExecutionFocus.value = { intentId: 91 }; },
};
const hasSuccess = events => events.some(event => event.message === 'fixture pending saved' || event.message?.startsWith('已进入运营执行'));

test('unchanged origin opens the same pending ID through intentional navigation and exact GET', async () => {
    for (const stage of ['response', 'exact_get', 'open_ready', 'navigation_tick', 'navigation_load']) {
        const { events, ctx } = await run(stage);
        assert.equal(ctx.currentPage.value, 'ops-track');
        assert.equal(ctx.operationFilters.value.hotel_id, '20');
        assert.equal(ctx.revenueAiExecutionFocus.value.intentId, 90);
        assert.match(events.find(event => event.kind === 'exact_get').endpoint, /hotel_id=20/);
        assert.equal(hasSuccess(events), true);
    }
});
for (const stage of ['response', 'exact_get', 'open_ready']) {
    test(`${stage}: changed origin cannot navigate, refresh or report stale success; result remains readable`, async () => {
        for (const [name, change] of Object.entries(originChanges)) {
            const { events, ctx, changedPage } = await run(stage, change);
            assert.equal(ctx.currentPage.value, changedPage, name);
            assert.equal(ctx.operationFilters.value.hotel_id, '', name);
            assert.equal(ctx.revenueAiExecutionFocus.value, null, name);
            assert.equal(events.some(event => event.kind === 'operation_load' || event.kind === 'overview_refresh'), false, name);
            assert.equal(hasSuccess(events), false, name);
            const warning = events.find(event => event.level === 'warning');
            assert.match(warning.message, /不要重复提交/);
            if (name === 'session') assert.doesNotMatch(warning.message, /#90|酒店 20|2026-08-20/);
            else assert.match(warning.message, /#90.*酒店 20.*2026-08-20/);
            assert.equal(events.at(-1).loading, false);
            if (stage === 'response') assert.equal(events.some(event => event.kind === 'exact_get'), false);
        }
    });
}
test('navigation await keeps a newer destination intact and avoids starting its loader', async () => {
    for (const [name, change] of Object.entries(destinationChanges)) {
        const { events, ctx, changedPage } = await run('navigation_tick', change);
        assert.equal(ctx.currentPage.value, changedPage, name);
        assert.equal(events.some(event => event.kind === 'operation_load'), false, name);
        assert.equal(hasSuccess(events), false, name);
        assert.equal(events.some(event => event.kind === 'overview_refresh'), false, name);
    }
});
test('stale or failed operation loading cannot become a success or overview refresh', async () => {
    for (const change of Object.values(destinationChanges)) {
        const { events, ctx, changedPage } = await run('navigation_load', change);
        assert.equal(ctx.currentPage.value, changedPage);
        assert.equal(hasSuccess(events), false);
        assert.equal(events.some(event => event.kind === 'overview_refresh'), false);
    }
    for (const result of [false, undefined]) {
        // Explicit undefined models the production loader's stale return.
        const { events } = await run('navigation_load', () => {}, { value: result });
        assert.equal(hasSuccess(events), false);
        assert.equal(events.some(event => event.kind === 'overview_refresh'), false);
    }
});
test('restoring the source context reads the same ID without a new fixture record', async () => {
    const rejected = await run('exact_get', originChanges.hotel);
    assert.equal(hasSuccess(rejected.events), false);
    const restored = await run('exact_get');
    assert.equal(restored.ctx.revenueAiExecutionFocus.value.intentId, 90);
    assert.equal(hasSuccess(restored.events), true);
    assert.deepEqual(intent.tasks, []);
    assert.equal(intent.status, 'pending_approval');
});
