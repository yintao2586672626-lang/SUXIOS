import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import path from 'node:path';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
const rootOption = process.argv.find(value => value.startsWith('--source-root='));
const sourceRoot = path.resolve(rootOption ? rootOption.slice('--source-root='.length) : '.');
const read = file => fs.readFileSync(fs.existsSync(path.join(sourceRoot, file)) ? path.join(sourceRoot, file) : path.resolve(file), 'utf8');
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const sourceHashes = Object.fromEntries(['public/app-main.js', 'public/hotel-three-source-onboarding-static.js'].map(file => [file, sha(read(file))]));
const main = read('public/app-main.js').replaceAll('\r\n', '\n');
const cut = (source, a, b) => { const start = source.indexOf(a), end = source.indexOf(b, start + a.length); assert.ok(start >= 0 && end > start, a); return source.slice(start, end); };
const decl = name => { const start = main.indexOf(`            const ${name} =`); assert.ok(start >= 0, name); const next = /\n            (?:const|let) /.exec(main.slice(start + 1)); assert.ok(next, name); return main.slice(start, start + 1 + next.index); };
const requestSource = [cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  cut(main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  cut(main, '            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  cut(main, '            const request = async (', '            const apiRequest = request;')].join('\n');
const targetSource = [decl('captureAuthSession'), decl('isAuthSessionCurrent'), decl('userHasPermission'), decl('canManageOwnHotels'),
  main.match(/^\s*let hotelPmsBindingModalRequestSequence = 0;$/m)?.[0] || assert.fail('real PMS modal request sequence declaration must exist'),
  decl('hotelWide'), decl('hotelRowsVisible'), cut(main, '            const hotelRowsForDisplay =', '            watch(() => [\n                hotelManagementSnapshotReady.value,'),
  cut(main, '            const getEmptyHotelBackgroundProfile =', '            const getHotelDescriptionProfileRows ='),
  decl('openHotelModal'), decl('applyHotelPmsBinding'), decl('loadHotelPmsBindingForModal'),
  ...['hotelAutomationLifecycle', 'hotelAutomationLifecycleStatusText', 'hotelAutomationLifecycleStatusClass', 'hotelAutomationLifecycleProgress',
    'hotelAutomationLifecycleProgressText', 'hotelAutomationLifecycleCanRoute', 'openHotelAutomationLifecycleAction', 'hotelAutomationLifecycleSummary'].map(decl)].join('\n');
const panelSource = cut(read('public/components/system/app-main-components.js'), '    const HotelThreeSourceOnboardingPanel = {', '    const OperatingLoopAuthority = {');
const pageAst = parse(read('resources/frontend/templates/fragments/18-page-hotels.html'));
const allAst = []; const walkAst = (node, parents = []) => { allAst.push({ node, parents }); for (const child of node.children || []) walkAst(child, [...parents, node]); }; walkAst(pageAst);
const originalEntry = allAst.find(({ node }) => node.type === 1 && node.tag === 'component' && node.props.some(p => p.name === 'bind' && p.arg?.content === 'is' && p.exp?.content === 'hotelAutomationLifecycleSummary'));
assert.ok(originalEntry);
const retain = node => { if (node === originalEntry.node) return node.loc.source; const children = (node.children || []).map(retain).join('');
  if (!children || node.type === 0) return children; return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + node.loc.source.slice(node.loc.source.lastIndexOf('</')); };
const originalList = retain(pageAst), originalDialog = read('resources/frontend/templates/fragments/40-dialog-hotel.html');
const render = new Function('Vue', compile(`<section>${originalList}${originalDialog}</section>`, { mode: 'function', prefixIdentifiers: true }).code)(Vue);

const factorySource = cut(main, '            let hotelOnboardingController = null;', '            const hotelSaving = ref(false);');
const clone = value => JSON.parse(JSON.stringify(value));

const pms = id => ({ hotel_id: id, binding_status: 'configured', selected_provider: 'dingdandao_pms', sources: { dingdandao_pms: { provider_hotel_id: `synthetic-public-${id}-pms`, provider_hotel_name: `Synthetic public hotel ${id}` } } });
const status = (id, ready) => {
  // Public metadata shape from CloudBrowserProfileService::publicProfile and
  // HotelThreeSourceOnboardingService::profileStatus/sourceMap/blockers.
  // These are fictional states, not authorization or collection operations.
  const profiles = Object.fromEntries(['ctrip', 'meituan', 'dingdandao', 'meituan_cloud_pms'].map((platform, index) => {
    const available = ready && platform !== 'meituan_cloud_pms';
    const profile = available ? { profile_id: `cbp_${Buffer.alloc(24, id + index).toString('base64url')}`, hotel_id: id, platform,
      authorization_status: 'ready_to_collect', status_reason: 'gateway_collection_ready',
      login_verified_at: '2026-09-20 08:00:00', ready_at: '2026-09-20 08:00:01', session_expires_at: null,
      last_state_change_at: '2026-09-20 08:00:01', browser_started: false } : null;
    return [platform, { platform, profile_status: available ? 'ready_to_collect' : 'missing', ready: available, profile }];
  }));
  const pmsSummary = { binding_status: 'configured', selected_provider: 'dingdandao_pms',
    provider_hotel_id: `synthetic-public-${id}-pms`, provider_hotel_name: `Synthetic public hotel ${id}` };
  const sources = Object.fromEntries(['ctrip', 'meituan', 'dingdandao'].map((platform, index) => {
    const identity = `synthetic-public-${id}-${platform}`, isPms = platform === 'dingdandao';
    const binding = isPms ? { binding_status: 'readback_verified', readback_verified: true, provider: 'dingdandao_pms',
      provider_hotel_id: pmsSummary.provider_hotel_id, provider_hotel_name: pmsSummary.provider_hotel_name,
      platform_hotel_id: pmsSummary.provider_hotel_id, platform_hotel_name: pmsSummary.provider_hotel_name }
      : ready ? { platform, readback_verified: true, binding_status: 'readback_verified', binding_type: 'cloud_profile',
        platform_hotel_id: identity, platform_hotel_name: `Synthetic public hotel ${id}`, data_source_id: id * 10 + index + 1,
        profile_id: profiles[platform].profile.profile_id, profile_exact_match: true, secret_stored: false }
        : { platform, binding_status: 'missing', readback_verified: false, data_source_id: null, failure_code: 'browser_profile_data_source_missing' };
    return [platform, { platform, status: ready ? 'ready' : isPms ? 'missing' : 'missing_binding',
      profile_ready: ready, authorization_status: profiles[platform].profile_status, profile: profiles[platform].profile, binding,
      platform_hotel_id: binding.platform_hotel_id ?? null, platform_hotel_name: binding.platform_hotel_name ?? null,
      detail: ready ? 'binding_and_profile_ready' : isPms ? 'profile_login_required' : 'browser_profile_data_source_missing' }];
  }));
  const blockers = ready ? [] : [{ code: 'dingdandao_profile_not_ready', action: 'request_dingdandao_login' },
    { code: 'ctrip_binding_not_ready', action: 'bind_ctrip' }, { code: 'meituan_binding_not_ready', action: 'bind_meituan' }];
  blockers.push({ code: 'collection_plan_not_active', action: 'activate_collection_plan' });
  return { contract_version: 'hotel_three_source_onboarding.v1', tenant_id: 7, hotel_id: id, hotel_name: `Synthetic hotel ${id === 80 ? 'A' : 'B'}`,
    status: ready ? 'needs_collection_plan' : 'blocked', overall_status: ready ? 'needs_collection_plan' : 'blocked',
    onboarding_status: ready ? 'needs_collection_plan' : 'blocked', ready: false,
    source_status: ready ? 'ready' : 'blocked', source_ready: ready, collection_plan_ready: false, ota_channel_strategy: 'dual',
    required_platforms: Object.keys(sources), sources, platforms: sources, source_statuses: sources,
    pms: pmsSummary, profiles, platform_bindings: Object.fromEntries(['ctrip', 'meituan'].map(platform => [platform, sources[platform].binding])),
    delivery: { wechat: { hotel_id: id, binding_status: 'unknown', binding: null, failure_code: 'wechat_binding_unavailable' },
      hourly_plan: { plan_status: 'unknown', id: null, enabled: false, schedule_status: null, failure_code: 'manual_notification_plan_unavailable' } },
    collection_plan: { status: 'unknown', readback_verified: false, execution_authorized: false, failure_code: 'hotel_collection_plan_unavailable' },
    blockers, next_action: blockers[0].action,
    external_action_performed: false };
};

function createHarness(t) {
const hotels = [80, 81].map((id, index) => ({ id, tenant_id: 7, name: `Synthetic hotel ${index ? 'B' : 'A'}`, code: `SYN${id}`,
  address: '', contact_person: 'Synthetic operator', contact_phone: '', description: '', status: 1, ota_channel_strategy: 'dual' }));
const initial = { currentPage: 'hotels', showHotelModal: false, hotelSaving: false, hotelForm: {}, hotelBackgroundProfileForm: {},
  hotelPmsBinding: null, hotelPmsBindingLoading: false, hotelPmsBindingError: '', hotelOtaConfig: {}, hotelOtaConfigLoading: false,
  ctripConfigList: [], meituanConfigList: [], platformDataSources: [], showHotelOtaConfig: false,
  hotels, permittedHotels: hotels, filteredHotels: hotels, hotelManagementSnapshotReady: true, hotelManagementRowsReady: true,
  hotelManagementVisibleRowLimit: 2, hotelManagementLoading: false, hotelManagementLoadError: '',
  hotelOnboardingActive: false, hotelOnboardingStep: 'hotel', hotelOnboardingHotelId: '', hotelOnboardingSnapshot: null,
  hotelOnboardingLoading: false, hotelOnboardingError: '', hotelOnboardingBusyPlatform: '', hotelOnboardingLoginSessions: {}, hotelOnboardingBindingForms: {},
  hotelOnboardingCollectionPlanStatus: 'idle', hotelOnboardingCollectionPlanError: '',
  hotelAutomationLifecycleById: Object.fromEntries(hotels.map(hotel => [String(hotel.id), { hotel_id: String(hotel.id), status: 'awaiting_binding', next_action_code: 'open_hotel_binding', next_action_label: '核对身份', total_stage_count: 6, completed_stage_count: 1 }])),
  wechatNotificationHotelId: '', manualNotificationForm: {},
};
const refs = Object.fromEntries(Object.entries(initial).map(([key, value]) => [key, Vue.ref(value)]));
const requests = [], notices = [], errors = [], expectedHttpFailures = [], expectedFailureMessages = new Set(), sideReads = [], flows = [];
const sandbox = { ...refs, window: { matchMedia: query => { assert.equal(query, '(min-width: 1280px)'); return { matches: true }; } }, runtimeWindow: { location: { origin: 'https://synthetic.invalid' }, open() { throw new Error('No login/window allowed'); } },
  h: Vue.h, markRaw: Vue.markRaw, ref: Vue.ref, computed: Vue.computed, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, Date, setTimeout, clearTimeout,
  API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 1,
  user: Vue.ref({ id: 11, tenant_id: 7, is_super_admin: true, permissions: { can_manage_own_hotels: true } }),
  token: Vue.ref('round107-synthetic-session-not-a-credential'), authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', tokenStatus: 'valid', platform: 'all' }),
  filterReportHotel: Vue.ref('80'), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
  console: { error: (...args) => {
    const error = args.at(-1);
    if (args[0] === 'API请求失败:' && error?.status === 500 && expectedFailureMessages.has(error.message)) {
      expectedHttpFailures.push({ status: 500, message: error.message }); return;
    }
    errors.push(args.map(String).join(' '));
  }, warn: (...args) => errors.push(args.map(String).join(' ')) },
  showToast: (message, type) => notices.push({ message, type }), getCurrentOperatorName: () => 'Synthetic operator',
  isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
  // Adjacent OTA configuration readers are a closed no-op boundary. They do
  // not set hotel ID, PMS, onboarding snapshot, source rows or ready state.
  buildHotelOtaConfig: () => ({}), ensureHotelOtaConfigLists: async () => { sideReads.push('Unrelated OTA configuration cache/read boundary not executed'); },
  fetch: (url, options) => new Promise((resolve, reject) => {
    const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(options.method || 'GET', 'GET');
    assert.match(parsed.pathname, /^\/api\/hotels\/(80|81)\/(pms-binding|three-source-onboarding)$/);
    assert.equal(new Headers(options.headers).get('Authorization'), sandbox.token.value); assert.ok(options.signal);
    const call = { url, method: 'GET', resolve, reject, settled: false, aborted: false, has_abort_signal: true };
    requests.push(call); options.signal.addEventListener('abort', () => { if (!call.settled) { call.settled = true; call.aborted = true; call.abort_origin = 'original coordinator signal'; reject(new DOMException('Original request aborted', 'AbortError')); } }, { once: true });
  }),
};
vm.createContext(sandbox); vm.runInContext(read('public/system-static.js') + '\n' + read('public/hotel-three-source-onboarding-static.js'), sandbox);
sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name];
for (const name of ['createHotelForm', 'normalizeHotelIdentityName']) sandbox[name] = sandbox.appSystemStatic[name];
sandbox.hotelFormChannelSelected = platform => sandbox.appSystemStatic.selectedHotelOtaPlatforms(refs.hotelForm.value.ota_channel_strategy).includes(platform);
vm.runInContext(requestSource + '\nconst apiRequest = request;\n' + targetSource + '\n' + panelSource + '\n' + factorySource
  + '\nglobalThis.original={request,openHotelModal,loadHotelPmsBindingForModal,hotelAutomationLifecycleSummary,hotelWide,hotelRowsVisible,hotelRowsForDisplay,requireHotelOnboardingController,resetHotelOnboarding,hotelOnboardingSourceRows,hotelOnboardingReady,hotelOnboardingStatusText,hotelOnboardingStatusClass,hotelOnboardingCollectionPlanEligible,setHotelOnboardingBindingField,loadHotelThreeSourceOnboarding,openHotelOnboardingCloudLogin,completeHotelOnboardingCloudLogin,saveHotelOnboardingBinding,goToHotelOnboardingVerification,finishHotelOnboarding,enableHotelOnboardingHourlyCollection,openHotelOnboardingWechatConfig,hotelThreeSourceOnboardingPanel};', sandbox);
const ctx = { ...refs, ...sandbox.original, user: sandbox.user, hotelFormChannelSelected: sandbox.hotelFormChannelSelected };
let tree;
const inspect = async () => {
  const app = Vue.createSSRApp({ setup: () => ctx, render() { tree = render(this, []); return tree; } });
  app.config.errorHandler = error => { errors.push(error.message); throw error; };
  app.config.warnHandler = message => { throw new Error(`Unexpected Vue warning: ${message}`); };
  const html = await renderToString(app), rows = [];
  const walk = (node, parents = []) => { if (Array.isArray(node)) return node.forEach(child => walk(child, parents)); if (!node || typeof node !== 'object') return;
    rows.push({ node, parents }); walk(node.children, [...parents, node]); if (node.component?.subTree) walk(node.component.subTree, [...parents, node]); };
  walk(tree); return { html, rows };
};
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const allowed = entry => !entry.node.props?.disabled && !entry.parents.some(node => node.type === 'fieldset' && node.props?.disabled)
  && !(refs.showHotelModal.value && entry.node.props?.['data-testid'] === 'hotel-autopilot-next-action');
const open = async id => {
  const view = await inspect(), entry = view.rows.find(row => row.node.props?.['data-testid'] === 'hotel-autopilot-next-action'
    && row.parents.some(node => Number(node.props?.hotel?.id) === id));
  assert.ok(entry && allowed(entry), 'Original visible hotel lifecycle action must be enabled outside modal');
  const pending = entry.node.props.onClick(); flows.push(pending); await tick(); return { pending };
};
const close = async () => {
  const view = await inspect(), entry = view.rows.find(row => row.node.type === 'button' && row.node.props?.onClick?.toString().includes('showHotelModal = false'));
  assert.ok(entry && allowed(entry)); assert.equal(refs.hotelSaving.value, false); entry.node.props.onClick(); await tick(); assert.equal(refs.showHotelModal.value, false);
};
const pending = (id, endpoint) => { const call = requests.findLast(call => !call.settled && new URL(call.url).pathname === `/api/hotels/${id}/${endpoint}`); assert.ok(call); return call; };
const reply = (call, data) => { assert.ok(!call.settled); call.settled = true; call.response = clone(data); call.resolve(new Response(JSON.stringify({ code: 200, data }), { status: 200, headers: { 'Content-Type': 'application/json' } })); };
const fail = (call, message) => { assert.ok(!call.settled); expectedFailureMessages.add(message); call.settled = true; call.failure = message; call.resolve(new Response(JSON.stringify({ code: 500, message }), { status: 500, headers: { 'Content-Type': 'application/json' } })); };


  const plainText = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(plainText).join('') : plainText(node?.children || '');
  const refresh = async () => {
    const view = await inspect(), entry = view.rows.find(row => row.node.type === 'button' && plainText(row.node) === '刷新回读');
    assert.ok(entry && allowed(entry), 'Original visible refresh control must be enabled');
    const promise = entry.node.props.onClick(); flows.push(promise); await tick(); return { promise };
  };
  const replyPms = async (id, { allowCache = false } = {}) => {
    const call = requests.findLast(call => !call.settled && new URL(call.url).pathname === '/api/hotels/' + id + '/pms-binding');
    if (call) reply(call, pms(id)); else assert.ok(allowCache, 'Original PMS read must exist unless its coordinator reused a completed same-scope read');
    await tick(); assert.equal(refs.hotelForm.value.pms_provider, 'dingdandao_pms');
  };
  const load = async options => { const promise = sandbox.original.loadHotelThreeSourceOnboarding(options); flows.push(promise); await tick(); return { promise }; };
  const enabledCollection = async () => {
    const view = await inspect(), entry = view.rows.find(row => row.node.props?.['data-testid'] === 'hotel-onboarding-enable-collection');
    assert.ok(entry); return allowed(entry);
  };
  t.after(async () => {
    // Also close requests after a baseline assertion failure; no pending fake
    // transport is left behind and teardown responses do not count as oracles.
    for (let pass = 0; pass < 12; pass += 1) {
      await tick();
      const unresolved = requests.filter(call => !call.settled);
      if (!unresolved.length) break;
      for (const call of unresolved) {
        const parsed = new URL(call.url), id = Number(parsed.pathname.split('/')[3]);
        call.teardown_only = true;
        reply(call, parsed.pathname.endsWith('/pms-binding') ? pms(id) : status(id, false));
      }
    }
    await Promise.allSettled(flows); await tick();
    assert.deepEqual(errors, []); assert.ok(requests.every(call => call.settled));
    assert.ok(requests.every(call => call.method === 'GET' && call.has_abort_signal));
    t.diagnostic(JSON.stringify({ sourceHashes, requests: requests.map(({ url, method, settled, aborted, abort_origin, teardown_only }) =>
      ({ url, method, settled, aborted, abort_origin, teardown_only })), gets: requests.length, posts: 0,
      expected_http_failures: expectedHttpFailures, vue_errors: errors.length, notices, unrelated_ota_boundary_calls: sideReads.length }));
  });
  return { refs, ctx, sandbox, requests, notices, errors, expectedHttpFailures, open, close, refresh, replyPms, pending, reply, fail,
    inspect, allowed, tick, load, enabledCollection };
}

test('late A success cannot replace the current B verification after original close and reopen', async t => {
  const h = createHarness(t);
  const a = await h.open(80); await h.replyPms(80); const oldA = h.pending(80, 'three-source-onboarding');
  const covered = (await h.inspect()).rows.filter(row => row.node.props?.['data-testid'] === 'hotel-autopilot-next-action');
  assert.ok(covered.length && covered.every(row => !h.allowed(row)), 'Modal overlay blocks the underlying hotel list');
  await h.close(); const b = await h.open(81); await h.replyPms(81);
  h.reply(h.pending(81, 'three-source-onboarding'), status(81, false)); await b.pending; await h.tick();
  assert.equal(await h.enabledCollection(), false); const before = clone(h.refs.hotelOnboardingSnapshot.value);
  h.reply(oldA, status(80, true)); await a.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingHotelId.value, '81'); assert.equal(h.refs.hotelForm.value.id, 81);
  assert.deepEqual(clone(h.refs.hotelOnboardingSnapshot.value), before);
  assert.equal(await h.enabledCollection(), false); assert.equal(h.ctx.hotelOnboardingReady.value, false);
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.deepEqual(h.notices, []); assert.equal(h.requests.length, 4);
  assert.ok(h.requests.every(call => !call.aborted));
});

test('old failure cannot clear B loading; rejected old loads preserve owner and current failure can retry', async t => {
  const h = createHarness(t);
  const a = await h.open(80); await h.replyPms(80); const oldA = h.pending(80, 'three-source-onboarding');
  await h.close(); const b = await h.open(81); await h.replyPms(81); const currentB = h.pending(81, 'three-source-onboarding');
  h.fail(oldA, 'Synthetic old A read failed'); await a.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingLoading.value, true, 'Old finally must not release B request');
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.refs.hotelOnboardingSnapshot.value, null);
  const count = h.requests.length;
  // This direct delegate call represents an old async caller, not a hidden UI
  // click. It must fail before taking ownership or issuing a transport.
  const rejectedOld = await h.load({ hotelId: '80', silent: true }); assert.equal(await rejectedOld.promise, false);
  assert.equal(h.requests.length, count); assert.equal(h.refs.hotelOnboardingLoading.value, true);
  h.fail(currentB, 'Synthetic current B read failed'); await b.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingLoading.value, false); assert.equal(h.refs.hotelOnboardingError.value, 'Synthetic current B read failed');
  assert.equal(h.refs.hotelForm.value.id, 81);
  const retry = await h.refresh(); h.reply(h.pending(81, 'three-source-onboarding'), status(81, false));
  assert.equal(await retry.promise, true); await h.tick();
  assert.equal(h.refs.hotelOnboardingSnapshot.value.hotel_id, 81); assert.equal(h.refs.hotelOnboardingError.value, '');
  assert.equal(await h.enabledCollection(), false);
  const reread = await h.refresh(); const closedRead = h.pending(81, 'three-source-onboarding');
  const savedSnapshot = clone(h.refs.hotelOnboardingSnapshot.value);
  await h.close(); const closedCount = h.requests.length;
  const closedLoad = await h.load({ hotelId: '81', silent: true }); assert.equal(await closedLoad.promise, false);
  assert.equal(h.requests.length, closedCount);
  h.reply(closedRead, status(81, true)); assert.equal(await reread.promise, false); await h.tick();
  assert.equal(h.refs.showHotelModal.value, false); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.deepEqual(clone(h.refs.hotelOnboardingSnapshot.value), savedSnapshot);
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.deepEqual(h.notices, []);
  assert.equal(h.expectedHttpFailures.length, 2); assert.equal(h.requests.length, 6);
});

