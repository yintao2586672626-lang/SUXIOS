// Original controls/auth/reset/request/lifecycle with memory-only transport.
// Additional small tests isolate the setter contract; they do not claim a UI
// login or network lifecycle. No real browser, credentials, storage, HTTP or DB.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import os from 'node:os';
import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { parse } = require('@babel/parser');
const Vue = require('vue');
const { parse: parseTemplate, compile } = require('@vue/compiler-dom');
const { renderToString } = require('@vue/server-renderer');
const root = process.cwd();
const argument = name => process.argv.find(value => value.startsWith(name + '='))?.slice(name.length + 1);
const sourceRoot = argument('--source-root') ? path.resolve(argument('--source-root')) : root;
const out = argument('--evidence-root') ? path.resolve(argument('--evidence-root')) : fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-default-auth-'));
fs.mkdirSync(out, { recursive: true });
const hash = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const readers = new Map();
const read = file => {
  const candidate = path.resolve(sourceRoot, file), absolute = fs.existsSync(candidate) ? candidate : path.resolve(root, file);
  const value = fs.readFileSync(absolute, 'utf8');
  readers.set(file, { path: file, reader_path: absolute, sha256: hash(value) });
  return value;
};
const source = read('public/app-main.js');
const ast = parse(source, { sourceType: 'script' });
const visit = (node, fn, parent) => {
  if (!node || typeof node !== 'object') return;
  if (node.type) fn(node, parent);
  for (const [key, value] of Object.entries(node)) {
    if (['loc', 'start', 'end', 'extra', 'comments'].includes(key)) continue;
    if (Array.isArray(value)) value.forEach(child => visit(child, fn, node));
    else if (value && typeof value === 'object') visit(value, fn, node);
  }
};
let setup;
visit(ast, node => {
  if (node.type !== 'ObjectMethod' || node.key.name !== 'setup') return;
  const hasSetter = node.body.body.some(statement => statement.type === 'VariableDeclaration'
    && statement.declarations.some(declaration => declaration.id.name === 'setDefaultMainHotel'));
  if (hasSetter) { assert.ok(!setup, 'Main setup must be unique'); setup = node; }
});
assert.ok(setup);
const bindingNames = node => {
  if (node.type === 'Identifier') return [node.name];
  if (node.type === 'ObjectPattern') return node.properties.flatMap(item => bindingNames(item.value || item.argument));
  if (node.type === 'ArrayPattern') return node.elements.filter(Boolean).flatMap(bindingNames);
  if (node.type === 'AssignmentPattern') return bindingNames(node.left);
  if (node.type === 'RestElement') return bindingNames(node.argument);
  throw new Error(`Unsupported declaration pattern ${node.type}`);
};
const declarations = new Map();
for (const statement of [...ast.program.body, ...setup.body.body]) {
  if (statement.type !== 'VariableDeclaration') continue;
  for (const declaration of statement.declarations) for (const name of bindingNames(declaration.id)) {
    declarations.set(name, { declaration, kind: statement.kind });
  }
}
const declarationSource = name => {
  const declaration = declarations.get(name)?.declaration; assert.ok(declaration, name);
  return 'const ' + source.slice(declaration.start, declaration.end) + ';';
};
const clone = value => JSON.parse(JSON.stringify(value));
const fakeSession = { A: 'synthetic-round114-A-not-a-credential', B: 'synthetic-round114-B-not-a-credential' };
const hotelRows = [80, 81].map(id => ({ id, tenant_id: 7, name: `Synthetic hotel ${id}`, code: `SYN${id}`,
  address: '', contact_person: '', contact_phone: '', description: '', status: 1, ota_channel_strategy: 'dual' }));

// DTO fields match Auth::buildLoginUserPayload (373–399) and buildAuthContext
// (447–480). This is synthetic authorization, never an account-validation claim.
// The public literal projection is prepared by source parsing, never PHP/DB.
function publicLoginProjection() {
  // Parse only the literal supported default-policy branch. This does not
  // execute PHP or inspect live SystemConfig. Public super-admin shape is an
  // explicit fixture premise, never real authorization evidence.
  const policy = read('app/service/ProtectedCapabilityService.php');
  const marker = "'capabilities' => [";
  let cursor = policy.indexOf(marker) + marker.length - 1; assert.ok(cursor >= marker.length);
  const skip = () => { while (true) {
    const match = /^(?:\s+|\/\/[^\n]*|\/\*[\s\S]*?\*\/)/.exec(policy.slice(cursor));
    if (!match) return; cursor += match[0].length;
  } };
  const literal = () => {
    skip(); const ch = policy[cursor];
    if (ch === "'") { cursor++; let value = ''; while (policy[cursor] !== "'") {
      if (policy[cursor] === '\\' && ["'", '\\'].includes(policy[cursor + 1])) cursor++;
      value += policy[cursor++]; assert.ok(cursor < policy.length, 'Unclosed policy literal');
    } cursor++; return value; }
    if (ch === '[') {
      cursor++; const items = [], object = {}; let associative = false;
      while (true) {
        skip(); if (policy[cursor] === ']') { cursor++; return associative ? object : items; }
        const first = literal(); skip();
        if (policy.slice(cursor, cursor + 2) === '=>') { associative = true; cursor += 2; object[first] = literal(); }
        else items.push(first);
        skip(); if (policy[cursor] === ',') cursor++; else assert.equal(policy[cursor], ']');
      }
    }
    const token = /^(true|false|null|[0-9]+)/.exec(policy.slice(cursor)); assert.ok(token, 'Nonliteral policy'); cursor += token[0].length;
    return token[0] === 'true' ? true : token[0] === 'false' ? false : token[0] === 'null' ? null : Number(token[0]);
  };
  const capabilities = literal(); assert.ok(!Array.isArray(capabilities));
  const protectedAccess = Object.entries(capabilities).map(([key, capability]) => ({ key, module: capability.module || '',
    allowed: true, reason: 'available', paths: capability.paths.map(rule => typeof rule === 'string'
      ? { path: rule.trim(), methods: [] } : { path: rule.path.trim(), methods: [...new Set((rule.methods || [])
        .map(method => method.trim().toUpperCase()).filter(Boolean))] }) }));
  for (const file of ['app/controller/Auth.php', 'app/model/User.php', 'app/model/Role.php', 'app/service/HotelScopeService.php',
    'app/service/PermissionService.php', 'app/controller/Base.php', 'app/middleware/Auth.php', 'app/service/UserDefaultHotelService.php', 'route/app.php']) read(file);
  return { permissions: Object.fromEntries(['can_view_report', 'can_fill_daily_report', 'can_fill_monthly_task', 'can_edit_report', 'can_delete_report',
    'can_view_online_data', 'can_fetch_online_data', 'can_delete_online_data', 'can_manage_own_hotels', 'can_manage_users', 'can_use_ai_decision',
    'can_use_investment', 'can_export_data', 'can_view_field_assets', 'can_view_diagnostics', 'can_manage_ai_governance', 'can_execute_operation'].map(key => [key, true])),
    capabilities: ['all'], modules: Object.fromEntries(['ai', 'investment', 'operation', 'export', 'online_data', 'collection_health', 'field_assets', 'ai_governance'].map(key => [key, true])),
    protected_access: protectedAccess, notices: [] };
}
const loginContract = { projection: publicLoginProjection() };
const publicUser = (id, label) => ({ id, username: `synthetic-user-${label}`, realname: `Synthetic ${label}`,
  role_id: 1, role_name: '超级管理员', hotel_id: 80, default_hotel_id: 80, hotel_name: hotelRows[0].name,
  is_super_admin: true, is_hotel_manager: false, permitted_hotels: clone(label === 'A' ? hotelRows : hotelRows.filter(hotel => hotel.id === 80)),
  ...clone(loginContract.projection), hotel_scope: { type: 'all', hotel_ids: label === 'A' ? [80, 81] : [80], source_field: 'admin' } });
