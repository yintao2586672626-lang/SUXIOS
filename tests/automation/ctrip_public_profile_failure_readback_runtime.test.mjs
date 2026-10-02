import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../public/app-main.js',import.meta.url),'utf8');
const a=source.indexOf('const ctripPublicProfilePayload ='),b=source.indexOf('const ctripCompetitiveLocalDate =',a);
assert.ok(a>=0&&b>a);
function harness(){
 const ctx=vm.createContext({ref:value=>({value}),computed:fn=>({get value(){return fn();}})});
 vm.runInContext(source.slice(a,b)+';globalThis.state={profiles:ctripPublicProfiles,filter:ctripPublicProfileQualityFilter,filtered:ctripPublicProfilesFiltered,cards:ctripPublicProfileSummaryCards,attention:ctripPublicProfileNeedsAttention};',ctx);
 return {...ctx.state,card:key=>ctx.state.cards.value.find(card=>card.key===key)?.value};
}
const success=()=>({ota_hotel_id:'1001',role:'self',capture_status:'available',source_validation_status:'source_verified',persistence_readback_verified:true,collected_at:'2026-09-27 09:00:00',last_seen_at:'2026-09-27 10:00:00',fields:{room_count:0}});
test('persisted latest failed attempt makes retained successful profile need attention',()=>{
 const state=harness();
 for(const status of ['collection_failed','failed','error']){
  const profile={...success(),latest_capture_attempt:{capture_status:status,collected_at:'2026-09-27 10:00:00',failure_reason:'http_429'}};
  state.profiles.value=[profile];
  assert.equal(state.attention(profile),true);
  assert.equal(state.card('attention'),1);
  state.filter.value='attention';assert.equal(state.filtered.value.length,1);
  state.filter.value='ready';assert.equal(state.filtered.value.length,0);
  assert.equal(profile.fields.room_count,0);
 }
});
test('retained facts keep their collection time instead of a failed attempt update time',()=>{
 const state=harness();state.profiles.value=[{...success(),latest_capture_attempt:{capture_status:'collection_failed'}}];
 assert.equal(state.card('latest'),'09-27 09:00');
 state.profiles.value[0].collected_at='';
 assert.equal(state.card('latest'),'未记录');
});
test('successful re-read clears attention while missing and legacy source states remain honest',()=>{
 const state=harness(),profile=success();
 state.profiles.value=[profile];state.filter.value='ready';assert.equal(state.filtered.value.length,1);
 assert.equal(state.attention({...profile,latest_capture_attempt:{capture_status:'available'}}),false);
 assert.equal(state.attention({...profile,latest_capture_attempt:null}),false);
 assert.equal(state.attention({...profile,capture_status:'partial'}),true);
 assert.equal(state.attention({...profile,source_validation_status:'unverified'}),true);
 assert.equal(state.attention({...profile,persistence_readback_verified:false}),true);
 assert.equal(state.attention({...profile,capture_status:'collection_failed'}),true);
 state.profiles.value=[{...profile,collected_at:'2026-09-27 11:00:00',last_seen_at:'2026-09-27 11:00:00'}];
 assert.equal(state.card('attention'),0);assert.equal(state.card('latest'),'09-27 11:00');
 state.profiles.value=[{...profile,collected_at:''}];assert.equal(state.card('latest'),profile.last_seen_at.slice(5,16));
});
