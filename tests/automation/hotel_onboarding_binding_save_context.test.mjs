import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import path from 'node:path';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
// Defaults read canonical repository source; the optional override only selects a candidate root.
const rootOption = process.argv.find(value => value.startsWith('--source-root='));
const sourceRoot = path.resolve(rootOption ? rootOption.slice('--source-root='.length) : '.');
const read = file => fs.readFileSync(fs.existsSync(path.join(sourceRoot, file)) ? path.join(sourceRoot, file) : path.resolve(file), 'utf8');
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const sourceHashes = Object.fromEntries(['public/app-main.js', 'public/hotel-three-source-onboarding-static.js', 'public/system-static.js',
  'public/components/system/app-main-components.js', 'resources/frontend/templates/fragments/18-page-hotels.html',
  'resources/frontend/templates/fragments/40-dialog-hotel.html'].map(file => [file, sha(read(file))]));
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
  token: Vue.ref('synthetic-binding-session-not-a-credential'), authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', tokenStatus: 'valid', platform: 'all' }),
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
    const parsed = new URL(url), method = options.method || 'GET'; assert.equal(parsed.origin, 'https://synthetic.invalid');
    assert.equal(new Headers(options.headers).get('Authorization'), sandbox.token.value);
    if (method === 'GET') { assert.match(parsed.pathname, /^\/api\/hotels\/(80|81)\/(pms-binding|three-source-onboarding)$/); assert.ok(options.signal); }
    else { assert.equal(method, 'PUT'); assert.equal(parsed.pathname, '/api/hotels/80/platform-bindings/ctrip'); assert.equal(options.signal, undefined); }
    const call = { url, method, body: options.body ? JSON.parse(options.body) : undefined, resolve, reject, settled: false, aborted: false, has_abort_signal: !!options.signal };
    requests.push(call); options.signal?.addEventListener('abort', () => { if (!call.settled) { call.settled = true; call.aborted = true; call.abort_origin = 'original coordinator signal'; reject(new DOMException('Original request aborted', 'AbortError')); } }, { once: true });
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
  const ctripControls = async () => {
    const view = await inspect();
    const inCtrip = row => row.parents.some(node => node.type === 'article' && node.key === 'ctrip');
    const inputs = view.rows.filter(row => row.node.type === 'input' && inCtrip(row));
    assert.equal(inputs.length, 2); assert.ok(inputs.every(row => row.parents.some(node => node.props?.['data-testid'] === 'hotel-onboarding-verification-step')));
    assert.ok(inputs.every(row => allowed(row) && !row.node.props?.readonly));
    assert.ok(inputs[0].parents.some(node => node.type === 'label' && plainText(node).includes('平台公开门店 ID')));
    assert.ok(inputs[1].parents.some(node => node.type === 'label' && plainText(node).includes('平台公开门店名称')));
    const button = view.rows.find(row => row.node.type === 'button' && inCtrip(row) && plainText(row.node) === '保存并回读此来源');
    assert.ok(button); return { view, inputs, button };
  };
  const editCtrip = async values => { const controls = await ctripControls();
    for (const [index, value] of values.entries()) { assert.ok(allowed(controls.inputs[index])); controls.inputs[index].node.props.onInput({ target: { value } }); }
    await tick();
  };
  const saveCtrip = async () => { const {button} = await ctripControls(); assert.ok(allowed(button), 'Only invoke enabled visible original save');
    const promise = button.node.props.onClick(); flows.push(promise); await tick(); return { promise };
  };
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
        if (call.method === 'PUT') { call.settled = true; call.reject(new Error('Synthetic teardown only')); }
        else reply(call, parsed.pathname.endsWith('/pms-binding') ? pms(id) : status(id, false));
      }
    }
    await Promise.allSettled(flows); await tick();
    assert.deepEqual(errors, []); assert.ok(requests.every(call => call.settled));
    assert.ok(requests.every(call => call.method === 'GET' ? call.has_abort_signal : call.method === 'PUT' && !call.has_abort_signal));
    t.diagnostic(JSON.stringify({ sourceHashes, requests: requests.map(({ url, method, settled, aborted, abort_origin, teardown_only }) =>
      ({ url, method, settled, aborted, abort_origin, teardown_only })), gets: requests.filter(call => call.method === 'GET').length, puts: requests.filter(call => call.method === 'PUT').length, posts: 0,
      expected_http_failures: expectedHttpFailures, vue_errors: errors.length, notices, unrelated_ota_boundary_calls: sideReads.length }));
  });
  return { refs, ctx, sandbox, requests, notices, errors, expectedHttpFailures, open, close, refresh, replyPms, pending, reply, fail,
    inspect, allowed, tick, load, ctripControls, editCtrip, saveCtrip };
}



