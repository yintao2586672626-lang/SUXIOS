import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {createHash} from 'node:crypto';

const helper = readFileSync('public/form-operation-support.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');

test('room draft isolation helper has a current cache-busting runtime version', () => {
    const main = readFileSync('public/app-main.js', 'utf8');
    const hash = createHash('sha256').update(helper).digest('hex').slice(0, 10);
    assert.ok(main.includes(`const formOperationSupportScriptVersion = '20260715-h${hash}';`));
});

function fixture(disabled = true) {
    const memory = new Map();
    const listeners = {};
    let scan;
    let reads = 0;
    let writes = 0;
    const scope = {
        dataset: {formKey: 'isolated-room-form'}, tagName: 'FORM',
        getAttribute: name => name === 'data-form-draft' && disabled ? 'off' : null,
        querySelectorAll: () => [field],
    };
    const field = {
        name: 'room_name', type: 'text', value: '',
        getAttribute: () => null,
        closest: selector => selector === '[data-form-draft="off"]' ? (disabled ? scope : null) : scope,
        dispatchEvent: event => listeners[event.type]?.({target: field}),
    };
    const document = {readyState: 'complete', body: {},
        querySelectorAll: selector => selector.startsWith('[data-testid') ? [] : [scope],
        addEventListener: (name, handler) => {listeners[name] = handler;},
    };
    field.ownerDocument = document;
    memory.set('suxios.form.draft.v1:isolated-room-form', JSON.stringify({room_name: '另一酒店的旧草稿'}));
    const app = {document, localStorage: {
        getItem: key => {reads++; return memory.get(key) ?? null;},
        setItem: (key, value) => {writes++; memory.set(key, value);},
        removeItem: key => memory.delete(key),
    }};
    const context = vm.createContext({window: app, Event: class {constructor(type) {this.type = type;}},
        MutationObserver: class {constructor(callback) {scan = callback;} observe() {}},
    });
    vm.runInContext(helper, context);
    return {field, memory, listeners, scan, counters: () => ({reads, writes})};
}

test('room pricing form explicitly opts out of cross-page generic drafts', () => {
    const panel = template.slice(template.indexOf('data-testid="agent-room-type-pricing-guard"'));
    assert.match(panel.slice(0, panel.indexOf('</form>')), /<form[^>]*data-form-draft="off"/);
});

test('opted-out room form never reads or restores old generic drafts', () => {
    const f = fixture();
    f.scan();
    assert.equal(f.field.value, '');
    assert.deepEqual(f.counters(), {reads: 0, writes: 0});
});

test('opted-out input events do not persist a new generic draft', () => {
    const f = fixture();
    f.field.value = '本酒店尚未保存的编辑';
    f.listeners.input({target: f.field});
    f.listeners.change({target: f.field});
    assert.deepEqual(f.counters(), {reads: 0, writes: 0});
});

test('clearing an opted-out form remains empty after later page mutations', () => {
    const f = fixture();
    f.field.value = '本酒店尚未保存的编辑';
    f.listeners.input({target: f.field});
    f.field.value = '';
    f.scan();
    f.scan();
    assert.equal(f.field.value, '');
});

test('existing generic forms retain restore and input persistence behavior', () => {
    const f = fixture(false);
    assert.equal(f.field.value, '另一酒店的旧草稿');
    f.field.value = '通用表单编辑';
    f.listeners.input({target: f.field});
    assert.equal(JSON.parse(f.memory.get('suxios.form.draft.v1:isolated-room-form')).room_name, '通用表单编辑');
});
