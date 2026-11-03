import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/components/system/autonomous-maintenance-workbench.js', import.meta.url), 'utf8');
const base = () => ({
    schema_version: 1, revision: 1,
    control: { mode: 'running', revision: 1, deadline_at: '2026-10-04T18:30:00+08:00', reason: 'fixture' },
    executor: null, execution_state: 'idle', executor_lock_held: false,
    tasks: { mt_fixture: { id: 'mt_fixture', title: 'local_synthetic_fixture_only', priority: 'P1',
        kind: 'development', state: 'waiting_user', updated_at: '2026-10-02T09:00:00+08:00',
        scope: {}, evidence: ['fixture only'], acceptance: ['real login required'], result: {
            reason: 'await login', summary: 'await login', tiers: { local_completed: true, actual_page_verified: false },
        } } },
    events: [], last_check: null, scheduler: { interval_minutes: 15, status: 'PAUSED' }, daily_summaries: {},
});
const tick = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); };

test('authorized machine restart policy and exact recovery receipt remain visible after refresh; old state stays manual', async () => {
    const state = base();
    const view = mount(async () => ({ code: 200, data: structuredClone(state) }));
    await tick();
    assert.match(view.text(), /本机重启自动恢复未启用/);
    state.restart_recovery = { enabled: true, policy_revision: 1,
        last_review: { outcome: 'resumed', observed_at: '2026-10-03T15:30:00+08:00' } };
    await view.find('maintenance-refresh').props.onClick();
    assert.match(view.text(), /本机重启恢复已授权/);
    assert.match(view.text(), /主动暂停、停止或到期保持生效/);
    assert.match(view.text(), /2026-10-03T15:30:00\+08:00/);
    state.control.mode = 'stopped';
    await view.find('maintenance-refresh').props.onClick();
    assert.match(view.text(), /已停止/);
});
test('execution evidence shows waiting, delayed original time, next candidate and overdue independently from ACTIVE', async () => {
    const state = base();
    state.control.mode = 'running';
    state.scheduler = { interval_minutes: 15, status: 'ACTIVE' };
    state.work_status = { state: 'waiting_concurrency', next_step: 'continue bounded revenue intent review after writer exits',
        counts: { queued: 0, ready: 0, needs_review: 1, blocked: 1 }, check_overdue: true, overdue_after_minutes: 30 };
    state.candidates = { next: { title: 'Review operation entry', state: 'needs_review', target_paths: ['fixture.php'],
        evidence: ['local_synthetic_fixture_only'], next_step: 'reproduce exact saved intent' } };
    state.runs = { old: { triggered_at: '2026-10-02T02:27:08+08:00', observed_at: '2026-10-02T02:28:00+08:00',
        registered_at: '2026-10-02T03:00:00+08:00', delayed_registration: true, trigger: 'scheduled', outcome: 'waiting_concurrency',
        evidence: 'fixture writer active', next_step: 'resume candidate', resume_condition: 'writer exits' } };
    const view = mount(async () => ({ code: 200, data: structuredClone(state) }));
    await tick();
    assert.match(view.text(), /等待同工作区执行者退出/);
    assert.match(view.text(), /空闲后延后登记/);
    assert.match(view.text(), /02:28:00/);
    assert.match(view.text(), /Review operation entry/);
    assert.ok(view.find('maintenance-overdue'));
    await view.find('maintenance-refresh').props.onClick();
    assert.match(view.text(), /fixture writer active/);
});
test('bounded path coverage and exact next action persist on refresh without implying whole-area acceptance', async () => {
    const state = base();
    state.work_status = { state: 'discovery_pending', next_step: '限定复核：收益页来源异常补证的酒店/日期跳转',
        counts: { queued: 0, ready: 0, needs_review: 0, blocked: 0 },
        discovery_coverage: { catalog_version: 'business-paths-v1', reviewed_paths: 3, total_paths: 6 } };
    const view = mount(async () => ({ code: 200, data: structuredClone(state) }));
    await tick();
    assert.match(view.text(), /3\/6 条限定业务路径/);
    assert.match(view.text(), /收益页来源异常补证/);
    assert.ok(view.find('maintenance-discovery-coverage'));
    await view.find('maintenance-refresh').props.onClick();
    assert.match(view.text(), /3\/6 条限定业务路径/);
});
function mount(request) {
    let mounted;
    const Vue = {
        ref: value => ({ value }), computed: read => ({ get value() { return read(); } }),
        onMounted: fn => { mounted = fn; }, onUnmounted: () => {},
    };
    const h = (tag, props, children) => ({ tag, props: props || {}, children });
    const context = { window: {}, setInterval: () => 1, clearInterval: () => {}, console };
    vm.runInNewContext(source, context);
    const component = context.window.SUXI_MAINTENANCE_WORKBENCH.create({ Vue, h });
    const render = component.setup({ request });
    mounted();
    const all = (node, out = []) => {
        if (!node || typeof node !== 'object') return out;
        if (node.tag) out.push(node);
        for (const child of Array.isArray(node.children) ? node.children.flat(5) : [node.children]) all(child, out);
        return out;
    };
    return {
        render,
        find: id => all(render()).find(node => node.props['data-testid'] === id),
        input: () => all(render()).find(node => node.tag === 'input'),
        text: () => JSON.stringify(render()),
    };
}
test('saved Shanghai deadline is editable and missing page evidence stays unverified', async () => {
    const view = mount(async () => ({ code: 200, data: base() }));
    await tick();
    assert.equal(view.input().props.value, '2026-10-04T18:30');
    assert.match(view.text(), /实际账号页面：未验证/);
    assert.match(view.text(), /PAUSED/);
    assert.match(view.text(), /尚无检查回执/);
});
test('control write requires exact readback and refresh retains persisted state', async () => {
    let state = base(), writes = 0;
    const view = mount(async (path, options) => {
        if (options) {
            writes++;
            const input = JSON.parse(options.body);
            assert.equal(input.expected_revision, state.control.revision);
            state.control.mode = input.action === 'pause' ? 'paused' : 'running';
            state.control.revision++;
            return { code: 200, data: { control: structuredClone(state.control) } };
        }
        return { code: 200, data: structuredClone(state) };
    });
    await tick();
    await view.find('maintenance-pause').props.onClick();
    assert.match(view.text(), /已暂停/);
    assert.match(view.text(), /精确回读/);
    await view.find('maintenance-refresh').props.onClick();
    assert.match(view.text(), /已暂停/);
    await view.find('maintenance-resume').props.onClick();
    assert.match(view.text(), /允许运行/);
    assert.equal(writes, 2);
});
test('write succeeded but readback failed is visible and cannot masquerade as saved success', async () => {
    let reads = 0;
    const view = mount(async (path, options) => {
        if (options) return { code: 200, data: { control: { ...base().control, mode: 'paused', revision: 2 } } };
        reads++;
        if (reads > 1) throw new Error('isolated readback failed');
        return { code: 200, data: base() };
    });
    await tick();
    await view.find('maintenance-pause').props.onClick();
    assert.match(view.text(), /isolated readback failed/);
    assert.match(view.text(), /上次已保存记录/);
    assert.equal(view.find('maintenance-control-receipt'), undefined);
    assert.equal(view.find('maintenance-resume').props.disabled, true);
});
test('older pending GET cannot overwrite a newer saved control', async () => {
    let state = base(), pending, reads = 0;
    const view = mount(async (path, options) => {
        if (options) {
            state.control = { ...state.control, mode: 'paused', revision: 2 };
            return { code: 200, data: { control: structuredClone(state.control) } };
        }
        reads++;
        if (reads === 1) return { code: 200, data: structuredClone(state) };
        if (reads === 2) return new Promise(resolve => { pending = resolve; });
        return { code: 200, data: structuredClone(state) };
    });
    await tick();
    const refresh = view.find('maintenance-refresh').props.onClick();
    // Programmatic call models an already queued click; generation fences its late response.
    await view.find('maintenance-pause').props.onClick();
    pending({ code: 200, data: base() });
    await refresh;
    assert.match(view.text(), /已暂停/);
});
test('lost executor and requested pause remain distinct from confirmed idle', async () => {
    const state = base();
    state.executor = { execution_id: 'fixture-lost' };
    state.execution_state = 'executor_lost';
    const view = mount(async () => ({ code: 200, data: state }));
    await tick();
    assert.match(view.text(), /执行者失联/);
    assert.doesNotMatch(view.text(), /执行者已释放/);
});
test('initial failure reports unknown availability rather than empty success', async () => {
    const view = mount(async () => { throw new Error('isolated unavailable'); });
    await tick();
    assert.match(view.text(), /无法确认队列是否为空/);
    assert.equal(view.find('maintenance-empty'), undefined);
});
test('malformed old state does not invent completed result', async () => {
    const state = base();
    state.tasks.mt_fixture = { id: 'legacy', state: 'legacy_unknown', title: 'legacy task', scope: {} };
    const view = mount(async () => ({ code: 200, data: state }));
    await tick();
    assert.match(view.text(), /旧状态待核实/);
    assert.match(view.text(), /尚无完成回执/);
});

test('empty deadline is a form error and does not disable pause or stop', async () => {
    const state = base(); state.control.deadline_at = null;
    const view = mount(async () => ({ code: 200, data: state }));
    await tick();
    await view.find('maintenance-save-deadline').props.onClick();
    assert.match(view.text(), /请选择截止时间/);
    assert.equal(view.find('maintenance-error'), undefined);
    assert.equal(view.find('maintenance-pause').props.disabled, false);
    assert.equal(view.find('maintenance-stop').props.disabled, false);
    view.input().props.onInput({ target: { value: '2026-10-04T18:30' } });
    assert.equal(view.find('maintenance-deadline-error'), undefined);
});
