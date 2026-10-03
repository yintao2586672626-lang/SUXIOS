import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const from = source.indexOf('const saveCtripProfileField = async () => {');
const to = source.indexOf('const toggleCtripProfileFieldEnabled = async', from);
assert.ok(from >= 0 && to > from);
const helperContext = vm.createContext({ window: {}, URL });
vm.runInContext(readFileSync('public/ctrip-static.js', 'utf8'), helperContext);
const valid = () => ({ field_key: 'order_amount', field_name: '隔离订单金额', page_url: 'https://fixture.invalid/page', request_url: 'https://fixture.invalid/api', json_path: 'data.order_amount', target_value: 'order_amount', value_meaning: '隔离渠道订单金额', enabled: false, notes: '保留草稿' });
const harness = (form, reply = { code: 200, message: '字段配置已保存' }) => {
  const calls = [], toasts = [];
  const ref = { value: { ...form } }, saving = { value: false };
  let resets = 0, reloads = 0;
  const ctx = vm.createContext({
    ctripProfileFieldForm: ref, ctripProfileFieldSaving: saving,
    buildCtripProfileFieldSavePayload: helperContext.window.SUXI_CTRIP_STATIC.buildCtripProfileFieldSavePayload,
    showToast: (message, type) => toasts.push({ message, type }),
    request: async (url, options) => { calls.push({ url, body: JSON.parse(options.body) }); return reply; },
    resetCtripProfileFieldForm: () => { resets++; ref.value = {}; },
    clearCtripProfileFieldCache: () => {}, loadCtripProfileFields: async () => { reloads++; },
  });
  vm.runInContext(`${source.slice(from, to)}; this.save = saveCtripProfileField;`, ctx);
  return { save: ctx.save, ref, saving, calls, toasts, counts: () => ({ resets, reloads }) };
};

for (const key of ['page_url', 'request_url', 'json_path']) {
  test(`new field with missing ${key} is stopped before POST and retains its draft`, async () => {
    const input = valid(); input[key] = '';
    const h = harness(input); await h.save();
    assert.equal(h.calls.length, 0);
    assert.deepEqual(h.ref.value, input);
    assert.equal(h.saving.value, false);
    assert.equal(h.toasts.at(-1).type, 'error');
  });
}

test('JSON path can supply the value field through the existing helper and complete save refreshes once', async () => {
  const input = valid(); input.target_value = '';
  const h = harness(input); await h.save();
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].body.target_value, 'order_amount');
  assert.deepEqual(h.counts(), { resets: 1, reloads: 1 });
  assert.equal(h.saving.value, false);
});

test('backend rejection preserves correction inputs and never reports success', async () => {
  const input = valid(); const h = harness(input, { code: 400, message: '本次未保存' }); await h.save();
  assert.equal(h.calls.length, 1);
  assert.deepEqual(h.ref.value, input);
  assert.deepEqual(h.counts(), { resets: 0, reloads: 0 });
  assert.equal(h.toasts.at(-1).message, '本次未保存');
  assert.equal(h.toasts.at(-1).type, 'error');
  assert.equal(h.saving.value, false);
});

test('legacy edit does not acquire new-field evidence requirements and displays the server recheck message', async () => {
  const input = { ...valid(), id: 'fixture_saved', request_url: '', json_path: '' };
  const h = harness(input, { code: 200, message: '字段配置已保存，需重新核验样本' }); await h.save();
  assert.equal(h.calls.length, 1);
  assert.match(h.toasts.at(-1).message, /需重新核验/);
  assert.deepEqual(h.counts(), { resets: 1, reloads: 1 });
});
