import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import {createRequire} from 'node:module';
const {ref,computed}=createRequire(new URL('../../package.json',import.meta.url))('vue');
const source=fs.readFileSync(process.env.SUXIOS_PUBLIC_EVIDENCE_SOURCE||new URL('../../public/app-main.js',import.meta.url),'utf8');
const slice=(start,end)=>{const a=source.indexOf(start),b=source.indexOf(end,a);assert.ok(a>=0&&b>a);return source.slice(a,b);};
function fixture(){
 const calls=[],state={selectedCtripHotelId:ref('10'),otaPublicPageDiagnosisFilter:ref({platform:'ctrip',business_date:'2026-09-20'}),
  otaPublicPageDiagnosisPayload:ref({system_hotel_id:10,platform:'ctrip',business_date:'2026-09-20',latest_available_date:'2026-09-19'}),
  otaPublicPageEvidenceSaving:ref(false),otaPublicPageDiagnosisLoading:ref(false),otaPublicPageDiagnosisExecutionLoading:ref(false),ctripPublicProfileRefreshing:ref(false),permission:true};
 const sandbox={...state,computed,canMaintainOtaConfig:()=>state.permission,ctripCompetitiveLocalDate:()=> '2026-09-27',
  loadOtaPublicPageDiagnosis:async()=>calls.push({type:'read',hotel:state.selectedCtripHotelId.value,...state.otaPublicPageDiagnosisFilter.value}),
  saveMeituanPublicPageEvidence:async()=>calls.push({type:'form',hotel:state.selectedCtripHotelId.value,...state.otaPublicPageDiagnosisFilter.value}),
  refreshCtripPublicProfiles:async()=>calls.push({type:'refresh'})};
 vm.createContext(sandbox);vm.runInContext(slice('const otaPublicPageDiagnosisLatestDate =','const ctripCompetitiveOperationsPayload =')+slice('const handleOtaPublicPageEvidenceAction =','const loadOtaPublicPageDiagnosis =')+';globalThis.result={action:handleOtaPublicPageEvidenceAction,text:otaPublicPageEvidenceActionText,disabled:otaPublicPageEvidenceActionDisabled,latest:otaPublicPageDiagnosisHasLatestSnapshotAction};',sandbox);
 return {state,calls,...sandbox.result};
}
test('switching Ctrip to Meituan offers entry for the selected date instead of a Ctrip historical read',async()=>{
 const p=fixture();p.state.otaPublicPageDiagnosisFilter.value.platform='meituan';assert.equal(p.text.value,'录入公开证据');await p.action();
 assert.deepEqual(p.calls,[{type:'form',hotel:'10',platform:'meituan',business_date:'2026-09-20'}]);
});
test('latest snapshot suggestions must match hotel, platform and queried date',()=>{
 for(const change of [p=>{p.state.selectedCtripHotelId.value='20';},p=>{p.state.otaPublicPageDiagnosisFilter.value.business_date='2026-09-21';},p=>{p.state.otaPublicPageDiagnosisPayload.value={latest_available_date:'2026-09-19'};},p=>{p.state.otaPublicPageDiagnosisPayload.value=null;}]){
  const p=fixture();change(p);assert.equal(p.latest.value,false);
 }
});
test('same-scope historical suggestions remain available with read-only permission and numeric/string hotel IDs',async()=>{
 const p=fixture();p.state.permission=false;assert.equal(p.latest.value,true);assert.equal(p.disabled.value,false);assert.equal(p.text.value,'查看 2026-09-19');await p.action();
 assert.deepEqual(p.calls,[{type:'read',hotel:'10',platform:'ctrip',business_date:'2026-09-19'}]);assert.equal(p.latest.value,false);
});
test('Meituan history stays reachable after a fresh exact-scope diagnosis, without creating an observation',async()=>{
 const p=fixture();p.state.otaPublicPageDiagnosisFilter.value.platform='meituan';p.state.otaPublicPageDiagnosisPayload.value.platform='meituan';await p.action();
 assert.equal(p.calls[0].type,'read');assert.equal(p.calls[0].platform,'meituan');assert.equal(p.calls[0].business_date,'2026-09-19');
});
test('same-day or missing latest dates keep the normal permission-checked entry',()=>{
 for(const date of ['',null,'2026-09-20']){const p=fixture();p.state.permission=false;p.state.otaPublicPageDiagnosisPayload.value.latest_available_date=date;assert.equal(p.latest.value,false);assert.equal(p.disabled.value,true);}
});
