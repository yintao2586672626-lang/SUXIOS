import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {ref,computed,watch,nextTick} from 'vue';

const source=readFileSync('public/app-main.js','utf8');
const block=source.slice(source.indexOf('        const ctripChannelOrderUploadOpen = '),source.indexOf('        const ctripOrderSummaryMetricDefinitions = '));
async function stateFor(channels){
  const context=vm.createContext({ref,computed,watch,nextTick,URL,Blob,document:{querySelector:()=>null},authSessionEpoch:0,token:ref('synthetic-fixture'),readAuthToken:()=>'',platformHotelContext:ref('ctrip'),platformHotelSelectedId:ref('64'),platformHotelSelectedName:ref('合成酒店'),FormData:class{append(){}},fetch:async()=>({ok:true,status:200,json:async()=>({code:200,data:{task_id:'synthetic-task',saved_count:1,import_preview:{system_hotel_id:64,channels}}})})});
  vm.runInContext(`${block}\nthis.calls={handleCtripChannelOrderFileChange,uploadCtripChannelOrders}; this.result={rate:ctripChannelOrderUploadCancelRate,cancelled:ctripChannelOrderUploadCancelledOrders,insight:ctripChannelOrderPortraitInsight,notice:typeof ctripChannelOrderUploadCancelEvidenceText==='undefined'?{value:''}:ctripChannelOrderUploadCancelEvidenceText};`,context);
  context.calls.handleCtripChannelOrderFileChange({target:{files:[{name:'synthetic.csv',size:20}]}});
  await context.calls.uploadCtripChannelOrders();return context.result;
}
const row=(overrides={})=>({label:'合成渠道甲',orders:9,gross_orders:10,cancelled_orders:1,cancel_rate:0.1,row_count:1,cancel_rate_status:'available',cancel_rate_missing_rows:0,...overrides});

test('missing cancellation stays unavailable instead of zero or highest-rate advice',async()=>{
  const s=await stateFor([row({cancelled_orders:null,cancel_rate:null,cancel_rate_status:'evidence_missing',cancel_rate_missing_rows:1})]);
  assert.equal(s.rate.value,null);assert.equal(s.cancelled.value,null);
  assert.match(s.notice.value,/不完整|缺失|未齐/);assert.doesNotMatch(s.insight.value,/取消率最高/);
});
test('mixed channel gaps preserve known counts but do not produce a total rate',async()=>{
  const s=await stateFor([row({cancelled_orders:2,orders:8,cancel_rate:0.2}),row({label:'合成渠道乙',gross_orders:5,orders:4,cancelled_orders:null,cancel_rate:null,cancel_rate_status:'evidence_missing',cancel_rate_missing_rows:1})]);
  assert.equal(s.cancelled.value,2);assert.equal(s.rate.value,null);
  assert.match(s.notice.value,/已知/);assert.doesNotMatch(s.insight.value,/取消率最高/);
});
test('backend partial row coverage gates rates even when both saved sums are numeric',async()=>{
  const s=await stateFor([row({gross_orders:20,cancelled_orders:2,row_count:2,cancel_rate:null,cancel_rate_status:'evidence_missing',cancel_rate_missing_rows:1})]);
  assert.equal(s.cancelled.value,2);assert.equal(s.rate.value,null);assert.doesNotMatch(s.insight.value,/取消率最高/);
});
test('real zero cancellation remains zero with a positive complete denominator',async()=>{
  const s=await stateFor([row({orders:10,cancelled_orders:0,cancel_rate:0})]);
  assert.equal(s.rate.value,0);assert.equal(s.cancelled.value,0);assert.equal(s.notice.value,'');
  assert.match(s.insight.value,/0\.0%/);
});
test('highest cancellation comparison includes a fully cancelled channel with no active orders',async()=>{
  const s=await stateFor([row(),row({label:'全取消渠道',orders:0,gross_orders:8,cancelled_orders:8,cancel_rate:1})]);
  assert.equal(s.rate.value,50);assert.match(s.insight.value,/取消率最高的是全取消渠道（100\.0%）/);
});
test('known zero denominator is not described as missing input or a zero rate',async()=>{
  const s=await stateFor([row({orders:0,gross_orders:0,cancelled_orders:0,cancel_rate:null,cancel_rate_status:'not_computable'})]);
  assert.equal(s.rate.value,null);assert.match(s.notice.value,/总单为0/);assert.doesNotMatch(s.notice.value,/不完整/);
});
test('legacy one-row paired counts remain compatible while ambiguous multiple-row coverage stays unavailable',async()=>{
  const single=row({cancel_rate_status:undefined,cancel_rate_missing_rows:undefined});
  const s=await stateFor([single]);assert.equal(s.rate.value,10);
  const partial=await stateFor([{...single,row_count:2}]);assert.equal(partial.rate.value,null);assert.match(partial.notice.value,/不完整|未齐/);
});
for(const invalid of [null,'',true,-1,11])test(`invalid or absent cancellation ${JSON.stringify(invalid)} cannot become a rate`,async()=>{
  const s=await stateFor([row({cancelled_orders:invalid})]);assert.equal(s.rate.value,null);
});
test('an upload containing only cancelled orders still reports its complete cancellation evidence',async()=>{
  const s=await stateFor([row({label:'全取消渠道',orders:0,cancelled_orders:10,cancel_rate:1})]);
  assert.equal(s.rate.value,100);assert.match(s.insight.value,/全取消渠道（100\.0%）/);
});
test('complete zero-count channels do not invalidate another channel positive denominator',async()=>{
  const s=await stateFor([row(),row({orders:0,gross_orders:0,cancelled_orders:0,cancel_rate:null,cancel_rate_status:'not_computable'})]);
  assert.equal(s.rate.value,10);assert.equal(s.notice.value,'');
});
