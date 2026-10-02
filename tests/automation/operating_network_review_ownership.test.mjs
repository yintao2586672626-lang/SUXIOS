import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Default reads current product source; overrides support pre-integration runs.
// All requests use closed synthetic DTO transport. No HTTP, DB or browser.
const sourceRoot = process.env.SUXI_NETWORK_REVIEWS_SOURCE_ROOT || '.';
const candidateRoot = process.env.SUXI_NETWORK_REVIEWS_CANDIDATE_ROOT || '';
const read = file => fs.readFileSync(candidateRoot && fs.existsSync(path.resolve(candidateRoot, file)) ? path.resolve(candidateRoot, file) : path.resolve(sourceRoot, file), 'utf8');
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
  refs: cut(main, '            const operatingNetworkProfileDimensions =', '            const selectedKnowledgeCenterUnitIds ='),
  auth: declaration('captureAuthSession') + '\n' + declaration('isAuthSessionCurrent'),
  compare: declaration('canonicalAiGovernanceJson') + '\n' + declaration('sameAiGovernanceJson'),
};
const navNames = ['normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess', 'getMenuItemName', 'stableHashSegmentForTestId', 'normalizeTestIdSegmentInline', 'menuTestId', 'toggleSubmenu', 'isSidebarMenuItemActive', 'handleParentMenuClick', 'handleMenuClick', 'handleNestedMenuClick'];
parts.navHandlers = navNames.map(declaration).join('\n');
parts.delegate = declaration('callKnowledgeCenterDomain') + '\n' + ['generateOperatingNetworkReplicationDraft','restoreOperatingNetworkReplication','changeOperatingNetworkHotel','loadOperatingNetwork','saveOperatingNetworkReview'].map(declaration).join('\n');

const ast = parse(rawTemplate), selected = new Set(), astEntries = []; let workbench, draft;
const walkAst = (node, parents = []) => { astEntries.push({ node, parents });
  const id = node.props?.find(p => p.name === 'data-testid')?.value?.content;
  if (id === 'operating-network-workbench') workbench = node;
  if (id === 'operating-network-replication-draft') draft = node;
  for (const child of node.children || []) walkAst(child, [...parents, node]); };
walkAst(ast); assert.ok(workbench && draft); selected.add(draft);
for (const child of workbench.children.filter(n => n.type === 1)) {
  if (child.loc.source.includes('data-testid="operating-network-hotel"') && child.tag !== 'template') selected.add(child);
  if (child.props.some(p => p.name === 'if' && p.exp?.content === '!operatingNetworkHotelId')) selected.add(child);
  if (child.props.some(p => p.name === 'data-testid' && p.value?.content === 'operating-network-error')) selected.add(child);
}
const retain = node => { if (selected.has(node)) return node.loc.source;
  const children = (node.children || []).map(retain).join(''); if (!children || node.type === 0) return children;
  return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + node.loc.source.slice(node.loc.source.lastIndexOf('</')); };
