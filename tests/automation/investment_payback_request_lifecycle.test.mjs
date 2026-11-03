import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');

const productionSlice = (start, end, includeEnd = false) => {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first + start.length);
    assert.notEqual(first, -1, `missing production start: ${start}`);
    assert.notEqual(last, -1, `missing production end: ${end}`);
    return source.slice(first, last + (includeEnd ? end.length : 0));
};

const deferred = () => {
    let resolve;
    const promise = new Promise(complete => { resolve = complete; });
    return { promise, resolve };
};

const flushRequests = async () => {
    for (let turn = 0; turn < 10; turn += 1) await Promise.resolve();
};

const createHarness = () => {
    const network = deferred();
    const calls = [];
    const context = vm.createContext({
        AbortController, DOMException, Date, Headers, JSON, Map, Math, Number,
        Object, Promise, Set, String, URL, URLSearchParams, structuredClone,
        API_BASE: '/api',
        authSessionEpoch: 7,
        pageRequestGeneration: 4,
        currentPage: { value: 'investment-payback' },
        authContext: { value: { tenantId: 'tenant-a', hotelId: '80' } },
        user: { value: { id: 9, hotel_id: 80 } },
        token: { value: 'synthetic-session-a' },
        filterReportHotel: { value: '80' },
        revenueAiBusinessDate: { value: '2026-10-02' },
        coreOperationsTargetDate: { value: '2026-10-02' },
        console: { error() {}, warn() {} },
        clearAuthSessionIfCurrent: () => false,
        isTerminalAuthFailureResponse: () => false,
        normalizeTokenStatusFromReason: value => value,
        terminalAuthFailureReason: () => '',
        showToast() {},
        applyAuthContext() {},
        ensureBusinessReadContext: async () => {},
        withBusinessRequestContext: (url, options) => ({ url, options }),
        fetch: (url, options) => {
            calls.push({ url, options });
            return network.promise;
        },
    });
    // Business context hydration and transport are synthetic; cancellation,
    // scope capture, auth identity, and request coordination execute production code.
    context.currentBusinessRequestContext = () => ({
        tenant_id: context.authContext.value.tenantId,
        system_hotel_id: context.filterReportHotel.value,
    });
    const authHelpers = productionSlice('const captureAuthSession = () => ({', 'const createDefaultAuthContext = ');
    const abortHelper = productionSlice('const createRequestAbortError = ', 'const clearPageLifecycleTimers = ');
    const policies = productionSlice('const currentPageReadPolicy = ', 'const cancelPageLoadRequests = ');
    const coordinator = productionSlice('const COORDINATED_GET_MAX_CONCURRENCY = 3;', 'const apiRequest = request;', true);
    vm.runInContext(`${authHelpers}\n${abortHelper}\n${policies}\n${coordinator}\n
        globalThis.__requests = { request, currentPageReadPolicy, cancelPageGetConsumers, coordinatedGetRequests };`, context);
    return {
        context,
        calls,
        ...context.__requests,
        observe: (url, options = { withBusinessContext: false }) => context.__requests.request(url, options).then(
            value => ({ status: 'fulfilled', value }),
            error => ({ status: 'rejected', error }),
        ),
        succeed: () => network.resolve({
            ok: true,
            status: 200,
            json: async () => ({ code: 200, data: { list: [{ id: 41, tenant_id: 'tenant-a' }] } }),
        }),
    };
};

const assertCancelled = outcome => {
    assert.equal(outcome.status, 'rejected');
    assert.equal(outcome.error.name, 'AbortError');
    assert.match(outcome.error.message, /Page request was cancelled/);
};

test('payback GET survives unrelated report hotel and date changes on the same page', async () => {
    const harness = createHarness();
    const pending = harness.observe('/investment-payback/projects?as_of=2026-10-03');
    await flushRequests();
    assert.equal(harness.calls.length, 1);
    harness.context.filterReportHotel.value = '81';
    harness.context.revenueAiBusinessDate.value = '2026-10-03';
    harness.succeed();
    const outcome = await pending;
    assert.equal(outcome.status, 'fulfilled', outcome.error?.message);
    assert.equal(outcome.value.code, 200);
    assert.equal(outcome.value.data.list[0].id, 41);
    assert.equal(harness.calls[0].url, '/api/investment-payback/projects?as_of=2026-10-03');
    assert.equal(harness.calls[0].options.signal.aborted, false);
    assert.equal(harness.context.currentPage.value, 'investment-payback');
});

