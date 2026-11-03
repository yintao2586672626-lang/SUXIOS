import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import test from 'node:test';import vm from 'node:vm';
const c={window:{},URLSearchParams};for(const name of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${name}`,'utf8'),c);const h=c.window.SUXI_REVENUE_AI_STATIC;
const base={status:'ready_for_manual_generation',can_generate_pending_suggestions:true,hotel_id:64,target_hotel_ids:[64],target_date_rows:2,room_type_count:1,create_candidate_count:3,pending_suggestion_count:0,target_page:'agent-center',target_filter:{hotel_id:64}};
const build=(patch={},extra={},hotel=64)=>h.buildRevenueAiPricingGenerationPreflightSummary({overview:{hotel_id:hotel,pricing_generation_preflight:{...base,...patch}},...extra});
test('a foreign hotel preflight is rejected without displaying or navigating its content',()=>{
 const x=build({hotel_id:65,target_hotel_ids:[65],target_filter:{hotel_id:65},detail:'合成外酒店内容'});
 assert.equal(x.visible,true);assert.equal(x.status,'blocked');assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.deepEqual(Array.from(x.targetHotelIds),[]);assert.equal(x.createCandidateCount,null);assert.doesNotMatch(JSON.stringify(x),/合成外酒店内容|"hotel_id":65/);assert.match(x.reasonText,/酒店.*范围.*不一致/);
});
test('mixed or absent target hotel lists do not fit a single-hotel overview',()=>{
 for(const target_hotel_ids of [[64,65],[],undefined,[64,null]]){const x=build({target_hotel_ids});assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.match(x.reasonText,/范围/);}
});
test('an explicit conflicting preflight hotel identity is rejected even when its list matches',()=>{
 for(const hotel_id of [65,null,0,true]){const x=build({hotel_id});assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);}
});
test('readonly navigation cannot silently broaden to another hotel or the portfolio',()=>{
 for(const hotel_id of [65,0,null,true]){const x=build({target_filter:{hotel_id}});assert.equal(x.canOpenTarget,false);assert.equal(x.canGeneratePendingSuggestions,false);}
});
test('matching hotel and legacy missing redundant identity or filter retain valid readiness',()=>{
 for(const patch of [{},{hotel_id:undefined,target_filter:{}},{hotel_id:'64',target_hotel_ids:['64']}]){const x=build(patch);assert.equal(x.canGeneratePendingSuggestions,true);assert.equal(x.canOpenTarget,true);}
});
test('portfolio scope accepts an explicit portfolio preflight and complete hotel list',()=>{
 const x=build({hotel_id:null,target_hotel_ids:[64,65],target_filter:{hotel_id:0}}, {},null);assert.equal(x.canGeneratePendingSuggestions,true);assert.equal(x.targetHotelCount,2);
 const mismatch=build({}, {},null);assert.equal(mismatch.canGeneratePendingSuggestions,false);assert.equal(mismatch.canOpenTarget,false);
});
test('a legacy unscoped caller retains the existing local helper contract',()=>{
 const x=h.buildRevenueAiPricingGenerationPreflightSummary({overview:{pricing_generation_preflight:base}});assert.equal(x.canGeneratePendingSuggestions,true);
});
test('foreign content remains rejected during a read failure or retry',()=>{
 for(const state of [{overviewError:'合成读取失败'},{overviewLoading:true}]){const x=build({hotel_id:65,target_hotel_ids:[65]},state);assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.equal(x.targetHotelCount,null);assert.doesNotMatch(x.statusLabel,/前次/);}
});
test('action-specific foreign preflight cannot replace the current hotel evidence',()=>{
 const x=h.buildRevenueAiActionRows({overview:{hotel_id:64,pricing_generation_preflight:base,actions:[{key:'pricing',pricing_generation_preflight:{...base,hotel_id:65,target_hotel_ids:[65],target_filter:{hotel_id:65}}}]}})[0].pricingGenerationPreflightSummary;
 assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.equal(x.createCandidateCount,null);
});