const compileNode = text => new Function('Vue', compile(text, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const renderPanel = compileNode(retain(ast));
const renderNav = compileNode(cut(rawShell, '                <nav ', '                </nav>') + '                </nav>');
const componentSource = cut(read('public/components/system/app-main-components.js'), '    const OperatingNetworkReplicationList =', '    const MeituanSearchKeywordWorkbench =');
const OriginalList = new Function('h', componentSource + '\nreturn OperatingNetworkReplicationList;')(Vue.h);

// Fixtures model allowed blocked drafts and inconclusive reviews; no backend or browser is called.
const createHarness = async () => {
const requests = [], notices = [], runtimeErrors = [], requestDiagnostics = [], inFlight = [];
const boundaries = { contract_version: 'controlled_operating_network.v1', status_is_draft: true, human_target_validation_required: true, automatic_execution: false, ota_write: false, external_message: false, causality_claimed: false };
const replicationBoundaries = { status_is_draft: true, target_verified: false, human_target_validation_required: true, automatic_execution: false, ota_write: false, external_message: false };
const sourceOptions = [301, 302].map(id => ({ id, hotel_id: 8, hotel_name: 'Synthetic accessible source hotel', title: `Verified source SOP ${id}`, version_no: 1, profile_dimension_count: 8, evidence_valid_until: '2027-12-31', replication_eligibility: 'eligible_for_validation_draft', replication_gaps: [], source_operating_loop_ref: 'hotel_operating_cycles#801', validation_status: 'verified', lifecycle_status: 'active' }));
const missingTargetGap = { code: 'target_hotel_comparable_fact_missing', message: 'Synthetic target has no matching strict-readback facts; draft remains blocked.' };
const dimensions = ['hotel_type_and_scale', 'city_district_demand', 'price_band', 'room_type_structure', 'platform_channel_structure', 'seasonality', 'data_quality', 'pre_action_state'];
const sourceProfile = Object.fromEntries(dimensions.map(key => [key, [`Synthetic source ${key}`]]));
const replication = (id, sourceId, dateStart, dateEnd) => ({ id, tenant_id: 70, source_sop_version_id: sourceId, source_hotel_id: 8, target_hotel_id: 7,
  status: 'blocked_missing_target_facts', target_validation_status: 'blocked_missing_target_facts', content_digest: sha(`Synthetic replication ${id}`).toLowerCase(),
  target_fact_refs: [], data_gaps: [clone(missingTargetGap)], created_by: 901, created_at: '2026-09-15 09:00:00', updated_at: '2026-09-15 09:00:00', deleted_at: null,
  draft: { contract_version: 'hotel_operating_sop.v1', source_sop_version_id: sourceId, source_hotel_id: 8, target_hotel_id: 7, title: `Verified source SOP ${sourceId}`, objective: 'Observe manually', steps: ['Observe verified channel scope'], stop_conditions: ['Stop if evidence differs'],
    scope: { tenant_id: 70, hotel_id: 7, source_hotel_id: 8, platform: 'ctrip', source_scope: 'ota_channel', target_validation_required: true, applicable_data_types: ['traffic'], metric_definitions: ['channel_exposure'] },
    source_evidence_policy: 'reference_only_not_reused_as_target_fact', target_fact_refs: [],
    target_fact_comparison_contract: { tenant_id: 70, target_hotel_id: 7, platform: 'ctrip', source_scope: 'ota_channel', date_start: dateStart, date_end: dateEnd, date_scope_source: 'target_request', data_types: ['traffic'], metric_definitions: ['channel_exposure'], readback_verified: true, validation_status: 'normal' },
    experience_applicability: { profile: clone(sourceProfile), action_parameters: ['Observe once'], success_conditions: ['Matching evidence'], failure_samples: ['Evidence gap'], stop_conditions: ['Stop if evidence differs'], evidence_valid_until: '2027-12-31' },
    applicability_assessment: { contract_version: boundaries.contract_version, recommendation: 'validation_draft_only', confidence: 'blocked', summary: 'Synthetic target profile and evidence are missing; manual validation is required.', dimension_results: dimensions.map(key => ({ dimension: key, label: key, status: 'missing', source_values: sourceProfile[key], target_values: [], unmet_source_values: sourceProfile[key] })),
      matched_count: 0, missing_count: 8, conflict_count: 0, counterexample_count: 0, success_count: 0, matched_dimensions: [], missing_dimensions: dimensions, conflicting_dimensions: [], counterexamples: [], success_samples: [], source_profile_gaps: [], target_profile: null,
      replication_evidence: { success_count: 0, ignored_review_count: 0, success_samples: [] }, source_operating_loop: { id: 801 }, target_operating_loop: null, data_gaps: [clone(missingTargetGap)], boundaries: clone(boundaries) }, boundaries: clone(replicationBoundaries) } });
const oldA = replication(901, 301, '2026-09-14', '2026-09-14'), newB = replication(902, 302, '2026-09-15', '2026-09-15');
const uniqueOldNote = 'Synthetic prior observation: the first draft needs an additional manual check';
const oldReview = { id: 1001, tenant_id: 70, replication_id: 901, review_no: 1, source_sop_version_id: 301, source_hotel_id: 8, target_hotel_id: 7, outcome: 'inconclusive', content_digest: sha('Synthetic old review 1001').toLowerCase(), created_by: 901, created_at: '2026-09-15 09:00:00', deleted_at: null,
  review: { contract_version: boundaries.contract_version, outcome: 'inconclusive', note: uniqueOldNote, observed_conditions: [], failure_conditions: [], stop_triggered: [], evidence_refs: [], reviewed_business_date: null,
    evidence_verification: { status: 'not_required', lineage_status: 'not_required', verified_refs: [], scope: { tenant_id: 70, target_hotel_id: 7 } }, target_profile_snapshot: null, draft_snapshot: clone(oldA.draft), causality_claimed: false } };
const overview = { data_status: 'ok', profile: null, onboarding: { current_stage: 'identity_confirmation', stages: [] }, comparable_hotels: [], verified_sops: sourceOptions,
  replications: { data_status: 'ok', list: [oldA], count: 1, unavailable_count: 0 }, network_asset_summary: { field_validated: false, field_evidence_status: 'none' }, hotel_options: [{ id: 7, name: 'Synthetic target hotel' }, { id: 8, name: 'Synthetic source hotel' }], data_gaps: [{ code: 'hotel_operating_profile_missing', message: 'Synthetic target profile is missing.' }], boundaries: clone(boundaries) };
const sandbox = {
  window: { innerWidth: 1280 }, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
  ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, Date, setTimeout, clearTimeout,
  console: { warn() {}, error(...args) { requestDiagnostics.push(args.map(String).join(' ')); } }, API_BASE: 'https://synthetic.invalid/api',
  currentPage: Vue.ref('compass'), user: Vue.ref({ id: 901, realname: 'Synthetic operator', is_super_admin: true, capabilities: ['all'] }),
  authSessionEpoch: 1, pageRequestGeneration: 1, filterReportHotel: Vue.ref('7'), authContext: Vue.ref({ hotelId: '7', tenantId: '70', platform: 'ctrip', tokenStatus: 'valid', permissionStatus: 'allowed' }),
  permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 70 }]), token: Vue.ref('round99-synthetic-session-not-a-credential'),
  revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'), operationYesterday: '2026-09-14',
  isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
  expandedMenus: Vue.ref([]), sidebarCollapsed: false, agentTab: Vue.ref('overview'), revenueAgentTab: Vue.ref('analysis'), onlineDataTab: Vue.ref('data-health'), pendingOnlineDataEntryTab: '',
  aiModelConfigText: key => key, showToast: (message, type = 'success') => notices.push({ message, type }), knowledgeCenterDomainRevision: Vue.ref(0),
  fetch: (url, options) => new Promise((resolve, reject) => {
    const parsed = new URL(url), method = options.method || 'GET'; assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(new Headers(options.headers).get('Authorization'), sandbox.token.value);
    assert.ok(method === 'POST' && (parsed.pathname === '/api/operation/operating-sops/302/replications' || parsed.pathname === '/api/operation/operating-sop-replications/901/reviews') || method === 'GET' && (parsed.pathname === '/api/operation/operating-network' || /^\/api\/operation\/operating-sop-replications\/(901|902)(\/reviews)?$/.test(parsed.pathname)));
    const call = { url, method, body: options.body ? JSON.parse(options.body) : null, resolve, reject, settled: false, aborted: false, response: null, has_abort_signal: !!options.signal }; requests.push(call);
    const abort = () => { if (!call.settled) { call.settled = true; call.aborted = true; reject(new DOMException('Original request aborted', 'AbortError')); } };
    call.removeAbort = () => options.signal?.removeEventListener('abort', abort); if (options.signal?.aborted) abort(); else options.signal?.addEventListener('abort', abort, { once: true });
  }),
};
vm.createContext(sandbox); vm.runInContext(read('public/system-static.js') + '\n' + read('public/operation-static.js') + '\n' + rawDomain, sandbox);
sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name]; sandbox.testIdNameMap = sandbox.appSystemStatic.testIdNameMap;
sandbox.operationStatic = Vue.ref(sandbox.window.SUXI_OPERATION_STATIC);
// The original operation helper is already loaded in memory; no asset request is needed.
sandbox.ensureOperationStaticReady = async () => sandbox.operationStatic.value;
sandbox.requireOperationStatic = (config, key) => { assert.equal(typeof config[key], 'function'); return config[key]; };
const refNames = [...parts.refs.matchAll(/const (\w+) =/g)].map(match => match[1]);
vm.runInContext(Object.values(parts).filter(part => part !== parts.delegate).join('\n') + '\nglobalThis.initial={request,buildLeanNavigationItems,captureAuthSession,isAuthSessionCurrent,canonicalAiGovernanceJson,sameAiGovernanceJson,' + refNames.join(',') + ',' + navNames.join(',') + '};', sandbox);
const initial = sandbox.initial;
const domain = sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({ ...sandbox, ...initial, computed: Vue.computed, request: initial.request, requireSystemStatic: name => sandbox.appSystemStatic[name] });
sandbox.createKnowledgeCenterDomain = () => domain;
vm.runInContext(parts.delegate + '\nglobalThis.delegates={generateOperatingNetworkReplicationDraft,restoreOperatingNetworkReplication,changeOperatingNetworkHotel,loadOperatingNetwork,saveOperatingNetworkReview};', sandbox);
const menus = initial.buildLeanNavigationItems(sandbox.appSystemStatic.filterVisibleMenuItems(sandbox.appSystemStatic.resolveMenuItems(sandbox.appSystemStatic.menuItemDefinitions, {}), sandbox.user.value));
const state = Vue.proxyRefs({ ...sandbox, ...initial, ...domain, ...sandbox.delegates, visibleMenuItems: menus, knowledgeCenterHotelOptions: overview.hotel_options });
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const nodeText = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(nodeText).join('') : node?.children ? nodeText(node.children) : '';
const openedDetails = new Set();
const inspect = async (navigation = false) => {
  let tree, listTree; const captureList = { ...OriginalList, render() { listTree = OriginalList.render.call(this); return listTree; } };
  const applyNativeDetailsState = node => { if (Array.isArray(node)) return node.forEach(applyNativeDetailsState); if (!node || typeof node !== 'object') return;
    if (node.type === 'details' && openedDetails.has(node.props?.['data-testid'])) node.props = { ...node.props, open: true }; applyNativeDetailsState(node.children); };
  const app = Vue.createSSRApp({ render() { tree = (navigation ? renderNav : renderPanel)(state, []); applyNativeDetailsState(tree); return tree; } });
  app.component('OperatingNetworkReplicationList', captureList); app.config.errorHandler = error => { runtimeErrors.push(error.message); };
  const html = await renderToString(app), entries = []; const walk = (node, parents = []) => { if (Array.isArray(node)) return node.forEach(child => walk(child, parents)); if (!node || typeof node !== 'object') return; entries.push({ node, parents }); walk(node.children, [...parents, node]); };
  walk(tree); const listHost = entries.find(entry => entry.node.type === captureList);
  if (listTree) { assert.ok(listHost); walk(listTree, [...listHost.parents, listHost.node]); }
  return { html, entries, nodes: entries.map(entry => entry.node) };
};
const disabled = (view, node) => !!node.props?.disabled || view.entries.find(entry => entry.node === node).parents.some(parent => parent.props?.inert || parent.type === 'fieldset' && parent.props?.disabled);
const visible = (view, node) => { const entry = view.entries.find(item => item.node === node); return ![...entry.parents, node].some(parent => parent.props?.style?.display === 'none' || parent.props?.inert)
  && !entry.parents.some((parent, index) => parent.type === 'details' && !parent.props?.open && (entry.parents[index + 1] || node).type !== 'summary'); };