// Public identity fixtures follow bindPlatform/exactBindingReadback. All values
// are fictional and carry no credentials, collection or message-send effects.
const submitted = Object.freeze({ platform_hotel_id: '108080', platform_hotel_name: 'Synthetic A public hotel revised' });
function bindingReply(values = submitted) {
  const profile = status(80, true).profiles.ctrip.profile;
  assert.match(profile.profile_id, /^cbp_[A-Za-z0-9_-]{16,64}$/);
  return {contract_version: 'hotel_three_source_onboarding.v1', tenant_id: 7, hotel_id: 80, platform: 'ctrip',
    binding_status: 'readback_verified', readback_verified: true,
    data_source: {id: 801, platform: 'ctrip', ...values, ingestion_method: 'browser_profile', status: 'ready', enabled: true,
      profile_id: profile.profile_id, profile_binding_status: 'active', secret_stored: false},
    profile, next_action: 'profile_ready', credentials_accepted: false, browser_started: false, collection_performed: false, message_sent: false};
}
function readback(values = submitted) {
  const dto = status(80, true);
  Object.assign(dto.sources.ctrip, values);
  Object.assign(dto.sources.ctrip.binding, values);
  // status() uses the same source map for its service aliases and the same
  // binding object for platform_bindings, matching the backend projection.
  return dto;
}
async function openA(h) {
  const opened = await h.open(80); await h.replyPms(80);
  h.reply(h.pending(80, 'three-source-onboarding'), status(80, true)); await opened.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingStep.value, 'verification'); assert.equal(h.refs.hotelForm.value.id, 80);
  assert.equal(h.refs.hotelOnboardingSnapshot.value.hotel_id, 80); assert.equal(h.refs.hotelOnboardingLoading.value, false);
}
async function beginSave(h, values = submitted) {
  await h.editCtrip([values.platform_hotel_id, values.platform_hotel_name]);
  const save = await h.saveCtrip(); const put = h.pending(80, 'platform-bindings/ctrip');
  assert.equal(put.method, 'PUT'); assert.deepEqual(put.body, values);
  assert.equal(h.refs.hotelOnboardingLoading.value, true); assert.equal(h.refs.hotelSaving.value, false);
  assert.equal(h.allowed((await h.ctripControls()).button), false);
  return {save, put};
}
async function assertPendingOwner(h, hotelId, call) {
  assert.equal(call.settled, false); assert.equal(h.refs.hotelForm.value.id, hotelId);
  assert.equal(h.refs.hotelOnboardingHotelId.value, String(hotelId));
  assert.equal(h.refs.hotelOnboardingLoading.value, true);
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.refs.hotelOnboardingSnapshot.value, null);
  const controls = await h.ctripControls(); assert.equal(h.allowed(controls.button), false);
  assert.match(controls.view.html, /正在按精确门店 ID 保存或回读/);
  assert.ok(!controls.view.rows.some(row => row.node.props?.['data-testid'] === 'hotel-onboarding-error'));
}
function assertClosedTransport(h, {gets, puts, aborts = 0}) {
  assert.equal(h.requests.filter(call => call.method === 'GET').length, gets);
  assert.equal(h.requests.filter(call => call.method === 'PUT').length, puts);
  assert.equal(h.requests.filter(call => call.aborted).length, aborts);
  assert.ok(h.requests.every(call => call.settled && !call.teardown_only)); assert.deepEqual(h.errors, []);
}

for (const outcome of ['success', 'failure']) test(`late A binding PUT ${outcome} cannot pollute B or release its pending read`, async t => {
  const h = createHarness(t); await openA(h); const {save, put} = await beginSave(h);
  const covered = (await h.inspect()).rows.filter(row => row.node.props?.['data-testid'] === 'hotel-autopilot-next-action');
  assert.ok(covered.length && covered.every(row => !h.allowed(row)));
  await h.close(); const b = await h.open(81); await h.replyPms(81); const readB = h.pending(81, 'three-source-onboarding');
  await assertPendingOwner(h, 81, readB);
  const formB = clone(h.refs.hotelForm.value), draftB = clone(h.refs.hotelOnboardingBindingForms.value);
  if (outcome === 'success') h.reply(put, bindingReply()); else h.fail(put, 'Synthetic old A binding unavailable');
  assert.equal(await save.promise, false); await h.tick();
  await assertPendingOwner(h, 81, readB); assert.equal(h.requests.length, 5, 'No stale A follow-up GET');
  assert.deepEqual(clone(h.refs.hotelForm.value), formB); assert.deepEqual(clone(h.refs.hotelOnboardingBindingForms.value), draftB);
  assert.deepEqual(h.notices, []);
  h.reply(readB, status(81, false)); await b.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingSnapshot.value.hotel_id, 81); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.expectedHttpFailures.length, outcome === 'failure' ? 1 : 0);
  assertClosedTransport(h, {gets: 4, puts: 1});
});

