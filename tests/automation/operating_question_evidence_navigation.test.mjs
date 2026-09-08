import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/components/system/operating-intelligence-components.js', 'utf8');
const answer = (id, date = '2026-08-23', overrides = {}) => ({
  id, hotel_id: 80, platform: 'ctrip', date_start: date, date_end: date,
  content_digest: String(id % 10).repeat(64), question_text: `${date}携程订单多少？`,
  answer_summary: '按已保存记录查看来源。', answer_status: 'answered', answer: {},
  ...overrides,
});
const turn = (exact, id = `turn-${exact?.id || 'missing'}`) => ({
  id, query: exact?.question_text || '订单多少？',
  result: {
    assistant_mode: 'report', route_type: 'operating_query', precise_query_id: 9000 + Number(exact?.id || 0),
    operating_result: exact, assistant_message: '已保存并回读。',
  },
});
const walk = (value) => {
  if (Array.isArray(value)) return value.flatMap(walk);
  if (!value || typeof value !== 'object') return [];
  return [value, ...walk(value.children)];
};
const textOf = (value) => {
  if (Array.isArray(value)) return value.map(textOf).join(' ');
  if (value && typeof value === 'object') return textOf(value.children);
  return String(value ?? '');
};

function mount(turns, callback) {
  const refs = [];
  const anchor = { classList: { add() {}, remove() {} }, scrollIntoView() {} };
  const sandbox = {
    window: {
      Vue: { watch() {} }, innerWidth: 1200, innerHeight: 900,
      SUXI_HOTEL_DATA_ANALYST_COMPONENTS: { create: () => ({
        suggestions: [], createFeedbackUi: () => ({}), renderQualityReceipt: () => null,
      }) },
    },
    document: { querySelector: () => anchor },
    sessionStorage: { setItem() {} },
    setTimeout: () => 0,
  };
  vm.runInNewContext(readFileSync('public/components/system/operating-intelligence-loader.js', 'utf8'), sandbox);
  vm.runInNewContext(source, sandbox);
  const components = sandbox.window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL.create({
    ref: (value) => { const result = { value }; refs.push(result); return result; },
    computed: (get) => ({ get value() { return get(); } }),
    inject: () => null,
    h(type, props, children) {
      if (arguments.length === 2 && (Array.isArray(props) || typeof props !== 'object')) {
        return { type, props: {}, children: props };
      }
      return { type, props: props || {}, children };
    },
    nextTick: async () => {}, onMounted() {}, onUnmounted() {},
  });
  const ctx = {
    currentPage: 'compass', user: { id: 1, hotel_id: 80 },
    operatingQuestionForm: { hotel_id: '80', platform: 'ctrip', date_start: '2026-08-25', date_end: '2026-08-25' },
    openOperatingQuestionEvidence: callback,
  };
  const render = components.operatingQuestionConsultant.setup({ ctx });
  const state = refs.find((item) => Array.isArray(item.value?.turns)).value;
  state.turns = turns;
  const nodes = () => walk(render());
  const button = (id) => nodes().find((node) => node.props['data-operating-question-id'] === id);
  return { state, ctx, render, nodes, button };
}

test('each answer opens its own operating question ID and scope, including an earlier turn', async () => {
  const first = answer(41);
  const second = answer(42, '2026-08-24', { platform: 'meituan' });
  const opened = [];
  const ui = mount([turn(first), turn(second)], async (payload) => {
    opened.push({ ...payload });
    return payload.id === first.id ? first : second;
  });
  assert.equal(await ui.button(first.id).props.onClick(), true);
  assert.equal(await ui.button(second.id).props.onClick(), true);
  assert.deepEqual(opened.map(({ id, hotel_id, platform, date_start, date_end, content_digest }) => (
    { id, hotel_id, platform, date_start, date_end, content_digest }
  )), [first, second].map(({ id, hotel_id, platform, date_start, date_end, content_digest }) => (
    { id, hotel_id, platform, date_start, date_end, content_digest }
  )));
  assert.deepEqual(opened.map((item) => item.precise_query_id), [9041, 9042]);
  assert.equal(ui.ctx.currentPage, 'compass', 'only the parent callback owns page navigation');
  assert.equal(ui.state.opening_key, '');
});

