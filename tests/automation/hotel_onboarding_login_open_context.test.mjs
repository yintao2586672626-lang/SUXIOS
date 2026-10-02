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
const resolveSource = file => fs.existsSync(path.join(sourceRoot, file)) ? path.join(sourceRoot, file) : path.resolve(file);
const read = file => fs.readFileSync(resolveSource(file), 'utf8');
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const sourceRecords = ['public/app-main.js', 'public/hotel-three-source-onboarding-static.js', 'public/system-static.js',
  'public/components/system/app-main-components.js', 'resources/frontend/templates/fragments/18-page-hotels.html',
  'resources/frontend/templates/fragments/40-dialog-hotel.html'].map(file => ({ path: file, resolved_path: resolveSource(file), sha256: sha(read(file)) }));
const sourceHashes = Object.fromEntries(sourceRecords.map(({ path, sha256 }) => [path, sha256]));
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
  hotelAutomationLifecycleById: Object.fromEntries(hotels.map(hotel => [String(hotel.id), { hotel_id: String(hotel.id), status: 'awaiting_login', next_action_code: 'open_hotel_login', next_action_label: '在原设备完成一次登录授权', total_stage_count: 6, completed_stage_count: 1 }])),
  wechatNotificationHotelId: '', manualNotificationForm: {},
};
const refs = Object.fromEntries(Object.entries(initial).map(([key, value]) => [key, Vue.ref(value)]));
const requests = [], notices = [], errors = [], expectedHttpFailures = [], expectedFailureMessages = new Set(), sideReads = [], flows = [], viewers = [];
const sandbox = { ...refs, window: { matchMedia: query => { assert.equal(query, '(min-width: 1280px)'); return { matches: true }; } }, runtimeWindow: { location: { origin: 'https://synthetic.invalid' }, open(url,target) {
    assert.equal(url,'about:blank');assert.equal(target,'_blank');
    const viewer={opener:undefined,closed:false,navigations:[],location:{replace(value){
      const parsed=new URL(value);assert.equal(parsed.origin,'https://synthetic.invalid');assert.equal(parsed.pathname,'/cloud-browser-viewer/vnc.html');
      assert.equal(parsed.search,'?autoconnect=true&resize=scale&path=cloud-browser-viewer%2Fwebsockify');if (viewer.failNavigation) throw new Error('Synthetic viewer navigation rejected'); viewer.navigations.push(value);
    }},close(){viewer.closed=true;}};viewers.push(viewer);return viewer;
  } },
  h: Vue.h, markRaw: Vue.markRaw, ref: Vue.ref, computed: Vue.computed, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, Date, setTimeout, clearTimeout,
  API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 1,
  user: Vue.ref({ id: 11, tenant_id: 7, is_super_admin: true, permissions: { can_manage_own_hotels: true } }),
  token: Vue.ref('synthetic-round111-session-not-a-credential'), authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', tokenStatus: 'valid', platform: 'all' }),
  filterReportHotel: Vue.ref('80'), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
  console: { error: (...args) => {
    const error = args.at(-1);
    if (args[0] === 'API请求失败:' && error?.status === 422 && expectedFailureMessages.has(error.message)) {
      expectedHttpFailures.push({ status: 422, message: error.message }); return;
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
    else { assert.equal(method, 'POST'); assert.equal(parsed.pathname, '/api/cloud-browser-profiles/open-login'); assert.deepEqual(JSON.parse(options.body), { hotel_id: 80, platform: 'ctrip' }); assert.equal(options.signal, undefined); }
    const call = { url, method, body: options.body ? JSON.parse(options.body) : undefined, resolve, reject, settled: false, aborted: false, has_abort_signal: !!options.signal };
    requests.push(call); options.signal?.addEventListener('abort', () => { if (!call.settled) { call.settled = true; call.aborted = true; call.abort_origin = 'original coordinator signal'; reject(new DOMException('Original request aborted', 'AbortError')); } }, { once: true });
  }),
};
// The unchanged original factory passes window as runtimeWindow.
Object.assign(sandbox.window, {open: sandbox.runtimeWindow.open, location: sandbox.runtimeWindow.location});
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
const failOpen = call => {
  assert.ok(!call.settled); assert.equal(new URL(call.url).pathname, '/api/cloud-browser-profiles/open-login');
  const body = { code: 422, message: '无法打开云端登录窗口', data: { reason: 'cloud_browser_gateway_open_failed' }, time: Math.floor(Date.now() / 1000) };
  expectedFailureMessages.add(body.message); call.settled = true; call.response = clone(body);
  call.resolve(new Response(JSON.stringify(body), { status: 422, headers: { 'Content-Type': 'application/json' } }));
};
const reply = (call, data) => { assert.ok(!call.settled); call.settled = true; call.response = clone(data); call.resolve(new Response(JSON.stringify({ code: 200, data }), { status: 200, headers: { 'Content-Type': 'application/json' } })); };



  const plainText = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(plainText).join('') : plainText(node?.children || '');
  const loginControl=async label=>{
    const view=await inspect();const entry=view.rows.find(row=>row.node.type==='button'&&plainText(row.node)===label
      &&row.parents.some(node=>node.props?.['data-testid']==='hotel-onboarding-source-ctrip'));
    assert.ok(entry,'Original ctrip control exists: '+label);
    assert.ok(entry.parents.some(node=>node.props?.['data-testid']==='hotel-onboarding-authorization-step'));
    return {view,entry};
  };
  const clickLogin=async label=>{const {entry}=await loginControl(label);assert.ok(allowed(entry),'Original control is enabled');
    const promise=entry.node.props.onClick();flows.push(promise);await tick();return {promise};};
  const cloudPending=(hotelId,endpoint)=>{const call=requests.findLast(call=>!call.settled&&new URL(call.url).pathname==='/api/cloud-browser-profiles/'+endpoint&&call.body.hotel_id===hotelId);assert.ok(call);return call;};
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
        if (call.method === 'POST') failOpen(call);
        else reply(call, parsed.pathname.endsWith('/pms-binding') ? pms(id) : status(id, false));
      }
    }
    await Promise.allSettled(flows); await tick();
    assert.deepEqual(errors, []); assert.ok(requests.every(call => call.settled));
    assert.ok(requests.every(call => call.method === 'GET' ? call.has_abort_signal : call.method === 'POST' && !call.has_abort_signal));
    t.diagnostic(JSON.stringify({ sourceHashes, source_records: sourceRecords, requests: requests.map(({ url, method, settled, aborted, abort_origin, teardown_only }) =>
      ({ url, method, settled, aborted, abort_origin, teardown_only })), gets: requests.filter(call => call.method === 'GET').length, puts: 0, posts: requests.filter(call => call.method === 'POST').length,
      expected_http_failures: expectedHttpFailures, vue_errors: errors.length, notices, unrelated_ota_boundary_calls: sideReads.length, viewers: viewers.map(({ opener, closed, navigations, failNavigation }) => ({ opener, closed, navigations, synthetic_navigation_exception: !!failNavigation })) }));
  });
  return { refs, ctx, sandbox, requests, notices, errors, expectedHttpFailures, open, close, refresh, replyPms, pending, reply, failOpen,
    inspect, allowed, tick, load, loginControl, clickLogin, cloudPending, viewers, track: promise => flows.push(promise) };
}




