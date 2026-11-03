import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source=readFileSync('public/app-main.js','utf8');
const between=(start,end)=>{
  const from=source.indexOf(start),to=source.indexOf(end,from);
  assert.ok(from>=0&&to>from,`production function missing: ${start}`);
  return source.slice(from,to);
};
const auth=between('let authSessionEpoch = 0;','const createDefaultAuthContext =');
const scope=between('let agentRevenueStateEpoch = 0;','const resetAgentCenterClientState =');
const review=between('const reviewPriceSuggestion = async','// 加载Agent日志');
const loader=between('const loadPriceSuggestions = async','const changePriceSuggestionPage = async');
const validator=between('const resolvePriceSuggestionListPayload =','const loadPriceSuggestions = async');
const display=between('const optionalFiniteNumber = (value) => {','const competitorAlertPriceText =');
const counters=(source.match(/^\s*let (?:priceSuggestionsRequestSequence|priceSuggestionReviewRequestSequence) = 0;/gm)||[]).join('\n');
const ref=value=>({value});
const deferred=()=>{let resolve,reject;const promise=new Promise((r,j)=>{resolve=r;reject=j;});return {promise,resolve,reject};};

function harness(){
  const state={hotel:ref('80'),token:ref('synthetic-session-A'),filter:ref({date:'2026-09-08',end_date:'2026-09-08',status:0}),
    rows:ref([]),review:ref(null),pagination:ref({total:0,page:1,page_size:20,total_page:0}),load:null};
  const requests=[],toasts=[];
  const context=vm.createContext({URLSearchParams,filterReportHotel:state.hotel,token:state.token,
    priceSuggestionFilter:state.filter,priceSuggestions:state.rows,priceSuggestionReview:state.review,
    priceSuggestionPagination:state.pagination,computed:fn=>({get value(){return fn();}}),
    createPriceSuggestionPagination:()=>({total:0,page:1,page_size:20,total_page:0}),
    priceSuggestionRangeError:()=>'',setRevenueLoadState:(key,status,error='')=>{state.load={key,status,error};},
    showToast:(...args)=>toasts.push(args),console:{error(){}},
    request:endpoint=>{const gate=deferred();requests.push({endpoint,...gate});return gate.promise;},
  });
  vm.runInContext(`${counters}${auth}${scope}${validator}${loader}${review}${display};this.read=reviewPriceSuggestion;this.load=loadPriceSuggestions;this.bumpSession=()=>{authSessionEpoch+=1;};this.projection=()=>({comparable:priceSuggestionReviewHasComparableSamples.value,amount:priceSuggestionReviewMetricText(priceSuggestionReview.value?.delta?.amount,'¥'),adr:priceSuggestionReviewMetricText(priceSuggestionReview.value?.delta?.adr,'¥')});`,context);
  return {state,requests,toasts,read:context.read,load:context.load,bumpSession:context.bumpSession,projection:context.projection};
}
function receipt(marker,{id=8001,amount=900,adr=60,status='ok'}={}){
  const common={system_hotel_id:80,platforms:['ctrip'],source:'online_daily_data',scope:'online_ota_operating_sample',
    metric_scope:'ota_channel',data_status:status,evidence_status:'trusted_deduplicated',sample_count:7,trusted_fact_count:7};
  return {code:200,data:{marker,suggestion:{id,hotel_id:80,suggestion_date:'2026-09-08'},anchor_date:'2026-09-08',
    before:{...common,start_date:'2026-09-01',end_date:'2026-09-07'},
    after:{...common,start_date:'2026-09-08',end_date:'2026-09-14'},delta:{amount,adr}}};
}
async function acceptEmptyList(h,date){
  h.state.filter.value={date,end_date:date,status:0};
  const index=h.requests.length,pending=h.load({silent:true});
  h.requests[index].resolve({code:200,data:{list:[],pagination:{total:0,page:1,page_size:20,total_page:0},
    query_scope:{hotel_id:80,platform:'ctrip',start_date:date,end_date:date}}});
  await pending;assert.equal(h.state.load.status,'empty');assert.equal(h.state.review.value,null);
}

