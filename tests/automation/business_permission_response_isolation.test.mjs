import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// local_synthetic_fixture_only: execute the production response handler without
// browser credentials, real accounts, network calls, or operating writes.
const source = readFileSync('public/app-main.js', 'utf8');
const system = {window: {}, console, setTimeout, clearTimeout};
vm.runInNewContext(readFileSync('public/system-static.js', 'utf8'), system);
const slice = (start, end) => {
    const from = source.indexOf(start), to = source.indexOf(end, from);
    assert.ok(from >= 0 && to > from);
    return source.slice(from, to);
};
const authHelpers = slice('const normalizeTokenStatusFromReason =', 'const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =');
const handler = slice('const executeApiRequest = async ({ requestSession, requestUrl, requestOptions, headers }) => {',
    'const request = async (url, options = {}) => {');

function harness({status = 403, code = 403, reason = 'permission_denied', current = true} = {}) {
    const context = {
        API_BASE: '/api', token: {value: 'local-synthetic-only'},
        authContext: {value: {permissionStatus: 'allowed', hotelId: 64, tenantId: 64, platform: 'unknown'}},
        fetch: async () => ({ok: status < 400, status, json: async () => ({code, message: reason, data: {reason}})}),
        isAuthSessionCurrent: () => current,
        clearAuthSessionIfCurrent: () => {
            if (!current) return false;
            context.token.value = '';
            context.authContext.value = {permissionStatus: 'denied', hotelId: null, tenantId: null};
            return true;
        },
        showToast() {}, console: {error() {}},
    };
    vm.createContext(context);
    vm.runInContext(`${authHelpers}\n${handler}\nthis.execute = executeApiRequest;`, context);
    const readScope = () => system.window.SUXI_SYSTEM_STATIC.resolveBusinessRequestContext({
        authContext: context.authContext.value, selectedHotelId: '64',
        hotelPool: [{id: 64, tenant_id: 64}], user: {hotel_id: 64},
    });
    return {context, readScope, execute: url => context.execute({requestSession: {}, requestUrl: url, requestOptions: {}, headers: {}})};
}

test('an endpoint permission denial remains 403 while the permitted hotel can still be read', async () => {
    const h = harness();
    await assert.rejects(h.execute('/online-data/local-collector-status?hotel_id=64'), e => e.httpStatus === 403);
    assert.equal(h.context.authContext.value.permissionStatus, 'allowed');
    assert.deepEqual(JSON.parse(JSON.stringify(h.readScope())), {system_hotel_id: '64', tenant_id: '64'});
});

test('a business-code 403 is not converted to success or a global permission denial', async () => {
    const h = harness({status: 200});
    assert.equal((await h.execute('/online-data/optional-source?hotel_id=64')).code, 403);
    assert.equal(h.context.authContext.value.permissionStatus, 'allowed');
});

test('an authoritative auth-info denial still blocks the global business scope', async () => {
    for (const url of ['/auth/info', '/auth/info?refresh=1']) {
        const h = harness();
        await assert.rejects(h.execute(url), e => e.httpStatus === 403);
        assert.equal(h.context.authContext.value.permissionStatus, 'denied');
        assert.equal(h.readScope(), null);
    }
});

test('expired and disabled accounts still clear the current session', async () => {
    for (const input of [{status: 401, code: 401, reason: 'token_expired'}, {reason: 'user_disabled'}]) {
        const h = harness(input);
        await h.execute('/online-data/optional-source');
        assert.equal(h.context.token.value, '');
        assert.equal(h.readScope(), null);
    }
});

test('a stale auth-info denial cannot replace the newer session scope', async () => {
    const h = harness({current: false});
    await assert.rejects(h.execute('/auth/info'), e => e.httpStatus === 403);
    assert.equal(h.context.authContext.value.permissionStatus, 'allowed');
});

test('unknown permissions and an unpermitted hotel remain blocked', () => {
    const resolve = system.window.SUXI_SYSTEM_STATIC.resolveBusinessRequestContext;
    const base = {authContext: {permissionStatus: 'allowed', tenantId: 64}, selectedHotelId: '999', hotelPool: [{id: 64, tenant_id: 64}]};
    assert.equal(resolve(base), null);
    assert.equal(resolve({...base, selectedHotelId: '64', authContext: {permissionStatus: 'unknown'}}), null);
});