test('A to B to A respects the original force-refresh abort and only the newest open owns UI', async t => {
  const h = createHarness(t);
  const firstA = await h.open(80); await h.replyPms(80); const oldA = h.pending(80, 'three-source-onboarding');
  await h.close(); const b = await h.open(81); await h.replyPms(81); const oldB = h.pending(81, 'three-source-onboarding');
  await h.close(); const currentA = await h.open(80); await h.replyPms(80, { allowCache: true });
  const newA = h.pending(80, 'three-source-onboarding');
  assert.notEqual(newA, oldA); assert.equal(oldA.aborted, true); assert.equal(oldA.abort_origin, 'original coordinator signal');
  await firstA.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingLoading.value, true, 'Superseded A catch/finally cannot release new A');
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.refs.hotelOnboardingSnapshot.value, null);
  h.reply(oldB, status(81, true)); await b.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingLoading.value, true); assert.equal(h.refs.hotelOnboardingError.value, '');
  assert.equal(h.refs.hotelOnboardingSnapshot.value, null);
  h.reply(newA, status(80, false)); await currentA.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingHotelId.value, '80'); assert.equal(h.refs.hotelOnboardingSnapshot.value.hotel_id, 80);
  assert.equal(h.refs.hotelOnboardingLoading.value, false); assert.equal(await h.enabledCollection(), false);
  assert.equal(h.requests.filter(call => call.url.endsWith('/three-source-onboarding')).length, 3);
  assert.equal(h.requests.filter(call => call.aborted).length, 1); assert.deepEqual(h.notices, []);
});

