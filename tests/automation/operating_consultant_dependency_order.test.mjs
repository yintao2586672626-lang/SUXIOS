import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync('public/components/system/operating-intelligence-loader.js', 'utf8');
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

function harness({ cached = false } = {}) {
  const loads = [], scripts = [], creates = [];
  const component = { name: 'SyntheticConsultant' };
  const window = {};
  const factory = { create() {
    creates.push(true);
    assert.equal(typeof window.SUXI_OPERATING_EVIDENCE_NAVIGATION?.createEvidenceNavigation, 'function');
    return { operatingQuestionConsultant: component };
  } };
  if (cached) window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL = factory;
  const document = {
    baseURI: 'http://127.0.0.1:18080/', scripts: [],
    createElement() {
      return { dataset: {}, listeners: {}, addEventListener(name, callback) { this.listeners[name] = callback; }, remove() {} };
    },
    head: { appendChild(script) {
      scripts.push(script.src);
      document.scripts.push(script);
      if (script.src.includes('hotel-data-analyst-components.js')) window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS = { create() {} };
      else window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL = factory;
      script.listeners.load();
    } },
  };
  let load = () => Promise.resolve();
  window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = asset => { loads.push(asset); return load(asset); };
  vm.runInNewContext(source, { window, document, URL, Date, setTimeout, clearTimeout });
  const Vue = { ref: value => ({ value }), shallowRef: value => ({ value }), defineAsyncComponent: value => value };
  const h = (type, props, children) => ({ type, props, children });
  const gate = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.create({ Vue, h }).operatingQuestionConsultant;
  const render = gate.setup({ ctx: { hotel_id: 7 } });
  return { window, loads, scripts, creates, component, render, setLoad: value => { load = value; } };
}

test('consultant waits for its deferred navigation runtime before loading or constructing full components', async () => {
  const h = harness(), pending = deferred();
  h.setLoad(asset => asset === 'app-deferred-helpers.min.js' ? pending.promise : Promise.resolve());
  const open = h.render().props.onClick();
  await flush();
  assert.deepEqual(h.loads, ['style.min.css', 'app-deferred-helpers.min.js']);
  assert.equal(h.scripts.length, 0);
  assert.equal(h.creates.length, 0);
  assert.equal(h.render().props.disabled, true);
  h.window.SUXI_OPERATING_EVIDENCE_NAVIGATION = { createEvidenceNavigation() {} };
  pending.resolve(); await open;
  assert.equal(h.creates.length, 1);
  assert.equal(h.scripts.length, 2);
  assert.equal(h.render().type, h.component);
  assert.equal(h.render().props.openOnMount, true);
});

test('an already loaded navigation factory is reused without a deferred request', async () => {
  const h = harness({ cached: true });
  h.window.SUXI_OPERATING_EVIDENCE_NAVIGATION = { createEvidenceNavigation() {} };
  await h.render().props.onClick();
  assert.deepEqual(h.loads, []);
  assert.equal(h.render().type, h.component);
});

for (const [label, api] of [['missing', undefined], ['boolean', true], ['object', {}], ['string', 'not-a-factory']]) {
  test(`a completed asset response with a ${label} navigation factory remains failed and can recover on retry`, async () => {
    const h = harness({ cached: true });
    h.window.SUXI_OPERATING_EVIDENCE_NAVIGATION = { createEvidenceNavigation: api };
    h.setLoad(() => Promise.resolve());
    await h.render().props.onClick();
    assert.deepEqual(h.loads, ['app-deferred-helpers.min.js']);
    assert.equal(h.scripts.length, 0);
    assert.equal(h.creates.length, 0, 'invalid dependency must be rejected before any full factory construction');
    assert.equal(h.render().children, '经营助手加载失败，点击重试');
    assert.equal(h.render().props.disabled, false);
    h.setLoad(() => { h.window.SUXI_OPERATING_EVIDENCE_NAVIGATION = { createEvidenceNavigation() {} }; return Promise.resolve(); });
    await h.render().props.onClick();
    assert.deepEqual(h.loads, ['app-deferred-helpers.min.js', 'app-deferred-helpers.min.js']);
    assert.equal(h.creates.length, 1);
    assert.equal(h.render().type, h.component);
  });
}

test('a failed deferred load exposes retry and never creates a consultant until the successful retry', async () => {
  const h = harness({ cached: true });
  h.setLoad(() => Promise.reject(new Error('Synthetic asset failure')));
  await h.render().props.onClick();
  assert.equal(h.creates.length, 0);
  assert.equal(h.render().children, '经营助手加载失败，点击重试');
  h.setLoad(() => { h.window.SUXI_OPERATING_EVIDENCE_NAVIGATION = { createEvidenceNavigation() {} }; return Promise.resolve(); });
  await h.render().props.onClick();
  assert.equal(h.render().type, h.component);
  assert.equal(h.creates.length, 1);
});
