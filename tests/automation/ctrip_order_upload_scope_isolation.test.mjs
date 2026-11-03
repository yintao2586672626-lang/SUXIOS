import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { ref, computed, watch, nextTick } from 'vue';

const source=readFileSync('public/app-main.js','utf8');
const block=source.slice(source.indexOf('        const ctripChannelOrderUploadOpen = '),source.indexOf('        const ctripOrderSummaryMetricDefinitions = '));
const watcher=source.match(/watch\(\[platformHotelContext, platformHotelSelectedId, token\], resetCtripChannelOrderUploadScope[^;]*;/)?.[0]||'';
const fixtureFile={name:'synthetic.csv',size:20};
const reply=(id=64)=>({code:200,data:{task_id:'synthetic-task',saved_count:1,import_readback:{status:'verified',value_level_verified:true,saved_count:1,readback_count:1},import_preview:{system_hotel_id:id,channels:[{orders:3,gross_orders:4,cancelled_orders:1}]}}});
function harness(){
  const requests=[];
  const context=vm.createContext({ref,computed,watch,nextTick,URL,Blob,
    document:{querySelector:()=>null},authSessionEpoch:0,
    token:ref('synthetic-fixture'),readAuthToken:()=>'',
    platformHotelContext:ref('ctrip'),platformHotelSelectedId:ref('64'),platformHotelSelectedName:ref('合成酒店甲'),
    FormData:class{constructor(){this.entries=[];}append(key,value){this.entries.push([key,value]);}},
    fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject})),
  });
  vm.runInContext(`${block}\n${watcher}\nthis.calls={handleCtripChannelOrderFileChange,uploadCtripChannelOrders}; this.state={ctripChannelOrderUploadFile,ctripChannelOrderUploading,ctripChannelOrderUploadError,ctripChannelOrderUploadResult,ctripChannelOrderUploadPreview,ctripChannelOrderUploadReceiptText};`,context);
  const select=()=>context.calls.handleCtripChannelOrderFileChange({target:{files:[fixtureFile]}});
  const changeHotel=(id='65')=>{context.platformHotelSelectedId.value=id;context.platformHotelSelectedName.value='合成酒店乙';};
  const respond=(request,payload=reply())=>request.resolve({ok:true,status:200,json:async()=>payload});
  return {context,requests,select,changeHotel,respond,state:context.state};
}

test('switching hotel clears file selection and accepted upload preview',async()=>{
  const h=harness();h.select();
  const pending=h.context.calls.uploadCtripChannelOrders();h.respond(h.requests.shift());await pending;
  assert.equal(h.state.ctripChannelOrderUploadPreview.value.system_hotel_id,64);
  h.changeHotel();await nextTick();
  assert.equal(h.state.ctripChannelOrderUploadFile.value,null);
  assert.equal(h.state.ctripChannelOrderUploadResult.value,null);
  assert.equal(h.state.ctripChannelOrderUploadPreview.value,null);
});

for(const outcome of ['success','failure']) test(`late ${outcome} from hotel A cannot become the current hotel B upload state`,async()=>{
  const h=harness();h.select();const pending=h.context.calls.uploadCtripChannelOrders();const request=h.requests.shift();
  h.changeHotel();await nextTick();
  if(outcome==='success')h.respond(request);else request.reject(new Error('酒店甲旧请求失败'));
  await pending;
  assert.equal(h.state.ctripChannelOrderUploadResult.value,null);
  assert.equal(h.state.ctripChannelOrderUploadPreview.value,null);
  assert.doesNotMatch(h.state.ctripChannelOrderUploadError.value,/酒店甲旧请求失败/);
  assert.equal(h.state.ctripChannelOrderUploading.value,false);
  assert.match(h.state.ctripChannelOrderUploadError.value,/原酒店|切换/);
});

