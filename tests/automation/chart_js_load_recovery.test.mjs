import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/system-static.js', import.meta.url), 'utf8');

function harness({ existing = false, appendFails = false } = {}) {
  const nodes = [], requests = [], warnings = [];
  class Script extends EventTarget {
    dataset = {};
    remove() { const index = nodes.indexOf(this); if (index >= 0) nodes.splice(index, 1); }
    fire(type) { this.dispatchEvent(new Event(type)); this[`on${type}`]?.(); }
  }
  const document = {
    querySelector: () => nodes[0] || null,
    createElement: () => new Script(),
    head: { appendChild(script) {
      nodes.push(script);
      requests.push(script);
      if (appendFails) throw new Error('synthetic append failure');
    } },
  };
  if (existing) nodes.push(new Script());
  const window = {};
  vm.runInNewContext(source, { window, document, URLSearchParams,
    console: { warn: message => warnings.push(message) } });
  return { window, nodes, requests, warnings, load: window.SUXI_SYSTEM_STATIC.loadChartJs };
}

for (const outcome of ['error', 'load']) {
  test(`first script ${outcome} without Chart can recover on the next request`, async () => {
    const h = harness();
    const first = h.load();
    h.requests[0].fire(outcome);
    assert.equal(await first, null);
    const retry = h.load();
    assert.equal(h.requests.length, 2, 'retry must initiate a new request instead of awaiting a dead script');
    assert.equal(h.nodes.length, 1);
    const Chart = h.window.Chart = function Chart() {};
    h.requests[1].fire('load');
    assert.equal(await retry, Chart);
    assert.equal(await h.load(), Chart);
    assert.equal(h.requests.length, 2, 'successful library stays cached');
  });

  test(`an existing in-flight script ${outcome} without Chart settles and permits recovery`, async () => {
    const h = harness({ existing: true });
    const first = h.load();
    assert.equal(h.requests.length, 0, 'reuse the active request');
    h.nodes[0].fire(outcome);
    assert.equal(await first, null, 'load without a library is a failure, not undefined success');
    const retry = h.load();
    assert.equal(h.requests.length, 1);
    const Chart = h.window.Chart = function Chart() {};
    h.requests[0].fire('load');
    assert.equal(await retry, Chart);
  });
}

test('concurrent graph consumers share each attempt through failure and successful recovery', async () => {
  const h = harness();
  const a = h.load(), b = h.load();
  assert.equal(a, b);
  assert.equal(h.requests.length, 1);
  h.requests[0].fire('error');
  assert.deepEqual(await Promise.all([a, b]), [null, null]);
  const c = h.load(), d = h.load();
  assert.equal(c, d);
  assert.equal(h.requests.length, 2);
  const Chart = h.window.Chart = function Chart() {};
  h.requests[1].fire('load');
  assert.deepEqual(await Promise.all([c, d]), [Chart, Chart]);
});

test('already available Chart requires no script request', async () => {
  const h = harness();
  const Chart = h.window.Chart = function Chart() {};
  assert.equal(await h.load(), Chart);
  assert.equal(h.requests.length, 0);
});

test('existing in-flight success returns the loaded library without a duplicate request', async () => {
  const h = harness({ existing: true });
  const first = h.load();
  const Chart = h.window.Chart = function Chart() {};
  h.nodes[0].fire('load');
  assert.equal(await first, Chart);
  assert.equal(h.nodes.length, 1);
  assert.equal(h.requests.length, 0);
});

test('script attachment failure settles and leaves no dead script for retry', async () => {
  const h = harness({ appendFails: true });
  assert.equal(await h.load(), null);
  assert.equal(h.nodes.length, 0);
  assert.equal(await h.load(), null);
  assert.equal(h.requests.length, 2);
});
