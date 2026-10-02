import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const sandbox={window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api=sandbox.window.SUXI_MEITUAN_STATIC;
const row=extra=>({id:1,source:'meituan',data_type:'order',system_hotel_id:80,data_date:'2026-09-25',dimension:'synthetic',...extra});

test('blank or unit-only strings cannot become obtained zero order facts',()=>{
 for(const value of [' ','\t\n','%','￥','¥元',' , ， ']){
  const input=row({book_order_num:value,quantity:value,amount:value});
  const data=api.buildMeituanDownloadData([input]);
  assert.equal(data.orderBookOrder,null,JSON.stringify(value));assert.equal(data.orderQuantity,null);assert.equal(data.orderAmount,null);
  assert.equal(data.orderCoverage.amount.status,'missing');
  assert.equal(data.orderRows[0].amount,null);assert.equal(data.orderRows[0].book_order_num,null);assert.equal(data.orderRows[0].quantity,null);
  assert.equal(input.amount,value,'display projection must not mutate saved source');
 }
});
test('arrays and objects are invalid metrics even when coercion could yield zero or one',()=>{
 for(const value of [[],[0],[1],{},true,false]){
  assert.equal(api.getMeituanExposureMetricValue({list_exposure:value}),null);
 }
});
test('true zero and supported formatted numbers remain available in summary and rows',()=>{
 for(const value of [0,'0',' 0 ','￥0元']){
  const data=api.buildMeituanDownloadData([row({amount:value,quantity:1})]);
  assert.equal(data.orderAmount,0);assert.equal(data.orderRows[0].amount,0);assert.equal(data.orderCoverage.amount.status,'complete');
 }
 const data=api.buildMeituanDownloadData([row({amount:'￥1,234.56元',quantity:'2'})]);
 assert.equal(data.orderRows[0].amount,1234.56);assert.equal(data.orderRows[0].quantity,2);
});
test('empty canonical fields can fall through to a real alias without forcing zero',()=>{
 assert.equal(api.getMeituanExposureMetricValue({list_exposure:' ',exposure_count:20}),20);
 assert.equal(api.getMeituanClickMetricValue({detail_exposure:[],click_count:0}),0);
});
test('advertising table metrics and CSV preserve blank exposure and click values as missing',()=>{
 const data=api.buildMeituanDownloadData([row({data_type:'advertising',list_exposure:' ',detail_exposure:'%',amount:'¥',book_order_num:[]})]);
 assert.equal(data.adsExposure,null);assert.equal(data.adsClick,null);assert.equal(data.adsClickRate,null);
 assert.equal(data.adsCoverage.exposure.status,'missing');
 assert.equal(api.hasMeituanExposureMetric(data.adsRows[0]),false);
 const csv=api.buildMeituanStoredPageCsvPayload('ads',data,{});
 assert.match(csv.csv,/synthetic,,,,,,,,/);
});
