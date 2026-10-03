import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const c={window:{},URLSearchParams};for(const file of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${file}`,'utf8'),c);
const helpers=c.window.SUXI_REVENUE_AI_STATIC;
const build=preflight=>helpers.buildRevenueAiPricingGenerationPreflightSummary({overview:{pricing_generation_preflight:{status:'blocked',...preflight}}});
const fields={target_hotel_count:'targetHotelCount',target_date_rows:'targetDateRows',room_type_count:'roomTypeCount',create_candidate_count:'createCandidateCount',pending_suggestion_count:'pendingSuggestionCount',skipped_candidate_count:'skippedCandidateCount'};
test('a partial preflight leaves all absent counts unknown',()=>{const x=build({});for(const key of Object.values(fields))assert.equal(x[key],null,key);assert.match(x.detailText,/未提供/);assert.doesNotMatch(x.detailText,/OTA行 0|房型 0|候选 0|待审 0/);});
test('explicit actual zeros remain zeros including a zero hotel count',()=>{const x=build({...Object.fromEntries(Object.keys(fields).map(key=>[key,0])),target_hotel_ids:[64]});for(const key of Object.values(fields))assert.equal(x[key],0,key);assert.match(x.detailText,/OTA行 0/);});
test('null, blank, booleans, malformed, fractional and nonfinite counts remain missing',()=>{
 for(const [input,key] of Object.entries(fields))for(const value of [null,'',' ',true,false,NaN,Infinity,-1,0.5,'2.5','bad',[],{},Number.MAX_SAFE_INTEGER+1])assert.equal(build({[input]:value})[key],null,`${input}: ${String(value)}`);
});
test('finite nonnegative integers and canonical numeric strings are accepted',()=>{
 for(const [input,key] of Object.entries(fields))for(const value of [0,3,'0','3',' 3 '])assert.equal(build({[input]:value})[key],Number(value),`${input}: ${value}`);
});
test('only an explicit complete valid hotel ID list can derive a missing hotel count',()=>{
 assert.equal(build({target_hotel_ids:[64,'65',64]}).targetHotelCount,2);assert.equal(build({target_hotel_ids:[]}).targetHotelCount,0);
 for(const ids of [undefined,null,'64',[64,null],[64,0],[64,'bad'],[true],[-1],[0.5]])assert.equal(build({target_hotel_ids:ids}).targetHotelCount,null,JSON.stringify(ids));
 assert.equal(build({target_hotel_count:null,target_hotel_ids:[64]}).targetHotelCount,null);
});
test('detail text and action summary share the same missing count contract',()=>{
 const overview={pricing_generation_preflight:{status:'blocked',target_hotel_ids:[64],target_date_rows:4,room_type_count:null,create_candidate_count:0,pending_suggestion_count:'bad'}};
 const x=helpers.buildRevenueAiPricingGenerationPreflightSummary({overview}),a=helpers.buildRevenueAiActionRows({overview})[0].pricingGenerationPreflightSummary;
 assert.equal(a.detailText,x.detailText);assert.match(x.detailText,/OTA行 4/);assert.match(x.detailText,/房型 未提供/);assert.match(x.detailText,/候选 0/);assert.match(x.detailText,/待审 未提供/);assert.doesNotMatch(x.detailText,/NaN/);
});

test('per-hotel checks distinguish omitted counts from real zero and reject malformed counts',()=>{
 const names={target_date_rows:'targetDateRows',room_type_count:'roomTypeCount',pending_suggestions:'pendingSuggestions',demand_forecasts:'demandForecasts',competitor_analysis_recent:'competitorAnalysisRecent',create_candidate_count:'createCandidateCount',skipped_candidate_count:'skippedCandidateCount'};
 for(const value of [undefined,null,'',true,-1,0.5,'bad',0,'0',3]){
  const input={hotel_id:64,...Object.fromEntries(Object.keys(names).map(key=>[key,value]))};
  const check=build({hotel_checks:[input]}).hotelChecks[0];
  for(const key of Object.values(names))assert.equal(check[key],[0,'0',3].includes(value)?Number(value):null,key);
 }
});
