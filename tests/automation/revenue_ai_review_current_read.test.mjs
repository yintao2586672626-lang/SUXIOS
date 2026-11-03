import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const helperContext={window:{},URLSearchParams};
for(const file of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js','data-health-static.js'])vm.runInNewContext(readFileSync(`public/${file}`,'utf8'),helperContext);
const helper=helperContext.window.SUXI_REVENUE_AI_STATIC;
const pending=()=>({id:11,hotel_id:20,suggestion_date:'2026-08-20',suggested_price:110,current_price_display:'100元',suggested_price_display:'110元',can_review:true,can_confirm:true,auto_write_ota:false,
 trusted_decision:{contract_version:'revenue_ai_trusted_decision.v1',store:{hotel_id:20},platform:{key:'meituan'},date:{value:'2026-08-20'},sources:{status:'verified'},data_quality:{label:'已验证',status:'verified'},metric_formula:{display:'+10%',status:'calculable'},gaps:[],human_confirmation:{can_confirm:true}},
 action_entry:{target_filter:{hotel_id:20,date:'2026-08-20'},allowed_endpoints:{review:'/api/revenue-ai/price-suggestions/11/review'},manual_actions:['approve','approve_with_changes','reject']}});
const overview=()=>({hotel_id:20,business_date:'2026-08-20',as_of_date:'2026-08-20',as_of_date_contract_version:'revenue_overview_as_of_date.v1',actions:[{key:'pricing_review',status:'pending_review',review_queue:{pending_items:[pending()]},decision_basis_summary:{display:'fixture历史依据已验证',status:'ok',ready_count:1,items:[{key:'history',status:'ok'}]}}]});
const rows=(data,extra={})=>helper.buildRevenueAiActionRows({overview:data,...extra});

test('503 and wrong-scope current failures retain history immutably but expose no current review actions',()=>{
 for(const error of ['HTTP503','wrong hotel/date']){
  const source=overview(),before=JSON.stringify(source),row=rows(source,{overviewError:error})[0],item=row.reviewQueueItems[0];
  assert.equal(JSON.stringify(source),before);assert.equal(item.id,11);assert.equal(item.trustedDecision.data_quality.status,'verified');
  assert.equal(row.statusLabel,'读取失败');assert.equal(row.decisionBasisReadyCount,null);assert.equal(row.decisionBasisItems.length,0);
  assert.match(row.decisionBasisDisplay,/当前总览读取失败/);assert.equal(item.actionButtons.length,0);
  for(const flag of ['canReview','canApprove','canApproveWithChanges','canReject','canCreateExecutionIntent'])assert.equal(item[flag],false);
  for(const key of ['quality','gaps'])assert.match(item.trustedDecisionRows.find(f=>f.key===key).value,/尚未重新复核/);
  assert.match(item.trustedDecisionRows.find(f=>f.key==='formula').value,/历史记录/);
  assert.equal(helper.resolveRevenueAiReviewActionDraft({item,action:'approve'}).ok,false);
 }
});
test('in-flight overview projects a pending current read then restores exactly the same ID and allowed buttons',()=>{
 const source=overview(),loading=rows(source,{overviewLoading:true})[0];assert.equal(loading.statusLabel,'正在读取');assert.equal(loading.reviewQueueItems[0].actionButtons.length,0);
 const restored=rows(source)[0].reviewQueueItems[0];assert.equal(restored.id,11);assert.deepEqual(Array.from(restored.actionButtons,b=>b.key),['approve','approve_with_changes','reject']);assert.equal(restored.currentReadReason,undefined);
});
test('fresh quality-blocked saved suggestion keeps reject policy; legacy draft defaults remain compatible',()=>{
 const source=overview();source.actions[0].review_queue.pending_items[0].trusted_decision.human_confirmation.can_confirm=false;
 const item=rows(source)[0].reviewQueueItems[0];assert.deepEqual(Array.from(item.actionButtons,b=>b.key),['reject']);
 assert.equal(helper.resolveRevenueAiReviewActionDraft({item,action:'reject'}).ok,true);
 for(const context of [{overviewError:'503'},{overviewLoading:true},{currentActionAvailable:false}])assert.equal(helper.resolveRevenueAiReviewActionDraft({item,action:'reject',...context}).ok,false);
 assert.equal(helper.resolveRevenueAiReviewActionDraft({item:{id:11,allowedEndpoints:{review:'/revenue-ai/price-suggestions/11/review'}},action:'reject'}).ok,true);
});

const main=readFileSync('public/app-main.js','utf8');
const start=main.indexOf('            const submitRevenueAiReviewAction = async'),end=main.indexOf('            const openRevenueAiReviewItem = async',start);
assert.ok(start>=0&&end>start);
const submitSource=main.slice(start,end);
const harness=()=>{
 const state={overview:overview(),error:'',loading:false,hotel:'20',date:'2026-08-20',page:'compass',epoch:0,events:[],form:null,confirm:null};
 const ref=key=>({get value(){return state[key];},set value(value){state[key]=value;}});
 const context={revenueAiOverview:ref('overview'),revenueAiOverviewError:ref('error'),revenueAiOverviewLoading:ref('loading'),filterReportHotel:ref('hotel'),currentPage:ref('page'),
  revenueAiActionRows:{get value(){return rows(state.overview,{overviewError:state.error,overviewLoading:state.loading});}},
  currentPageReadPolicy:()=>({businessDate:state.date}),captureAuthSession:()=>state.epoch,isAuthSessionCurrent:epoch=>epoch===state.epoch,
  ensureRevenueAiStaticReady:async()=>{},showToast:message=>state.events.push({kind:'toast',message}),
  revenueAiResolveReviewActionDraft:helper.resolveRevenueAiReviewActionDraft,revenueAiResolveOverviewResponse:helper.resolveRevenueAiOverviewResponse,revenueAiBuildReviewConfirmText:helper.buildRevenueAiReviewConfirmText,revenueAiBuildReviewRequestBody:helper.buildRevenueAiReviewRequestBody,revenueAiValidateApprovedPrice:helper.validateRevenueAiApprovedPrice,
  collectPriceExecutionIntentFields:async()=>{state.events.push({kind:'execution-form'});return state.form?state.form():null;},
  openWorkflowFormDialog:async config=>{state.events.push({kind:'price-form',value:config.fields[0].value});return state.form?state.form():null;},
  window:{confirm:()=>{state.events.push({kind:'confirm'});return state.confirm?state.confirm():false;}},
  setRevenueAiReviewActionLoading:()=>{},request:async()=>{state.events.push({kind:'POST-denied'});throw Error('local_synthetic_fixture_only: no network or business writes');},loadRevenueAiOverview:async()=>{},
 };
 vm.runInNewContext(submitSource+';this.submit=submitRevenueAiReviewAction;',context);
 return {state,context,item:()=>rows(state.overview)[0].reviewQueueItems[0],submit:context.submit};
};
test('original submit cancels fresh confirmation normally and blocks every action before dialogs on failed/loading reads',async()=>{
 const normal=harness();await normal.submit(normal.item(),'approve');assert.deepEqual(normal.state.events.map(e=>e.kind),['confirm']);
 for(const status of ['error','loading'])for(const action of ['approve','approve_with_changes','reject','execution_intent']){
  const h=harness(),old=h.item();h.state[status]=status==='error'?'HTTP503':true;await h.submit(old,action);assert.deepEqual(h.state.events.map(e=>e.kind),['toast']);
 }
});
test('original submit resolves current same-ID values and refuses a removed or wrong-scope old object',async()=>{
 const h=harness(),old=h.item();h.state.overview.actions[0].review_queue.pending_items[0].suggested_price=130;
 await h.submit(old,'approve_with_changes');assert.equal(h.state.events[0].value,'130');
 for(const mutate of [s=>{s.overview.actions[0].review_queue.pending_items=[];},s=>{s.overview.actions[0].review_queue.pending_items[0].hotel_id=21;},s=>{s.overview.actions[0].review_queue.pending_items[0].suggestion_date='2026-08-21';}]){
  const h=harness(),old=h.item();mutate(h.state);await h.submit(old,'approve');assert.deepEqual(h.state.events.map(e=>e.kind),['toast']);
 }
});
test('async price form invalidation before confirmation blocks error, refresh, auth, hotel and date changes',async()=>{
 for(const mutate of [s=>{s.error='503';},s=>{s.loading=true;},s=>{s.overview=overview();},s=>{s.hotel='21';},s=>{s.date='2026-08-21';},s=>{s.epoch++;},s=>{s.page='other';}]){
  const h=harness();h.state.form=()=>{mutate(h.state);return {approved_price:110,review_remark:'fixture only'};};
  await h.submit(h.item(),'approve_with_changes');assert.deepEqual(h.state.events.map(e=>e.kind),['price-form','toast']);
 }
});
test('synchronous confirmation cannot submit after its current scope or overview was invalidated',async()=>{
 for(const mutate of [s=>{s.error='503';},s=>{s.loading=true;},s=>{s.hotel='21';},s=>{s.date='2026-08-21';},s=>{s.overview=overview();},s=>{s.epoch++;}]){
  const h=harness();h.state.confirm=()=>{mutate(h.state);return true;};await h.submit(h.item(),'approve');assert.deepEqual(h.state.events.map(e=>e.kind),['confirm','toast']);
 }
});
test('async execution handoff form cannot continue when its saved suggestion read becomes invalid',async()=>{
 const h=harness(),source=h.state.overview.actions[0].review_queue.pending_items[0];
 source.can_review=false;source.can_confirm=false;source.can_transfer_to_operation_task=true;
 source.trusted_decision.human_confirmation.can_confirm=false;
 source.trusted_decision.human_confirmation.can_transfer_to_operation_task=true;
 source.action_entry.manual_actions=['create_execution_intent'];
 source.action_entry.allowed_endpoints.execution_intent='/api/revenue-ai/price-suggestions/11/execution-intent';
 assert.deepEqual(Array.from(h.item().actionButtons,b=>b.key),['execution_intent']);
 h.state.form=()=>{h.state.error='503';return {execution_date:'2026-08-20'};};
 await h.submit(h.item(),'execution_intent');assert.deepEqual(h.state.events.map(e=>e.kind),['execution-form','toast']);
});
test('new helper readiness await failures cannot use a prior current action',async()=>{
 const h=harness();h.context.ensureRevenueAiStaticReady=async()=>{h.state.error='helper changed';};await h.submit(h.item(),'approve');assert.deepEqual(h.state.events.map(e=>e.kind),['toast']);
});
test('a scope switch before invocation cannot use retained prior-hotel or prior-date suggestions',async()=>{
 for(const mutate of [s=>{s.hotel='21';},s=>{s.date='2026-08-21';}]){
  const h=harness(),old=h.item();mutate(h.state);await h.submit(old,'approve');assert.deepEqual(h.state.events.map(e=>e.kind),['toast']);
 }
});
