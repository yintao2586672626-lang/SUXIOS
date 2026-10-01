import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const context={window:{},URLSearchParams};
for(const name of ['revenue-overview-contract-static.js','revenue-cockpit-static.js','revenue-ai-static.js'])vm.runInNewContext(readFileSync(`public/${name}`,'utf8'),context);
const build=context.window.SUXI_REVENUE_AI_STATIC.buildRevenueAiSignalRows;
const row=signal=>build({overview:{signals:{pricing_advice:signal}}}).find(x=>x.key==='pricing_advice');
test('missing signals in a received overview stay missing rather than claiming matched facts',()=>{
  for(const overview of [{},{signals:null},{signals:{}}])for(const x of build({overview})){
    assert.equal(x.value,'--');assert.equal(x.statusLabel,'缺失');assert.doesNotMatch(x.reasonText,/数据已命中/);assert.match(x.reasonText,/缺失|未提供|未取得/);
  }
});
test('empty or malformed signal objects remain evidence gaps',()=>{
  for(const signal of [{},null,[],true,'synthetic']){const x=row(signal);assert.equal(x.value,'--');assert.equal(x.statusLabel,'缺失');assert.doesNotMatch(x.reasonText,/数据已命中/);}
});
test('a ready status cannot make absent, malformed or nonfinite values into usable input',()=>{
  for(const value of [null,undefined,'','  ','--',NaN,Infinity,{},[],true]){
    const x=row({status:'ready',value});assert.equal(x.value,'--');assert.equal(x.statusLabel,'缺失');assert.match(x.reasonText,/缺失|未提供|未取得/);
  }
});
test('an unknown status with content remains unverified without a success reason',()=>{
  for(const status of [undefined,'unknown','unverified','new_unrecognized_state']){
    const x=row({status,value:'合成内容'});assert.equal(x.value,'合成内容');assert.notEqual(x.statusLabel,'可作为输入');assert.doesNotMatch(x.reasonText,/数据已命中/);assert.match(x.reasonText,/未确认|未验证|缺口/);
  }
});
test('existing explicit source reasons and details survive missing or blocked signals',()=>{
  const x=row({status:'blocked',value:'--',reason:'phase1a_readonly_no_pricing_model',detail:'合成具体服务端缺口'});
  assert.equal(x.statusLabel,'待补数据');assert.equal(x.reasonText,'合成具体服务端缺口');
  assert.match(row({status:'missing',value:null,reason:'demand_forecasts_not_loaded'}).reasonText,/未|暂无|缺/);
});
test('a contradictory ready response still explains the missing value while keeping source detail',()=>{
  const x=row({status:'ready',value:null,detail:'合成服务端说明'});assert.equal(x.statusLabel,'缺失');assert.match(x.reasonText,/缺失|未提供/);assert.match(x.reasonText,/合成服务端说明/);
});
test('valid zero, finite values and healthy legacy ready content retain their contract',()=>{
  for(const value of [0,2.5,'合成建议']){const x=row({status:'ready',value});assert.equal(x.value,value);assert.equal(x.statusLabel,'可作为输入');assert.equal(x.reasonText,'数据已命中当前口径。');}
});
test('confirmed empty remains empty and has an explicit empty explanation',()=>{
  const x=row({status:'empty_confirmed',value:null});assert.equal(x.statusLabel,'确认无数据');assert.doesNotMatch(x.reasonText,/数据已命中/);assert.match(x.reasonText,/确认无数据/);
});
test('an absent overview remains not loaded rather than an accepted missing receipt',()=>{
  for(const x of build({overview:null})){assert.equal(x.statusLabel,'未加载');assert.match(x.reasonText,/尚未返回/);}
});
