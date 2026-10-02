import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const template=fs.readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html','utf8');

test('history detail entrance reads a saved record by its exact ID',()=>{
 assert.ok(source.includes('const loadOnlineHistoryRecordDetail = async'), 'exact detail handler exists');
 assert.ok(source.includes('`/online-data/history/${recordId}`'), 'exact saved ID API is called');
 assert.match(template,/onlineHistoryRecordIds\(item\)/);
});

const start=source.indexOf('const onlineHistoryRecordIds =');
const end=source.indexOf('// 加载线上数据列表',start);
assert.ok(start>=0&&end>start);
const handler=source.slice(start,end);
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return{promise,resolve};};
const group={id:41,record_ids:[41,42],hotel_id:80,platform:'ctrip',data_type:'traffic',data_date:'2026-09-27'};
const detail=(id=41,overrides={})=>({id,hotel_id:80,platform:'ctrip',data_type:'traffic',data_date:'2026-09-27',hotel_name:'Synthetic hotel',status_label:'已保存',readback_verified:1,metrics_summary:'曝光 0',raw_data:'hidden',...overrides});
function harness(){
 const ref=value=>({value});let session=1;const calls=[];
 const env={onlineHistoryRecordDetailRequestSeq:0,onlineHistoryRequestSeq:1,onlineHistoryCurrentQueryKey:ref('query-a'),onlineHistorySnapshotKey:ref('query-a'),onlineHistoryExpandedId:ref(null),onlineHistorySelectedRecordId:ref(null),onlineHistoryRecordDetail:ref(null),onlineHistoryRecordDetailError:ref(''),onlineHistoryRecordDetailLoading:ref(false),onlineHistoryList:ref([group]),onlineHistoryRawId:ref(null),currentPage:ref('ctrip-ebooking'),
  captureAuthSession:()=>session,isAuthSessionCurrent:value=>value===session,request:url=>{const call=deferred();calls.push({url,...call});return call.promise;}};
 const context=vm.createContext(env);vm.runInContext(handler+';globalThis.toggle=toggleOnlineHistoryDetail;globalThis.load=loadOnlineHistoryRecordDetail;globalThis.readbackText=onlineHistoryReadbackText;',context);
 return{env,calls,toggle:context.toggle,load:context.load,readbackText:context.readbackText,newSession:()=>{session++;}};
}
test('merged row can read each saved ID and preserves real zero with verified status',async()=>{
 const h=harness(),first=h.toggle(group);
 assert.equal(h.calls[0].url,'/online-data/history/41');
 h.calls[0].resolve({code:200,data:detail()});await first;
 assert.equal(h.env.onlineHistoryRecordDetail.value.id,41);
 assert.equal(h.env.onlineHistoryRecordDetail.value.metrics_summary,'曝光 0');
 assert.equal(h.readbackText(h.env.onlineHistoryRecordDetail.value.readback_verified),'已验证');
 const second=h.load(group,42);
 assert.equal(h.calls[1].url,'/online-data/history/42');
 h.calls[1].resolve({code:200,data:detail(42)});await second;
 assert.equal(h.env.onlineHistoryRecordDetail.value.id,42);
});
test('wrong hotel or business date in exact response is rejected with a retryable error',async()=>{
 const h=harness(),pending=h.toggle(group);
 h.calls[0].resolve({code:200,data:detail(41,{hotel_id:81})});await pending;
 assert.equal(h.env.onlineHistoryRecordDetail.value,null);
 assert.match(h.env.onlineHistoryRecordDetailError.value,/身份|范围|一致/);
 const retry=h.load(group,41);h.calls[1].resolve({code:200,data:detail()});await retry;
 assert.equal(h.env.onlineHistoryRecordDetailError.value,'');
 assert.equal(h.env.onlineHistoryRecordDetail.value.id,41);
});
test('old session and changed filter cannot replace the current saved record detail',async()=>{
 const h=harness(),old=h.toggle(group);h.newSession();h.env.onlineHistoryCurrentQueryKey.value='query-b';
 h.calls[0].resolve({code:200,data:detail()});await old;
 assert.equal(h.env.onlineHistoryRecordDetail.value,null);
 assert.equal(h.env.onlineHistoryRecordDetailError.value,'');
 assert.equal(h.env.onlineHistoryRecordDetailLoading.value,false);
});
test('older record response cannot overwrite or unlock a newer record read',async()=>{
 const h=harness(),old=h.toggle(group),current=h.load(group,42);
 h.calls[0].resolve({code:200,data:detail()});await old;
 assert.equal(h.env.onlineHistoryRecordDetailLoading.value,true);
 assert.equal(h.env.onlineHistoryRecordDetail.value,null);
 h.calls[1].resolve({code:200,data:detail(42)});await current;
 assert.equal(h.env.onlineHistoryRecordDetailLoading.value,false);
 assert.equal(h.env.onlineHistoryRecordDetail.value.id,42);
});
test('unknown readback is distinct from true zero or explicit unverified',()=>{
 const h=harness();
 assert.equal(h.readbackText(null),'未返回');
 assert.equal(h.readbackText(0),'未验证');
 assert.equal(h.readbackText(1),'已验证');
 const detailMarkup=template.slice(template.indexOf('data-testid="online-history-exact-detail"'),template.indexOf('尚未取得这条记录的精确回读'));
 assert.doesNotMatch(detailMarkup,/raw_data|raw_data_json|cookie/i);
});
