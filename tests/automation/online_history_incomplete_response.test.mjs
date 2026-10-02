import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const start=source.indexOf('const loadOnlineHistory = async () => {');
const end=source.indexOf('const refreshOnlineHistory = async',start);
assert.ok(start>=0&&end>start);
const script=`${source.slice(start,end)};globalThis.load=loadOnlineHistory;`;
function harness(response, filter={hotel_scope:'all',platform:'ctrip'}){
 const ref=value=>({value});const notices=[];let requestCount=0;let lastRequest=null;const onlineHistoryPage=ref(1);
 const env={onlineHistoryRequestSeq:0,onlineHistoryFilter:ref(filter),onlineHistoryPage:ref(1),onlineHistoryPagination:ref({total:3,page:1,page_size:20}),onlineHistoryLoading:ref(false),onlineHistoryError:ref(''),onlineHistoryErrorQueryKey:ref(''),onlineHistorySnapshotKey:ref('page=1'),onlineHistoryList:ref([{id:3}]),onlineHistorySummary:ref({total_records:3}),onlineHistoryExpandedId:ref(null),onlineHistoryRawId:ref(null),onlineHistoryCurrentQueryKey:{get value(){return `page=${onlineHistoryPage.value}`;}},
  clearOnlineHistoryRecordDetail:()=>{},buildOnlineHistoryQueryParams:()=>({toString:()=> `page=${onlineHistoryPage.value}`}),request:async(...args)=>{lastRequest=args;requestCount++;return typeof response==='function'?response(requestCount):response;},createEmptyOnlineHistorySummary:()=>({total_records:null}),showToast:(message)=>notices.push(message),console:{error:()=>{}}};
 env.onlineHistoryPage=onlineHistoryPage;
 const context=vm.createContext(env);vm.runInContext(script,context);return{env,notices,load:context.load,requestCount:()=>requestCount,lastRequest:()=>lastRequest};
}
test('missing total in a partial success cannot become a real zero and erase pagination',async()=>{
 const h=harness({code:200,data:{list:[],page:1,page_size:20}});
 assert.equal(await h.load(),false);
 assert.equal(h.env.onlineHistoryPagination.value.total,3);
 assert.deepEqual(h.env.onlineHistoryList.value,[{id:3}]);
 assert.equal(h.env.onlineHistorySnapshotKey.value,'page=1');
 assert.match(h.env.onlineHistoryError.value,/不完整/);
 assert.equal(h.notices.length,1);
});
test('genuine empty history with explicit zero remains a successful read',async()=>{
 const h=harness({code:200,data:{list:[],total:0,page:1,page_size:20,summary:{total_records:0}}});
 assert.equal(await h.load(),true);
 assert.equal(h.env.onlineHistoryPagination.value.total,0);
 assert.equal(h.env.onlineHistoryList.value.length,0);
 assert.equal(h.env.onlineHistoryError.value,'');
});
test('wrong page or impossible total is rejected without replacing the prior snapshot',async()=>{
 for(const data of [{list:[],total:0,page:2,page_size:20},{list:[{id:4}],total:0,page:1,page_size:20}]){
  const h=harness({code:200,data});
  assert.equal(await h.load(),false);
  assert.equal(h.env.onlineHistoryPagination.value.total,3);
  assert.equal(h.env.onlineHistoryList.value[0].id,3);
  assert.match(h.env.onlineHistoryError.value,/不完整/);
 }
});
test('out-of-range page after records change recovers to the last available page',async()=>{
 const h=harness(call=>call===1
  ?{code:200,data:{list:[],total:40,page:3,page_size:20,summary:{total_records:40}}}
  :{code:200,data:{list:[{id:2,platform:'ctrip'}],total:40,page:2,page_size:20,summary:{total_records:40}}});
 h.env.onlineHistoryPage.value=3;
 assert.equal(await h.load(),true);
 assert.equal(h.env.onlineHistoryPage.value,2);
 assert.equal(h.env.onlineHistoryList.value[0].id,2);
 assert.equal(h.requestCount(),2);
});
test('empty in-range page with a positive total remains a retryable read failure',async()=>{
 const h=harness({code:200,data:{list:[],total:3,page:1,page_size:20,summary:{total_records:3}}});
 assert.equal(await h.load(),false);
 assert.equal(h.env.onlineHistoryList.value[0].id,3);
 assert.match(h.env.onlineHistoryError.value,/回读为空/);
 assert.equal(h.requestCount(),1);
});
test('a selected hotel never accepts another hotels OTA history as its own',async()=>{
 const h=harness({code:200,data:{list:[{id:9,hotel_id:8,platform:'ctrip',data_date:'2026-09-14',data_type:'business'}],total:1,page:1,page_size:20,summary:{total_records:1}}},
  {hotel_scope:'7',platform:'ctrip',start_date:'2026-09-14',end_date:'2026-09-14'});
 assert.equal(await h.load(),false);
 assert.equal(h.env.onlineHistoryList.value[0].id,3);
 assert.match(h.env.onlineHistoryError.value,/范围|酒店/);
});
test('explicit OTA platform and date cannot be replaced by another channel or day',async()=>{
 for(const row of [
  {id:9,hotel_id:7,platform:'meituan',data_date:'2026-09-14',data_type:'business'},
  {id:9,hotel_id:7,platform:'ctrip',data_date:'2026-09-13',data_type:'business'},
 ]){
  const h=harness({code:200,data:{list:[row],total:1,page:1,page_size:20,summary:{total_records:1}}},
   {hotel_scope:'7',platform:'ctrip',start_date:'2026-09-14',end_date:'2026-09-14'});
  assert.equal(await h.load(),false);
  assert.equal(h.env.onlineHistoryList.value[0].id,3);
 }
});
test('matching hotel, platform and business date remain readable in the selected scope',async()=>{
 const row={id:9,hotel_id:7,platform:'ctrip',data_date:'2026-09-14',data_type:'business'};
 const h=harness({code:200,data:{list:[row],total:1,page:1,page_size:20,summary:{total_records:1}}},
  {hotel_scope:'7',platform:'ctrip',start_date:'2026-09-14',end_date:'2026-09-14'});
 assert.equal(await h.load(),true);
 assert.equal(h.env.onlineHistoryList.value[0].id,9);
 assert.equal(h.lastRequest()[1].businessContext.hotelId,'7');
 assert.equal(h.lastRequest()[1].businessContext.platform,'ctrip');
});
test('selected data type rejects another type and accepts a matching normalized type',async()=>{
 const row={id:9,hotel_id:7,platform:'ctrip',data_date:'2026-09-14',data_type:'business'};
 const response={code:200,data:{list:[row],total:1,page:1,page_size:20,summary:{total_records:1}}};
 const selected={hotel_scope:'7',platform:'ctrip',data_type:'traffic',start_date:'2026-09-14'};
 const wrong=harness(response,selected);
 assert.equal(await wrong.load(),false);
 assert.equal(wrong.env.onlineHistoryList.value[0].id,3);
 assert.match(wrong.env.onlineHistoryError.value,/数据类型/);
 const matched=harness(response,{...selected,data_type:'business'});
 assert.equal(await matched.load(),true);
 assert.equal(matched.env.onlineHistoryList.value[0].id,9);
 const legacy=harness({code:200,data:{...response.data,list:[{...row,data_type:'ranking'}]}},
  {...selected,data_type:'business'});
 assert.equal(await legacy.load(),true);
 assert.equal(legacy.env.onlineHistoryList.value[0].data_type,'ranking');
});
test('an all-hotel selection does not send stale hotel context',async()=>{
 const h=harness({code:200,data:{list:[],total:0,page:1,page_size:20}},
  {hotel_scope:'all',hotel_id:'7',platform:'ctrip'});
 assert.equal(await h.load(),true);
 assert.equal(h.lastRequest()[1].businessContext,undefined);
});
test('contradictory history summary cannot replace the matching pagination snapshot',async()=>{
 const h=harness({code:200,data:{list:[{id:9,platform:'ctrip'}],total:1,page:1,page_size:20,summary:{total_records:0,failed_records:0}}});
 assert.equal(await h.load(),false);
 assert.equal(h.env.onlineHistoryList.value[0].id,3);
 assert.equal(h.env.onlineHistoryPagination.value.total,3);
 assert.match(h.env.onlineHistoryError.value,/汇总|总数/);
});
test('server page size drift cannot make a successful list disappear from the current query',async()=>{
 const h=harness({code:200,data:{list:[{id:9,platform:'ctrip'}],total:2,page:1,page_size:1,summary:{total_records:2}}});
 assert.equal(await h.load(),false);
 assert.equal(h.env.onlineHistoryPagination.value.page_size,20);
 assert.equal(h.env.onlineHistoryList.value[0].id,3);
 assert.match(h.env.onlineHistoryError.value,/分页/);
});
