import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';
const source=fs.readFileSync(process.env.SUXIOS_PRICE_REVIEW_MAIN_SOURCE||'public/app-main.js','utf8');
const take=name=>{const start=source.indexOf('            const '+name+' ='),end=/\n            (?:const|let) /.exec(source.slice(start+1));assert.ok(start>=0&&end,name);return source.slice(start,start+1+end.index);};
const production=['resolveDemandForecastListPayload','resolvePriceSuggestionListPayload',...['applyRevenueAiOverviewReadback','captureRevenueForecastRange','isRevenueForecastRangeCurrent','applyRevenueDashboardReadback','applyRevenueAnalysisReadback'].filter(name=>source.includes('            const '+name+' =')),...(source.includes('            const applyRoomTypeReadback =')?['applyRoomTypeReadback']:[]),...(source.includes('            const applyDemandForecastReadback =')?['applyDemandForecastReadback']:[]),'captureAgentRevenueRequestContext','isAgentRevenueRequestCurrent','createPriceSuggestionPagination','createRevenueLoadState','createEmptyRevenueAnalysisData','createEmptyRevenueDashboard','setRevenueLoadState','priceSuggestionRangeError','loadPriceSuggestions','loadRevenueAnalysisBundle','generatePriceSuggestions','reviewPriceSuggestion'].map(take).join('\n');
const copy=x=>JSON.parse(JSON.stringify(x));
function harness(){
 const refs=Object.fromEntries([...new Set([...production.matchAll(/\b(\w+)\.value\b/g)].map(m=>m[1]))].map(n=>[n,{value:null}]));
 const initial={filterReportHotel:'81',priceSuggestionFilter:{date:'2026-09-27',end_date:'2026-09-27',status:0},priceSuggestionPagination:{page:1,page_size:20,total:2,total_page:1},priceSuggestions:[{id:41},{id:42}],priceSuggestionReview:null,forecastFilter:{start_date:'2026-09-27',end_date:'2026-09-27'},competitorFilter:{date:'2026-09-27'},demandForecastForm:{},competitorPriceForm:{},revenueAiBusinessDate:'2026-09-27'};
 for(const[k,v]of Object.entries(initial))refs[k]={value:v};
 const queue=[],toasts=[];let session=1;
 const c=vm.createContext({...refs,URLSearchParams,console:{error(){}},agentRevenueStateEpoch:1,priceSuggestionRequestSeq:0,priceSuggestionReviewRequestSeq:0,revenueAnalysisBundleRequestSeq:0,roomTypesRequestSequence:0,demandForecastsRequestSequence:0,revenueAiOverviewRequestSeq:0,revenueAiOverviewRequestPromises:new Map(),
  captureAuthSession:()=>session,isAuthSessionCurrent:s=>s===session,ensureRevenueAiStaticReady:async()=>true,loadRevenueAiOverview:async()=>null,resetCompetitorAnalysisView:()=>{},firstEnabledRoomTypeId:()=>0,formatDate:()=> '2026-09-27',revenueAiResolveOverviewResponse:({response})=>({overview:response.data,errorMessage:''}),loadCompetitorAnalysis:async({priceResponsePromise})=>priceResponsePromise.catch(()=>null),revenueAiBuildPriceSuggestionGenerateResult:()=>({message:'synthetic generation failure'}),showToast:(...x)=>toasts.push(x),request:(url,options={})=>new Promise((resolve,reject)=>queue.push({url,options,resolve,reject}))});
 vm.runInContext(production+'\nrevenueLoadState.value=createRevenueLoadState();globalThis.api={review:reviewPriceSuggestion,list:loadPriceSuggestions,bundle:loadRevenueAnalysisBundle,generate:generatePriceSuggestions};',c);
 const snapshot=()=>copy({review:refs.priceSuggestionReview.value,toasts});
 const settle=(index,outcome='success',id=41)=>{
  const q=queue[index];assert.ok(q);
  if(outcome==='throw')q.reject(new Error('synthetic review failed'));
  else q.resolve(outcome==='failed'?{code:503,message:'synthetic review failed'}:{code:200,data:{suggestion:{id,hotel_id:81},anchor_date:id===41?'2026-09-12':'2026-09-13',before:{},after:{},delta:{},scope_notice:'仅OTA合成样本'}});
 };
 return{refs,c,api:c.api,queue,toasts,snapshot,settle,setSession:v=>{session=v;}};
}
for(const outcome of ['success','failed','throw'])test(`older suggestion ${outcome} cannot replace latest selected review`,async()=>{
 const h=harness(),old=h.api.review(41),current=h.api.review(42);
 h.settle(1,'success',42);await current;const before=h.snapshot();
 h.settle(0,outcome,41);await old;assert.deepEqual(h.snapshot(),before);
});
test('choosing another suggestion removes the previously displayed review immediately',async()=>{
 const h=harness(),first=h.api.review(41);h.settle(0);await first;
 const next=h.api.review(42);assert.equal(h.refs.priceSuggestionReview.value,null);
 h.settle(1,'success',42);await next;assert.equal(h.refs.priceSuggestionReview.value.suggestion.id,42);
});
test('bundle refresh clears a completed old review before its response arrives',async()=>{
 const h=harness(),review=h.api.review(41);h.settle(0);await review;
 const reload=h.api.bundle();await new Promise(setImmediate);
 assert.equal(h.refs.priceSuggestionReview.value,null);
 h.queue[1].resolve({code:200,data:{price_suggestions:{list:[],pagination:{page:1}}}});await reload;
});
test('generation invalidates the pending review it explicitly clears without real writes',async()=>{
 const h=harness(),review=h.api.review(41),generation=h.api.generate();
 assert.equal(h.queue[1].options.method,'POST'); // A deferred local Promise only; no HTTP client exists in this harness.
 const before=h.snapshot();h.settle(0);await review;assert.deepEqual(h.snapshot(),before);
 h.queue[1].resolve({code:503,message:'synthetic generation failure'});await generation;
 assert.equal(h.refs.priceSuggestionGenerating.value,false);
});
for(const loader of ['list','bundle'])for(const outcome of ['success','failed','throw'])test(`${loader} reload invalidates pending review ${outcome}`,async()=>{
 const h=harness(),review=h.api.review(41),reload=h.api[loader]();
 await new Promise(setImmediate);assert.equal(h.queue.length,2);
 const before=h.snapshot();h.settle(0,outcome);await review;assert.deepEqual(h.snapshot(),before);
 h.queue[1].resolve({code:200,data:loader==='list'?{list:[],pagination:{page:1}}:{price_suggestions:{list:[],pagination:{page:1}}}});await reload;
 assert.equal(h.refs.priceSuggestionReview.value,null);
});
for(const field of ['date','end_date','status','page','hotel','session','epoch'])test(`changed ${field} rejects old review without needing a replacement request`,async()=>{
 const h=harness(),pending=h.api.review(41);
 if(field==='hotel')h.refs.filterReportHotel.value='82';
 else if(field==='session')h.setSession(2);
 else if(field==='epoch')h.c.agentRevenueStateEpoch++;
 else if(field==='page')h.refs.priceSuggestionPagination.value.page=2;
 else h.refs.priceSuggestionFilter.value[field]=field==='status'?4:'2026-09-28';
 const before=h.snapshot();h.settle(0);await pending;assert.deepEqual(h.snapshot(),before);
});
test('closing the latest completed review is not undone by an older pending response',async()=>{
 const h=harness(),old=h.api.review(41),current=h.api.review(42);h.settle(1,'success',42);await current;
 h.refs.priceSuggestionReview.value=null;const before=h.snapshot();h.settle(0);await old;assert.deepEqual(h.snapshot(),before);
});
for(const outcome of ['failed','throw'])test(`current review ${outcome} is reported and original button retry recovers`,async()=>{
 const h=harness(),failed=h.api.review(41);h.settle(0,outcome);await failed;
 assert.equal(h.refs.priceSuggestionReview.value,null);assert.equal(h.toasts.length,1);assert.equal(h.toasts[0][1],'error');
 const retry=h.api.review(41);h.settle(1);await retry;assert.equal(h.refs.priceSuggestionReview.value.suggestion.id,41);assert.equal(h.toasts.length,2);
});
