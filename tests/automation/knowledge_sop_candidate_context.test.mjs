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
const sourceRoot=process.env.SUXI_SOP_CANDIDATE_SOURCE_ROOT||'.';
const domainRoot=process.env.SUXI_SOP_CANDIDATE_DOMAIN_ROOT||sourceRoot;
const read=file=>fs.readFileSync(path.resolve(file.endsWith('knowledge-center-domain.js')?domainRoot:sourceRoot,file),'utf8');
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
  refs: cut(main, '            const knowledgePromotionHotelId =', '            const operatingNetworkProfileDimensions ='),
  auth: declaration('captureAuthSession') + '\n' + declaration('isAuthSessionCurrent'),
  compare: declaration('canonicalAiGovernanceJson') + '\n' + declaration('sameAiGovernanceJson'),
  eligible: ['knowledgePromotionSourceCandidates','knowledgeSopCandidateEligibleMemories'].map(declaration).join('\n'),
};
const navNames = ['normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess', 'getMenuItemName', 'stableHashSegmentForTestId', 'normalizeTestIdSegmentInline', 'menuTestId', 'toggleSubmenu', 'isSidebarMenuItemActive', 'handleParentMenuClick', 'handleMenuClick', 'handleNestedMenuClick'];
parts.navHandlers = navNames.map(declaration).join('\n');
parts.delegate = declaration('callKnowledgeCenterDomain') + '\n' + ['createKnowledgeSopCandidate','changeKnowledgePromotionHotel','loadKnowledgePromotionWorkbench'].map(declaration).join('\n');

const ast = parse(rawTemplate), selected = new Set(); let builder, builderAncestors, panel;
const find = (node, parents = []) => {
  if (node.props?.some(prop=>prop.name==='data-testid'&&prop.value?.content==='knowledge-sop-candidate-builder')) {builder=node;builderAncestors=parents;panel=parents.at(-2);}
  for(const child of node.children||[])find(child,[...parents,node]);
};
find(ast); assert.ok(builder&&panel);
selected.add(builder);
const choose = node => {
  if(node.type===1 && (node.props.some(prop=>prop.name==='aria-label'&&['知识晋级门店','候选SOP来源版本'].includes(prop.value?.content)) || node.props.some(prop=>prop.name==='if'&&prop.exp?.content==='!knowledgePromotionHotelId'))) selected.add(node);
  for(const child of node.children||[])choose(child);
};
choose(panel);
const retain = node => {
  if(selected.has(node))return node.loc.source;
  const children=(node.children||[]).map(retain).join('');
  if(!children||node.type===0)return children;
  return node.loc.source.slice(0,node.loc.source.indexOf('>')+1)+children+node.loc.source.slice(node.loc.source.lastIndexOf('</'));
};
const markup=retain(ast);
const renderPanel=new Function('Vue',compile(markup,{mode:'function',prefixIdentifiers:true}).code)(Vue);
const navMarkup=cut(rawShell,'                <nav ','                </nav>')+'                </nav>';
const renderNav=new Function('Vue',compile(navMarkup,{mode:'function',prefixIdentifiers:true}).code)(Vue);