test('an answer without its own saved ID stays unavailable and never substitutes the precise query ID', async () => {
  let calls = 0;
  const ui = mount([turn(answer(0))], async () => { calls += 1; });
  const open = ui.nodes().find((node) => node.props['data-testid'] === 'system-guide-open-operating-workspace');
  assert.equal(open.props.disabled, true);
  assert.match(textOf(ui.render()), /没有已保存的经营问答编号/);
  assert.equal(await open.props.onClick(), false);
  assert.equal(calls, 0);
  const absent = mount([turn(null)], async () => { calls += 1; });
  assert.match(textOf(absent.render()), /暂无已保存回答可查看/);
  assert.equal(calls, 0);
});

test('pending reads disable both turn buttons and repeated clicks cannot duplicate requests', async () => {
  let finish;
  let calls = 0;
  const first = answer(41);
  const ui = mount([turn(first), turn(answer(42))], () => {
    calls += 1;
    return new Promise((resolve) => { finish = resolve; });
  });
  const pending = ui.button(41).props.onClick();
  assert.equal(ui.button(41).props['aria-busy'], true);
  assert.equal(ui.button(41).props.disabled, true);
  assert.equal(ui.button(42).props.disabled, true);
  assert.equal(await ui.button(42).props.onClick(), false);
  assert.equal(calls, 1);
  finish(first);
  assert.equal(await pending, true);
  assert.equal(ui.button(42).props.disabled, false);
});

test('a failed exact read is visible on its turn and can be retried without changing the answer', async () => {
  const exact = answer(41);
  let calls = 0;
  const ui = mount([turn(exact)], async () => {
    calls += 1;
    if (calls === 1) throw new Error('保存问答回读失败');
    return exact;
  });
  assert.equal(await ui.button(41).props.onClick(), false);
  assert.match(textOf(ui.render()), /保存问答回读失败/);
  assert.equal(ui.button(41).props.disabled, false);
  assert.equal(ui.state.turns[0].result.operating_result, exact);
  assert.equal(ui.ctx.currentPage, 'compass');
  assert.equal(await ui.button(41).props.onClick(), true);
  assert.doesNotMatch(textOf(ui.render()), /保存问答回读失败/);
  assert.equal(calls, 2);
});

test('wrong ID, hotel, platform, date or digest never counts as opening the requested evidence', async () => {
  const exact = answer(41);
  for (const mutation of [
    { id: 42 }, { hotel_id: 81 }, { platform: 'meituan' },
    { date_start: '2026-08-22' }, { date_end: '2026-08-24' }, { content_digest: 'a'.repeat(64) },
  ]) {
    const ui = mount([turn(exact)], async () => ({ ...exact, ...mutation }));
    assert.equal(await ui.button(41).props.onClick(), false);
    assert.match(textOf(ui.render()), /编号、范围或凭证不一致/);
    assert.equal(ui.button(41).props.disabled, false);
    assert.equal(ui.ctx.currentPage, 'compass');
  }
});

test('missing scope, missing callback and lack of a professional entry fail without generating answers', async () => {
  let calls = 0;
  const incomplete = mount([turn(answer(41, '2026-08-23', { content_digest: '' }))], async () => { calls += 1; });
  assert.equal(incomplete.button(41).props.disabled, true);
  assert.match(textOf(incomplete.render()), /缺少完整范围或回读凭证/);
  assert.equal(await incomplete.button(41).props.onClick(), false);
  const unloaded = mount([turn(answer(41))]);
  assert.equal(await unloaded.button(41).props.onClick(), false);
  assert.match(textOf(unloaded.render()), /完整证据入口尚未加载/);
  const forbidden = mount([turn(answer(41))], async () => { calls += 1; });
  forbidden.ctx.visibleMenuItems = [{ path: 'compass' }];
  assert.equal(await forbidden.button(41).props.onClick(), false);
  assert.match(textOf(forbidden.render()), /没有专业问答入口权限/);
  assert.equal(calls, 0);
});
