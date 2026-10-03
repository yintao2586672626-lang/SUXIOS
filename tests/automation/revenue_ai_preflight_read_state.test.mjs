import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {ref,computed} from 'vue';
const context={window:{},URLSearchParams};
for(const name of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${name}`,'utf8'),context);
const helpers=context.window.SUXI_REVENUE_AI_STATIC,build=helpers.buildRevenueAiPricingGenerationPreflightSummary;
const preflight={status:'ready_for_manual_generation',reason:'pricing_generation_candidates_ready',target_hotel_ids:[64],target_date_rows:2,room_type_count:1,create_candidate_count:3,pending_suggestion_count:0,can_generate_pending_suggestions:true,target_page:'agent',target_agent_tab:'pricing'};
const overview={pricing_generation_preflight:preflight,actions:[{key:'pricing',status:'ready',pricing_generation_preflight:preflight}]};
test('failed refresh hides prior preflight conclusions and keeps current counts unknown',()=>{
 const x=build({overview,overviewError:'合成总览失败'});assert.equal(x.visible,true);assert.equal(x.status,'failed');assert.match(x.statusLabel,/失败/);assert.equal(x.canGeneratePendingSuggestions,false);assert.equal(x.createCandidateCount,null);assert.match(x.reasonText,/合成总览失败/);assert.match(x.detailText,/合成总览失败/);assert.equal(x.canOpenTarget,false);
});
test('waiting for a retry hides prior readiness and keeps current counts unknown',()=>{
 const x=build({overview,overviewLoading:true});assert.match(x.statusLabel,/读取中/);assert.equal(x.canGeneratePendingSuggestions,false);assert.match(x.reasonText,/尚未|未确认/);assert.equal(x.createCandidateCount,null);
});
test('first failed read is visible and leaves every missing summary count unknown',()=>{
 const x=build({overview:null,overviewError:'合成首次失败'});assert.equal(x.visible,true);assert.equal(x.status,'failed');assert.equal(x.canGeneratePendingSuggestions,false);assert.match(x.reasonText,/合成首次失败/);
 for(const key of ['targetHotelCount','targetDateRows','roomTypeCount','createCandidateCount','pendingSuggestionCount'])assert.equal(x[key],null,key);
 assert.doesNotMatch(x.detailText,/前次/);
});
test('successful recovery restores current valid readiness without changing read-only navigation',()=>{
 const x=build({overview});assert.equal(x.statusLabel,'可生成待审');assert.equal(x.canGeneratePendingSuggestions,true);assert.equal(x.createCandidateCount,3);assert.equal(x.canOpenTarget,true);
});
test('action card summaries receive the same failure and pending read state',()=>{
 const failed=helpers.buildRevenueAiActionRows({overview,overviewError:'合成当前失败'})[0].pricingGenerationPreflightSummary;
 assert.equal(failed.status,'failed');assert.equal(failed.canGeneratePendingSuggestions,false);
 const pending=helpers.buildRevenueAiActionRows({overview,overviewLoading:true})[0].pricingGenerationPreflightSummary;assert.match(pending.statusLabel,/读取中/);assert.equal(pending.canGeneratePendingSuggestions,false);
});
test('actual Agent root computed reacts to error and both loading sources',()=>{
 const source=readFileSync('public/app-main.js','utf8'),begin=source.indexOf('const agentPricingGenerationPreflightSummary = computed('),block=source.slice(begin,source.indexOf('const revenueAiPricingGateRows =',begin));
 const error=ref('合成当前失败'),loading=ref(false),staticLoading=ref(false);
 const x=vm.runInNewContext(`${block};agentPricingGenerationPreflightSummary;`,{computed,revenueAiBuildPricingGenerationPreflightSummary:build,revenueAiOverview:ref(overview),revenueAiOverviewError:error,revenueAiOverviewLoading:loading,revenueAiStaticLoading:staticLoading});
 assert.equal(x.value.canGeneratePendingSuggestions,false);error.value='';loading.value=true;assert.match(x.value.statusLabel,/读取中/);loading.value=false;staticLoading.value=true;assert.match(x.value.statusLabel,/读取中/);staticLoading.value=false;assert.equal(x.value.statusLabel,'可生成待审');
});
test('Agent template does not turn missing preflight counts into zero',()=>{
 const html=readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html','utf8');
 for(const key of ['targetHotelCount','targetDateRows','roomTypeCount','createCandidateCount','pendingSuggestionCount'])assert.doesNotMatch(html,new RegExp(`agentPricingGenerationPreflightSummary\\.${key}\\s*\\|\\|`));
});
test('no request and no prior preflight retain the hidden legacy state',()=>assert.equal(build({overview:null}).visible,false));