function harness(){
const requests=[], notices=[], inFlight=[];
const memoryA={id:501,tenant_id:70,hotel_id:7,memory_layer:'execution_review',quality_status:'verified',usage_level:'decision_support',lifecycle_status:'active',platform:'ctrip',source_scope:'ota_channel',business_date:'2026-09-15',source_record_type:'operation_execution_task',source_record_id:601,title:'Synthetic positive review A',context:{outcome_verified:true,positive_outcome_verified:true,sop_candidate_ready:true},evidence_refs:['synthetic_execution_review#601'],deleted_at:null};
const memoryFor=hotel=>({...clone(memoryA),hotel_id:hotel,id:hotel===7?501:502,source_record_id:hotel===7?601:602,title:'Synthetic positive review '+hotel,evidence_refs:['synthetic_execution_review#'+(hotel===7?601:602)]});
const sandbox={
  window:{innerWidth:1280},URL,URLSearchParams,Headers,FormData,AbortController,DOMException,structuredClone,
  ref:Vue.ref,computed:Vue.computed,nextTick:Vue.nextTick,Date,setTimeout,clearTimeout,
  console:{warn(){},error(){}},API_BASE:'https://synthetic.invalid/api',
  currentPage:Vue.ref('compass'),user:Vue.ref({id:901,realname:'Synthetic operator',is_super_admin:true,capabilities:['all']}),
  authSessionEpoch:1,pageRequestGeneration:1,filterReportHotel:Vue.ref('7'),
  authContext:Vue.ref({hotelId:'7',tenantId:'70',platform:'ctrip',permissionStatus:'allowed'}),
  permittedHotels:Vue.ref([{id:7,tenant_id:70},{id:8,tenant_id:70}]),token:Vue.ref(''),
  revenueAiBusinessDate:Vue.ref('2026-09-15'),coreOperationsTargetDate:Vue.ref('2026-09-15'),operationYesterday:'2026-09-14',
  isTerminalAuthFailureResponse:()=>false,readRequestCooldown:{check:()=>null,record(){}},
  expandedMenus:Vue.ref([]),sidebarCollapsed:false,agentTab:Vue.ref('overview'),revenueAgentTab:Vue.ref('analysis'),onlineDataTab:Vue.ref('data-health'),pendingOnlineDataEntryTab:'',
  aiModelConfigText:key=>key,showToast:(message,type='success')=>notices.push({message,type}),knowledgeCenterDomainRevision:Vue.ref(0),
  fetch:(url,options)=>new Promise((resolve,reject)=>{
    const parsed=new URL(url),method=options.method||'GET';assert.equal(parsed.origin,'https://synthetic.invalid');
    assert.ok(method==='POST'&&parsed.pathname==='/api/operation/operating-sops'||method==='GET'&&(/^\/api\/operation\/operating-sops(?:\/\d+)?$/.test(parsed.pathname)||['/api/knowledge/promotions','/api/operation/operating-memories'].includes(parsed.pathname)));
    const call={url,method,body:options.body?JSON.parse(options.body):null,resolve,reject,settled:false,response:null,aborted:false};requests.push(call);
    const abort=()=>{if(!call.settled){call.settled=true;call.aborted=true;reject(new DOMException('Original request aborted','AbortError'));}};
    call.removeAbort=()=>options.signal?.removeEventListener('abort',abort);
    if(options.signal?.aborted)abort();else options.signal?.addEventListener('abort',abort,{once:true});
  }),
};
vm.createContext(sandbox);vm.runInContext(read('public/system-static.js')+'\n'+rawDomain,sandbox);
sandbox.appSystemStatic=sandbox.window.SUXI_SYSTEM_STATIC;
sandbox.requireAppSystemStatic=name=>sandbox.appSystemStatic[name];sandbox.testIdNameMap=sandbox.appSystemStatic.testIdNameMap;
const refNames=[...parts.refs.matchAll(/const (\w+) =/g)].map(match=>match[1]);
vm.runInContext(Object.values(parts).filter(part=>part!==parts.delegate).join('\n')+'\nglobalThis.initial={request,buildLeanNavigationItems,captureAuthSession,isAuthSessionCurrent,canonicalAiGovernanceJson,sameAiGovernanceJson,knowledgePromotionSourceCandidates,knowledgeSopCandidateEligibleMemories,'+refNames.join(',')+','+navNames.join(',')+'};',sandbox);
const initial=sandbox.initial;
const domain=sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({...sandbox,...initial,computed:Vue.computed,request:initial.request,requireSystemStatic:name=>sandbox.appSystemStatic[name]});
sandbox.createKnowledgeCenterDomain=()=>domain;
vm.runInContext(parts.delegate+'\nglobalThis.delegates={createKnowledgeSopCandidate,changeKnowledgePromotionHotel,loadKnowledgePromotionWorkbench};',sandbox);
const visibleMenus=initial.buildLeanNavigationItems(sandbox.appSystemStatic.filterVisibleMenuItems(sandbox.appSystemStatic.resolveMenuItems(sandbox.appSystemStatic.menuItemDefinitions,{}),sandbox.user.value));
const state=Vue.proxyRefs({...sandbox,...initial,...sandbox.delegates,visibleMenuItems:visibleMenus,knowledgeCenterHotelOptions:[{id:7,name:'Synthetic Hotel A'},{id:8,name:'Synthetic Hotel B'}]});
const tick=async()=>{await Vue.nextTick();await new Promise(resolve=>setImmediate(resolve));};
const nodeText=node=>typeof node==='string'?node:Array.isArray(node)?node.map(nodeText).join(''):node?.children?nodeText(node.children):'';
const inspect=async(navigation=false)=>{let tree;const app=Vue.createSSRApp({render(){tree=(navigation?renderNav:renderPanel)(state,[]);return tree;}});const html=await renderToString(app),entries=[];const walk=(node,parents=[])=>{if(Array.isArray(node))node.forEach(child=>walk(child,parents));else if(node&&typeof node==='object'){entries.push({node,parents});walk(node.children,[...parents,node]);}};walk(tree);return{html,entries,nodes:entries.map(entry=>entry.node)};};
const disabled=(view,node)=>!!node.props?.disabled||view.entries.find(entry=>entry.node===node).parents.some(parent=>parent.props?.inert||parent.type==='fieldset'&&parent.props?.disabled);
const visible=(view,node)=>!view.entries.find(entry=>entry.node===node).parents.some(parent=>parent.props?.style?.display==='none'||parent.props?.inert)&&node.props?.style?.display!=='none';
const reply=(call,body,status=200)=>{assert.ok(call&&!call.settled);call.settled=true;call.removeAbort();call.response=clone(body);call.resolve(new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}}));};
const switchHotel=async hotel=>{
  const view=await inspect(),select=view.nodes.find(node=>node.type==='select'&&node.props?.['aria-label']==='知识晋级门店');
  ok(select&&visible(view,select)&&!disabled(view,select),'Original local hotel selector is operable');
  const listeners={},element={multiple:false,options:['','7','8'].map(value=>({value,selected:value===hotel})),addEventListener:(name,callback)=>listeners[name]=callback};
  Vue.vModelSelect.created(element,{modifiers:{}},select);listeners.change({target:element});select.props.onChange();await tick();
  eq(state.knowledgePromotionHotelId,hotel);eq(state.filterReportHotel,'7','Local selector does not change global request hotel');
};
const finishLists=async(hotel,versions=[])=>{
  const completed=[];
  // An older exact GET may occupy one of the original coordinator's three
  // slots. Drain the original three list requests in their observed waves.
  while(completed.length<3){
    const pending=requests.filter(call=>!call.settled&&call.method==='GET'&&new URL(call.url).searchParams.get('hotel_id')===String(hotel)&&['/api/knowledge/promotions','/api/operation/operating-sops','/api/operation/operating-memories'].includes(new URL(call.url).pathname));
    ok(pending.length>0&&completed.length+pending.length<=3,'Original workbench list wave is available');
    for(const call of pending){
      const url=new URL(call.url);eq(url.searchParams.get('hotel_id'),String(hotel));
      const list=url.pathname.endsWith('/operating-memories')?[memoryFor(hotel)]:url.pathname.endsWith('/operating-sops')?versions:[];
      reply(call,{code:200,data:{data_status:'ok',list,total:list.length}});completed.push(url.pathname);
    }
    await tick();
  }
  eq(new Set(completed).size,3,'All three original workbench lists completed');
  eq(state.knowledgePromotionLoading,false);eq(state.knowledgePromotionError,'');
};
const nativeText=async(model,value)=>{
  const view=await inspect(),node=view.nodes.find(node=>node.props?.['onUpdate:modelValue']?.toString().includes('knowledgeSopCandidateForm.'+model));
  ok(node&&['input','textarea'].includes(node.type)&&visible(view,node)&&!disabled(view,node));
  const listeners={},element={type:node.type==='textarea'?'textarea':'text',value,composing:false,addEventListener:(name,callback)=>listeners[name]=callback};
  Vue.vModelText.created(element,{modifiers:{}},node);listeners.input({target:element});await tick();eq(state.knowledgeSopCandidateForm[model],value);
};
const chooseMemory=async(hotel=7)=>{
  const memoryId=memoryFor(hotel).id;
  const view=await inspect(),node=view.nodes.find(node=>node.type==='input'&&node.props?.type==='checkbox'&&node.props?.value===memoryId);
  ok(node&&visible(view,node)&&!disabled(view,node),'Original eligible memory checkbox exists');
  const listeners={},element={type:'checkbox',value:memoryId,_value:memoryId,checked:false,addEventListener:(name,callback)=>listeners[name]=callback};
  Vue.vModelCheckbox.created(element,{modifiers:{}},node);Vue.vModelCheckbox.mounted(element,{value:state.knowledgeSopCandidateForm.source_memory_ids},node);element.checked=true;listeners.change({target:element});await tick();eq(clone(state.knowledgeSopCandidateForm.source_memory_ids),[memoryId]);
};
const beginSave=async(hotel=7)=>{
  const view=await inspect(),button=view.nodes.find(node=>node.type==='button'&&node.props?.onClick?.toString().includes('createKnowledgeSopCandidate'));
  ok(button&&visible(view,button)&&!disabled(view,button),'Original save candidate button is operable');
  const pending=button.props.onClick();inFlight.push(pending);await tick();
  const post=requests.findLast(call=>call.method==='POST'&&!call.settled);ok(post);eq(post.body.hotel_id,hotel);eq(post.body.source_memory_ids,[memoryFor(hotel).id]);eq(post.body.platform,'ctrip');eq(post.body.tenant_id,'70');return{pending,post};
};
const versionFor=body=>({
  id:body.hotel_id===7?801:802,tenant_id:70,hotel_id:body.hotel_id,sop_key:'synthetic-candidate-'+body.hotel_id,version_no:1,previous_version_id:0,title:body.title,objective:body.objective,steps:clone(body.steps),stop_conditions:clone(body.stop_conditions),
  scope:{tenant_id:70,hotel_id:body.hotel_id,platform:'ctrip',source_scope:'ota_channel',evidence_date_start:'2026-09-15',evidence_date_end:'2026-09-15',applicable_data_types:[],metric_definitions:[],applicability_contract_version:'controlled_operating_network.v1',applicability_profile:{},action_parameters:[],success_conditions:[],failure_samples:[],evidence_valid_until:null,replication_scope:'same_tenant_draft_only'},
  source_memory_ids:clone(body.source_memory_ids),evidence_refs:body.source_memory_ids.map(id=>'hotel_operating_memories#'+id),validation_status:'candidate',validation_note:'',content_digest:sha(JSON.stringify(body)).toLowerCase(),lifecycle_status:'active',created_by:901,validated_by:0,validated_at:null,created_at:'2026-09-15 09:00:00',updated_at:'2026-09-15 09:00:00',deleted_at:null,
});
const enter=async()=>{
  let nav=await inspect(true);const parent=nav.nodes.find(node=>node.type==='a'&&node.props?.['aria-label']==='系统与工具');ok(parent&&visible(nav,parent));parent.props.onClick();await tick();nav=await inspect(true);
  const entry=nav.nodes.find(node=>node.type==='a'&&node.props?.['data-testid']==='nav-knowledge-center');ok(entry&&visible(nav,entry));entry.props.onClick({stopPropagation(){}});await tick();eq(state.currentPage,'knowledge-center');
  await switchHotel('7');await finishLists(7);eq(state.knowledgeSopCandidateEligibleMemories.length,1);
};
const fill=async(hotel=7)=>{await chooseMemory(hotel);await nativeText('title','Synthetic candidate SOP '+hotel);await nativeText('steps_text','Review synthetic channel evidence\nRecord manual observation');};
const acceptPost=async save=>{const version=versionFor(save.post.body);reply(save.post,{code:200,data:{version,created:true,persistence_status:'readback_verified',write_boundaries:{automatic_publish:false,automatic_execution:false,ota_write:false,external_message:false}}});await tick();return version;};
const exactGet=id=>{const get=requests.findLast(call=>!call.settled&&call.method==='GET'&&new URL(call.url).pathname==='/api/operation/operating-sops/'+id);ok(get,'Original exact GET is pending');return get;};
const noReceipt=async()=>{eq(state.knowledgeSopCandidateReadback,null);eq(state.knowledgePromotionForm.source_version_id,'');eq(state.knowledgeSopCandidateError,'');const view=await inspect();eq(view.nodes.some(node=>node.props?.['data-testid']==='knowledge-sop-candidate-readback'),false);};
const busy=async()=>{eq(state.knowledgeSopCandidateAction,'save');const view=await inspect(),button=view.nodes.find(node=>node.type==='button'&&node.props?.onClick?.toString().includes('createKnowledgeSopCandidate'));ok(button&&visible(view,button)&&disabled(view,button),'New request retains its native save lock');};
const cleanup=async()=>{
  // On an original-source red, a failed oracle may leave an exact GET or its
  // ordinary follow-up lists pending. Close transport before awaiting handlers.
  for(let pass=0;pass<8;pass++){
    for(const call of requests.filter(call=>!call.settled))reply(call,{code:500,message:'Synthetic cleanup',data:null},500);
    await tick();
    if(!requests.some(call=>!call.settled))break;
  }
  await Promise.allSettled(inFlight);
  ok(requests.every(call=>call.settled),'Every synthetic request is closed');
};
return{state,requests,notices,tick,inspect,reply,switchHotel,finishLists,nativeText,chooseMemory,beginSave,versionFor,enter,fill,acceptPost,exactGet,noReceipt,busy,cleanup};
}

