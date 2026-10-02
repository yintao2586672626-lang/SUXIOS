import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const start=source.indexOf('const createOperationAlertTask = async');
const end=source.indexOf('let operationStrategyInputRevision = 0;',start);
assert.ok(start>=0&&end>start);
const handler=source.slice(start,end);
const loadingStart=source.indexOf('const isOperationAlertTaskLoading =');
const loadingEnd=source.indexOf('const operationParams =',loadingStart);
assert.ok(loadingStart>=0&&loadingEnd>loadingStart);
const loadingHelper=source.slice(loadingStart,loadingEnd);
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return{promise,resolve};};
const alert=(hotel=80)=>({id:11,hotel_id:hotel,status:'unread',task_bridge:{can_convert:true,linked:false}});
const success=(hotel=80)=>({code:200,data:{alert:{...alert(hotel),status:'read'},execution_intent:{id:901,hotel_id:hotel,source_module:'operation_alert',source_record_id:11,status:'pending_approval',tasks:[]},reused_existing_intent:false}});
function harness(){
 const ref=value=>({value});let session=1;const post=deferred(),toasts=[],opens=[],calls=[];
 const env={operationAlerts:ref({list:[alert()],unread_count:1,selected_hotel_id:80,scope:'single_hotel'}),operationAlertTaskLoadingIds:ref([]),operationAlertTaskRequests:new Map(),operationFilters:ref({hotel_id:'80'}),currentPage:ref('ops-insight'),pageRequestGeneration:0,
  captureAuthSession:()=>session,isAuthSessionCurrent:value=>value===session,
  apiRequest:(url,options)=>{calls.push({url,options});return post.promise;},
  openOperationAlertTask:async row=>{opens.push(row);return true;},
  showToast:(message,level)=>toasts.push({message,level}),operationErrorMessage:error=>error.message};
 const context=vm.createContext(env);vm.runInContext(loadingHelper+handler+';globalThis.create=createOperationAlertTask;globalThis.loading=isOperationAlertTaskLoading;',context);
 return{env,post,toasts,opens,calls,create:context.create,loading:context.loading,newSession:()=>{session++;}};
}
test('old hotel conversion cannot attach to a new hotel alert list',async()=>{
 const h=harness(),pending=h.create(alert());
 h.env.operationFilters.value.hotel_id='81';h.env.operationAlerts.value={list:[alert(81)],unread_count:1,selected_hotel_id:81,scope:'single_hotel'};
 h.post.resolve(success());await pending;
 assert.equal(h.env.operationAlerts.value.list[0]?.task_bridge.linked,false);
 assert.equal(h.env.operationAlerts.value.unread_count,1);
 assert.equal(h.opens.length,0);assert.equal(h.toasts.length,0);
});
test('old session conversion cannot update replacement session alerts',async()=>{
 const h=harness(),pending=h.create(alert());h.newSession();
 h.env.operationAlerts.value={list:[alert()],unread_count:1,selected_hotel_id:80,scope:'single_hotel'};
 h.post.resolve(success());await pending;
 assert.equal(h.env.operationAlerts.value.list[0]?.task_bridge.linked,false);
 assert.equal(h.opens.length,0);assert.equal(h.toasts.length,0);
});
test('current exact conversion retains the saved intent and opens it',async()=>{
 const h=harness(),pending=h.create(alert());h.post.resolve(success());await pending;
 assert.equal(h.env.operationAlerts.value.list[0]?.task_bridge.intent_id,901);
 assert.equal(h.env.operationAlerts.value.unread_count,0);
 assert.equal(h.opens[0]?.task_bridge.intent_id,901);
 assert.match(h.toasts[0]?.message||'',/待审批运营任务/);
});
test('wrong hotel or alert identity in saved receipt cannot attach a task',async()=>{
 const h=harness(),pending=h.create(alert());
 const receipt=success();receipt.data.execution_intent.hotel_id=81;
 h.post.resolve(receipt);await pending;
 assert.equal(h.env.operationAlerts.value.list[0]?.task_bridge.linked,false);
 assert.equal(h.env.operationAlerts.value.unread_count,1);
 assert.equal(h.opens.length,0);
 assert.match(h.toasts[0]?.message||'',/回执范围不匹配/);
});
test('old completion does not unlock a newer request for the same alert ID',async()=>{
 const h=harness(),oldPost=deferred(),newPost=deferred();let call=0;
 h.env.apiRequest=()=>++call===1?oldPost.promise:newPost.promise;
 const oldPending=h.create(alert());
 h.env.operationFilters.value.hotel_id='81';
 h.env.operationAlerts.value={list:[alert(81)],unread_count:1,selected_hotel_id:81,scope:'single_hotel'};
 assert.equal(h.loading(alert()),false);
 const newPending=h.create(alert(81));
 assert.equal(h.loading(alert(81)),true);
 oldPost.resolve(success());await oldPending;
 assert.equal(h.loading(alert(81)),true);
 assert.deepEqual(Array.from(h.env.operationAlertTaskLoadingIds.value),[11]);
 newPost.resolve(success(81));await newPending;
 assert.equal(h.loading(alert(81)),false);
 assert.equal(h.env.operationAlerts.value.list[0]?.task_bridge.intent_id,901);
 assert.equal(h.opens.length,1);
});