const publicContext = { tokenStatus: 'valid', hotelId: 80, tenantId: 7, platform: 'unknown',
  currentHotelName: hotelRows[0].name, permissionStatus: 'allowed', platformLoginScope: ['ctrip', 'meituan'] };
const fixtureA = publicUser(11, 'A'), fixtureB = publicUser(12, 'B');
const loginSuccess = { code: 200, message: '登录成功', data: { token: fakeSession.B, expires_in: 259200,
  user: fixtureB, context: clone(publicContext), notices: [] }, time: 1800000000 };
const expired = { code: 401, message: '登录已过期，请重新登录', data: { reason: 'token_expired',
  reference_id: 'synthetic-round114-expired' }, request_id: 'synthetic-round114-expired' };
const oldDenied = { code: 403, message: '只能选择当前账号有权限且营业中的门店', data: null, time: 1800000001 };

function newObservation() {
  const attempt = new Date().toISOString().replace(/[^0-9]/g, '');
  const attemptDir = path.join(out, 'attempts', attempt);
  assert.ok(!fs.existsSync(attemptDir)); fs.mkdirSync(attemptDir, { recursive: true });
  return { attempt, attemptDir, status: 'INITIALIZING', calls: [], runtimeErrors: [], diagnostics: [], declarationReads: [],
    hookReads: [], timerRecords: [], publisherEvents: [], stops: [], phase: 'Before createHarness', values: new Map(), clock: null };
}
function saveObservation(observation, stage) {
  const state = Object.fromEntries(['currentPage', 'isLoggedIn', 'loading', 'loginError', 'defaultMainHotelSavingId',
    'defaultMainHotelError', 'compassLoading', 'operatingLoopError', 'homeRevenueFactLayerLoading', 'homeRevenueFactLayerError', 'toast']
    .filter(name => observation.values.has(name)).map(name => {
      const value = Vue.unref(observation.values.get(name));
      return [name, value === undefined ? { observation: 'undefined' } : clone(value)];
    }));
  const user = Vue.unref(observation.values.get('user'));
  state.user_id = user?.id ?? null; state.hotel_id = user?.hotel_id ?? null;
  const record = { status: observation.status, phase: observation.phase, stage, failure: observation.failure || null,
    attempt: observation.attempt, state, calls: observation.calls.map(({ resolve, reject, ...call }) => call),
    pending_calls: observation.calls.filter(call => !call.settled).map(call => ({ method: call.method, url: call.url })),
    original_declarations: observation.declarationReads, original_watchers: observation.hookReads,
    runtime_errors: observation.runtimeErrors, publisher_events: observation.publisherEvents,
    diagnostics: observation.diagnostics.map(args => ({ prefix: String(args[0]),
      url: typeof args[1] === 'string' ? args[1] : undefined, error: String(args.at(-1)?.message || args.at(-1)), status: args.at(-1)?.status })),
    timers: observation.timerRecords.map(({ callback, args, ...timer }) => timer), source_readers: [...readers.values()],
    test_sha256: hash(fs.readFileSync(new URL(import.meta.url))),
    teardown_is_success_evidence: false };
  fs.writeFileSync(path.join(observation.attemptDir, `${stage}.json`), JSON.stringify(record, null, 2) + '\n');
}
function createHarness(observation) {
  const { calls, runtimeErrors, diagnostics, declarationReads, hookReads, timerRecords, publisherEvents, stops } = observation;
  let phase = 'A initial declaration materialization';
  observation.phase = phase;
  const clock = { now: Date.now(), nextId: 1, timers: new Map() };
  observation.clock = clock;
  class MemoryDate extends Date {
    constructor(...args) { super(...(args.length ? args : [clock.now])); }
    static now() { return clock.now; }
  }
  const memoryStorage = () => {
    const store = new Map();
    return { getItem: key => store.has(String(key)) ? store.get(String(key)) : null,
      setItem: (key, value) => store.set(String(key), String(value)), removeItem: key => store.delete(String(key)), clear: () => store.clear() };
  };
  const localStorage = memoryStorage(), sessionStorage = memoryStorage();
  // Only the initial A fixture is seeded. B must be established by original form/handleLogin.
  sessionStorage.setItem('token', fakeSession.A);
  localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({ saved_at: clock.now, user: fixtureA }));
  const inputElements = new Map();
  for (const key of ['login-username', 'login-password']) inputElements.set(key, { id: key, value: '', matches: () => false });
  const document = { documentElement: { dataset: { suxiRenderPhase: 'full', suxiFullRenderReady: '1', suxiAuthenticatedInteractiveReady: '1' }, lang: 'zh-CN' },
    body: { dataset: {} }, getElementById: id => inputElements.get(id) || null,
    querySelector: selector => selector === '[data-testid="app-main"]' ? { dataset: { currentPage: sandbox.currentPage.value } } : null,
    querySelectorAll: () => [], addEventListener() {}, removeEventListener() {} };
  const setTimeoutInMemory = (callback, delay = 0, ...args) => {
    const id = clock.nextId++, entry = { id, callback, args, delay: Number(delay) || 0, due_at: clock.now + (Number(delay) || 0), cleared: false, fired: false };
    clock.timers.set(id, entry); timerRecords.push(entry); return id;
  };
  const clearTimeoutInMemory = id => { const entry = clock.timers.get(id); if (entry) { entry.cleared = true; clock.timers.delete(id); } };
  const eventTarget = new EventTarget();
  const window = { document, localStorage, sessionStorage, innerWidth: 1400, location: { origin: 'https://synthetic.invalid', search: '', hash: '' },
    matchMedia: query => {
      const min = /min-width:\s*(\d+)px/.exec(query), max = /max-width:\s*(\d+)px/.exec(query);
      return { matches: min ? 1400 >= Number(min[1]) : max ? 1400 <= Number(max[1]) : false,
        addEventListener() {}, removeEventListener() {} };
    },
    addEventListener: (...args) => eventTarget.addEventListener(...args), removeEventListener: (...args) => eventTarget.removeEventListener(...args),
    dispatchEvent: event => { publisherEvents.push({ type: event.type, detail: clone(event.detail) }); return eventTarget.dispatchEvent(event); },
    setTimeout: setTimeoutInMemory, clearTimeout: clearTimeoutInMemory };
  const consoleInMemory = { error: (...args) => diagnostics.push(args), warn: (...args) => runtimeErrors.push(args.map(String).join(' ')), debug() {}, log() {} };
  const sandbox = { ...Vue, Vue, window, document, localStorage, sessionStorage, console: consoleInMemory,
    URL, URLSearchParams, Headers, FormData, Response, AbortController, DOMException, CustomEvent, EventTarget, Date: MemoryDate, Map, Set, WeakMap,
    setTimeout: setTimeoutInMemory, clearTimeout: clearTimeoutInMemory,
    setInterval: setTimeoutInMemory, clearInterval: clearTimeoutInMemory,
    requestAnimationFrame: callback => setTimeoutInMemory(callback, 16), cancelAnimationFrame: clearTimeoutInMemory,
    navigator: {}, performance: { now: () => clock.now },
    fetch: (url, options = {}) => new Promise((resolve, reject) => {
      const parsed = new URL(url, 'https://synthetic.invalid'), method = options.method || 'GET';
      const call = { url: parsed.pathname + parsed.search, method, has_abort_signal: !!options.signal,
        body: parsed.pathname === '/api/auth/default-hotel' && method === 'PUT' && options.body ? JSON.parse(options.body) : '[body omitted]',
        resolve, reject, settled: false, aborted: false };
      calls.push(call);
      try {
      assert.equal(parsed.origin, 'https://synthetic.invalid');
      const allowedGets = new Set(['/api/hotels', '/api/online-data/get-ctrip-config-list', '/api/online-data/get-meituan-config-list',
        '/api/online-data/data-sources', '/api/compass', '/api/dashboard/revenue-facts']);
      const allowed = method === 'GET' && allowedGets.has(parsed.pathname)
        || method === 'POST' && parsed.pathname === '/api/auth/login'
        || method === 'PUT' && parsed.pathname === '/api/auth/default-hotel';
      assert.ok(allowed, `Unexpected synthetic request: ${method} ${parsed.pathname}`);
      const authorization = new Headers(options.headers).get('Authorization');
      if (parsed.pathname === '/api/auth/login') assert.equal(authorization, null);
      else { assert.ok(sandbox.token.value); assert.ok(authorization === sandbox.token.value, 'Original protected Authorization matches current synthetic session'); }
      if (parsed.pathname === '/api/auth/login') {
        const loginBody = JSON.parse(options.body);
        assert.deepEqual(Object.keys(loginBody).sort(), ['password', 'username']);
        assert.equal(loginBody.username, inputElements.get('login-username').value);
        assert.equal(loginBody.password, inputElements.get('login-password').value);
      }
      // Neither authentication headers, login body, nor session markers enter the call record.
      if (method === 'GET' || parsed.pathname === '/api/auth/login') assert.ok(options.signal);
      if (method === 'PUT') { assert.equal(parsed.pathname, '/api/auth/default-hotel'); assert.equal(options.signal, undefined); }
      options.signal?.addEventListener('abort', () => {
        if (call.settled) return;
        call.settled = true; call.aborted = true; call.abort_origin = 'original request signal';
        reject(new DOMException('Original request aborted', 'AbortError'));
      }, { once: true });
      } catch (error) {
        call.settled = true; call.fixture_validation_failure = error.message;
        runtimeErrors.push(error.message); reject(error);
      }
    }),
  };
  vm.createContext(sandbox);
  for (const statement of setup.body.body.filter(node => node.type === 'FunctionDeclaration')) {
    vm.runInContext(source.slice(statement.start, statement.end), sandbox);
  }
  for (const file of ['public/system-static.js', 'public/compass-static.js', 'public/home-static.js', 'public/dual-ota-home-static.js',
    'public/ctrip-static-loader.js', 'public/ctrip-static.js', 'public/meituan-static.js', 'public/operation-static.js']) vm.runInContext(read(file), sandbox, { filename: file });

  // An explicit original-declaration registry supplies cold refs/timers from their
  // source initializers. It never invents an unknown helper or empty success.
  // Lazy cold-state initialization is a fixture boundary, not full app mounting.
  // Critical original methods and registered watcher callbacks remain unmodified.
  const values = new Map(), resolving = new Set();
  observation.values = values;
  const seed = (name, value) => values.set(name, value);
  seed('token', Vue.ref(fakeSession.A)); seed('cachedAuthUser', clone(fixtureA));
  seed('user', Vue.ref(clone(fixtureA))); seed('isLoggedIn', Vue.ref(true));
  seed('currentPage', Vue.ref('hotels')); seed('previousPageLifecycleKey', 'hotels');
  seed('initialPageOverride', ''); seed('authContext', Vue.ref(clone(publicContext)));
  seed('hotels', Vue.ref(clone(hotelRows))); seed('permittedHotels', Vue.ref(clone(hotelRows)));
  seed('hotelListSnapshotReady', Vue.ref(true)); seed('hotelListSnapshotScope', 'all-active');
  seed('hotelManagementSnapshotReady', Vue.ref(true)); seed('hotelManagementRowsReady', Vue.ref(true));
  seed('hotelManagementVisibleRowLimit', Vue.ref(2)); seed('filterReportHotel', Vue.ref('80'));
  seed('API_BASE', '/api');
  const resolveDeclaration = name => {
    if (values.has(name)) return values.get(name);
    const entry = declarations.get(name);
    assert.ok(entry, `Missing original declaration: ${name}`);
    if (resolving.has(entry.declaration)) throw new Error(`Cyclic cold initializer: ${name}`);
    resolving.add(entry.declaration);
    const declaration = entry.declaration, init = declaration.init ? source.slice(declaration.init.start, declaration.init.end) : 'undefined';
    const declaredNames = bindingNames(declaration.id);
    const declarationRecord = { name, line: declaration.loc.start.line, phase, status: 'initializing', sha256: hash(source.slice(declaration.start, declaration.end)) };
    declarationReads.push(declarationRecord);
    try {
      const result = declaration.id.type === 'Identifier'
        ? { [name]: vm.runInContext(`(${init})`, sandbox, { filename: `original-main:${name}:${declaration.loc.start.line}` }) }
        : vm.runInContext(`(()=>{const ${source.slice(declaration.start, declaration.end)};return {${declaredNames.join(',')}};})()`, sandbox);
      for (const key of declaredNames) if (!values.has(key)) values.set(key, result[key]);
      declarationRecord.status = 'initialized';
      return values.get(name);
    } catch (error) { declarationRecord.status = 'failed'; declarationRecord.error = error.message; throw error;
    } finally { resolving.delete(entry.declaration); }
  };
  for (const name of declarations.keys()) {
    if (Object.prototype.hasOwnProperty.call(sandbox, name)) continue;
    Object.defineProperty(sandbox, name, { configurable: true, enumerable: true,
      get: () => resolveDeclaration(name), set: value => values.set(name, value) });
  }
  const criticalInitialDeclarations = ['token', 'cachedAuthUser', 'isLoggedIn', 'user', 'authSessionEpoch', 'authContext',
    'currentPage', 'previousPageLifecycleKey', 'pageRequestGeneration', 'filterReportHotel', 'permittedHotels', 'hotels',
    'loginForm', 'rememberPassword', 'loading', 'loginError', 'defaultMainHotelSavingId', 'defaultMainHotelError', 'toast',
    'hotelManagementLoading', 'hotelManagementLoadError', 'hotelManagementSnapshotReady', 'hotelManagementRequestSeq',
    'hotelManagementLoadingPromise', 'compassRequestSeq', 'compassLoading', 'homeRevenueFactLayer',
    'homeRevenueFactLayerLoading', 'homeRevenueFactLayerError', 'homeRevenueFactBusinessDate'];
  for (const name of criticalInitialDeclarations) { assert.ok(declarations.has(name), name); void sandbox[name]; }
  // Bootstrap reset and final global assignment are original source, not auth stubs.
  const bootstrap = read('public/app-bootstrap.js'), bootstrapAst = parse(bootstrap, { sourceType: 'script' });
  let resetDeclaration, resetAssignment;
  visit(bootstrapAst, (node, parent) => {
    if (node.type === 'VariableDeclarator' && node.id.name === 'resetAuthenticatedInteractiveState') resetDeclaration = parent;
    if (node.type === 'ExpressionStatement' && node.expression.type === 'AssignmentExpression'
      && node.expression.left.type === 'MemberExpression' && node.expression.left.object.name === 'window'
      && node.expression.left.property.name === 'SUXI_RESET_AUTHENTICATED_INTERACTIVE_STATE') resetAssignment = node;
  });
  assert.ok(resetDeclaration && resetAssignment);
  vm.runInContext(bootstrap.slice(resetDeclaration.start, resetDeclaration.end) + '\n'
    + bootstrap.slice(resetAssignment.start, resetAssignment.end), sandbox);
  assert.equal(typeof window.SUXI_RESET_AUTHENTICATED_INTERACTIVE_STATE, 'function');
  for (const name of ['requestSuxiFullRenderForPage', 'publishSuxiAuthenticatedInteractiveReady']) {
    const assignment = ast.program.body.findLast(statement => statement.type === 'ExpressionStatement'
      && statement.expression.type === 'AssignmentExpression' && statement.expression.left.name === name);
    assert.ok(assignment, name);
    vm.runInContext(source.slice(assignment.start, assignment.end), sandbox);
  }
  const homeAssignment = setup.body.body.filter(statement => statement.type === 'ExpressionStatement'
    && statement.expression.type === 'AssignmentExpression' && statement.expression.left.name === 'homeRevenueFactLayerController');
  assert.equal(homeAssignment.length, 1);
  vm.runInContext(source.slice(homeAssignment[0].start, homeAssignment[0].end), sandbox);
  const watcherAnchors = [
    'watch(() => user.value?.id, (userId) => {',
    'watch(isLoggedIn, (loggedIn, wasLoggedIn) => {',
    'watch(currentPage, (newPage) => {',
    'watch(isLoggedIn, (loggedIn) => {',
    'watch(isLoggedIn, loggedIn => {',
    'watch(() => user.value?.id, (newUserId, previousUserId) => {',
    'watch(filterReportHotel, (newHotelId, previousHotelId) => {',
    'watch(currentPage, () => { simulationDetailRequestId += 1; }',
    'watch(currentPage, invalidateSimulationCalculation,',
    'watch([currentPage, () => aiSimulationParams.value.hotel_id, token], clearSimulationComparison,',
    'watch(filterReportHotel, (hotelId, previousHotelId) => {',
  ];
  const watchers = setup.body.body.filter(statement => statement.type === 'ExpressionStatement'
    && statement.expression.type === 'CallExpression' && statement.expression.callee.name === 'watch');
  const selected = new Set(watcherAnchors.map(anchor => {
    const matches = watchers.filter(statement => source.slice(statement.start, statement.end).startsWith(anchor));
    assert.equal(matches.length, 1, anchor); return matches[0];
  }));
  assert.equal(selected.size, 11);
  for (const statement of watchers.filter(statement => selected.has(statement))) {
    const code = source.slice(statement.start, statement.end);
    const hookRecord = { line: statement.loc.start.line, status: 'registering', sha256: hash(code) }; hookReads.push(hookRecord);
    try { stops.push(vm.runInContext(code, sandbox, { filename: 'original-watcher:' + statement.loc.start.line })); hookRecord.status = 'registered'; }
    catch (error) { hookRecord.status = 'failed'; hookRecord.error = error.message; throw error; }
  }
  const tick = async () => {
    for (let turn = 0; turn < 60; turn++) {
      await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve));
      const due = [...clock.timers.values()].filter(timer => timer.due_at <= clock.now);
      if (!due.length) return;
      for (const timer of due) { clock.timers.delete(timer.id); timer.fired = true; timer.callback(...timer.args); }
    }
    throw new Error('Unexpected unbounded immediate timer loop');
  };
  const reply = (call, envelope, status) => {
    assert.ok(call && !call.settled); call.settled = true;
    call.response = call.url === '/api/auth/login' ? { code: envelope.code, data: { user_id: envelope.data.user.id, token: '[omitted]' } } : clone(envelope);
    call.resolve(new Response(JSON.stringify(envelope), { status, headers: { 'Content-Type': 'application/json' } }));
  };
  return { sandbox, calls, values, diagnostics, runtimeErrors, declarationReads, hookReads, timerRecords, publisherEvents, stops, tick, reply, inputElements, clock,
    criticalInitialDeclarations, setPhase: value => { phase = value; observation.phase = value; } };
}