test('late candidate POST after local hotel switch cannot request old exact data or write B UI',async t=>{
  const start=assertions,h=harness();
  try{
    await h.enter();await h.fill();const save=await h.beginSave();
    await h.switchHotel('8');await h.finishLists(8);await h.noReceipt();
    const count=h.requests.length,notices=h.notices.length;
    await h.acceptPost(save);
    eq(h.requests.length,count,'Stale POST must not start an old-hotel exact GET');
    eq(await save.pending,null);await h.noReceipt();eq(h.state.knowledgePromotionHotelId,'8');eq(h.notices.length,notices);eq(h.state.knowledgeSopCandidateAction,'');
    eq(h.requests.filter(call=>call.method==='POST').length,1);eq(h.requests.filter(call=>call.method==='GET').length,6);
  }finally{await h.cleanup();}
  t.diagnostic(`${assertions-start} assertions; original A to B control path; no stale follow-up request`);
});

test('old exact GET is rejected after A to B to A even when hotel identity matches again',async t=>{
  const start=assertions,h=harness();
  try{
    await h.enter();await h.fill();const save=await h.beginSave(),version=await h.acceptPost(save),get=h.exactGet(801);
    await h.switchHotel('8');await h.finishLists(8);await h.switchHotel('7');await h.finishLists(7);await h.noReceipt();
    const count=h.requests.length,notices=h.notices.length;
    h.reply(get,{code:200,data:version});await h.tick();
    eq(h.requests.length,count,'Stale exact GET must not refresh or reselect an old candidate');
    eq(await save.pending,null);await h.noReceipt();eq(h.state.knowledgePromotionHotelId,'7');eq(h.notices.length,notices);eq(h.state.knowledgeSopCandidateAction,'');
    eq(h.requests.filter(call=>call.method==='POST').length,1);eq(h.requests.filter(call=>call.method==='GET').length,10);
  }finally{await h.cleanup();}
  t.diagnostic(`${assertions-start} assertions; epoch rejects the ABA case without bypassing cancellation`);
});

