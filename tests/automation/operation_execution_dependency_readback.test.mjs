import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { createRequire } from 'node:module';

const context = { window: {}, console };
vm.runInNewContext(fs.readFileSync('public/operation-static.js', 'utf8'), context);
const api = context.window.SUXI_OPERATION_STATIC;
const fixture = () => ({
    truncated: false,
    statistics: { task_status_loaded: true, evidence_loaded: true, roi_loaded: true },
    summary: { total: 5, approved: 2, approval_rate: 40, executed: 0, execution_rate: 0, evidence_ready: 0, evidence_rate: 0, roi_ready: 0, roi_percent_ready: 0, revenue_lift_ready: 0, avg_roi: 0, avg_revenue_lift: 0, total_profit: 0, total_incremental_revenue: 0, money_status: 'break_even' },
    stages: [{ key: 'approval', count: 3 }, { key: 'execution', count: 2 }, { key: 'evidence', count: 0 }, { key: 'reviewed', count: 0 }],
    list: [{ id: 4, hotel_id: 20, approval: { status: 'approved' }, execution: { task_id: 1, status: 'pending' }, roi: { status: 'ready', value: 0 }, next_action: { label: '记录执行结果' } }],
});
const card = (model, label) => api.buildOperationExecutionSummaryCards(model.summary).find(c => c.label === label);

test('verified zeros and old responses remain distinct from unavailable dependencies', () => {
    for (const legacy of [false, true]) {
        const flow = fixture();
        if (legacy) delete flow.statistics;
        const model = api.buildOperationExecutionReadback(flow);
        assert.equal(card(model, '执行率').value, '0%');
        assert.equal(card(model, '证据率').value, '0%');
        assert.equal(card(model, '平均 ROI').value, '0%');
        assert.equal(model.list[0].execution.task_id, 1);
        assert.equal(model.list[0].read_state.complete, true);
    }
});

test('evidence unread suppresses evidence-dependent execution and ROI without erasing known task or intent', () => {
    const flow = fixture();
    flow.statistics.evidence_loaded = false;
    const before = JSON.stringify(flow), model = api.buildOperationExecutionReadback(flow);
    assert.equal(JSON.stringify(flow), before);
    assert.equal(model.summary.total, 5);
    assert.equal(card(model, '审批率').value, '40%');
    assert.equal(model.list[0].execution.task_id, 1);
    assert.equal(model.list[0].execution.status, 'pending');
    assert.equal(card(model, '执行率').value, '-');
    assert.equal(card(model, '证据率').hint, '执行证据未读取');
    assert.equal(card(model, '平均 ROI').value, '-');
    assert.equal(model.summary.total_profit, null);
    assert.equal(api.operationExecutionNodeRecordText(model.list[0]), '执行证据未读取');
    assert.match(api.operationExecutionReviewText(model.list[0]), /未读取/);
    assert.match(api.operationExecutionRoiText(model.list[0].roi), /未读取/);
    assert.equal(api.buildOperationExecutionTraceRows(model.summary).find(r => r.key === 'evidence').value, '未读取');
    assert.equal(model.stages[0].count, 3);
    assert.equal(model.stages[1].count, null);
});

test('task unread cascades to dependent fields and never displays a nonexistent task', () => {
    const flow = fixture();
    flow.statistics.task_status_loaded = false;
    const model = api.buildOperationExecutionReadback(flow);
    assert.equal(model.list[0].execution.task_id, null);
    assert.equal(model.list[0].execution.status, 'read_unavailable');
    assert.equal(card(model, '执行率').hint, '任务状态未读取');
    assert.equal(model.summary.evidence_ready, null);
    assert.match(model.list[0].next_action.label, /恢复回读/);
    assert.equal(model.summary.total, 5);
    assert.match(api.operationExecutionBottleneckText(model.summary), /暂不能判断/);
    assert.match(api.operationExecutionMoneyStatusText(model.summary.money_status), /未读取/);
});

test('ROI unread suppresses even stale positive success while retaining independent execution data', () => {
    const flow = fixture();
    flow.statistics.roi_loaded = false;
    flow.summary.avg_roi = 99;
    flow.summary.total_profit = 999;
    flow.summary.money_status = 'profit_positive';
    const model = api.buildOperationExecutionReadback(flow);
    assert.equal(card(model, '执行率').value, '0%');
    assert.equal(card(model, '证据率').value, '0%');
    assert.equal(card(model, '平均 ROI').value, '-');
    assert.equal(card(model, '净收益').value, '-');
    assert.equal(api.buildOperationExecutionTraceRows(model.summary).find(r => r.key === 'roi').value, '未读取');
    assert.doesNotMatch(api.operationExecutionMoneyStatusText(model.summary.money_status), /已验证赚钱/);
});

