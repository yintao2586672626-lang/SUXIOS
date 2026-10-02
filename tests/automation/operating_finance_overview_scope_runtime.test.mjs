import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

function harness() {
  const sandbox={window:{},URLSearchParams,console};
  vm.runInNewContext(readFileSync('public/components/system/operating-finance-control-center.js','utf8'),sandbox);
  const component=sandbox.window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody;
  const requests=[];
  const h={hotels:[{id:7,name:'synthetic A'},{id:8,name:'synthetic B'}],selectedHotelId:'7',$emit(){}};
  Object.assign(h,component.data.call(h));
  for(const [key,method] of Object.entries(component.methods)) h[key]=method.bind(h);
  for(const [key,get] of Object.entries(component.computed)) Object.defineProperty(h,key,{get:()=>get.call(h)});
  h.request=url=>new Promise(resolve=>requests.push({url,resolve}));
  h.hotelId='7';h.periodMonth='2026-08';
  return {h,requests,component};
}
const payload=(hotel=7,month='2026-08',gop=7000)=>({code:200,data:{contract_version:'operating_finance_control_center.v1',hotel_id:hotel,period_month:month,boundaries:{external_write_count:0},monthly_finance:{period_month:month,results:{gop}},portfolio:{period_month:month,items:[{hotel_id:hotel,gop,rank:1}]}}});

for(const scope of ['month','hotel']) test(`changing ${scope} invalidates old monthly and portfolio facts before new response`,async()=>{
  const {h,requests}=harness();
  const loaded=h.loadOverview();requests.at(-1).resolve(payload());await loaded;
  assert.equal(h.currentFinance.results.gop,7000);assert.equal(h.currentPortfolio.items[0].rank,1);
  if(scope==='month')h.periodMonth='2026-07';else h.hotelId='8';
  const next=h.loadOverview();
  assert.equal(h.currentFinance.results,undefined);
  assert.equal(h.currentPortfolio.items,undefined);
  assert.equal(h.loading,true);
  requests.at(-1).resolve(payload(Number(h.hotelId),h.periodMonth,0));await next;
  assert.equal(h.currentFinance.results.gop,0);assert.equal(h.money(0),'¥0');assert.equal(h.money(null),'未取得');
});

test('loss of selectable hotels clears projection and invalidates a pending old response',async()=>{
  const {h,requests,component}=harness();
  const loaded=h.loadOverview();requests.at(-1).resolve(payload());await loaded;
  const pending=h.loadOverview();
  h.hotels=[];h.selectedHotelId='';
  component.watch.hotels.handler.call(h);
  assert.equal(h.hotelId,'');assert.equal(h.loading,false);assert.equal(h.overview,null);
  assert.equal(requests.length,2,'no request without a hotel');
  requests.at(-1).resolve(payload());await pending;
  assert.equal(h.overview,null);assert.equal(h.error,'');
});

test('old request does not finish current loading and a current failure can recover without stale values',async()=>{
  const {h,requests}=harness();
  const old=h.loadOverview();h.periodMonth='2026-07';const current=h.loadOverview();
  requests[0].resolve(payload());await old;
  assert.equal(h.loading,true);assert.equal(h.overview,null);
  requests[1].resolve({code:503,message:'synthetic current failure'});await current;
  assert.equal(h.error,'synthetic current failure');assert.equal(h.overview,null);
  const recovered=h.loadOverview();requests[2].resolve(payload(7,'2026-07',null));await recovered;
  assert.equal(h.currentFinance.results.gop,null);assert.equal(h.error,'');assert.equal(h.loading,false);
});
