import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
function between(start, end, from = 0) {
    const offset = source.indexOf(start, from), finish = source.indexOf(end, offset);
    assert.ok(offset >= 0 && finish > offset, 'actual login destination path must be extractable');
    return source.slice(offset, finish);
}
function login(requestedInitialPage) {
    const activePages = between('const ACTIVE_DISCOVERABLE_PAGE_PATHS =', '\n            const collectMenuPagePaths');
    const resolver = between('const resolveInitialPageOverride =', '\n            const requestedUrlPage');
    const activation = between('const activateCoreOperationsAfterLogin =', '\n            const isVisibleOnlineDataTab');
    const loginStart = source.indexOf('const handleLogin = async () => {');
    const acceptedUser = between('user.value = res.data.user;', '\n                        applyAuthContext', loginStart);
    return Function('requestedInitialPage', `
        const user = { value: null }, currentPage = { value: 'compass' };
        ${activePages}
        const normalizeCanonicalPage = page => String(page || '').trim();
        const discoverablePagePathsForUser = actor => new Set(['compass', ...(actor?.pages || [])]);
        ${resolver}
        let initialPageOverride = resolveInitialPageOverride(requestedInitialPage, user.value);
        const nextTick = () => Promise.resolve(), isCompassDataPage = page => page === 'compass';
        const homeSecondaryPanelsReady = { value: true };
        const scheduleHomeSecondaryPanelsReady = () => {}, scheduleDualOtaWorkbenchAutoFetch = () => {}, scheduleDualOtaSystemMetricDrilldownHydration = () => {};
        const currentCompassReadPolicy = () => ({}), DASHBOARD_PAGE_CACHE_TTL_MS = 1;
        let dashboardLoads = 0;
        const loadCompassData = () => { dashboardLoads++; };
        const runPageLoadOnce = (page, kind, run) => Promise.resolve(run());
        ${activation}
        return { async authenticate(actor) { const res = { data: { user: actor } }; ${acceptedUser} await activateCoreOperationsAfterLogin(); return { page: currentPage.value, dashboardLoads }; } };
    `)(requestedInitialPage);
}

test('login preserves a permitted operating-finance destination that was unavailable before authentication', async () => {
    assert.deepEqual(await login('operating-finance').authenticate({ pages: ['operating-finance'] }), { page: 'operating-finance', dashboardLoads: 0 });
});
test('login keeps unknown or unpermitted destinations on the existing default page', async () => {
    for (const page of ['operating-finance', 'unknown-page', '']) {
        assert.deepEqual(await login(page).authenticate({ pages: [] }), { page: 'compass', dashboardLoads: 1 });
    }
});
test('login still resolves agent permissions from the authenticated user', async () => {
    assert.equal((await login('agent-center').authenticate({ capabilities: ['ai.view'] })).page, 'agent-center');
    assert.equal((await login('agent-center').authenticate({ pages: [] })).page, 'compass');
});
