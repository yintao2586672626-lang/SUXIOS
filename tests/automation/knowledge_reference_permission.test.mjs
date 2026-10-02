import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import {compile, parse} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';
const main=fs.readFileSync('public/app-main.js','utf8');
const between=(start,end)=>{const a=main.indexOf(start),b=main.indexOf(end,a);assert.ok(a>=0&&b>a);return main.slice(a,b);};
const permission=between('            const canWriteKnowledgeReference =','            const userHasCapability =');
const action=between('            const editKnowledgeReference =','            const createKnowledgeSopTask =');
function fixture(profile){const calls=[],notices=[],user=Vue.ref(profile);const context={user,showToast:(...args)=>notices.push(args),callKnowledgeCenterDomain:(...args)=>{calls.push(args);return 'opened';}};
 vm.runInNewContext(permission+action+'\nglobalThis.api={canWriteKnowledgeReference,editKnowledgeReference};',context);return {...context,calls,notices,...context.api};}
test('membership, read permission and governance permission without module entitlement cannot start reference write',()=>{
 for(const profile of [{},{permissions:{'ai.view':true}},{permissions:{can_manage_ai_governance:true}},{protected_access:[{key:'ai_governance',allowed:false}]}]){
  const h=fixture(profile);assert.equal(h.canWriteKnowledgeReference(),false);assert.equal(h.editKnowledgeReference(),null);assert.equal(h.calls.length,0);assert.match(h.notices[0][0],/需开通知识管理权限/);
 }
});
test('authorized governance manager and super-admin retain reference entry; current permission loss stops next open',()=>{
 for(const profile of [{protected_access:[{key:'ai_governance',allowed:true}]},{is_super_admin:true}]){
  const h=fixture(profile);assert.equal(h.editKnowledgeReference('source'), 'opened');assert.equal(h.calls[0][0],'editKnowledgeReference');
  h.user.value={protected_access:[{key:'ai_governance',allowed:false}]};assert.equal(h.editKnowledgeReference(),null);assert.equal(h.calls.length,1);
 }
});
const template=fs.readFileSync('resources/frontend/templates/fragments/20-page-knowledge-center.html','utf8');
const ast=parse(template);const find=(nodes,id)=>{for(const n of nodes){if(n.type===1&&n.props?.some(p=>p.type===6&&p.name==='data-testid'&&p.value?.content===id))return n;const hit=n.children&&find(n.children,id);if(hit)return hit;}return null;};
const button=find(ast.children,'knowledge-reference-merge');assert.ok(button);
test('actual reference merge button reflects permission and selection independently',async()=>{
 const render=new Function('Vue',compile(button.loc.source,{mode:'function',prefixIdentifiers:true}).code)(Vue);
 for(const [allowed,ids,disabled] of [[false,[10],true],[true,[],true],[true,[10],false]]){
  const html=await renderToString(Vue.createSSRApp({data:()=>({selectedKnowledgeCenterUnitIds:ids}),methods:{canWriteKnowledgeReference:()=>allowed,editKnowledgeReference(){}},render}));
  assert.equal(/\sdisabled(?:=|\s|>)/.test(html),disabled,html);
 }
});
