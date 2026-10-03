import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync('public/app-main.js', 'utf8');
const helper = readFileSync('public/form-operation-support.js', 'utf8');
const start = main.indexOf('const loadFormOperationSupport = () => {');
const end = main.indexOf('const clearFormOperationSupportLoadTimer', start);
assert.ok(start >= 0 && end > start);

function fixture() {
    const memory = new Map([
        ['suxios.form.draft.v1:business-form', JSON.stringify({ description: '另一用户和酒店的旧草稿' })],
    ]);
    const attrs = new Map();
    const listeners = {};
    let reads = 0;
    let writes = 0;
    let mutationScan;
    const appNode = { setAttribute: (key, value) => attrs.set(key, value) };
    const optedOut = () => attrs.get('data-form-draft') === 'off' ? appNode : null;
    const scope = {
        dataset: { formKey: 'business-form' }, tagName: 'FORM',
        getAttribute: () => null,
        closest: () => optedOut(),
        querySelectorAll: () => [field],
    };
    const field = {
        name: 'description', type: 'text', value: '',
        getAttribute: () => null,
        closest: selector => selector === '[data-form-draft="off"]' ? optedOut() : scope,
        dispatchEvent: event => listeners[event.type]?.({ target: field }),
    };
    const document = {
        readyState: 'loading', body: {},
        getElementById: id => id === 'app' ? appNode : null,
        querySelectorAll: selector => selector.startsWith('[data-testid') ? [] : [scope],
        addEventListener: (name, handler) => { listeners[name] = handler; },
    };
    field.ownerDocument = document;
    const app = { document, localStorage: {
        getItem: key => { reads += 1; return memory.get(key) ?? null; },
        setItem: (key, value) => { writes += 1; memory.set(key, value); },
        removeItem: key => memory.delete(key),
    } };
    const context = vm.createContext({
        window: app, document, console,
        Event: class { constructor(type) { this.type = type; } },
        MutationObserver: class { constructor(callback) { mutationScan = callback; } observe() {} },
    });
    vm.runInContext(helper, context);
    vm.runInContext(`${main.slice(start, end)}; globalThis.load = loadFormOperationSupport;`, context);
    return { context, field, listeners, memory, attrs, scan: () => mutationScan?.(), counters: () => ({ reads, writes }) };
}

test('authenticated app refuses unscoped legacy DOM drafts before initializing its helper', async () => {
    const f = fixture();
    await f.context.load();
    assert.equal(f.attrs.get('data-form-draft'), 'off');
    assert.equal(f.field.value, '');
    assert.deepEqual(f.counters(), { reads: 0, writes: 0 });
});

test('hotel or user changes and DOM mutations cannot restore or persist a generic business draft', async () => {
    const f = fixture();
    await f.context.load();
    f.field.value = '本酒店尚未保存的编辑';
    f.listeners.input({ target: f.field });
    f.listeners.change({ target: f.field });
    f.field.value = '';
    f.scan();
    f.scan();
    await f.context.load();
    assert.equal(f.field.value, '');
    assert.deepEqual(f.counters(), { reads: 0, writes: 0 });
    assert.equal(JSON.parse(f.memory.get('suxios.form.draft.v1:business-form')).description, '另一用户和酒店的旧草稿');
});
