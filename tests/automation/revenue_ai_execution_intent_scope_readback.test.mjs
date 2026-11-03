import test from 'node:test';import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';
const helpers={window:{},URLSearchParams};for(const f of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js','data-health-static.js','operation-static.js'])vm.runInNewContext(fs.readFileSync('public/'+f,'utf8'),helpers);
const ai=helpers.window.SUXI_REVENUE_AI_STATIC,op=helpers.window.SUXI_OPERATION_STATIC;
const main=fs.readFileSync('public/app-main.js','utf8'),start=main.indexOf('            const submitRevenueAiReviewAction = async'),end=main.indexOf('            const openRevenueAiReviewItem = async',start),submit=main.slice(main.indexOf('            const revenueAiReviewIntentKey ='),main.indexOf('            const openRevenueAiExecutionItem = async'))+main.slice(start,end);
const fields={platform:'ctrip',execution_date:'2099-10-08',room_type_key:'fixture-room',rate_plan_key:'fixture-rate'};
const intent=()=>({id:90,hotel_id:20,source_module:'price_suggestion',source_record_id:12,platform:'ctrip',date_start:'2099-10-08',date_end:'2099-10-08',target_value:{room_type_key:'fixture-room',rate_plan_key:'fixture-rate'},status:'pending_approval',blocked_reason:'',tasks:[]});
async function run(saved, responseCode=200){
 const source={id:12,hotel_id:20,status:'approved',suggestion_date:'2026-08-20',suggested_price:110,auto_write_ota:false,can_transfer_to_operation_task:true,trusted_decision:{contract_version:'revenue_ai_trusted_decision.v1',store:{hotel_id:20},platform:{key:'ctrip'},date:{value:'2026-08-20'},sources:{status:'verified'},data_quality:{status:'verified'},metric_formula:{status:'calculable'},gaps:[],human_confirmation:{can_confirm:false,can_transfer_to_operation_task:true}},action_entry:{target_filter:{hotel_id:20,date:'2026-08-20'},allowed_endpoints:{execution_intent:'/api/revenue-ai/price-suggestions/12/execution-intent'},manual_actions:['create_execution_intent']}};
 const overview={hotel_id:20,business_date:'2026-08-20',as_of_date:'2026-08-20',as_of_date_contract_version:'revenue_overview_as_of_date.v1',actions:[{key:'pricing_review',status:'pending_review',review_queue:{recent_items:[source]}}]},rows=ai.buildRevenueAiActionRows({overview}),events=[];
 const ctx={revenueAiOverview:{value:overview},revenueAiOverviewError:{value:''},revenueAiOverviewLoading:{value:false},revenueAiActionRows:{value:rows},currentPage:{value:'compass'},filterReportHotel:{value:'20'},currentPageReadPolicy:()=>({businessDate:'2026-08-20'}),captureAuthSession:()=>1,isAuthSessionCurrent:()=>true,ensureRevenueAiStaticReady:async()=>{},showToast:(message,level)=>events.push({kind:'toast',message,level}),setRevenueAiReviewActionLoading:()=>{},collectPriceExecutionIntentFields:async()=>fields,window:{confirm:()=>true},revenueAiResolveOverviewResponse:ai.resolveRevenueAiOverviewResponse,revenueAiResolveReviewActionDraft:ai.resolveRevenueAiReviewActionDraft,revenueAiBuildReviewConfirmText:ai.buildRevenueAiReviewConfirmText,revenueAiBuildReviewRequestBody:ai.buildRevenueAiReviewRequestBody,revenueAiValidateApprovedPrice:ai.validateRevenueAiApprovedPrice,request:async()=>({code:responseCode,message:responseCode===200?'fixture saved':'scope mismatch',data:{execution_intent_existing:true,execution_intent:{id:90},operation_task_created:false}}),readOperationExecutionIntent:async(id,hotel)=>op.readOperationExecutionIntent(async(endpoint)=>{events.push({kind:'GET',endpoint});return {code:200,data:saved}},id,hotel),openRevenueAiExecutionItem:async()=>events.push({kind:'opened'}),revenueAiBuildExecutionIntentOpenRow:ai.buildRevenueAiExecutionIntentOpenRow,loadRevenueAiOverview:async()=>events.push({kind:'refreshed'})};
 Object.assign(ctx,{revenueAiReviewSubmittedIntents:{value:{}},revenueAiReviewIntentOpeningKey:{value:''},revenueAiReviewSubmittedIntentSession:null});
 vm.runInNewContext(submit+';this.submit=submitRevenueAiReviewAction',ctx);await ctx.submit(rows[0].reviewQueueItems[0],'execution_intent');return events;
}
test('same explicit scope preserves new and duplicate intent readback with hotel-qualified exact GET',async()=>{
 const events=await run(intent());assert.equal(events.some(e=>e.kind==='opened'),true);assert.match(events.find(e=>e.kind==='GET').endpoint,/hotel_id=20/);assert.equal(events.at(-2).message,'fixture saved');
});
test('different saved date/platform/room/rate/source/hotel cannot become success',async()=>{
 for(const key of ['date_start','date_end','platform','room_type_key','rate_plan_key','source_record_id','source_module','hotel_id']){
  const row=intent();if(key.endsWith('_key'))row.target_value[key]='other';else row[key]=key.endsWith('_id')?21:'other';
  const events=await run(row);assert.equal(events.some(e=>e.kind==='opened'),false,key);assert.equal(events.at(-1).level,'error',key);assert.equal(events.some(e=>e.message==='fixture saved'),false,key);
 }
});
test('missing or malformed target readback stays failed and matching correction recovers',async()=>{
 for(const target of [null,'corrupt',[],{}]){const row=intent();row.target_value=target;assert.equal((await run(row)).some(e=>e.kind==='opened'),false);}
 assert.equal((await run(intent())).some(e=>e.kind==='opened'),true);
});
test('backend409 leaves exact read/open/success untouched until matching retry',async()=>{
 const failed=await run(intent(),409);assert.equal(failed.some(e=>e.kind==='GET'||e.kind==='opened'),false);assert.equal(failed.at(-1).message,'scope mismatch');
 assert.equal((await run(intent())).some(e=>e.kind==='opened'),true);
});