function originalView(harness) {
  const { sandbox } = harness;
  const fullTemplate = read('resources/frontend/app-template.html');
  for (const file of ['00-app-shell.html', '18-page-hotels.html', '46-global-toast.html']) {
    assert.ok(fullTemplate.replaceAll('\r\n', '\n').includes(read(`resources/frontend/templates/fragments/${file}`).replaceAll('\r\n', '\n')));
  }
  const templateAst = parseTemplate(fullTemplate), targetNodes = new Set(), paths = [];
  const walk = (node, parents = []) => {
    if (node.type === 1) {
      const event = name => node.props.some(prop => prop.type === 7 && prop.name === 'on' && prop.arg?.content === name);
      const handler = text => node.props.some(prop => prop.type === 7 && prop.exp?.content?.includes(text));
      const isLoginForm = node.tag === 'form' && event('submit') && handler('handleLogin');
      const isRefresh = node.tag === 'button' && handler('refreshHotelBindingPanelLight')
        && !node.props.some(prop => prop.name === 'if');
      const isWideDefault = node.tag === 'button' && handler('setDefaultMainHotel')
        && parents.some(parent => parent.props?.some(prop => prop.name === 'if' && prop.exp?.content === 'hotelWide'));
      const isToast = node.props.some(prop => prop.name === 'if' && prop.exp?.content === 'toast.show');
      if (isLoginForm || isRefresh || isWideDefault || isToast) {
        targetNodes.add(node); paths.push({ kind: isLoginForm ? 'login-form' : isRefresh ? 'original-refresh' : isWideDefault ? 'original-set-default' : 'global-toast',
          line: node.loc.start.line, ancestors: parents.filter(parent => parent.type === 1).map(parent => ({ tag: parent.tag,
            source_open: parent.loc.source.slice(0, parent.loc.source.indexOf('>') + 1) })) });
      }
    }
    for (const child of node.children || []) walk(child, [...parents, node]);
  };
  walk(templateAst); assert.equal(targetNodes.size, 4);
  const retain = node => {
    if (targetNodes.has(node)) return node.loc.source;
    const children = (node.children || []).map(retain).join('');
    if (!children || node.type === 0) return children;
    return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
  };
  const retained = retain(templateAst);
  const render = new Function('Vue', compile(retained, { mode: 'function' }).code)(Vue);
  const builtins = { String, Number, Boolean, Object, Array, Math, JSON, Infinity, NaN, undefined };
  const ctx = new Proxy({}, {
    has: (_target, key) => typeof key === 'string' && (key in sandbox || key in builtins),
    get: (_target, key) => { if (key === Symbol.unscopables) return undefined; return Vue.unref(key in builtins ? builtins[key] : sandbox[key]); },
    set: (_target, key, value) => { const current = sandbox[key]; if (Vue.isRef(current)) current.value = value; else sandbox[key] = value; return true; },
  });
  const inspect = async () => {
    let tree;
    const app = Vue.createSSRApp({ render() { tree = render(ctx, []); return tree; } });
    app.config.errorHandler = error => { harness.runtimeErrors.push(error.message); throw error; };
    app.config.warnHandler = message => { harness.runtimeErrors.push(message); throw new Error(message); };
    const html = await renderToString(app), rows = [];
    const walkVNode = (node, parents = []) => {
      if (Array.isArray(node)) return node.forEach(child => walkVNode(child, parents));
      if (!node || typeof node !== 'object') return;
      rows.push({ node, parents }); walkVNode(node.children, [...parents, node]);
      if (node.component?.subTree) walkVNode(node.component.subTree, [...parents, node]);
    };
    walkVNode(tree); return { html, rows };
  };
  const enabled = entry => entry && !entry.node.props?.disabled
    && !entry.parents.some(parent => parent.type === 'fieldset' && parent.props?.disabled);
  return { inspect, enabled, paths, retained };
}

