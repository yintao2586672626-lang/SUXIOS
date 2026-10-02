import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const slice = (a, b) => {
  const start = source.indexOf(a), end = source.indexOf(b, start);
  assert.ok(start >= 0 && end > start);
  return source.slice(start, end);
};
const dialog = slice('const createWorkflowFormDialogState =', 'let runtimeErrorRecoveryQueued =');
const reset = slice('const resetHotelScopedClientState =', 'const clearActiveHotelDashboardSnapshots =');
const auth = slice('const beginAuthSession =', 'const handleAuthInfoBootstrapUnavailable =');
const drain = async () => { for (let i = 0; i < 5; i++) await Promise.resolve(); };

function fixture() {
  // Execute the complete real auth reset and dialog methods. Only unrelated
  // dashboard state/factory side effects are synthetic; no app/network startup.
  const context = { ref: value => ({ value }), Date };
  for (const match of (reset + auth).matchAll(/\b(\w+)\.value\b/g)) context[match[1]] = { value: {} };
  for (const match of reset.matchAll(/\b(\w+)\.clear\(\)/g)) context[match[1]] = new Map();
  for (const match of (reset + auth).matchAll(/^\s*(\w+)\s*(?:\+=|=(?!=))/gm)) context[match[1]] = 0;
  for (const match of reset.matchAll(/\b(\w+)\(/g)) {
    if (!['if', 'Date', 'resetHotelScopedClientState', 'closeWorkflowFormDialog'].includes(match[1])) {
      context[match[1]] = () => ({});
    }
  }
  Object.assign(context, {
    authSessionEpoch: 1, coreOperationsMaxDate: '2026-09-27', homeRevenueFactLayerController: { reset() {} },
    token: { value: 'synthetic-session-a' }, user: { value: { id: 11 } }, isLoggedIn: { value: true },
    authContext: { value: { tenantId: 42 } }, window: {},
    clearStartupHotelListLoadTimer() {}, clearFormOperationSupportLoadTimer() {},
    createDefaultAuthContext: () => ({}), clearAuthToken() {}, clearCachedAuthUser() {},
  });
  vm.createContext(context);
  vm.runInContext(`${dialog}\n${reset}\n${auth}\nglobalThis.methods = {
    open: openWorkflowFormDialog, close: closeWorkflowFormDialog, submit: submitWorkflowFormDialog,
    begin: beginAuthSession, clear: clearAuthSessionWithStatus, state: workflowFormDialog
  };`, context);
  return context.methods;
}

for (const transition of ['expired', 'new-account', 'same-account-bootstrap']) {
  test(`auth ${transition} clears the previous workflow draft and resolves its caller as cancellation`, async () => {
    const f = fixture(); let resolved = false, result;
    const pending = f.open({ title: '账号A观测', fields: [{ name: 'note', value: 'A合成草稿', required: true }] });
    pending.then(value => { resolved = true; result = value; });
    if (transition === 'expired') f.clear('expired');
    else f.begin(transition === 'new-account' ? 'synthetic-session-b' : 'synthetic-session-a');
    await drain();
    const observed = { visible: f.state.value.visible, values: JSON.stringify(f.state.value.values), resolved, result };
    f.close(); await pending;
    assert.equal(observed.visible, false);
    assert.equal(observed.values, '{}');
    assert.equal(observed.resolved, true);
    assert.equal(observed.result, null);
  });
}

test('replacing a form cancels its caller once and keeps the new fields independently editable', async () => {
  const f = fixture(); let firstResolutions = 0;
  const first = f.open({ fields: [{ name: 'old', value: 'old' }] });
  first.then(() => firstResolutions++);
  const second = f.open({ title: '第二表单', fields: [{ name: 'current', value: 'new' }] });
  assert.equal(await first, null);
  assert.equal(firstResolutions, 1);
  assert.equal(f.state.value.title, '第二表单');
  assert.equal(f.state.value.values.old, undefined);
  f.state.value.values.current = 'edited'; f.submit();
  assert.equal((await second).current, 'edited');
  assert.equal(f.state.value.visible, false);
  f.close(); assert.equal(firstResolutions, 1);
});

test('validation errors stay editable and normal close or submit allows the next form', async () => {
  const f = fixture();
  const first = f.open({ fields: [{ name: 'price', label: '价格', type: 'number', required: true }] });
  f.submit(); assert.equal(f.state.value.visible, true); assert.match(f.state.value.errors.price, /不能为空/);
  f.state.value.values.price = 'abc'; f.submit(); assert.match(f.state.value.errors.price, /必须是数字/);
  f.state.value.values.price = '0'; f.submit(); assert.equal((await first).price, '0');
  const next = f.open({ fields: [{ name: 'day', type: 'date', min: '2026-09-27', value: '2026-09-26' }] });
  f.submit(); assert.match(f.state.value.errors.day, /不能早于/);
  f.close(); assert.equal(await next, null); assert.equal(f.state.value.visible, false);
});
