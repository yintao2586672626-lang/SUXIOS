import test from 'node:test';import assert from 'node:assert/strict';import fs from 'node:fs';import vm from 'node:vm';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{parse,compile}=require('@vue/compiler-dom'),Vue=require('vue');
const template=fs.readFileSync(new URL('../../resources/frontend/templates/fragments/17-page-ops-track.html',import.meta.url),'utf8');
const ctx={window:{}};
vm.runInNewContext(fs.readFileSync(new URL('../../public/operation-static.js',import.meta.url),'utf8'),ctx);const op=ctx.window.SUXI_OPERATION_STATIC;
const find=(nodes,id,result=[])=>{for(const n of nodes){if(n.type===1&&n.props.some(p=>p.type===6&&p.name==='data-testid'&&p.value?.content===id))result.push(n);if(n.children)find(n.children,id,result)}return result};
const nodes=find(parse(template).children,'operation-refresh-readback');
const render=n=>new Function('Vue',compile(n.loc.source,{mode:'function'}).code)(Vue);
const item=(reported='success')=>({id:4,execution:{task_id:1,status:'executed'},review:{status:'unverified',reported_status:reported,is_available:true},recommendation:{source_module:'revenue_cockpit_action'},evidence_truth:{source_verified:false},next_action:{key:'refresh_readback'},read_state:{complete:false}});
test('original mobile and desktop offer readonly refresh even when dependent readback is incomplete',()=>{
 assert.equal(nodes.length,2);for(const n of nodes){let received;const r=render(n)({item:item(),operationLoading:{actions:false},loadOperationActions:v=>{received=v}},[]);
 assert.equal(r.type,'button');assert.equal(r.props.disabled,false);assert.equal(r.children,'刷新核验结果');r.props.onClick();assert.deepEqual(received,{preserveFocus:true});assert.match(r.props.class,/min-h-\[44px\]/);assert.match(r.props.title,/缺少的核验依据/);
 }
});
test('refresh is disabled while original loader is reading and absent for normal results',()=>{
 for(const n of nodes){assert.equal(render(n)({item:item(),operationLoading:{actions:true},loadOperationActions:()=>{}},[]).props.disabled,true);
 assert.equal(typeof render(n)({item:{next_action:{key:'none'}},operationLoading:{actions:false},loadOperationActions:()=>{}},[]).type,'symbol');}
});
test('terminal saved status still closes original review and source readback write controls',()=>{
 for(const s of ['success','near_success','failed']){const i=item(s);assert.equal(op.operationCanReviewExecution(i),false);assert.equal(op.operationCanReconcileExecution(i),false)}
 const i=item('observing');assert.equal(op.operationCanReviewExecution(i),true);assert.equal(op.operationCanReconcileExecution(i),true);
});
