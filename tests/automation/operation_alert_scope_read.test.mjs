import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const start=source.indexOf('let operationAlertsRequestSeq = 0;');
const end=source.indexOf('const markOperationAlertsRead = async',start);
assert.ok(start>=0&&end>start);
const handler=source.slice(start,end);
assert.match(fs.readFileSync('resources/frontend/templates/fragments/15c-page-ops-insight.html','utf8'),/v-model="operationFilters\.hotel_id" @change="loadOperationAlerts"/);
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return{promise,resolve};};
const drain=async()=>{for(let i=0;i<5;i++)await Promise.resolve();};
const result=(hotel,id)=>({code:200,data:{selected_hotel_id:hotel,scope:'single_hotel',data_status:'ok',list:[{id,hotel_id:hotel,status:'unread'}],unread_count:1}});
function harness(){
 const ref=value=>({value});let session=1;const calls=[],toasts=[];
 const env={URLSearchParams,operationFilters:ref({hotel_id:'80'}),operationLoading:ref({alerts:false}),operationError:ref({alerts:''}),operationAlerts:ref({list:[],unread_count:0}),currentPage:ref('ops-insight'),
  captureAuthSession:()=>session,isAuthSessionCurrent:value=>value===session,
  ensureOperationStaticReady:async()=>{},normalizeOperationHotelSelection:filters=>filters.value.hotel_id,
  apiRequest:url=>{const request=deferred();calls.push({url,...request});return request.promise;},
  showToast:(message,level)=>toasts.push({message,level}),operationErrorMessage:error=>error.message};
 const context=vm.createContext(env);vm.runInContext(handler+';globalThis.load=loadOperationAlerts;',context);
 return{env,calls,toasts,load:context.load,newSession:()=>{session++;}};
}
test('older hotel alert response cannot replace the newly selected hotel list',async()=>{
 const h=harness(),old=h.load();await drain();
 h.env.operationFilters.value.hotel_id='81';const current=h.load();await drain();
 h.calls[1].resolve(result(81,8101));await current;
 h.calls[0].resolve(result(80,8001));await old;
 assert.equal(h.env.operationAlerts.value.selected_hotel_id,81);
 assert.equal(h.env.operationAlerts.value.list[0]?.id,8101);
 assert.equal(h.toasts.length,0);
});
test('old read completion cannot clear a newer hotel loading state',async()=>{
 const h=harness(),old=h.load();await drain();
 h.env.operationFilters.value.hotel_id='81';const current=h.load();await drain();
 h.calls[0].resolve(result(80,8001));await old;
 assert.equal(h.env.operationLoading.value.alerts,true);
 assert.equal(h.env.operationAlerts.value.list.length,0);
 h.calls[1].resolve(result(81,8101));await current;
 assert.equal(h.env.operationLoading.value.alerts,false);
 assert.equal(h.env.operationAlerts.value.list[0]?.id,8101);
});
test('old session failure cannot write an alert error into a replacement session',async()=>{
 const h=harness(),old=h.load();await drain();h.newSession();
 h.calls[0].resolve({code:503,message:'旧会话失败'});await old;
 assert.equal(h.env.operationError.value.alerts,'');assert.equal(h.toasts.length,0);
});
test('alert endpoint response must match requested single hotel',async()=>{
 const h=harness(),pending=h.load();await drain();
 h.calls[0].resolve(result(81,8101));await pending;
 assert.equal(h.env.operationAlerts.value.list.length,0);
 assert.match(h.env.operationError.value.alerts,/酒店|范围/);
});
test('failed refresh cannot leave prior alert rows as current',async()=>{
 const h=harness(),first=h.load();await drain();h.calls[0].resolve(result(80,8001));await first;
 const second=h.load();await drain();h.calls[1].resolve({code:503,message:'合成预警读取失败'});await second;
 assert.equal(h.env.operationAlerts.value.list.length,0);
 assert.match(h.env.operationError.value.alerts,/合成预警读取失败/);
});
