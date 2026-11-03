import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

// local_synthetic_fixture_only: no database, OTA, approval or external request.
const context = { window: {}, URLSearchParams };
for (const source of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']) {
    vm.runInNewContext(readFileSync('public/' + source, 'utf8'), context);
}
const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const scope = { tenant_id: 801, hotel_id: 901, business_date: '2026-10-01', platform: 'ctrip' };
const model = { tenantId: 801, businessDate: scope.business_date, selectedPlatform: scope.platform };
const snapshot = { id: 1901, content_digest: 'a'.repeat(64), evidence_identity_status: 'matched_current',
    visible_model: { opportunities: [{ opportunityKey: 'fixture-current', title: 'Fixture review', recommendedCheckAction: 'Fixture observation only' }] } };
const intent = (id, old = false) => ({ id, tenant_id: 801, hotel_id: 901, platform: 'ctrip',
    source_module: 'revenue_cockpit_action', object_type: 'operation_checklist', action_type: 'human_reviewed_operating_check',
    date_start: scope.business_date, date_end: scope.business_date, status: 'pending_approval', tasks: [],
    fact_integrity_status: old ? 'drifted' : 'verified', fact_integrity_failure_reason: old ? 'fixture old snapshot' : '',
    target_value: { action_card: { contract_version: 'operation_action_card.v1', content_digest: 'c'.repeat(64),
        action: { title: 'Fixture review', description: 'Fixture observation only' }, trace: {
            opportunity_key: 'fixture-current', opportunity_digest: 'd'.repeat(64),
            decision_snapshot_id: old ? 1900 : 1901, decision_snapshot_digest: (old ? 'b' : 'a').repeat(64),
        } } } });
const restore = (items, options = {}) => {
    const requests = [];
    const request = async path => {
        requests.push(path);
        if (path.startsWith('/revenue-ai/cockpit/pending-approval')) return { code: 200, data: {
            cockpit_scope: scope, found: true, persistence_status: 'readback_verified', status: items[0].status,
            execution_intent: items[0], execution_intents: items, execution_intent_count: items.length, execution_task_count: items[0].tasks.length,
        } };
        const id = Number(path.split('/').at(-1));
        const found = items.find(item => item.id === id);
        return options.readback ? options.readback(found) : { code: 200, data: structuredClone(found) };
    };
    return { requests, result: helpers.restoreRevenueCockpitPendingApprovalWithReadback({ request, model, hotelId: 901, snapshot: options.snapshot || snapshot }) };
};
test('local_synthetic_fixture_only: current and old snapshot intents restore independently with drift visible', async () => {
    const current = intent(990001), old = intent(990002, true);
    const { result, requests } = restore([current, old]);
    const saved = await result;
    assert.equal(saved.ok, true);
    assert.equal(saved.status, 'partial');
    assert.equal(saved.intents.length, 2);
    assert.equal(saved.intents[0].id, current.id);
    assert.equal(saved.intents[1].fact_integrity_status, 'drifted');
    assert.match(saved.message, /1.*1/);
    assert.ok(requests.every(path => !path.includes('approve')));
    const refreshed = await restore([current, old]).result;
    assert.equal(JSON.stringify(refreshed), JSON.stringify(saved));
});
test('local_synthetic_fixture_only: normal current intent retains exact saved payload', async () => {
    const current = intent(990001);
    const saved = await restore([current]).result;
    assert.equal(saved.status, 'readback_verified');
    assert.equal(JSON.stringify(saved.intent), JSON.stringify(current));
});
test('local_synthetic_fixture_only: foreign hotel exact readback fails closed', async () => {
    await assert.rejects(restore([intent(990001)], { readback: item => ({ code: 200, data: { ...item, hotel_id: 902 } }) }).result, /身份不一致/);
});
test('local_synthetic_fixture_only: permission failure is visible rather than empty success', async () => {
    await assert.rejects(restore([intent(990001)], { readback: () => ({ code: 403, message: 'fixture forbidden' }) }).result, /fixture forbidden/);
});
test('local_synthetic_fixture_only: old-first order selects valid primary; all old stays blocked', async () => {
    const current = intent(990001), old = intent(990002, true);
    const mixed = await restore([old, current]).result;
    assert.equal(mixed.intent.id, current.id);
    const blocked = await restore([old]).result;
    assert.equal(blocked.status, 'blocked');
    assert.equal(blocked.intent.fact_integrity_status, 'drifted');
    assert.match(blocked.message, /990002/);
});
test('local_synthetic_fixture_only: restore component keeps warning and disables drifted operating entry', async () => {
    vm.runInNewContext(readFileSync('public/components/system/app-main-components.js', 'utf8'), context);
    const h = (tag, props, children) => ({ tag, props: props || {}, children });
    const components = context.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue, h });
    const component = components.RevenueCockpitActionRestoreStatus;
    const old = (await restore([intent(990002, true)]).result).intent;
    const tree = component.render.call({ intent: old, status: 'blocked', error: '', $emit() {} });
    const flatten = node => !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : flatten(node.children))];
    const nodes = flatten(tree);
    assert.equal(nodes.find(node => node.props?.['data-testid'] === 'revenue-cockpit-restored-action-open').props.disabled, true);
    assert.match(JSON.stringify(tree), /只读/);
    assert.match(JSON.stringify(tree), /fixture old snapshot/);
    const validTree = component.render.call({ intent: { ...intent(990001), restoration_warning: '990002 old fixture blocked' }, status: 'partial', error: '', $emit() {} });
    assert.equal(flatten(validTree).find(node => node.props?.['data-testid'] === 'revenue-cockpit-restored-action-open').props.disabled, false);
    assert.match(JSON.stringify(validTree), /990002 old fixture blocked/);
});
test('local_synthetic_fixture_only: saved baseline proof reason survives stale snapshot and restores exact identity', async () => {
    const current = intent(990001);
    const reason = '已保存基准指标“详情曝光”缺少同酒店、平台和营业日的严格回读证据；补齐该指标证据后只读刷新。';
    const missingProof = { ...current, fact_integrity_status: 'drifted', fact_integrity_failure_reason: reason };
    const stale = { ...snapshot, evidence_identity_status: 'stale_current_evidence' };
    const { result, requests } = restore([missingProof], { snapshot: stale });
    const saved = await result;
    assert.equal(saved.status, 'blocked');
    assert.equal(saved.intent.id, current.id);
    assert.equal(JSON.stringify(saved.intent.target_value), JSON.stringify(current.target_value));
    assert.equal(saved.intent.fact_integrity_failure_reason, reason);
    assert.ok(saved.message.includes(reason));
    assert.ok(requests.every(path => !path.includes('approve')));
    const restored = await restore([current]).result;
    assert.equal(restored.status, 'readback_verified');
    assert.equal(JSON.stringify(restored.intent), JSON.stringify(current));
});