test('after switching hotel, upload requires a new file selection and binds new results',async()=>{
  const h=harness();h.select();h.changeHotel();await nextTick();
  const withoutReselect=h.context.calls.uploadCtripChannelOrders();
  assert.equal(h.requests.length,0);
  await withoutReselect;
  h.select();const pending=h.context.calls.uploadCtripChannelOrders();const request=h.requests.shift();
  assert.equal(request.options.body.entries.find(([key])=>key==='system_hotel_id')[1],'65');
  h.respond(request,reply(65));await pending;
  assert.equal(h.state.ctripChannelOrderUploadPreview.value.system_hotel_id,65);
  assert.match(h.state.ctripChannelOrderUploadReceiptText.value,/值级回读确认 1 条/);
});

test('duplicate upload calls share the active operation without sending another write',async()=>{
  const h=harness();h.select();const first=h.context.calls.uploadCtripChannelOrders();
  const duplicate=h.context.calls.uploadCtripChannelOrders();
  assert.equal(h.requests.length,1);
  await duplicate;
  h.respond(h.requests.shift());await first;
});

test('same-hotel failure remains actionable and a later explicit retry succeeds',async()=>{
  const h=harness();h.select();const first=h.context.calls.uploadCtripChannelOrders();
  h.requests.shift().reject(new Error('合成网络失败'));await first;
  assert.equal(h.state.ctripChannelOrderUploadError.value,'合成网络失败');
  const retry=h.context.calls.uploadCtripChannelOrders();h.respond(h.requests.shift());await retry;
  assert.equal(h.state.ctripChannelOrderUploadError.value,'');
  assert.equal(h.state.ctripChannelOrderUploadPreview.value.system_hotel_id,64);
});

test('wrong-hotel upload receipt is not displayed as a completed current-hotel import',async()=>{
  const h=harness();h.select();const pending=h.context.calls.uploadCtripChannelOrders();
  h.respond(h.requests.shift(),reply(65));await pending;
  assert.equal(h.state.ctripChannelOrderUploadResult.value,null);
  assert.equal(h.state.ctripChannelOrderUploadPreview.value,null);
  assert.match(h.state.ctripChannelOrderUploadError.value,/酒店|回执/);
});

test('login-session change clears file and suppresses the old upload reply',async()=>{
  const h=harness();h.select();const pending=h.context.calls.uploadCtripChannelOrders();const request=h.requests.shift();
  h.context.authSessionEpoch++;h.context.token.value='another-synthetic-fixture';await nextTick();
  h.respond(request);await pending;
  assert.equal(h.state.ctripChannelOrderUploadFile.value,null);
  assert.equal(h.state.ctripChannelOrderUploadResult.value,null);
});

test('saved but unverified readback remains partial without inventing a preview',async()=>{
  const h=harness();h.select();const pending=h.context.calls.uploadCtripChannelOrders();
  h.respond(h.requests.shift(),{code:200,data:{task_id:'partial-synthetic-task',status:'partial_success',saved_count:1,import_readback:{status:'unverified',value_level_verified:false,readback_count:0}}});await pending;
  assert.equal(h.state.ctripChannelOrderUploadPreview.value,null);
  assert.match(h.state.ctripChannelOrderUploadReceiptText.value,/已保存 1 条；精确回读未确认/);
});

test('A to B to A round trip cannot revive the older upload reply',async()=>{
  const h=harness();h.select();const pending=h.context.calls.uploadCtripChannelOrders();const request=h.requests.shift();
  h.changeHotel('65');h.changeHotel('64');await nextTick();h.respond(request);await pending;
  assert.equal(h.state.ctripChannelOrderUploadResult.value,null);
  assert.equal(h.state.ctripChannelOrderUploadFile.value,null);
});

for(const data of [null,[]])test(`missing receipt ${JSON.stringify(data)} is not an empty upload success`,async()=>{
  const h=harness();h.select();const pending=h.context.calls.uploadCtripChannelOrders();
  h.respond(h.requests.shift(),{code:200,data});await pending;
  assert.equal(h.state.ctripChannelOrderUploadResult.value,null);
  assert.match(h.state.ctripChannelOrderUploadError.value,/回执不完整/);
});
