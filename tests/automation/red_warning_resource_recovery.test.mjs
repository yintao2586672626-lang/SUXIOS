import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { findDynamicTailwindConstructions } from '../../scripts/lib/frontend_tailwind_build.mjs';

const main = readFileSync('public/app-main.js', 'utf8');
const loaderSource = readFileSync('public/components/system/app-main-components-loader.js', 'utf8');
const ref = value => ({ value });
const tick = () => new Promise(setImmediate);
test('style warnings ignore metadata identifiers while still rejecting dynamic CSS class names', () => {
    const file = 'output/red-warning-audit-20261003/tailwind-detector-fixture.txt';
    writeFileSync(file, [
        "'data-testid': `dual-ota-field-csv-text-${surface}`,",
        "'aria-label': 'text-' + label,",
        "class: `bg-${tone}-500`,",
        "class: 'text-' + tone,",
    ].join('\n'));
    const warnings = findDynamicTailwindConstructions([file]);
    assert.deepEqual(warnings.map(item => item.line), [3, 4]);
});
function productionFunction(name, context) {
    const start = main.indexOf(`            const ${name} =`);
    const end = main.indexOf('\n            };', start) + '\n            };'.length;
    assert.ok(start > 0 && end > start);
    return vm.runInNewContext(`${main.slice(start, end)}\n${name}`, context);
}

for (const [name, errorKey] of [['simulateOperationStrategy', 'strategy'], ['createOperationAction', 'actions'], ['loadOpeningProjects', null]]) {
    test(`${name}: resource failure is visible, releases loading, and retries through the actual handler`, async () => {
        const messages = [], calls = [];
        const state = {
            openingProjectsRequestSeq: 0, pageRequestGeneration: 1,
            captureAuthSession: () => 1, isAuthSessionCurrent: session => session === 1,
            token: ref('synthetic-session'), openingLoading: ref(false), openingProjects: ref([]),
            selectedOpeningProjectId: ref(''), syncOpeningProjectForm() {},
            operationError: ref({ strategy: '', actions: '' }), operationLoading: ref({ strategy: false, actions: false }),
            operationStrategyResult: ref(null), strategyForm: ref({ hotel_id: '80' }),
            actionForm: ref({ hotel_id: '80', action_title: 'synthetic', action_type: 'price_adjust', start_date: '2026-10-03' }),
            ensureOperationStaticReady: async () => { throw new Error('业务工具加载失败'); },
            normalizeOperationHotelSelection: () => '80', buildOperationStrategyPayload: () => ({ hotel_id: 80 }),
            operationErrorMessage: error => error.message, showToast: (...args) => messages.push(args),
            loadOperationActions: async () => {},
        };
        state.request = state.apiRequest = async url => {
            calls.push(url);
            return { code: 200, data: { list: [], result: 'synthetic' } };
        };
        const run = productionFunction(name, state);
        await run();
        assert.equal(calls.length, 0, 'resource failure must not proceed to a business request');
        assert.ok(messages.some(([message, type]) => message.includes('业务工具加载失败') && type === 'error'));
        if (errorKey) {
            assert.equal(state.operationError.value[errorKey], '业务工具加载失败');
            assert.equal(state.operationLoading.value[errorKey], false);
        } else assert.equal(state.openingLoading.value, false);
        state.ensureOperationStaticReady = async () => {};
        await run();
        assert.equal(calls.length, 1, 'retry reaches the original business handler');
        if (errorKey) assert.equal(state.operationError.value[errorKey], '');
    });
}

function loaderHarness() {
    const scripts = [], appended = [];
    const document = {
        querySelector(selector) {
            const src = selector.match(/="([^"]+)"/)[1];
            return scripts.find(script => script.dataset.suxiOnlineDataComponent === src) || null;
        },
        createElement() {
            const script = { dataset: {}, remove() { const index = scripts.indexOf(script); if (index >= 0) scripts.splice(index, 1); } };
            return script;
        },
        head: { appendChild(script) { scripts.push(script); appended.push(script); } },
    };
    const start = loaderSource.indexOf('        const onlineDataComponentScriptPromises =');
    const end = loaderSource.indexOf('        const readOnlineDataComponent =', start);
    const load = vm.runInNewContext(`${loaderSource.slice(start, end)}\nloadOnlineDataComponentScript`, { document });
    return { load, scripts, appended };
}

test('knowledge retry fetches again after a script loaded without registering its domain', async () => {
    const loader = loaderHarness();
    let registered = null;
    const state = {
        knowledgeCenterDomainLoadPromise: null, knowledgeCenterDomainScript: 'synthetic-knowledge.js',
        knowledgeCenterDomainRevision: ref(0), createKnowledgeCenterDomain: () => registered,
        loadOnlineDataComponentScript: loader.load,
    };
    const run = productionFunction('loadKnowledgeCenterDomain', state);
    const first = run();
    loader.appended[0].onload();
    await assert.rejects(first, /未完成注册/);
    assert.equal(loader.scripts.length, 0, 'failed registration releases the downloaded script');
    const retry = run();
    assert.equal(loader.appended.length, 2, 'retry must create a second real script load');
    registered = { loadKnowledgeCenter() {} };
    loader.appended[1].onload();
    assert.equal(await retry, registered);
    assert.equal(await run(), registered);
    assert.equal(loader.appended.length, 2, 'a registered domain still reuses its successful load');
});

test('script transport failure releases its cache and permits a successful retry', async () => {
    const loader = loaderHarness();
    const first = loader.load('synthetic.js');
    loader.appended[0].onerror();
    await assert.rejects(first, /加载失败/);
    const retry = loader.load('synthetic.js');
    assert.equal(loader.appended.length, 2);
    loader.appended[1].onload();
    await retry;
    await loader.load('synthetic.js');
    assert.equal(loader.appended.length, 2);
});

test('maintenance registration failure also releases the shared script cache', async () => {
    const loader = loaderHarness();
    const start = loaderSource.indexOf('        systemComponents.AutonomousMaintenanceWorkbench =');
    const end = loaderSource.indexOf('        const requireSystemComponent =', start);
    const state = {
        systemComponents: {}, window: {}, h() {}, loadOnlineDataComponentScript: loader.load,
        Vue: { defineAsyncComponent: options => options },
    };
    vm.runInNewContext(loaderSource.slice(start, end), state);
    const options = state.systemComponents.AutonomousMaintenanceWorkbench;
    const first = options.loader();
    loader.appended[0].onload();
    await assert.rejects(first, /未完成注册/);
    const retry = options.loader();
    assert.equal(loader.appended.length, 2);
    state.window.SUXI_MAINTENANCE_WORKBENCH = { create: () => ({ name: 'SyntheticWorkbench' }) };
    loader.appended[1].onload();
    assert.equal((await retry).name, 'SyntheticWorkbench');
    await tick();
});
