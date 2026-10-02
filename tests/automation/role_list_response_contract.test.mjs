import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import test from 'node:test';

const sourceArgument = process.argv.find(arg => arg.startsWith('--source-root='));
const sourceRoot = path.resolve(sourceArgument ? sourceArgument.slice('--source-root='.length) : process.cwd());
const sourcePath = path.join(sourceRoot, 'public/app-main.js');
const sourceBytes = readFileSync(sourcePath);
const source = sourceBytes.toString('utf8');
const start = source.indexOf('            const loadRoles = async (options = {}) => {');
const end = source.indexOf('            const parseKnowledgeTags =', start);
assert.ok(start >= 0 && end > start);
const loaderSource = source.slice(start, end);
const sourceSha256 = createHash('sha256').update(sourceBytes).digest('hex').toUpperCase();

// A loader unit boundary: requests and current-session/page predicates are
// explicit local doubles. No HTTP, role writes, browser, or account operation.
// Malformed code-200 responses are fault injection, not observed upstream DTOs.
const harness = (t, initialRoles = []) => {
  const roles = { value: initialRoles }, currentPage = { value: 'hotels' };
  const session = { epoch: 1 }, calls = [], toasts = [];
  const request = (url, options) => {
    assert.equal(url, '/users/roles');
    assert.equal(options.requestPolicy.scope, 'page');
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    const call = { url, settled: false, reply: data => { call.settled = true; resolve(data); },
      fail: error => { call.settled = true; reject(error); } };
    calls.push(call); return promise;
  };
  const context = {
    roles, currentPage, request,
    captureAuthSession: () => ({ epoch: session.epoch }),
    isAuthSessionCurrent: captured => captured.epoch === session.epoch,
    currentPageReadPolicy: (pageKey, priority) => ({ scope: 'page', pageKey, priority, epoch: session.epoch }),
    isPageLoadPolicyCurrent: policy => policy.pageKey === currentPage.value && policy.epoch === session.epoch,
    showToast: (message, type) => toasts.push({ message, type }),
  };
  const names = Object.keys(context);
  const loadRoles = Function(...names, loaderSource + '\nreturn loadRoles;')(...names.map(name => context[name]));
  t.after(() => {
    assert.ok(calls.every(call => call.settled), 'All local replies settled before test completion');
    t.diagnostic(JSON.stringify({ mode: 'synthetic loader unit', sourcePath, sourceSha256,
      requests: calls.length, pending: calls.filter(call => !call.settled).length,
      roles: roles.value.map(role => ({ id: role.id, name: role.name })), toasts }));
  });
  return { roles, currentPage, session, calls, toasts, loadRoles };
};

test('role structure failure rejects a required cold-cache read without inventing an empty success', async t => {
  const h = harness(t), initial = h.roles.value;
  const pending = h.loadRoles({ throwOnError: true });
  const rejection = assert.rejects(pending, /角色列表返回格式异常/);
  h.calls[0].reply({ code: 200, data: {} });
  await rejection;
  assert.equal(h.roles.value, initial, 'No substitute empty array was published');
  assert.deepEqual(h.toasts, [], 'Caller owns the required-read error feedback');
});

test('role refresh failure preserves the last snapshot and a successful retry can report a real empty list', async t => {
  const previous = [{ id: 2, name: 'beta_user', level: 2, status: 1 }];
  const h = harness(t, previous);
  const failed = h.loadRoles();
  h.calls[0].reply({ code: 200, data: null });
  assert.equal(await failed, null, 'Malformed response remains a failure');
  assert.equal(h.roles.value, previous, 'Last successful snapshot remains intact');
  assert.deepEqual(h.toasts, [{ message: '角色列表返回格式异常', type: 'error' }]);

  const next = [{ id: 3, name: 'normal_user', level: 3, status: 1 }];
  const retry = h.loadRoles({ throwOnError: true });
  h.calls[1].reply({ code: 200, data: next });
  assert.equal(await retry, next); assert.equal(h.roles.value, next);
  const empty = [], emptyRead = h.loadRoles({ throwOnError: true });
  h.calls[2].reply({ code: 200, data: empty });
  assert.equal(await emptyRead, empty); assert.equal(h.roles.value, empty);
  assert.equal(h.toasts.length, 1, 'Successful responses add no failure feedback');
});

test('obsolete and cancelled role reads retain their existing silent cancellation behavior', async t => {
  const previous = [{ id: 2, name: 'beta_user', level: 2, status: 1 }];
  const h = harness(t, previous);
  const old = h.loadRoles({ throwOnError: true });
  h.session.epoch += 1;
  const current = [{ id: 3, name: 'normal_user', level: 3, status: 1 }];
  h.roles.value = current;
  h.calls[0].reply({ code: 200, data: {} });
  assert.equal(await old, current, 'Obsolete malformed response cannot change the current snapshot');
  const cancelled = h.loadRoles({ throwOnError: true });
  h.calls[1].fail(Object.assign(new Error('Synthetic cancellation'), { name: 'AbortError' }));
  assert.equal(await cancelled, current); assert.equal(h.roles.value, current);
  assert.deepEqual(h.toasts, []);
});
