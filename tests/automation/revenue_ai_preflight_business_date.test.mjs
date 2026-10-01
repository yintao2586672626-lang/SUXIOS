import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import test from 'node:test';import vm from 'node:vm';
const c={window:{},URLSearchParams};for(const name of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${name}`,'utf8'),c);const h=c.window.SUXI_REVENUE_AI_STATIC;
const base={status:'ready_for_manual_generation',can_generate_pending_suggestions:true,hotel_id:64,business_date:'2026-09-30',target_hotel_ids:[64],target_date_rows:2,room_type_count:1,create_candidate_count:3,pending_suggestion_count:0,target_page:'agent-center',target_filter:{hotel_id:64,date:'2026-09-30'}};
const build=(patch={},extra={},date='2026-09-30')=>h.buildRevenueAiPricingGenerationPreflightSummary({overview:{hotel_id:64,business_date:date,as_of_date:'2026-09-30',pricing_generation_preflight:{...base,...patch}},...extra});
test('a previous-day preflight is rejected without reusing its numbers or target',()=>{
 const x=build({business_date:'2026-09-29',target_filter:{hotel_id:64,date:'2026-09-29'},detail:'合成旧日内容'});assert.equal(x.status,'blocked');assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.equal(x.createCandidateCount,null);assert.match(x.reasonText,/业务日期/);assert.doesNotMatch(JSON.stringify(x),/合成旧日内容|2026-09-29/);
});
test('missing, blank, malformed or impossible preflight dates stay unverified',()=>{
 for(const business_date of [undefined,null,'','2026-02-30','bad',true]){const x=build({business_date});assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.match(x.reasonText,/业务日期/);}
});
test('readonly navigation cannot switch to a different or invalid business date',()=>{
 for(const date of ['2026-09-29',null,'bad']){const x=build({target_filter:{hotel_id:64,date}});assert.equal(x.canOpenTarget,false);assert.equal(x.canGeneratePendingSuggestions,false);}
});
test('as-of date cannot substitute a missing business date',()=>{
 const x=build({business_date:undefined,as_of_date:'2026-09-30'});assert.equal(x.canGeneratePendingSuggestions,false);assert.match(x.reasonText,/业务日期/);
});
test('the accepted future business date is distinct from the older data baseline',()=>{
 const x=build({business_date:'2026-10-01',target_filter:{hotel_id:64,date:'2026-10-01'}},{},'2026-10-01');assert.equal(x.canGeneratePendingSuggestions,true);assert.equal(x.canOpenTarget,true);
});
test('same date and an omitted redundant navigation date preserve current readiness',()=>{
 assert.equal(build().canGeneratePendingSuggestions,true);assert.equal(build({target_filter:{hotel_id:64}}).canOpenTarget,true);
});
test('legacy overview callers without a date scope retain their local helper contract',()=>{
 const x=h.buildRevenueAiPricingGenerationPreflightSummary({overview:{hotel_id:64,pricing_generation_preflight:base}});assert.equal(x.canGeneratePendingSuggestions,true);
});
test('action-specific wrong-date preflight does not override current-day evidence',()=>{
 const x=h.buildRevenueAiActionRows({overview:{hotel_id:64,business_date:'2026-09-30',pricing_generation_preflight:base,actions:[{key:'pricing',pricing_generation_preflight:{...base,business_date:'2026-09-29'}}]}})[0].pricingGenerationPreflightSummary;assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.equal(x.createCandidateCount,null);
});
test('read failure or waiting still does not retain wrong-date content',()=>{
 for(const extra of [{overviewError:'合成读取失败'},{overviewLoading:true}]){const x=build({business_date:'2026-09-29'},extra);assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.createCandidateCount,null);assert.doesNotMatch(x.statusLabel,/前次/);}
});
