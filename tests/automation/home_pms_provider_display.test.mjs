import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const context={window:{}};
vm.runInNewContext(fs.readFileSync('public/home-static.js','utf8'),context);
// Actual RevenueFactLayerService::build output with injected synthetic captures;
// captured by round218/capture-fact-layers.php, no business database or credentials.
const fixtures=JSON.parse(fs.readFileSync('tests/fixtures/revenue-fact-layer/pms-provider-display.json','utf8'));
const source=provider=>structuredClone(fixtures[provider]);
const model=(layer,overrides={})=>context.window.SUXI_HOME_STATIC.buildHomeBusinessTimeModel({
 revenueFactLayer:layer,selectedHotelId:80,selectedBusinessDate:'2026-07-30',...overrides,
}).yesterday;
const fact=(view,key)=>[...view.wholeHotelFacts,...view.wholeHotelDerivedFacts].find(row=>row.key===key);

test('selected verified Meituan Cloud PMS facts render with estimated revenue and directly captured room supply',()=>{
 const layer=source('meituan_cloud_pms'),view=model(layer);
 for(const key of ['sold_room_nights','sellable_room_nights','occupancy_rate_percent','room_revenue','whole_hotel_adr','whole_hotel_revpar'])assert.equal(fact(view,key).ready,true,key);
 assert.match(fact(view,'room_revenue').value,/7,?200/);
 assert.match(fact(view,'room_revenue').label,/预计/);
 assert.match(fact(view,'room_revenue').detail,/美团云 PMS/);
 assert.match(fact(view,'room_revenue').detail,/预计/);
 assert.doesNotMatch(fact(view,'sellable_room_nights').label,/推导/);
 assert.match(fact(view,'sellable_room_nights').detail,/总房量/);
 assert.equal(fact(view,'payment_collected_amount').ready,false);
 const row=view.dateSourceRows.find(row=>row.key==='meituan_cloud_pms');
 assert.equal(row?.status,'同日已验证');assert.match(row?.label,/美团云 PMS/);
 assert.equal(view.dateSourceRows.some(row=>row.key==='dingdandao_pms'),false);
});

test('explicit selected missing, failed or mismatched provider never borrows the other PMS facts',()=>{
 for(const mutation of [
  layer=>{delete layer.sources.meituan_cloud_pms;},
  layer=>{layer.sources.meituan_cloud_pms.data_status='read_failed';},
  layer=>{layer.sources.meituan_cloud_pms.actual_business_date='2026-07-29';},
  layer=>{layer.pms_binding.effective_provider='unsupported';},
  layer=>{layer.pms_binding.effective_provider='';},
 ]){
  const layer=source('meituan_cloud_pms');
  layer.sources.dingdandao_pms=source('dingdandao_pms').sources.dingdandao_pms;
  mutation(layer);
  assert.equal(fact(model(layer),'room_revenue').ready,false);
 }
});

test('unbound historical single PMS remains compatible but ambiguous sources stay unavailable',()=>{
 for(const provider of ['dingdandao_pms','meituan_cloud_pms']){
  const layer=source(provider);delete layer.pms_binding;
  assert.equal(fact(model(layer),'room_revenue').ready,true,provider);
 }
 const layer=source('meituan_cloud_pms');delete layer.pms_binding;
 layer.sources.dingdandao_pms=source('dingdandao_pms').sources.dingdandao_pms;
 assert.equal(fact(model(layer),'room_revenue').ready,false);
});

test('true zero, missing metrics and scope changes remain distinct and recover with the exact selected source',()=>{
 const layer=source('meituan_cloud_pms');
 layer.facts.whole_hotel_accommodation.room_revenue=0;
 assert.equal(fact(model(layer),'room_revenue').ready,true);
 assert.match(fact(model(layer),'room_revenue').value,/0/);
 layer.sources.meituan_cloud_pms.fact_statuses.room_revenue.status='missing';
 assert.equal(fact(model(layer),'room_revenue').ready,false);
 layer.sources.meituan_cloud_pms.fact_statuses.room_revenue.status='readback_verified';
 assert.equal(fact(model(layer),'room_revenue').ready,true);
 assert.equal(fact(model(layer,{selectedBusinessDate:'2026-07-29'}),'room_revenue').ready,false);
 assert.equal(fact(model(layer,{selectedHotelId:81}),'room_revenue').ready,false);
 assert.equal(fact(model(source('dingdandao_pms')),'room_revenue').ready,true);
 assert.doesNotMatch(fact(model(source('dingdandao_pms')),'room_revenue').label,/预计/);
});
