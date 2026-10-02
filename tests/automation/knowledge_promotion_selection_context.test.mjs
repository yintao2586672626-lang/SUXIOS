import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Default: actual checkout sources. Overrides support the identical test against a frozen baseline or candidate.
const sourceRoot = path.resolve(process.env.SUXI_PROMOTION_SELECTION_SOURCE_ROOT || '.');
const domainRoot = path.resolve(process.env.SUXI_PROMOTION_SELECTION_DOMAIN_ROOT || sourceRoot);
const read = file => fs.readFileSync(path.join(file === 'public/components/system/knowledge-center-domain.js' ? domainRoot : sourceRoot, file), 'utf8');
const main = read('public/app-main.js').replaceAll('\r\n', '\n');
const rawDomain = read('public/components/system/knowledge-center-domain.js');
const rawTemplate = read('resources/frontend/templates/fragments/20-page-knowledge-center.html');
const rawShell = read('resources/frontend/templates/fragments/00-app-shell.html');
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
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
  refs: cut(main, '            const knowledgePromotionHotelId =', '            const operatingNetworkProfileDimensions ='),
  auth: declaration('captureAuthSession') + '\n' + declaration('isAuthSessionCurrent'),
  compare: declaration('canonicalAiGovernanceJson') + '\n' + declaration('sameAiGovernanceJson'),
  eligible: ['knowledgePromotionSourceCandidates','knowledgePromotionEligibleMemories','knowledgeSopCandidateEligibleMemories','knowledgePromotionApprovalGate','knowledgePromotionStats'].map(declaration).join('\n'),
};
const navNames = ['normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess', 'getMenuItemName', 'stableHashSegmentForTestId', 'normalizeTestIdSegmentInline', 'menuTestId', 'toggleSubmenu', 'isSidebarMenuItemActive', 'handleParentMenuClick', 'handleMenuClick', 'handleNestedMenuClick'];
parts.navHandlers = navNames.map(declaration).join('\n');
parts.delegate = declaration('callKnowledgeCenterDomain') + '\n' + ['saveKnowledgePromotionRevision','openKnowledgePromotionCandidate','changeKnowledgePromotionHotel','loadKnowledgePromotionWorkbench'].map(declaration).join('\n');

