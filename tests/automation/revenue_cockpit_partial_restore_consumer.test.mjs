import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

// local_synthetic_fixture_only: exercises the original consumer, template and
// components without a database, authentication, approval or external request.
const main = readFileSync('public/app-main.js', 'utf8').replaceAll('\r\n', '\n');
const begin = main.indexOf('const restoredAction = await revenueAiRunCockpitHelper(');
const start = main.lastIndexOf('try {', begin);
const end = main.indexOf("\n                    }\n                    setRevenueLoadState('cockpit', 'ready');", begin);
assert.ok(start >= 0 && end > start);
const originalCaller = main.slice(start, end);
const prop = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8')
    .match(/:readback-blocked="([^"]+)"/)[1];
const ref = value => ({ value });
const current = (id, key) => ({ id, status: 'pending_approval', tasks: [], fact_integrity_status: 'verified',
    target_value: { action_card: { trace: { opportunity_key: key } } } });
const healthy = current(1, 'traffic_entry_shortage'), second = current(2, 'detail_conversion_shortage');
const ctx = {
    request() { throw Error('unexpected request'); }, hotelId: '20', isCurrentRequest: () => true,
    revenueCockpitLiveModel: ref({ opportunities: [
        { opportunityKey: 'traffic_entry_shortage' }, { opportunityKey: 'detail_conversion_shortage' },
    ] }), revenueCockpitDecisionSnapshot: ref({ id: 1 }), revenueCockpitPendingApproval: ref(null),
    revenueCockpitOpportunityApprovals: ref({}), revenueCockpitPendingApprovalError: ref(''),
    revenueCockpitPendingApprovalReadbackStatus: ref('idle'),
    revenueCockpitSnapshotError: ref(''), revenueCockpitSnapshotReadbackStatus: ref('matched_current'),
};
const apply = async result => {
    ctx.revenueAiRunCockpitHelper = async () => result;
    await vm.runInNewContext(`(async () => { ${originalCaller} })()`, ctx);
};
const blocked = () => vm.runInNewContext(prop, {
    revenueCockpitSnapshotError: ctx.revenueCockpitSnapshotError.value,
    revenueCockpitSnapshotReadbackStatus: ctx.revenueCockpitSnapshotReadbackStatus.value,
    revenueCockpitPendingApprovalReadbackStatus: ctx.revenueCockpitPendingApprovalReadbackStatus.value,
    revenueCockpitPendingApprovalError: ctx.revenueCockpitPendingApprovalError.value,
});
const componentContext = { window: {} };
vm.runInNewContext(readFileSync('public/components/system/app-main-components.js', 'utf8'), componentContext);
const h = (tag, props, children) => ({ tag, props: props || {}, children });
const components = componentContext.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue, h });
const flatten = node => !node || typeof node !== 'object' ? [] : [node,
    ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : flatten(node.children))];
const render = (key, intent) => components.RevenueCockpitOpportunityDetails.render.call({
    card: { opportunityKey: key, canCreatePendingApproval: true }, intent,
    loadingKey: '', snapshotSaving: false, readbackBlocked: blocked(), $emit() {},
});
const button = tree => flatten(tree).find(n => n.tag === 'button');

test('partial exact-read failure closes the unsafely unmapped opportunity while the verified action remains open', async () => {
    await apply({ ok: true, status: 'partial', intent: healthy, intents: [healthy],
        message: '行动 #2 精确回读暂不可用（HTTP 503），请刷新驾驶舱重试',
        restoration_failures: [{ id: 2, reason: 'HTTP 503' }],
    });
    assert.equal(ctx.revenueCockpitPendingApproval.value.id, 1);
    assert.equal(ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'partial');
    assert.match(ctx.revenueCockpitPendingApprovalError.value, /#2.*503/);
    assert.deepEqual(Object.keys(ctx.revenueCockpitOpportunityApprovals.value), ['traffic_entry_shortage']);
    assert.equal(button(render('traffic_entry_shortage', healthy)).props.disabled, false);
    const failedTree = render('detail_conversion_shortage', null);
    assert.equal(button(failedTree).props.disabled, true);
    assert.match(JSON.stringify(failedTree), /禁止重复送审/);
    const open = button(components.RevenueCockpitActionRestoreStatus.render.call({
        intent: healthy, status: 'partial', error: ctx.revenueCockpitPendingApprovalError.value, $emit() {},
    }));
    assert.equal(open.props.disabled, false);
});

test('the original direct creation handler sends no mutation while partial exact readback is unresolved', async () => {
    await apply({ ok: true, status: 'partial', intent: healthy, intents: [healthy],
        message: '行动 #2 尚未完成精确回读', restoration_failures: [{ id: 2 }] });
    const begin = main.indexOf('            const createRevenueCockpitOpportunityPendingApproval =');
    const end = main.indexOf('            const openRevenueCockpitPendingApproval =', begin);
    const touches = [];
    const create = vm.runInNewContext(`(() => { ${main.slice(begin, end)} return createRevenueCockpitOpportunityPendingApproval; })()`, {
        ...ctx, showToast: message => touches.push(message),
        revenueAiRunCockpitHelper: async () => { throw Error('mutation must not run'); },
    });
    assert.equal(await create({ opportunityKey: 'detail_conversion_shortage', canCreatePendingApproval: true }), null);
    assert.deepEqual(touches, ['请先刷新并完成已保存运营行动回读']);
});

test('successful exact readback clears only the failure gate and restores both original IDs', async () => {
    await apply({ ok: true, status: 'partial', intent: healthy, intents: [healthy],
        message: '行动 #2 尚未完成精确回读', restoration_failures: [{ id: 2 }] });
    const recovered = { ok: true, status: 'readback_verified', intent: healthy, intents: [healthy, second],
        message: '两个原行动已精确回读', restoration_failures: [] };
    for (let refresh = 0; refresh < 2; refresh++) {
        await apply(recovered);
        assert.equal(ctx.revenueCockpitPendingApprovalError.value, '');
        assert.equal(blocked(), false);
        assert.deepEqual(Object.values(ctx.revenueCockpitOpportunityApprovals.value).map(i => i.id), [1, 2]);
        assert.equal(button(render('detail_conversion_shortage', ctx.revenueCockpitOpportunityApprovals.value.detail_conversion_shortage)).props.disabled, false);
    }
});

test('legacy responses and partial drift without exact-read failures keep independent current actions compatible', async () => {
    const old = { ...second, fact_integrity_status: 'drifted' };
    for (const restoration_failures of [undefined, [], 'legacy-malformed']) {
        await apply({ ok: true, status: 'partial', intent: healthy, intents: [healthy, old],
            message: '旧快照只读；当前行动可查看', restoration_failures });
        assert.equal(ctx.revenueCockpitPendingApprovalError.value, '');
        assert.equal(blocked(), false);
        assert.equal(button(render('traffic_entry_shortage', healthy)).props.disabled, false);
        assert.deepEqual(Object.keys(ctx.revenueCockpitOpportunityApprovals.value), ['traffic_entry_shortage']);
    }
    await apply({ ok: false, message: '范围身份不一致' });
    assert.equal(ctx.revenueCockpitPendingApproval.value, null);
    assert.equal(Object.keys(ctx.revenueCockpitOpportunityApprovals.value).length, 0);
    assert.equal(ctx.revenueCockpitPendingApprovalReadbackStatus.value, 'error');
    assert.equal(blocked(), true);
});