// Public pre-login profile contract: bindings can exist while profiles remain
// unauthorized. No fixture promotes either hotel to ready_to_collect.
const preloginStatus=id=>{
  const dto=status(id,true);
  for(const platform of ['ctrip','meituan','dingdandao']){
    const projection=dto.profiles[platform];projection.profile_status='unauthorized';projection.ready=false;
    Object.assign(projection.profile,{authorization_status:'unauthorized',status_reason:'',login_verified_at:null,ready_at:null,session_expires_at:null});
    Object.assign(dto.sources[platform],{status:'unauthorized',profile_ready:false,authorization_status:'unauthorized',detail:'profile_login_required'});
  }
  Object.assign(dto,{status:'blocked',overall_status:'blocked',onboarding_status:'blocked',ready:false,source_status:'blocked',source_ready:false});
  dto.blockers=[...['dingdandao','ctrip','meituan'].map(platform=>({code:platform+'_profile_not_ready',action:'request_'+platform+'_login'})),
    {code:'collection_plan_not_active',action:'activate_collection_plan'}];dto.next_action=dto.blockers[0].action;return dto;
};
const openDto=id=>{
  const expiry=new Date(Date.now()+600000),pad=n=>String(n).padStart(2,'0');
  const expires_at=[expiry.getFullYear(),pad(expiry.getMonth()+1),pad(expiry.getDate())].join('-')+' '+[pad(expiry.getHours()),pad(expiry.getMinutes()),pad(expiry.getSeconds())].join(':');
  return {status:'awaiting_login',hotel_id:id,platform:'ctrip',profile_id:preloginStatus(id).profiles.ctrip.profile.profile_id,
    session_id:'cbls_'+Buffer.alloc(24,id+10).toString('base64url'),expires_at,
    viewer_url:'/cloud-browser-viewer/vnc.html?autoconnect=true&resize=scale&path=cloud-browser-viewer%2Fwebsockify',
    browser_started:true,profile_encrypted_at_rest:true,credentials_stored_by_suxios:false};
};
const INFO = '已打开携程云端可视登录页，请在该页面完成登录';
const OPEN_FAILURE = '无法打开云端登录窗口';
const noResidual = h => { assert.ok(h.requests.every(call => call.settled && !call.aborted && !call.teardown_only)); assert.deepEqual(h.errors, []); };
const openStatus = async (h, id) => {
  const flow = await h.open(id); await h.replyPms(id); h.reply(h.pending(id, 'three-source-onboarding'), preloginStatus(id));
  await flow.pending; await h.tick(); assert.equal(h.refs.hotelOnboardingHotelId.value, String(id));
};
const beginOpen = async (h, id = 80) => {
  const flow = await h.clickLogin('打开云端登录'), call = h.cloudPending(id, 'open-login');
  assert.deepEqual(call.body, { hotel_id: id, platform: 'ctrip' }); assert.equal(h.refs.hotelSaving.value, false);
  assert.equal(h.refs.hotelOnboardingBusyPlatform.value, 'ctrip');
  const view = await h.inspect();
  const refresh = view.rows.filter(row => row.node.type === 'button' && row.node.children === '刷新状态');
  assert.equal(refresh.length, 3); assert.ok(refresh.every(row => !h.allowed(row)), 'Original same-modal refresh is disabled while opening; do not dispatch it');
  return { promise: flow.promise, call, viewer: h.viewers.at(-1) };
};
const assertEmpty = h => { assert.deepEqual(clone(h.refs.hotelOnboardingLoginSessions.value), {}); assert.equal(h.refs.hotelOnboardingBusyPlatform.value, ''); };

