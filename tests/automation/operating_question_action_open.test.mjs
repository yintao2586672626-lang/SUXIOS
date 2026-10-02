import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(process.env.SUXI_QUESTION_OPEN_SOURCE||'public/app-main.js','utf8');
const cut=(start,end)=>source.slice(source.indexOf(start),source.indexOf(end,source.indexOf(start)));
const handlers=cut('const applyOperatingQuestionIntentReadback =','const operatingQuestionCouncilReadbackMatches =')+cut('const openOperatingQuestionActionIntent =','provide(\'operatingQuestionUi\'')+cut('const openRevenueCockpitPendingApproval =','const switchAgentTab =')+cut('const loadOperationActions = async','const parseOperationEvidenceNumber =');
const row=(source_module='revenue_cockpit_action')=>({id:901,hotel_id:20,tenant_id:7,platform:'ctrip',date_start:'2026-09-01',date_end:'2026-09-01',source_module,source_record_id:41,evidence:{question_content_digest:'a'.repeat(64),action_index:0},status:'pending_approval',tasks:[]});
const historyQuestion=record=>({id:41,hotel_id:20,tenant_id:7,platform:'ctrip',date_start:'2026-09-01',date_end:'2026-09-01',content_digest:'a'.repeat(64),action_intent_readback:{list:[{action_index:0,execution_intent:record}]}});
function createEnvironment(nextTick=async()=>{},initialPage='agent'){
 const ref=value=>({value});let fail='',trackingFail='',record=row();const calls=[],toasts=[];
 const env={URLSearchParams,operationActionsRequestSeq:0,suppressNextOpsTrackAutoLoad:false,
  operatingQuestionState:ref({action_error:''}),operationFilters:ref({hotel_id:'99'}),revenueAiExecutionFocus:ref(null),revenueCockpitPendingApproval:ref(null),currentPage:ref(initialPage),
  operationExecutionViewMode:ref('mine'),operationLoading:ref({actions:false}),operationError:ref({actions:''}),operatingGoalInterventionLoading:ref(false),operatingGoalInterventionError:ref(''),
  operationActionTrackingRead:ref({}),operationExecutionFlow:ref({list:[{id:99,hotel_id:99}]}),operationActions:ref([]),operationApprovalConfirmingIntentId:ref(0),operationEffectValidation:ref({}),operationClosureOverview:ref({}),operatingGoalInterventionOverview:ref({}),homeOperatingScheduleError:ref(''),filterReportHotel:ref('99'),operationYesterday:'2026-09-01',shanghaiBusinessYesterday:'2026-09-01',
  captureAuthSession:()=>1,isAuthSessionCurrent:s=>s===1,nextTick,showToast:(message)=>toasts.push(message),ensureOperationStaticReady:async()=>{},normalizeOperationHotelSelection:f=>f.value.hotel_id,currentPageReadPolicy:()=>({}),loadOperatingMemories:async()=>{},applyHomeOperatingScheduleFlow:()=>{},operationErrorMessage:e=>e.message,
  apiRequest:async(url,options)=>{calls.push({url,options});return url.startsWith('/operation/execution-flow')?(fail?{code:503,message:fail}:{code:200,data:{capabilities:{hotel_id:20},list:record?[record]:[],summary:{},stages:[],data_gaps:[],data_status:'ok',matched_total:record?1:0,returned_count:record?1:0,truncated:false,statistics:{execution_total_loaded:true}}}):(url.startsWith('/operation/closure-overview')?{code:200,data:{summary:{},modules:[],data_gaps:[],data_status:'ok'}}:url.startsWith('/operation/goal-intervention-overview')?{code:200,data:{hotel_id:20,data_status:'ok'}}:(trackingFail?{code:503,message:trackingFail}:{code:200,data:{hotel_id:20,actions:[],data_gaps:[],data_status:'ok',matched_total:0,returned_count:0,truncated:false,effect_validation:{metrics:[],data_gaps:[]}}}));},
 };
 env.operationExecutionItems={get value(){return env.operationExecutionFlow.value.list;}};
 return{env,calls,toasts,setFailure:value=>{fail=value;},setTrackingFailure:value=>{trackingFail=value;},setRecord:value=>{record=value;}};
}
function harness(page){const h=createEnvironment(undefined,page);const context=vm.createContext(h.env);vm.runInContext(handlers+';globalThis.openIntent=openOperatingQuestionActionIntent;globalThis.openRevenue=openRevenueCockpitPendingApproval;globalThis.restore=applyOperatingQuestionIntentReadback;',context);return{...h,open:context.openIntent,openRevenue:context.openRevenue,restore:context.restore};}
for(const source_module of ['operating_question','revenue_cockpit_action'])for(const page of ['agent','ops-track'])test(`${source_module} opens exact permitted intent from ${page}`,async()=>{
 const h=harness(page);h.setRecord(row(source_module));assert.equal(await h.open(row(source_module)),true);
 assert.equal(h.env.currentPage.value,'ops-track');assert.equal(h.env.operationFilters.value.hotel_id,'20');assert.equal(h.env.revenueAiExecutionFocus.value.intentId,901);assert.equal(h.env.operationExecutionViewMode.value,'all');
 const flow=h.calls.find(c=>c.url.startsWith('/operation/execution-flow'));const params=new URL(flow.url,'http://fixture.invalid').searchParams;
 assert.equal(params.get('intent_id'),'901');assert.equal(params.get('hotel_id'),'20');assert.equal(params.get('system_hotel_id'),'20');assert.equal(h.env.operationExecutionItems.value[0].id,901);assert.equal(h.env.operationExecutionItems.value[0].source_module,source_module);
 assert.equal(h.env.operatingQuestionState.value.action_error,'');assert.equal(h.env.operationLoading.value.actions,false);assert.equal(h.toasts.length,0);assert.equal(h.env.suppressNextOpsTrackAutoLoad,page!=='ops-track');
});
for(const invalid of [{id:0},{hotel_id:0},{source_module:'unknown'},{source_module:''}])test(`invalid open ${JSON.stringify(invalid)} does not navigate or read`,async()=>{
 const h=harness();assert.equal(await h.open({...row(),...invalid}),false);assert.equal(h.calls.length,0);assert.equal(h.env.currentPage.value,'agent');assert.match(h.env.operatingQuestionState.value.action_error,/精确回读/);
});
for(const [name,record] of [['wrong hotel',{...row(),hotel_id:21}],['wrong intent',{...row(),id:902}],['missing',null]])test(`${name} flow cannot masquerade as the requested saved intent`,async()=>{
 const h=harness();h.setRecord(record);assert.equal(await h.open(row()),false);assert.equal(h.env.operationExecutionItems.value.length,0);assert.equal(h.env.operationExecutionFlow.value.data_status,'load_failed');assert.equal(h.env.operationLoading.value.actions,false);assert.ok(h.toasts.length>0);
});
test('failed exact flow read can retry the original saved intent without creating or approving',async()=>{
 const h=harness();h.setFailure('合成读取失败');assert.equal(await h.open(row()),false);assert.equal(h.env.operationExecutionItems.value.length,0);assert.match(h.env.operationError.value.actions,/合成读取失败/);
 h.setFailure('');assert.equal(await h.open(row()),true);assert.equal(h.env.operationExecutionItems.value[0].id,901);assert.equal(h.env.operationExecutionItems.value[0].status,'pending_approval');assert.equal(h.env.operationExecutionItems.value[0].tasks.length,0);assert.equal(h.env.operationError.value.actions,'');assert.equal(h.env.operationLoading.value.actions,false);
 assert.ok(h.calls.every(c=>!c.options.method||c.options.method==='GET'));
});
test('partial operations read cannot claim the saved question action opened',async()=>{
 const h=harness();h.setTrackingFailure('合成追踪读取失败');
 assert.equal(await h.open(row()),false);
 assert.match(h.env.operationError.value.actions,/合成追踪读取失败/);
 assert.equal(h.env.operationExecutionItems.value[0]?.id,901,'original flow row remains available for retry');
 h.setTrackingFailure('');assert.equal(await h.open(row()),true);
});
test('revenue approval shortcut requires a complete exact operations read and can retry',async()=>{
 const h=harness();h.setTrackingFailure('合成收益追踪读取失败');
 assert.equal(await h.openRevenue(row()),false);
 assert.match(h.env.operationError.value.actions,/合成收益追踪读取失败/);
 assert.equal(h.env.operationExecutionItems.value[0]?.id,901);
 h.setTrackingFailure('');h.setRecord(null);
 assert.equal(await h.openRevenue(row()),false,'missing exact intent cannot be opened');
 h.setRecord(row());assert.equal(await h.openRevenue(row()),true);
 assert.equal(h.env.operationExecutionItems.value[0]?.id,901);
 assert.ok(h.calls.every(c=>!c.options.method||c.options.method==='GET'));
});
for(const source_module of ['operating_question','revenue_cockpit_action'])test(`${source_module} historical readback retains the same saved ID for reopening`,async()=>{
 const record=row(source_module);if(source_module==='revenue_cockpit_action'){record.source_record_id=73;record.evidence={};}
 const h=harness();h.setRecord(record);h.restore(historyQuestion(record));const restored=h.env.operatingQuestionState.value.action_intents['41:0'];
 assert.equal(restored?.id,901);assert.equal(restored.source_module,source_module);assert.equal(await h.open(restored),true);assert.equal(h.env.operationExecutionItems.value[0].id,901);
});
for(const source_module of ['operating_question','revenue_cockpit_action'])for(const [field,value] of [['hotel_id',21],['platform','meituan'],['date_start','2026-08-01'],['date_end','2026-09-02'],['id',0]])test(`history ${source_module} rejects mismatched ${field}`,()=>{
 const h=harness();h.restore(historyQuestion({...row(source_module),[field]:value}));assert.equal(Object.keys(h.env.operatingQuestionState.value.action_intents).length,0);
});
for(const changed of [{source_module:'unknown'},{source_record_id:42},{evidence:{question_content_digest:'b'.repeat(64),action_index:0}},{evidence:{question_content_digest:'a'.repeat(64),action_index:1}}])test(`question history preserves identity gate ${JSON.stringify(changed)}`,()=>{
 const h=harness();h.restore(historyQuestion({...row('operating_question'),...changed}));assert.equal(Object.keys(h.env.operatingQuestionState.value.action_intents).length,0);
});