async function runOriginalFlow() {
  const observation = newObservation();
  let h, ui, s;
  const pendingFlows = [];
  const captureUnexpectedRejection = error => observation.runtimeErrors.push(String(error?.message || error));
  process.on('unhandledRejection', captureUnexpectedRejection);
  try {
    h = createHarness(observation); ui = originalView(h); s = h.sandbox;
    observation.status = 'RUNNING_ORIGINAL_FLOW';
    let view = await ui.inspect();
    const setDefault = view.rows.find(row => row.node.props?.['data-testid'] === 'set-default-main-hotel');
    assert.ok(ui.enabled(setDefault)); assert.equal(s.currentPage.value, 'hotels'); assert.equal(s.isLoggedIn.value, true);
    assert.equal(s.showHotelModal.value, false); assert.equal(s.user.value.id, fixtureA.id);
    assert.equal(s.user.value.hotel_id, 80);
    h.setPhase('A original default-hotel PUT and original refresh');
    pendingFlows.push(Promise.resolve(setDefault.node.props.onClick())); await h.tick();
    const oldPut = h.calls.find(call => call.method === 'PUT');
    assert.ok(oldPut && !oldPut.settled); assert.deepEqual(oldPut.body, { hotel_id: 81 });
    assert.equal(oldPut.has_abort_signal, false);
    view = await ui.inspect();
    assert.ok(view.rows.filter(row => row.node.props?.['data-testid'] === 'set-default-main-hotel').every(row => row.node.props.disabled));
    const refresh = view.rows.find(row => row.node.type === 'button' && row.node.props?.onClick?.toString().includes('refreshHotelBindingPanelLight'));
    assert.ok(ui.enabled(refresh), 'Original refresh remains enabled during the independent default-hotel PUT');
    pendingFlows.push(Promise.resolve(refresh.node.props.onClick())); await h.tick();
    const hotelRead = h.calls.find(call => call.method === 'GET' && /^\/api\/hotels\?/.test(call.url));
    assert.ok(hotelRead && !hotelRead.settled);
    h.setPhase('Original 401 auth reset'); h.reply(hotelRead, expired, 401); await h.tick();
    assert.equal(s.isLoggedIn.value, false); assert.equal(s.user.value, null); assert.equal(s.token.value, '');
    assert.equal(oldPut.settled, false);
    assert.equal(s.document.documentElement.dataset.suxiAuthenticatedInteractiveReady, undefined);
    const resetState = { authenticated: s.isLoggedIn.value, old_put_still_pending: !oldPut.settled,
      get_calls: h.calls.filter(call => call.method === 'GET').map(call => ({ url: call.url, settled: call.settled, aborted: call.aborted })) };
    view = await ui.inspect(); fs.writeFileSync(path.join(observation.attemptDir, 'original-login-after-401.html'), view.html);
    for (const [testId, value] of [['login-username', fixtureB.username], ['login-password', 'synthetic-noncredential-input']]) {
      const input = view.rows.find(row => row.node.props?.['data-testid'] === testId);
      assert.ok(ui.enabled(input)); assert.equal(input.node.type, 'input');
      h.inputElements.get(testId).value = value;
      input.node.props['onUpdate:modelValue'](value);
      if (typeof input.node.props.onInput === 'function') input.node.props.onInput({ target: h.inputElements.get(testId) });
    }
    assert.equal(s.rememberPassword.value, false);
    view = await ui.inspect();
    const loginButton = view.rows.find(row => row.node.props?.['data-testid'] === 'login-submit');
    assert.ok(ui.enabled(loginButton));
    const loginForm = view.rows.find(row => row.node.type === 'form'); assert.ok(loginForm);
    const loginRun = Promise.resolve(loginForm.node.props.onSubmit({ preventDefault() {}, stopPropagation() {} }));
    pendingFlows.push(loginRun); await h.tick();
    const loginCall = h.calls.find(call => call.url === '/api/auth/login'); assert.ok(loginCall && !loginCall.settled);
    h.setPhase('Original B login, reset and page watcher'); h.reply(loginCall, loginSuccess, 200); await h.tick(); await loginRun;
    assert.equal(s.loginError.value, '', 'Original login handler completes without a caught dependency error');
    assert.equal(s.loading.value, false);
    assert.deepEqual(h.publisherEvents, [{ type: 'suxi:authenticated-interactive-ready', detail: { page: 'compass', renderPhase: 'full' } }]);
    assert.equal(s.isLoggedIn.value, true); assert.equal(s.user.value.id, fixtureB.id); assert.equal(s.token.value, fakeSession.B);
    assert.equal(s.currentPage.value, 'compass'); assert.equal(String(s.filterReportHotel.value), '80');
    assert.equal(s.compassLoading.value, true); assert.equal(s.homeRevenueFactLayerLoading.value, true);
    const compassRead = h.calls.find(call => /^\/api\/compass\?/.test(call.url) && !call.settled);
    const factRead = h.calls.find(call => /^\/api\/dashboard\/revenue-facts\?/.test(call.url) && !call.settled);
    assert.ok(compassRead && factRead, 'Both original immediate page reads really reached synthetic fetch');
    for (const call of [compassRead, factRead]) assert.equal(new URL(call.url, 'https://synthetic.invalid').searchParams.get('hotel_id'), '80');
    const before = { user: clone(s.user.value), auth_context: clone(s.authContext.value), hotel_id: s.user.value.hotel_id,
      permitted_hotels: clone(s.permittedHotels.value), page: s.currentPage.value, default_error: s.defaultMainHotelError.value, toast: clone(s.toast.value) };
    h.setPhase('Old A denied response'); h.reply(oldPut, oldDenied, 403); await h.tick();
    assert.deepEqual(clone(s.user.value), before.user); assert.deepEqual(clone(s.authContext.value), before.auth_context);
    assert.deepEqual(clone(s.permittedHotels.value), before.permitted_hotels); assert.equal(s.currentPage.value, 'compass');
    assert.equal(s.defaultMainHotelError.value, before.default_error, 'Old A failure cannot write B error state');
    assert.deepEqual(clone(s.toast.value), before.toast, 'Old A failure cannot overwrite B global feedback');
    assert.equal(s.defaultMainHotelSavingId.value, '', 'Old owner still releases its busy ref');
    view = await ui.inspect(); assert.ok(!view.html.includes('主门店设置失败：只能选择当前账号有权限且营业中的门店'));
    assert.ok(!view.html.includes('data-testid="hotel-account-summary-table"'));
    fs.writeFileSync(path.join(observation.attemptDir, 'original-B-compass-old-A-feedback.html'), view.html);
    const observed = { page: s.currentPage.value, user_id: s.user.value.id, hotel_id: s.user.value.hotel_id,
      default_error: s.defaultMainHotelError.value, toast: clone(s.toast.value), original_compass_reads_pending: [compassRead.url, factRead.url] };
    h.setPhase('Synthetic startup transport failures');
    for (const call of [compassRead, factRead]) { assert.ok(!call.settled); call.settled = true; call.transport_failure = 'synthetic TypeError: Failed to fetch'; call.reject(new TypeError('Failed to fetch')); }
    await h.tick(); await Promise.allSettled(pendingFlows); await h.tick();
    assert.equal(s.compassLoading.value, false); assert.equal(s.homeRevenueFactLayerLoading.value, false);
    assert.ok(s.operatingLoopError.value); assert.ok(s.homeRevenueFactLayerError.value);
    assert.equal(s.operatingLoop.value, null);
    assert.equal(s.toast.value.message, observed.toast.message, 'Original notify=false startup failures do not replace the target feedback');
    assert.equal(h.calls.filter(call => call.method === 'PUT').length, 1);
    assert.equal(h.calls.filter(call => call.url === '/api/auth/login').length, 1);
    assert.ok(h.calls.every(call => call.settled), 'Every actual fetch call is settled or originally aborted');
    assert.deepEqual(h.runtimeErrors, []);
    const diagnosticSummary = h.diagnostics.map(args => ({ prefix: String(args[0]), url: typeof args[1] === 'string' ? args[1] : undefined,
      error: String(args.at(-1)?.message || args.at(-1)), status: args.at(-1)?.status }));
    assert.equal(diagnosticSummary.length, 5);
    assert.equal(diagnosticSummary.filter(row => row.prefix === 'API请求失败:' && row.error === oldDenied.message
      && row.url === '/auth/default-hotel' && row.status === 403).length, 1);
    assert.equal(diagnosticSummary.filter(row => row.prefix === 'API请求失败:' && row.error === 'Failed to fetch'
      && /^\/(compass|dashboard\/revenue-facts)\?/.test(row.url)).length, 2);
    assert.equal(diagnosticSummary.filter(row => row.prefix === '加载首页罗盘失败:' && row.error === 'Failed to fetch').length, 1);
    assert.equal(diagnosticSummary.filter(row => row.prefix === '加载基础经营事实失败:' && row.error === 'Failed to fetch').length, 1);
    const evidence = { status: 'ORIGINAL_FLOW_GUARD_PASSED', initial_user_id: fixtureA.id, new_user_id: fixtureB.id,
      reset_state: resetState, before_old_response: { user_id: before.user.id, page: before.page, hotel_id: before.hotel_id }, observed,
      after_startup_failure: { compass_error: s.operatingLoopError.value, revenue_fact_error: s.homeRevenueFactLayerError.value },
      calls: h.calls.map(({ resolve, reject, ...call }) => call), diagnostics: diagnosticSummary,
      original_declarations: h.declarationReads, original_watchers: h.hookReads, source_readers: [...readers.values()],
      critical_A_initial_declarations: h.criticalInitialDeclarations,
      original_ui_paths: ui.paths, original_login_completion: { login_error: s.loginError.value, loading: s.loading.value, publisher_events: h.publisherEvents },
      timers: h.timerRecords.map(({ callback, args, ...timer }) => timer),
      clock_boundary: 'Logical observation clock fixed; only due-now original timers dispatched. Future registered timers not advanced. No wall-clock startup-completion claim.',
      limitations: ['Original VNode/v-model/SSR and memory DOM/storage only; no native browser.', 'Cold declarations initialized from original source on first use; not complete app mounting.',
        'Synthetic public login success is not real account validation.', 'Old PUT failure is assumed already formed after hotel81 stopped being enabled; B login includes only still-enabled hotel80. No actual server write/deactivation/revocation executed.',
        'Only frontend feedback ownership is tested; no cross-account data write or all-startup validation claimed.'], runtime_errors: h.runtimeErrors };
    observation.status = evidence.status;
    fs.writeFileSync(path.join(observation.attemptDir, 'original-flow-evidence.json'), JSON.stringify(evidence, null, 2) + '\n');
    saveObservation(observation, 'before-teardown');
    return { status: evidence.status, calls: h.calls.length, get: h.calls.filter(call => call.method === 'GET').length,
      put: 1, post: 1, original_aborts: h.calls.filter(call => call.aborted).length, declarations: h.declarationReads.length,
      attempt_dir: observation.attemptDir };
  } catch (error) {
    observation.status = 'FAILED_NOT_CONFIRMED';
    observation.failure = { name: error.name, message: error.message, stack: String(error.stack || '') };
    saveObservation(observation, 'before-teardown');
    throw error;
  } finally {
    for (const stop of observation.stops) stop?.();
    // Only local synthetic promises are released, after immutable pre-teardown
    // observations. This is never original cancellation or successful closure.
    for (const call of observation.calls.filter(call => !call.settled)) {
      call.pending_before_teardown = true; call.teardown_only = true; call.settled = true;
      call.reject(new DOMException('Synthetic teardown only; not original closure evidence', 'AbortError'));
    }
    if (observation.clock) {
      for (const timer of observation.clock.timers.values()) { timer.cleared = true; timer.teardown_only = true; }
      observation.clock.timers.clear();
    }
    await Promise.race([Promise.allSettled(pendingFlows), new Promise(resolve => setTimeout(resolve, 100))]);
    await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve));
    saveObservation(observation, 'after-teardown');
    process.removeListener('unhandledRejection', captureUnexpectedRejection);
  }
}

