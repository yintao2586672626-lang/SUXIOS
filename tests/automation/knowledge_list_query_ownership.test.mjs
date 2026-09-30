import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {createHash} from 'node:crypto';
import * as Vue from 'vue';
import {parse,compile} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';
const sourceRoot=path.resolve(process.env.KNOWLEDGE_LIST_TEST_SOURCE_ROOT || process.cwd());
const read=file=>fs.readFileSync(path.join(sourceRoot,file),'utf8');
const main=read('public/app-main.js').replaceAll('\r\n','\n'),rawDomain=read('public/components/system/knowledge-center-domain.js'),rawTemplate=read('resources/frontend/templates/fragments/20-page-knowledge-center.html'),rawShell=read('resources/frontend/templates/fragments/00-app-shell.html');
const sha=v=>createHash('sha256').update(v).digest('hex').toUpperCase(),clone=v=>JSON.parse(JSON.stringify(v));
let assertions=0;
const eq=(actual,expected,label)=>{assertions++;assert.deepEqual(actual,expected,label);},ok=(actual,label)=>{assertions++;assert.ok(actual,label);};
const cut=(source,start,end)=>{const a=source.indexOf(start),b=source.indexOf(end,a+start.length);assert.ok(a>=0&&b>a,start);return source.slice(a,b);};
const declaration=name=>{const a=main.indexOf('            const '+name+' =');assert.ok(a>=0,name);const match=/\n            (?:const|let) /.exec(main.slice(a+1));assert.ok(match,name);return main.slice(a,a+1+match.index);};
const refNames=['knowledgeCenterUnits','knowledgeCenterLoading','knowledgeCenterListError','knowledgeCenterViewMode','knowledgeCenterFilter','knowledgeCenterTargetHotelId','knowledgeCenterPagination','selectedKnowledgeCenterUnitIds','knowledgeCenterBatchDeleting'];
const displayNames=['parseKnowledgeTags','KNOWLEDGE_CENTER_DISPLAY_LABELS','KNOWLEDGE_CENTER_BOUNDARY_TAGS','knowledgeCenterDisplayLabel','knowledgeCenterTagTone','knowledgeCenterTagGroups','knowledgeCenterStatusLabel','knowledgeCenterStatusClass','knowledgeCenterReadinessClass','getHotelNameById','canWriteKnowledgeReference'];
const navNames=['normalizeCanonicalPage','SUPER_ADMIN_ONLY_PAGES','guardSuperAdminPageAccess','getMenuItemName','stableHashSegmentForTestId','normalizeTestIdSegmentInline','menuTestId','toggleSubmenu','isSidebarMenuItemActive','handleParentMenuClick','handleMenuClick','handleNestedMenuClick'];
const delegateNames=['loadKnowledgeCenter','reloadKnowledgeCenter','changeKnowledgeCenterPage','toggleSelectAllKnowledgeCenterUnits','refreshKnowledgeUnit','batchDeleteKnowledgeUnits'];
const parts={context:cut(main,'            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =','            const userHasPermission ='),abort:cut(main,'            const createRequestAbortError =','            const clearPageLifecycleTimers ='),policy:cut(main,'            const currentPageReadPolicy =','            const cancelPageLoadRequests ='),coordinator:cut(main,'            const COORDINATED_GET_MAX_CONCURRENCY =','            // API 请求'),request:cut(main,'            const request = async (','            const apiRequest = request;'),navigation:cut(main,'            const cloneMenuItem =','            // 可见菜单项'),auth:['captureAuthSession','isAuthSessionCurrent'].map(declaration).join('\n'),refs:refNames.map(declaration).join('\n'),display:displayNames.map(declaration).join('\n'),navHandlers:navNames.map(declaration).join('\n'),lifecycle:declaration('runPageLoadOnce'),delegate:declaration('callKnowledgeCenterDomain')+'\n'+delegateNames.map(declaration).join('\n')};
const pageLoadBranch=cut(main,"                if (newPage === 'knowledge-center') {","                if (newPage === 'agent-center') {");
let pageRoot,search,table,pager,input,inputAncestors;
const walkAst=(node,parents=[])=>{if(node.type===1){
  if(node.props.some(p=>p.name==='if'&&p.exp?.content==="currentPage === 'knowledge-center'"))pageRoot=node;
  if(node.tag==='input'&&node.props.some(p=>p.name==='model'&&p.exp?.content==='knowledgeCenterFilter.keyword')&&node.props.some(p=>p.name==='on'&&p.arg?.content==='keyup'&&p.exp?.content==='reloadKnowledgeCenter')){input=node;inputAncestors=parents;search=parents.find(p=>p.type===1&&p!==pageRoot&&p.tag==='div');}
  if(node.tag==='table'&&node.loc.source.includes('knowledgeCenterUnits'))table=parents.find(p=>p.type===1&&p!==pageRoot&&p.tag==='div');
  if(node.tag==='div'&&node.props.some(p=>p.name==='class'&&p.value?.content==='flex items-center justify-between bg-white rounded-xl shadow-sm border border-gray-100 p-4'))pager=node;
}for(const child of node.children||[])walkAst(child,[...parents,node]);};
walkAst(parse(rawTemplate));assert.ok(pageRoot&&search&&table&&pager&&input);
assert.ok(inputAncestors.filter(p=>p.type===1).every(p=>p===pageRoot||search.loc.source.includes(p.loc.source)),'Every original keyword ancestor retained');
const rootOpen=pageRoot.loc.source.slice(0,pageRoot.loc.source.indexOf('>')+1);
const renderPanel=new Function('Vue',compile(rootOpen+search.loc.source+table.loc.source+pager.loc.source+'</div>',{mode:'function',prefixIdentifiers:true}).code)(Vue);
const navMarkup=cut(rawShell,'                <nav ','                </nav>')+'                </nav>';
const renderNav=new Function('Vue',compile(navMarkup,{mode:'function',prefixIdentifiers:true}).code)(Vue);
// DTOs generated by unchanged PHP KnowledgePayloadMapper/Readiness pure functions.
// All rows are synthetic, owned by user 901 in hotel 7/tenant 70; no DB or HTTP.
const fixtures={
  "default": {
    "code": 0,
    "data": {
      "list": [
        {
          "unit_id": 501,
          "hotel_id": 7,
          "stable_key": null,
          "current_chunk_id": null,
          "name": "default synthetic knowledge 501",
          "source": "document",
          "status": "done",
          "lifecycle_status": "active",
          "lifecycle_reason": "",
          "known_knowns": [],
          "known_unknowns": [],
          "truth_profile_version": "",
          "description": "Synthetic owned document; no operational fact claimed",
          "tags": [],
          "chunk_count": 0,
          "readiness": {
            "stage": "unit_done_no_chunks",
            "status_label": "缺少片段",
            "score": 40,
            "closed_loop": false,
            "component_closed_loop": false,
            "authority_status": "diagnostic_only",
            "source_policy": "component_readiness_only_requires_hotel_operating_cycle_kernel",
            "next_action": "补充至少一个可检索知识片段",
            "missing_evidence": [
              {
                "code": "knowledge_chunks",
                "label": "知识片段",
                "next_action": "补充可检索片段后再用于分析或问答"
              }
            ],
            "chunk_count": 0,
            "hotel_id": 7,
            "can_open_chunks": true,
            "can_edit_unit": true,
            "lifecycle_status": "active",
            "lifecycle_reason": "",
            "known_known_count": 0,
            "known_unknown_count": 0,
            "truth_profile_version": "",
            "truth_profile_status": "missing",
            "notice": "仍缺：知识片段",
            "reviewed_at": null,
            "review_due_at": null,
            "freshness_status": "undated",
            "chunk_gate_summary": []
          },
          "created_by": 901,
          "reviewed_at": "",
          "review_due_at": "",
          "created_at": "2026-09-15 10:00:00",
          "updated_at": "2026-09-15 10:00:00",
          "system_read_only": false,
          "can_edit": true
        }
      ],
      "pagination": {
        "total": 1,
        "page": 1,
        "page_size": 10,
        "total_page": 1
      }
    },
    "msg": ""
  },
  "QUERY_A": {
    "code": 0,
    "data": {
      "list": [
        {
          "unit_id": 602,
          "hotel_id": 7,
          "stable_key": null,
          "current_chunk_id": null,
          "name": "QUERY_A synthetic knowledge 602",
          "source": "document",
          "status": "done",
          "lifecycle_status": "active",
          "lifecycle_reason": "",
          "known_knowns": [],
          "known_unknowns": [],
          "truth_profile_version": "",
          "description": "Synthetic owned document; no operational fact claimed",
          "tags": [],
          "chunk_count": 0,
          "readiness": {
            "stage": "unit_done_no_chunks",
            "status_label": "缺少片段",
            "score": 40,
            "closed_loop": false,
            "component_closed_loop": false,
            "authority_status": "diagnostic_only",
            "source_policy": "component_readiness_only_requires_hotel_operating_cycle_kernel",
            "next_action": "补充至少一个可检索知识片段",
            "missing_evidence": [
              {
                "code": "knowledge_chunks",
                "label": "知识片段",
                "next_action": "补充可检索片段后再用于分析或问答"
              }
            ],
            "chunk_count": 0,
            "hotel_id": 7,
            "can_open_chunks": true,
            "can_edit_unit": true,
            "lifecycle_status": "active",
            "lifecycle_reason": "",
            "known_known_count": 0,
            "known_unknown_count": 0,
            "truth_profile_version": "",
            "truth_profile_status": "missing",
            "notice": "仍缺：知识片段",
            "reviewed_at": null,
            "review_due_at": null,
            "freshness_status": "undated",
            "chunk_gate_summary": []
          },
          "created_by": 901,
          "reviewed_at": "",
          "review_due_at": "",
          "created_at": "2026-09-15 10:00:00",
          "updated_at": "2026-09-15 10:00:00",
          "system_read_only": false,
          "can_edit": true
        },
        {
          "unit_id": 601,
          "hotel_id": 7,
          "stable_key": null,
          "current_chunk_id": null,
          "name": "QUERY_A synthetic knowledge 601",
          "source": "document",
          "status": "done",
          "lifecycle_status": "active",
          "lifecycle_reason": "",
          "known_knowns": [],
          "known_unknowns": [],
          "truth_profile_version": "",
          "description": "Synthetic owned document; no operational fact claimed",
          "tags": [],
          "chunk_count": 0,
          "readiness": {
            "stage": "unit_done_no_chunks",
            "status_label": "缺少片段",
            "score": 40,
            "closed_loop": false,
            "component_closed_loop": false,
            "authority_status": "diagnostic_only",
            "source_policy": "component_readiness_only_requires_hotel_operating_cycle_kernel",
            "next_action": "补充至少一个可检索知识片段",
            "missing_evidence": [
              {
                "code": "knowledge_chunks",
                "label": "知识片段",
                "next_action": "补充可检索片段后再用于分析或问答"
              }
            ],
            "chunk_count": 0,
            "hotel_id": 7,
            "can_open_chunks": true,
            "can_edit_unit": true,
            "lifecycle_status": "active",
            "lifecycle_reason": "",
            "known_known_count": 0,
            "known_unknown_count": 0,
            "truth_profile_version": "",
            "truth_profile_status": "missing",
            "notice": "仍缺：知识片段",
            "reviewed_at": null,
            "review_due_at": null,
            "freshness_status": "undated",
            "chunk_gate_summary": []
          },
          "created_by": 901,
          "reviewed_at": "",
          "review_due_at": "",
          "created_at": "2026-09-15 10:00:00",
          "updated_at": "2026-09-15 10:00:00",
          "system_read_only": false,
          "can_edit": true
        }
      ],
      "pagination": {
        "total": 2,
        "page": 1,
        "page_size": 10,
        "total_page": 1
      }
    },
    "msg": ""
  },
  "QUERY_B": {
    "code": 0,
    "data": {
      "list": [
        {
          "unit_id": 701,
          "hotel_id": 7,
          "stable_key": null,
          "current_chunk_id": null,
          "name": "QUERY_B synthetic knowledge 701",
          "source": "document",
          "status": "done",
          "lifecycle_status": "active",
          "lifecycle_reason": "",
          "known_knowns": [],
          "known_unknowns": [],
          "truth_profile_version": "",
          "description": "Synthetic owned document; no operational fact claimed",
          "tags": [],
          "chunk_count": 0,
          "readiness": {
            "stage": "unit_done_no_chunks",
            "status_label": "缺少片段",
            "score": 40,
            "closed_loop": false,
            "component_closed_loop": false,
            "authority_status": "diagnostic_only",
            "source_policy": "component_readiness_only_requires_hotel_operating_cycle_kernel",
            "next_action": "补充至少一个可检索知识片段",
            "missing_evidence": [
              {
                "code": "knowledge_chunks",
                "label": "知识片段",
                "next_action": "补充可检索片段后再用于分析或问答"
              }
            ],
            "chunk_count": 0,
            "hotel_id": 7,
            "can_open_chunks": true,
            "can_edit_unit": true,
            "lifecycle_status": "active",
            "lifecycle_reason": "",
            "known_known_count": 0,
            "known_unknown_count": 0,
            "truth_profile_version": "",
            "truth_profile_status": "missing",
            "notice": "仍缺：知识片段",
            "reviewed_at": null,
            "review_due_at": null,
            "freshness_status": "undated",
            "chunk_gate_summary": []
          },
          "created_by": 901,
          "reviewed_at": "",
          "review_due_at": "",
          "created_at": "2026-09-15 10:00:00",
          "updated_at": "2026-09-15 10:00:00",
          "system_read_only": false,
          "can_edit": true
        }
      ],
      "pagination": {
        "total": 1,
        "page": 1,
        "page_size": 10,
        "total_page": 1
      }
    },
    "msg": ""
  }
};

