import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import test from 'node:test';import vm from 'node:vm';
const c={window:{},URLSearchParams};for(const file of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${file}`,'utf8'),c);const h=c.window.SUXI_REVENUE_AI_STATIC;
const base={status:'ready_for_manual_generation',can_generate_pending_suggestions:true,target_hotel_ids:[64],target_date_rows:2,room_type_count:1,create_candidate_count:3,pending_suggestion_count:0,target_page:'agent-center'};
const build=(patch={},extra={})=>h.buildRevenueAiPricingGenerationPreflightSummary({overview:{pricing_generation_preflight:{...base,...patch}},...extra});
test('a readonly preflight cannot declare automatic OTA writes and remain ready',()=>{
 const x=build({auto_write_ota:true,detail:'合成矛盾自动写入声明'});assert.equal(x.status,'blocked');assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.autoWriteOta,false);assert.equal(x.canOpenTarget,false);assert.equal(x.createCandidateCount,null);assert.match(x.reasonText,/只读.*不一致/);assert.doesNotMatch(x.reasonText,/合成矛盾自动写入声明/);
});
test('explicit non-readonly, non-advisory or no-human-review flags violate this preflight contract',()=>{
 for(const field of ['read_only','advisory_only','manual_review_required']){const x=build({[field]:false});assert.equal(x.status,'blocked',field);assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.canOpenTarget,false);assert.match(x.reasonText,/合同.*不一致/);}
});
test('malformed explicit authorization metadata stays unverified rather than defaulting healthy',()=>{
 for(const field of ['read_only','advisory_only','manual_review_required','auto_write_ota'])for(const value of [null,'true','false',0,1]){const x=build({[field]:value});assert.equal(x.canGeneratePendingSuggestions,false,`${field}: ${value}`);assert.equal(x.autoWriteOta,false);assert.equal(x.createCandidateCount,null);}
});
test('the actual server readonly and manual-review contract stays ready',()=>{
 const x=build({read_only:true,advisory_only:true,manual_review_required:true,auto_write_ota:false});assert.equal(x.canGeneratePendingSuggestions,true);assert.equal(x.readOnly,true);assert.equal(x.advisoryOnly,true);assert.equal(x.autoWriteOta,false);
});
test('legacy missing declarations keep their local helper contract without granting OTA authority',()=>{
 const x=build();assert.equal(x.canGeneratePendingSuggestions,true);assert.equal(x.readOnly,true);assert.equal(x.autoWriteOta,false);
});
test('failure and waiting remain higher-priority without exposing contradictory payload content',()=>{
 const failed=build({auto_write_ota:true},{overviewError:'合成当前读取失败'});assert.equal(failed.status,'failed');assert.equal(failed.canGeneratePendingSuggestions,false);assert.match(failed.reasonText,/合成当前读取失败/);assert.doesNotMatch(failed.statusLabel,/前次/);
 const pending=build({read_only:false},{overviewLoading:true});assert.match(pending.statusLabel,/读取中/);assert.equal(pending.canGeneratePendingSuggestions,false);assert.equal(pending.createCandidateCount,null);
});
test('action-specific contract conflicts cannot supply a healthy nested preflight',()=>{
 const x=h.buildRevenueAiActionRows({overview:{actions:[{key:'pricing',pricing_generation_preflight:{...base,auto_write_ota:true}}]}})[0].pricingGenerationPreflightSummary;assert.equal(x.status,'blocked');assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.autoWriteOta,false);
});
