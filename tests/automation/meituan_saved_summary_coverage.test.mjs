import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const sandbox={window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api=sandbox.window.SUXI_MEITUAN_STATIC;
const row=(id,extra={})=>({id,source:'meituan',system_hotel_id:80,data_date:'2026-09-25',data_type:'order',dimension:'scope-'+id,...extra});
test('order subtotals retain real zero with per-metric partial coverage',()=>{
 const data=api.buildMeituanDownloadData([row(1,{book_order_num:0,quantity:null,amount:20}),row(2,{book_order_num:1,quantity:null,amount:null})]);
 assert.equal(data.orderBookOrder,1);assert.equal(data.orderQuantity,null);assert.equal(data.orderAmount,20);
 assert.deepEqual(JSON.parse(JSON.stringify(data.orderCoverage.bookOrder)),{observed:2,total:2,missing:0,status:'complete',label:'当前页 2/2 条'});
 assert.equal(data.orderCoverage.quantity.status,'missing');
 assert.equal(data.orderCoverage.amount.status,'partial');
 assert.match(data.orderCoverage.amount.label,/部分小计.*1\/2/);
 const zero=api.buildMeituanDownloadData([row(1,{amount:0}),row(2,{amount:null})]);
 assert.equal(zero.orderAmount,0);assert.equal(zero.orderCoverage.amount.observed,1);
});
test('no rows and all missing rows have different coverage without fabricated zero',()=>{
 const empty=api.buildMeituanDownloadData([]);
 assert.equal(empty.orderAmount,null);assert.equal(empty.orderCoverage.amount.label,'暂无记录');
 const missing=api.buildMeituanDownloadData([row(1)]);
 assert.equal(missing.orderAmount,null);assert.equal(missing.orderCoverage.amount.label,'未取得 · 0/1 条');
});
test('unpaired ad metrics cannot manufacture a current-page click-through rate',()=>{
 const data=api.buildMeituanDownloadData([row(1,{data_type:'advertising',list_exposure:100,detail_exposure:null}),row(2,{data_type:'advertising',list_exposure:null,detail_exposure:10})]);
 assert.equal(data.adsExposure,100);assert.equal(data.adsClick,10);assert.equal(data.adsClickRate,null);
 assert.equal(data.adsCoverage.exposure.status,'partial');assert.equal(data.adsCoverage.click.status,'partial');
 assert.match(data.adsRateNotice,/记录缺少曝光或点击/);
});
test('partial zero-click totals do not imply a complete zero rate',()=>{
 const data=api.buildMeituanDownloadData([row(1,{data_type:'advertising',list_exposure:100,detail_exposure:0}),row(2,{data_type:'advertising'})]);
 assert.equal(data.adsClick,0);assert.equal(data.adsClickRate,null);assert.equal(data.adsCoverage.click.observed,1);
});
test('complete paired ads use weighted totals, keep zero and reject a zero denominator',()=>{
 const ads=(id,exposure,click)=>row(id,{data_type:'advertising',list_exposure:exposure,detail_exposure:click});
 const data=api.buildMeituanDownloadData([ads(1,100,0),ads(2,200,10)]);
 assert.equal(data.adsClickRate,10/300*100);assert.equal(data.adsCoverage.click.status,'complete');
 assert.equal(api.buildMeituanDownloadData([ads(1,100,0)]).adsClickRate,0);
 const zero=api.buildMeituanDownloadData([ads(1,0,0)]);
 assert.equal(zero.adsClickRate,null);assert.match(zero.adsRateNotice,/曝光合计为 0/);
 const invalid=api.buildMeituanDownloadData([ads(1,-10,0)]);
 assert.equal(invalid.adsClickRate,null);assert.match(invalid.adsRateNotice,/曝光合计无效/);
});
test('coverage counts only the actual category and deduplicated visible records',()=>{
 const a=row(1,{data_type:'advertising',list_exposure:100,detail_exposure:1});
 const data=api.buildMeituanDownloadData([a,{...a,id:2},row(3,{data_type:'advertising',list_exposure:100,detail_exposure:1}),row(4),row(5,{source:'ctrip',data_type:'advertising'})]);
 assert.equal(data.adsRowsCount,2);assert.equal(data.adsCoverage.exposure.total,2);
 assert.equal(data.orderCoverage.amount.total,1);
});
