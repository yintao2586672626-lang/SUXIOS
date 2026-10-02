import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const start=source.indexOf('let operationAlertMarkReadRequest = null;');
const end=source.indexOf('const openOperationAlertTask = async',start);
assert.ok(start>=0&&end>start);
const handler=source.slice(start,end);
const template=fs.readFileSync('resources/frontend/templates/fragments/15c-page-ops-insight.html','utf8');
assert.match(template,/:disabled="operationLoading\.alerts \|\| operationUnreadCount === 0"/);
assert.match(template,/:disabled="operationLoading\.alerts"[^>]*>\{\{ operationLoading\.alerts \? '更新中\.\.\.'/);
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return{promise,resolve};};
const alert=(id,hotel=80,status='unread')=>({id,hotel_id:hotel,status});
function harness(){
 const ref=value=>({value});let session=1;const post=deferred(),toasts=[],calls=[];
 const env={operationAlerts:ref({list:[alert(11),alert(12)],unread_count:2,selected_hotel_id:80,scope:'single_hotel'}),operationFilters:ref({hotel_id:'80'}),currentPage:ref('ops-insight'),pageRequestGeneration:0,
  operationLoading:ref({alerts:false}),operationError:ref({alerts:''}),captureAuthSession:()=>session,isAuthSessionCurrent:value=>session===value,
  apiRequest:(url,options)=>{calls.push({url,options});return post.promise;},
  loadOperationAlerts:async()=>{calls.push({url:'GET alerts'});return true;},
  showToast:(message,level)=>toasts.push({message,level}),operationErrorMessage:error=>error.message};
 const context=vm.createContext(env);vm.runInContext(handler+';globalThis.mark=markOperationAlertsRead;',context);
 return{env,post,toasts,calls,mark:context.mark,newSession:()=>{session++;}};
}
test('old hotel read receipt cannot mark new hotel alerts or toast success',async()=>{
 const h=harness(),pending=h.mark([11]);
 h.env.operationFilters.value.hotel_id='81';
 h.env.operationAlerts.value={list:[alert(11,81)],unread_count:1,selected_hotel_id:81,scope:'single_hotel'};
 h.post.resolve({code:200,data:{updated:1}});await pending;
 assert.equal(h.env.operationAlerts.value.list[0].status,'unread');
 assert.equal(h.env.operationAlerts.value.unread_count,1);
 assert.equal(h.calls.some(call=>call.url==='GET alerts'),false);
 assert.equal(h.toasts.length,0);
});
test('old session read receipt cannot update replacement session',async()=>{
 const h=harness(),pending=h.mark([11]);h.newSession();
 h.post.resolve({code:200,data:{updated:1}});await pending;
 assert.equal(h.env.operationAlerts.value.list[0].status,'unread');
 assert.equal(h.toasts.length,0);
});
test('partial update must rely on a fresh list rather than subtracting requested count',async()=>{
 const h=harness(),pending=h.mark([11,12]);
 h.post.resolve({code:200,data:{updated:1}});await pending;
 assert.equal(h.calls.some(call=>call.url==='GET alerts'),true);
 assert.equal(h.env.operationAlerts.value.unread_count,2);
 assert.match(h.toasts[0]?.message||'',/尚未确认/);
});
test('exact readback is required before reporting all alerts read',async()=>{
 const h=harness();h.env.loadOperationAlerts=async()=>{
  h.env.operationAlerts.value={list:[alert(11,80,'read'),alert(12,80,'read')],unread_count:0,selected_hotel_id:80,scope:'single_hotel'};
 };
 const pending=h.mark([11,12,12]);
 assert.deepEqual(JSON.parse(h.calls[0].options.body).ids,[11,12]);
 h.post.resolve({code:200,data:{updated:2}});await pending;
 assert.equal(h.env.operationAlerts.value.unread_count,0);
 assert.match(h.toasts[0]?.message||'',/预警已标记为已读/);
});
test('readback failure does not claim success after the write was accepted',async()=>{
 const h=harness();h.env.loadOperationAlerts=async()=>{
  h.env.operationError.value.alerts='读取失败';
  h.env.operationAlerts.value={list:[],unread_count:0,data_status:'load_failed'};
 };
 const pending=h.mark([11]);h.post.resolve({code:200,data:{updated:1}});await pending;
 assert.equal(h.toasts.some(item=>/已标记为已读/.test(item.message)),false);
 assert.equal(h.env.operationAlerts.value.data_status,'load_failed');
});
test('old request completion cannot clear a newer hotel read lock',async()=>{
 const h=harness(),oldPost=deferred(),newPost=deferred();let call=0;
 h.env.apiRequest=()=>++call===1?oldPost.promise:newPost.promise;
 const oldPending=h.mark([11]);
 h.env.operationFilters.value.hotel_id='81';
 h.env.operationAlerts.value={list:[alert(11,81)],unread_count:1,selected_hotel_id:81,scope:'single_hotel'};
 const newPending=h.mark([11]);
 oldPost.resolve({code:200,data:{updated:1}});await oldPending;
 assert.equal(h.env.operationLoading.value.alerts,true);
 newPost.resolve({code:200,data:{updated:1}});await newPending;
});