// Original nav/default-load branch, visible native inputs and GET coordinator.
// Vue SSR/event model with fake fetch only; never a real browser/account/DB claim.
const harness=({allowDelete=false}={})=>{
const requests=[],notices=[],runtimeErrors=[],requestDiagnostics=[],confirmations=[],inFlight=[];
const sandbox={window:{innerWidth:1280},URL,URLSearchParams,Headers,FormData,AbortController,DOMException,structuredClone,ref:Vue.ref,computed:Vue.computed,nextTick:Vue.nextTick,Date,setTimeout,clearTimeout,console:{warn(...args){throw new Error('Unexpected runtime warning: '+String(args[0]));},error(label,...args){const error=args.at(-1);requestDiagnostics.push({label:String(label),name:String(error?.name||''),message:String(error?.message||'')});}},API_BASE:'https://synthetic.invalid/api',
  currentPage:Vue.ref('compass'),user:Vue.ref({id:901,tenant_id:70,realname:'Synthetic operator',is_super_admin:true,capabilities:['all']}),authSessionEpoch:1,pageRequestGeneration:1,filterReportHotel:Vue.ref('7'),authContext:Vue.ref({hotelId:'7',tenantId:'70',platform:'ctrip',permissionStatus:'allowed'}),permittedHotels:Vue.ref([{id:7,tenant_id:70}]),hotels:Vue.ref([{id:7,tenant_id:70,name:'Synthetic Hotel 7'}]),
  // Synthetic in-memory session only. Never read a real credential or record headers.
  token:Vue.ref('synthetic-session-only'),revenueAiBusinessDate:Vue.ref('2026-09-15'),coreOperationsTargetDate:Vue.ref('2026-09-15'),operationYesterday:'2026-09-14',isTerminalAuthFailureResponse:()=>false,readRequestCooldown:{check:()=>null,record(){}},expandedMenus:Vue.ref([]),sidebarCollapsed:false,agentTab:Vue.ref('overview'),revenueAgentTab:Vue.ref('analysis'),onlineDataTab:Vue.ref('data-health'),pendingOnlineDataEntryTab:'',aiModelConfigText:key=>key,
  pageLoadRequests:new Map(),lastLoadedPage:'',lastLoadedPageAt:0,PAGE_LOAD_DEDUP_MS:1000,knowledgeCenterDomainRevision:Vue.ref(0),showToast:(message,type='success')=>notices.push({message,type}),
  confirm:prompt=>{assert.ok(allowDelete,'Synthetic delete must be explicitly enabled only for the amendment case');confirmations.push(prompt);return true;},
  fetch:(url,options)=>new Promise((resolve,reject)=>{
    const parsed=new URL(url),method=options.method||'GET';assert.equal(parsed.origin,'https://synthetic.invalid');
    assert.equal(new Headers(options.headers).get('Authorization'),sandbox.token.value,'Original Authorization carries only the synthetic in-memory session');
    if(method==='DELETE'){assert.ok(allowDelete);assert.equal(parsed.pathname,'/api/knowledge/501');assert.equal(options.signal,undefined,'Original mutation request does not invent a GET AbortSignal');}
    else {assert.equal(method,'GET');assert.equal(parsed.pathname,'/api/knowledge/list');assert.ok(options.signal instanceof AbortSignal);assert.equal(parsed.searchParams.has('hotel_id'),false,'Preserve original aggregate authorized query');}
    const call={url,method,resolve,reject,settled:false,response:null,aborted:false,has_abort_signal:!!options.signal};requests.push(call);
    const abort=()=>{if(!call.settled){call.settled=true;call.aborted=true;reject(new DOMException('Original request aborted','AbortError'));}};
    call.removeAbort=()=>options.signal?.removeEventListener('abort',abort);if(options.signal?.aborted)abort();else options.signal?.addEventListener('abort',abort,{once:true});
  }),
};
vm.createContext(sandbox);vm.runInContext(read('public/system-static.js')+'\n'+rawDomain,sandbox);
sandbox.appSystemStatic=sandbox.window.SUXI_SYSTEM_STATIC;sandbox.requireAppSystemStatic=name=>sandbox.appSystemStatic[name];sandbox.testIdNameMap=sandbox.appSystemStatic.testIdNameMap;
vm.runInContext(Object.entries(parts).filter(([key])=>key!=='delegate').map(([,v])=>v).join('\n')+'\nglobalThis.initial={request,runPageLoadOnce,buildLeanNavigationItems,captureAuthSession,isAuthSessionCurrent,'+[...refNames,...displayNames,...navNames].join(',')+'};',sandbox);
const initial=sandbox.initial,domain=sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({...sandbox,...initial,requireSystemStatic:name=>sandbox.appSystemStatic[name]});
sandbox.createKnowledgeCenterDomain=()=>domain;
vm.runInContext(parts.delegate+'\nglobalThis.delegates={'+delegateNames.join(',')+'};globalThis.originalKnowledgePageBranch=(newPage)=>{'+pageLoadBranch+'};',sandbox);
const visibleMenus=initial.buildLeanNavigationItems(sandbox.appSystemStatic.filterVisibleMenuItems(sandbox.appSystemStatic.resolveMenuItems(sandbox.appSystemStatic.menuItemDefinitions,{}),sandbox.user.value));
const state=Vue.proxyRefs({...sandbox,...initial,...domain,...sandbox.delegates,visibleMenuItems:visibleMenus,knowledgeCenterHotelOptions:sandbox.hotels});
const tick=async()=>{await Vue.nextTick();await new Promise(resolve=>setImmediate(resolve));};
const inspect=async(navigation=false)=>{let tree;const app=Vue.createSSRApp({render(){tree=(navigation?renderNav:renderPanel)(state,[]);return tree;}});app.config.errorHandler=e=>{runtimeErrors.push(String(e?.message||e));throw e;};const html=await renderToString(app),entries=[];const walk=(n,parents=[])=>{if(Array.isArray(n))n.forEach(c=>walk(c,parents));else if(n&&typeof n==='object'){entries.push({node:n,parents});walk(n.children,[...parents,n]);}};walk(tree);return{html,entries,nodes:entries.map(e=>e.node)};};
const ancestors=(view,node)=>view.entries.find(e=>e.node===node).parents;
const disabled=(view,node)=>!!node.props?.disabled||ancestors(view,node).some(p=>p.props?.inert||p.type==='fieldset'&&p.props?.disabled);
const visible=(view,node)=>node.props?.style?.display!=='none'&&!ancestors(view,node).some(p=>p.props?.style?.display==='none'||p.props?.inert);
const reply=(call,body,status=200)=>{ok(call&&!call.settled);call.settled=true;call.removeAbort();call.response=clone(body);call.resolve(new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}}));};
const keywordInput=view=>view.nodes.find(n=>n.type==='input'&&n.props?.placeholder==='搜索标题、卡片、步骤或工作表');
const typeKeyword=async value=>{let view=await inspect(),node=keywordInput(view);ok(node&&visible(view,node)&&!disabled(view,node)&&!node.props?.readonly,'Original keyword is operable');const listeners={},element={type:'text',value,composing:false,addEventListener:(name,cb)=>listeners[name]=cb};Vue.vModelText.created(element,{modifiers:{}},node);listeners.input({target:element});await tick();eq(state.knowledgeCenterFilter.keyword,value);return element;};
const enterKeyword=async value=>{const element=await typeKeyword(value),view=await inspect(),node=keywordInput(view);ok(visible(view,node)&&!disabled(view,node));node.props.onKeyup({key:'Enter',target:element});await tick();const call=requests.findLast(c=>!c.settled&&new URL(c.url).searchParams.get('keyword')===value);ok(call,'Original Enter scheduled or joined the query GET');eq(new URL(call.url).searchParams.get('page'),'1');return call;};
const snapshot=()=>({keyword:state.knowledgeCenterFilter.keyword,unit_ids:clone(state.knowledgeCenterUnits.map(u=>u.unit_id)),names:clone(state.knowledgeCenterUnits.map(u=>u.name)),pagination:clone(state.knowledgeCenterPagination),selection:clone(state.selectedKnowledgeCenterUnitIds),loading:state.knowledgeCenterLoading});

