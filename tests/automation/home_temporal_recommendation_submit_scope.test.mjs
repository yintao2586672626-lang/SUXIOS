import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const start=source.indexOf('const submitHomeTemporalRecommendationForReview = async');
const end=source.indexOf('const loadMacroSignals = async',start);
assert.ok(start>=0&&end>start);
const setupStart=source.indexOf('const homeTemporalOperationSubmitScopeKey =');
const setupEnd=source.indexOf('const loadHomeTemporalInsights =',setupStart);
assert.ok(setupStart>=0&&setupEnd>setupStart);
const handler=source.slice(setupStart,setupEnd)+source.slice(start,end);
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return{promise,resolve};};
const drain=async()=>{for(let i=0;i<6;i++)await Promise.resolve();};
const intent=(overrides={})=>({id:901,hotel_id:80,source_module:'temporal_forecast_recommendation',source_record_id:51,status:'pending_approval',tasks:[],...overrides});
function harness(){
 const ref=value=>({value});let epoch=1,scopeWatch;const post=deferred(),posts=[post],read=deferred(),calls=[],toasts=[],loads=[];
 const env={token:ref('synthetic-session-a'),homeTemporalOperationRecommendation:ref({can_submit_for_review:true,forecast_point_id:51}),homeTemporalOperationSubmitting:ref(false),
  homeTemporalSelectedHotelId:ref('80'),currentPage:ref('home'),operationFilters:ref({hotel_id:'1'}),
  authSessionEpoch:1,watch:(getter,callback)=>{scopeWatch={getter,callback};},
  captureAuthSession:()=>epoch,isAuthSessionCurrent:value=>value===epoch,
  request:async(url,options)=>{const index=calls.filter(call=>call.kind==='post').length;if(!posts[index])posts[index]=deferred();calls.push({kind:'post',url,options});return posts[index].promise;},
  readOperationExecutionIntent:async(id,hotel)=>{calls.push({kind:'read',id,hotel});return read.promise;},
  operationExecutionHotelId:row=>Number(row.hotel_id||0),
  showToast:(message,level)=>toasts.push({message,level}),operationErrorMessage:error=>error.message,
  nextTick:async()=>{},loadOperationActions:async options=>{loads.push(options);return true;}};
 const context=vm.createContext(env);vm.runInContext(handler+';globalThis.submit=submitHomeTemporalRecommendationForReview;',context);
 return{env,post,posts,read,calls,toasts,loads,submit:context.submit,newSession:()=>{epoch++;env.authSessionEpoch=epoch;env.token.value='synthetic-session-b';scopeWatch.callback();},scopeChanged:()=>scopeWatch.callback()};
}
test('old hotel response cannot read back or navigate a new hotel after submit',async()=>{
 const h=harness(),pending=h.submit();h.env.homeTemporalSelectedHotelId.value='81';
 h.post.resolve({code:200,data:{forecast_point_id:51,execution_intent:{id:901},task_created:false}});
 await Promise.resolve();h.read.resolve(intent());
 await pending;
 assert.equal(h.calls.filter(call=>call.kind==='read').length,0);
 assert.equal(h.toasts.length,0);assert.equal(h.loads.length,0);
 assert.equal(h.env.currentPage.value,'home');assert.equal(h.env.homeTemporalOperationSubmitting.value,false);
});
test('changed forecast point cannot inherit the old point submission',async()=>{
 const h=harness(),pending=h.submit();h.env.homeTemporalOperationRecommendation.value={can_submit_for_review:true,forecast_point_id:52};
 h.post.resolve({code:200,data:{forecast_point_id:51,execution_intent:{id:901},task_created:false}});
 await pending;
 assert.equal(h.calls.filter(call=>call.kind==='read').length,0);assert.equal(h.toasts.length,0);assert.equal(h.loads.length,0);
});
test('scope change releases the old busy lock without letting old completion unlock a new submit',async()=>{
 const h=harness(),oldPending=h.submit();
 assert.equal(h.env.homeTemporalOperationSubmitting.value,true);
 h.env.homeTemporalSelectedHotelId.value='81';
 h.env.homeTemporalOperationRecommendation.value={can_submit_for_review:true,forecast_point_id:52};
 h.scopeChanged();
 assert.equal(h.env.homeTemporalOperationSubmitting.value,false);
 const newPending=h.submit();assert.equal(h.env.homeTemporalOperationSubmitting.value,true);
 assert.equal(h.calls.filter(call=>call.kind==='post').length,2);
 h.post.resolve({code:200,data:{forecast_point_id:51,execution_intent:{id:901},task_created:false}});
 await oldPending;
 assert.equal(h.env.homeTemporalOperationSubmitting.value,true);
 assert.equal(h.toasts.length,0);
 h.posts[1].resolve({code:503,message:'合成新范围中断'});
 await newPending;
 assert.equal(h.env.homeTemporalOperationSubmitting.value,false);
 assert.match(h.toasts[0]?.message||'',/合成新范围中断/);
});
test('old session exact readback cannot announce or navigate in new session',async()=>{
 const h=harness(),pending=h.submit();h.post.resolve({code:200,data:{forecast_point_id:51,execution_intent:{id:901},task_created:false}});
 await drain();assert.equal(h.calls.filter(call=>call.kind==='read')[0]?.hotel,80);
 h.newSession();h.read.resolve(intent());await pending;
 assert.equal(h.toasts.length,0);assert.equal(h.loads.length,0);assert.equal(h.env.currentPage.value,'home');
});
test('same-scope readback must retain hotel and forecast point before showing saved state',async()=>{
 for(const mismatch of [{hotel_id:81},{source_record_id:52},{tasks:undefined}]){
  const h=harness(),pending=h.submit();h.post.resolve({code:200,data:{forecast_point_id:51,execution_intent:{id:901},task_created:false}});
  await Promise.resolve();h.read.resolve(intent(mismatch));await pending;
  assert.equal(h.loads.length,0);assert.equal(h.toasts.some(row=>row.level==='success'||/已送人工审核/.test(row.message)),false);
 }
});
test('same-scope saved recommendation may still open the exact pending intent',async()=>{
 const h=harness(),pending=h.submit();h.post.resolve({code:200,data:{forecast_point_id:51,execution_intent:{id:901},task_created:false}});
 await Promise.resolve();h.read.resolve(intent());await pending;
 assert.equal(h.env.operationFilters.value.hotel_id,'80');assert.equal(h.env.currentPage.value,'ops-track');
 assert.equal(h.loads[0]?.focusIntentId,901);assert.match(h.toasts[0]?.message||'',/已送人工审核/);
});