test('local_synthetic_fixture_only: legacy missing reason retains snapshot mismatch and verified facts cannot inject a cause', async () => {
    const stale = { ...snapshot, evidence_identity_status: 'stale_current_evidence' };
    const old = { ...intent(990001), fact_integrity_status: 'drifted', fact_integrity_failure_reason: '' };
    const legacy = await restore([old], { snapshot: stale }).result;
    assert.equal(legacy.status, 'blocked');
    assert.match(legacy.intent.fact_integrity_failure_reason, /快照身份不一致/);
    const current = { ...intent(990001), fact_integrity_failure_reason: 'fixture unused cause' };
    const verified = await restore([current], { snapshot: stale }).result;
    assert.match(verified.intent.fact_integrity_failure_reason, /快照身份不一致/);
    assert.equal(verified.intent.fact_integrity_failure_reason.includes('fixture unused cause'), false);
});

test('local_synthetic_fixture_only: drifted intent cannot navigate to operations even by direct handler', async () => {
    const app = readFileSync('public/app-main.js', 'utf8');
    const start = app.indexOf('            const openRevenueCockpitPendingApproval =');
    const end = app.indexOf('            const switchAgentTab =', start);
    const touches = [];
    const factory = vm.runInNewContext(`(() => { ${app.slice(start, end)} return openRevenueCockpitPendingApproval; })()`, {
        revenueCockpitPendingApproval: { value: null }, showToast: message => touches.push(message),
        operationFilters: { value: {} }, operationExecutionStageFilter: { value: '' },
        revenueAiExecutionFocus: { value: {} }, currentPage: { value: 'revenue-ai' },
        nextTick: async () => {}, loadOperationActions: async () => touches.push('operation_load'), suppressNextOpsTrackAutoLoad: false,
    });
    assert.equal(await factory(intent(990002, true)), false);
    assert.equal(touches.includes('operation_load'), false);
    assert.equal(await factory(intent(990001)), true);
    assert.equal(touches.includes('operation_load'), true);
});

