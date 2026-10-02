import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync(new URL('../../public/app-main.js',import.meta.url),'utf8');
const start=source.indexOf('const ctripPublicProfileNumber ='),end=source.indexOf('const ctripPublicProfileCoordinates =',start);
assert.ok(start>=0&&end>start);
const own={role:'self',fields:{room_count:48,rating:4.5}};
function harness(self=own){
 const context=vm.createContext({ctripPublicProfileSelf:{value:self},ctripPublicProfileFacilityCount:profile=>profile.fields?.facilities?.length||0});
 vm.runInContext(source.slice(start,end)+';globalThis.metric=ctripPublicProfileMetricNumber;globalThis.delta=ctripPublicProfileDeltaText;',context);
 return context;
}
test('missing or blank profile room count and rating stay incomparable',()=>{
 const {metric,delta}=harness();
 for(const value of [null,undefined,'','  ','\t\n','未获取','--']){
  for(const field of ['room_count','rating']){
   const profile={role:'competitor',fields:{[field]:value}};
   assert.equal(metric(profile,field),null,`${field}: ${String(value)}`);
   assert.equal(delta(profile,field),'不可比');
  }
 }
});
test('invalid non-scalar profile values cannot become observed zero',()=>{
 const {metric}=harness();
 for(const value of [false,true,[],[0],{},Infinity,NaN,'Infinity'])assert.equal(metric({fields:{room_count:value}},'room_count'),null);
});
test('actual zero and numeric legacy strings keep their differences',()=>{
 const {metric,delta}=harness();
 for(const value of [0,'0',' 0 ']){
  assert.equal(metric({fields:{room_count:value}},'room_count'),0);
  assert.equal(delta({role:'competitor',fields:{room_count:value}},'room_count'),'较本店 -48');
 }
 assert.equal(delta({role:'competitor',fields:{rating:'4.8'}},'rating'),'较本店 +0.3');
 assert.equal(delta({role:'competitor',fields:{room_count:'48'}},'room_count'),'与本店相同');
});
test('missing own baseline does not create an apparent improvement',()=>{
 const {delta}=harness({role:'self',fields:{room_count:null,rating:' '}});
 assert.equal(delta({role:'competitor',fields:{room_count:48}},'room_count'),'不可比');
 assert.equal(delta({role:'competitor',fields:{rating:4.8}},'rating'),'不可比');
 assert.equal(harness(null).delta({role:'competitor',fields:{room_count:48}},'room_count'),'缺本店基准');
});
test('independent fields and existing self/facility semantics are retained',()=>{
 const {delta}=harness();
 assert.equal(delta({role:'competitor',fields:{room_count:null,rating:4.5}},'room_count'),'不可比');
 assert.equal(delta({role:'competitor',fields:{room_count:null,rating:4.5}},'rating'),'与本店相同');
 assert.equal(delta(own,'room_count'),'本店基准');
 assert.equal(harness({fields:{facilities:['wifi']}}).delta({fields:{facilities:['wifi','parking']}},'facilities'),'较本店 +1');
});
