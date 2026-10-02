import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const sandbox={window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api=sandbox.window.SUXI_MEITUAN_STATIC;
const row=extra=>({id:1,source:'meituan',data_type:'advertising',system_hotel_id:80,
 data_date:'2026-09-25',dimension:'campaign-a',list_exposure:100,detail_exposure:10,flow_rate:null,...extra});

test('missing saved ad conversion never falls back to its click-through rate',()=>{
 const item=row();
 assert.equal(api.getMeituanFlowRateMetricValue(item),null);
 assert.equal(api.hasMeituanFlowRateMetric(item),false);
 const data=api.buildMeituanDownloadData([item]);
 assert.equal(data.adsClickRate,10,'CTR remains a separate valid metric');
 const csv=api.buildMeituanStoredPageCsvPayload('ads',data,{});
 assert.match(csv.csv,/campaign-a,100,10,,/);
});
test('zero clicks without returned order conversion remains missing, including legacy ads',()=>{
 for(const data_type of ['advertising','ads']) assert.equal(api.getMeituanFlowRateMetricValue(row({data_type,detail_exposure:0})),null);
});
test('saved percentage units and explicit zero keep their exact meaning',()=>{
 for(const [key,value] of [['flow_rate',0],['flow_rate',0.5],['conversion_rate',4.5],['conversionRate',20]]){
  assert.equal(api.getMeituanFlowRateMetricValue(row({[key]:value})),value);
 }
});
test('traffic exposure-to-click behavior stays unchanged',()=>{
 assert.equal(api.getMeituanFlowRateMetricValue(row({data_type:'traffic'})),10);
 assert.equal(api.getMeituanFlowRateMetricValue(row({data_type:'traffic_analysis',detail_exposure:0})),0);
 assert.equal(api.getMeituanFlowRateMetricValue(row({data_type:'traffic',list_exposure:0})),null);
});
test('missing stored conversion is not reconstructed from incomplete old order fields',()=>{
 for(const extra of [{book_order_num:0,detail_exposure:null},{book_order_num:1,detail_exposure:0},{book_order_num:2,detail_exposure:10}]){
  assert.equal(api.getMeituanFlowRateMetricValue(row(extra)),null);
 }
});