test('same hotel reopen owns its new force read after an old binding follow-up is aborted by the original coordinator', async t => {
  const h = createHarness(t); await openA(h); const {save, put} = await beginSave(h);
  h.reply(put, bindingReply()); await h.tick(); const oldRead = h.pending(80, 'three-source-onboarding');
  assert.equal(h.refs.hotelOnboardingLoading.value, true); await h.close();
  const reopen = await h.open(80); await h.replyPms(80);
  const newRead = h.pending(80, 'three-source-onboarding'); assert.notEqual(newRead, oldRead);
  assert.equal(oldRead.aborted, true); assert.equal(oldRead.abort_origin, 'original coordinator signal');
  assert.equal(await save.promise, false); await h.tick(); await assertPendingOwner(h, 80, newRead);
  assert.deepEqual(h.notices, []);
  h.reply(newRead, readback()); await reopen.pending; await h.tick();
  assert.equal(h.refs.hotelOnboardingSnapshot.value.hotel_id, 80); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.allowed((await h.ctripControls()).button), true);
  assertClosedTransport(h, {gets: 5, puts: 1, aborts: 1});
});

test('current exact binding confirmation preserves the later editable draft without posting it', async t => {
  const h = createHarness(t); await openA(h); const {save, put} = await beginSave(h);
  const later = {platform_hotel_id: '108081', platform_hotel_name: 'Synthetic later draft B'};
  await h.editCtrip([later.platform_hotel_id, later.platform_hotel_name]);
  assert.deepEqual(clone(h.refs.hotelOnboardingBindingForms.value.ctrip), later);
  h.reply(put, bindingReply()); await h.tick(); const exact = h.pending(80, 'three-source-onboarding');
  assert.equal(h.refs.hotelOnboardingLoading.value, true); assert.equal(h.allowed((await h.ctripControls()).button), false);
  h.reply(exact, readback()); assert.equal(await save.promise, true); await h.tick();
  assert.deepEqual(clone(h.refs.hotelOnboardingBindingForms.value.ctrip), later);
  assert.deepEqual(put.body, submitted); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.allowed((await h.ctripControls()).button), true);
  assert.deepEqual(h.notices, [{message: '携程门店身份已保存并回读', type: 'success'}]);
  assertClosedTransport(h, {gets: 3, puts: 1});
});

test('current HTTP failure and exact-read mismatch keep the draft and allow original explicit save retry', async t => {
  const h = createHarness(t); await openA(h); const first = await beginSave(h);
  h.fail(first.put, 'Synthetic current binding unavailable'); assert.equal(await first.save.promise, false); await h.tick();
  assert.equal(h.refs.hotelOnboardingLoading.value, false); assert.equal(h.refs.hotelOnboardingError.value, 'Synthetic current binding unavailable');
  assert.deepEqual(clone(h.refs.hotelOnboardingBindingForms.value.ctrip), submitted);
  assert.equal(h.allowed((await h.ctripControls()).button), true);
  const retry = await h.saveCtrip(); h.reply(h.pending(80, 'platform-bindings/ctrip'), bindingReply()); await h.tick();
  // A later valid same-hotel binding representation differs from this submit.
  // This is an exact-read mismatch, not a malformed or cross-hotel DTO.
  h.reply(h.pending(80, 'three-source-onboarding'), readback({platform_hotel_id: '108099', platform_hotel_name: 'Synthetic other current identity'}));
  assert.equal(await retry.promise, false); await h.tick(); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.equal(h.refs.hotelOnboardingError.value, '平台门店身份回读与本次保存不一致');
  assert.deepEqual(clone(h.refs.hotelOnboardingBindingForms.value.ctrip), submitted);
  const recovered = await h.saveCtrip(); h.reply(h.pending(80, 'platform-bindings/ctrip'), bindingReply()); await h.tick();
  h.reply(h.pending(80, 'three-source-onboarding'), readback()); assert.equal(await recovered.promise, true); await h.tick();
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.deepEqual(clone(h.refs.hotelOnboardingBindingForms.value.ctrip), submitted);
  assert.deepEqual(h.notices.map(item => item.type), ['error', 'error', 'success']);
  assert.equal(h.expectedHttpFailures.length, 1); assertClosedTransport(h, {gets: 4, puts: 3});
});