const ready=async(initialEnvelope=fixtures.default)=>{  let nav=await inspect(true);const parent=nav.nodes.find(n=>n.type==='a'&&n.props?.['aria-label']==='系统与工具');ok(parent&&visible(nav,parent));parent.props.onClick();await tick();nav=await inspect(true);const link=nav.nodes.find(n=>n.type==='a'&&n.props?.['data-testid']==='nav-knowledge-center');ok(link&&visible(nav,link));link.props.onClick({stopPropagation(){}});await tick();eq(state.currentPage,'knowledge-center');
  // Invoke the exact original page lifecycle branch after original navigation.
  // Full app watcher tree is not mounted; the branch and runPageLoadOnce are unchanged.
  sandbox.originalKnowledgePageBranch(state.currentPage);await tick();eq(requests.length,1);const initialPending=[...sandbox.pageLoadRequests.values()][0].promise;inFlight.push(initialPending);reply(requests[0],initialEnvelope);await initialPending;await tick();eq(snapshot().unit_ids,initialEnvelope.data.list.map(unit=>unit.unit_id));eq(state.knowledgeCenterLoading,false);
};
const selectPage=async()=>{const view=await inspect(),control=view.nodes.find(n=>n.type==='input'&&n.props?.['aria-label']==='选择当前页知识');ok(control&&visible(view,control)&&!disabled(view,control));control.props.onChange({target:{checked:true}});await tick();};
const refreshRow=async()=>{const view=await inspect(),control=view.nodes.find(n=>n.type==='button'&&n.props?.title==='刷新');ok(control&&visible(view,control)&&!disabled(view,control));const pending=control.props.onClick();inFlight.push(pending);await tick();return {pending};};
const selectUnit=async id=>{const view=await inspect(),control=view.nodes.find(n=>n.type==='input'&&n.props?.type==='checkbox'&&n.props?.value===String(id));ok(control&&visible(view,control)&&!disabled(view,control));const listeners={},element={type:'checkbox',checked:true,_value:String(id),_modelValue:state.selectedKnowledgeCenterUnitIds,addEventListener:(name,callback)=>listeners[name]=callback};Vue.vModelCheckbox.created(element,{modifiers:{}},control);listeners.change({target:element});await tick();};
const beginBatch=async()=>{const view=await inspect(),control=view.nodes.find(n=>n.type==='button'&&n.props?.onClick===state.batchDeleteKnowledgeUnits);ok(control&&visible(view,control)&&!disabled(view,control),'Original batch button is genuinely clickable');const pending=control.props.onClick();inFlight.push(pending);await tick();return{pending};};
const close=async()=>{for(const call of requests.filter(c=>!c.settled))reply(call,fixtures.default);await Promise.allSettled(inFlight);await tick();};
const closed=(expectedErrors=[])=>{eq(runtimeErrors,[]);eq(requestDiagnostics,expectedErrors.map(message=>({label:'API请求失败:',name:'Error',message})),'Only expected controlled request errors may be logged');ok(requests.every(c=>c.settled&&!c.aborted&&(c.method==='GET'?c.has_abort_signal:allowDelete&&c.method==='DELETE')),'All original requests closed with GET AbortSignals intact');};
return {state,requests,notices,confirmations,ready,inspect,reply,tick,enterKeyword,typeKeyword,snapshot,selectPage,refreshRow,selectUnit,beginBatch,close,closed};
};

