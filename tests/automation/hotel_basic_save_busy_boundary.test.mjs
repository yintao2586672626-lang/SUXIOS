import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const files = ['public/app-main.js', 'public/system-static.js', 'resources/frontend/templates/fragments/18-page-hotels.html', 'resources/frontend/templates/fragments/40-dialog-hotel.html', 'app/controller/Hotel.php', 'app/controller/Base.php', 'public/components/system/app-main-components.js', 'public/hotel-three-source-onboarding-static.js'];
const bytes = Object.fromEntries(files.map(path => [path, readFileSync(path)]));
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const hashes = Object.fromEntries(files.map(path => [path, sha(bytes[path])]));
const main = bytes[files[0]].toString().replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start); return main.slice(a, b); };
const requestSource = [section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='), section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='), section('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='), section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'), section('            const request = async (', '            const apiRequest = request;')].join('\n');
const permissionSource = ['userHasPermission', 'canManageOwnHotels'].map(name => {
  const line = main.split('\n').find(line => line.startsWith('            const ' + name + ' ='));
  assert.ok(line); return line;
}).join('\n');
const sliceSource = (source, start, end) => { const a = source.indexOf(start), b = source.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a); return source.slice(a, b); };
const panelSource = sliceSource(bytes[files[6]].toString(), 'const HotelThreeSourceOnboardingPanel = {', 'const OperatingLoopAuthority = {');
const resetSource = sliceSource(bytes[files[7]].toString(), 'const resetHotelOnboarding =', 'const hotelOnboardingExpectedPlatforms =');
const pmsSource = section('            const applyHotelPmsBinding =', '            const openSelectedHotelPmsConfiguration =');
const businessSource = [
  section('            const getEmptyHotelBackgroundProfile =', '            const getHotelDescriptionProfileRows ='),
  section('            const openHotelModal = async', '            const setDefaultMainHotel ='),
  section('            const saveHotel = async', '            const toggleHotelStatus ='),
  section('            const loadHotels = async', '            let startupHotelListLoadTimer ='),
  section('            const hotelBackgroundProfileFields =', '            const hotelBackgroundProfileForm ='),
  section('            const hotelBusinessProfileEditor =', '            const hotelForm ='),
  section('            const dedupeHotels =', '            const normalizeHotelAutomationLifecycle ='),
].join('\n');
const astWalk = (node, rows = []) => { rows.push(node); (node.children || []).forEach(child => astWalk(child, rows)); return rows; };
const pageTree = astWalk(parse(bytes[files[2]].toString()));
const dialogTree = astWalk(parse(bytes[files[3]].toString()));
const edit = pageTree.find(node => node.type === 1 && node.tag === 'button' && node.props.some(prop => prop.name === 'on' && prop.exp?.content === 'openHotelModal(hotel)') && node.loc.source.includes('>编辑</button>'));
const newEntry = pageTree.find(node => node.type === 1 && node.tag === 'button' && node.props.some(prop => prop.name === 'on' && prop.exp?.content === 'openHotelModal()'));
const mergeGroup = pageTree.find(node => node.type === 1 && node.tag === 'div' && node.props.some(prop => prop.name === 'if' && prop.exp?.content === 'user?.is_super_admin') && node.loc.source.includes('openHotelMergeModal()'));
const mergeEntry = astWalk(mergeGroup).find(node => node.type === 1 && node.tag === 'button' && node.props.some(prop => prop.name === 'on' && prop.exp?.content === 'openHotelMergeModal()'));
assert.ok(newEntry && mergeGroup && mergeEntry);
const name = pageTree.find(node => node.type === 1 && node.tag === 'span' && node.props.some(prop => prop.name === 'bind' && prop.arg?.content === 'title' && prop.exp?.content === 'hotel.name'));
const nameInput = dialogTree.find(node => node.type === 1 && node.tag === 'input' && node.props.some(prop => prop.name === 'model' && prop.exp?.content === 'hotelForm.name'));
const footer = dialogTree.find(node => node.type === 1 && node.tag === 'div' && node.props.some(prop => prop.name === 'class' && prop.value?.content === 'flex justify-end gap-3 pt-6 mt-6 border-t border-gray-100'));
const form = dialogTree.find(node => node.type === 1 && node.tag === 'form' && node.props.some(prop => prop.name === 'on' && prop.exp?.content === 'saveHotel'));
assert.ok(edit && name && nameInput && footer && form);
const formOpening = form.loc.source.slice(0, form.loc.source.indexOf('>') + 1).replace(' v-else', '');
// Render the complete original basic modal and its original background-profile component.
// The default fixture keeps binding algorithms closed. realPms opts into original
// public-metadata methods with strictly allowlisted synthetic GET/PUT responses.
const visible = '<section><div v-if="currentPage === \'hotels\' && !showHotelModal">' + newEntry.loc.source + '<div v-if="user?.is_super_admin">' + mergeEntry.loc.source + '</div><div v-for="hotel in hotels" :key="hotel.id">' + name.loc.source + '<div v-if="canManageOwnHotels()">' + edit.loc.source + '</div></div></div>' + bytes[files[3]].toString() + '</section>';
const render = new Function('Vue', compile(visible, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const hotel = { id: 80, tenant_id: 7, name: 'Synthetic initial hotel', code: 'SYNTHETIC80', address: '', contact_person: 'Synthetic operator', contact_phone: '', description: '', status: 1, ota_channel_strategy: 'none' };
let assertions = 0;
const eq = (actual, expected, label) => { assertions++; assert.deepEqual(actual, expected, label); };
const ok = (value, label) => { assertions++; assert.ok(value, label); };
function fixture({ secondHotel = false, superAdmin = true, realPms = false } = {}) {
  const requests = [], notices = [], sideBoundaryCalls = [], mergeCalls = [];
  const initialHotels = [clone(hotel), ...(secondHotel ? [{ ...clone(hotel), id: 81, name: 'Synthetic other hotel', code: 'SYNTHETIC81' }] : [])];
  const state = Object.fromEntries(Object.entries({ currentPage: 'hotels', showHotelModal: false, hotelForm: {}, hotelSaving: false,
    hotels: clone(initialHotels), permittedHotels: clone(initialHotels), hotelBackgroundProfileForm: {}, hotelPmsBinding: null, hotelPmsBindingLoading: false,
    hotelPmsBindingError: '', hotelOtaConfig: {}, hotelOtaConfigLoading: false, ctripConfigList: [], meituanConfigList: [], platformDataSources: [],
    showHotelOtaConfig: false, hotelOnboardingActive: false, hotelOnboardingHotelId: '', hotelOnboardingStep: 'hotel', hotelOnboardingError: '',
    hotelOnboardingSnapshot: null, hotelOnboardingLoading: false, hotelOnboardingBusyPlatform: '', hotelOnboardingLoginSessions: {},
    hotelOnboardingBindingForms: {}, hotelOnboardingCollectionPlanStatus: 'idle', hotelOnboardingCollectionPlanError: '',
    selectedCtripHotelId: '', hotelListLoading: false, hotelListLoadFailed: false, hotelListSnapshotReady: false,
  }).map(([key, value]) => [key, Vue.ref(value)]));
  const sandbox = { ...state, h: Vue.h, markRaw: Vue.markRaw, window: {}, URL, URLSearchParams, Headers, AbortController, DOMException, Date, Intl, setTimeout, clearTimeout,
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 0,
    filterReportHotel: Vue.ref('80'), authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    user: Vue.ref({ id: 11, is_super_admin: superAdmin, tenant_id: 7, permissions: { can_manage_own_hotels: true } }), token: Vue.ref(''), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: 1, token: '' }), isAuthSessionCurrent: value => value.epoch === 1,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    fetch: (url, options = {}) => new Promise((resolve, reject) => {
      const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.ok((['/api/hotels', '/api/hotels/all'].includes(parsed.pathname) && (!options.method || options.method === 'GET')) || (parsed.pathname === '/api/hotels' && options.method === 'POST') || (parsed.pathname === '/api/hotels/80' && options.method === 'PUT') || (realPms && parsed.pathname === '/api/hotels/80/pms-binding' && ['GET', 'PUT'].includes(options.method || 'GET')), 'Closed basic hotel/public PMS metadata URL/method allowlist');
      const req = { url, options, resolve, reject, settled: false }; requests.push(req);
      options.signal?.addEventListener('abort', () => { if (!req.settled) { req.settled = true; reject(new DOMException('Synthetic abort', 'AbortError')); } }, { once: true });
    }),
    openHotelMergeModal: async row => { assert.equal(superAdmin, true); mergeCalls.push(clone(row)); }, getCurrentOperatorName: () => 'Synthetic operator',
    buildHotelOtaConfig: () => ({}), ensureHotelOtaConfigLists: async () => { sideBoundaryCalls.push('OTA read not executed'); },
    invalidateOnlineHistoryHotelList: () => {},
    loadHotelPmsBindingForModal: async id => { assert.ok(['80', '81'].includes(String(id))); sideBoundaryCalls.push('PMS read not executed'); },
    saveHotelPmsBinding: async id => { assert.equal(String(id), '80'); assert.equal(state.hotelForm.value.pms_provider, 'none'); sideBoundaryCalls.push('PMS write not executed; closed success boundary'); },
    isHotelCodeDuplicate: () => false, applyHotelAutomationLifecycle: () => { throw new Error('Lifecycle scope not allowed'); },
    loadHotelThreeSourceOnboarding: () => { throw new Error('Onboarding scope not allowed'); }, applyCtripHotelConfig: () => { throw new Error('OTA scope not allowed'); },
    loadHotelAutomationLifecycles: async () => { sideBoundaryCalls.push('Lifecycle read not executed'); },
    readRequestCache: () => null, writeRequestCache: () => {}, hotelListRequestSeq: 0, hotelListPendingCount: 0, hotelListSnapshotScope: '',
    hotelPmsBindingModalRequestSequence: 0,
  };
  vm.createContext(sandbox); vm.runInContext(bytes[files[1]].toString(), sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  for (const key of ['createHotelForm', 'normalizeHotelIdentityName', 'buildHotelSavePayload']) sandbox[key] = sandbox.appSystemStatic[key];
  const pmsMethods = realPms ? ',loadHotelPmsBindingForModal,handleHotelPmsProviderChange,saveHotelPmsBinding' : '';
  vm.runInContext(requestSource + '\n' + permissionSource + '\n' + resetSource + '\n' + panelSource + '\n' + (realPms ? 'const apiRequest = request;\n' + pmsSource : '') + '\n' + businessSource + '\nglobalThis.originalMethods={canManageOwnHotels,openHotelModal,saveHotel,loadHotels,hotelBusinessProfileEditor' + pmsMethods + '};globalThis.originalPanel=markRaw(HotelThreeSourceOnboardingPanel);', sandbox);
  const context = { ...state, ...sandbox.originalMethods, openHotelMergeModal: sandbox.openHotelMergeModal, user: sandbox.user,
    hotelFormChannelSelected: platform => sandbox.appSystemStatic.selectedHotelOtaPlatforms(state.hotelForm.value.ota_channel_strategy).includes(platform),
    toggleHotelFormChannel: () => { throw new Error('Platform editing is outside this test'); },
    handleHotelPmsProviderChange: realPms ? sandbox.originalMethods.handleHotelPmsProviderChange : () => { throw new Error('PMS editing is outside this test'); },
    hotelAccountSummary: () => ({ statusText: 'Synthetic reference only' }), hotelFormAccountHotel: () => state.hotelForm.value,
    hotelApplicablePlatformBindingRows: () => [], hotelThreeSourceOnboardingPanel: sandbox.originalPanel,
  };
  let vnode;
  const html = () => renderToString(Vue.createSSRApp({ setup: () => context, render() { vnode = render.call(this, this, []); return vnode; } }));
  const flatten = (node, rows = []) => { if (Array.isArray(node)) node.forEach(child => flatten(child, rows)); else if (node && typeof node === 'object') { rows.push(node); flatten(node.children, rows); if (node.component?.subTree) flatten(node.component.subTree, rows); } return rows; };
  const text = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(text).join('') : node?.children ? text(node.children) : '';
  const nodes = () => flatten(vnode);
  const disabled = target => {
    let result = false;
    const visit = (node, inherited = false) => {
      if (Array.isArray(node)) { node.forEach(child => visit(child, inherited)); return; }
      if (!node || typeof node !== 'object') return;
      const own = node.props?.disabled === true || node.props?.disabled === '';
      if (node === target) result = own || inherited;
      visit(node.children, inherited || (node.type === 'fieldset' && own));
      if (node.component?.subTree) visit(node.component.subTree, inherited || (node.type === 'fieldset' && own));
    };
    visit(vnode); return result;
  };
  const button = label => nodes().find(node => node.type === 'button' && text(node).trim() === label);
  const open = async (index = 0) => { await html(); eq(state.showHotelModal.value, false); const entry = nodes().filter(node => node.type === 'button' && text(node).trim() === '编辑')[index]; ok(entry && !disabled(entry)); await entry.props.onClick(); await tick(); await html(); eq(state.showHotelModal.value, true); };
  const openNew = async () => { await html(); eq(state.showHotelModal.value, false); const entry = button('新增门店'); ok(entry && !disabled(entry)); await entry.props.onClick(); await tick(); await html(); eq(state.hotelOnboardingActive.value, true); eq(state.hotelOnboardingStep.value, 'hotel'); };
  const editProfile = async value => { await html(); const input = nodes().find(node => node.type === 'input' && node.props?.['aria-label'] === '酒店定位'); ok(input && !disabled(input)); input.props.onInput({ target: { value } }); await tick(); };
  const editNewName = async value => { await html(); const input = nodes().find(node => node.type === 'input' && node.props?.placeholder === '请输入门店名称'); ok(input && !disabled(input)); input.props.onInput({ target: { value } }); await tick(); };
  const submitNew = async () => { await html(); const control = nodes().find(node => node.type === 'button' && node.props?.['data-testid'] === 'hotel-onboarding-create'); ok(control && !disabled(control)); const pending = control.props.onClick(); await tick(); return { pending, req: requests.findLast(req => !req.settled && req.options.method === 'POST') }; };
  const editName = async value => { await html(); const input = nodes().find(node => node.type === 'input' && node.props?.['onUpdate:modelValue']); ok(input && !disabled(input) && !input.props.readonly, 'Actual hotel-name input enabled'); input.props['onUpdate:modelValue'](value); await tick(); };
  const submit = async () => { await html(); const control = nodes().find(node => node.type === 'button' && node.props?.type === 'submit'); ok(control && !disabled(control), 'Actual submit button enabled'); const pending = nodes().find(node => node.type === 'form').props.onSubmit({ preventDefault() {} }); await tick(); return { pending, req: requests.findLast(req => !req.settled && req.options.method === 'PUT') }; };
  const reply = (req, response, status = 200) => { assert.ok(req && !req.settled); req.settled = true; req.resolve(new Response(JSON.stringify(response), { status, headers: { 'Content-Type': 'application/json' } })); };
  const commitAndRead = async submitted => {
    const payload = JSON.parse(submitted.req.options.body); eq(payload.status, 1, 'No status change'); eq(payload.ota_channel_strategy, 'none', 'No platform change');
    const saved = { ...clone(hotel), ...payload }; reply(submitted.req, { code: 200, message: '更新成功', data: saved }); await tick();
    const read = requests.findLast(req => !req.settled && new URL(req.url).pathname === (superAdmin ? '/api/hotels' : '/api/hotels/all')); ok(read, 'Original loadHotels sends exact list reread');
    const savedRows = initialHotels.map(row => row.id === 80 ? saved : clone(row));
    reply(read, { code: 200, message: '操作成功', data: superAdmin ? { list: savedRows, pagination: { total: savedRows.length, page: 1, page_size: 100, total_page: 1 } } : savedRows });
    await submitted.pending; await tick(); return saved;
  };
  return { state, mergeCalls, initialHotels, superAdmin, openNew, editNewName, editProfile, submitNew, methods: sandbox.originalMethods, notices, requests, sideBoundaryCalls, html, nodes, disabled, button, open, editName, submit, reply, commitAndRead };
}

async function failSave(f, sent) {
  if (!sent.req.settled) f.reply(sent.req, { code: 500, message: 'Synthetic basic update failure', data: null }, 500);
  await sent.pending; await tick();
}
async function duringSave(check, options = {}) {
  const f = fixture(options); await f.open(); await f.editName('Synthetic submitted A'); const sent = await f.submit(); assert.ok(sent.req);
  try { await check(f, sent); } finally { if (!sent.req.settled) await failSave(f, sent); }
}
const nameControl = f => f.nodes().find(node => node.type === 'input' && node.props?.placeholder === '请输入门店名称');
const closeControl = f => f.nodes().find(node => node.type === 'button' && node.children?.some?.(child => child?.props?.class === 'fas fa-times'));
const overlayControl = f => f.nodes().find(node => node.type === 'div' && String(node.props?.class || '').includes('modal-overlay'));
const clickOverlay = f => { const overlay = overlayControl(f); assert.ok(overlay); const self = {}; return overlay.props.onClick({ target: self, currentTarget: self }); };

test('normal original edit and save refreshes the submitted hotel name and can reopen after completion', async () => {
  await duringSave(async (f, sent) => {
    await f.html(); assert.equal(f.disabled(f.button('保存中')), true);
    assert.equal(f.requests.filter(req => req.options.method === 'PUT').length, 1, 'No second click is attempted on the disabled submit control');
    await f.commitAndRead(sent);
    assert.equal(f.state.showHotelModal.value, false);
    assert.equal(f.state.hotels.value[0].name, 'Synthetic submitted A');
    assert.ok((await f.html()).includes('Synthetic submitted A'));
    await f.open(); assert.equal(f.state.hotelForm.value.name, 'Synthetic submitted A');
    assert.equal(f.disabled(nameControl(f)), false);
  });
});

test('name input cannot accept an in-flight newer draft that the success path would otherwise hide', async () => {
  await duringSave(async (f, sent) => {
    await f.html(); assert.equal(f.disabled(nameControl(f)), true);
    // A disabled native control is not invoked programmatically as a fake user edit.
    assert.equal(f.state.hotelForm.value.name, 'Synthetic submitted A');
    assert.equal(JSON.parse(sent.req.options.body).name, 'Synthetic submitted A');
    await f.commitAndRead(sent); await f.open();
    assert.equal(f.state.hotelForm.value.name, 'Synthetic submitted A');
  });
});

test('the whole original basic form inherits disabled including dynamic profile inputs and textarea', async () => {
  await duringSave(async (f) => {
    const html = await f.html(), tree = astWalk(parse(html));
    const fieldsets = tree.filter(node => node.type === 1 && node.tag === 'fieldset');
    assert.equal(fieldsets.length, 1);
    assert.ok(fieldsets[0].props.some(prop => prop.name === 'disabled'));
    const classes = fieldsets[0].props.find(prop => prop.name === 'class')?.value?.content || '';
    for (const className of ['m-0', 'p-0', 'border-0', 'min-w-0']) assert.ok(classes.split(/\s+/).includes(className), 'Preserve original layout: ' + className);
    const controls = [];
    const visit = (node, inherited = false) => {
      const ownDisabled = node.type === 1 && node.props.some(prop => prop.name === 'disabled');
      if (node.type === 1 && ['button', 'input', 'select', 'textarea'].includes(node.tag)) controls.push({ node, disabled: inherited || ownDisabled });
      for (const child of node.children || []) visit(child, inherited || (node.type === 1 && node.tag === 'fieldset' && ownDisabled));
    };
    visit(parse(html));
    assert.deepEqual([...new Set(controls.map(row => row.node.tag))].sort(), ['button', 'input', 'select', 'textarea']);
    assert.ok(controls.every(row => row.disabled), 'Every currently rendered modal native control is disabled, including header');
    assert.ok(controls.some(row => row.node.tag === 'textarea' && row.node.props.some(prop => prop.name === 'aria-label' && prop.value?.content === '运营偏好')), 'Original child component was actually SSR-rendered');
  });
});

test('header close is disabled in flight and is usable again after failure', async () => {
  await duringSave(async (f, sent) => {
    await f.html(); const close = closeControl(f); assert.ok(close); assert.equal(f.disabled(close), true);
    await failSave(f, sent); await f.html(); const unlocked = closeControl(f); assert.equal(f.disabled(unlocked), false);
    unlocked.props.onClick(); assert.equal(f.state.showHotelModal.value, false);
  });
});

test('overlay self-click cannot exit during save but can exit after the failed request completes', async () => {
  await duringSave(async (f, sent) => {
    await f.html(); clickOverlay(f); assert.equal(f.state.showHotelModal.value, true);
    await failSave(f, sent); await f.html(); clickOverlay(f); assert.equal(f.state.showHotelModal.value, false);
  });
});

test('footer cancellation is blocked during save and another real hotel edit works after failure and cancel', async () => {
  await duringSave(async (f, sent) => {
    await f.html(); assert.equal(f.disabled(f.button('取消')), true);
    await failSave(f, sent); await f.html(); const cancel = f.button('取消'); assert.equal(f.disabled(cancel), false);
    cancel.props.onClick(); assert.equal(f.state.showHotelModal.value, false);
    await f.open(1); assert.equal(f.state.hotelForm.value.id, 81); assert.equal(f.state.hotelForm.value.name, 'Synthetic other hotel');
    assert.equal(f.requests.filter(req => req.options.method === 'PUT').length, 1);
  }, { secondHotel: true });
});

test('openHotelModal also defensively refuses internal replacement while a save owns the form', async () => {
  await duringSave(async (f) => {
    const originalForm = f.state.hotelForm.value;
    // Explicit internal guard test; the real Edit button behind the overlay is
    // not invoked or presented as a user-reachable in-flight action.
    await f.methods.openHotelModal(f.state.hotels.value[1]);
    assert.equal(f.state.hotelForm.value, originalForm); assert.equal(f.state.hotelForm.value.id, 80);
    assert.equal(f.state.hotelForm.value.name, 'Synthetic submitted A');
  }, { secondHotel: true });
});

test('HTTP failure preserves name and unlocks editing plus a real retry, without an automatic second request', async () => {
  await duringSave(async (f, sent) => {
    await failSave(f, sent); await f.html();
    assert.equal(f.state.showHotelModal.value, true); assert.equal(f.state.hotelSaving.value, false);
    assert.equal(f.state.hotelForm.value.name, 'Synthetic submitted A'); assert.equal(f.disabled(nameControl(f)), false);
    assert.deepEqual(f.notices.map(item => item.type), ['error']); assert.equal(f.requests.length, 1);
    await f.editName('Synthetic explicitly retried B'); const retry = await f.submit();
    assert.equal(JSON.parse(retry.req.options.body).name, 'Synthetic explicitly retried B');
    await f.commitAndRead(retry);
    assert.equal(f.state.hotels.value[0].name, 'Synthetic explicitly retried B');
    assert.deepEqual(f.notices.map(item => item.type), ['error', 'success']);
  });
});

// Internal role permissions are the real frontend projection, not a claim that a
// live account was authorized. Responses model Hotel::duplicateHotelNameResponse;
// all PMS/OTA/lifecycle work and the superadmin merge action stay closed stubs.
const duplicateRow = { id: 81, name: 'Synthetic other hotel', code: 'SYNTHETIC81', status: 1 };
async function replyDuplicate(f, sent) {
  assert.ok(sent.req);
  f.reply(sent.req, { code: 409, message: '酒店名称已存在，请先核对并合并', data: { duplicate_hotels: [duplicateRow] } }, 409);
  await tick();
  // Drain the original superadmin refresh (and the regression's unwanted non-super
  // refresh) so the red baseline measures UI behavior rather than a pending fixture.
  const read = f.requests.findLast(req => !req.settled && (req.options.method || 'GET') === 'GET');
  if (read) f.reply(read, { code: 200, message: '操作成功', data: f.superAdmin
    ? { list: f.initialHotels, pagination: { total: f.initialHotels.length, page: 1, page_size: 100, total_page: 1 } }
    : f.initialHotels });
  await sent.pending; await tick(); await f.html();
}

test('non-super manager duplicate edit preserves the visible name and profile, then explicit rename retries and reads the saved hotel', async () => {
  const f = fixture({ superAdmin: false, secondHotel: true });
  assert.equal(f.methods.canManageOwnHotels(), true);
  await f.open(); await f.editName(duplicateRow.name); await f.editProfile('Synthetic preserved positioning');
  const formBefore = f.state.hotelForm.value, profileBefore = f.state.hotelBackgroundProfileForm.value;
  const draft = clone(formBefore), profile = clone(profileBefore), rows = clone(f.state.hotels.value);
  const sent = await f.submit();
  assert.equal(JSON.parse(sent.req.options.body).name, duplicateRow.name);
  assert.ok(JSON.parse(sent.req.options.body).description.includes(profile.positioning));
  await replyDuplicate(f, sent);
  assert.equal(f.state.showHotelModal.value, true);
  assert.equal(f.state.hotelSaving.value, false);
  assert.equal(f.state.hotelForm.value, formBefore); assert.equal(f.state.hotelBackgroundProfileForm.value, profileBefore);
  assert.deepEqual(clone(f.state.hotelForm.value), draft); assert.deepEqual(clone(f.state.hotelBackgroundProfileForm.value), profile);
  assert.deepEqual(clone(f.state.hotels.value), rows); assert.equal(f.requests.length, 1); assert.deepEqual(f.mergeCalls, []);
  assert.equal(f.button('数据迁移'), undefined); assert.equal(f.disabled(nameControl(f)), false);
  assert.equal(f.disabled(f.button('保存')), false); assert.equal(f.disabled(f.button('取消')), false);
  assert.match(f.notices[0].message, /名称.*存在.*修改名称.*重试/); assert.match(f.notices[0].message, /联系超级管理员/);
  assert.deepEqual(f.notices.map(item => item.type), ['warning']);
  assert.ok(!f.sideBoundaryCalls.some(value => value.includes('PMS write')));
  await f.editName('Synthetic unique renamed hotel'); const retry = await f.submit();
  assert.equal(JSON.parse(retry.req.options.body).name, 'Synthetic unique renamed hotel');
  assert.ok(JSON.parse(retry.req.options.body).description.includes(profile.positioning));
  const saved = await f.commitAndRead(retry);
  assert.equal(f.state.showHotelModal.value, false); assert.equal(f.state.hotels.value[0].id, 80);
  assert.equal(f.state.hotels.value[0].name, saved.name); assert.equal(f.state.hotels.value[0].description, saved.description);
  await f.open(); assert.equal(f.state.hotelForm.value.name, saved.name);
  assert.equal(f.state.hotelBackgroundProfileForm.value.positioning, profile.positioning);
  assert.equal(f.requests.filter(req => req.options.method === 'PUT').length, 2);
  assert.deepEqual(f.notices.map(item => item.type), ['warning', 'success']);
});

test('non-super actual onboarding create keeps a duplicate draft in the hotel step and sends only the explicitly corrected POST', async () => {
  const f = fixture({ superAdmin: false, secondHotel: true });
  assert.equal(f.methods.canManageOwnHotels(), true); await f.openNew();
  assert.ok(f.nodes().some(node => node.props?.['data-testid'] === 'hotel-onboarding-hotel-step'));
  assert.ok(!f.nodes().some(node => node.type === 'form'), 'Actual new entry renders the onboarding branch, not a forced basic form');
  await f.editNewName(duplicateRow.name); await f.editProfile('Synthetic new hotel positioning');
  const formBefore = f.state.hotelForm.value, profileBefore = f.state.hotelBackgroundProfileForm.value;
  const draft = clone(formBefore), profile = clone(profileBefore); const sent = await f.submitNew();
  assert.equal(JSON.parse(sent.req.options.body).name, duplicateRow.name);
  assert.ok(JSON.parse(sent.req.options.body).description.includes(profile.positioning));
  await replyDuplicate(f, sent);
  assert.equal(f.state.showHotelModal.value, true); assert.equal(f.state.hotelSaving.value, false);
  assert.equal(f.state.hotelForm.value, formBefore); assert.equal(f.state.hotelBackgroundProfileForm.value, profileBefore);
  assert.deepEqual(clone(f.state.hotelForm.value), draft); assert.deepEqual(clone(f.state.hotelBackgroundProfileForm.value), profile);
  assert.equal(f.state.hotelOnboardingActive.value, true); assert.equal(f.state.hotelOnboardingStep.value, 'hotel');
  assert.equal(f.state.hotelOnboardingHotelId.value, ''); assert.equal(f.requests.length, 1); assert.deepEqual(f.mergeCalls, []);
  assert.equal(f.disabled(nameControl(f)), false); assert.deepEqual(f.sideBoundaryCalls, []);
  assert.match(f.notices[0].message, /联系超级管理员/); assert.deepEqual(f.notices.map(item => item.type), ['warning']);
  await f.editNewName('Synthetic corrected new hotel'); const retry = await f.submitNew();
  assert.equal(JSON.parse(retry.req.options.body).name, 'Synthetic corrected new hotel');
  assert.ok(JSON.parse(retry.req.options.body).description.includes(profile.positioning));
  // A second explicit POST is closed by an ordinary business rejection: no
  // creation success, PMS save, authorization, collection or onboarding advance.
  f.reply(retry.req, { code: 400, message: 'Synthetic basic create validation failure', data: null }, 400);
  await retry.pending; await tick(); await f.html();
  assert.equal(f.state.showHotelModal.value, true); assert.equal(f.state.hotelSaving.value, false);
  assert.equal(f.state.hotelForm.value.name, 'Synthetic corrected new hotel');
  assert.equal(f.state.hotelBackgroundProfileForm.value.positioning, profile.positioning);
  assert.equal(f.state.hotelOnboardingStep.value, 'hotel'); assert.equal(f.state.hotelOnboardingHotelId.value, '');
  assert.equal(f.requests.length, 2); assert.ok(f.requests.every(req => req.options.method === 'POST'));
  assert.deepEqual(f.sideBoundaryCalls, []); assert.deepEqual(f.notices.map(item => item.type), ['warning', 'error']);
});

test('superadmin duplicate keeps the existing close, refresh and merge-entry branch', async () => {
  const f = fixture({ secondHotel: true }); await f.html(); assert.ok(f.button('数据迁移'));
  await f.open(); await f.editName(duplicateRow.name); const sent = await f.submit(); await replyDuplicate(f, sent);
  assert.equal(f.state.showHotelModal.value, false); assert.equal(f.state.hotelSaving.value, false);
  assert.equal(f.requests.length, 2); assert.equal(f.requests[0].options.method, 'PUT');
  assert.equal(new URL(f.requests[1].url).pathname, '/api/hotels');
  assert.deepEqual(f.mergeCalls, [duplicateRow], 'Closed substitute records the original merge entry, not a real merge');
  assert.deepEqual(f.notices, [{ message: '发现同名酒店，请先核对并合并', type: 'warning' }]);
  assert.ok(!f.sideBoundaryCalls.some(value => value.includes('PMS write')));
});

test('actual onboarding hotel step locks all controls until basic POST failure, then keeps the submitted draft editable and later completion usable', async () => {
  const f = fixture({ superAdmin: false });
  await f.openNew();
  await f.editNewName('Synthetic onboarding submitted A');
  await f.editProfile('Synthetic onboarding positioning A');
  const sent = await f.submitNew();
  assert.ok(sent.req);
  try {
    await f.html();
    assert.equal(f.state.hotelSaving.value, true);
    assert.equal(f.state.showHotelModal.value, true);
    assert.equal(f.state.hotelOnboardingActive.value, true);
    assert.equal(f.state.hotelOnboardingStep.value, 'hotel');
    const step = f.nodes().find(node => node.props?.['data-testid'] === 'hotel-onboarding-hotel-step');
    const controls = f.nodes().filter(node => ['input', 'textarea', 'select', 'button'].includes(node.type));
    assert.deepEqual([...new Set(controls.map(node => node.type))].sort(), ['button', 'input', 'select', 'textarea']);
    assert.ok(controls.every(node => f.disabled(node)), 'Original dynamic profile, platform/PMS controls and later button inherit the save lock');
    assert.equal(f.disabled(f.button('稍后完成')), true);
    assert.equal(step.type, 'fieldset');
    for (const name of ['m-0', 'min-w-0', 'border-0', 'p-0', 'space-y-4']) {
      assert.ok(step.props.class.split(/\s+/).includes(name), 'Keep the original layout: ' + name);
    }
    // Disabled native controls are not invoked to manufacture an in-flight edit.
    assert.equal(f.state.hotelForm.value.name, 'Synthetic onboarding submitted A');
    assert.equal(f.state.hotelBackgroundProfileForm.value.positioning, 'Synthetic onboarding positioning A');
    const submittedBody = JSON.parse(sent.req.options.body);
    assert.equal(submittedBody.name, f.state.hotelForm.value.name);
    assert.ok(submittedBody.description.includes(f.state.hotelBackgroundProfileForm.value.positioning));
    assert.equal(f.requests.length, 1);
    f.reply(sent.req, { code: 500, message: 'Synthetic onboarding basic create failure', data: null }, 500);
    await sent.pending; await tick(); await f.html();
    assert.equal(f.state.hotelSaving.value, false);
    assert.equal(f.state.showHotelModal.value, true);
    assert.equal(f.state.hotelOnboardingStep.value, 'hotel');
    assert.equal(f.state.hotelForm.value.name, 'Synthetic onboarding submitted A');
    assert.equal(f.state.hotelBackgroundProfileForm.value.positioning, 'Synthetic onboarding positioning A');
    assert.ok(f.notices.some(notice => notice.type === 'error' && notice.message.includes('Synthetic onboarding basic create failure')));
    assert.ok(f.nodes().filter(node => ['input', 'textarea', 'select', 'button'].includes(node.type)).every(node => !f.disabled(node)));
    await f.editNewName('Synthetic onboarding corrected B');
    await f.editProfile('Synthetic onboarding corrected positioning B');
    const html = await f.html();
    assert.ok(html.includes('Synthetic onboarding corrected B') && html.includes('Synthetic onboarding corrected positioning B'));
    const later = f.button('稍后完成');
    assert.ok(later && !f.disabled(later));
    later.props.onClick(); await tick();
    assert.equal(f.state.showHotelModal.value, false);
    assert.equal(f.state.hotelSaving.value, false);
    assert.equal(f.requests.length, 1, 'No automatic retry or post-save read');
    assert.deepEqual(f.sideBoundaryCalls, []);
    assert.deepEqual(f.mergeCalls, []);
  } finally {
    if (!sent.req.settled) await failSave(f, sent);
  }
});

// Public metadata only: this models status/sourceSummary, not account access,
// collection readiness or server-side persistence. selected_source is an opaque
// projection of the unused integration status; original UI consumes sources.
function publicPmsStatus(provider = 'none', publicId = null, publicName = null, rowsExist = false) {
  const labels = { dingdandao_pms: '订单来了 PMS', meituan_cloud_pms: '美团云 PMS' };
  const selected = provider === 'none' ? null : provider;
  const sources = Object.fromEntries(Object.entries(labels).map(([key, label]) => [key, {
    provider: key, provider_label: label, configured: rowsExist || key === selected, enabled: key === selected,
    provider_hotel_id: key === selected ? publicId : null,
    provider_hotel_name: key === selected ? publicName : null,
    updated_at: rowsExist || key === selected ? '2026-09-15 09:00:00' : null,
  }]));
  return {
    binding_status: selected ? 'configured' : 'unconfigured',
    binding_status_label: selected ? '已配置唯一 PMS' : '尚未配置 PMS',
    selected_provider: selected, selected_provider_label: selected ? labels[selected] : null,
    sources,
    selected_source: selected ? { provider: selected, provider_label: labels[selected], config: {
      configured: true, status: true, provider_hotel_id: publicId, provider_hotel_name: publicName,
      updated_at: '2026-09-15 09:00:00',
    } } : null,
    blockers: selected ? [] : [{ code: 'hotel_pms_unconfigured', message: '当前门店尚未配置使用的 PMS，请在门店管理中选择。' }],
  };
}
const pendingPms = (f, method = 'GET') => f.requests.findLast(req => !req.settled
  && new URL(req.url).pathname === '/api/hotels/80/pms-binding' && (req.options.method || 'GET') === method);
const pmsIdentityInput = (f, kind) => f.nodes().find(node => node.type === 'input'
  && node.props?.['data-testid'] === 'hotel-pms-provider-hotel-' + kind);
const pmsProviderControl = f => f.nodes().find(node => node.type === 'select'
  && node.props?.['data-testid'] === 'hotel-pms-provider');
async function readPublicPms(f, data) {
  const read = pendingPms(f); assert.ok(read, 'Original same-hotel public metadata GET');
  f.reply(read, { code: 200, message: '操作成功', data }); await tick(); await f.html();
  assert.equal(f.state.hotelPmsBindingLoading.value, false);
}
async function choosePublicPms(f, provider) {
  await f.html(); const control = pmsProviderControl(f);
  assert.ok(control && !f.disabled(control));
  control.props['onUpdate:modelValue'](provider); await tick();
  control.props.onChange({ target: { value: provider } }); await tick(); await f.html();
}
async function fillPublicPms(f, publicId, publicName) {
  await f.html();
  for (const [kind, value, label] of [['id', publicId, 'PMS 公开门店 ID'], ['name', publicName, 'PMS 公开门店名称']]) {
    const input = pmsIdentityInput(f, kind);
    assert.ok(input && !f.disabled(input), 'Actual editable public PMS ' + kind + ' input');
    assert.equal(input.props['aria-label'], label);
    input.props['onUpdate:modelValue'](value); await tick(); await f.html();
  }
}
async function replyPublicHotelList(f, saved) {
  const read = f.requests.findLast(req => !req.settled && new URL(req.url).pathname === '/api/hotels/all');
  assert.ok(read, 'Original authorized hotel list reread');
  f.reply(read, { code: 200, message: '操作成功', data: f.initialHotels.map(row => row.id === saved.id ? saved : clone(row)) });
  await tick();
}
async function advanceToPublicPmsSave(f, submitted, expectedPublicBody) {
  assert.ok(submitted.req); assert.equal(new URL(submitted.req.url).pathname, '/api/hotels/80');
  const hotelPayload = JSON.parse(submitted.req.options.body);
  assert.equal(hotelPayload.pms_provider_hotel_id, undefined);
  assert.equal(hotelPayload.pms_provider_hotel_name, undefined);
  const saved = { ...clone(hotel), ...hotelPayload };
  f.reply(submitted.req, { code: 200, message: '更新成功', data: saved }); await tick();
  const metadata = pendingPms(f, 'PUT'); assert.ok(metadata, 'Original public metadata PUT follows the basic hotel PUT');
  assert.deepEqual(JSON.parse(metadata.options.body), expectedPublicBody, 'Only the three existing public fields, preserving string ID');
  return { saved, metadata };
}
async function finishPublicPmsSave(f, submitted, expectedBody, responseData) {
  const { saved, metadata } = await advanceToPublicPmsSave(f, submitted, expectedBody);
  f.reply(metadata, { code: 200, message: '门店 PMS 配置已保存并回读', data: responseData }); await tick();
  await replyPublicHotelList(f, saved); await submitted.pending; await tick(); await f.html();
  assert.equal(f.state.showHotelModal.value, false); assert.equal(f.state.hotelSaving.value, false);
  assert.equal(f.state.hotels.value.find(row => row.id === 80).name, saved.name);
  assert.ok(f.notices.some(row => row.type === 'success'));
  return saved;
}

for (const provider of ['dingdandao_pms', 'meituan_cloud_pms']) {
  test('public PMS edit supports unconfigured ' + provider + ' through original metadata save and explicit same-hotel reopen', async () => {
    const f = fixture({ realPms: true, superAdmin: false, secondHotel: true });
    await f.open(); await readPublicPms(f, publicPmsStatus());
    assert.equal(pmsIdentityInput(f, 'id'), undefined);
    await choosePublicPms(f, provider);
    await fillPublicPms(f, '00080-PUBLIC', 'Synthetic public PMS hotel A');
    await f.editName('Synthetic local hotel updated'); await f.editProfile('Synthetic saved positioning');
    const sent = await f.submit(); await f.html();
    assert.equal(f.disabled(pmsIdentityInput(f, 'id')), true);
    assert.equal(f.disabled(pmsIdentityInput(f, 'name')), true);
    const returned = publicPmsStatus(provider, '00080-PUBLIC', 'Synthetic public PMS hotel A', true);
    await finishPublicPmsSave(f, sent, { provider, provider_hotel_id: '00080-PUBLIC', provider_hotel_name: 'Synthetic public PMS hotel A' }, returned);
    await f.open(); await readPublicPms(f, returned);
    assert.equal(f.state.hotelForm.value.id, 80); assert.equal(f.state.hotelForm.value.pms_provider, provider);
    assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '00080-PUBLIC');
    assert.equal(f.state.hotelForm.value.pms_provider_hotel_name, 'Synthetic public PMS hotel A');
    const html = await f.html(); assert.ok(html.includes('00080-PUBLIC') && html.includes('Synthetic public PMS hotel A'));
    assert.equal(f.state.hotelBackgroundProfileForm.value.positioning, 'Synthetic saved positioning');
    assert.deepEqual(f.requests.map(req => [req.options.method || 'GET', new URL(req.url).pathname]), [
      ['GET', '/api/hotels/80/pms-binding'], ['PUT', '/api/hotels/80'], ['PUT', '/api/hotels/80/pms-binding'],
      ['GET', '/api/hotels/all'], ['GET', '/api/hotels/80/pms-binding'],
    ]);
    assert.ok(!f.sideBoundaryCalls.some(value => value.includes('PMS write')));
  });
}

