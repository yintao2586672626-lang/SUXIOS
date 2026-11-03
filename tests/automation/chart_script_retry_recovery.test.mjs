import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('public/system-static.js', 'utf8');

const createFixture = ({ existingScript = false, Chart } = {}) => {
  const created = [];
  const connected = [];
  const removed = [];
  const warnings = [];
  const makeScript = () => {
    const listeners = new Map();
    return {
      dataset: {},
      parentNode: null,
      addEventListener(type, callback, options = {}) {
        const entries = listeners.get(type) || [];
        entries.push({ callback, once: options.once });
        listeners.set(type, entries);
      },
      removeEventListener(type, callback) {
        listeners.set(type, (listeners.get(type) || []).filter((entry) => entry.callback !== callback));
      },
      listenerCount(type) {
        return (listeners.get(type) || []).length + (typeof this[`on${type}`] === 'function' ? 1 : 0);
      },
      emit(type) {
        this[`on${type}`]?.();
        for (const entry of [...(listeners.get(type) || [])]) {
          entry.callback();
          if (entry.once) this.removeEventListener(type, entry.callback);
        }
      },
      remove() {
        this.parentNode?.removeChild(this);
      },
    };
  };
  const document = {
    querySelector(selector) {
      assert.equal(selector, 'script[data-suxi-chartjs="1"]');
      return connected.find((script) => script.dataset.suxiChartjs === '1') || null;
    },
    createElement(tag) {
      assert.equal(tag, 'script');
      const script = makeScript();
      created.push(script);
      return script;
    },
    head: {
      appendChild(script) {
        script.parentNode = this;
        connected.push(script);
        return script;
      },
      removeChild(script) {
        const index = connected.indexOf(script);
        assert.ok(index >= 0);
        connected.splice(index, 1);
        removed.push(script);
        script.parentNode = null;
        return script;
      },
    },
  };
  let existing = null;
  if (existingScript) {
    existing = makeScript();
    existing.dataset.suxiChartjs = '1';
    document.head.appendChild(existing);
  }
  const window = Chart ? { Chart } : {};
  vm.runInNewContext(source, { window, document, console: { warn: (message) => warnings.push(message) } },
    { filename: 'public/system-static.js' });
  return { window, api: window.SUXI_SYSTEM_STATIC, created, connected, removed, warnings, existing };
};

const assertReleased = (fixture, script) => {
  assert.equal(script.parentNode, null, 'failed loader script must leave the document');
  assert.ok(fixture.removed.includes(script));
  assert.equal(script.listenerCount('load'), 0, 'load listener must be released');
  assert.equal(script.listenerCount('error'), 0, 'error listener must be released');
};

test('two consecutive script errors release failed nodes and a third request succeeds', { timeout: 1000 }, async () => {
  const fixture = createFixture();
  for (let attempt = 0; attempt < 2; attempt += 1) {
    const promise = fixture.api.loadChartJs();
    assert.equal(fixture.created.length, attempt + 1);
    const script = fixture.created[attempt];
    assert.equal(script.src, '/vendor/chart.umd.js');
    assert.equal(script.async, true);
    script.emit('error');
    assert.equal(await promise, null, 'existing warning + null failure contract must survive');
    assertReleased(fixture, script);
    assert.equal(fixture.connected.length, 0);
  }
  const recovery = fixture.api.loadChartJs();
  assert.equal(fixture.created.length, 3);
  fixture.window.Chart = function Chart() {};
  fixture.created[2].emit('load');
  assert.equal(await recovery, fixture.window.Chart);
  assert.equal(fixture.created[2].listenerCount('load'), 0);
  assert.equal(fixture.created[2].listenerCount('error'), 0);
  assert.deepEqual(fixture.warnings, ['Chart.js加载失败', 'Chart.js加载失败']);
});

test('a newly loaded script without Chart fails explicitly and the next call recovers', { timeout: 1000 }, async () => {
  const fixture = createFixture();
  const first = fixture.api.loadChartJs();
  fixture.created[0].emit('load');
  assert.equal(await first, null);
  assertReleased(fixture, fixture.created[0]);
  assert.deepEqual(fixture.warnings, ['Chart.js加载后未暴露Chart对象']);
  const retry = fixture.api.loadChartJs();
  assert.equal(fixture.created.length, 2);
  fixture.window.Chart = function Chart() {};
  fixture.created[1].emit('load');
  assert.equal(await retry, fixture.window.Chart);
});

test('an existing loader script without Chart fails explicitly and leaves a retry path', { timeout: 1000 }, async () => {
  const fixture = createFixture({ existingScript: true });
  const promise = fixture.api.loadChartJs();
  assert.equal(fixture.created.length, 0, 'pending existing script must be reused');
  fixture.existing.emit('load');
  assert.equal(await promise, null, 'load event alone is not a usable Chart result');
  assertReleased(fixture, fixture.existing);
  assert.deepEqual(fixture.warnings, ['Chart.js加载后未暴露Chart对象']);
  const retry = fixture.api.loadChartJs();
  assert.equal(fixture.created.length, 1);
  fixture.window.Chart = function Chart() {};
  fixture.created[0].emit('load');
  assert.equal(await retry, fixture.window.Chart);
});

test('an existing script error releases its listeners and creates a new request on retry', { timeout: 1000 }, async () => {
  const fixture = createFixture({ existingScript: true });
  const first = fixture.api.loadChartJs();
  fixture.existing.emit('error');
  assert.equal(await first, null);
  assertReleased(fixture, fixture.existing);
  const retry = fixture.api.loadChartJs();
  assert.equal(fixture.created.length, 1);
  fixture.window.Chart = function Chart() {};
  fixture.created[0].emit('load');
  assert.equal(await retry, fixture.window.Chart);
  assert.deepEqual(fixture.warnings, ['Chart.js加载失败']);
});

test('concurrent callers share one promise and one successful script request', { timeout: 1000 }, async () => {
  const fixture = createFixture();
  const first = fixture.api.loadChartJs();
  const second = fixture.api.loadChartJs();
  assert.equal(first, second);
  assert.equal(fixture.created.length, 1);
  assert.equal(fixture.connected.length, 1);
  fixture.window.Chart = function Chart() {};
  fixture.created[0].emit('load');
  assert.equal(await first, fixture.window.Chart);
  assert.equal(await second, fixture.window.Chart);
  assert.equal(fixture.connected.length, 1, 'a usable script is retained');
  assert.deepEqual(fixture.warnings, []);
});

test('an already available Chart returns directly without adding a script', async () => {
  const Chart = function Chart() {};
  const fixture = createFixture({ Chart });
  assert.equal(await fixture.api.loadChartJs(), Chart);
  assert.equal(fixture.created.length, 0);
  assert.equal(fixture.connected.length, 0);
  assert.deepEqual(fixture.warnings, []);
});