test('local_synthetic_fixture_only: saved revenue navigation clears the old stage before focused read', async () => {
    const app = readFileSync('public/app-main.js', 'utf8');
    const start = app.indexOf('            const openRevenueCockpitPendingApproval =');
    const end = app.indexOf('            const switchAgentTab =', start);
    for (const page of ['revenue-ai', 'ops-track']) {
        const stage = { value: 'review' }, filters = { value: { hotel_id: '902' } };
        const focus = { value: null }, currentPage = { value: page }, reads = [];
        const open = vm.runInNewContext(`(() => { ${app.slice(start, end)} return openRevenueCockpitPendingApproval; })()`, {
            revenueCockpitPendingApproval: { value: intent(990001) }, showToast() {},
            operationFilters: filters, operationExecutionStageFilter: stage,
            revenueAiExecutionFocus: focus, currentPage, nextTick: async () => {},
            loadOperationActions: async options => {
                reads.push({ id: options.focusIntentId, stage: stage.value, hotel: filters.value.hotel_id, page: currentPage.value });
                return true;
            }, suppressNextOpsTrackAutoLoad: false,
        });
        assert.equal(await open(), true);
        assert.equal(reads.length, 1);
        assert.equal(reads[0].id, 990001);
        assert.equal(reads[0].stage, '');
        assert.equal(reads[0].hotel, String(intent(990001).hotel_id));
        assert.equal(reads[0].page, 'ops-track');
        assert.equal(focus.value.intentId, 990001);
    }
});

test('local_synthetic_fixture_only: rejected revenue navigation retains the selected stage and scope', async () => {
    const app = readFileSync('public/app-main.js', 'utf8');
    const start = app.indexOf('            const openRevenueCockpitPendingApproval =');
    const end = app.indexOf('            const switchAgentTab =', start);
    for (const selected of [intent(990002, true), { ...intent(990001), id: 0 }, { ...intent(990001), source_module: 'unrelated' }]) {
        const stage = { value: 'review' }, filters = { value: { hotel_id: '902' } };
        const focus = { value: null }, currentPage = { value: 'revenue-ai' };
        let reads = 0;
        const open = vm.runInNewContext(`(() => { ${app.slice(start, end)} return openRevenueCockpitPendingApproval; })()`, {
            revenueCockpitPendingApproval: { value: null }, showToast() {},
            operationFilters: filters, operationExecutionStageFilter: stage,
            revenueAiExecutionFocus: focus, currentPage, nextTick: async () => {},
            loadOperationActions: async () => { reads++; }, suppressNextOpsTrackAutoLoad: false,
        });
        assert.equal(await open(selected), false);
        assert.equal(stage.value, 'review');
        assert.equal(filters.value.hotel_id, '902');
        assert.equal(currentPage.value, 'revenue-ai');
        assert.equal(focus.value, null);
        assert.equal(reads, 0);
    }
});
test('local_synthetic_fixture_only: drifted same-key item cannot replace current opportunity mapping', async () => {
    const saved = await restore([intent(990001), intent(990002, true)]).result;
    const app = readFileSync('public/app-main.js', 'utf8');
    const start = app.indexOf('                            restoredIntents.forEach((intent) =>');
    const end = app.indexOf('                            revenueCockpitOpportunityApprovals.value', start);
    const mapped = {};
    vm.runInNewContext(app.slice(start, end), { restoredIntents: saved.intents,
        currentOpportunityKeys: new Set(['fixture-current']), restoredOpportunityIntents: mapped });
    assert.equal(mapped['fixture-current'].id, 990001);
});

test('local_synthetic_fixture_only: one transient readback failure keeps exact current action and readonly failure identity', async () => {
    for (const code of [500, 502, 503, 504]) {
        const current = intent(990001), old = intent(990002, true);
        const saved = await restore([old, current], {
            readback: item => item.id === old.id ? { code } : { code: 200, data: structuredClone(item) },
        }).result;
        assert.equal(saved.ok, true);
        assert.equal(saved.status, 'partial');
        assert.equal(saved.intent.id, current.id);
        assert.equal(saved.intents.length, 1);
        assert.equal(saved.restoration_failures.length, 1);
        const failed = saved.restoration_failures[0];
        assert.equal(failed.id, old.id);
        assert.equal(failed.status, 'readback_failed');
        assert.deepEqual(JSON.parse(JSON.stringify(failed.cockpit_scope)), scope);
        assert.equal(failed.target_value, undefined);
        assert.equal(failed.tasks, undefined);
        assert.equal(failed.persistence_status, undefined);
        assert.match(saved.intent.restoration_warning, /990002.*刷新驾驶舱重试/);
        const app = readFileSync('public/app-main.js', 'utf8');
        const start = app.indexOf('                            restoredIntents.forEach((intent) =>');
        const end = app.indexOf('                            revenueCockpitOpportunityApprovals.value', start);
        const mapped = {};
        vm.runInNewContext(app.slice(start, end), { restoredIntents: saved.intents,
            currentOpportunityKeys: new Set(['fixture-current']), restoredOpportunityIntents: mapped });
        assert.equal(mapped['fixture-current'].id, current.id);
    }
});