test('same-hotel failure preserves draft, and old A failure/finally cannot unlock a new B save',async t=>{
  const start=assertions,h=harness();
  try{
    await h.enter();await h.fill();const draft=clone(h.state.knowledgeSopCandidateForm),failure=await h.beginSave();
    h.reply(failure.post,{code:500,message:'Synthetic same-hotel save unavailable',data:null},500);await failure.pending;await h.tick();
    eq(clone(h.state.knowledgeSopCandidateForm),draft);eq(h.state.knowledgeSopCandidateReadback,null);eq(h.state.knowledgeSopCandidateAction,'');ok(h.state.knowledgeSopCandidateError.includes('Synthetic same-hotel save unavailable'));
    const old=await h.beginSave();await h.switchHotel('8');await h.finishLists(8);await h.fill(8);
    eq(h.state.knowledgeSopCandidateAction,'','Switching hotels releases the obsolete builder lock');
    const current=await h.beginSave(8),notices=h.notices.length;await h.busy();
    h.reply(old.post,{code:500,message:'Synthetic obsolete A failure',data:null},500);await old.pending;await h.tick();
    await h.busy();eq(h.state.knowledgeSopCandidateError,'');eq(h.notices.length,notices);eq(h.state.knowledgeSopCandidateReadback,null);
    const version=await h.acceptPost(current),get=h.exactGet(802);await h.busy();
    h.reply(get,{code:200,data:version});await h.tick();await h.busy();await h.finishLists(8,[version]);eq(clone(await current.pending),version);await h.tick();
    eq(h.state.knowledgePromotionHotelId,'8');eq(h.state.knowledgeSopCandidateReadback.hotel_id,8);eq(h.state.knowledgeSopCandidateReadback.id,802);eq(h.state.knowledgeSopCandidateReadback.validation_status,'candidate');eq(h.state.knowledgeSopCandidateReadback.lifecycle_status,'active');
    eq(h.state.knowledgePromotionForm.source_version_id,'802');eq(h.state.knowledgePromotionSourceCandidates.length,1);eq(h.state.knowledgeSopCandidateAction,'');eq(h.state.knowledgeSopCandidateError,'');
    const view=await h.inspect(),readback=view.nodes.find(node=>node.props?.['data-testid']==='knowledge-sop-candidate-readback');ok(readback,'Current B exact receipt is rendered');
    eq(h.notices.filter(notice=>notice.type==='success'&&notice.message.includes('候选 SOP 已保存并精确回读')).length,1);
    eq(h.requests.filter(call=>call.method==='POST').length,3);eq(h.requests.filter(call=>call.method==='GET').length,10);
  }finally{await h.cleanup();}
  t.diagnostic(`${assertions-start} assertions; same-hotel 500 recovery, independent request ownership and normal candidate readback`);
});