test('public PMS metadata rejection preserves editable identity and a later explicit correction saves through the same public endpoints', async () => {
  const f = fixture({ realPms: true, superAdmin: false }); await f.open();
  await readPublicPms(f, publicPmsStatus()); await choosePublicPms(f, 'dingdandao_pms');
  await fillPublicPms(f, '00080-A', 'Synthetic rejected public name');
  const first = await f.submit();
  const { saved, metadata } = await advanceToPublicPmsSave(f, first, { provider: 'dingdandao_pms', provider_hotel_id: '00080-A', provider_hotel_name: 'Synthetic rejected public name' });
  f.reply(metadata, { code: 422, message: 'Synthetic public metadata validation rejection', data: null }, 422); await tick();
  await replyPublicHotelList(f, saved); await first.pending; await tick(); await f.html();
  assert.equal(f.state.showHotelModal.value, true); assert.equal(f.state.hotelSaving.value, false);
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '00080-A');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_name, 'Synthetic rejected public name');
  assert.equal(f.disabled(pmsIdentityInput(f, 'id')), false); assert.equal(f.disabled(pmsIdentityInput(f, 'name')), false);
  assert.equal(f.notices.at(-1).type, 'warning');
  assert.match(f.notices.at(-1).message, /门店资料已保存.*Synthetic public metadata validation rejection/);
  assert.equal(f.requests.filter(req => req.options.method === 'PUT').length, 2, 'No automatic re-save after the failure');
  await fillPublicPms(f, '00080-B', 'Synthetic corrected public name');
  const second = await f.submit(); const returned = publicPmsStatus('dingdandao_pms', '00080-B', 'Synthetic corrected public name', true);
  await finishPublicPmsSave(f, second, { provider: 'dingdandao_pms', provider_hotel_id: '00080-B', provider_hotel_name: 'Synthetic corrected public name' }, returned);
  await f.open(); await readPublicPms(f, returned);
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '00080-B');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_name, 'Synthetic corrected public name');
  assert.deepEqual(f.notices.map(row => row.type), ['warning', 'success']);
});

