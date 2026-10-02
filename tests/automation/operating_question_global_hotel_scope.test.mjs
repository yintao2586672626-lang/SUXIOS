import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {test} from 'node:test';
import * as Vue from 'vue';
import {compile} from '@vue/compiler-dom';
const main=fs.readFileSync(process.env.QUESTION_GLOBAL_SOURCE||'public/app-main.js','utf8');
const system=fs.readFileSync('public/system-static.js','utf8');
const cut=(text,start,end)=>{const a=text.indexOf(start),b=text.indexOf(end,a);assert.ok(a>=0&&b>a,start);return text.slice(a,b);};
const createState=new Function(cut(system,'const createOperatingQuestionState =','const operatingQuestionScopeCooldown =')+';return createOperatingQuestionState;')();
const update=cut(main,'const updateOperatingQuestionScope =','            watch(');
const scope=cut(main,'const ensureOperatingQuestionScope =','const operatingQuestionSuggestions =');
const history=cut(main,'const invalidateOperatingQuestionHistory =','const loadOperatingQuestionHistory =');
const options=cut(main.slice(main.indexOf(update)+update.length),'            watch(','const askOperatingQuestion =');
const template=fs.readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html','utf8');
const select=template.slice(template.indexOf('<select '),template.indexOf('</select>')+9);
const render=new Function('Vue',compile(select,{mode:'function',prefixIdentifiers:true}).code)(Vue);
function harness(active=false){
 const state=Vue.ref(createState()),form=Vue.ref({hotel_id:'7',platform:'ctrip',date_start:'2026-09-23',date_end:'2026-09-23'}),filter=Vue.ref('7'),calls=[];
 const ctx={...Vue,operatingQuestionState:state,operatingQuestionForm:form,filterReportHotel:filter,currentPage:Vue.ref(active?'agent-center':'compass'),agentTab:Vue.ref('overview'),user:Vue.ref({id:17,hotel_id:7}),otaDiagnosisHotelOptions:Vue.ref([{value:'7',label:'甲店'},{value:'8',label:'乙店'}]),reportHotelOptionExists:id=>['7','8'].includes(String(id)),operatingQuestionScopeCooldown:()=>{},loadOperatingQuestionScopeOptions:async opts=>calls.push({kind:'scope',hotel:form.value.hotel_id,opts}),loadOperatingQuestionHistory:async opts=>calls.push({kind:'history',hotel:form.value.hotel_id,opts})};
 // Keep the real invalidate function and both real watchers; replace transport only.
 const effect=Vue.effectScope();effect.run(()=>vm.runInNewContext(`let operatingQuestionHistoryRequestId=0,operatingQuestionHistoryOpenRequestId=0,operatingQuestionScopeRequestId=0;${history}${update}${scope}${options}globalThis.changeScope=updateOperatingQuestionScope;`,ctx));
 const seed=()=>Object.assign(state.value,{media_file:{name:'甲店参考.png'},media_result:{id:701,hotel_id:7},media_history:[{id:701,hotel_id:7}],media_selected_ids:[701],media_error:'甲店提取错误',council_run:{id:7001},council_error:'甲店会诊错误',wecom_binding_code:{hotel_id:7},wecom_bindings:[{hotel_id:7}],wecom_events:[{hotel_id:7}],wecom_error:'甲店绑定错误',result:{id:99,hotel_id:7},action_intents:{old:1},history:[{id:99,hotel_id:7}],history_loaded_hotel_id:'7'});
 const change=async value=>{const node=render(Vue.proxyRefs({filterReportHotel:filter,compassHotelOptions:Vue.ref([{id:'7',name:'甲店'},{id:'8',name:'乙店'}])}),[]);node.props['onUpdate:modelValue'](value);await Vue.nextTick();};
 return {state,form,calls,seed,change,ctx,stop:()=>effect.stop()};
}
for(const active of [false,true])test(`original home selector clears old hotel reference state with panel ${active?'active':'inactive'}`,async()=>{
 const h=harness(active);try{
  await Vue.nextTick();h.calls.length=0;h.seed();await h.change('8');
  assert.equal(h.form.value.hotel_id,'8');
  for(const key of ['media_file','media_result','council_run','wecom_binding_code','result'])assert.equal(h.state.value[key],null,key);
  for(const key of ['media_history','media_selected_ids','wecom_bindings','wecom_events','history'])assert.equal(h.state.value[key].length,0,key);
  for(const key of ['media_error','council_error','wecom_error'])assert.equal(h.state.value[key],'',key);
  assert.equal(h.state.value.history_loaded_hotel_id,'');assert.equal(Object.keys(h.state.value.action_intents).length,0);
  if(!active)assert.equal(h.calls.length,0,'inactive question panel does not trigger extra reads');
  else assert.ok(h.calls.length>0&&h.calls.every(call=>call.hotel==='8'));
  await h.change('7');assert.equal(h.state.value.media_selected_ids.length,0,'returning to A does not reselect old media');
 }finally{h.stop();}
});
test('unchanged, unselected and unauthorized global hotel values do not erase current question scope',async()=>{
 const h=harness();try{await Vue.nextTick();h.seed();for(const value of ['7','','999']){await h.change(value);assert.equal(h.form.value.hotel_id,'7');assert.deepEqual([...h.state.value.media_selected_ids],[701]);}}finally{h.stop();}
});
test('busy primary question retains its original hotel until the current submission completes',async()=>{
 const h=harness();try{await Vue.nextTick();h.seed();h.state.value.loading=true;await h.change('8');assert.equal(h.form.value.hotel_id,'7');assert.deepEqual([...h.state.value.media_selected_ids],[701]);assert.equal(h.state.value.loading,true);}finally{h.stop();}
});
test('dedicated panel scope change still clears references and loads its new scope',async()=>{
 const h=harness(true);try{await Vue.nextTick();h.seed();h.calls.length=0;h.ctx.changeScope('hotel_id','8');assert.equal(h.state.value.media_selected_ids.length,0);assert.deepEqual(h.calls.map(call=>call.kind),['scope','history']);assert.ok(h.calls.every(call=>call.hotel==='8'));}finally{h.stop();}
});