test('newer keyword rows, total and selection survive late older success; unsubmitted input is only a draft',async t=>{
  const p=harness(),start=assertions;
  try {
    await p.ready();const a=await p.enterKeyword('QUERY_A'),b=await p.enterKeyword('QUERY_B');eq(p.requests.length,3);
    p.reply(b,fixtures.QUERY_B);await p.tick();eq(p.snapshot().unit_ids,[701]);eq(p.state.knowledgeCenterLoading,false);await p.selectPage();eq(p.snapshot().selection,['701']);
    const committed=p.snapshot();p.reply(a,fixtures.QUERY_A);await p.tick();eq(p.snapshot(),committed,'Old A must not replace B rows, pagination, selection or busy state');
    const rendered=await p.inspect();ok(rendered.html.includes('QUERY_B synthetic knowledge 701'));ok(rendered.html.includes('共 1 条'));ok(rendered.html.includes('value="QUERY_B"'));
    // A later unsubmitted keyword must not invalidate the last submitted query.
    const again=await p.enterKeyword('QUERY_A');await p.typeKeyword('NOT_SUBMITTED');eq(p.requests.length,4);p.reply(again,fixtures.QUERY_A);await p.tick();eq(p.snapshot().unit_ids,[602,601]);eq(p.snapshot().pagination.total,2);eq(p.snapshot().keyword,'NOT_SUBMITTED');eq(p.state.knowledgeCenterLoading,false);eq(p.notices,[]);p.closed();
    t.diagnostic(JSON.stringify({assertions:assertions-start,get_count:p.requests.length,all_closed:true}));
  } finally {await p.close();}
});

