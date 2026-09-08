import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
function harness() {
    const functionStart = source.indexOf('const loadSimulationDetail = async (id) => {');
    const guardStart = source.indexOf('let simulationDetailRequestId = 0;');
    const start = guardStart >= 0 ? guardStart : functionStart;
    const end = source.indexOf('\n            const reuseSimulationRecord', start);
    assert.ok(start >= 0 && end > start);
    const pending = [], applied = [], toasts = [], watchers = [];
    const state = {
        currentPage: { value: 'ai-simulation' }, session: 1,
        ensureSimulationStaticReady: async () => {},
        request: url => new Promise((resolve, reject) => pending.push({ url, resolve, reject })),
        applySimulationRecord: record => applied.push(record.id),
        showToast: (...args) => toasts.push(args),
        watch: (ref, callback) => watchers.push(callback),
    };
    state.captureAuthSession = () => ({ epoch: state.session });
    state.isAuthSessionCurrent = session => session.epoch === state.session;
    state.isStillOnRequestPage = page => state.currentPage.value === page;
    vm.createContext(state);
    vm.runInContext(source.slice(start, end) + '\nthis.load = loadSimulationDetail;', state);
    return { state, pending, applied, toasts, navigate(page) {
        state.currentPage.value = page; watchers.forEach(fn => fn(page));
    } };
}
const flush = () => new Promise(setImmediate);
const response = id => ({ code: 200, data: { id } });

test('the last selected historical record wins even when its response arrives first', async () => {
    const h = harness();
    const a = h.state.load(101); await flush();
    const b = h.state.load(202); await flush();
    h.pending[1].resolve(response(202)); await b;
    h.pending[0].resolve(response(101)); await a;
    assert.deepEqual(h.applied, [202]);
});

test('an old failure cannot overwrite the outcome of the latest successful selection', async () => {
    const h = harness();
    const a = h.state.load(101); await flush();
    const b = h.state.load(202); await flush();
    h.pending[1].resolve(response(202)); await b;
    h.pending[0].reject(new Error('old request failed')); await a;
    assert.equal(h.toasts.some(item => item[0] === 'old request failed'), false);
});

test('leaving and returning to the page invalidates its previous detail request', async () => {
    const h = harness();
    const a = h.state.load(101); await flush();
    h.navigate('compass'); h.navigate('ai-simulation');
    h.pending[0].resolve(response(101)); await a;
    assert.deepEqual(h.applied, []);
});

test('account changes prevent a previous account response from filling the form', async () => {
    const h = harness();
    const a = h.state.load(101); await flush();
    h.state.session++;
    h.pending[0].resolve(response(101)); await a;
    assert.deepEqual(h.applied, []);
});

test('the latest failure remains visible and a subsequent retry can succeed', async () => {
    const h = harness();
    const a = h.state.load(101); await flush();
    h.pending[0].reject(new Error('latest failed')); await a;
    assert.equal(h.toasts[0][0], 'latest failed');
    const b = h.state.load(101); await flush();
    h.pending[1].resolve(response(101)); await b;
    assert.deepEqual(h.applied, [101]);
});

test('unreachable break-even is rendered explicitly while old missing data stays missing', () => {
    const template = fs.readFileSync(new URL('../../resources/frontend/templates/fragments/02-page-ai-simulation.html', import.meta.url), 'utf8');
    assert.ok(/row\.breakEvenOccupancyStatus === 'unreachable'\s*\? '当前条件无法保本'\s*:\s*formatPercent\(row\.breakEvenOccupancy\)/.test(template));
});