const ast=parse(rawTemplate);let pageRoot,workbench,listButton,listAncestors,detail;
const find=(node,parents=[])=>{
  if(node.type===1){
    if(node.props.some(p=>p.name==='if'&&p.exp?.content==="currentPage === 'knowledge-center'"))pageRoot=node;
    if(node.tag==='button'&&node.props.some(p=>p.name==='bind'&&p.arg?.content==='key'&&p.exp?.content==="'promotion-candidate-' + candidate.id")){listButton=node;listAncestors=parents;workbench=parents.find(n=>n.type===1&&n!==pageRoot&&n.loc.source.includes('正式知识晋级审核台'));}
    if(node.props.some(p=>p.name==='model'&&p.exp?.content==='knowledgePromotionForm.title'))detail=[...parents].reverse().find(n=>n.type===1&&n.tag==='div'&&n.props.some(p=>p.name==='class'&&p.value?.content==='xl:col-span-3 rounded-lg border border-gray-100 p-4'));
  }
  for(const child of node.children||[])find(child,[...parents,node]);
};
find(ast);assert.ok(pageRoot&&workbench&&listButton&&detail);
const rootOpen=pageRoot.loc.source.slice(0,pageRoot.loc.source.indexOf('>')+1);
const renderPanel=new Function('Vue',compile(rootOpen+workbench.loc.source+'</div>',{mode:'function',prefixIdentifiers:true}).code)(Vue);
const navMarkup=cut(rawShell,'                <nav ','                </nav>')+'                </nav>';
const renderNav=new Function('Vue',compile(navMarkup,{mode:'function',prefixIdentifiers:true}).code)(Vue);
const service=read('app/service/KnowledgePromotionService.php');
const sourceRecordType=service.match(/SOURCE_RECORD_TYPE = '([^']+)'/)[1],contractVersion=service.match(/CONTRACT_VERSION = '([^']+)'/)[1];
function createHarness(t) {
  let assertions = 0;
  const eq = (actual, expected, label) => { assertions++; assert.deepEqual(actual, expected, label); };
  const ok = (actual, label) => { assertions++; assert.ok(actual, label); };
  const requests=[],notices=[],inFlight=[],runtimeErrors=[];
  const makeCandidate=(id,revisionId,sourceId,label)=>({id,tenant_id:70,hotel_id:7,candidate_key:'synthetic-candidate-'+id,candidate_type:'operating_sop',source_record_type:sourceRecordType,source_record_id:sourceId,workflow_status:'draft',current_revision_id:revisionId,current_revision_no:1,row_version:1,event_count:1,promoted_sop_version_id:null,promoted_knowledge_unit_id:null,promoted_knowledge_chunk_id:null,current_revision:{id:revisionId,candidate_id:id,revision_no:1,source_sop_candidate_version_id:sourceId,title:'Synthetic candidate '+label,objective:'Synthetic objective '+label,steps:['Synthetic step '+label],stop_conditions:[],applicability:{platform:'ctrip',applicability_profile:{},action_parameters:[],success_conditions:[],failure_samples:[],evidence_valid_until:null},scope:{platform:'ctrip',source_scope:'ota_channel'},evidence_refs:[],outcome_refs:[],conflict_refs:[],source_digest:sha('synthetic source '+sourceId),content_digest:sha('synthetic revision '+revisionId),submitted_by:null,submitted_at:null}});
  let candidateA=makeCandidate(101,201,301,'A');const candidateB=makeCandidate(102,211,311,'B');
  let eventsA=[{id:501,candidate_id:101,revision_id:201,event_type:'candidate_created',from_status:'',to_status:'draft'}];
  const eventsB=[{id:511,candidate_id:102,revision_id:211,event_type:'candidate_created',from_status:'',to_status:'draft'}];
  const sources=[candidateA,candidateB].map(candidate=>({id:candidate.source_record_id,tenant_id:70,hotel_id:7,title:candidate.current_revision.title,version_no:1,validation_status:'candidate',lifecycle_status:'active',content_digest:candidate.current_revision.source_digest,source_memory_ids:[candidate.id===101?701:711],scope:{platform:'ctrip',source_scope:'ota_channel'}}));
  let requestUuid=0;
  const sandbox={
    window:{innerWidth:1280},URL,URLSearchParams,Headers,FormData,AbortController,DOMException,structuredClone,
    ref:Vue.ref,computed:Vue.computed,nextTick:Vue.nextTick,Date,setTimeout,clearTimeout,crypto:{randomUUID:()=>`10000000-0000-4000-8000-${String(++requestUuid).padStart(12,'0')}`},
    console:{warn(){},error(){}},API_BASE:'https://synthetic.invalid/api',
    currentPage:Vue.ref('compass'),user:Vue.ref({id:901,realname:'Synthetic operator',is_super_admin:true,capabilities:['all']}),authSessionEpoch:1,pageRequestGeneration:1,filterReportHotel:Vue.ref('7'),
    authContext:Vue.ref({hotelId:'7',tenantId:'70',platform:'ctrip',permissionStatus:'allowed'}),permittedHotels:Vue.ref([{id:7,tenant_id:70}]),
    // In-memory synthetic marker only; no credential is read or recorded in transport.
    token:Vue.ref('synthetic-session-only'),revenueAiBusinessDate:Vue.ref('2026-09-15'),coreOperationsTargetDate:Vue.ref('2026-09-15'),operationYesterday:'2026-09-14',
    isTerminalAuthFailureResponse:()=>false,readRequestCooldown:{check:()=>null,record(){}},expandedMenus:Vue.ref([]),sidebarCollapsed:false,agentTab:Vue.ref('overview'),revenueAgentTab:Vue.ref('analysis'),onlineDataTab:Vue.ref('data-health'),pendingOnlineDataEntryTab:'',
    aiModelConfigText:key=>key,showToast:(message,type='success')=>notices.push({message,type}),knowledgeCenterDomainRevision:Vue.ref(0),
    fetch:(url,options)=>new Promise((resolve,reject)=>{
      const parsed=new URL(url),method=options.method||'GET';assert.equal(parsed.origin,'https://synthetic.invalid');
      assert.ok(method==='POST'&&/^\/api\/knowledge\/promotions\/10[12]\/revisions$/.test(parsed.pathname)||method==='GET'&&(/^\/api\/knowledge\/promotions(?:\/10[12](?:\/events)?)?$/.test(parsed.pathname)||['/api/operation/operating-sops','/api/operation/operating-memories'].includes(parsed.pathname)));
      const call={url,method,body:options.body?JSON.parse(options.body):null,resolve,reject,settled:false,response:null,aborted:false};requests.push(call);
      const abort=()=>{if(!call.settled){call.settled=true;call.aborted=true;reject(new DOMException('Original request aborted','AbortError'));}};
      call.removeAbort=()=>options.signal?.removeEventListener('abort',abort);if(options.signal?.aborted)abort();else options.signal?.addEventListener('abort',abort,{once:true});
    }),
  };
  vm.createContext(sandbox);vm.runInContext(read('public/system-static.js')+'\n'+rawDomain,sandbox);
  sandbox.appSystemStatic=sandbox.window.SUXI_SYSTEM_STATIC;sandbox.requireAppSystemStatic=name=>sandbox.appSystemStatic[name];sandbox.testIdNameMap=sandbox.appSystemStatic.testIdNameMap;
  const refNames=[...parts.refs.matchAll(/const (\w+) =/g)].map(match=>match[1]);
  const computedNames=['knowledgePromotionSourceCandidates','knowledgePromotionEligibleMemories','knowledgeSopCandidateEligibleMemories','knowledgePromotionApprovalGate','knowledgePromotionStats'];
  vm.runInContext(Object.values(parts).filter(part=>part!==parts.delegate).join('\n')+'\nglobalThis.initial={request,buildLeanNavigationItems,captureAuthSession,isAuthSessionCurrent,canonicalAiGovernanceJson,sameAiGovernanceJson,'+computedNames.join(',')+','+refNames.join(',')+','+navNames.join(',')+'};',sandbox);
  const initial=sandbox.initial;
  const domain=sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({...sandbox,...initial,computed:Vue.computed,request:initial.request,requireSystemStatic:name=>sandbox.appSystemStatic[name]});
  sandbox.createKnowledgeCenterDomain=()=>domain;
  vm.runInContext(parts.delegate+'\nglobalThis.delegates={saveKnowledgePromotionRevision,openKnowledgePromotionCandidate,changeKnowledgePromotionHotel,loadKnowledgePromotionWorkbench};',sandbox);
  const visibleMenus=initial.buildLeanNavigationItems(sandbox.appSystemStatic.filterVisibleMenuItems(sandbox.appSystemStatic.resolveMenuItems(sandbox.appSystemStatic.menuItemDefinitions,{}),sandbox.user.value));
  const state=Vue.proxyRefs({...sandbox,...initial,...domain,...sandbox.delegates,visibleMenuItems:visibleMenus,knowledgeCenterHotelOptions:[{id:7,name:'Synthetic Hotel 7'}]});
  const tick=async()=>{await Vue.nextTick();await new Promise(resolve=>setImmediate(resolve));};
  const nodeText=node=>typeof node==='string'?node:Array.isArray(node)?node.map(nodeText).join(''):node?.children?nodeText(node.children):'';
  const inspect=async(navigation=false)=>{let tree;const app=Vue.createSSRApp({render(){tree=(navigation?renderNav:renderPanel)(state,[]);return tree;}});app.config.errorHandler=error=>{runtimeErrors.push(String(error?.message||error));throw error;};const html=await renderToString(app),entries=[];const walk=(node,parents=[])=>{if(Array.isArray(node))node.forEach(child=>walk(child,parents));else if(node&&typeof node==='object'){entries.push({node,parents});walk(node.children,[...parents,node]);}};walk(tree);return{html,entries,nodes:entries.map(entry=>entry.node)};};
  const disabled=(view,node)=>!!node.props?.disabled||view.entries.find(entry=>entry.node===node).parents.some(parent=>parent.props?.inert||parent.type==='fieldset'&&parent.props?.disabled);
  const visible=(view,node)=>!view.entries.find(entry=>entry.node===node).parents.some(parent=>parent.props?.style?.display==='none'||parent.props?.inert)&&node.props?.style?.display!=='none';
  const reply=(call,body,status=200)=>{assert.ok(call&&!call.settled);call.settled=true;call.removeAbort();call.response=clone(body);call.resolve(new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}}));};

  // Every transport is synthetic and closed, while the actual request coordinator,
  // auth context, AbortSignals and three-GET concurrency limit stay in place.
  const pendingDetails = id => requests.filter(call => !call.settled && call.method === 'GET'
    && new URL(call.url).pathname.startsWith('/api/knowledge/promotions/' + id));
  const detailReply = (call, candidate, events, failEvents = false) => {
    const isEvents = new URL(call.url).pathname.endsWith('/events');
    if (isEvents && failEvents) {
      reply(call, { code: 500, message: 'Synthetic candidate events unavailable', data: null }, 500);
    } else {
      reply(call, { code: 200, data: isEvents
        ? { data_status: 'ok', candidate_id: candidate.id, count: events.length, list: clone(events) }
        : clone(candidate) });
    }
  };
  const finishDetails = async (candidate, events, failEvents = false) => {
    let completed = 0;
    // Another candidate can occupy two coordinator slots; drain the target in
    // waves without bypassing the queue or settling the other candidate's GETs.
    for (let wave = 0; wave < 6 && completed < 2; wave++) {
      await tick();
      for (const call of pendingDetails(candidate.id)) {
        detailReply(call, candidate, events, failEvents);
        completed++;
      }
    }
    eq(completed, 2, 'Both original candidate/detail requests close');
    await tick();
  };
  const openCandidate = async id => {
    const view = await inspect();
    const button = view.nodes.find(node => node.type === 'button' && node.key === 'promotion-candidate-' + id);
    ok(button && visible(view, button) && !disabled(view, button), 'Original list button is visible and enabled');
    const pending = button.props.onClick();
    inFlight.push(pending);
    await tick();
    return { pending };
  };
  const nativeText = async (model, value) => {
    const view = await inspect();
    const node = view.nodes.find(node => node.props?.['onUpdate:modelValue']?.toString().includes('knowledgePromotionForm.' + model));
    ok(node && ['input', 'textarea'].includes(node.type) && visible(view, node) && !disabled(view, node), 'Original draft control is enabled');
    const listeners = {};
    const element = { type: node.type === 'textarea' ? 'textarea' : 'text', value, composing: false,
      addEventListener: (name, callback) => listeners[name] = callback };
    Vue.vModelText.created(element, { modifiers: {} }, node);
    listeners.input({ target: element });
    await tick();
    eq(state.knowledgePromotionForm[model], value);
  };
  const fillDraft = async label => {
    await nativeText('title', 'Submitted revision ' + label);
    await nativeText('objective', 'Synthetic revision ' + label + ' objective');
    await nativeText('steps_text', 'Synthetic revised ' + label + ' step');
    return clone(state.knowledgePromotionForm);
  };
  const saveButton = view => view.nodes.find(node => node.type === 'button' && nodeText(node).includes('保存新修订并独立回读'));
  const beginSave = async id => {
    const view = await inspect(), button = saveButton(view);
    ok(button && visible(view, button) && !disabled(view, button), 'Original revision save button is enabled');
    const pending = button.props.onClick();
    inFlight.push(pending);
    await tick();
    const post = requests.findLast(call => !call.settled && call.method === 'POST');
    ok(post);
    eq(new URL(post.url).pathname, '/api/knowledge/promotions/' + id + '/revisions');
    eq(post.body.title, state.knowledgePromotionForm.title);
    eq(state.knowledgePromotionAction, 'revision');
    return { pending, post };
  };
  const savedRevision = (candidate, events, post) => {
    const revisionId = candidate.current_revision_id + 1;
    const sourceId = candidate.source_record_id + 1;
    const event = { id: events[0].id + 3, candidate_id: candidate.id, revision_id: revisionId,
      event_type: 'revision_created', from_status: 'draft', to_status: 'draft' };
    const nextEvents = [...clone(events), event];
    const nextRevision = { ...clone(candidate.current_revision), id: revisionId, revision_no: 2,
      source_sop_candidate_version_id: sourceId, title: post.body.title, objective: post.body.objective,
      steps: clone(post.body.steps), stop_conditions: clone(post.body.stop_conditions),
      applicability: { platform: 'ctrip', applicability_profile: clone(post.body.applicability_profile),
        action_parameters: clone(post.body.action_parameters), success_conditions: clone(post.body.success_conditions),
        failure_samples: clone(post.body.failure_samples), evidence_valid_until: null },
      source_digest: sha('synthetic new source' + sourceId), content_digest: sha(JSON.stringify(post.body)),
      submitted_by: null, submitted_at: null };
    const nextCandidate = { ...clone(candidate), current_revision_id: revisionId, current_revision_no: 2,
      row_version: 2, event_count: nextEvents.length, current_revision: nextRevision, review_due_at: null };
    return { candidate: nextCandidate, events: nextEvents,
      payload: { candidate: clone(nextCandidate), event: clone(event), created: true,
        operation_status: 'revision_created', persistence_status: 'readback_verified', write_boundaries: {
          contract_version: contractVersion, runtime_json_is_formal_source: false, causality_verified: false,
          automatic_execution: false, ota_write: false, external_message: false, knowledge_write_before_approval: false } } };
  };
  const releasePost = (save, saved) => reply(save.post, { code: 200, message: 'Synthetic revision saved', data: saved.payload });
  const assertNoOldDetail = async () => {
    const view = await inspect();
    eq(state.knowledgePromotionSelectedCandidate, null, 'Pending new selection removes old detail');
    eq(view.nodes.some(node => node.props?.['onUpdate:modelValue']?.toString().includes('knowledgePromotionForm.title')), false);
    for (const label of ['保存新修订并独立回读', '送审并独立回读', '撤回候选']) {
      eq(view.nodes.some(node => node.type === 'button' && nodeText(node).includes(label)), false, label + ' is not mounted');
    }
  };
  const assertSelected = async (candidate, events) => {
    eq(state.knowledgePromotionSelectedCandidate.id, candidate.id);
    eq(state.knowledgePromotionSelectedCandidate.current_revision_id, candidate.current_revision_id);
    eq(state.knowledgePromotionForm.title, candidate.current_revision.title);
    eq(clone(state.knowledgePromotionEvents), events);
    const view = await inspect();
    ok(view.nodes.some(node => node.type === 'h4' && nodeText(node) === candidate.current_revision.title));
  };
  const start = async () => {
    let nav = await inspect(true);
    const parent = nav.nodes.find(node => node.type === 'a' && node.props?.['aria-label'] === '系统与工具');
    ok(parent && visible(nav, parent)); parent.props.onClick(); await tick();
    nav = await inspect(true);
    const link = nav.nodes.find(node => node.type === 'a' && node.props?.['data-testid'] === 'nav-knowledge-center');
    ok(link && visible(nav, link)); link.props.onClick({ stopPropagation() {} }); await tick();
    eq(state.currentPage, 'knowledge-center');
    const view = await inspect();
    const select = view.nodes.find(node => node.type === 'select' && node.props?.['aria-label'] === '知识晋级门店');
    ok(select && visible(view, select) && !disabled(view, select));
    const listeners = {}, element = { multiple: false, options: [{ value: '', selected: false }, { value: '7', selected: true }],
      addEventListener: (name, callback) => listeners[name] = callback };
    Vue.vModelSelect.created(element, { modifiers: {} }, select);
    listeners.change({ target: element }); select.props.onChange(); await tick();
    const lists = requests.filter(call => !call.settled); eq(lists.length, 3);
    for (const call of lists) {
      const url = new URL(call.url); eq(url.searchParams.get('hotel_id'), '7');
      const list = url.pathname === '/api/knowledge/promotions' ? [candidateA, candidateB]
        : url.pathname.endsWith('/operating-sops') ? sources : [];
      reply(call, { code: 200, data: { data_status: 'ok', list: clone(list) } });
    }
    await tick(); eq(state.knowledgePromotionCandidates.length, 2);
    const open = await openCandidate(101);
    await finishDetails(candidateA, eventsA); await open.pending;
    await assertSelected(candidateA, eventsA);
    const selectedView = await inspect();
    for (const label of ['保存新修订并独立回读', '送审并独立回读', '撤回候选']) {
      ok(selectedView.nodes.some(node => node.type === 'button' && nodeText(node).includes(label)),
        'Original selected A actually contains ' + label);
    }
  };
  const assertClosed = () => {
    eq(runtimeErrors, []);
    ok(requests.every(call => call.settled && !call.aborted), 'Every synthetic request closed through the original coordinator');
    t.diagnostic(JSON.stringify({ assertions, posts: requests.filter(call => call.method === 'POST').length,
      gets: requests.filter(call => call.method === 'GET').length, all_closed: true, browser: false, real_http: false }));
  };
  t.after(async () => {
    // Failure cleanup can create queued GETs; drain in waves without disabling
    // cancellation, then await the actual handlers. Green assertions run first.
    for (let wave = 0; wave < 20; wave++) {
      for (const call of requests.filter(call => !call.settled)) {
        reply(call, { code: 500, message: 'Synthetic test cleanup', data: null }, 500);
      }
      await tick();
    }
    await Promise.allSettled(inFlight);
  });
  return { eq, ok, state, requests, notices, candidateA, candidateB, eventsA, eventsB, start, tick,
    inspect, disabled, visible, saveButton, reply, finishDetails, pendingDetails, openCandidate,
    fillDraft, beginSave, savedRevision, releasePost, assertNoOldDetail, assertSelected, assertClosed };
}