test('older success cannot release newer loading; changed-scope failure hides prior rows and Enter retries to legal empty',async t=>{
  const p=harness(),start=assertions;
  try {
    await p.ready();const initial=p.snapshot(),a=await p.enterKeyword('QUERY_A'),b=await p.enterKeyword('QUERY_B');p.reply(a,fixtures.QUERY_A);await p.tick();
    eq(p.state.knowledgeCenterLoading,true,'Only pending B may release loading');eq(p.snapshot().unit_ids,[],'A different submitted query must not display the prior query rows');eq(p.snapshot().pagination,initial.pagination);eq(p.notices,[]);
    p.reply(b,{code:503,message:'Synthetic latest query unavailable',data:null},503);await p.tick();eq(p.state.knowledgeCenterLoading,false);eq(p.snapshot().unit_ids,[]);eq(p.snapshot().pagination,initial.pagination);eq(p.state.knowledgeCenterListError,'Synthetic latest query unavailable');eq(p.notices,[{message:'Synthetic latest query unavailable',type:'error'}]);
    const retry=await p.enterKeyword('QUERY_B');eq(p.requests.length,4);const empty={code:0,msg:'',data:{list:[],pagination:{total:0,page:1,page_size:10,total_page:0}}};p.reply(retry,empty);await p.tick();eq(p.snapshot().unit_ids,[]);eq(p.snapshot().pagination,empty.data.pagination);eq(p.snapshot().selection,[]);eq(p.state.knowledgeCenterLoading,false);eq(p.notices.length,1);p.closed(['Synthetic latest query unavailable']);
    t.diagnostic(JSON.stringify({assertions:assertions-start,get_count:p.requests.length,all_closed:true}));
  } finally {await p.close();}
});

