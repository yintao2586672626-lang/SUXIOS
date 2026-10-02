import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const sandbox = {window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api = sandbox.window.SUXI_MEITUAN_STATIC;
const row = overrides => ({id:101,source:'meituan',data_type:'order',system_hotel_id:80,
  hotel_name:'Synthetic Hotel',data_date:'2026-09-25',dimension:'order:paid:'+'a'.repeat(64),
  book_order_num:1,quantity:1,amount:100,data_value:100,...overrides});

test('different saved orders with identical amounts remain separate in rows and page subtotals', () => {
  const data = api.buildMeituanDownloadData([row(),row({id:100,dimension:'order:paid:'+'b'.repeat(64)})]);
  assert.equal(data.orderRows.length,2);
  assert.equal(data.orderBookOrder,2);
  assert.equal(data.orderQuantity,2);
  assert.equal(data.orderAmount,200);
  assert.equal(data.orderCoverage.amount.total,2);
});

test('identical snapshots of the same order keep the first input record', () => {
  const data = api.buildMeituanDownloadData([row(),row({id:99})]);
  assert.deepEqual(Array.from(data.orderRows,item=>item.id),[101]);
});

test('aggregate identity cannot hide a distinct saved order with matching metrics', () => {
  const data = api.buildMeituanDownloadData([row(),row({id:100,dimension:'order:aggregate:'+'c'.repeat(64)})]);
  assert.equal(data.orderRows.length,2);
  // These are page samples, not a claim that aggregate and detail represent disjoint orders.
  assert.match(data.orderRows[1].dimension,/^order:aggregate:/);
});

test('legacy orders use record identity and unknown identities are preserved', () => {
  const data = api.buildMeituanDownloadData([
    row({dimension:'',id:101,source_trace_id:'shared-batch'}),
    row({dimension:null,id:100,source_trace_id:'shared-batch'}),
    row({dimension:' ',id:undefined}),row({dimension:undefined,id:undefined}),
    row({dimension:'',id:101,source_trace_id:'shared-batch'}),
  ]);
  assert.equal(data.orderRows.length,4);
});

test('hotel, business date, platform and changed metrics remain distinct without mutating source rows', () => {
  const rows = [row({amount:'100',raw_data:'{"source":"synthetic"}'}),
    row({id:100,system_hotel_id:121}),row({id:99,data_date:'2026-09-26'}),
    row({id:98,data_value:120}),row({id:97,amount:0}),row({id:96,source:'ctrip'})];
  const before = JSON.stringify(rows);
  const data = api.buildMeituanDownloadData(rows);
  assert.equal(data.orderRows.length,5);
  assert.equal(JSON.stringify(rows),before);
  assert.equal(data.orderRows[0].raw_data,rows[0].raw_data);
  assert.equal(data.orderRows[0].data_date,'2026-09-25');
});