test('local_synthetic_fixture_only: all transient failures remain error with each identity and retry path', async () => {
    await assert.rejects(restore([intent(990001), intent(990002, true)], {
        readback: () => ({ code: 503 }),
    }).result, error => /990001/.test(error.message) && /990002/.test(error.message)
        && /刷新驾驶舱重试/.test(error.message));
});

test('local_synthetic_fixture_only: partial recovery never relaxes authentication or exact identity rejection', async () => {
    for (const code of [401, 403]) {
        await assert.rejects(restore([intent(990001), intent(990002)], {
            readback: item => item.id === 990002 ? { code, message: 'fixture auth denied' }
                : { code: 200, data: structuredClone(item) },
        }).result, /fixture auth denied/);
    }
    await assert.rejects(restore([intent(990001), intent(990002)], {
        readback: item => ({ code: 200, data: item.id === 990002
            ? { ...item, hotel_id: 902 } : structuredClone(item) }),
    }).result, /身份不一致/);
});

test('local_synthetic_fixture_only: unreadable foreign manifest entry cannot become a scope-labelled failure', async () => {
    for (const changed of [{ hotel_id: 902 }, { tenant_id: 802 }, { date_start: '2026-09-30' }]) {
        await assert.rejects(restore([intent(990001), { ...intent(990002), ...changed }], {
            readback: item => item.id === 990002 ? { code: 503 }
                : { code: 200, data: structuredClone(item) },
        }).result, /身份不一致/);
    }
});


// A resolved {code:503} alone does not exercise the production httpStatus rejection.
const transportReadback = ({ status = 503, code = status, failure } = {}) => {
    const app = readFileSync('public/app-main.js', 'utf8');
    const start = app.indexOf('const executeApiRequest = async (');
    const end = app.indexOf('// API 请求', start);
    assert.ok(start >= 0 && end > start);
    const execute = vm.runInNewContext('(() => { ' + app.slice(start, end)
        + ' return executeApiRequest; })()', {
        API_BASE: 'http://synthetic.invalid',
        fetch: async () => {
            if (failure) throw failure;
            return new Response(JSON.stringify({ code, message: 'fixture transport denied' }), { status });
        },
        console: { error() {} },
        normalizeTokenStatusFromReason: () => 'synthetic_invalid',
        clearAuthSessionIfCurrent: () => false,
        showToast() {},
        isTerminalAuthFailureResponse: () => false,
        terminalAuthFailureReason: () => '',
        isAuthSessionCurrent: () => false,
        applyAuthContext() {},
        token: { value: '' },
    });
    return () => execute({ requestSession: {}, requestUrl: '/fixture-exact',
        requestOptions: {}, headers: {} });
};

test('local_synthetic_fixture_only: production rejected HTTP 5xx retains healthy sibling and readonly failure', async () => {
    for (const status of [500, 503, 599]) {
        const transport = transportReadback({ status });
        const saved = await restore([intent(990001), intent(990002, true)], {
            readback: item => item.id === 990002 ? transport() : { code: 200, data: structuredClone(item) },
        }).result;
        assert.equal(saved.status, 'partial');
        assert.equal(saved.intent.id, 990001);
        assert.equal(saved.intents.length, 1);
        assert.equal(saved.restoration_failures[0].id, 990002);
        assert.equal(saved.restoration_failures[0].target_value, undefined);
        assert.equal(saved.restoration_failures[0].tasks, undefined);
        assert.match(saved.restoration_failures[0].reason, /刷新驾驶舱重试/);
    }
});

test('local_synthetic_fixture_only: rejected auth network and abort cannot become a transient readonly success', async () => {
    for (const options of [
        { status: 401 }, { status: 403 }, { status: 503, code: 403 },
        { failure: new TypeError('fixture network failed') },
        { failure: new DOMException('fixture request aborted', 'AbortError') },
    ]) {
        const transport = transportReadback(options);
        await assert.rejects(restore([intent(990001), intent(990002)], {
            readback: item => item.id === 990002 ? transport() : { code: 200, data: structuredClone(item) },
        }).result);
    }
});

test('local_synthetic_fixture_only: all rejected HTTP 503 entries remain explicit error with retry identity', async () => {
    await assert.rejects(restore([intent(990001), intent(990002)], {
        readback: transportReadback(),
    }).result, error => /990001/.test(error.message) && /990002/.test(error.message)
        && /刷新驾驶舱重试/.test(error.message));
});
