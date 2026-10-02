import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const sandbox = {window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api = sandbox.window.SUXI_MEITUAN_STATIC;
const row = overrides => ({id:101,source:'meituan',data_type:'advertising',system_hotel_id:80,
  hotel_name:'Synthetic Hotel',data_date:'2026-09-25',dimension:'ads:identity:campaign-a',
  list_exposure:100,detail_exposure:10,amount:20,book_order_num:1,data_value:2,...overrides});

test('different saved advertising identities remain separate in table, totals and CSV', () => {
  const data = api.buildMeituanDownloadData([row(),row({id:100,dimension:'ads:identity:campaign-b'})]);
  assert.equal(data.adsRowsCount,2);
  assert.equal(data.adsExposure,200);
  assert.equal(data.adsClick,20);
  const csv = api.buildMeituanStoredPageCsvPayload('ads',data,{hotelId:80,startDate:'2026-09-25'});
  assert.equal(csv.rows.length,2);
  assert.match(csv.csv,/ads:identity:campaign-a/);
  assert.match(csv.csv,/ads:identity:campaign-b/);
});

test('identical snapshots of the same ad retain only the newest input record', () => {
  const data = api.buildMeituanDownloadData([row(),row({id:99})]);
  assert.deepEqual(Array.from(data.adsRows,item=>item.id),[101]);
});

test('legacy ads without a dimension retain distinct record identities and unknown identities', () => {
  const data = api.buildMeituanDownloadData([row({dimension:'',id:101,source_trace_id:'batch-a'}),row({dimension:null,id:100,source_trace_id:'batch-a'}),
    row({dimension:' ',id:undefined}),row({dimension:undefined,id:undefined})]);
  assert.equal(data.adsRowsCount,4);
});

test('same-ad snapshots with different displayed ROAS or attributed revenue are not discarded', () => {
  const data = api.buildMeituanDownloadData([row(),row({id:100,data_value:3}),row({id:99,order_amount:60})]);
  assert.equal(data.adsRowsCount,3);
  const csv = api.buildMeituanStoredPageCsvPayload('ads',data,{});
  assert.equal(csv.rows.length,3);
});

test('display aliases participate in fact equality and hotel/date/platform scope stays separate', () => {
  const data = api.buildMeituanDownloadData([row(),row({id:100,amount:null,spend:21}),
    row({id:99,book_order_num:null,orders:2}),row({id:98,system_hotel_id:121}),
    row({id:97,data_date:'2026-09-26'}),row({id:96,source:'ctrip'})]);
  assert.equal(data.adsRowsCount,5);
});