test('late A awaiting-login success cannot publish A session or completion control in current B', async t => {
  const h = createHarness(t); await openStatus(h, 80); const old = await beginOpen(h);
  const covered = (await h.inspect()).rows.filter(row => row.node.props?.['data-testid'] === 'hotel-autopilot-next-action');
  assert.ok(covered.length && covered.every(row => !h.allowed(row)));
  await h.close(); await openStatus(h, 81); const beforeHotel = clone(h.refs.hotelForm.value), beforeSnapshot = clone(h.refs.hotelOnboardingSnapshot.value);
  const count = h.requests.length; h.reply(old.call, openDto(80)); assert.equal(await old.promise, false); await h.tick();
  assert.equal(h.requests.length, count); assertEmpty(h); assert.equal(h.refs.hotelOnboardingError.value, '');
  assert.deepEqual(clone(h.refs.hotelForm.value), beforeHotel); assert.deepEqual(clone(h.refs.hotelOnboardingSnapshot.value), beforeSnapshot);
  const current = await h.loginControl('打开云端登录'); assert.ok(h.allowed(current.entry));
  assert.match(current.view.html, /当前精确门店 ID：81/); assert.doesNotMatch(current.view.html, /我已在云端页面完成登录/);
  assert.equal(old.viewer.closed, true); assert.deepEqual(old.viewer.navigations, []); assert.deepEqual(h.notices, []);
  assert.equal(h.requests.length, 5); noResidual(h);
});

test('current open failure releases its original control and explicit retry retains a valid awaiting-login session', async t => {
  const h = createHarness(t); await openStatus(h, 80); const failed = await beginOpen(h);
  h.failOpen(failed.call); assert.equal(await failed.promise, false); await h.tick();
  assertEmpty(h); assert.equal(failed.viewer.closed, true); assert.equal(h.refs.hotelOnboardingError.value, OPEN_FAILURE);
  const retryControl = await h.loginControl('打开云端登录'); assert.ok(h.allowed(retryControl.entry)); assert.equal(h.requests.length, 3);
  const retry = await beginOpen(h), dto = openDto(80); h.reply(retry.call, dto); assert.equal(await retry.promise, true); await h.tick();
  assert.deepEqual(clone(h.refs.hotelOnboardingLoginSessions.value.ctrip), { profile_id: dto.profile_id, session_id: dto.session_id });
  assert.equal(h.refs.hotelOnboardingBusyPlatform.value, 'ctrip'); assert.equal(h.refs.hotelOnboardingError.value, '');
  const completion = await h.loginControl('我已在云端页面完成登录'); assert.ok(h.allowed(completion.entry));
  assert.equal(retry.viewer.closed, false); assert.equal(retry.viewer.navigations.length, 1); assert.equal(retry.viewer.opener, null);
  assert.deepEqual(h.notices, [{ message: OPEN_FAILURE, type: 'error' }, { message: INFO, type: 'info' }]);
  assert.equal(h.ctx.hotelOnboardingReady.value, false); assert.equal(h.requests.length, 4); noResidual(h);
});

