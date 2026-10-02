import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const main = fs.readFileSync('public/app-main.js', 'utf8');
const statics = fs.readFileSync('public/operation-static.js', 'utf8');
const take = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a); assert.ok(a >= 0 && b > a); return main.slice(a, b); };
function setup() {
  const pending = [], messages = [], watchers = [];
  const form = { hotel_id: '1', strategy_type: 'price_adjust', adjust_amount: -5, discount_rate: '', start_date: '2026-09-01', end_date: '2026-09-03' };
  let session = 1;
  const s = { window: {}, strategyForm: { value: form }, operationStrategyResult: { value: null }, operationLoading: { value: { strategy: false } }, operationError: { value: { strategy: '' } },
    operationStrategyAmountRequired: { get value() { return form.strategy_type === 'price_adjust'; } }, operationStrategyDiscountRequired: { get value() { return form.strategy_type === 'promotion'; } },
    captureAuthSession: () => session, isAuthSessionCurrent: value => value === session,
    watch: (source, callback) => watchers.push(callback), normalizeOperationHotelSelection: () => form.hotel_id || null,
    operationErrorMessage: (error, fallback) => error.message || fallback, showToast: (...args) => messages.push(args),
    apiRequest: (url, options) => new Promise((resolve, reject) => pending.push({ url, options, resolve, reject })) };
  vm.createContext(s); vm.runInContext(statics, s);
  const context = { ...s, normalizeHotel: () => s.normalizeOperationHotelSelection(), request: s.apiRequest, errorMessage: s.operationErrorMessage };
  s.operationStrategyController = s.window.SUXI_OPERATION_STATIC.createOperationStrategyController?.(context);
  s.ensureOperationStaticReady = async () => {};
  const start = main.includes('let operationStrategyInputRevision =') ? 'let operationStrategyInputRevision =' : 'const isBlankStrategyValue =';
  vm.runInContext(take(start, 'const createOperationAction =') + '\nthis.simulate = simulateOperationStrategy;', s);
  return { s, form, pending, messages, change: patch => { Object.assign(form, patch); watchers.forEach(fn => fn()); }, newSession: () => { session++; } };
}
const response = label => ({ code: 200, data: { strategy_name: label, simulated: true, baseline: { avg_orders: 10 }, rule_scenario: { avg_orders: 12 } } });
const tick = async () => { await Promise.resolve(); await Promise.resolve(); };

for (const patch of [{ hotel_id: '2' }, { start_date: '2026-09-02' }, { adjust_amount: -10 }, { strategy_type: 'promotion', discount_rate: 8 }]) {
  test(`changing submitted inputs clears ownership: ${JSON.stringify(patch)}`, async () => {
    const t = setup(), work = t.s.simulate(); await tick();
    const posted = JSON.parse(t.pending[0].options.body); t.change(patch);
    t.pending[0].resolve(response('old inputs')); await work;
    assert.equal(posted.hotel_id, '1'); assert.equal(posted.adjust_amount, -5);
    assert.equal(t.s.operationStrategyResult.value, null); assert.equal(t.messages.length, 0);
    assert.equal(t.s.operationLoading.value.strategy, false);
  });
}
test('editing an already calculated form removes the previous result immediately', async () => {
  const t = setup(), work = t.s.simulate(); await tick(); t.pending[0].resolve(response('original')); await work;
  assert.equal(t.s.operationStrategyResult.value.strategy_name, 'original');
  t.change({ adjust_amount: -10 }); assert.equal(t.s.operationStrategyResult.value, null);
});
test('A-B-A cannot restore the first A response or release a newer request busy state', async () => {
  const t = setup(), first = t.s.simulate(); await tick(); t.change({ hotel_id: '2' }); t.change({ hotel_id: '1' });
  const second = t.s.simulate(); await tick(); t.pending[0].resolve(response('old A')); await first;
  assert.equal(t.s.operationStrategyResult.value, null); assert.equal(t.s.operationLoading.value.strategy, true);
  t.pending[1].resolve(response('latest A')); await second;
  assert.equal(t.s.operationStrategyResult.value.strategy_name, 'latest A'); assert.equal(t.messages.length, 1);
});
test('stale errors and prior login replies do not become current results or failures', async () => {
  const t = setup(), first = t.s.simulate(); await tick(); t.change({ end_date: '2026-09-04' });
  t.pending[0].reject(new Error('old error')); await first;
  assert.equal(t.s.operationError.value.strategy, ''); assert.equal(t.messages.length, 0);
  const second = t.s.simulate(); await tick(); t.newSession(); t.pending[1].resolve(response('old session')); await second;
  assert.equal(t.s.operationStrategyResult.value, null); assert.equal(t.messages.length, 0);
});
test('current failure is visible, retry preserves the exact form and current missing-data DTO', async () => {
  const t = setup(), first = t.s.simulate(); await tick(); t.pending[0].reject(new Error('network failed')); await first;
  assert.equal(t.s.operationError.value.strategy, 'network failed'); assert.equal(t.s.operationLoading.value.strategy, false);
  const second = t.s.simulate(); await tick();
  const missing = { simulated: false, status: 'insufficient_data', baseline: { avg_orders: null }, rule_scenario: { avg_orders: null } };
  t.pending[1].resolve({ code: 200, data: missing }); await second;
  assert.deepEqual(t.s.operationStrategyResult.value, missing); assert.equal(t.form.adjust_amount, -5);
});
test('current normal request uses exact input and returns one visible scenario', async () => {
  const t = setup(), work = t.s.simulate(); await tick();
  assert.equal(t.s.operationLoading.value.strategy, true);
  assert.deepEqual(JSON.parse(t.pending[0].options.body), { hotel_id: '1', strategy_type: 'price_adjust', start_date: '2026-09-01', end_date: '2026-09-03', adjust_amount: -5 });
  t.pending[0].resolve(response('current')); await work;
  assert.equal(t.s.operationStrategyResult.value.strategy_name, 'current');
  assert.equal(t.messages.length, 1); assert.notEqual(t.messages[0][1], 'warning');
});
test('invalid date and amount remain visible errors with no request', async () => {
  const t = setup(); t.change({ start_date: '2026-09-04', end_date: '2026-09-03' });
  await t.s.simulate(); assert.equal(t.pending.length, 0); assert.match(t.s.operationError.value.strategy, /结束日期/);
  t.change({ start_date: '2026-09-01', adjust_amount: 0 });
  await t.s.simulate(); assert.equal(t.pending.length, 0); assert.match(t.s.operationError.value.strategy, /调价金额/);
});
test('delayed module initialization never submits a form superseded before readiness', async () => {
  const t = setup(); let release;
  t.s.ensureOperationStaticReady = () => new Promise(resolve => { release = resolve; });
  const work = t.s.simulate(); t.change({ hotel_id: '2' }); release(); await work;
  assert.equal(t.pending.length, 0); assert.equal(t.messages.length, 0);
});
test('insufficient baseline remains an explicit non-simulated result rather than a success receipt', async () => {
  const t = setup(), work = t.s.simulate(); await tick();
  t.pending[0].resolve({code:200,data:{simulated:false,status:'insufficient_data',baseline:{data_status:'read_failed',data_gaps:[{code:'baseline_read_failed'}]},rule_scenario:{avg_orders:null},disclaimer:'基线读取失败，本次未生成规则情景'}});
  await work;
  assert.equal(t.s.operationStrategyResult.value.simulated, false);
  assert.equal(t.s.operationStrategyResult.value.baseline.data_status, 'read_failed');
  assert.equal(t.messages.length, 1); assert.equal(t.messages[0][1], 'warning');
});