function hydrationHarness({response, permission = 'unknown', hotelId = '64', transport} = {}) {
    let calls = 0;
    const context = {
        authContext: {value: {permissionStatus: permission}},
        filterReportHotel: {value: hotelId}, permittedHotels: {value: [{id: 64, tenant_id: 64}]},
        shouldAttachBusinessContext: url => url.startsWith('/online-data/'),
        currentBusinessRequestContext: () => context.authContext.value.permissionStatus === 'allowed' ? {} : null,
        isAuthSessionCurrent: () => context.current,
        current: true,
        createRequestAbortError: () => Object.assign(new Error('cancelled'), {name: 'AbortError'}),
        applyAuthContext: value => { context.authContext.value = value; },
        request: async url => {
            calls++;
            assert.equal(url, '/auth/info?system_hotel_id=64');
            return transport ? transport() : (response || {code: 200, data: {
                context: {permissionStatus: 'allowed', hotelId: 64, tenantId: 64},
                permitted_hotels: [{id: 64, tenant_id: 64}],
            }});
        },
    };
    vm.createContext(context);
    vm.runInContext(slice('let businessReadContextRequest = null;', 'const userHasPermission ='), context);
    vm.runInContext('this.ensure = ensureBusinessReadContext;', context);
    return {context, calls: () => calls, read: (options = {}) => context.ensure('/online-data/data-health', options, {})};
}

test('a read without a primary hotel confirms the selected permitted scope with auth-info', async () => {
    const h = hydrationHarness();
    await Promise.all([h.read(), h.read()]);
    assert.equal(h.calls(), 1);
    assert.equal(h.context.authContext.value.permissionStatus, 'allowed');
    assert.equal(h.context.authContext.value.hotelId, 64);
});

test('denied, unpermitted, and write scopes never auto-confirm unknown permissions', async () => {
    for (const config of [{permission: 'denied'}, {hotelId: '999'}]) {
        const h = hydrationHarness(config);
        await h.read();
        assert.equal(h.calls(), 0);
        assert.notEqual(h.context.authContext.value.permissionStatus, 'allowed');
    }
    const h = hydrationHarness();
    await h.read({method: 'POST'});
    assert.equal(h.calls(), 0);
});

test('scope confirmation rejects wrong hotel, tenant, missing authorization, and server failure', async () => {
    for (const response of [
        {code: 403, message: 'forbidden'},
        {code: 200, data: {context: {permissionStatus: 'allowed', hotelId: 65, tenantId: 64}, permitted_hotels: [{id: 64, tenant_id: 64}]}},
        {code: 200, data: {context: {permissionStatus: 'allowed', hotelId: 64, tenantId: 65}, permitted_hotels: [{id: 64, tenant_id: 64}]}},
        {code: 200, data: {context: {permissionStatus: 'allowed', hotelId: 64, tenantId: 64}, permitted_hotels: []}},
    ]) {
        const h = hydrationHarness({response});
        await assert.rejects(h.read());
        assert.equal(h.context.authContext.value.permissionStatus, 'unknown');
    }
});

test('a delayed scope confirmation cannot overwrite another hotel, session, or authoritative denial', async () => {
    for (const change of [
        h => {h.context.filterReportHotel.value = '65';},
        h => {h.context.current = false;},
        h => {h.context.authContext.value.permissionStatus = 'denied';},
    ]) {
        let resolve;
        const pending = new Promise(done => {resolve = done;});
        const h = hydrationHarness({transport: () => pending});
        const read = h.read();
        change(h);
        resolve({code: 200, data: {context: {permissionStatus: 'allowed', hotelId: 64, tenantId: 64}, permitted_hotels: [{id: 64, tenant_id: 64}]}});
        await assert.rejects(read);
        assert.notEqual(h.context.authContext.value.permissionStatus, 'allowed');
    }
});

test('failed scope confirmation is explicit and a later successful read can recover', async () => {
    let available = false;
    const h = hydrationHarness({transport: async () => {
        if (!available) throw new Error('auth-info unavailable');
        return {code: 200, data: {context: {permissionStatus: 'allowed', hotelId: 64, tenantId: 64}, permitted_hotels: [{id: 64, tenant_id: 64}]}};
    }});
    await assert.rejects(h.read(), /unavailable/);
    assert.equal(h.context.authContext.value.permissionStatus, 'unknown');
    available = true;
    await h.read();
    assert.equal(h.calls(), 2);
    assert.equal(h.context.authContext.value.permissionStatus, 'allowed');
});