test('ordinary query truncation retains valid loaded subset, dependency gap plus truncation does not', () => {
    const flow = fixture();
    flow.truncated = true;
    flow.statistics = { task_status_loaded: false, evidence_loaded: false, roi_loaded: false };
    flow.data_gaps = [{ code: 'operation_execution_flow_truncated' }];
    assert.equal(card(api.buildOperationExecutionReadback(flow), '执行率').value, '0%');
    flow.data_gaps.push({ code: 'operation_execution_evidence_missing' });
    assert.equal(card(api.buildOperationExecutionReadback(flow), '执行率').value, '-');
});

test('identity exclusion with loaded dependencies keeps the prior partial scope contract', () => {
    const flow = fixture();
    flow.data_status = 'partial';
    flow.data_gaps = [{ code: 'operation_execution_identity_mismatch_excluded' }];
    flow.list[0].execution = { task_id: 0, status: 'pending_create' };
    flow.list[0].identity = { gap_count: 1 };
    const model = api.buildOperationExecutionReadback(flow);
    assert.equal(model.list[0].read_state.complete, true);
    assert.equal(model.list[0].identity.gap_count, 1);
    assert.equal(model.list[0].execution.task_id, 0);
});

test('original lazy main binding renders before loading and projects the next service response after loading', () => {
    const require = createRequire(import.meta.url), Vue = require('vue');
    const main = fs.readFileSync('public/app-main.js', 'utf8');
    const line = name => main.match(new RegExp('^[ \\t]*(?:const|let) ' + name + ' = .+;$', 'm'))?.[0];
    const helperStart = main.indexOf('const requireOperationStatic ='), helperEnd = main.indexOf('const operationToday =', helperStart);
    const binding = main.match(/^[ \t]*buildOperationExecutionReadback = requireOperationStatic\(staticConfig, 'buildOperationExecutionReadback'\);$/m)?.[0];
    assert.ok(binding);
    const mounted = new Function('Vue', 'staticConfig', `${main.slice(helperStart, helperEnd)}
        const {ref,computed}=Vue; const operationExecutionFlow=ref({list:[]});
        ${line('buildOperationExecutionReadback')}
        ${line('operationExecutionReadback')}
        ${line('operationExecutionItems')}
        return {items:operationExecutionItems,load(flow){${binding};operationExecutionFlow.value=flow}};`)(Vue, api);
    assert.equal(mounted.items.value.length, 0);
    const flow = fixture(); flow.statistics.task_status_loaded = false;
    mounted.load(flow);
    assert.equal(mounted.items.value[0].execution.status, 'read_unavailable');
    assert.equal(mounted.items.value[0].execution.task_id, null);
    mounted.load(fixture());
    assert.equal(mounted.items.value[0].execution.task_id, 1);
});

test('both original action containers withhold mutation controls until dependency readback recovers', async () => {
    const require = createRequire(import.meta.url), Vue = require('vue');
    const { parse, compile } = require('@vue/compiler-dom'), { renderToString } = require('@vue/server-renderer');
    const source = fs.readFileSync('resources/frontend/templates/fragments/17-page-ops-track.html', 'utf8'), nodes = [];
    const visit = children => { for (const n of children) { if (n.type === 1) nodes.push(n); if (n.children) visit(n.children); } };
    visit(parse(source).children);
    const controls = nodes.filter(n => n.tag === 'div' && n.props.some(p => p.type === 7 && p.name === 'if' && p.exp?.content === 'item.read_state?.complete !== false'));
    assert.equal(controls.length, 2);
    const render = new Function('Vue', compile('<section>' + controls.map(n => n.loc.source).join('') + '</section>', { mode: 'function', prefixIdentifiers: true }).code)(Vue);
    for (const complete of [false, true]) {
        const data = { item: { read_state: { complete }, execution: {}, review: {} }, operationLoading: { actions: false } };
        for (const name of ['operationCanApproveExecution','operationApprovalConfirming','operationCanStartExecution','operationCanExecuteWithEvidence','operationCanRecordNodeCheck','operationCanCancelExecution','operationCanReconcileExecution','operationCanReviewExecution','operationCanDefineIntervention','operationCanAssessIntervention','canSaveMemo','operationExecutionActionAvailable']) data[name] = () => name === 'operationCanApproveExecution';
        data.operationApprovalText = () => '确认审批'; data.operationRejectText = () => '拒绝';
        const app = Vue.createSSRApp({ data: () => data, render });
        const html = await renderToString(app);
        assert.equal(html.includes('data-testid="operation-approve"'), complete);
        assert.equal(html.includes('data-testid="operation-reject"'), complete);
    }
});
