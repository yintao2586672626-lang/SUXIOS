import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';import test from 'node:test';
const source=fs.readFileSync('public/components/system/knowledge-center-domain.js','utf8');
const domain=source.slice(source.indexOf('        const referenceDrafts ='),source.indexOf('        let knowledgeSopCandidateRequestEpoch ='));
const ref=value=>({value});const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};
function fixture(){const calls=[],pending=deferred();const s={Map,JSON,Number,String,Object,Set,Array,knowledgeCenterListRequestEpoch:1,
 currentPage:ref('knowledge-center'),knowledgeCenterSelectedUnit:ref({unit_id:10,hotel_id:7,name:'Synthetic'}),knowledgeCenterFilter:ref({hotel_id:7,keyword:''}),selectedKnowledgeCenterUnitIds:ref([10]),
 captureAuthSession:()=>1,isAuthSessionCurrent:value=>value===1,defaultKnowledgeCenterHotelId:()=>7,normalizeKnowledgeChunkContent:()=>({}),showToast(){},
 request:async url=>{calls.push(url);return pending.promise},openWorkflowFormDialog:async()=>{calls.push('dialog');return null}};
vm.runInNewContext(domain+'\nglobalThis.edit=editKnowledgeReference;',s);return {s,calls,pending};}
for(const change of ['hotel','page','selection'])test('old reference source read cannot open a dialog after '+change+' changes',async()=>{
 const {s,calls,pending}=fixture();const work=s.edit();
 if(change==='hotel')s.knowledgeCenterFilter.value.hotel_id=8;
 if(change==='page')s.currentPage.value='compass';
 if(change==='selection')s.selectedKnowledgeCenterUnitIds.value=[12];
 pending.resolve({code:200,data:{unit:{current_chunk_id:20},chunks:[{chunk_id:20,lifecycle_status:'active'}]}});await work;
 assert.deepEqual(calls,['/knowledge/10']);
});