const reply = (call, body, status = 200) => { assert.ok(call && !call.settled); call.settled = true; call.removeAbort(); call.response = clone(body); call.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
const pending = pathname => { const call = requests.findLast(call => !call.settled && new URL(call.url).pathname === pathname); assert.ok(call, pathname); return call; };
const selectValue = async (view, node, value) => { assert.ok(node && visible(view, node) && !disabled(view, node));
  const options = view.nodes.filter(n => n.type === 'option' && view.entries.find(entry => entry.node === n).parents.includes(node));
  const chosen = options.find(n => String(n.props?.value || '') === value); assert.ok(chosen && !chosen.props.disabled);
  const listeners = {}, element = { multiple: false, options: options.map(n => ({ value: String(n.props?.value || ''), selected: String(n.props?.value || '') === value, disabled: !!n.props?.disabled })), addEventListener: (name, callback) => listeners[name] = callback };
  Vue.vModelSelect.created(element, { modifiers: {} }, node); listeners.change({ target: element }); if (node.props.onChange) node.props.onChange(); await tick(); };
const click = async predicate => { const view = await inspect(), node = view.nodes.find(predicate); assert.ok(node && visible(view, node) && !disabled(view, node), 'original visible enabled control'); const result = node.props.onClick(); await tick(); return { result }; };
const setText = async (predicate, value) => { const view = await inspect(), node = view.nodes.find(predicate); assert.ok(node && visible(view, node) && !disabled(view, node), 'original visible enabled input');
  const listeners = {}, element = { type: node.props?.type || 'textarea', value, composing: false, addEventListener: (name, cb) => listeners[name] = cb }; Vue.vModelText.created(element, { modifiers: {} }, node); listeners.input({ target: element }); await tick(); };
const reviewResponse = (id, rows) => ({ code: 200, data: { data_status: 'ok', replication_id: id, list: rows, count: rows.length, append_only: true, boundaries: clone(boundaries) } });
const reviewFor = (replication, id, note) => ({ ...clone(oldReview), id, replication_id: replication.id, source_sop_version_id: replication.source_sop_version_id,
  content_digest: sha(`Synthetic review ${id} ${note}`).toLowerCase(), review: { ...clone(oldReview.review), note, draft_snapshot: clone(replication.draft) } });
const openReviews = async () => { const view = await inspect(), details = view.nodes.find(n => n.type === 'details' && n.props?.['data-testid'] === 'operating-network-review'); assert.ok(details);
  if (!details.props.open) { const summary = view.nodes.find(n => n.type === 'summary' && view.entries.find(entry => entry.node === n).parents.at(-1) === details); assert.ok(summary && visible(view, summary) && !disabled(view, summary)); openedDetails.add('operating-network-review'); }
};
const renderState = async () => { const view = await inspect(), marker = name => view.nodes.find(n => n.props?.['data-testid'] === `operating-network-reviews-${name}`), rows = view.nodes.filter(n => typeof n.key === 'string' && n.key.startsWith('network-review-') && visible(view, n));
  return { ...view, marker, rows, heading: nodeText(view.nodes.find(n => n.props?.['data-testid'] === 'operating-network-assessment')) }; };
const startRestore = async id => click(n => n.type === 'button' && n.key === id);
const bootstrap = async () => { let nav = await inspect(true), parent = nav.nodes.find(n => n.type === 'a' && n.props?.['aria-label'] === '系统与工具'); assert.ok(parent && visible(nav, parent)); parent.props.onClick(); await tick();
  nav = await inspect(true); const entry = nav.nodes.find(n => n.type === 'a' && n.props?.['data-testid'] === 'nav-knowledge-center'); assert.ok(entry && visible(nav, entry)); entry.props.onClick({ stopPropagation() {} }); await tick(); assert.equal(state.currentPage, 'knowledge-center');
  const view = await inspect(); await selectValue(view, view.nodes.find(n => n.props?.['data-testid'] === 'operating-network-hotel'), '7'); reply(pending('/api/operation/operating-network'), { code: 200, data: overview }); await tick();
  await startRestore(901); reply(pending('/api/operation/operating-sop-replications/901'), { code: 200, data: oldA }); await tick(); reply(pending('/api/operation/operating-sop-replications/901/reviews'), reviewResponse(901, [oldReview])); await tick();
  assert.equal(state.operatingNetworkLastReplication.id, 901); assert.equal(state.operatingNetworkReviews[0].replication_id, 901); assert.equal(state.operatingNetworkAction, ''); await openReviews();
  const result = await renderState(); assert.ok(result.html.includes(uniqueOldNote)); return result;
};
const startB = async () => { const view = await inspect(), source = view.nodes.find(n => n.type === 'select' && n.props?.['onUpdate:modelValue']?.toString().includes('operatingNetworkReplicationForm.source_sop_version_id')); await selectValue(view, source, '302');
  for (const label of ['目标事实开始日期', '目标事实结束日期']) await setText(n => n.props?.['aria-label'] === label, '2026-09-15');
  const { result } = await click(n => n.type === 'button' && n.props?.onClick?.toString().includes('generateOperatingNetworkReplicationDraft')); const post = pending('/api/operation/operating-sops/302/replications');
  assert.equal(post.body.target_hotel_id, 7); assert.equal(post.body.target_date_start, '2026-09-15'); assert.equal(post.body.target_date_end, '2026-09-15');
  reply(post, { code: 200, data: { replication: newB, created: true, persistence_status: 'readback_verified', write_boundaries: replicationBoundaries } }); await tick();
  reply(pending('/api/operation/operating-sop-replications/902'), { code: 200, data: newB }); await tick(); return { result, reviews: pending('/api/operation/operating-sop-replications/902/reviews') }; };
const failReviews = (call, message) => reply(call, { code: 500, message, data: null }, 500);
const setNote = text => setText(n => n.type === 'textarea' && n.props?.['onUpdate:modelValue']?.toString().includes('operatingNetworkReviewForm.note'), text);
const startSave = async () => (await click(n => n.type === 'button' && n.props?.onClick?.toString().includes('saveOperatingNetworkReview')));
const finish = expectedDiagnostics => { assert.deepEqual(requestDiagnostics, expectedDiagnostics); assert.deepEqual(runtimeErrors, []); assert.ok(requests.every(r => r.settled && !r.aborted)); assert.ok(requests.filter(r => r.method === 'GET').every(r => r.has_abort_signal)); assert.ok(requests.filter(r => r.method === 'POST').every(r => !r.has_abort_signal)); return { requests: requests.length, post: requests.filter(r => r.method === 'POST').length, get: requests.filter(r => r.method === 'GET').length }; };
const diagnostic = (id, message) => `API请求失败: /operation/operating-sop-replications/${id}/reviews?system_hotel_id=7&tenant_id=70&platform=ctrip Error: ${message}`;
return { state, requests, notices, bootstrap, startB, startRestore, startSave, renderState, inspect, click, setNote, pending, reply, tick, failReviews, finish, diagnostic, reviewResponse, reviewFor, oldA, newB, oldReview, overview, boundaries, uniqueOldNote };
};

