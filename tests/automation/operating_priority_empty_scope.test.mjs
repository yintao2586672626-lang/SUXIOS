import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import * as Vue from 'vue';

const source = readFileSync(process.env.PRIORITY_COMPONENT_SOURCE || 'public/components/system/operating-opportunity-lab.js', 'utf8');
const date = '2026-09-15';
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const overview = (problem = 'Synthetic current fact') => ({ code: 200, data: {
  tenant_id: 7, system_hotel_id: 80, business_date: date, today_state: 'not_saved',
  today: { selected: { candidate_key: 'fixture', problem, scope: {}, action: {} } },
  today_preview: { contract_version: 'daily_one_thing.v2', selection_policy: { full_candidate_list_exposed: false } },
} });

function mount() {
  const window = {};
  new Function('window', 'Vue', source)(window, Vue);
  const definition = window.SUXI_SYSTEM_COMPONENTS.OperatingOpportunityLabBody;
  const element = (type, text = '') => ({ type, text, props: {}, children: [], parent: null });
  const remove = node => { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); node.parent = null; };
  const renderer = Vue.createRenderer({
    createElement: element, createText: text => element('text', text), createComment: text => element('comment', text),
    setText: (node, text) => { node.text = text; }, setElementText: (node, text) => { node.text = text; node.children = []; },
    parentNode: node => node.parent, nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1] || null,
    insert(node, parent, anchor) { remove(node); node.parent = parent; const i = parent.children.indexOf(anchor); parent.children.splice(i < 0 ? parent.children.length : i, 0, node); },
    remove, patchProp: (node, key, old, value) => { node.props[key] = value; },
  });
  const requests = [];
  const root = element('root');
  const app = renderer.createApp({ ...definition, data: () => ({ ...definition.data(), businessDate: date }) }, {
    hotels: [{ id: 80, name: 'Synthetic A' }], selectedHotelId: 80,
    request: (url, options) => new Promise((resolve, reject) => { requests.push({ url, options, resolve, reject }); }),
  });
  const component = app.mount(root);
  const walk = node => [node, ...node.children.flatMap(walk)];
  const find = predicate => walk(root).find(predicate);
  return { component, requests, app, find,
    date(value) { find(node => node.type === 'input' && node.props.type === 'date').props.onInput({ target: { value } }); },
    hotel(value) { find(node => node.type === 'select').props.onChange({ target: { value } }); },
    card() { return find(node => node.props['data-testid'] === 'daily-one-thing-card'); },
    save() { return find(node => node.props['data-testid'] === 'daily-one-thing-save'); },
  };
}

async function ready(p) {
  await tick();
  for (const request of p.requests) request.resolve(overview());
  await tick();
  assert.ok(p.card());
  assert.ok(p.save());
}

for (const field of ['date', 'hotel']) test(`clearing the actual ${field} control removes old facts and permits exact recovery`, async () => {
  const p = mount();
  try {
    await ready(p);
    const count = p.requests.length;
    p[field]('');
    await tick();
    assert.equal(p.component.overview, null);
    assert.equal(p.component.loadedScope, '');
    assert.equal(p.component.loading, false);
    assert.equal(p.card(), undefined);
    assert.equal(p.save(), undefined);
    assert.match(p.component.error, /请选择/);
    await p.component.savePriority();
    assert.equal(p.requests.length, count, 'empty selection cannot send a request or save');
    p[field](field === 'date' ? date : '80');
    await tick();
    for (const request of p.requests.slice(count)) request.resolve(overview('Restored exact fact'));
    await tick();
    assert.equal(p.component.selected.problem, 'Restored exact fact');
    assert.equal(p.component.error, '');
    assert.ok(p.save());
  } finally { p.app.unmount(); }
});

for (const outcome of ['success', 'failure']) test(`cleared date invalidates an in-flight ${outcome} even after A-empty-A`, async () => {
  const p = mount();
  try {
    await ready(p);
    const oldRead = p.component.loadOverview();
    const old = p.requests.at(-1);
    p.date('');
    await tick();
    assert.equal(p.component.loading, false);
    assert.equal(p.component.overview, null);
    p.date(date);
    await tick();
    const fresh = p.requests.at(-1);
    assert.notEqual(fresh, old);
    if (outcome === 'success') old.resolve(overview('Obsolete fact')); else old.reject(new Error('Obsolete failure'));
    await oldRead;
    await tick();
    assert.equal(p.component.overview, null);
    assert.equal(p.component.loading, true, 'old completion must not clear the fresh request busy state');
    assert.equal(p.component.error, '');
    fresh.resolve(overview('Fresh fact'));
    await tick();
    assert.equal(p.component.selected.problem, 'Fresh fact');
  } finally { p.app.unmount(); }
});

test('loss of the current hotel list clears old facts and feedback without a request', async () => {
  const p = mount();
  try {
    await ready(p);
    p.component.feedbackStatus = 'useful';
    p.component.feedbackConfirmed = { key: 'old', reasonCode: 'useful', readSeq: 2 };
    const count = p.requests.length;
    p.app._instance.props.hotels = [];
    await tick();
    assert.equal(p.component.hotelId, '');
    assert.equal(p.component.overview, null);
    assert.equal(p.component.feedbackStatus, '');
    assert.equal(p.component.feedbackConfirmed, null);
    assert.equal(p.requests.length, count);
  } finally { p.app.unmount(); }
});
