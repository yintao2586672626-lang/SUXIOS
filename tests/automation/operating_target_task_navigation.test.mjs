import fs from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { harness, flowPayload, actionPayload } from './operation_execution_scope_recovery.test.mjs';

const source=fs.readFileSync(process.env.OPERATING_TARGET_SOURCE||'public/app-main.js','utf8');
const start=source.indexOf('const openOperatingTargetTaskDraft =');
const handler=source.slice(start,source.indexOf('const requestOperatingTargetTestPush =',start));
const watcherStart=source.indexOf("if (newPage === 'ops-track') {");
const watcher=source.slice(watcherStart,source.indexOf("if (newPage === 'operating-growth-archive')",watcherStart));
const draft=()=>({target:{target_date:'2026-09-20'},execution_intent:{id:42,hotel_id:7,date_start:'2026-09-20'}});
export function navigation({ready,fetch}={}){
 const h=harness({hotel:'8',ready,fetch:fetch|| (call=>{
  const url=new URL(call.url,'http://fixture.invalid'),hotel=Number(url.searchParams.get('hotel_id'));
  return{code:200,data:url.pathname==='/operation/execution-flow'?flowPayload([{id:42,hotel_id:7}],hotel):url.pathname==='/operation/action-tracking'?actionPayload([]):{summary:{status:'unverified'},modules:[],data_status:'ok',data_gaps:[]}};
 })});
 const c=h.context;Object.assign(c,{
  window: {},
  currentPage:Vue.ref('operating-targets'),operatingTargetForm:Vue.ref({hotel_id:'7',target_date:'2026-09-20'}),
  operatingTargetTaskDraft:Vue.ref(draft()),operatingTargetTaskDraftError:Vue.ref(''),
  operationFilters:Vue.ref({hotel_id:'8'}),operationExecutionViewMode:Vue.ref('mine'),revenueAiExecutionFocus:Vue.ref(null),
  operationExecutionStageFilter:Vue.ref('executed'),
  suppressNextOpsTrackAutoLoad:false,watch:Vue.watch,nextTick:Vue.nextTick,
  isOperationHotelPermitted:id=>['7','8'].includes(String(id)),activateOpsTrackPage:()=>c.loadFlow(),
 });
 vm.runInContext(fs.readFileSync('public/operation-static.js','utf8'),c);
 vm.runInContext(handler+';globalThis.openDraft=openOperatingTargetTaskDraft;watch(currentPage,(newPage)=>{'+watcher+'});',c);
 return h;
}
const flush=async()=>{for(let i=0;i<18;i++)await Promise.resolve();};
test('target task navigation carries exact hotel and intent, overriding prior my-tasks filter',async()=>{
 const h=navigation();await h.context.openDraft();await flush();
 assert.equal(h.context.operationFilters.value.hotel_id,'7');assert.equal(h.context.operationExecutionViewMode.value,'all');
 assert.equal(h.context.operationExecutionStageFilter.value,'');
 assert.equal(h.context.revenueAiExecutionFocus.value.intentId,42);
 const flow=h.calls.filter(c=>c.url.includes('/execution-flow'));assert.equal(flow.length,1);assert.match(flow[0].url,/hotel_id=7/);assert.match(flow[0].url,/intent_id=42/);
 assert.equal(h.context.operationExecutionFlow.value.list[0]?.id,42);assert.equal(h.context.operationError.value.actions,'');
});
for(const kind of ['missing','zero-id','fractional-id','hotel-mismatch','unpermitted','date-mismatch'])test('target task navigation rejects '+kind,async()=>{
 const h=navigation(),c=h.context;
 if(kind==='missing')c.operatingTargetTaskDraft.value=null;
 else if(kind==='zero-id')c.operatingTargetTaskDraft.value.execution_intent.id=0;
 else if(kind==='fractional-id')c.operatingTargetTaskDraft.value.execution_intent.id=1.5;
 else if(kind==='hotel-mismatch')c.operatingTargetTaskDraft.value.execution_intent.hotel_id=8;
 else if(kind==='unpermitted'){c.operatingTargetForm.value.hotel_id='9';c.operatingTargetTaskDraft.value.execution_intent.hotel_id=9;}
 else c.operatingTargetForm.value.target_date='2026-09-21';
 await c.openDraft();await flush();assert.equal(c.currentPage.value,'operating-targets');assert.equal(h.calls.length,0);assert.ok(c.operatingTargetTaskDraftError.value);
});
test('target task navigation exposes failed read and original page retry recovers same intent',async()=>{
 let fail=true;const h=navigation({fetch:call=>{
  if(fail)throw Error('SYNTHETIC read failed');
  return{code:200,data:call.url.includes('/execution-flow')?flowPayload([{id:42,hotel_id:7}],7):actionPayload([])};
 }});
 await h.context.openDraft();await flush();assert.ok(h.context.operationError.value.actions);assert.equal(h.context.operationLoading.value.actions,false);
 fail=false;await h.context.loadFlow({focusIntentId:42});assert.equal(h.context.operationExecutionFlow.value.list[0]?.id,42);
});
test('target task navigation will not call focused missing record a successful read',async()=>{
 const h=navigation({fetch:call=>({code:200,data:call.url.includes('/execution-flow')?flowPayload([],7):actionPayload([])})});await h.context.openDraft();await flush();
 assert.match(h.context.operationError.value.actions,/对应任务/);assert.equal(h.context.operationLoading.value.actions,false);
});
test('target task navigation cannot restore rows after session or page changes during read',async()=>{
 let release;const gate=new Promise(resolve=>{release=resolve;});const h=navigation({ready:()=>gate});
 const done=h.context.openDraft();await flush();h.context.epoch++;h.context.currentPage.value='home';release();await done;await flush();
 assert.equal(h.calls.length,0);assert.equal(h.context.operationExecutionFlow.value.list.length,0);
});
