import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/home-static.js', 'utf8'), context);
context.window.Vue = { h: (type, props, children) => ({ type, props, children }) };
const { HomeOperatingOrchestration, buildHomeOperatingScheduleModel } = context.window.SUXI_HOME_STATIC;
const nodes = tree => Array.isArray(tree) ? tree.flatMap(nodes)
  : (tree && typeof tree === 'object' ? [tree, ...nodes(tree.children)] : []);
const content = tree => Array.isArray(tree) ? tree.map(content).join(' ')
  : (tree && typeof tree === 'object' ? content(tree.children) : String(tree ?? ''));

// Synthetic render fixtures only; no requests, approvals or persisted hotel facts.
const task = (id, overrides = {}) => ({
  id, hotel_id: 5, stage: 'approval',
  recommendation: {
    source: `test_only#${id}`, source_module: 'ota_diagnosis_saved', platform: 'ctrip',
    date_start: '2026-09-08', date_end: '2026-09-08', created_at: '2026-09-08 08:30:00',
  },
  approval: { status: 'pending' }, execution: { status: 'pending_create' },
  assignment: { status: 'scheduled', due_at: '2026-09-08 10:00:00' },
  next_action: { key: 'approve_intent', label: '查看测试任务' },
  ...overrides,
});
const build = (list, extra = {}) => buildHomeOperatingScheduleModel({
  flow: { data_status: 'ok', list }, today: '2026-09-08', selectedHotelId: 5,
  scopeHotelName: 'test-only hotel', helpers: { hotelNameForId: () => 'test-only hotel' },
  ...extra,
});
const render = (model, extra = {}) => HomeOperatingOrchestration.render.call({
  compact: true, model, loading: false, weeklyLoading: false, weeklyError: '',
  weeklyPlan: null, $emit: () => {}, ...extra,
});

test('manual attestation remains an evidence gap with a readable explanation and original code', () => {
  const model = build([task(102, {
    stage: 'blocked', approval: { status: 'blocked', blocked_reason: 'operator_attested_only' },
  })]);
  const reason = nodes(render(model)).find(node => node.type === 'em' && node.props?.title === 'operator_attested_only');
  assert.equal(reason?.children, '只有人工确认，仍需补充来源证据');
  assert.equal(model.items[0].blockedReason, 'operator_attested_only');
});

test('compact tasks render once across normal, anomaly and daily-focus entries and open the original task', () => {
  const focus = task(103);
  focus.recommendation.source_module = 'daily_one_thing';
  const model = build([
    task(101),
    task(102, { stage: 'blocked', approval: { status: 'blocked', blocked_reason: 'test-only evidence missing' } }),
    focus,
  ]);
  assert.equal(model.items.length, 2);
  assert.equal(model.anomalyItems.length, 1);
  assert.equal(model.followupItems.length, 1);
  assert.equal(model.dailyFocus.intentId, 103);
  const events = [];
  const tree = render(model, { $emit: (...args) => events.push(args) });
  const entries = nodes(tree).filter(node => node.props?.['data-intent-id']);
  assert.deepEqual(entries.map(node => node.props['data-intent-id']).sort(), [101, 102, 103]);
  assert.equal(nodes(tree).filter(node => node.props?.['data-testid'] === 'home-operating-task-list').length, 1);
  for (const entry of entries) {
    const original = [model.dailyFocus, ...model.items].find(item => item.intentId === entry.props['data-intent-id']);
    assert.ok(content(entry).includes(original.hotelName));
    assert.ok(content(entry).includes(original.businessDateText));
    assert.ok(content(entry).includes(original.sourceRef));
    entry.props.onClick();
    assert.equal(events.at(-1)[0], 'open');
    assert.equal(events.at(-1)[1], original);
  }
  assert.ok(content(tree).includes('test-only evidence missing'));
  const summary = nodes(tree).find(node => node.props?.['data-testid'] === 'home-operating-anomaly-summary');
  assert.equal(summary.type, 'p');
  assert.equal(content(summary), model.anomalyStateLabel);
});

test('compact controls, hidden-task navigation and collapsed weekly readback remain available', () => {
  const events = [];
  const model = build([task(101), task(102)], { maxItems: 1 });
  const weeklyPlan = {
    readback_verified: true, snapshot_id: 9, status: 'ready',
    week_start: '2026-08-31', week_end: '2026-09-06',
    selected_focus: { title: 'test-only weekly focus', reason: 'test-only saved evidence' },
  };
  const tree = render(model, { weeklyPlan, $emit: (...args) => events.push(args) });
  const button = className => nodes(tree).find(node => node.type === 'button' && node.props?.class === className);
  button('home-orchestration-secondary').props.onClick();
  button('home-orchestration-primary').props.onClick();
  button('home-orchestration-more').props.onClick();
  assert.deepEqual(events.map(event => event[0]), ['refresh', 'openAll', 'openAll']);
  const weekly = nodes(tree).find(node => node.type === 'details' && node.props?.class === 'home-weekly-fold');
  assert.ok(weekly);
  assert.notEqual(weekly.props.open, true);
  assert.ok(weekly.children.some(node => node.type === 'summary'));
  assert.ok(content(weekly).includes(weeklyPlan.selected_focus.title));
  assert.ok(content(weekly).includes('快照 #9'));
  const refreshing = render(model, { loading: true });
  assert.equal(nodes(refreshing).find(node => node.props?.class === 'home-orchestration-secondary').props.disabled, true);
});

test('compact initial loading, failed reads and confirmed empty results keep distinct recovery states', () => {
  const initial = render(build([], { flow: null, loading: true }), { loading: true });
  assert.equal(nodes(initial).filter(node => node.props?.['data-testid'] === 'home-operating-orchestration-loading').length, 1);
  assert.ok(!nodes(initial).some(node => node.props?.['data-testid'] === 'home-operating-orchestration-empty'));
  for (const model of [build([]), build([], { flow: null, error: 'test-only task read failure' })]) {
    const events = [];
    const tree = render(model, { $emit: (...args) => events.push(args) });
    const empty = nodes(tree).filter(node => node.props?.['data-testid'] === 'home-operating-orchestration-empty');
    assert.equal(empty.length, 1);
    assert.ok(!nodes(tree).some(node => node.props?.['data-testid'] === 'home-operating-task-list'));
    const notice = nodes(tree).find(node => node.props?.['data-testid'] === 'home-operating-orchestration-notice');
    if (model.stateCode === 'failed') {
      assert.ok(content(notice).includes('test-only task read failure'));
      assert.ok(content(empty[0]).includes('暂时无法确认今日待办'));
      assert.ok(!content(empty[0]).includes('今天没有匹配的运营任务'));
    } else {
      assert.equal(notice, undefined);
      assert.ok(content(empty[0]).includes('今天没有匹配的运营任务'));
    }
    nodes(empty[0]).find(node => node.type === 'button').props.onClick();
    assert.deepEqual(events, [['openAll']]);
  }
});
