import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import test from 'node:test';import vm from 'node:vm';
const c={window:{},URLSearchParams};for(const name of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${name}`,'utf8'),c);
const helpers=c.window.SUXI_REVENUE_AI_STATIC;
const valid={status:'ready_for_manual_generation',reason:'pricing_generation_candidates_ready',can_generate_pending_suggestions:true,target_hotel_ids:[64],target_date_rows:2,room_type_count:1,create_candidate_count:3,pending_suggestion_count:0,target_page:'agent-center'};
const build=(patch={},read={})=>helpers.buildRevenueAiPricingGenerationPreflightSummary({overview:{pricing_generation_preflight:{...valid,...patch}},...read});
test('blocked, unknown, legacy skip or pending-review status cannot be made ready by a true flag',()=>{
 for(const status of ['blocked','unknown','not_loaded','skipped_by_operator_policy','pending_review_exists']){const x=build({status});assert.equal(x.canGeneratePendingSuggestions,false,status);assert.notEqual(x.statusLabel,'可生成待审');}
});
test('ready with a false or absent permission flag explicitly exposes contradictory evidence',()=>{
 for(const flag of [false,undefined,'true']){const x=build({can_generate_pending_suggestions:flag});assert.equal(x.canGeneratePendingSuggestions,false);assert.notEqual(x.statusLabel,'可生成待审');assert.match(x.reasonText,/不一致|不足|缺失|不能/);}
});
test('missing required summary counts block a ready claim without pretending the missing field is zero',()=>{
 for(const key of ['target_date_rows','room_type_count','create_candidate_count','pending_suggestion_count']){const x=build({[key]:null});assert.equal(x.canGeneratePendingSuggestions,false,key);assert.equal(x.status,'blocked');assert.match(x.reasonText,/不足|未提供|缺失/);}
});
test('missing, invalid or contradictory hotel scope cannot support generation readiness',()=>{
 for(const patch of [{target_hotel_ids:undefined},{target_hotel_ids:[]},{target_hotel_ids:[64,null]},{target_hotel_count:2},{target_hotel_count:0}]){const x=build(patch);assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.status,'blocked');}
});
test('no room types, no candidates or existing pending review preserve the backend gate',()=>{
 for(const patch of [{room_type_count:0},{create_candidate_count:0},{pending_suggestion_count:1}]){const x=build(patch);assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.status,'blocked');assert.notEqual(x.statusLabel,'可生成待审');}
});
test('valid current readiness and a known zero OTA row count remain compatible',()=>{
 const x=build();assert.equal(x.statusLabel,'可生成待审');assert.equal(x.canGeneratePendingSuggestions,true);assert.equal(x.canOpenTarget,true);
 const zero=build({target_date_rows:0});assert.equal(zero.canGeneratePendingSuggestions,true);assert.equal(zero.targetDateRows,0);
});
test('read failure and waiting retain their higher-priority truthful state',()=>{
 const failed=build({create_candidate_count:null},{overviewError:'合成读取失败'});assert.equal(failed.status,'failed');assert.match(failed.statusLabel,/失败/);assert.match(failed.reasonText,/合成读取失败/);assert.equal(failed.canGeneratePendingSuggestions,false);
 const waiting=build({create_candidate_count:null},{overviewLoading:true});assert.match(waiting.statusLabel,/读取中/);assert.equal(waiting.canGeneratePendingSuggestions,false);
});
test('action card consumes the same incomplete preflight readiness without removing readonly navigation',()=>{
 const x=helpers.buildRevenueAiActionRows({overview:{pricing_generation_preflight:{...valid,create_candidate_count:null}}})[0].pricingGenerationPreflightSummary;
 assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.status,'blocked');assert.equal(x.canOpenTarget,true);
});
