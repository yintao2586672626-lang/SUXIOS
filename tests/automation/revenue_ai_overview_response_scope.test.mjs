import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const context={window:{},URLSearchParams};
for(const file of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js']){
  vm.runInNewContext(readFileSync(`public/${file}`,'utf8'),context,{filename:file});
}
const resolve=context.window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiOverviewResponse;
const fixture=(overrides={})=>({hotel_id:64,business_date:'2026-09-30',as_of_date:'2026-09-30',as_of_date_contract_version:'revenue_overview_as_of_date.v1',data_status:'data_missing',...overrides});
const result=(data,expectedScope={hotelId:'64',businessDate:'2026-09-30'})=>resolve({response:{code:200,data},expectedScope});

test('exact hotel and business date may return a truthful data gap',()=>{
  const r=result(fixture());assert.equal(r.ok,true);assert.equal(r.overview.data_status,'data_missing');assert.equal(r.errorMessage,'');
});
for(const hotel of [65,null,0,'',true,'64.0'])test(`wrong or missing hotel ${JSON.stringify(hotel)} is not accepted for hotel64`,()=>{
  const r=result(fixture({hotel_id:hotel}));assert.equal(r.ok,false);assert.equal(r.overview,null);assert.match(r.errorMessage,/酒店|范围/);
});
for(const date of ['2026-10-01',null,''])test(`wrong or missing business date ${JSON.stringify(date)} is not accepted`,()=>{
  const r=result(fixture({business_date:date}));assert.equal(r.ok,false);assert.equal(r.overview,null);assert.match(r.errorMessage,/日期|范围/);
});
test('numeric and canonical text hotel identities remain compatible',()=>{
  assert.equal(result(fixture({hotel_id:'64'})).ok,true);
});
test('an explicit portfolio request accepts its null hotel scope and rejects a single hotel receipt',()=>{
  const expected={hotelId:'',businessDate:'2026-09-30'};
  assert.equal(result(fixture({hotel_id:null}),expected).ok,true);
  assert.equal(result(fixture(),expected).ok,false);
});
test('unscoped legacy helper callers retain the existing as-of contract validation',()=>{
  assert.equal(resolve({response:{code:200,data:fixture({hotel_id:null})}}).ok,true);
  assert.equal(resolve({response:{code:200,data:fixture({as_of_date_contract_version:''})}}).ok,false);
});
test('an explicitly invalid expected hotel cannot disable scope validation',()=>{
  const r=result(fixture(),{hotelId:'not-a-hotel',businessDate:'2026-09-30'});assert.equal(r.ok,false);assert.equal(r.overview,null);
});
test('a future business date remains separate from its as-of date',()=>{
  const r=result(fixture({business_date:'2026-10-01'}),{hotelId:64,businessDate:'2026-10-01'});assert.equal(r.ok,true);
});
test('an omitted request date still requires a valid returned business date',()=>{
  const expected={hotelId:64,businessDate:''};assert.equal(result(fixture(),expected).ok,true);assert.equal(result(fixture({business_date:'2026-02-30'}),expected).ok,false);
});
test('the revenue-bundle consumer supplies its captured request identity to the actual resolver',()=>{
  const main=readFileSync('public/app-main.js','utf8');
  const begin=main.indexOf('const overviewResult = revenueAiResolveOverviewResponse({');
  const block=main.slice(begin,main.indexOf('});',begin)+3);
  const run=payload=>vm.runInNewContext(`${block};overviewResult;`,{revenueAiResolveOverviewResponse:resolve,payload:{overview:payload},requestContext:{hotelId:'64'},businessDate:'2026-09-30'});
  assert.equal(run(fixture()).ok,true);assert.equal(run(fixture({hotel_id:65})).ok,false);assert.equal(run(fixture({business_date:'2026-10-01'})).ok,false);
});