test('payback GET retains its tenant, page generation and authentication session policy', async () => {
    const harness = createHarness();
    const pending = harness.observe('/investment-payback/projects');
    await flushRequests();
    const entry = [...harness.coordinatedGetRequests.values()][0];
    const consumer = [...entry.consumers.values()][0];
    assert.equal(consumer.tenantId, 'tenant-a');
    assert.equal(consumer.pageKey, 'investment-payback');
    assert.equal(consumer.pageGeneration, 4);
    assert.equal(consumer.requestSession.epoch, 7);
    assert.equal(consumer.systemHotelId, '');
    assert.equal(consumer.businessDate, '');
    harness.succeed();
    assert.equal((await pending).status, 'fulfilled');
});

for (const [scope, mutate] of [
    ['tenant', context => { context.authContext.value.tenantId = 'tenant-b'; }],
    ['authentication epoch', context => { context.authSessionEpoch += 1; }],
    ['authentication token', context => { context.token.value = 'synthetic-session-b'; }],
    ['page generation', context => { context.pageRequestGeneration += 1; }],
    ['current page', context => { context.currentPage.value = 'compass'; }],
]) {
    test(`payback GET still rejects a changed ${scope} before completion`, async () => {
        const harness = createHarness();
        const pending = harness.observe('/investment-payback/projects');
        await flushRequests();
        mutate(harness.context);
        harness.succeed();
        assertCancelled(await pending);
    });
}

test('leaving the payback page cancels its consumer and aborts its transport', async () => {
    const harness = createHarness();
    const pending = harness.observe('/investment-payback/projects');
    await flushRequests();
    harness.context.currentPage.value = 'compass';
    harness.context.pageRequestGeneration += 1;
    harness.cancelPageGetConsumers('investment-payback');
    assert.equal(harness.calls[0].options.signal.aborted, true);
    assertCancelled(await pending);
    harness.succeed();
    await flushRequests();
    assert.equal(harness.coordinatedGetRequests.size, 0);
});

for (const [scope, mutate] of [
    ['hotel', context => { context.filterReportHotel.value = '81'; }],
    ['business date', context => { context.revenueAiBusinessDate.value = '2026-10-03'; }],
]) {
    test(`other report GETs retain their ${scope} cancellation guard`, async () => {
        const harness = createHarness();
        const pending = harness.observe('/dashboard?hotel_id=80');
        await flushRequests();
        mutate(harness.context);
        harness.succeed();
        assertCancelled(await pending);
    });
}

test('an explicit payback request policy is preserved rather than replaced', async () => {
    const harness = createHarness();
    const policy = harness.currentPageReadPolicy('investment-payback', 'action');
    const pending = harness.observe('/investment-payback/projects', { withBusinessContext: false, requestPolicy: policy });
    await flushRequests();
    const consumer = [...[...harness.coordinatedGetRequests.values()][0].consumers.values()][0];
    assert.equal(consumer.priority, 'action');
    assert.equal(consumer.systemHotelId, '80');
    assert.equal(consumer.businessDate, '2026-10-02');
    harness.context.filterReportHotel.value = '81';
    harness.context.revenueAiBusinessDate.value = '2026-10-03';
    harness.succeed();
    assertCancelled(await pending);
    assert.equal(policy.systemHotelId, '80');
    assert.equal(policy.businessDate, '2026-10-02');
});

test('payback requests with attached business context retain their report scope', async () => {
    const harness = createHarness();
    const pending = harness.observe('/investment-payback/projects', { withBusinessContext: true });
    await flushRequests();
    harness.context.filterReportHotel.value = '81';
    harness.context.revenueAiBusinessDate.value = '2026-10-03';
    harness.succeed();
    assertCancelled(await pending);
});

test('similarly named non-payback routes retain their report scope', async () => {
    const harness = createHarness();
    const pending = harness.observe('/investment-payback-preview/projects');
    await flushRequests();
    harness.context.filterReportHotel.value = '81';
    harness.context.revenueAiBusinessDate.value = '2026-10-03';
    harness.succeed();
    assertCancelled(await pending);
});