test('obsolete HTTP failure stays silent and a superseded original row refresh returns false',async t=>{
  const p=harness(),start=assertions;
  try {
    await p.ready();const row=await p.refreshRow(),a=p.requests.at(-1),joined=await p.refreshRow();eq(p.requests.length,2,'Two same-scope original row refreshes must share one transport through the original GET coordinator');const b=await p.enterKeyword('QUERY_B');eq(p.requests.length,3);
    p.reply(b,fixtures.QUERY_B);await p.tick();await p.selectPage();const committed=p.snapshot();p.reply(a,{code:500,message:'Synthetic obsolete query failure',data:null},500);eq(await row.pending,false,'Superseded refresh does not claim successful refresh');eq(await joined.pending,false,'The joined refresh also cannot claim success after its scope was superseded');await p.tick();eq(p.snapshot(),committed);eq(p.notices,[],'Neither stale error nor 已刷新 may obscure current B');p.closed(['Synthetic obsolete query failure']);
    t.diagnostic(JSON.stringify({assertions:assertions-start,get_count:p.requests.length,all_closed:true}));
  } finally {await p.close();}
});

test('two original Enter calls for the same URL share one transport and the newest call commits',async t=>{
  const p=harness(),start=assertions;
  try {
    await p.ready();const first=await p.enterKeyword('QUERY_A'),second=await p.enterKeyword('QUERY_A');eq(first,second,'No fabricated duplicate HTTP GET');eq(p.requests.length,2);eq(p.state.knowledgeCenterLoading,true);p.reply(first,fixtures.QUERY_A);await p.tick();eq(p.snapshot().unit_ids,[602,601]);eq(p.snapshot().pagination,fixtures.QUERY_A.data.pagination);eq(p.snapshot().keyword,'QUERY_A');eq(p.state.knowledgeCenterLoading,false);eq(p.notices,[]);p.closed();
    t.diagnostic(JSON.stringify({assertions:assertions-start,get_count:p.requests.length,all_closed:true}));
  } finally {await p.close();}
});

