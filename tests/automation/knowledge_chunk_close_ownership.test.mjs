import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Default reads current product source; overrides support pre-integration runs.
// All requests use closed synthetic DTO transport. No HTTP, DB or browser.
const sourceArgument = process.argv.find(argument => argument.startsWith('--source='));
const domainPath = sourceArgument ? sourceArgument.slice('--source='.length) : 'public/components/system/knowledge-center-domain.js';
const read = file => fs.readFileSync(file.endsWith('knowledge-center-domain.js') ? domainPath : file, 'utf8');
const main = read('public/app-main.js').replaceAll('\r\n', '\n');
const rawDomain = read('public/components/system/knowledge-center-domain.js');
const rawTemplate = read('resources/frontend/templates/fragments/20-page-knowledge-center.html');
const rawShell = read('resources/frontend/templates/fragments/00-app-shell.html');
const clone = value => JSON.parse(JSON.stringify(value));
const cut = (source, start, end) => { const a = source.indexOf(start), b = source.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start); return source.slice(a, b); };
const declaration = name => { const a = main.indexOf('            const ' + name + ' ='); assert.ok(a >= 0, name); const match = /\n            (?:const|let) /.exec(main.slice(a + 1)); assert.ok(match, name); return main.slice(a, a + 1 + match.index); };
const parts = {
  context: cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  abort: cut(main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  policy: cut(main, '            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  coordinator: cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  request: cut(main, '            const request = async (', '            const apiRequest = request;'),
  navigation: cut(main, '            const cloneMenuItem =', '            // 可见菜单项'),
  refs: cut(main, '            const knowledgeCenterUnits =', '            const knowledgePromotionHotelId =') + '\n' + cut(main, '            const selectedKnowledgeCenterUnitIds =', '            // 表格列定义'),
  auth: declaration('captureAuthSession') + '\n' + declaration('isAuthSessionCurrent'),
  compare: declaration('canonicalAiGovernanceJson') + '\n' + declaration('sameAiGovernanceJson'),
};
const navNames = ['normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess', 'getMenuItemName', 'stableHashSegmentForTestId', 'normalizeTestIdSegmentInline', 'menuTestId', 'toggleSubmenu', 'isSidebarMenuItemActive', 'handleParentMenuClick', 'handleMenuClick', 'handleNestedMenuClick'];
parts.navHandlers = navNames.map(declaration).join('\n');
parts.delegate = declaration('callKnowledgeCenterDomain') + '\n' + ['loadKnowledgeCenter','openKnowledgeChunks','saveKnowledgeChunk'].map(declaration).join('\n');


const displayNames = ['parseKnowledgeTags', 'KNOWLEDGE_CENTER_DISPLAY_LABELS', 'KNOWLEDGE_CENTER_BOUNDARY_TAGS',
  'knowledgeCenterDisplayLabel', 'knowledgeCenterTagTone', 'knowledgeCenterTagGroups',
  'knowledgeCenterStatusLabel', 'knowledgeCenterStatusClass', 'knowledgeCenterReadinessClass',
  'knowledgeCenterHotelOptions', 'defaultKnowledgeCenterHotelId', 'getHotelNameById'];
parts.display = displayNames.map(declaration).join('\n');
const dialog = read('resources/frontend/templates/fragments/38-dialogs-knowledge-center.html');
const entries = [], selected = new Set(); let table, refresh, modal;
const walkAst = (node, parents = []) => {
  entries.push({ node, parents });
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === 'openKnowledgeChunks(unit)')) {
    table = [...parents].reverse().find(n => n.tag === 'table');
  }
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === 'loadKnowledgeCenter')) refresh = node;
  if (node.type === 1 && node.props.some(p => p.name === 'if' && p.exp?.content === 'showKnowledgeCenterChunksModal')) modal = node;
  for (const child of node.children || []) walkAst(child, [...parents, node]);
};
const pageAst = parse(rawTemplate); walkAst(pageAst); walkAst(parse(dialog));
assert.ok(table && refresh && modal); selected.add(table); selected.add(refresh);
const retain = node => {
  if (selected.has(node)) return node.loc.source;
  const children = (node.children || []).map(retain).join('');
  if (!children || node.type === 0) return children;
  return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
};
const compileNode = source => new Function('Vue', compile(source, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const renderPage = compileNode(retain(pageAst)), renderModal = compileNode(modal.loc.source);
const renderNav = compileNode(cut(rawShell, '                <nav ', '                </nav>') + '                </nav>');
const units = [101, 102].map((id, index) => ({ unit_id: id, tenant_id: 70, hotel_id: 7, created_by: 901,
  name: `Synthetic unit ${index ? 'B' : 'A'}`, source: 'text', status: 'pending', description: 'Synthetic unverified reference',
  tags: [], can_edit: true, system_read_only: false }));
const listBody = { code: 0, data: { list: units, pagination: { total: 2, page: 1, page_size: 10, total_page: 1 } } };

// This fixed new-chunk DTO was generated with the original PHP mapper,
// KnowledgeContentDigestService and pure retrieval compare. It has grade D,
// user_provided_unverified quality; it is not a real persistence/account claim.
// request_digest = digest([normalized data + created_by, 0, '']); revision_digest
// = digest(content including exact revision metadata). No test runtime PHP.
const savedFixture = {
  "input_body": {
    "type": "经验片段",
    "content": {
      "text": "Synthetic accepted reference note",
      "source_verification_status": "unverified",
      "evidence_level": "reference_only"
    },
    "replaces_chunk_id": 0,
    "expected_digest": "",
    "request_id": "round101-synthetic-request-2"
  },
  "receipt": {
    "code": 0,
    "data": {
      "chunk": {
        "chunk_id": 1001,
        "unit_id": 101,
        "type": "经验片段",
        "promotion_candidate_id": null,
        "operating_sop_version_id": null,
        "version_no": null,
        "lifecycle_status": null,
        "content_digest": null,
        "superseded_by_chunk_id": null,
        "published_at": null,
        "retired_at": null,
        "is_current": false,
        "integrity_status": "not_applicable",
        "content": {
          "text": "Synthetic accepted reference note",
          "evidence_level": "user_provided_unverified",
          "scope": "hotel_specific_reference_unverified",
          "evidence_grade": "D",
          "reference_only": true,
          "decision_safe": false,
          "task_draft_safe": false,
          "external_write_authorized": false,
          "source_verification_status": "unverified",
          "requires_current_verification": true,
          "current_verification_status": "unverified",
          "decision_policy": "reference_only_until_separate_review",
          "blocked_uses": [
            "operation_task_creation",
            "operation_execution",
            "automatic_ota_write"
          ],
          "submitted_evidence_level": "reference_only",
          "knowledge_revision": {
            "number": 1,
            "parent_chunk_id": null,
            "parent_digest": null,
            "request_id": "round101-synthetic-request-2",
            "request_digest": "a52ea072e9d877d6d96ca891ed661ec48e192cceaac97b975e9635c330ac5c68",
            "evaluation_context": {
              "hotel_id": 7,
              "user_id": 901,
              "tenant_id": 70,
              "platform": "all_ota",
              "as_of": "2026-09-20",
              "hotel_conditions": []
            },
            "evaluation_unit": {
              "unit_id": 101,
              "tenant_id": 70,
              "hotel_id": 7,
              "created_by": 901,
              "name": "Synthetic unit A",
              "source": "text",
              "status": "pending",
              "description": "Synthetic unverified reference"
            }
          }
        },
        "created_by": 901,
        "revision_digest": "f6c0fdd7179548a1fab9a9f6029bdd4f8836de4409f9d7bca5d687956c79ea52",
        "revision_no": 1,
        "created_at": "2026-09-20 12:00:00"
      },
      "readback_verified": true,
      "replayed": false,
      "reevaluation": {
        "status": "reevaluated",
        "scope": "selected_unit_only",
        "question_count": 32,
        "affected_count": 0,
        "affected_questions": [],
        "evidence_boundary": "deterministic_retrieval_and_citations_not_llm_quality"
      }
    }
  }
};
class FixedDate extends Date {
  constructor(...args) { super(...(args.length ? args : ['2026-09-20T04:00:00.000Z'])); }
  static now() { return new Date('2026-09-20T04:00:00.000Z').getTime(); }
}

function harness(t) {
const requests = [], notices = [], runtimeErrors = [], diagnostics = [], inFlight = [];
const sandbox = {
  window: { innerWidth: 1280 }, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
  ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, Date: FixedDate, setTimeout, clearTimeout,
  console: { warn() {}, error(...args) { diagnostics.push(args.map(String).join(' ')); } },
  crypto: { randomUUID: () => `round101-synthetic-request-${requests.filter(call => call.method === 'POST').length + 2}` },
  API_BASE: 'https://synthetic.invalid/api', currentPage: Vue.ref('compass'),
  user: Vue.ref({ id: 901, is_super_admin: true, capabilities: ['all'] }),
  token: Vue.ref('round101-synthetic-session-not-a-credential'), authSessionEpoch: 1, pageRequestGeneration: 1,
  authContext: Vue.ref({ tenantId: '70', hotelId: '7', platform: 'ctrip', tokenStatus: 'valid', permissionStatus: 'allowed' }),
  permittedHotels: Vue.ref([{ id: 7, tenant_id: 70, name: 'Synthetic hotel' }]), hotels: Vue.ref([{ id: 7, tenant_id: 70, name: 'Synthetic hotel' }]),
  filterReportHotel: Vue.ref('7'), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'), operationYesterday: '2026-09-19',
  isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
  expandedMenus: Vue.ref([]), sidebarCollapsed: false, agentTab: Vue.ref('overview'), revenueAgentTab: Vue.ref('analysis'), onlineDataTab: Vue.ref('data-health'), pendingOnlineDataEntryTab: '',
  aiModelConfigText: key => key, showToast: (message, type = 'success') => notices.push({ message, type }), knowledgeCenterDomainRevision: Vue.ref(0),
  fetch: (url, options) => new Promise((resolve, reject) => {
    const parsed = new URL(url), method = options.method || 'GET';
    assert.equal(parsed.origin, 'https://synthetic.invalid');
    assert.equal(new Headers(options.headers).get('Authorization'), sandbox.token.value);
    assert.ok(method === 'GET' && ['/api/knowledge/list', '/api/knowledge/101', '/api/knowledge/102'].includes(parsed.pathname)
      || method === 'POST' && parsed.pathname === '/api/knowledge/101/add-chunk');
    const call = { url, method, body: options.body ? JSON.parse(options.body) : null, resolve, reject, settled: false,
      has_abort_signal: !!options.signal, aborted: false, response: null }; requests.push(call);
    const abort = () => { if (!call.settled) { call.settled = true; call.aborted = true; reject(new DOMException('Original request aborted', 'AbortError')); } };
    call.removeAbort = () => options.signal?.removeEventListener('abort', abort);
    if (options.signal?.aborted) abort(); else options.signal?.addEventListener('abort', abort, { once: true });
  }),
};
vm.createContext(sandbox); vm.runInContext(read('public/system-static.js') + '\n' + rawDomain, sandbox);
sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name]; sandbox.testIdNameMap = sandbox.appSystemStatic.testIdNameMap;
sandbox.formatKnowledgeJson = sandbox.appSystemStatic.formatKnowledgeJson;
const refNames = [...parts.refs.matchAll(/const (\w+) =/g)].map(match => match[1]);
vm.runInContext(Object.values(parts).filter(part => part !== parts.delegate).join('\n')
  + '\nglobalThis.initial={request,buildLeanNavigationItems,captureAuthSession,isAuthSessionCurrent,canonicalAiGovernanceJson,sameAiGovernanceJson,'
  + refNames.join(',') + ',' + navNames.join(',') + ',' + displayNames.join(',') + '};', sandbox);
const initial = sandbox.initial;
const domain = sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({ ...sandbox, ...initial, computed: Vue.computed,
  request: initial.request, requireSystemStatic: name => sandbox.appSystemStatic[name] });
sandbox.createKnowledgeCenterDomain = () => domain;
vm.runInContext(parts.delegate + '\nglobalThis.delegates={loadKnowledgeCenter,openKnowledgeChunks,saveKnowledgeChunk};', sandbox);
const menus = initial.buildLeanNavigationItems(sandbox.appSystemStatic.filterVisibleMenuItems(sandbox.appSystemStatic.resolveMenuItems(sandbox.appSystemStatic.menuItemDefinitions, {}), sandbox.user.value));
const state = Vue.proxyRefs({ ...sandbox, ...initial, ...domain, ...sandbox.delegates, visibleMenuItems: menus });
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const nodeText = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(nodeText).join('') : node?.children ? nodeText(node.children) : '';
const inspect = async (surface = 'page') => {
  let tree; const app = Vue.createSSRApp({ render() { tree = (surface === 'nav' ? renderNav : surface === 'modal' ? renderModal : renderPage)(state, []); return tree; } });
  app.config.errorHandler = error => { runtimeErrors.push(error.message); throw error; };
  const html = await renderToString(app), all = [];
  const walk = (node, parents = []) => { if (Array.isArray(node)) return node.forEach(child => walk(child, parents));
    if (!node || typeof node !== 'object') return; all.push({ node, parents }); walk(node.children, [...parents, node]); };
  walk(tree); return { surface, html, entries: all, nodes: all.map(entry => entry.node) };
};
const allowed = (view, node) => {
  const entry = view.entries.find(entry => entry.node === node); if (!entry) return false;
  // The full fixed modal overlay blocks the underlying list. Never dispatch
  // list or navigation events while it is open, even though SSR keeps that DOM.
  if (state.showKnowledgeCenterChunksModal && view.surface !== 'modal') return false;
  return !node.props?.disabled && ![...entry.parents, node].some(parent => parent.props?.inert || parent.props?.style?.display === 'none')
    && !entry.parents.some(parent => parent.type === 'fieldset' && parent.props?.disabled);
};
const click = async (surface, predicate) => { const view = await inspect(surface), node = view.nodes.find(predicate);
  assert.ok(node && allowed(view, node), 'Original control must be visible, enabled and not covered by the modal');
  const pending = node.props.onClick({ stopPropagation() {}, preventDefault() {} }); if (pending?.then) inFlight.push(pending);
  await tick(); return { pending }; };
const pendingCall = (pathname, method = 'GET') => { const call = requests.findLast(call => !call.settled && call.method === method && new URL(call.url).pathname === pathname); assert.ok(call, pathname); return call; };
const reply = (call, body, status = 200) => { assert.ok(!call.settled); call.settled = true; call.removeAbort(); call.response = clone(body);
  call.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
const openUnit = async (unitId, chunks = []) => {
  const view = await inspect('page'); const node = view.nodes.find(node => node.type === 'button' && node.props?.title === '查看片段'
    && view.entries.find(entry => entry.node === node).parents.some(parent => parent.type === 'tr' && parent.key === unitId));
  assert.ok(node && allowed(view, node)); const pending = node.props.onClick(); inFlight.push(pending); await tick();
  reply(pendingCall(`/api/knowledge/${unitId}`), { code: 0, data: { unit: units.find(unit => unit.unit_id === unitId), chunks } }); await pending; await tick();
};
const openA = chunks => openUnit(101, chunks);
const fill = async text => {
  const view = await inspect('modal'), node = view.nodes.find(node => node.type === 'textarea'
    && node.props?.['onUpdate:modelValue']?.toString().includes('knowledgeCenterChunkForm.content'));
  assert.ok(node && allowed(view, node));
  const listeners = {}, element = { type: 'textarea', value: JSON.stringify({ text, source_verification_status: 'unverified', evidence_level: 'reference_only' }), composing: false,
    addEventListener: (name, callback) => listeners[name] = callback };
  Vue.vModelText.created(element, { modifiers: {} }, node); listeners.input({ target: element }); await tick();
};
const fillType = async value => {
  const view = await inspect('modal'), node = view.nodes.find(node => node.type === 'input'
    && node.props?.['onUpdate:modelValue']?.toString().includes('knowledgeCenterChunkForm.type'));
  assert.ok(node && allowed(view, node));
  const listeners = {}, element = { type: 'text', value, composing: false, addEventListener: (name, callback) => listeners[name] = callback };
  Vue.vModelText.created(element, { modifiers: {} }, node); listeners.input({ target: element }); await tick();
};
const save = async () => {
  const view = await inspect('modal'), form = view.nodes.find(node => node.type === 'form' && node.props?.onSubmit),
    button = view.nodes.find(node => node.type === 'button' && node.props?.type === 'submit');
  assert.ok(form && button && allowed(view, form) && allowed(view, button));
  const pending = form.props.onSubmit({ preventDefault() {}, stopPropagation() {} }); inFlight.push(pending); await tick();
  return { pending, post: pendingCall('/api/knowledge/101/add-chunk', 'POST') };
};
const close = async () => {
  const view = await inspect('modal'), button = view.nodes.find(node => node.type === 'button'
    && node.props?.onClick?.toString().includes('showKnowledgeCenterChunksModal = false'));
  assert.ok(button && allowed(view, button), 'Original modal close is enabled during pending save');
  button.props.onClick(); await tick(); assert.equal(state.showKnowledgeCenterChunksModal, false);
  assert.equal((await inspect('modal')).nodes.some(node => typeof node.type === 'string'), false);
};

const initialize = async () => {
  await click('nav', node => node.type === 'a' && node.props?.['aria-label'] === '系统与工具');
  await click('nav', node => node.type === 'a' && node.props?.['data-testid'] === 'nav-knowledge-center');
  assert.equal(state.currentPage, 'knowledge-center');
  const flow = await click('page', node => node.type === 'button' && node.props?.onClick?.toString().includes('loadKnowledgeCenter'));
  reply(pendingCall('/api/knowledge/list'), listBody); await flow.pending; await tick();
  await openA();
};
const beginSave = async () => {
  await fill('Synthetic accepted reference note');
  const view = await inspect('page');
  const behind = view.nodes.find(node => node.type === 'button' && node.props?.title === '查看片段');
  assert.ok(behind && !allowed(view, behind), 'Real modal overlay blocks list actions');
  const result = await save();
  assert.deepEqual(clone(result.post.body), savedFixture.input_body);
  const query = new URL(result.post.url).searchParams;
  assert.equal(query.get('hotel_id'), '7'); assert.equal(query.get('platform'), 'all_ota'); assert.equal(query.get('as_of'), '2026-09-20');
  assert.equal(state.knowledgeCenterChunkForm.saving, true);
  return result;
};
const succeed = async post => { reply(post, savedFixture.receipt); await tick(); };
const completeRefresh = async pending => {
  reply(pendingCall('/api/knowledge/101'), { code: 0, data: { unit: units[0], chunks: [savedFixture.receipt.data.chunk] } }); await tick();
  reply(pendingCall('/api/knowledge/list'), listBody); await pending; await tick();
};
const assertExactVisibleChunk = async () => {
  assert.equal(state.showKnowledgeCenterChunksModal, true);
  assert.equal(state.knowledgeCenterSelectedUnit.unit_id, 101);
  assert.deepEqual(clone(state.knowledgeCenterChunks), [savedFixture.receipt.data.chunk]);
  const view = await inspect('modal'); assert.ok(view.html.includes('Synthetic accepted reference note'));
  assert.ok(view.html.includes('Synthetic unit A'));
};
const verifyClosedRequests = (expectedGets, expectedErrors = 0, expectedPosts = 1) => {
  assert.deepEqual(runtimeErrors, []); assert.equal(diagnostics.length, expectedErrors);
  assert.ok(requests.every(call => call.settled && !call.aborted));
  assert.ok(requests.filter(call => call.method === 'GET').every(call => call.has_abort_signal));
  assert.ok(requests.filter(call => call.method === 'POST').every(call => !call.has_abort_signal));
  assert.equal(requests.filter(call => call.method === 'POST').length, expectedPosts);
  assert.equal(requests.filter(call => call.method === 'GET').length, expectedGets);
};
t.after(async () => {
  // Settle any real original request even if an earlier assertion fails. Never
  // bypass the coordinator or invent another UI request for cleanup.
  for (let wave = 0; wave < 12; wave++) {
    for (const call of requests.filter(call => !call.settled)) {
      if (call.method === 'POST') reply(call, { code: 500, message: 'Synthetic teardown only' }, 500);
      else reply(call, new URL(call.url).pathname === '/api/knowledge/list' ? listBody
        : { code: 0, data: { unit: units[0], chunks: [savedFixture.receipt.data.chunk] } });
    }
    await tick();
  }
  await Promise.allSettled(inFlight);
  assert.ok(requests.every(call => call.settled && !call.aborted));
  assert.deepEqual(runtimeErrors, []);
  t.diagnostic(JSON.stringify({ posts: requests.filter(call => call.method === 'POST').length,
    gets: requests.filter(call => call.method === 'GET').length, all_closed: true, runtime_errors: runtimeErrors.length }));
});
const expireSyntheticSession = () => { const captured = initial.captureAuthSession(); sandbox.authSessionEpoch++;
  assert.equal(initial.isAuthSessionCurrent(captured), false); };
return { state, requests, notices, diagnostics, initialize, beginSave, close, succeed, completeRefresh, openA, openUnit, fill, fillType, save, expireSyntheticSession, inspect, tick,
  pendingCall, reply, assertExactVisibleChunk, verifyClosedRequests };
}

test('closed chunk modal stays closed after exact save success; explicit reopen reads the saved chunk without another POST', async t => {
  const h = harness(t); await h.initialize(); const flow = await h.beginSave();
  const submittedForm = h.state.knowledgeCenterChunkForm;
  await h.close(); await h.succeed(flow.post);
  const remainedClosedWhenReceiptArrived = h.state.showKnowledgeCenterChunksModal === false;
  const unexpectedRefresh = h.requests.find(call => !call.settled && new URL(call.url).pathname === '/api/knowledge/101');
  // Baseline's spurious refresh is closed before the meaningful red assertion.
  if (unexpectedRefresh) {
    h.reply(unexpectedRefresh, { code: 0, data: { unit: units[0], chunks: [savedFixture.receipt.data.chunk] } }); await h.tick();
  }
  h.reply(h.pendingCall('/api/knowledge/list'), listBody); await flow.pending; await h.tick();
  assert.equal(submittedForm.pending_save, undefined, 'Strict successful receipt consumes the pending save');
  assert.equal(submittedForm.saving, false);
  assert.equal(h.notices.at(-1).message, '片段已保存并精确回读；修改内容保持未核验参考状态');
  assert.equal(h.notices.at(-1).type, 'success');
  assert.equal(remainedClosedWhenReceiptArrived, true, 'Late successful POST must respect the original explicit close');
  assert.equal(h.state.showKnowledgeCenterChunksModal, false);
  assert.equal((await h.inspect('modal')).nodes.some(node => typeof node.type === 'string'), false);
  assert.equal(unexpectedRefresh, undefined, 'Closed modal must not issue a refresh that opens it');
  await h.openA([savedFixture.receipt.data.chunk]); await h.assertExactVisibleChunk();
  h.verifyClosedRequests(4);
});

test('normal open-modal save still performs exact chunk readback and keeps its normalized reference visible', async t => {
  const h = harness(t); await h.initialize(); const flow = await h.beginSave();
  await h.succeed(flow.post);
  assert.equal(h.state.showKnowledgeCenterChunksModal, true); assert.equal(h.state.knowledgeCenterSelectedUnit.chunks_loading, true);
  await h.completeRefresh(flow.pending); await h.assertExactVisibleChunk();
  assert.equal(h.state.knowledgeCenterSelectedUnit.chunks_loading, false);
  assert.equal(h.state.knowledgeCenterChunkForm.pending_save, undefined);
  assert.equal(h.notices.at(-1).type, 'success');
  h.verifyClosedRequests(4);
});

test('closing after save has entered its exact refresh GET also remains closed when detail and list return', async t => {
  const h = harness(t); await h.initialize(); const flow = await h.beginSave();
  await h.succeed(flow.post); h.pendingCall('/api/knowledge/101');
  assert.equal(h.state.knowledgeCenterSelectedUnit.chunks_loading, true);
  await h.close(); await h.completeRefresh(flow.pending);
  assert.equal(h.state.showKnowledgeCenterChunksModal, false);
  assert.equal((await h.inspect('modal')).nodes.some(node => typeof node.type === 'string'), false);
  assert.deepEqual(clone(h.state.knowledgeCenterChunks), [savedFixture.receipt.data.chunk]);
  assert.equal(h.notices.at(-1).type, 'success');
  h.verifyClosedRequests(4);
});

test('closed-modal HTTP500 retains draft and unknown-save pending state and releases busy without any refresh', async t => {
  const h = harness(t); await h.initialize(); const flow = await h.beginSave();
  const form = h.state.knowledgeCenterChunkForm, content = form.content, pending = form.pending_save;
  await h.close(); const failureMessage = 'Synthetic save transport unavailable';
  h.reply(flow.post, { code: 500, message: failureMessage, data: null }, 500); await flow.pending; await h.tick();
  assert.equal(h.state.showKnowledgeCenterChunksModal, false);
  assert.equal(h.state.knowledgeCenterChunkForm, form); assert.equal(form.content, content);
  assert.equal(form.pending_save, pending); assert.equal(form.saving, false);
  assert.equal(h.notices.at(-1).type, 'error'); assert.ok(h.notices.at(-1).message.includes('保存结果待确认'));
  assert.ok(h.diagnostics[0].includes(failureMessage)); h.verifyClosedRequests(2, 1);
});

const makeClosedSavedNewerDraft = async h => {
  await h.initialize(); const flow = await h.beginSave();
  const form = h.state.knowledgeCenterChunkForm;
  await h.fill('Synthetic newer draft B'); await h.fillType('Synthetic recovered type B');
  const draft = { type: form.type, content: form.content };
  assert.deepEqual(flow.post.body, savedFixture.input_body, 'POST retains A while original fields accept B');
  await h.close(); await h.succeed(flow.post);
  const remainedClosed = h.state.showKnowledgeCenterChunksModal === false;
  const baselineRefresh = h.requests.find(call => !call.settled && new URL(call.url).pathname === '/api/knowledge/101');
  if (baselineRefresh) {
    h.reply(baselineRefresh, { code: 0, data: { unit: units[0], chunks: [savedFixture.receipt.data.chunk] } }); await h.tick();
  }
  h.reply(h.pendingCall('/api/knowledge/list'), listBody); await flow.pending; await h.tick();
  assert.equal(form.pending_save, undefined); assert.equal(form.saving, false);
  assert.equal(form.replaces_chunk_id, 1001); assert.equal(form.expected_digest, savedFixture.receipt.data.chunk.revision_digest);
  assert.equal(form.type, draft.type); assert.equal(form.content, draft.content);
  assert.equal(h.notices.at(-1).message, '提交的片段已保存并精确回读；新修改已保留，尚未保存，请再次保存');
  assert.equal(h.requests.filter(call => call.method === 'POST').length, 1, 'No automatic resubmission of B');
  // Keep the same final test runnable on both baseline and candidate: baseline
  // reopened itself, so use the real close again before the real explicit open.
  if (h.state.showKnowledgeCenterChunksModal) await h.close();
  return { form, draft, remainedClosed };
};

test('new draft entered during A save survives a closed success and one explicit same-unit reopen, then can be submitted', async t => {
  const h = harness(t), { form, draft, remainedClosed } = await makeClosedSavedNewerDraft(h);
  await h.openA([savedFixture.receipt.data.chunk]);
  assert.equal(h.state.knowledgeCenterChunkForm, form, 'Explicit same-unit reopen must recover the exact newer draft form');
  assert.equal(form.type, draft.type); assert.equal(form.content, draft.content);
  assert.equal(form.replaces_chunk_id, 1001); assert.equal(form.expected_digest, savedFixture.receipt.data.chunk.revision_digest);
  const view = await h.inspect('modal');
  assert.ok(view.html.includes('Synthetic newer draft B')); assert.ok(view.html.includes('Synthetic recovered type B'));
  assert.equal(remainedClosed, true, 'Recovery must wait for explicit open, never auto-reopen');
  assert.ok(Object.values(form).every(value => typeof value !== 'function'), 'Recovery ownership is private, not serialized on form');
  const next = await h.save();
  assert.equal(next.post.body.request_id, 'round101-synthetic-request-3');
  assert.notEqual(next.post.body.request_id, savedFixture.input_body.request_id);
  assert.equal(next.post.body.type, draft.type); assert.deepEqual(next.post.body.content, JSON.parse(draft.content));
  assert.equal(next.post.body.replaces_chunk_id, 1001); assert.equal(next.post.body.expected_digest, savedFixture.receipt.data.chunk.revision_digest);
  // A controlled transport failure closes B's explicit POST without inventing
  // an unvalidated second-success DTO or claiming B has persisted.
  h.reply(next.post, { code: 500, message: 'Synthetic explicit B submission unavailable', data: null }, 500);
  await next.pending; await h.tick(); assert.equal(form.saving, false); assert.equal(form.content, draft.content);
  h.verifyClosedRequests(4, 1, 2);
});

test('closed-success draft recovery does not cross units and is consumed by the first explicit open', async t => {
  const h = harness(t), { form, draft, remainedClosed } = await makeClosedSavedNewerDraft(h);
  await h.openUnit(102);
  assert.equal(h.state.knowledgeCenterSelectedUnit.unit_id, 102);
  assert.notEqual(h.state.knowledgeCenterChunkForm, form); assert.notEqual(h.state.knowledgeCenterChunkForm.content, draft.content);
  assert.equal(h.state.knowledgeCenterChunkForm.replaces_chunk_id, undefined);
  await h.close(); await h.openA([savedFixture.receipt.data.chunk]);
  assert.notEqual(h.state.knowledgeCenterChunkForm, form); assert.notEqual(h.state.knowledgeCenterChunkForm.content, draft.content);
  assert.equal(h.state.knowledgeCenterChunkForm.replaces_chunk_id, undefined);
  h.verifyClosedRequests(5 + (remainedClosed ? 0 : 1));
});

test('same-unit recovery rejects a changed synthetic auth session before opening without serializing session material', async t => {
  const h = harness(t), { form, draft, remainedClosed } = await makeClosedSavedNewerDraft(h);
  // This is an explicit in-memory session-generation control, not a UI login or
  // authentication bypass. The real capture/isCurrent functions decide validity.
  h.expireSyntheticSession(); await h.openA([savedFixture.receipt.data.chunk]);
  assert.notEqual(h.state.knowledgeCenterChunkForm, form); assert.notEqual(h.state.knowledgeCenterChunkForm.content, draft.content);
  assert.equal(h.state.knowledgeCenterChunkForm.replaces_chunk_id, undefined);
  assert.ok(Object.values(form).every(value => typeof value !== 'function'));
  h.verifyClosedRequests(4 + (remainedClosed ? 0 : 1));
});