test('new draft review loading/failure excludes previous rows and original refresh/restore recovers with GET only', async () => {
  const h = await createHarness(); await h.bootstrap(); const saved = await h.startB();
  let view = await h.renderState(); assert.match(view.heading, /草稿 #902/); assert.equal(view.rows.length, 0, 'B pending must not show A reviews'); assert.ok(view.marker('loading')); assert.ok(!view.marker('empty')); assert.equal(h.state.operatingNetworkAction, 'replication');
  const message = '复制复盘查询失败（合成 500）'; h.failReviews(saved.reviews, message); await saved.result; await h.tick();
  view = await h.renderState(); assert.equal(view.rows.length, 0); assert.ok(view.marker('error')); assert.match(nodeTextGlobal(view.marker('error')), /草稿 #902 已回读/); assert.ok(!view.marker('empty')); assert.equal(h.state.operatingNetworkAction, ''); assert.equal(h.notices.filter(n => n.type === 'success').length, 0);
  await h.setNote('Synthetic unsaved note kept during recovery');
  const refresh = await h.click(n => n.type === 'button' && n.props?.onClick?.toString().includes('loadOperatingNetwork'));
  h.reply(h.pending('/api/operation/operating-network'), { code: 200, data: { ...h.overview, replications: { data_status: 'ok', list: [h.oldA, h.newB], count: 2, unavailable_count: 0 } } }); await refresh.result; await h.tick();
  assert.equal(h.state.operatingNetworkLastReplication.id, 902); assert.equal(h.state.operatingNetworkReviewForm.note, 'Synthetic unsaved note kept during recovery');
  await h.startRestore(902); h.reply(h.pending('/api/operation/operating-sop-replications/902'), { code: 200, data: h.newB }); await h.tick();
  const ownReview = h.reviewFor(h.newB, 1002, 'Synthetic current draft observation'); h.reply(h.pending('/api/operation/operating-sop-replications/902/reviews'), h.reviewResponse(902, [ownReview])); await h.tick();
  view = await h.renderState(); assert.ok(view.marker('ready')); assert.equal(view.rows.length, 1); assert.ok(view.html.includes(ownReview.review.note)); assert.ok(!view.html.includes(h.uniqueOldNote)); assert.equal(h.state.operatingNetworkReviews[0].replication_id, 902); assert.equal(h.state.operatingNetworkReviewForm.note, 'Synthetic unsaved note kept during recovery');
  assert.deepEqual(h.finish([h.diagnostic(902, message)]), { requests: 9, post: 1, get: 8 });
});

const nodeTextGlobal = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(nodeTextGlobal).join('') : node?.children ? nodeTextGlobal(node.children) : '';
test('a strict successful empty review response is explicitly distinct from loading and failure', async () => {
  const h = await createHarness(); await h.bootstrap(); const saved = await h.startB(), empty = h.reviewResponse(902, []); empty.data.count = '0'; h.reply(saved.reviews, empty); await saved.result; await h.tick();
  const view = await h.renderState(); assert.ok(view.marker('empty')); assert.match(nodeTextGlobal(view.marker('empty')), /已确认草稿 #902 暂无复盘记录/); assert.ok(!view.marker('error')); assert.ok(!view.marker('loading')); assert.equal(view.rows.length, 0); assert.equal(h.notices.at(-1).type, 'success');
  assert.deepEqual(h.finish([]), { requests: 6, post: 1, get: 5 });
});

for (const succeeds of [false, true]) test(`saving the current draft review ${succeeds ? 'clears only after exact readback' : 'keeps the draft after list readback failure'}`, async () => {
  const h = await createHarness(); await h.bootstrap(); const note = 'Synthetic explicit human review'; await h.setNote(note); const saved = await h.startSave();
  const post = h.pending('/api/operation/operating-sop-replications/901/reviews'); assert.equal(post.method, 'POST'); assert.equal(post.body.note, note); assert.equal(post.body.outcome, 'inconclusive');
  const newReview = { ...h.reviewFor(h.oldA, 1003, note), review_no: 2 }; h.reply(post, { code: 200, data: { review: newReview, persistence_status: 'readback_verified', write_boundaries: h.boundaries } }); await h.tick();
  let view = await h.renderState(); assert.ok(view.marker('loading')); assert.equal(view.rows.length, 0); assert.equal(h.state.operatingNetworkReviewForm.note, note); assert.equal(h.notices.filter(n => n.type === 'success').length, 0);
  const readback = h.pending('/api/operation/operating-sop-replications/901/reviews'), message = '复盘已写入，列表读取未完成（合成 500）';
  if (succeeds) h.reply(readback, h.reviewResponse(901, [h.oldReview, newReview])); else h.failReviews(readback, message);
  await saved.result; await h.tick(); view = await h.renderState(); assert.equal(h.state.operatingNetworkAction, '');
  if (succeeds) { assert.ok(view.marker('ready')); assert.equal(view.rows.length, 2); assert.equal(h.state.operatingNetworkReviewForm.note, ''); assert.equal(h.notices.at(-1).type, 'success'); }
  else { assert.ok(view.marker('error')); assert.ok(!view.marker('empty')); assert.equal(view.rows.length, 0); assert.equal(h.state.operatingNetworkReviewForm.note, note); assert.equal(h.notices.at(-1).type, 'error'); }
  assert.deepEqual(h.finish(succeeds ? [] : [h.diagnostic(901, message)]), { requests: 5, post: 1, get: 4 });
});

test('incomplete lists, foreign rows and unsafe boundaries never become confirmed review content', async () => {
for (const invalid of ['missing_list', 'missing_count', 'row_parent', 'boundaries']) {
  const h = await createHarness(); await h.bootstrap(); const saved = await h.startB(), response = h.reviewResponse(902, []);
  if (invalid === 'missing_list') delete response.data.list;
  if (invalid === 'missing_count') delete response.data.count;
  if (invalid === 'row_parent') { response.data.list = [h.oldReview]; response.data.count = 1; }
  if (invalid === 'boundaries') response.data.boundaries.automatic_execution = true;
  h.reply(saved.reviews, response); await saved.result; await h.tick(); const view = await h.renderState();
  assert.ok(view.marker('error')); assert.ok(!view.marker('empty')); assert.ok(!view.marker('ready')); assert.equal(view.rows.length, 0); assert.equal(h.notices.filter(n => n.type === 'success').length, 0); assert.equal(h.state.operatingNetworkLastReplication.id, 902);
  assert.deepEqual(h.finish([]), { requests: 6, post: 1, get: 5 });
}
});