test('confirmed synthetic batch deletion followed by a superseded refresh uses a neutral reminder and preserves query B',async t=>{
  const p=harness({allowDelete:true}),start=assertions;
  try {
    // Both owned rows exist before deletion; removing 501 legitimately leaves B/701.
    const initial={code:0,msg:'',data:{list:[...clone(fixtures.QUERY_B.data.list),...clone(fixtures.default.data.list)],pagination:{total:2,page:1,page_size:10,total_page:1}}};
    await p.ready(initial);await p.selectUnit('501');eq(p.snapshot().selection,['501']);
    const batch=await p.beginBatch();eq(p.confirmations.length,1);ok(p.confirmations[0].includes('1 条知识'));const deletion=p.requests.find(c=>c.method==='DELETE');ok(deletion);eq(new URL(deletion.url).pathname,'/api/knowledge/501');
    p.reply(deletion,{code:0,data:{unit_id:501},msg:'deleted'});await p.tick();const a=p.requests.findLast(c=>c.method==='GET'&&!c.settled);ok(a);eq(new URL(a.url).searchParams.get('keyword'),null);eq(p.state.knowledgeCenterBatchDeleting,true);
    const b=await p.enterKeyword('QUERY_B');p.reply(b,fixtures.QUERY_B);await p.tick();await p.selectPage();const committed=p.snapshot();
    p.reply(a,fixtures.QUERY_B);await batch.pending;await p.tick();eq(p.snapshot(),committed);eq(p.snapshot().unit_ids,[701]);eq(p.snapshot().selection,['701']);eq(p.state.knowledgeCenterBatchDeleting,false);
    eq(p.notices,[{message:'已确认删除 1 条知识；本次列表刷新未确认，请刷新确认',type:'error'}]);ok(!p.notices[0].message.includes('当前列表未更新'));eq(p.requests.filter(c=>c.method==='DELETE').length,1,'No retry or duplicate DELETE');eq(p.requests.filter(c=>c.method==='GET').length,3);p.closed();
    t.diagnostic(JSON.stringify({assertions:assertions-start,get_count:3,delete_count:1,all_closed:true}));
  } finally {await p.close();}
});