test('late revision POST cannot revive old detail while the new selection loads; failed detail can retry', { timeout: 15000 }, async t => {
  const h = createHarness(t);
  await h.start();
  const draftA = await h.fillDraft('A');
  const saveA = await h.beginSave(101), savedA = h.savedRevision(h.candidateA, h.eventsA, saveA.post);
  const openB = await h.openCandidate(102);
  h.eq(h.pendingDetails(102).length, 2);
  // A ends first. Keep B's original two GETs pending to expose the transition.
  h.releasePost(saveA, savedA); await h.tick();
  h.eq(h.pendingDetails(101).length, 0, 'Stale POST must not start exact readback for old A');
  await saveA.pending;
  h.eq(h.state.knowledgePromotionAction, '');
  h.eq(h.state.knowledgePromotionLoading, true);
  await h.assertNoOldDetail();
  h.eq(clone(h.state.knowledgePromotionForm), draftA, 'Hiding the previous detail retains form memory');
  h.eq(h.notices, []);
  await h.finishDetails(h.candidateB, h.eventsB, true); await openB.pending;
  h.eq(h.state.knowledgePromotionLoading, false);
  h.eq(h.state.knowledgePromotionAction, '');
  h.ok(h.state.knowledgePromotionError.includes('Synthetic candidate events unavailable'));
  h.eq(clone(h.state.knowledgePromotionForm), draftA);
  await h.assertNoOldDetail();
  const retryB = await h.openCandidate(102);
  await h.finishDetails(h.candidateB, h.eventsB); await retryB.pending;
  await h.assertSelected(h.candidateB, h.eventsB);
  h.eq(h.state.knowledgePromotionError, '');
  const ready = await h.inspect(); h.ok(!h.disabled(ready, h.saveButton(ready)));
  h.eq(h.requests.filter(call => call.method === 'POST').length, 1);
  h.assertClosed();
});

