import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
const {ref,watch,effectScope}=createRequire(new URL('../../package.json',import.meta.url))('vue');
const source=fs.readFileSync(process.env.SUXIOS_PUBLIC_EVIDENCE_SOURCE||new URL('../../public/app-main.js',import.meta.url),'utf8');
const slice=(start,end,optional=false)=>{const a=source.indexOf(start);if(a<0&&optional)return '';const b=source.indexOf(end,a);assert.ok(a>=0&&b>a,start);return source.slice(a,b);};
const reader=slice('const loadOtaPublicPageDiagnosis =','const openOtaPublicPageDiagnosisExecutionIntent =');
const watcher=slice('const invalidateOtaPublicPageEvidence =',source.includes('const invalidateCtripPublicProfileScope =')?'const invalidateCtripPublicProfileScope =':'watch(platformHotelContext, clearPlatformHotelSearch);',true);
function fixture(t){
 const pending=[],notices=[],applied=[],effects=effectScope();
 const state={selectedCtripHotelId:ref('10'),otaPublicPageDiagnosisFilter:ref({platform:'meituan',business_date:'2026-09-20'}),
  otaPublicPageEvidenceSaving:ref(false),otaPublicPageDiagnosisLoading:ref(false),otaPublicPageDiagnosisPayload:ref(null),otaPublicPageDiagnosisExecutionLoading:ref(false),
  otaPublicPageDiagnosisExecutionIntent:ref(null),otaPublicPageDiagnosisOperationSurfaceAccessible:ref(false),otaPublicPageDiagnosisOperationSurfaceStatus:ref(''),
  otaPublicPageDiagnosisTaskBridge:ref({}),otaPublicPageDiagnosisError:ref(''),user:ref({id:3}),authContext:ref({tenantId:1}),permission:ref(true),session:1};
 const sandbox={...state,watch,captureAuthSession:()=>state.session,isAuthSessionCurrent:s=>s===state.session,canMaintainOtaConfig:()=>state.permission.value,
  showToast:(...args)=>notices.push(args),applyOtaPublicPageDiagnosisTaskBridge:(bridge,scope)=>{applied.push({bridge,scope});return true;},
  request:(url,options)=>new Promise((resolve,reject)=>pending.push({url,options,resolve,reject}))};
 vm.createContext(sandbox);effects.run(()=>vm.runInContext(`let otaPublicPageDiagnosisRequestSeq=0,otaPublicPageEvidenceSeq=0,otaPublicPageDiagnosisExecutionRequestSeq=0;${watcher}${reader};globalThis.load=loadOtaPublicPageDiagnosis;`,sandbox));t.after(()=>effects.stop());
 return {state,pending,notices,applied,load:sandbox.load};
}
const success={code:200,data:{dimensions:[],status:'insufficient_evidence',task_bridge:{state:'no_intent'}}};
test('equivalent account and filter object replacements preserve the current diagnosis read',async t=>{
 const p=fixture(t),reading=p.load();p.state.authContext.value={...p.state.authContext.value};p.state.user.value={...p.state.user.value};
 p.state.otaPublicPageDiagnosisFilter.value={...p.state.otaPublicPageDiagnosisFilter.value};p.pending[0].resolve(success);
 assert.equal((await reading)?.status,'insufficient_evidence');assert.equal(p.applied.length,1);
});
const changes={date:p=>{p.state.otaPublicPageDiagnosisFilter.value.business_date='2026-09-21';},platform:p=>{p.state.otaPublicPageDiagnosisFilter.value.platform='ctrip';},
 hotel:p=>{p.state.selectedCtripHotelId.value='20';},session:p=>{p.state.session++;},tenant:p=>{p.state.authContext.value.tenantId=2;},user:p=>{p.state.user.value.id=4;},
 aba:p=>{p.state.otaPublicPageDiagnosisFilter.value.business_date='2026-09-21';p.state.otaPublicPageDiagnosisFilter.value.business_date='2026-09-20';}};
for(const outcome of ['success','failure'])test(`old diagnosis ${outcome} cannot change current view after scope or session changes`,async t=>{
 for(const [name,change] of Object.entries(changes)){
  const p=fixture(t),reading=p.load();change(p);p.state.otaPublicPageDiagnosisPayload.value={current:name};p.state.otaPublicPageDiagnosisError.value='当前提示';
  if(outcome==='success')p.pending[0].resolve(success);else p.pending[0].reject(Error('旧请求错误'));
  assert.equal(await reading,null,name);assert.equal(p.state.otaPublicPageDiagnosisPayload.value.current,name,name);assert.equal(p.state.otaPublicPageDiagnosisError.value,'当前提示',name);
  assert.equal(p.applied.length,0,name);assert.equal(p.notices.length,0,name);assert.equal(p.state.otaPublicPageDiagnosisLoading.value,false,name);
 }
});
test('new diagnosis owns its loading state and old completion cannot clear it',async t=>{
 const p=fixture(t),old=p.load();p.state.selectedCtripHotelId.value='20';const current=p.load();p.pending[0].reject(Error('旧错误'));
 await old;assert.equal(p.state.otaPublicPageDiagnosisLoading.value,true);assert.equal(p.state.otaPublicPageDiagnosisError.value,'');
 p.pending[1].resolve(success);await current;assert.equal(p.state.otaPublicPageDiagnosisLoading.value,false);assert.equal(p.applied[0].scope.systemHotelId,'20');
});
test('current diagnosis failure clears facts and retry reads the exact selected channel/date',async t=>{
 const p=fixture(t);p.state.otaPublicPageDiagnosisPayload.value={old:true};const failed=p.load();p.pending[0].reject(Error('合成读取失败'));await failed;
 assert.equal(p.state.otaPublicPageDiagnosisPayload.value,null);assert.equal(p.state.otaPublicPageDiagnosisError.value,'合成读取失败');assert.equal(p.state.otaPublicPageDiagnosisTaskBridge.value.readback_status,'readback_mismatch');
 const retried=p.load();p.pending[1].resolve(success);await retried;
 assert.equal(p.state.otaPublicPageDiagnosisError.value,'');assert.equal(p.state.otaPublicPageDiagnosisLoading.value,false);
 assert.equal(p.pending[1].url,'/online-data/public-page-diagnosis?system_hotel_id=10&platform=meituan&business_date=2026-09-20');
 assert.equal(p.applied[0].scope.platform,'meituan');assert.equal(p.applied[0].scope.businessDate,'2026-09-20');
});