// The other cases below isolate the caller contract with original capture,
// beginAuthSession and isCurrent. reset/cache/preferences are bounded memory
// observers; apiRequest promises are transport-return contracts, not another
// original UI login or coordinator cancellation proof.
function callerHarness() {
  const calls = [], toasts = [], caches = [], preferences = [], contexts = [], resets = [], runs = [];
  const s = { ...Vue, token: Vue.ref(fakeSession.A), user: Vue.ref(clone(fixtureA)), permittedHotels: Vue.ref(clone(hotelRows)),
    authSessionEpoch: 1, defaultMainHotelSavingId: Vue.ref(''), defaultMainHotelError: Vue.ref(''),
    filterReportHotel: Vue.ref('80'), hotelListSnapshotReady: Vue.ref(true), hotelListLoadFailed: Vue.ref(false), hotelListSnapshotScope: 'all-active',
    isDefaultMainHotel: hotel => Number(hotel.id) === Number(s.user.value?.hotel_id),
    resetHotelScopedClientState: options => resets.push(clone(options)),
    showToast: (message, type) => toasts.push({ message, type }), applyAuthContext: value => contexts.push(clone(value)),
    saveCachedAuthUser: value => caches.push(clone(value)), persistDualOtaWorkbenchPreferences: value => preferences.push(clone(value)),
    dedupeHotels: rows => [...new Map(rows.map(row => [String(row.id), row])).values()],
    apiRequest: (url, options = {}) => new Promise((resolve, reject) => calls.push({ url, options: clone(options), resolve, reject, settled: false })),
  };
  vm.createContext(s);
  vm.runInContext(['captureAuthSession', 'isAuthSessionCurrent', 'beginAuthSession', 'setDefaultMainHotel'].map(declarationSource).join('\n')
    + '\nglobalThis.setter = setDefaultMainHotel; globalThis.begin = beginAuthSession;', s);
  const reply = (call, value, error = false) => { assert.ok(call && !call.settled); call.settled = true; error ? call.reject(value) : call.resolve(value); };
  const tick = async () => { await Promise.resolve(); await Promise.resolve(); };
  const changeSession = () => { s.begin(fakeSession.B); s.user.value = clone(fixtureB); s.permittedHotels.value = clone(fixtureB.permitted_hotels); };
  const snapshot = () => ({ user: clone(s.user.value), permitted: clone(s.permittedHotels.value), hotel: s.filterReportHotel.value,
    error: s.defaultMainHotelError.value, toasts: clone(toasts), caches: clone(caches), preferences: clone(preferences), contexts: clone(contexts) });
  const start = () => { const run = s.setter(hotelRows[1]); runs.push(run); return run; };
  return { s, calls, toasts, caches, preferences, contexts, resets, runs, start, reply, tick, changeSession, snapshot };
}
const acceptedWrite = { code: 200, message: '默认主门店已保存', data: { default_hotel_id: 81, previous_default_hotel_id: 80,
  changed: true, hotel: { id: 81, name: hotelRows[1].name, status: 1, tenant_id: 7 } }, time: 1800000002 };