test('public PMS loading and read failure prevent editing until the actual retry loads existing identity', async () => {
  const f = fixture({ realPms: true, superAdmin: false }); await f.open();
  assert.equal(f.state.hotelPmsBindingLoading.value, true);
  assert.equal(f.disabled(pmsProviderControl(f)), true);
  assert.equal(pmsIdentityInput(f, 'id'), undefined, 'No identity form for the initial none value');
  f.reply(pendingPms(f), { code: 500, message: 'Synthetic public metadata read failure', data: null }, 500); await tick(); await f.html();
  assert.equal(f.state.hotelPmsBindingLoading.value, false);
  assert.equal(f.disabled(pmsProviderControl(f)), true);
  assert.ok((await f.html()).includes('Synthetic public metadata read failure'));
  const retry = f.button('重试'); assert.ok(retry && !f.disabled(retry));
  const pending = retry.props.onClick(); await tick(); await f.html();
  assert.equal(f.disabled(pmsProviderControl(f)), true);
  await readPublicPms(f, publicPmsStatus('meituan_cloud_pms', '00080-LEGACY', 'Synthetic existing provider hotel'));
  await pending; await f.html();
  assert.equal(f.state.hotelPmsBindingError.value, ''); assert.equal(f.disabled(pmsProviderControl(f)), false);
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '00080-LEGACY');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_name, 'Synthetic existing provider hotel');
  await fillPublicPms(f, '00080-EDITED', 'Synthetic edited existing public hotel');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '00080-EDITED');
  assert.equal(f.requests.length, 2); assert.ok(f.requests.every(req => !req.options.method || req.options.method === 'GET'));
});