test('late exact candidate/events readback preserves B selection, then B can fail and save normally', { timeout: 15000 }, async t => {
  const h = createHarness(t);
  await h.start(); await h.fillDraft('A');
  const saveA = await h.beginSave(101), savedA = h.savedRevision(h.candidateA, h.eventsA, saveA.post);
  h.releasePost(saveA, savedA); await h.tick();
  h.eq(h.pendingDetails(101).length, 2, 'A strict readback is genuinely in flight');
  const openB = await h.openCandidate(102);
  await h.finishDetails(h.candidateB, h.eventsB); await openB.pending;
  await h.assertSelected(h.candidateB, h.eventsB);
  h.eq(h.state.knowledgePromotionAction, 'revision');
  const busyB = await h.inspect();
  h.ok(h.disabled(busyB, h.saveButton(busyB)), 'No new B save can start before A finishes');
  const title = busyB.nodes.find(node => node.props?.['onUpdate:modelValue']?.toString().includes('knowledgePromotionForm.title'));
  h.ok(title && h.disabled(busyB, title), 'Round94 native fieldset remains effective on B');
  await h.finishDetails(savedA.candidate, savedA.events); await saveA.pending;
  await h.assertSelected(h.candidateB, h.eventsB);
  h.eq(h.state.knowledgePromotionAction, ''); h.eq(h.state.knowledgePromotionError, ''); h.eq(h.notices, []);
  const draftB = await h.fillDraft('B');
  const failure = await h.beginSave(102);
  h.reply(failure.post, { code: 500, message: 'Synthetic revision unavailable', data: null }, 500); await failure.pending;
  h.eq(clone(h.state.knowledgePromotionForm), draftB); h.eq(h.state.knowledgePromotionSelectedCandidate.id, 102);
  h.eq(h.state.knowledgePromotionAction, ''); h.ok(h.state.knowledgePromotionError.includes('Synthetic revision unavailable'));
  h.eq(h.pendingDetails(102).length, 0);
  const saveB = await h.beginSave(102), savedB = h.savedRevision(h.candidateB, h.eventsB, saveB.post);
  h.releasePost(saveB, savedB); await h.tick();
  h.eq(h.state.knowledgePromotionAction, 'revision');
  await h.finishDetails(savedB.candidate, savedB.events); await saveB.pending;
  await h.assertSelected(savedB.candidate, savedB.events);
  h.eq(h.state.knowledgePromotionAction, ''); h.eq(h.state.knowledgePromotionError, '');
  h.eq(h.notices, [{ message: 'Synthetic revision unavailable', type: 'error' },
    { message: '候选修订已保存并完成独立回读', type: 'success' }]);
  h.eq(h.requests.filter(call => call.method === 'POST').length, 3);
  h.assertClosed();
});

test('A to B to A selection generations suppress the old A failure despite equal candidate IDs', { timeout: 15000 }, async t => {
  const h = createHarness(t);
  await h.start(); await h.fillDraft('A');
  const saveA = await h.beginSave(101);
  const openB = await h.openCandidate(102);
  await h.finishDetails(h.candidateB, h.eventsB); await openB.pending;
  const openA = await h.openCandidate(101);
  await h.finishDetails(h.candidateA, h.eventsA); await openA.pending;
  await h.assertSelected(h.candidateA, h.eventsA);
  h.eq(h.state.knowledgePromotionAction, 'revision');
  const before = h.requests.length;
  h.reply(saveA.post, { code: 500, message: 'Synthetic stale A error', data: null }, 500); await saveA.pending;
  await h.assertSelected(h.candidateA, h.eventsA);
  h.eq(h.state.knowledgePromotionAction, '');
  h.eq(h.state.knowledgePromotionError, '', 'The prior A selection does not own current A errors');
  h.eq(h.notices, []);
  h.eq(h.requests.length, before, 'No automatic retry or follow-up write');
  h.assertClosed();
});