test('closed or auth-invalid binding save without successor releases its own busy state and original recovery remains usable', async t => {
  for (const invalidation of ['closed', 'auth']) {
    const h = createHarness(t); await openA(h); const {save, put} = await beginSave(h);
    if (invalidation === 'closed') await h.close(); else h.sandbox.authSessionEpoch += 1;
    // External auth generation invalidation is synthetic, without reading or
    // changing real credentials; original auth helpers remain active.
    const count = h.requests.length;
    h.reply(put, bindingReply()); await h.tick();
    assert.equal(h.requests.length, count, 'Invalidated save cannot start a fresh follow-up under a new context');
    assert.equal(await save.promise, false); await h.tick();
    assert.equal(h.refs.hotelOnboardingLoading.value, false); assert.equal(h.refs.hotelOnboardingError.value, '');
    assert.deepEqual(h.notices, []);
    if (invalidation === 'closed') {
      // A direct stale delegate is a negative caller-boundary check, not a
      // dispatched event on a hidden control. It must not acquire busy/epoch.
      const previousCalls = h.requests.length;
      const rejected = h.ctx.saveHotelOnboardingBinding({platform: 'ctrip'}); await h.tick();
      assert.equal(h.requests.length, previousCalls); assert.equal(await rejected, false);
      assert.equal(h.refs.hotelOnboardingLoading.value, false);
      const reopened = await h.open(80); await h.replyPms(80);
      h.reply(h.pending(80, 'three-source-onboarding'), readback()); await reopened.pending;
    } else {
      const refreshed = await h.refresh(); h.reply(h.pending(80, 'three-source-onboarding'), readback());
      assert.equal(await refreshed.promise, true);
    }
    await h.tick(); assert.equal(h.refs.hotelOnboardingSnapshot.value.hotel_id, 80);
    assert.equal(h.refs.hotelOnboardingLoading.value, false); assert.equal(h.refs.hotelOnboardingError.value, '');
    assert.equal(h.allowed((await h.ctripControls()).button), true);
    assertClosedTransport(h, {gets: invalidation === 'closed' ? 4 : 3, puts: 1});
  }
});

test('binding failure yields shared loading to a same-modal successor read without changing that read error or busy', async t => {
  const h = createHarness(t); await openA(h); const {save, put} = await beginSave(h);
  // Static control-flow evidence: an adjacent plan caller can start this same
  // loader while a binding PUT is pending. Call the original loader delegate
  // to model only that read-ownership boundary. This is not a plan-button UI
  // test, plan-authorization DTO, second write action, or collection operation.
  const successor = await h.load({hotelId: '80', silent: true});
  const successorRead = h.pending(80, 'three-source-onboarding');
  assert.equal(h.refs.hotelOnboardingLoading.value, true);
  const snapshot = clone(h.refs.hotelOnboardingSnapshot.value), count = h.requests.length;
  h.fail(put, 'Synthetic binding failed after successor read');
  assert.equal(await save.promise, false); await h.tick();
  assert.equal(h.requests.length, count, 'No extra binding follow-up read');
  assert.equal(successorRead.settled, false); assert.equal(h.refs.hotelOnboardingLoading.value, true);
  assert.equal(h.refs.hotelOnboardingError.value, ''); assert.deepEqual(h.notices, []);
  assert.deepEqual(clone(h.refs.hotelOnboardingSnapshot.value), snapshot);
  const waiting = await h.ctripControls(); assert.equal(h.allowed(waiting.button), false);
  assert.match(waiting.view.html, /正在按精确门店 ID 保存或回读/);
  h.reply(successorRead, status(80, true)); assert.equal(await successor.promise, true); await h.tick();
  assert.equal(h.refs.hotelOnboardingLoading.value, false); assert.equal(h.refs.hotelOnboardingError.value, '');
  assert.deepEqual(clone(h.refs.hotelOnboardingBindingForms.value.ctrip), submitted);
  assert.equal(h.expectedHttpFailures.length, 1); assertClosedTransport(h, {gets: 3, puts: 1});
});
