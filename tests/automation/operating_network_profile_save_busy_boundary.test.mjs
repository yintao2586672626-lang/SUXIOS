import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Source roots are optional pre-integration red/green overrides. Defaults use
// the checked-out product. No browser, DB, real fetch or credential is used.
const sourceRoot = process.env.SUXI_PROFILE_SOURCE_ROOT || '.';
const templateRoot = process.env.SUXI_PROFILE_TEMPLATE_ROOT || sourceRoot;
const read = file => fs.readFileSync(path.resolve(file.endsWith('20-page-knowledge-center.html') ? templateRoot : sourceRoot, file), 'utf8');
const main = read('public/app-main.js').replaceAll('\r\n', '\n');
const rawDomain = read('public/components/system/knowledge-center-domain.js');
const rawTemplate = read('resources/frontend/templates/fragments/20-page-knowledge-center.html');
const rawShell = read('resources/frontend/templates/fragments/00-app-shell.html');
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const clone = value => JSON.parse(JSON.stringify(value));
let assertions = 0;
const eq = (actual, expected, label) => { assertions++; assert.deepEqual(actual, expected, label); };
const ok = (actual, label) => { assertions++; assert.ok(actual, label); };
const cut = (source, start, end) => { const a = source.indexOf(start), b = source.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start); return source.slice(a, b); };
const declaration = name => { const a = main.indexOf('            const ' + name + ' ='); assert.ok(a >= 0, name); const match = /\n            (?:const|let) /.exec(main.slice(a + 1)); assert.ok(match, name); return main.slice(a, a + 1 + match.index); };
const parts = {
  context: cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  abort: cut(main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  policy: cut(main, '            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  coordinator: cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  request: cut(main, '            const request = async (', '            const apiRequest = request;'),
  navigation: cut(main, '            const cloneMenuItem =', '            // 可见菜单项'),
  refs: cut(main, '            const operatingNetworkProfileDimensions =', '            const operatingNetworkReviewForm ='),
};
const navNames = ['normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess', 'getMenuItemName', 'stableHashSegmentForTestId', 'normalizeTestIdSegmentInline', 'menuTestId', 'toggleSubmenu', 'isSidebarMenuItemActive', 'handleParentMenuClick', 'handleMenuClick', 'handleNestedMenuClick'];
parts.navHandlers = navNames.map(declaration).join('\n');
parts.delegate = declaration('callKnowledgeCenterDomain') + '\n' + ['saveOperatingNetworkProfile', 'changeOperatingNetworkHotel', 'loadOperatingNetwork', 'generateOperatingNetworkProfilePreview', 'applyOperatingNetworkProfilePreview'].map(declaration).join('\n');

const ast = parse(rawTemplate), selected = new Set(); let editor, profileCard, workbench, editorAncestors;
const find = (node, parents = []) => {
  const testid = node.props?.find(prop => prop.name === 'data-testid')?.value?.content;
  if (testid === 'operating-network-workbench') workbench = node;
  if (testid === 'operating-network-profile-editor') { editor = node; profileCard = parents.at(-1); editorAncestors = parents; }
  for (const child of node.children || []) find(child, [...parents, node]);
};
find(ast); assert.ok(editor && profileCard && workbench);
selected.add(profileCard);
for (const child of workbench.children.filter(node => node.type === 1)) {
  if (child.loc.source.includes('data-testid="operating-network-hotel"') && child.tag !== 'template') selected.add(child);
  if (child.props.some(prop => prop.name === 'if' && prop.exp?.content === '!operatingNetworkHotelId')) selected.add(child);
  if (child.props.some(prop => prop.name === 'data-testid' && prop.value?.content === 'operating-network-error')) selected.add(child);
}
const retain = node => {
  if (selected.has(node)) return node.loc.source;
  const children = (node.children || []).map(retain).join('');
  if (!children || node.type === 0) return children;
  return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
};
const markup = retain(ast);
const renderEditor = new Function('Vue', compile(markup, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const navMarkup = cut(rawShell, '                <nav ', '                </nav>') + '                </nav>';
const renderNav = new Function('Vue', compile(navMarkup, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const modelNames = [];
const scan = node => { if (node.type === 1) { const model = node.props.find(prop => prop.name === 'model')?.exp?.content; if (model) modelNames.push({ tag: node.tag, model, directDisabled: node.props.find(prop => prop.name === 'bind' && prop.arg?.content === 'disabled')?.exp?.content || null }); } for (const child of node.children || []) scan(child); };
scan(editor);
assert.ok(!editorAncestors.some(node => node.tag === 'fieldset' || node.props?.some(prop => prop.name === 'inert')));
test('profile editor locks native inputs and preview apply until exact save readback, then recovers', async t => {
const requests = [], notices = [], inFlight = [];
const boundaries = { contract_version: 'controlled_operating_network.v1', status_is_draft: true, human_target_validation_required: true, automatic_execution: false, ota_write: false, external_message: false, causality_claimed: false };
const overview = profile => ({ data_status: 'ok', profile, onboarding: { current_stage: 'room_rate_mapping', stages: [] }, comparable_hotels: [], verified_sops: [], replications: { data_status: 'ok', items: [] }, network_asset_summary: { field_validated: false, field_evidence_status: 'none' }, hotel_options: [{ id: 7, name: 'Synthetic Hotel A' }], data_gaps: profile ? [{ code: 'profile_unverified', message: 'Synthetic manual profile remains unverified.' }] : [{ code: 'hotel_operating_profile_missing', message: 'Synthetic profile absent.' }], boundaries: clone(boundaries) });
const sandbox = {
  window: { innerWidth: 1280 }, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
  ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, Date, setTimeout, clearTimeout,
  console: { warn() {}, error() {} }, API_BASE: 'https://synthetic.invalid/api',
  currentPage: Vue.ref('compass'), user: Vue.ref({ id: 901, realname: 'Synthetic operator', is_super_admin: true, capabilities: ['all'] }),
  authSessionEpoch: 1, pageRequestGeneration: 1, filterReportHotel: Vue.ref('7'),
  authContext: Vue.ref({ hotelId: '7', tenantId: '70', platform: 'all', permissionStatus: 'allowed' }),
  permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 80 }]), token: Vue.ref(''),
  revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'), operationYesterday: '2026-09-14',
  captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: '' }), isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
  isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
  expandedMenus: Vue.ref([]), sidebarCollapsed: false, agentTab: Vue.ref('overview'), revenueAgentTab: Vue.ref('analysis'), onlineDataTab: Vue.ref('data-health'), pendingOnlineDataEntryTab: '',
  aiModelConfigText: key => key, showToast: (message, type = 'success') => notices.push({ message, type }),
  knowledgeCenterDomainRevision: Vue.ref(0),
  fetch: (url, options) => new Promise((resolve, reject) => {
    const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid');
    const method = options.method || 'GET';
    assert.ok(method === 'POST' && parsed.pathname === '/api/operation/operating-profiles' || method === 'GET' && ['/api/operation/operating-network', '/api/operation/operating-profiles/preview'].includes(parsed.pathname));
    const call = { url, method, body: options.body ? JSON.parse(options.body) : null, resolve, reject, settled: false, response: null, aborted: false };
    requests.push(call);
    const abort = () => { if (!call.settled) { call.settled = true; call.aborted = true; reject(new DOMException('Original request aborted', 'AbortError')); } };
    call.removeAbort = () => options.signal?.removeEventListener('abort', abort);
    if (options.signal?.aborted) abort(); else options.signal?.addEventListener('abort', abort, { once: true });
  }),
};
vm.createContext(sandbox);
vm.runInContext(read('public/system-static.js') + '\n' + rawDomain, sandbox);
sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name];
sandbox.testIdNameMap = sandbox.appSystemStatic.testIdNameMap;
const refNames = [...parts.refs.matchAll(/const (\w+) =/g)].map(match => match[1]);
vm.runInContext(Object.values(parts).filter(part => part !== parts.delegate).join('\n') + '\nglobalThis.initial = { request, buildLeanNavigationItems, ' + refNames.join(',') + ', ' + navNames.join(',') + ' };', sandbox);
const initial = sandbox.initial;
const domainContext = { ...sandbox, ...initial, computed: Vue.computed, request: initial.request, showToast: sandbox.showToast, requireSystemStatic: name => sandbox.appSystemStatic[name] };
const domain = sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create(domainContext);
sandbox.createKnowledgeCenterDomain = () => domain;
vm.runInContext(parts.delegate + '\nglobalThis.delegates = { saveOperatingNetworkProfile, changeOperatingNetworkHotel, loadOperatingNetwork, generateOperatingNetworkProfilePreview, applyOperatingNetworkProfilePreview };', sandbox);
const visibleMenus = initial.buildLeanNavigationItems(sandbox.appSystemStatic.filterVisibleMenuItems(sandbox.appSystemStatic.resolveMenuItems(sandbox.appSystemStatic.menuItemDefinitions, {}), sandbox.user.value));
const state = Vue.proxyRefs({ ...sandbox, ...initial, ...sandbox.delegates, visibleMenuItems: visibleMenus, knowledgeCenterHotelOptions: [{ id: 7, name: 'Synthetic Hotel A' }, { id: 8, name: 'Synthetic Hotel B' }] });
let detailsOpened = false;
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const nodeText = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(nodeText).join('') : node?.children ? nodeText(node.children) : '';
const inspect = async (navigation = false) => {
  let tree;
  const app = Vue.createSSRApp({ render() { tree = (navigation ? renderNav : renderEditor)(state, []); return tree; } });
  const html = await renderToString(app), entries = [];
  const walk = (node, parents = []) => { if (Array.isArray(node)) node.forEach(child => walk(child, parents)); else if (node && typeof node === 'object') { entries.push({ node, parents }); walk(node.children, [...parents,node]); } };
  walk(tree); return { html, entries, nodes: entries.map(entry => entry.node) };
};
const disabled = (view, node) => !!node.props?.disabled || view.entries.find(entry => entry.node === node).parents.some(parent => parent.props?.inert || parent.type === 'fieldset' && parent.props?.disabled);
const visible = (view, node) => !view.entries.find(entry => entry.node === node).parents.some(parent => parent.props?.style?.display === 'none' || parent.props?.inert || parent.type === 'details' && !detailsOpened) && node.props?.style?.display !== 'none';
const reply = (call, body, status = 200) => { assert.ok(call && !call.settled); call.settled = true; call.removeAbort(); call.response = clone(body); call.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
const nativeInput = async (model, value, blocked = false) => {
  const view = await inspect();
  const node = view.nodes.find(node => node.dirs?.some(dir => String(dir.value) === String(state.operatingNetworkProfileForm[model])) && node.props?.['onUpdate:modelValue']?.toString().includes('operatingNetworkProfileForm.' + model));
  ok(node && ['input', 'textarea'].includes(node.type), 'Original model control: ' + model);
  ok(visible(view,node), 'Original model is visible: ' + model);
  if (blocked) { eq(disabled(view,node), true, 'Native disabled blocks input: ' + model); return false; }
  eq(disabled(view,node), false, 'Original model is operable: ' + model);
  const listeners = {}, el = { type: node.type === 'textarea' ? 'textarea' : node.props.type || 'text', value, composing: false, addEventListener: (event, callback) => { listeners[event] = callback; } };
  Vue.vModelText.created(el, { modifiers: {} }, node); listeners.input({ target: el }); await tick(); eq(state.operatingNetworkProfileForm[model], value);
};
const setDraft = async label => { await nativeInput('effective_date', '2026-09-15'); await nativeInput('source_method', 'synthetic_manual_' + label); await nativeInput('notes', 'Synthetic unsaved notes ' + label); };
const beginSave = async () => { const view = await inspect(), button = view.nodes.find(node => node.props?.['data-testid'] === 'operating-network-save-profile'); ok(button && visible(view,button) && !disabled(view,button)); const pending = button.props.onClick(); inFlight.push(pending); await tick(); const post = requests.findLast(call => call.method === 'POST' && !call.settled); ok(post); eq(post.body.hotel_id, 7); eq(post.body.quality_status, 'unverified'); eq(post.body.onboarding.room_rate_mapping.status, 'missing'); eq(post.body.onboarding.metric_definition.status, 'missing'); eq(post.body.notes, 'Synthetic unsaved notes A'); return { pending, post }; };
const fieldsFor = view => view.entries.filter(({node,parents}) => ['select','input','textarea'].includes(node.type) && parents.some(parent => parent.type === 'details'));
const previewApply = async blocked => {
  const view = await inspect(), button = view.nodes.find(node => node.props?.['data-testid'] === 'operating-network-apply-profile-preview');
  ok(button && visible(view,button), 'Original preview apply button is present');
  if (blocked) { eq(disabled(view,button), true, 'Native disabled blocks preview replacement'); return false; }
  eq(disabled(view,button), false, 'Preview apply is enabled after failure');
  const result = button.props.onClick(); await tick(); return result;
};
const pendingState = async draft => {
  const view = await inspect();
  eq(state.operatingNetworkAction, 'profile');
  const hotel = view.nodes.find(node => node.props?.['data-testid'] === 'operating-network-hotel');
  const save = view.nodes.find(node => node.props?.['data-testid'] === 'operating-network-save-profile');
  eq(disabled(view,hotel), true); eq(disabled(view,save), true);
  const fields = fieldsFor(view); eq(fields.length, 18);
  ok(fields.every(({node}) => visible(view,node) && disabled(view,node)), 'All 18 profile fields must remain natively disabled while saving');
  // A user action on a disabled native control dispatches no input/click event.
  // In particular, do not call v-model or preview handlers to bypass fieldset.
  eq(await nativeInput('source_method', 'synthetic_manual_B', true), false);
  eq(await nativeInput('notes', 'Synthetic unsaved notes B', true), false);
  eq(await previewApply(true), false);
  eq(clone(state.operatingNetworkProfileForm), draft, 'No busy control may mutate draft A');
};
const assertEditable = async () => {
  const view = await inspect(), fields = fieldsFor(view); eq(fields.length, 18);
  ok(fields.every(({node}) => visible(view,node) && !disabled(view,node)), 'All 18 fields unlock');
};
const profileFor = body => {
  const content = { tenant_id: 70, hotel_id: 7, profile: { contract_version: 'controlled_operating_network.v1', dimensions: clone(body.profile), onboarding_confirmations: clone(body.onboarding), notes: body.notes }, quality_status: 'unverified', effective_date: body.effective_date, evidence_valid_until: null, evidence_refs: clone(body.evidence_refs), source_method: body.source_method };
  return { ...content, id: 101, version_no: 1, previous_version_id: null, is_current: true, content_digest: sha(JSON.stringify(content)).toLowerCase(), created_by: 901, created_at: '2026-09-15 09:00:00', updated_at: '2026-09-15 09:00:00', deleted_at: null, freshness_status: 'expired_or_missing' };
};

// Closed preview DTO follows OperatingNetworkService::previewProfileDraft.
// With no trusted facts, all dimensions and effective date remain missing.
const preview = {
  data_status: 'ok', preview_status: 'unavailable', preview_only: true,
  persistence_status: 'not_persisted', automatic_verification: false, hotel_id: 7,
  draft: {
    hotel_id: 7,
    profile: { contract_version: 'controlled_operating_network.v1', dimensions: Object.fromEntries(initial.operatingNetworkProfileDimensions.map(dimension => [dimension.key, []])), onboarding_confirmations: { room_rate_mapping: { status: 'missing', evidence_refs: [] }, metric_definition: { status: 'missing', evidence_refs: [] } }, notes: '' },
    quality_status: 'unverified', effective_date: '', evidence_valid_until: null, evidence_refs: [], source_method: 'system_evidence_draft_preview_v1',
  },
  dimension_evidence: {}, data_gaps: [{ code: 'synthetic_no_verified_fact', message: 'Synthetic preview has no verified facts.' }],
  summary: { filled_dimension_count: 0, missing_dimension_count: 8, confirmation_gap_count: 1, active_binding_count: 0, verified_fact_count: 0, verified_platforms: [], verified_business_date_start: null, verified_business_date_end: null, readback_candidate_count: 0, evaluated_readback_candidate_count: 0, readback_candidate_status_counts: {}, readback_candidate_evaluation_truncated: false, metadata_updated_date: null, room_type_count: 0 },
  preview_digest: sha('synthetic missing-facts preview').toLowerCase(), boundaries: clone(boundaries),
};

try {
  let nav = await inspect(true);
  const parentMenu = nav.nodes.find(node => node.type === 'a' && node.props?.['aria-label'] === '系统与工具');
  ok(parentMenu && visible(nav,parentMenu)); parentMenu.props.onClick(); await tick(); nav = await inspect(true);
  const entry = nav.nodes.find(node => node.type === 'a' && node.props?.['data-testid'] === 'nav-knowledge-center');
  ok(entry && visible(nav,entry)); entry.props.onClick({ stopPropagation() {} }); await tick(); eq(state.currentPage, 'knowledge-center');
  const view = await inspect(), select = view.nodes.find(node => node.props?.['data-testid'] === 'operating-network-hotel');
  ok(select && visible(view,select) && !disabled(view,select));
  const listeners = {}, element = { multiple: false, options: [{ value: '', selected: false }, { value: '7', selected: true }, { value: '8', selected: false }], addEventListener: (name, callback) => { listeners[name] = callback; } };
  Vue.vModelSelect.created(element, { modifiers: {} }, select); listeners.change({ target: element }); select.props.onChange(); await tick();
  const initialGet = requests.find(call => !call.settled); ok(initialGet); eq(initialGet.method, 'GET'); eq(new URL(initialGet.url).searchParams.get('hotel_id'), '7');
  reply(initialGet, { code: 200, data: overview(null) }); await tick(); eq(state.operatingNetworkData.data_status, 'ok');
  const collapsed = await inspect();
  const summary = collapsed.nodes.find(node => node.type === 'summary' && nodeText(node).trim() === '编辑并保存画像版本'); ok(summary); ok(!disabled(collapsed,summary));
  ok(collapsed.entries.find(entry => entry.node === summary).parents.some(node => node.type === 'details' && node.props?.['data-testid'] === 'operating-network-profile-editor'));
  // Model only normal native summary/details expansion, without changing source.
  detailsOpened = true;
  await assertEditable();

  // One original read-only preview request establishes a legitimate visible
  // apply button. It is never persisted or promoted to verified quality.
  const previewView = await inspect(), generate = previewView.nodes.find(node => node.props?.['data-testid'] === 'operating-network-generate-profile-preview');
  ok(generate && visible(previewView,generate) && !disabled(previewView,generate));
  const generatePending = generate.props.onClick(); inFlight.push(generatePending); await tick();
  const previewGet = requests.findLast(call => !call.settled); ok(previewGet); eq(previewGet.method, 'GET');
  eq(new URL(previewGet.url).pathname, '/api/operation/operating-profiles/preview'); eq(new URL(previewGet.url).searchParams.get('hotel_id'), '7');
  reply(previewGet, { code: 200, data: preview }); await generatePending; await tick();
  eq(clone(state.operatingNetworkProfilePreview), preview); eq(state.operatingNetworkAction, '');

  await setDraft('A'); const draftA = clone(state.operatingNetworkProfileForm);
  const first = await beginSave(); await pendingState(draftA);
  reply(first.post, { code: 500, message: 'Synthetic profile unavailable', data: null }, 500); await first.pending; await tick();
  eq(clone(state.operatingNetworkProfileForm), draftA, 'Failure preserves submitted A'); eq(state.operatingNetworkAction, '');
  ok(state.operatingNetworkError.includes('Synthetic profile unavailable')); await assertEditable(); eq(requests.length, 3);
  eq(await previewApply(false), true, 'The original preview handler works after failure unlock');
  eq(state.operatingNetworkProfileForm.source_method, 'system_evidence_draft_preview_v1');
  eq(state.operatingNetworkProfileForm.quality_status, 'unverified'); eq(state.operatingNetworkProfileForm.evidence_valid_until, '');
  eq(state.operatingNetworkProfileForm.room_rate_mapping_status, 'missing'); eq(state.operatingNetworkProfileForm.metric_definition_status, 'missing');
  eq(requests.length, 3, 'Applying preview does not issue a write');

  await setDraft('A'); const retryDraft = clone(state.operatingNetworkProfileForm);
  const save = await beginSave(); await pendingState(retryDraft);
  const saved = profileFor(save.post.body);
  reply(save.post, { code: 200, data: { profile: saved, created: true, persistence_status: 'readback_verified', write_boundaries: clone(boundaries) } }); await tick();
  const get = requests.findLast(call => call.method === 'GET' && !call.settled); ok(get);
  eq(new URL(get.url).pathname, '/api/operation/operating-network'); eq(new URL(get.url).searchParams.get('hotel_id'), '7');
  await pendingState(retryDraft);
  reply(get, { code: 200, data: overview(saved) }); await save.pending; await tick();
  eq(state.operatingNetworkAction, ''); eq(state.operatingNetworkHotelId, '7');
  eq(clone(state.operatingNetworkProfileForm), retryDraft, 'Same-hotel exact readback displays A');
  eq(state.operatingNetworkData.profile.id, 101); eq(state.operatingNetworkData.profile.hotel_id, 7); eq(state.operatingNetworkData.profile.content_digest, saved.content_digest);
  eq(state.operatingNetworkProfilePreview, null); ok(notices.some(notice => notice.type === 'success' && notice.message.includes('独立回读')));
  await assertEditable(); await setDraft('B');
  eq(state.operatingNetworkProfileForm.notes, 'Synthetic unsaved notes B'); eq(state.operatingNetworkProfileForm.source_method, 'synthetic_manual_B');
  eq(state.operatingNetworkProfileForm.quality_status, 'unverified');
  eq(requests.length, 5, 'Editing B after success does not save it');
  eq(requests.filter(call=>call.method==='POST').length, 2); eq(requests.filter(call=>call.method==='GET').length, 3);
  ok(requests.every(call => call.settled && !call.aborted));
  t.diagnostic(`${assertions} explicit assertions; 2 synthetic POST + 3 GET closed; no disabled-control events`);
} finally {
  for (const call of requests.filter(call=>!call.settled)) reply(call, { code: 500, message: 'Synthetic cleanup', data: null }, 500);
  await Promise.allSettled(inFlight);
}
});
