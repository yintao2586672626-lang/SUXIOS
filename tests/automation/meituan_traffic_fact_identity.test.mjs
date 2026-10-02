import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const sandbox={window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api=sandbox.window.SUXI_MEITUAN_STATIC;
const row=extra=>({id:101,source:'meituan',data_type:'traffic_analysis',system_hotel_id:80,data_date:'2026-09-25',
  dimension:'traffic_analysis:source_a',list_exposure:100,detail_exposure:10,order_submit_num:2,order_filling_num:null,flow_rate:20,...extra});

test('saved traffic dimensions remain separately accessible when metrics match',()=>{
  const data=api.buildMeituanDownloadData([row(),row({id:100,dimension:'traffic_analysis:source_b'})]);
  assert.deepEqual(Array.from(data.trafficRows,r=>r.id),[101,100]);
});

test('same dimension and metric snapshots still deduplicate',()=>{
  const data=api.buildMeituanDownloadData([row(),row({id:100})]);
  assert.deepEqual(Array.from(data.trafficRows,r=>r.id),[101]);
});

test('legacy traffic without dimension retains distinct record identities',()=>{
  const data=api.buildMeituanDownloadData([row({dimension:''}),row({id:100,dimension:null}),row({id:undefined,dimension:' '}),row({id:undefined,dimension:null})]);
  assert.equal(data.trafficRows.length,4);
});

test('blank metric rows stay hidden and true zero remains visible without filling source fields',()=>{
  const zero=row({list_exposure:0,detail_exposure:0,order_submit_num:0,flow_rate:0});
  const blank=row({id:100,dimension:'traffic_analysis:blank',list_exposure:null,detail_exposure:null,order_submit_num:null,flow_rate:null});
  const rows=[zero,blank],before=JSON.stringify(rows);
  const data=api.buildMeituanDownloadData(rows);
  assert.equal(data.allRows.length,2);
  assert.deepEqual(Array.from(data.trafficRows,r=>r.id),[101]);
  assert.equal(data.trafficRows[0].order_filling_num,null);
  assert.equal(JSON.stringify(rows),before);
});

test('traffic identity remains scoped by hotel, business date and source',()=>{
  const data=api.buildMeituanDownloadData([row(),row({id:100,system_hotel_id:121}),row({id:99,data_date:'2026-09-26'}),row({id:98,source:'ctrip'})]);
  assert.equal(data.trafficRows.length,3);
});