test('synthetic auth generation invalidation rejects old data but releases its own loading for original refresh', async t => {
  const h = createHarness(t);
  const a = await h.open(80); await h.replyPms(80); const oldA = h.pending(80, 'three-source-onboarding');
  // Model an external auth generation change without real login or stored
  // credentials. Both coordinator and controller use the original auth helpers.
  h.sandbox.authSessionEpoch += 1;
  h.reply(oldA, status(80, true)); await a.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingSnapshot.value, null); assert.equal(h.refs.hotelOnboardingError.value, '');
  assert.equal(h.refs.hotelOnboardingLoading.value, false, 'No successor read: release this old owner instead of permanently disabling refresh');
  assert.deepEqual(h.notices, []); assert.equal(await h.enabledCollection(), false);
  const refresh = await h.refresh(); h.reply(h.pending(80, 'three-source-onboarding'), status(80, false));
  assert.equal(await refresh.promise, true); await h.tick();
  assert.equal(h.refs.hotelOnboardingSnapshot.value.hotel_id, 80); assert.equal(h.refs.hotelOnboardingError.value, '');
  assert.equal(h.refs.hotelOnboardingLoading.value, false); assert.equal(await h.enabledCollection(), false);
  assert.equal(h.requests.length, 3); assert.ok(h.requests.every(call => !call.aborted));
});
