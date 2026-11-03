import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {ref,computed} from 'vue';
const helperContext={window:{},URLSearchParams};
for(const file of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${file}`,'utf8'),helperContext);
const build=helperContext.window.SUXI_REVENUE_AI_STATIC.buildRevenueAiSignalRows;
const overview={signals:{pricing_advice:{status:'ready',value:'合成前次建议'},demand_7d:{status:'ready',value:'合成前次需求'}}};
const signal=rows=>rows.find(row=>row.key==='pricing_advice');

test('a failed read preserves the previous advice but stops claiming fresh input',()=>{
  const row=signal(build({overview,overviewError:'合成当前接口失败'}));
  assert.equal(row.value,'合成前次建议');assert.match(row.statusLabel,/失败|前次|未确认/);assert.doesNotMatch(row.reasonText,/数据已命中当前口径/);assert.match(row.reasonText,/合成当前接口失败|前次|未取得/);
});
test('every signal exposes the current read failure, including prior gaps',()=>{
  const rows=build({overview,overviewError:'合成错范围回执'});
  assert.equal(rows.length,6);for(const row of rows){assert.match(row.statusLabel,/失败|前次|未确认/);assert.match(row.reasonText,/合成错范围回执|前次|未取得/);}
});
test('a failed first read stays missing without inventing previous content',()=>{
  const row=signal(build({overview:null,overviewError:'合成首次失败'}));
  assert.equal(row.value,'--');assert.match(row.statusLabel,/失败/);assert.match(row.reasonText,/合成首次失败/);
});
test('a successful retry restores the normal same-scope signal',()=>{
  const failed=signal(build({overview,overviewError:'合成失败'}));
  const restored=signal(build({overview,overviewError:''}));
  assert.notEqual(failed.statusLabel,restored.statusLabel);assert.equal(restored.statusLabel,'可作为输入');assert.equal(restored.reasonText,'数据已命中当前口径。');
});
test('the actual root computed forwards the current error and updates after recovery',()=>{
  const source=readFileSync('public/app-main.js','utf8');
  const begin=source.indexOf('const revenueAiSignalRows = computed(');
  const block=source.slice(begin,source.indexOf('const revenueAiActionRows =',begin));
  const view=ref(overview),error=ref('合成当前接口失败'),loading=ref(false),staticLoading=ref(false);
  const state=vm.runInNewContext(`${block};revenueAiSignalRows;`,{computed,revenueAiBuildSignalRows:build,revenueAiOverview:view,revenueAiOverviewError:error,revenueAiOverviewLoading:loading,revenueAiStaticLoading:staticLoading});
  assert.match(signal(state.value).statusLabel,/失败|前次|未确认/);error.value='';assert.equal(signal(state.value).statusLabel,'可作为输入');
  loading.value=true;assert.match(signal(state.value).statusLabel,/读取中/);loading.value=false;staticLoading.value=true;assert.match(signal(state.value).statusLabel,/读取中/);
});
test('legacy callers with no error retain the existing signal contract',()=>{
  const row=signal(build({overview}));assert.equal(row.value,'合成前次建议');assert.equal(row.statusLabel,'可作为输入');
});
test('a real zero is retained and missing prior values stay missing after failure',()=>{
  const rows=build({overview:{signals:{pricing_advice:{status:'ready',value:0}}},overviewError:'合成读取失败'});
  assert.equal(signal(rows).value,0);assert.match(signal(rows).statusLabel,/前次/);
  const absent=rows.find(row=>row.key==='holiday_event');assert.equal(absent.value,'--');assert.doesNotMatch(absent.reasonText,/保留前次/);
});
test('retrying keeps previous content unconfirmed until the new receipt is accepted',()=>{
  const row=signal(build({overview,overviewError:'',overviewLoading:true}));
  assert.equal(row.value,'合成前次建议');assert.match(row.statusLabel,/前次.*读取中/);assert.doesNotMatch(row.reasonText,/数据已命中当前口径/);assert.match(row.reasonText,/未确认|尚未/);
  const empty=signal(build({overview:null,overviewLoading:true}));assert.equal(empty.value,'--');assert.match(empty.statusLabel,/读取中/);assert.doesNotMatch(empty.reasonText,/保留前次/);
});