test('public PMS legacy missing ID stays fillable while none and conflict keep their original identity requirements', async () => {
  const f = fixture({ realPms: true, superAdmin: false }); await f.open();
  const legacy = publicPmsStatus('dingdandao_pms', null, 'Synthetic legacy name only');
  await readPublicPms(f, legacy);
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_name, 'Synthetic legacy name only');
  const stopped = await f.submit(); await stopped.pending;
  assert.equal(stopped.req, undefined); assert.equal(f.requests.length, 1);
  await fillPublicPms(f, '00080-CORRECTED', 'Synthetic corrected legacy name');
  await choosePublicPms(f, 'none');
  assert.equal(pmsIdentityInput(f, 'id'), undefined); assert.equal(pmsIdentityInput(f, 'name'), undefined);
  const none = await f.submit();
  const disabledStatus = clone(legacy);
  Object.assign(disabledStatus, { binding_status: 'unconfigured', binding_status_label: '尚未配置 PMS', selected_provider: null, selected_provider_label: null, selected_source: null, blockers: publicPmsStatus().blockers });
  for (const row of Object.values(disabledStatus.sources)) { row.configured = true; row.enabled = false; row.updated_at = '2026-09-15 09:00:00'; }
  await finishPublicPmsSave(f, none, { provider: 'none', provider_hotel_id: '', provider_hotel_name: '' }, disabledStatus);
  await f.open();
  const conflict = publicPmsStatus('dingdandao_pms', '00080-DD', 'Synthetic existing DD hotel');
  conflict.sources.meituan_cloud_pms = publicPmsStatus('meituan_cloud_pms', '00080-MT', 'Synthetic existing MT hotel').sources.meituan_cloud_pms;
  Object.assign(conflict, { binding_status: 'conflict', binding_status_label: '配置冲突', selected_provider: null, selected_provider_label: null, selected_source: null, blockers: [{ code: 'hotel_pms_multiple_sources_enabled', message: '历史配置中有两套 PMS 同时启用，请在门店管理中明确保留一个。' }] });
  await readPublicPms(f, conflict);
  assert.equal(f.state.hotelForm.value.pms_provider, 'conflict'); assert.equal(pmsIdentityInput(f, 'id'), undefined);
  await choosePublicPms(f, 'meituan_cloud_pms');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '00080-MT');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_name, 'Synthetic existing MT hotel');
  assert.ok(pmsIdentityInput(f, 'id') && pmsIdentityInput(f, 'name'));
});
