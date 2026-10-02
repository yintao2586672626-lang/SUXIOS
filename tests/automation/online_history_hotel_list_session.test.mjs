import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const resetStart=source.indexOf('onlineHistoryRequestSeq += 1;');
const resetEnd=source.indexOf('ctripConfigList.value = [];',resetStart);
const loaderStart=source.indexOf('let onlineHistoryHotelListLoadingPromise = null;');
const loaderEnd=source.indexOf('const loadOnlineHistory = async',loaderStart);
assert.ok(resetStart>=0&&resetEnd>resetStart&&loaderStart>=0&&loaderEnd>loaderStart);
const script=`const resetHistory=()=>{${source.slice(resetStart,resetEnd)}};${source.slice(loaderStart,loaderEnd)};globalThis.reset=resetHistory;globalThis.load=loadOnlineHistoryHotelList;`;
const template=fs.readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html','utf8');
assert.match(template,/v-if="onlineHistoryHotelListError"[\s\S]*?loadOnlineHistoryHotelList\(\{ force: true \}\)/);
assert.match(template,/v-else-if="onlineHistoryHotelListLoaded &amp;&amp; !onlineHistoryHotelList\.length"/);
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return{promise,resolve};};
function harness(){
 const ref=value=>({value});let session=1;const calls=[];let reloads=0;
 const env={ref,onlineHistoryRequestSeq:0,onlineHistoryRecordDetailRequestSeq:0,onlineHistoryLoading:ref(false),onlineHistoryError:ref(''),onlineHistoryErrorQueryKey:ref(''),onlineHistorySnapshotKey:ref(''),onlineHistoryList:ref([]),onlineHistoryPagination:ref({}),onlineHistoryPage:ref(1),onlineHistorySummary:ref({}),onlineHistoryHotelList:ref([]),onlineHistoryHotelListError:ref(''),onlineHistoryFilter:ref({hotel_scope:'80',platform:'ctrip'}),onlineHistoryExpandedId:ref(11),onlineHistoryRawId:ref(11),onlineHistorySelectedRecordId:ref(11),onlineHistoryRecordDetail:ref({}),onlineHistoryRecordDetailError:ref(''),onlineHistoryRecordDetailLoading:ref(false),
  createEmptyOnlineHistorySummary:()=>({total_records:null}),captureAuthSession:()=>session,isAuthSessionCurrent:value=>session===value,loadOnlineHistory:async()=>{reloads++;return true;},
  formatOnlineHistoryHotelOption:item=>item,request:url=>{const call=deferred();calls.push({url,...call});return call.promise;},console:{error:()=>{}}};
 const context=vm.createContext(env);vm.runInContext(script,context);
 return{env,calls,reset:context.reset,load:context.load,reloads:()=>reloads,newSession:()=>{session++;context.reset();}};
}
test('hotel selector cache is reloaded for a replacement login session',async()=>{
 const h=harness(),old=h.load();h.calls[0].resolve({code:200,data:[{value:'80',label:'Old hotel'}]});await old;
 assert.equal(h.env.onlineHistoryHotelList.value[0]?.label,'Old hotel');
 h.newSession();
 assert.equal(h.env.onlineHistoryHotelList.value.length,0);
 assert.equal(h.env.onlineHistoryFilter.value.hotel_scope,'all');
 assert.equal(h.env.onlineHistoryExpandedId.value,null);
 assert.equal(h.env.onlineHistoryRawId.value,null);
 const current=h.load();
 assert.equal(h.calls.length,2);
 h.calls[1].resolve({code:200,data:[{value:'81',label:'New hotel'}]});await current;
 assert.equal(h.env.onlineHistoryHotelList.value[0]?.label,'New hotel');
});
test('late hotel selector response from old session cannot populate new session',async()=>{
 const h=harness(),old=h.load();h.newSession();const current=h.load();
 assert.equal(h.calls.length,2);
 h.calls[1].resolve({code:200,data:[{value:'81',label:'New hotel'}]});await current;
 h.calls[0].resolve({code:200,data:[{value:'80',label:'Old hotel'}]});await old;
 assert.equal(h.env.onlineHistoryHotelList.value[0]?.label,'New hotel');
});
test('failed hotel option read stays distinct from an empty authorized list and can recover',async()=>{
 const h=harness(),first=h.load();h.calls[0].resolve({code:503,message:'暂时不可用'});await first;
 assert.equal(h.env.onlineHistoryHotelList.value.length,0);
 assert.match(h.env.onlineHistoryHotelListError.value,/暂时不可用/);
 const second=h.load();assert.equal(h.calls.length,2);
 h.calls[1].resolve({code:200,data:[{value:'81',label:'Current hotel'}]});await second;
 assert.equal(h.env.onlineHistoryHotelListError.value,'');
 assert.equal(h.env.onlineHistoryHotelList.value[0]?.label,'Current hotel');
});
test('late old-session failure does not replace a new account option status',async()=>{
 const h=harness(),old=h.load();h.newSession();const current=h.load();
 h.calls[1].resolve({code:200,data:[]});await current;
 h.calls[0].resolve({code:503,message:'old account failed'});await old;
 assert.equal(h.env.onlineHistoryHotelListError.value,'');
 assert.equal(h.env.onlineHistoryHotelList.value.length,0);
});
test('refreshed permissions remove a selected hotel that is no longer available',async()=>{
 const h=harness(),first=h.load();h.calls[0].resolve({code:200,data:[{value:'80',label:'Old hotel'}]});await first;
 const changed=h.load({force:true});h.calls[1].resolve({code:200,data:[{value:'81',label:'Current hotel'}]});await changed;
 assert.equal(h.env.onlineHistoryFilter.value.hotel_scope,'all');
 assert.deepEqual(h.env.onlineHistoryHotelList.value.map(item=>item.value),['81']);
 assert.equal(h.reloads(),1);
});