test('R01 new date list cannot be filled by a late old-date review',async()=>{
  const h=harness(),pending=h.read(8001);await acceptEmptyList(h,'2026-09-22');
  h.requests[0].resolve(receipt('old date'));await pending;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,0);
});
test('R02 a changed end date invalidates the pending review before a new request',async()=>{
  const h=harness(),pending=h.read(8001);h.state.filter.value.end_date='2026-09-09';
  h.requests[0].resolve(receipt('old end date'));await pending;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,0);
});
test('R03 latest same-date repeat request owns its receipt',async()=>{
  const h=harness(),old=h.read(8001),latest=h.read(8001);
  h.requests[1].resolve(receipt('latest'));await latest;
  h.requests[0].resolve(receipt('old'));await old;
  assert.equal(h.state.review.value.marker,'latest');assert.equal(h.toasts.length,1);
});
test('R04 latest selected suggestion on the same date owns the shared review',async()=>{
  const h=harness(),old=h.read(8001),latest=h.read(8002);
  h.requests[1].resolve(receipt('latest suggestion',{id:8002}));await latest;
  h.requests[0].resolve(receipt('old suggestion'));await old;
  assert.equal(h.state.review.value.suggestion.id,8002);assert.equal(h.toasts.length,1);
});
test('R05 an obsolete same-date error cannot toast over a newer accepted review',async()=>{
  const h=harness(),old=h.read(8001),latest=h.read(8001);
  h.requests[1].resolve(receipt('latest'));await latest;
  h.requests[0].reject(new Error('obsolete error'));await old;
  assert.equal(h.state.review.value.marker,'latest');assert.equal(h.toasts.length,1);
});
test('R06 earlier success cannot hide current failure and explicit retry can recover',async()=>{
  const h=harness(),old=h.read(8001),latest=h.read(8001);
  h.requests[1].reject(new Error('current error'));await latest;
  h.requests[0].resolve(receipt('old'));await old;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,1);
  assert.match(h.toasts[0][0],/current error/);assert.equal(h.toasts[0][1],'error');
  const retry=h.read(8001);h.requests[2].resolve(receipt('recovered'));await retry;
  assert.equal(h.state.review.value.marker,'recovered');assert.equal(h.toasts.length,2);
});
test('R07 a date switched away then back does not revive an earlier pending review',async()=>{
  const h=harness(),pending=h.read(8001);
  await acceptEmptyList(h,'2026-09-22');await acceptEmptyList(h,'2026-09-08');
  h.requests[0].resolve(receipt('before date round trip'));await pending;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,0);
});
test('R08 hotel switch rejects the old response',async()=>{
  const h=harness(),pending=h.read(8001);h.state.hotel.value='81';
  h.requests[0].resolve(receipt('old hotel'));await pending;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,0);
});
test('R09 account session token switch rejects the old response',async()=>{
  const h=harness(),pending=h.read(8001);h.state.token.value='synthetic-session-B';
  h.requests[0].resolve(receipt('old account'));await pending;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,0);
});
test('R10 a session epoch change suppresses an obsolete error',async()=>{
  const h=harness(),pending=h.read(8001);h.bumpSession();
  h.requests[0].reject(new Error('old session error'));await pending;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,0);
});
test('R11 a legal current same-date review retains complete numeric changes',async()=>{
  const h=harness(),pending=h.read(8001);h.requests[0].resolve(receipt('current'));await pending;
  assert.equal(h.state.review.value.marker,'current');assert.equal(h.toasts.length,1);
  assert.equal(h.projection().comparable,true);assert.equal(h.projection().amount,'¥900');assert.equal(h.projection().adr,'¥60');
});
test('R12 legal complete zero remains a real displayed zero',async()=>{
  const h=harness(),pending=h.read(8001);h.requests[0].resolve(receipt('zero',{amount:0,adr:0}));await pending;
  assert.equal(h.projection().comparable,true);assert.equal(h.projection().amount,'¥0');assert.equal(h.projection().adr,'¥0');
});
test('R13 legal missing amount and ADR remain null and display a dash',async()=>{
  const h=harness(),pending=h.read(8001);h.requests[0].resolve(receipt('missing',{amount:null,adr:null}));await pending;
  assert.equal(h.state.review.value.delta.amount,null);assert.equal(h.state.review.value.delta.adr,null);
  assert.equal(h.projection().amount,'—');assert.equal(h.projection().adr,'—');
});
test('R14 a current numeric partial receipt retains the existing guard',async()=>{
  const h=harness(),pending=h.read(8001);h.requests[0].resolve(receipt('partial',{status:'partial'}));await pending;
  assert.equal(h.projection().comparable,false);assert.equal(h.projection().amount,'—');assert.equal(h.projection().adr,'—');
});
test('R15 a failed current review request cannot keep the prior review visible',async()=>{
  const h=harness();h.state.review.value=receipt('previous').data;
  const pending=h.read(8002);h.requests[0].resolve({code:422,message:'current review unavailable'});await pending;
  assert.equal(h.state.review.value,null);assert.equal(h.toasts.length,1);
  assert.equal(h.toasts[0][0],'current review unavailable');assert.equal(h.toasts[0][1],'error');
});
