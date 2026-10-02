import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const root = process.env.SUXI_KNOWLEDGE_REVISION_SOURCE_ROOT || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const paths = {
  main: 'public/app-main.js', system: 'public/system-static.js',
  domain: 'public/components/system/knowledge-center-domain.js',
  page: 'resources/frontend/templates/fragments/20-page-knowledge-center.html',
  toast: 'resources/frontend/templates/fragments/46-global-toast.html',
};
const raw = Object.fromEntries(Object.entries(paths).map(([k, p]) => [k, fs.readFileSync(k === 'page' && process.env.SUXI_KNOWLEDGE_REVISION_TEMPLATE_PATH ? process.env.SUXI_KNOWLEDGE_REVISION_TEMPLATE_PATH : path.join(root, p), 'utf8')]));
const normalized = Object.fromEntries(Object.entries(raw).map(([k, value]) => [k, value.replaceAll('\r\n', '\n')]));
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
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
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const decl = (source, name) => {
  const start = source.indexOf(`            const ${name} =`); assert.ok(start >= 0, name);
  const next = /\n {12}(?:const|let) /.exec(source.slice(start + 1)); assert.ok(next, name);
  return source.slice(start, start + 1 + next.index);
};
const treeEntries = source => {
  const found = []; const walk = (node, ancestors = []) => { found.push({ node, ancestors });
    for (const child of node.children || []) walk(child, [...ancestors, node]); };
  walk(parse(source)); return found;
};
const pageEntries = treeEntries(raw.page), shellPath = 'resources/frontend/templates/fragments/00-app-shell.html';
const shell = fs.readFileSync(path.join(root, shellPath), 'utf8');
const sidebarStart = shell.indexOf('<aside '), sidebarEnd = shell.indexOf('</aside>', sidebarStart);
assert.ok(sidebarStart >= 0 && sidebarEnd > sidebarStart);
// 00-app-shell deliberately leaves the main layout open for later fragments.
// Parse its complete original sidebar subtree rather than that incomplete fragment.
const navEntries = treeEntries(shell.slice(sidebarStart, sidebarEnd + '</aside>'.length));
const titleEntry = pageEntries.find(({ node }) => node.type === 1 && node.props.some(p => p.name === 'model' && p.exp?.content === 'knowledgePromotionForm.title'));
assert.ok(titleEntry);
const pageRoot = titleEntry.ancestors.find(n => n.type === 1 && n.props.some(p => p.name === 'if' && p.exp?.content === "currentPage === 'knowledge-center'"));
const workbench = [...titleEntry.ancestors].reverse().find(n => n.type === 1 && n !== pageRoot && n.loc.source.includes('正式知识晋级审核台'));
assert.ok(pageRoot && workbench);

