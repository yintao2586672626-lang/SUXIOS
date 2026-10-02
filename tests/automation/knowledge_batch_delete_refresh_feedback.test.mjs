import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const paths = {
  main: 'public/app-main.js', system: 'public/system-static.js',
  domain: 'public/components/system/knowledge-center-domain.js',
  page: 'resources/frontend/templates/fragments/20-page-knowledge-center.html',
  toast: 'resources/frontend/templates/fragments/46-global-toast.html',
};
const raw = Object.fromEntries(Object.entries(paths).map(([k, p]) => [k, fs.readFileSync(p, 'utf8')]));
const normalized = Object.fromEntries(Object.entries(raw).map(([k, value]) => [k, value.replaceAll('\r\n', '\n')]));
const cut = (text, start, end) => {
  const a = text.indexOf(start), b = text.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start); return text.slice(a, b);
};
const snippets = {
  context: cut(normalized.main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  abort: cut(normalized.main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  policy: cut(normalized.main, '            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  coordinator: cut(normalized.main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  request: cut(normalized.main, '            const request = async (', '            const askSystemUsageGuide ='),
  callDomain: cut(normalized.main, '            const callKnowledgeCenterDomain =', '            const knowledgeCenterVisibleChunks ='),
  toast: cut(normalized.main, '            const toast = ref(', '            const createWorkflowFormDialogState ='),
  recovery: cut(normalized.main, '            let runtimeErrorRecoveryQueued =', '            const showAuthNotices ='),
  errorHandler: cut(normalized.main, '        app.config.errorHandler =', '        app.config.globalProperties.aiModelConfigText ='),
};
const entries = [];
const walkAst = (node, ancestors = []) => {
  entries.push({ node, ancestors });
  for (const child of node.children || []) walkAst(child, [...ancestors, node]);
};
walkAst(parse(raw.page));
const rowEntry = entries.find(({ node }) => node.type === 1 && node.tag === 'tr'
  && node.props.some(p => p.name === 'for' && p.exp?.content === 'unit in knowledgeCenterUnits'));
assert.ok(rowEntry);
const compiled = source => new Function('Vue', compile(source, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const rowRender = compiled(rowEntry.node.loc.source);
const toastNodes = [];
const walkToast = node => { toastNodes.push(node); for (const child of node.children || []) walkToast(child); };
walkToast(parse(raw.toast));
const toastNode = toastNodes.find(n => n.type === 1 && n.props.some(p => p.name === 'if' && p.exp?.content === 'toast.show'));
assert.ok(toastNode);
const toastRender = compiled(toastNode.loc.source);
const nodesOf = root => {
  const nodes = []; const walk = n => { if (Array.isArray(n)) return n.forEach(walk);
    if (!n || typeof n !== 'object') return; nodes.push(n); walk(n.children); };
  walk(root); return nodes;
};
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const selectNode = entries.find(({ node }) => node.type === 1 && node.tag === 'input'
  && node.props.some(p => p.name === 'on' && p.exp?.content === 'toggleSelectAllKnowledgeCenterUnits($event.target.checked)'))?.node;
const batchNode = entries.find(({ node }) => node.type === 1 && node.tag === 'button'
  && node.props.some(p => p.name === 'on' && p.exp?.content === 'batchDeleteKnowledgeUnits'))?.node;
assert.ok(selectNode && batchNode);
const refreshNode = entries.find(({ node }) => node.type === 1 && node.tag === 'button'
  && node.props.some(p => p.name === 'on' && p.exp?.content === 'loadKnowledgeCenter'))?.node;
assert.ok(refreshNode);
const controlsRender = compiled('<section>' + selectNode.loc.source + batchNode.loc.source + refreshNode.loc.source + '</section>');
const wrappers = ['toggleSelectAllKnowledgeCenterUnits', 'batchDeleteKnowledgeUnits', 'loadKnowledgeCenter'].map(name =>
  normalized.main.match(new RegExp('            const ' + name + ' = [^\\n]+'))[0]).join('\n');
// Closed synthetic transport over canonical current sources; no real DELETE, DB or browser.
for (const mode of ['all-deleted-list-http500', 'all-deleted-list-success', 'partial-delete-list-http500']) test(mode, async t => {
  const requests = [], confirmations = [], notices = [], timers = [];
  let refreshRecovered = false, initialListConfirmed = false;
  const unit = id => ({ unit_id: id, hotel_id: 7, can_edit: true, name: `Synthetic existing knowledge ${id}`,
    description: 'Synthetic active knowledge row', tags: [], status: 'pending', created_at: '2026-09-15 10:00:00' });
  const originalRows = [unit(101), unit(102)], store = new Map(originalRows.map(row => [row.unit_id, clone(row)]));
  const originalPagination = { page: 1, total: 2, page_size: 10, total_page: 1 };
  const sandbox = {
    window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
    ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, Date, queueMicrotask,
    setTimeout: (callback, delay) => { assert.equal(delay, 3000); timers.push({ callback, delay }); return timers.length; }, clearTimeout() {},
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 1, currentPage: Vue.ref('knowledge-center'), filterReportHotel: Vue.ref('7'),
    authContext: Vue.ref({ hotelId: '7', tenantId: '70', platform: 'all', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 901, realname: 'Synthetic user', is_super_admin: true }), token: Vue.ref('synthetic-session-only'),
    permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }]), revenueAiBusinessDate: Vue.ref('2026-09-15'),
    coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: 1, token: 'synthetic-session-only' }), isAuthSessionCurrent: captured => captured.epoch === 1,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    knowledgeCenterDomainRevision: Vue.ref(1),
    knowledgeCenterUnits: Vue.ref(clone(originalRows)), knowledgeCenterLoading: Vue.ref(false), knowledgeCenterListError: Vue.ref(''), knowledgeCenterBatchDeleting: Vue.ref(false),
    knowledgeCenterPagination: Vue.ref(clone(originalPagination)), knowledgeCenterFilter: Vue.ref({}),
    selectedKnowledgeCenterUnitIds: Vue.ref([]), knowledgeCenterSelectedUnit: Vue.ref(null),
    knowledgeCenterChunks: Vue.ref([]), knowledgeCenterChunkForm: Vue.ref({}), showKnowledgeCenterChunksModal: Vue.ref(false),
    confirm: prompt => { confirmations.push(prompt); return true; },
    scheduleSuxiStartupError: () => { throw new Error('No fatal error expected'); }, recoverSuxiRuntimeError: null,
    loadKnowledgeCenterDomain: () => { throw new Error('Already-loaded domain expected'); },
    reportKnowledgeCenterDomainLoadError: () => { throw new Error('No lazy load expected'); },
    fetch: async (url, options) => {
      assert.ok(url.startsWith('https://synthetic.invalid/api/knowledge/'));
      // Synthetic session premise only; no real credential read or header recorded.
      assert.equal(new Headers(options.headers).get('Authorization'), sandbox.token.value);
      const parsed = new URL(url), call = { url, method: options.method || 'GET' }; requests.push(call);
      let body, status = 200;
      if (call.method === 'DELETE') {
        const id = Number(parsed.pathname.match(/^\/api\/knowledge\/(101|102)$/)?.[1]); assert.ok(id);
        if (mode === 'partial-delete-list-http500' && id === 102) {
          status = 500; body = { code: 500, data: null, msg: 'Synthetic unit 102 deletion unavailable' };
        } else {
          assert.ok(store.has(id)); store.delete(id);
          // Exact public success envelope of the active Knowledge::delete -> ok contract.
          body = { code: 0, data: { unit_id: id }, msg: 'deleted' };
        }
      } else {
        assert.equal(call.method, 'GET'); assert.equal(parsed.pathname, '/api/knowledge/list');
        assert.equal(parsed.searchParams.get('page'), '1'); assert.equal(parsed.searchParams.get('page_size'), '10');
        assert.equal(parsed.searchParams.has('hotel_id'), false, 'Keep the original list scope; do not invent a hotel query');
        assert.equal(sandbox.selectedKnowledgeCenterUnitIds.value.length, 0, 'Batch clears selection before refresh');
        if (!initialListConfirmed) {
          initialListConfirmed = true;
          body = { code: 0, data: { list: clone(originalRows), pagination: clone(originalPagination) }, msg: '' };
        } else if (mode === 'all-deleted-list-success' || refreshRecovered) {
          body = { code: 0, data: { list: [...store.values()], pagination: { page: 1, total: 0, page_size: 10, total_page: 0 } }, msg: '' };
        } else {
          status = 500; body = { code: 500, data: null, msg: 'Synthetic post-delete list unavailable' };
        }
      }
      call.status = status; call.response = clone(body);
      return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    },
  };
  vm.createContext(sandbox); vm.runInContext(raw.system + '\n' + raw.domain, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  vm.runInContext(['context', 'abort', 'policy', 'coordinator', 'request', 'toast', 'recovery'].map(k => snippets[k]).join('\n')
    + '\nglobalThis.requestForDomain = request; globalThis.showToastForDomain = showToast; globalThis.toastState = toast;', sandbox);
  const stopToastWatch = Vue.watch(sandbox.toastState, value => notices.push(clone(value)), { flush: 'sync' });
  t.after(stopToastWatch);
  const domain = sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({
    ...sandbox, request: sandbox.requestForDomain, showToast: sandbox.showToastForDomain,
    requireSystemStatic: () => ({}), defaultKnowledgeCenterHotelId: () => 7,
    defaultKnowledgeExperienceChunk: () => '{}', formatKnowledgeJson: JSON.stringify,
  });
  sandbox.createKnowledgeCenterDomain = () => domain;
  vm.runInContext(snippets.callDomain + wrappers + '\nglobalThis.adapters = { toggleSelectAllKnowledgeCenterUnits, batchDeleteKnowledgeUnits, loadKnowledgeCenter };', sandbox);
  const state = Vue.proxyRefs({ ...sandbox, ...sandbox.adapters, toast: sandbox.toastState,
    isAllKnowledgeCenterPageSelected: domain.isAllKnowledgeCenterPageSelected,
    knowledgeCenterTagGroups: () => ({ business: [], boundary: [] }), getHotelNameById: () => 'Synthetic hotel',
    knowledgeCenterStatusClass: () => '', knowledgeCenterStatusLabel: status => status,
    openKnowledgeChunks() {}, refreshKnowledgeUnit() {}, deleteKnowledgeUnit() {},
  });
  const render = async renderer => {
    let tree, instance;
    const app = Vue.createSSRApp({ setup() { instance = Vue.getCurrentInstance(); return {}; }, render() { tree = renderer(state, []); return tree; } });
    app.config.warnHandler = () => {}; sandbox.app = app; vm.runInContext(snippets.errorHandler, sandbox);
    const html = await renderToString(app); return { html, nodes: nodesOf(tree), instance };
  };
  assert.equal(await domain.loadKnowledgeCenter(), true, 'Establish a confirmed same-scope snapshot through the actual list loader');
  assert.deepEqual(clone(sandbox.knowledgeCenterUnits.value), originalRows);
  const beforeRows = (await render(rowRender)).html;
  let controls = await render(controlsRender);
  assert.equal(controls.nodes.find(n => n.type === 'button').props.disabled, true);
  const checkbox = controls.nodes.find(n => n.type === 'input' && n.props?.type === 'checkbox');
  assert.ok(checkbox && !checkbox.props.disabled);
  Vue.callWithAsyncErrorHandling(checkbox.props.onChange, controls.instance, 5, [{ target: { checked: true } }]);
  await tick();
  assert.deepEqual(clone(sandbox.selectedKnowledgeCenterUnitIds.value), ['101', '102']);
  controls = await render(controlsRender);
  const button = controls.nodes.find(n => n.type === 'button'); assert.ok(button && !button.props.disabled);
  await Vue.callWithAsyncErrorHandling(button.props.onClick, controls.instance, 5, [{ type: 'click' }]);
  await tick();
  assert.equal(confirmations.length, 1); assert.ok(confirmations[0].includes('2 条知识'));
  assert.deepEqual(requests.map(r => r.method), ['GET', 'DELETE', 'DELETE', 'GET']);
  assert.equal(sandbox.knowledgeCenterBatchDeleting.value, false); assert.equal(sandbox.knowledgeCenterLoading.value, false);
  assert.deepEqual(clone(sandbox.selectedKnowledgeCenterUnitIds.value), []);
  const finalToast = clone(sandbox.toastState.value), toastHtml = (await render(toastRender)).html;
  const afterRows = (await render(rowRender)).html;
  assert.ok(toastHtml.includes(finalToast.message));
  if (mode === 'all-deleted-list-success') {
    assert.equal(store.size, 0); assert.deepEqual(clone(sandbox.knowledgeCenterUnits.value), []);
    assert.equal(afterRows.includes('Synthetic existing knowledge'), false);
    assert.equal(notices.length, 1); assert.equal(finalToast.type, 'success');
    assert.equal(finalToast.message, '已删除 2 条知识');
  } else {
    assert.deepEqual(clone(sandbox.knowledgeCenterUnits.value), originalRows);
    assert.deepEqual(clone(sandbox.knowledgeCenterPagination.value), originalPagination);
    assert.equal(afterRows, beforeRows);
    assert.equal(notices.length, 2);
    assert.equal(notices[0].type, 'error'); assert.equal(notices[0].message, 'Synthetic post-delete list unavailable');
    assert.equal(finalToast.type, 'error', 'Refresh failure cannot finish as a plain success');
    assert.match(finalToast.message, /本次列表刷新未确认，请刷新确认/);
    if (mode === 'partial-delete-list-http500') {
      assert.deepEqual([...store.keys()], [102]);
      assert.match(finalToast.message, /已确认删除 1 条知识/);
      assert.match(finalToast.message, /1 条删除失败或未确认/);
      assert.ok(finalToast.message.includes('#102: Synthetic unit 102 deletion unavailable'));
      assert.doesNotMatch(finalToast.message, /未删除|已回滚/);
    } else {
      assert.equal(store.size, 0);
      assert.match(finalToast.message, /已确认删除 2 条知识/);
      assert.doesNotMatch(finalToast.message, /删除失败或未确认/);
      // The user can recover the stale list through the original Refresh control.
      refreshRecovered = true;
      const retryControls = await render(controlsRender);
      const refreshButton = retryControls.nodes.find(n => n.type === 'button' && n.props?.onClick === state.loadKnowledgeCenter);
      assert.ok(refreshButton && !refreshButton.props.disabled);
      const refreshed = await Vue.callWithAsyncErrorHandling(refreshButton.props.onClick, retryControls.instance, 5, [{ type: 'click' }]);
      await tick();
      assert.equal(refreshed, true);
      assert.deepEqual(clone(sandbox.knowledgeCenterUnits.value), []);
      assert.equal((await render(rowRender)).html.includes('Synthetic existing knowledge'), false);
      assert.equal(requests.filter(r => r.method === 'DELETE').length, 2, 'Refreshing never repeats a deletion');
      assert.equal(requests.filter(r => r.method === 'GET').length, 3);
    }
  }
});
