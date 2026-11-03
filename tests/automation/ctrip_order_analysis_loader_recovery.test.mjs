import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const entrySource = fs.readFileSync('public/components/online-data/ctrip-order-analysis-loader.js', 'utf8');
const fallbackSource = fs.readFileSync('public/components/system/app-main-components.js', 'utf8');
const fallbackStart = fallbackSource.indexOf('    const systemComponents = ');
const fallbackEnd = fallbackSource.indexOf('    const requireSystemComponent = ', fallbackStart);
assert.ok(fallbackStart >= 0 && fallbackEnd > fallbackStart);
const contracts = [
  { name: 'initial entry', source: entrySource, dataset: 'suxiCtripOrderAnalysis' },
  { name: 'fallback entry', source: fallbackSource.slice(fallbackStart, fallbackEnd), dataset: 'suxiCtripOrderAnalysisBody' },
];

function fixture(contract, { existing = false, registered = false } = {}) {
  const created = [], connected = [], removed = [], definitions = [];
  const body = { name: 'RegisteredOrderAnalysisBody' };
  const scriptSrc = contract.source.match(/(?:scriptSrc|ctripOrderAnalysisPanelBodyScript) = '([^']+)'/)[1];
  const makeScript = () => {
    const listeners = new Map();
    return {
      dataset: {}, parentNode: null,
      addEventListener(type, callback, options = {}) {
        const entries = listeners.get(type) || [];
        entries.push({ callback, once: options.once });
        listeners.set(type, entries);
      },
      removeEventListener(type, callback) {
        listeners.set(type, (listeners.get(type) || []).filter((entry) => entry.callback !== callback));
      },
      countListeners() { return [...listeners.values()].reduce((count, entries) => count + entries.length, 0); },
      emit(type) {
        for (const entry of [...(listeners.get(type) || [])]) {
          entry.callback();
          if (entry.once) this.removeEventListener(type, entry.callback);
        }
      },
      remove() { this.parentNode?.removeChild(this); },
    };
  };
  const document = {
    querySelector(selector) {
      assert.ok(selector.includes(scriptSrc));
      return connected.find((script) => script.dataset[contract.dataset] === scriptSrc) || null;
    },
    createElement(tag) {
      assert.equal(tag, 'script');
      const script = makeScript(); created.push(script); return script;
    },
    head: {
      appendChild(script) { script.parentNode = this; connected.push(script); return script; },
      removeChild(script) {
        const index = connected.indexOf(script); assert.ok(index >= 0);
        connected.splice(index, 1); removed.push(script); script.parentNode = null; return script;
      },
    },
  };
  let original = null;
  if (existing) {
    original = makeScript(); original.src = scriptSrc; original.dataset[contract.dataset] = scriptSrc;
    document.head.appendChild(original);
  }
  const registry = registered ? { CtripOrderAnalysisPanelBody: body } : {};
  const h = (tag, props, children) => ({ tag, props, children });
  const Vue = { h, defineAsyncComponent(options) { definitions.push(options); return options; } };
  vm.runInNewContext(contract.source, { window: { SUXI_SYSTEM_COMPONENTS: registry }, document, Vue, h },
    { filename: contract.name });
  return { body, registry, config: definitions[0], created, connected, removed, original, scriptSrc };
}

function assertReleased(f, script) {
  assert.equal(script.parentNode, null, 'failed script must leave this entry');
  assert.ok(f.removed.includes(script));
  assert.equal(script.countListeners(), 0, 'both load and error listeners must be released');
}

async function succeed(f) {
  const pending = f.config.loader();
  const script = f.created.at(-1);
  assert.ok(script);
  f.registry.CtripOrderAnalysisPanelBody = f.body;
  script.emit('load');
  assert.equal(await pending, f.body);
  assert.equal(script.countListeners(), 0);
}

for (const contract of contracts) {
  test(`${contract.name}: load without registration rejects, releases and permits a fresh successful request`, { timeout: 1000 }, async () => {
    const f = fixture(contract);
    const failed = f.config.loader();
    const rejected = assert.rejects(failed, /未完成注册/);
    f.created[0].emit('load');
    await rejected;
    assertReleased(f, f.created[0]);
    await succeed(f);
    assert.equal(f.created.length, 2);
  });

  test(`${contract.name}: a network error rejects and the next request recovers`, { timeout: 1000 }, async () => {
    const f = fixture(contract);
    const failed = f.config.loader();
    const rejected = assert.rejects(failed, /加载失败/);
    f.created[0].emit('error');
    await rejected;
    assertReleased(f, f.created[0]);
    await succeed(f);
    assert.equal(f.created.length, 2);
  });

  for (const event of ['load', 'error']) {
    test(`${contract.name}: an existing script ${event} failure releases the old node and recovers`, { timeout: 1000 }, async () => {
      const f = fixture(contract, { existing: true });
      const failed = f.config.loader();
      assert.equal(f.created.length, 0, 'a pending existing script must be reused');
      const rejected = assert.rejects(failed, event === 'load' ? /未完成注册/ : /加载失败/);
      f.original.emit(event);
      await rejected;
      assertReleased(f, f.original);
      await succeed(f);
      assert.equal(f.created.length, 1);
    });
  }

  test(`${contract.name}: repeated failures remain failures and a later explicit request can succeed`, { timeout: 1000 }, async () => {
    const f = fixture(contract);
    for (let attempt = 0; attempt < 2; attempt += 1) {
      const failed = f.config.loader();
      assert.equal(f.created.length, attempt + 1);
      const rejected = assert.rejects(failed, /加载失败/);
      f.created[attempt].emit('error');
      await rejected;
      assertReleased(f, f.created[attempt]);
    }
    await succeed(f);
    assert.equal(f.created.length, 3);
  });

  test(`${contract.name}: concurrent callers share one promise and registered bodies use the fast path`, { timeout: 1000 }, async () => {
    const f = fixture(contract);
    const first = f.config.loader(), second = f.config.loader();
    assert.equal(first, second);
    assert.equal(f.created.length, 1);
    f.registry.CtripOrderAnalysisPanelBody = f.body;
    f.created[0].emit('load');
    assert.equal(await first, f.body); assert.equal(await second, f.body);
    assert.equal(f.created[0].countListeners(), 0);
    assert.equal(await f.config.loader(), f.body);
    assert.equal(f.created.length, 1);
    const ready = fixture(contract, { registered: true });
    assert.equal(await ready.config.loader(), ready.body);
    assert.equal(ready.created.length, 0);
  });

  test(`${contract.name}: async component retries once, then preserves the existing error component`, () => {
    const f = fixture(contract);
    assert.equal(typeof f.config.onError, 'function');
    let retries = 0, failures = 0;
    const error = new Error('synthetic network failure');
    f.config.onError(error, () => { retries += 1; }, () => { failures += 1; }, 1);
    assert.equal(retries, 1); assert.equal(failures, 0);
    f.config.onError(error, () => { retries += 1; }, () => { failures += 1; }, 2);
    assert.equal(retries, 1); assert.equal(failures, 1);
    const rendered = f.config.errorComponent.render();
    assert.equal(rendered.props['data-testid'], 'ctrip-order-analysis-load-error');
    assert.match(rendered.children, /加载失败，请刷新页面重试/);
  });
}

test('both entries request the same current body version', () => {
  assert.equal(fixture(contracts[0]).scriptSrc, fixture(contracts[1]).scriptSrc);
});