const rootOpen = pageRoot.loc.source.slice(0, pageRoot.loc.source.indexOf('>') + 1);
const compileNode = source => new Function('Vue', compile(source, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const workbenchRender = compileNode(rootOpen + workbench.loc.source + '</div>');
const toastNode = treeEntries(raw.toast).find(({ node }) => node.type === 1 && node.props.some(p => p.name === 'if' && p.exp?.content === 'toast.show'))?.node;
assert.ok(toastNode);
const toastRender = compileNode(toastNode.loc.source);
const navEntry = navEntries.find(({ node }) => node.type === 1 && node.tag === 'a'
  && node.props.some(p => p.name === 'on' && p.arg?.content === 'click' && p.exp?.content === 'handleNestedMenuClick(child, item.name)'));
assert.ok(navEntry);
const navContainer = [...navEntry.ancestors].reverse().find(n => n.type === 1 && n.tag === 'div' && n.props.some(p => p.name === 'show'));
assert.ok(navContainer);
const navRender = compileNode(navContainer.loc.source);
const nodesOf = root => { const nodes = []; const walk = (n, ancestors = []) => { if (Array.isArray(n)) return n.forEach(child => walk(child, ancestors));
  if (!n || typeof n !== 'object') return; nodes.push({ node: n, ancestors }); walk(n.children, [...ancestors, n]); }; walk(root); return nodes; };
const titleA = 'Submitted revision A', titleB = 'New unsaved revision B';
const objectiveA = 'Confirm the same-scope receipt A', objectiveB = 'Later objective B';
const stepsA = 'Read the matching receipt A\nRecord the result A', stepsB = 'Keep later step B';
const service = fs.readFileSync(path.join(root, 'app/service/KnowledgePromotionService.php'), 'utf8');
const sourceRecordType = service.match(/SOURCE_RECORD_TYPE = '([^']+)'/)[1];
const contractVersion = service.match(/CONTRACT_VERSION = '([^']+)'/)[1];
for (const mode of ['draft-success-readback', 'changes-requested-success-readback', 'draft-post-failure']) test(`revision draft fields remain stable until completion: ${mode}`, async () => {
  const requests = [], notices = [], timers = [], runtimeErrors = []; let releasePost, postCompleted = false; const pendingGets = new Map();
  const workflowStatus = mode.startsWith('draft') ? 'draft' : 'changes_requested';
  const revision = { id: 201, candidate_id: 101, revision_no: 1, source_sop_candidate_version_id: 301,
    title: 'Original synthetic SOP', objective: 'Original objective', steps: ['Original step'], stop_conditions: [],
    applicability: { platform: 'ctrip', applicability_profile: {}, action_parameters: [], success_conditions: [], failure_samples: [], evidence_valid_until: null },
    scope: { platform: 'ctrip', source_scope: 'hotel' }, evidence_refs: [], outcome_refs: [], conflict_refs: [],
    source_digest: sha('Synthetic source version 301'), content_digest: sha('Synthetic revision 201'),
    submitted_by: workflowStatus === 'draft' ? null : 901, submitted_at: workflowStatus === 'draft' ? null : '2026-09-15 09:00:00' };
  let events = [{ id: 501, candidate_id: 101, revision_id: 201, event_type: 'candidate_created', from_status: '', to_status: 'draft' }];
  if (workflowStatus === 'changes_requested') events.push(
    { id: 502, candidate_id: 101, revision_id: 201, event_type: 'submitted', from_status: 'draft', to_status: 'in_review' },
    { id: 503, candidate_id: 101, revision_id: 201, event_type: 'changes_requested', from_status: 'in_review', to_status: 'changes_requested' });
  let candidate = { id: 101, tenant_id: 70, hotel_id: 7, candidate_key: 'synthetic-candidate-101', candidate_type: 'operating_sop',
    source_record_type: sourceRecordType, source_record_id: 301, workflow_status: workflowStatus,
    current_revision_id: 201, current_revision_no: 1, row_version: workflowStatus === 'draft' ? 1 : 3,
    event_count: events.length, current_revision: revision, promoted_sop_version_id: null, promoted_knowledge_unit_id: null, promoted_knowledge_chunk_id: null };
  const sourceVersion = { id: 301, tenant_id: 70, hotel_id: 7, title: revision.title, version_no: 1,
    validation_status: 'candidate', lifecycle_status: 'active', content_digest: revision.source_digest,
    source_memory_ids: [701, 702, 703], scope: { platform: 'ctrip', source_scope: 'hotel' } };
  const sandbox = {
    window: { innerWidth: 1200 }, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
    ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, Date, queueMicrotask,
    crypto: { randomUUID: () => '10000000-0000-4000-8000-000000000001' },
    setTimeout: (callback, delay) => { assert.equal(delay, 3000); timers.push({ callback, delay }); return timers.length; }, clearTimeout() {},
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 1,
    currentPage: Vue.ref('compass'), filterReportHotel: Vue.ref('7'),
    authContext: Vue.ref({ hotelId: '7', tenantId: '70', platform: 'all', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 901, realname: 'Synthetic user', is_super_admin: true }), token: Vue.ref(''),
    // This marker is an in-memory fixture, not an account credential; no header is recorded or sent outside this closure.
    captureAuthSession: () => ({ epoch: 1, token: 'synthetic-session-only' }), isAuthSessionCurrent: s => s.epoch === 1,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    permittedHotels: Vue.ref([{ id: 7, name: 'Synthetic hotel 7', tenant_id: 70 }]), hotels: Vue.ref([]),
    revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    knowledgeCenterDomainRevision: Vue.ref(1), knowledgeCenterTargetHotelId: Vue.ref('7'),
    knowledgePromotionHotelId: Vue.ref(''), knowledgePromotionWorkflowStatus: Vue.ref(''),
    knowledgePromotionAction: Vue.ref(''), knowledgePromotionLoading: Vue.ref(false), knowledgePromotionError: Vue.ref(''),
    knowledgePromotionCandidates: Vue.ref([]), knowledgePromotionSourceVersions: Vue.ref([]), knowledgePromotionMemories: Vue.ref([]),
    knowledgePromotionSelectedCandidate: Vue.ref(null), knowledgePromotionEvents: Vue.ref([]), knowledgePromotionForm: Vue.ref({}),
    knowledgeSopCandidateForm: Vue.ref({ title: '', objective: '', steps_text: '', stop_conditions_text: '', source_memory_ids: [] }),
    knowledgeSopCandidateAction: Vue.ref(''), knowledgeSopCandidateError: Vue.ref(''), knowledgeSopCandidateReadback: Vue.ref(null),
    loadKnowledgeCenterDomain: () => { throw new Error('Loaded domain expected'); },
    reportKnowledgeCenterDomainLoadError: () => { throw new Error('No domain load failure expected'); },
    scheduleSuxiStartupError: error => { throw error; }, recoverSuxiRuntimeError: null,
    fetch: async (url, options) => {
      assert.ok(url.startsWith('https://synthetic.invalid/api/'));
      const parsed = new URL(url), call = { url, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null };
      requests.push(call); let data;
      if (call.method === 'POST') {
        assert.equal(parsed.pathname, '/api/knowledge/promotions/101/revisions');
        assert.equal(call.body.title, titleA); assert.equal(call.body.objective, objectiveA); assert.deepEqual(call.body.steps, stepsA.split('\n'));
        return new Promise(resolve => { releasePost = () => {
          postCompleted = true;
          if (mode.endsWith('post-failure')) {
            call.response = { code: 500, message: 'Synthetic revision request failed', data: null };
            resolve(new Response(JSON.stringify(call.response), { status: 500 })); return;
          }
          const nextRevision = { ...clone(candidate.current_revision), id: 202, revision_no: 2, source_sop_candidate_version_id: 302,
            title: call.body.title, objective: call.body.objective, steps: clone(call.body.steps), stop_conditions: clone(call.body.stop_conditions),
            applicability: { platform: 'ctrip', applicability_profile: clone(call.body.applicability_profile), action_parameters: clone(call.body.action_parameters),
              success_conditions: clone(call.body.success_conditions), failure_samples: clone(call.body.failure_samples), evidence_valid_until: call.body.evidence_valid_until || null },
            source_digest: sha('Synthetic active source version 302'), content_digest: sha(JSON.stringify(call.body)), submitted_by: null, submitted_at: null };
          const event = { id: 504, candidate_id: 101, revision_id: 202, event_type: 'revision_created', from_status: candidate.workflow_status, to_status: 'draft' };
          events = [...events, event];
          candidate = { ...candidate, workflow_status: 'draft', current_revision_id: 202, current_revision_no: 2, row_version: candidate.row_version + 1,
            current_revision: nextRevision, event_count: events.length, review_due_at: null };
          call.response = { code: 200, message: '操作成功', data: { candidate: clone(candidate), event: clone(event), created: true,
            operation_status: 'revision_created', persistence_status: 'readback_verified', write_boundaries: {
              contract_version: contractVersion, runtime_json_is_formal_source: false, causality_verified: false, automatic_execution: false,
              ota_write: false, external_message: false, knowledge_write_before_approval: false } } };
          resolve(new Response(JSON.stringify(call.response), { status: 200 }));
        }; });
      }
      assert.equal(call.method, 'GET');
      if (parsed.pathname === '/api/knowledge/promotions') {
        assert.equal(parsed.searchParams.get('hotel_id'), '7'); data = { data_status: 'ok', list: [clone(candidate)] };
      } else if (parsed.pathname === '/api/operation/operating-sops') {
        assert.equal(parsed.searchParams.get('hotel_id'), '7'); data = { data_status: 'ok', list: [clone(sourceVersion)] };
      } else if (parsed.pathname === '/api/operation/operating-memories') {
        assert.equal(parsed.searchParams.get('hotel_id'), '7'); data = { data_status: 'ok', list: [] };
      } else if (parsed.pathname === '/api/knowledge/promotions/101') data = clone(candidate);
      else if (parsed.pathname === '/api/knowledge/promotions/101/events') data = { data_status: 'ok', candidate_id: 101, count: events.length, list: clone(events) };
      else throw new Error('Unsupported synthetic path: ' + parsed.pathname);
      call.response = { code: 200, data };
      if (postCompleted && ['/api/knowledge/promotions/101', '/api/knowledge/promotions/101/events'].includes(parsed.pathname)) {
        return new Promise(resolve => pendingGets.set(parsed.pathname, () => resolve(new Response(JSON.stringify(call.response), { status: 200 }))));
      }
      return new Response(JSON.stringify(call.response), { status: 200 });
    },
  };
  vm.createContext(sandbox); vm.runInContext(raw.system + '\n' + raw.domain, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  const mainNames = ['normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess', 'handleMenuClick', 'handleNestedMenuClick',
    'knowledgeCenterHotelOptions', 'knowledgePromotionSourceCandidates', 'knowledgePromotionEligibleMemories', 'knowledgeSopCandidateEligibleMemories', 'knowledgePromotionApprovalGate', 'knowledgePromotionStats'];
  vm.runInContext(['context', 'abort', 'policy', 'coordinator', 'request', 'toast', 'recovery'].map(k => snippets[k]).join('\n')
    + '\n' + mainNames.map(name => decl(normalized.main, name)).join('\n')
    + '\nglobalThis.contextRefs = { knowledgeCenterHotelOptions, knowledgePromotionSourceCandidates, knowledgePromotionEligibleMemories, knowledgeSopCandidateEligibleMemories, knowledgePromotionApprovalGate, knowledgePromotionStats };'
    + '\nglobalThis.navMethods = { handleMenuClick, handleNestedMenuClick }; globalThis.requestForDomain = request; globalThis.showToastForDomain = showToast; globalThis.toastState = toast;', sandbox);
  const domain = sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({ ...sandbox, ...sandbox.contextRefs,
    request: sandbox.requestForDomain, showToast: sandbox.showToastForDomain, requireSystemStatic: key => sandbox.appSystemStatic[key],
    defaultKnowledgeCenterHotelId: () => 7, defaultKnowledgeExperienceChunk: () => '{}', formatKnowledgeJson: JSON.stringify });
  sandbox.createKnowledgeCenterDomain = () => domain;
  const adapterNames = ['changeKnowledgePromotionHotel', 'loadKnowledgePromotionWorkbench', 'openKnowledgePromotionCandidate', 'saveKnowledgePromotionRevision'];
  vm.runInContext(snippets.callDomain + adapterNames.map(name => decl(normalized.main, name)).join('\n')
    + '\nglobalThis.adapters = { ' + adapterNames.join(', ') + ' };', sandbox);
  const state = Vue.proxyRefs({ ...sandbox, ...sandbox.contextRefs, ...domain, ...sandbox.adapters, ...sandbox.navMethods,
    item: { name: '系统与工具', children: [{ path: 'knowledge-center', name: '知识与经验', icon: 'fas fa-brain' }] },
    sidebarCollapsed: false, expandedMenus: ['系统与工具'], expandedSubmenus: [],
    menuTestId: item => 'menu-' + item.path, isSidebarMenuItemActive: item => sandbox.currentPage.value === item.path,
    getMenuItemName: item => item.name, toggleNestedSubmenu() {}, toast: sandbox.toastState });
  const stopWatch = Vue.watch(sandbox.toastState, value => notices.push(clone(value)), { flush: 'sync' });
  const render = async renderer => {
    let tree, instance; const app = Vue.createSSRApp({ setup() { instance = Vue.getCurrentInstance(); return {}; }, render() { tree = renderer(state, []); return tree; } });
    app.config.warnHandler = () => {}; sandbox.app = app; vm.runInContext(snippets.errorHandler, sandbox);
    const originalErrorHandler = app.config.errorHandler;
    app.config.errorHandler = (...args) => { runtimeErrors.push(String(args[0]?.message || args[0])); return originalErrorHandler(...args); };
    const html = await renderToString(app), entries = nodesOf(tree); return { html, entries, nodes: entries.map(entry => entry.node), instance };
  };
  const nav = await render(navRender), link = nav.nodes.find(n => n.type === 'a' && n.props?.['data-testid'] === 'menu-knowledge-center');
  assert.ok(link);
  Vue.callWithAsyncErrorHandling(link.props.onClick, nav.instance, 5, [{ stopPropagation() {} }]); await tick();
  assert.equal(sandbox.currentPage.value, 'knowledge-center');
  let view = await render(workbenchRender);
  const hotelSelect = view.nodes.find(n => n.type === 'select' && n.props?.['aria-label'] === '知识晋级门店'); assert.ok(hotelSelect && !hotelSelect.props.disabled);
  hotelSelect.props['onUpdate:modelValue']('7');
  Vue.callWithAsyncErrorHandling(hotelSelect.props.onChange, view.instance, 5, [{ target: { value: '7' } }]); await tick(); await tick();
  assert.equal(sandbox.knowledgePromotionCandidates.value.length, 1);
  view = await render(workbenchRender);
  const candidateButton = view.nodes.find(n => n.type === 'button' && n.key === 'promotion-candidate-101'); assert.ok(candidateButton && !candidateButton.props.disabled);
  await Vue.callWithAsyncErrorHandling(candidateButton.props.onClick, view.instance, 5, [{ type: 'click' }]); await tick();
  assert.equal(sandbox.knowledgePromotionSelectedCandidate.value.id, 101);
  const revisionNode = titleEntry.ancestors.find(n => n.type === 1 && n.props.some(p => p.name === 'if' && p.exp?.content === "['draft', 'changes_requested'].includes(knowledgePromotionSelectedCandidate.workflow_status)"));
  const controls = pageEntries.filter(({ node, ancestors }) => node.type === 1 && ['input', 'textarea'].includes(node.tag)
    && (ancestors.includes(revisionNode) || node.props.some(p => p.name === 'model' && ['knowledgePromotionForm.note', 'knowledgePromotionForm.review_due_at'].includes(p.exp?.content))))
    .map(({ node }) => {
      const model = node.props.find(p => p.name === 'model').exp.content.split('.').at(-1);
      const attributes = Object.fromEntries(node.props.filter(p => p.type === 6).map(p => [p.name, p.value?.content || '']));
      return { model, tag: node.tag, attributes };
    });
  assert.equal(controls.length, 18, 'All 16 revision fields plus shared note and due date are represented');
  const findControl = (current, descriptor) => current.entries.find(({ node }) => node.type === descriptor.tag
    && (descriptor.attributes.placeholder ? node.props?.placeholder === descriptor.attributes.placeholder
      : descriptor.attributes['aria-label'] ? node.props?.['aria-label'] === descriptor.attributes['aria-label']
        : node.props?.type === descriptor.attributes.type));
  const disabled = entry => !!entry.node.props?.disabled || entry.ancestors.some(n => n.type === 'fieldset' && !!n.props?.disabled);
  const openedDetails = new Set();
  const visible = entry => {
    if ([...entry.ancestors, entry.node].some(node => node.props?.hidden || node.props?.inert
      || node.props?.style?.display === 'none' || typeof node.props?.style === 'string' && /display\s*:\s*none/.test(node.props.style))) return false;
    for (const [index, ancestor] of entry.ancestors.entries()) {
      if (ancestor.type !== 'details' || ancestor.props?.open === true || ancestor.props?.open === ''
        || openedDetails.has(ancestor.props?.['data-testid'])) continue;
      // Only the first direct summary remains visible in a closed native details element.
      const child = entry.ancestors[index + 1] || entry.node;
      const firstSummary = Array.isArray(ancestor.children) && ancestor.children.find(node => node?.type === 'summary');
      if (child !== firstSummary) return false;
    }
    return true;
  };
  const draftValue = (model, variant) => {
    if (model === 'title') return variant === 'A' ? titleA : titleB;
    if (model === 'objective') return variant === 'A' ? objectiveA : objectiveB;
    if (model === 'steps_text') return variant === 'A' ? stepsA : stepsB;
    if (model === 'note') return variant === 'A' ? 'Submitted revision note A' : 'Later unsaved note B';
    if (model === 'review_due_at') return variant === 'A' ? '2026-09-22T10:00' : '2026-09-23T12:00';
    if (model === 'evidence_valid_until') return variant === 'A' ? '2026-12-31' : '2027-01-31';
    return `${model} synthetic ${variant}`;
  };
  let dispatchedInputEvents = 0;
  const editControl = (entry, value) => {
    if (!visible(entry) || disabled(entry) || entry.node.props?.readonly) return false;
    assert.equal(typeof entry.node.props['onUpdate:modelValue'], 'function');
    entry.node.props['onUpdate:modelValue'](value); dispatchedInputEvents += 1; return true;
  };
  const editDraft = async variant => {
    const current = await render(workbenchRender);
    for (const descriptor of controls) {
      const entry = findControl(current, descriptor);
      if (descriptor.model === 'review_due_at' && sandbox.knowledgePromotionSelectedCandidate.value.workflow_status !== 'draft') {
        assert.equal(entry, undefined); continue;
      }
      assert.ok(entry, descriptor.model);
      assert.equal(visible(entry), true, `${descriptor.model} must be visible before dispatching an input update`);
      assert.equal(editControl(entry, draftValue(descriptor.model, variant)), true, `${descriptor.model} must be editable after completion`);
    }
    await tick();
  };
  const assertLockedWithoutDispatch = async () => {
    const current = await render(workbenchRender), before = clone(sandbox.knowledgePromotionForm.value), dispatchedBefore = dispatchedInputEvents;
    assert.equal(sandbox.knowledgePromotionAction.value, 'revision');
    assert.equal(sandbox.knowledgePromotionHotelId.value, '7');
    assert.equal(sandbox.knowledgePromotionSelectedCandidate.value.id, 101);
    for (const descriptor of controls) {
      const entry = findControl(current, descriptor);
      if (descriptor.model === 'review_due_at' && sandbox.knowledgePromotionSelectedCandidate.value.workflow_status !== 'draft') {
        assert.equal(entry, undefined); continue;
      }
      assert.ok(entry, descriptor.model);
      assert.equal(visible(entry), true, `${descriptor.model} remains visible in the expanded editor`);
      assert.equal(disabled(entry), true, `${descriptor.model} must stay locked until exact candidate and event GETs finish`);
      assert.equal(editControl(entry, draftValue(descriptor.model, 'B')), false);
    }
    assert.equal(dispatchedInputEvents, dispatchedBefore, 'Disabled controls must not dispatch model updates');
    assert.deepEqual(clone(sandbox.knowledgePromotionForm.value), before);
  };
  const collapsed = await render(workbenchRender);
  const details = collapsed.nodes.find(node => node.type === 'details' && node.props?.['data-testid'] === 'knowledge-promotion-applicability-editor');
  assert.ok(details); assert.equal(!!details.props.open, false);
  const summary = collapsed.entries.find(entry => entry.node.type === 'summary' && entry.ancestors.at(-1) === details);
  assert.ok(summary && visible(summary) && !disabled(summary));
  assert.match(String(summary.node.children), /复制适用画像、动作参数与证据有效期/);
  assert.equal(summary.node.props?.onClick, undefined, 'The original summary uses native details behavior');
  const profileControl = findControl(collapsed, controls.find(descriptor => descriptor.model === 'hotel_type_and_scale_text'));
  assert.equal(visible(profileControl), false, 'The original applicability fields start folded');
  const beforeHiddenAttempt = dispatchedInputEvents;
  assert.equal(editControl(profileControl, 'Must not enter a hidden field'), false);
  assert.equal(dispatchedInputEvents, beforeHiddenAttempt, 'Hidden fields dispatch no model update');
  // Model the ordinary browser default action of this original summary.
  // This is an in-memory visibility model, not a native-browser click assertion.
  openedDetails.add(details.props['data-testid']);
  await tick();
  const expanded = await render(workbenchRender);
  assert.equal(visible(findControl(expanded, controls.find(descriptor => descriptor.model === 'hotel_type_and_scale_text'))), true);
  await editDraft('A'); view = await render(workbenchRender);
  const save = view.nodes.find(n => n.type === 'button' && String(n.children).includes('保存新修订并独立回读')); assert.ok(save && !save.props.disabled);
  const pending = Vue.callWithAsyncErrorHandling(save.props.onClick, view.instance, 5, [{ type: 'click' }]); await tick();
  assert.equal(sandbox.knowledgePromotionAction.value, 'revision'); assert.equal(typeof releasePost, 'function');
  view = await render(workbenchRender);
  assert.equal(view.nodes.find(n => n.type === 'button' && String(n.children).includes('保存新修订并独立回读')).props.disabled, true);
  const pendingWorkbenchHtml = view.html;
  const submittedDraft = clone(sandbox.knowledgePromotionForm.value);
  await assertLockedWithoutDispatch();
  releasePost(); await tick(); await tick();
  if (!mode.endsWith('post-failure')) {
    assert.equal(pendingGets.size, 2, 'Both exact readbacks must start after POST');
    await assertLockedWithoutDispatch();
    pendingGets.get('/api/knowledge/promotions/101')(); await tick();
    await assertLockedWithoutDispatch();
    pendingGets.get('/api/knowledge/promotions/101/events')();
  }
  await pending; await tick();
  const final = await render(workbenchRender), finalToast = await render(toastRender), finalForm = clone(sandbox.knowledgePromotionForm.value);
  assert.equal(sandbox.knowledgePromotionAction.value, '');
  const postIndex = requests.findIndex(r => r.method === 'POST'), afterPost = requests.slice(postIndex + 1);
  if (mode.endsWith('post-failure')) {
    assert.deepEqual(finalForm, submittedDraft, 'Failed POST keeps the submitted draft, including note and due date');
    assert.equal(afterPost.length, 0); assert.match(sandbox.knowledgePromotionError.value, /Synthetic revision request failed/);
    assert.equal(notices.some(n => n.type === 'success'), false);
    assert.equal(sandbox.toastState.value.type, 'error');
    assert.match(finalToast.html, /Synthetic revision request failed/);
  } else {
    assert.equal(finalForm.title, titleA); assert.equal(finalForm.objective, objectiveA); assert.equal(finalForm.steps_text, stepsA);
    assert.equal(afterPost.length, 2);
    assert.ok(afterPost.some(r => new URL(r.url).pathname === '/api/knowledge/promotions/101'));
    assert.ok(afterPost.some(r => new URL(r.url).pathname === '/api/knowledge/promotions/101/events'));
    assert.equal(sandbox.knowledgePromotionEvents.value.at(-1).event_type, 'revision_created');
    assert.equal(sandbox.knowledgePromotionError.value, ''); assert.ok(notices.some(n => n.type === 'success'));
    assert.equal(sandbox.toastState.value.type, 'success');
    assert.match(finalToast.html, /候选修订已保存并完成独立回读/);
    assert.equal(final.html.includes(titleB), false); assert.ok(final.html.includes(titleA));
    assert.equal(finalForm.note, ''); assert.equal(finalForm.review_due_at, '');
  }
  assert.deepEqual(runtimeErrors, [], 'No fixture render/runtime error may masquerade as a product result');
  assert.equal(requests.filter(r => r.method === 'POST').length, 1);
  assert.equal(sandbox.knowledgePromotionHotelId.value, '7');
  assert.equal(sandbox.knowledgePromotionSelectedCandidate.value.id, 101);
  await editDraft('B');
  assert.equal(sandbox.knowledgePromotionForm.value.title, titleB);
  assert.equal(sandbox.knowledgePromotionForm.value.note, 'Later unsaved note B');
  assert.equal(sandbox.knowledgePromotionForm.value.review_due_at, '2026-09-23T12:00');
  assert.equal(requests.filter(r => r.method === 'POST').length, 1, 'Editing after unlock must not submit');
  stopWatch();
});