test('A-B-A reset rejects a cleaned-up old failure without closing the new viewer or clearing its busy', async t => {
  const h = createHarness(t); await openStatus(h, 80); const old = await beginOpen(h);
  // Synthetic backend premise: the old open failed and its original Gateway
  // revoke/cleanup/cancel branch completed; only HTTP422 delivery is delayed.
  // B only reads. No simultaneous successful gateway slots are assumed.
  await h.close(); await openStatus(h, 81); await h.close(); await openStatus(h, 80);
  const current = await beginOpen(h); h.failOpen(old.call); assert.equal(await old.promise, false); await h.tick();
  assert.equal(h.refs.hotelOnboardingBusyPlatform.value, 'ctrip'); assert.equal(h.refs.hotelOnboardingError.value, '');
  assert.deepEqual(h.notices, []); assert.equal(old.viewer.closed, true); assert.equal(current.viewer.closed, false);
  assert.deepEqual(clone(h.refs.hotelOnboardingLoginSessions.value), {}); assert.deepEqual(current.viewer.navigations, []);
  const dto = openDto(80); h.reply(current.call, dto); assert.equal(await current.promise, true); await h.tick();
  assert.deepEqual(clone(h.refs.hotelOnboardingLoginSessions.value.ctrip), { profile_id: dto.profile_id, session_id: dto.session_id });
  assert.equal(h.refs.hotelOnboardingBusyPlatform.value, 'ctrip'); assert.equal(current.viewer.navigations.length, 1);
  const completion = await h.loginControl('我已在云端页面完成登录'); assert.ok(h.allowed(completion.entry));
  assert.deepEqual(h.notices, [{ message: INFO, type: 'info' }]); assert.equal(h.requests.length, 8); noResidual(h);
});

for (const boundary of ['auth', 'closed']) test(`old ${boundary} open success releases its own busy without a successor or server cleanup claim`, async t => {
  const h = createHarness(t); await openStatus(h, 80); const old = await beginOpen(h);
  if (boundary === 'auth') h.sandbox.authSessionEpoch += 1; else await h.close();
  h.reply(old.call, openDto(80)); assert.equal(await old.promise, false); await h.tick();
  assertEmpty(h); assert.equal(h.refs.hotelOnboardingError.value, ''); assert.equal(h.refs.hotelOnboardingLoading.value, false);
  assert.equal(old.viewer.closed, true); assert.deepEqual(old.viewer.navigations, []); assert.deepEqual(h.notices, []);
  if (boundary === 'auth') await h.close();
  // Explicit stale delegate guard, not a hidden native control event.
  const count = h.requests.length, windows = h.viewers.length;
  const closed = h.ctx.openHotelOnboardingCloudLogin({ platform: 'ctrip' }); h.track(closed); await h.tick();
  assert.equal(h.requests.length, count); assert.equal(h.viewers.length, windows); assert.equal(await closed, false);
  await openStatus(h, 80); const current = await h.loginControl('打开云端登录'); assert.ok(h.allowed(current.entry));
  assertEmpty(h); assert.deepEqual(h.notices, []);
  // Reopen only reads. Closing a local viewer does not prove server capacity
  // or authorize another gateway open, so no second open POST is issued here.
  assert.equal(h.requests.filter(call => call.method === 'POST').length, 1); noResidual(h);
});

test('synthetic viewer navigation exception leaves no published session and restores the original opening control', async t => {
  const h = createHarness(t); await openStatus(h, 80); const current = await beginOpen(h);
  // Source exception-branch verification in the in-memory facade only. This
  // is not a reproduced native browser failure or a server cleanup assertion.
  current.viewer.failNavigation = true;
  h.reply(current.call, openDto(80)); assert.equal(await current.promise, false); await h.tick();
  assertEmpty(h); assert.equal(current.viewer.closed, true); assert.deepEqual(current.viewer.navigations, []);
  assert.equal(h.refs.hotelOnboardingError.value, 'Synthetic viewer navigation rejected');
  assert.deepEqual(h.notices, [{ message: 'Synthetic viewer navigation rejected', type: 'error' }]);
  const retry = await h.loginControl('打开云端登录'); assert.ok(h.allowed(retry.entry));
  assert.doesNotMatch(retry.view.html, /我已在云端页面完成登录/); assert.equal(h.requests.length, 3); noResidual(h);
});