// Auth::info full public fields, both active hotels still available for the
// current-session controls. No account or database is queried.
const exactInfo = (hotelId = 81) => {
  const { hotel_name, ...base } = clone(fixtureA);
  return { code: 200, message: '操作成功', time: 1800000003, data: { ...base, email: '', phone: '', hotel_id: hotelId,
    default_hotel_id: hotelId, hotel: hotelRows.find(hotel => hotel.id === hotelId),
    context: { ...publicContext, hotelId, currentHotelName: hotelRows.find(hotel => hotel.id === hotelId).name } } };
};
async function callerCase(name, body) {
  const h = callerHarness(); let status = 'RUNNING', failure = null;
  const record = stage => fs.writeFileSync(path.join(out, name + '-' + stage + '.json'), JSON.stringify({ status, failure,
    evidence_layer: 'ORIGINAL_SETTER_AND_AUTH_FUNCTION_CONTRACT', calls: h.calls.map(({ resolve, reject, ...call }) => call),
    pending_calls: h.calls.filter(call => !call.settled).map(call => call.url), final: h.snapshot(), resets: h.resets,
    source_readers: [...readers.values()], teardown_is_success_evidence: false }, null, 2) + '\n');
  try { await body(h); assert.ok(h.calls.every(call => call.settled)); status = 'PASSED'; }
  catch (error) { status = 'FAILED'; failure = error.message; throw error; }
  finally {
    record('before-teardown');
    for (const call of h.calls.filter(call => !call.settled)) {
      call.teardown_only = true; h.reply(call, new DOMException('Synthetic teardown only', 'AbortError'), true);
    }
    await Promise.allSettled(h.runs); record('after-teardown');
  }
}
if (process.argv.includes('--prepare-only')) {
  const ui = originalView({ sandbox: {} });
  console.log(JSON.stringify({ status: 'SOURCE_COMPILE_ONLY', retained_template_sha256: hash(ui.retained), source_readers: [...readers.values()], behavior_executions: 0 }));
} else {
  test('original 401 refresh and same-tree login keep late A default-hotel failure out of B feedback', async () => {
    console.log(JSON.stringify(await runOriginalFlow()));
  });
  test('caller contract: old successful PUT does not start auth-info in a successor session', () => callerCase('late-put-success', async h => {
    const run = h.start();
    assert.equal(h.calls.length, 1); assert.equal(h.calls[0].url, '/auth/default-hotel');
    assert.deepEqual(JSON.parse(h.calls[0].options.body), { hotel_id: 81 });
    h.changeSession(); const before = h.snapshot(); h.reply(h.calls[0], clone(acceptedWrite));
    await h.tick(); assert.equal(h.calls.length, 1, 'A stale successful PUT must not initiate a new-session read');
    assert.equal(await run, false); assert.deepEqual(h.snapshot(), before);
    assert.equal(h.s.defaultMainHotelSavingId.value, '');
  }));
  test('caller contract: an auth epoch change after a read return cannot commit old public user data', () => callerCase('late-read-return', async h => {
    const run = h.start();
    h.reply(h.calls[0], clone(acceptedWrite)); await h.tick();
    assert.equal(h.calls[1].url, '/auth/info');
    assert.deepEqual(h.calls[1].options.requestPolicy, { scope: 'session', priority: 'action', force: true });
    // A settled transport return and a synchronous auth boundary can precede
    // the awaiting caller's microtask. This does not reply to an aborted GET.
    h.reply(h.calls[1], exactInfo()); h.s.begin(fakeSession.A); const before = h.snapshot();
    assert.equal(await run, false); assert.deepEqual(h.snapshot(), before); assert.equal(h.s.defaultMainHotelSavingId.value, '');
  }));
  test('caller contract: read cancellation after session change keeps successor feedback and releases busy', () => callerCase('cancelled-read', async h => {
    const run = h.start();
    h.reply(h.calls[0], clone(acceptedWrite)); await h.tick(); assert.equal(h.calls.length, 2);
    h.changeSession(); const before = h.snapshot();
    h.reply(h.calls[1], new DOMException('Original session read cancelled', 'AbortError'), true);
    assert.equal(await run, false); assert.deepEqual(h.snapshot(), before); assert.equal(h.s.defaultMainHotelSavingId.value, '');
  }));
  test('caller contract: current failure can retry; mismatched read rejects; exact read alone updates main hotel', () => callerCase('current-retry-and-exact-read', async h => {
    let run = h.start();
    const failure = new Error(oldDenied.message); failure.status = 403; h.reply(h.calls[0], failure, true);
    assert.equal(await run, false); assert.equal(h.s.user.value.hotel_id, 80); assert.equal(h.s.defaultMainHotelSavingId.value, '');
    assert.equal(h.toasts.at(-1).type, 'error'); assert.equal(h.caches.length, 0);
    run = h.start(); h.reply(h.calls[1], clone(acceptedWrite)); await h.tick();
    assert.equal(h.s.user.value.hotel_id, 80); assert.equal(h.caches.length, 0);
    h.reply(h.calls[2], exactInfo(80)); assert.equal(await run, false);
    assert.match(h.s.defaultMainHotelError.value, /主门店已提交，但尚未完成回读确认：主门店回读不一致/);
    assert.equal(h.toasts.at(-1).type, 'warning'); assert.equal(h.caches.length, 0);
    run = h.start(); h.reply(h.calls[3], clone(acceptedWrite)); await h.tick();
    assert.equal(h.s.user.value.hotel_id, 80); assert.equal(h.s.defaultMainHotelSavingId.value, '81');
    assert.equal(h.caches.length, 0); const expected = exactInfo(); h.reply(h.calls[4], expected);
    assert.equal(await run, true); assert.deepEqual(clone(h.s.user.value), expected.data);
    assert.equal(h.s.filterReportHotel.value, '81'); assert.equal(h.s.defaultMainHotelSavingId.value, '');
    assert.equal(h.s.defaultMainHotelError.value, ''); assert.deepEqual(h.caches, [expected.data]);
    assert.deepEqual(h.preferences, [{ hotel_id: '81' }]); assert.deepEqual(h.contexts, [expected.data.context]);
    assert.equal(h.toasts.filter(item => item.type === 'success').length, 1);
  }));
}
