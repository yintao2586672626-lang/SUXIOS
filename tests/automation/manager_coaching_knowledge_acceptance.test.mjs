import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import * as Vue from 'vue';

const env = { window: { Vue }, Intl, Date };
vm.runInNewContext(fs.readFileSync('public/components/system/manager-coaching-panel.js', 'utf8'), env);
const component = env.window.SUXI_SYSTEM_COMPONENTS.ManagerCoachingPanel;
function fixture() {
  const state = { ...component.data(), hotelId: 7, managerId: 42, cases: [], canManage: true };
  Object.defineProperty(state, 'scopeKey', { get: () => `${state.hotelId}:${state.managerId}` });
  for (const [key, method] of Object.entries(component.methods)) state[key] = method.bind(state);
  state.selected = { id: 11, hotel_id: 7, manager_user_id: 42, revision: 1,
    content: { title: 'Synthetic plan', acceptance_criteria: 'Private original case criteria' } };
  state.events = [{ id: 1, event_type: 'review', payload: {} }];
  state.begin('knowledge');
  return state;
}
function nodes(node) {
  if (!node || typeof node !== 'object') return [];
  return [node, ...(Array.isArray(node.children) ? node.children.flatMap(nodes) : [])];
}

for (const succeeds of [true, false]) {
  test(`knowledge experience collects independent anonymized acceptance text; save success=${succeeds}`, async () => {
    const state = fixture();
    const input = nodes(component.render.call(state)).find(node => node.props?.['aria-label'] === '脱敏验收方法');
    assert.ok(input, 'the real knowledge form exposes an editable acceptance field');
    assert.equal(input.type, 'textarea');
    assert.ok(!input.props.value, 'private plan criteria must not be copied into the public experience draft');
    const text = 'Use an anonymized checklist and compare three synthetic samples.';
    input.props.onInput({ target: { value: text } });
    const requests = [];
    const saved = { ...state.selected, revision: 2, content_digest: 'synthetic-fixed-digest' };
    const events = [...state.events, { id: 2, event_type: 'knowledge', payload: { knowledge_unit_id: 21 } }];
    state.request = async (url, options) => {
      requests.push({ url, options });
      if (options?.method === 'POST') {
        const payload = JSON.parse(options.body);
        assert.equal(payload.acceptance_criteria, text);
        assert.equal(payload.hotel_id, 7);
        assert.equal(payload.manager_user_id, 42);
        assert.equal(payload.expected_revision, 1);
        assert.ok(payload.idempotency_key);
        assert.ok(!options.body.includes('Private original case criteria'));
        return succeeds ? { code: 200, data: { plan: saved, events } } : { code: 422, message: 'Synthetic rejection' };
      }
      if (url.includes('/11?')) return { code: 200, data: { plan: saved, events } };
      return { code: 200, data: { hotel_id: 7, manager_user_id: 42, list: [saved] } };
    };
    await state.save();
    assert.equal(state.busy, false);
    if (succeeds) {
      assert.equal(requests[1].url, '/operation/manager-capability/coaching/11?hotel_id=7&manager_user_id=42');
      assert.equal(state.selected.revision, 2);
      assert.match(state.notice, /独立回读/);
    } else {
      assert.equal(requests.length, 1);
      assert.equal(state.record.acceptance_criteria, text);
      assert.equal(state.action, 'knowledge');
      assert.match(state.error, /Synthetic rejection/);
    }
  });
}
