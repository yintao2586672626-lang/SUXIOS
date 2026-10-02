import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source=fs.readFileSync('public/app-main.js','utf8');
const start=source.indexOf('const openOperationAlertTask = async');
const end=source.indexOf('const createOperationAlertTask = async',start);
assert.ok(start>=0&&end>start);
const handler=source.slice(start,end);
const visibleRowHelper=source.slice(source.indexOf('const findVisibleOperationIntentRow ='),source.indexOf('let revenueAiOverviewRequestSeq ='));
const alert={id:11,hotel_id:80,task_bridge:{linked:true,intent_id:901}};
function harness(){
 const ref=value=>({value});let loaded=false,visible=true;const calls=[],toasts=[],scrolls=[];
 const env={operationFilters:ref({hotel_id:'1'}),revenueAiExecutionFocus:ref(null),currentPage:ref('ops-insight'),
  loadOperationActions:async options=>{calls.push(options);return loaded;},nextTick:async()=>{},
  showToast:(message,level)=>toasts.push({message,level}),
  document:{querySelectorAll:selector=>visible?[{getClientRects:()=>[{}],scrollIntoView:()=>scrolls.push(selector)}]:[]}};
 const context=vm.createContext(env);vm.runInContext(visibleRowHelper+'\n'+handler+';globalThis.open=openOperationAlertTask;',context);
 return{env,calls,toasts,scrolls,open:context.open,setLoaded:value=>{loaded=value;},setVisible:value=>{visible=value;}};
}
test('linked alert task does not scroll a partial operations result and can retry exact ID',async()=>{
 const h=harness();
 assert.equal(await h.open(alert),false);
 assert.equal(h.calls[0].focusIntentId,901);
 assert.equal(h.scrolls.length,0);
 h.setLoaded(true);
 assert.equal(await h.open(alert),true);
 assert.equal(h.scrolls.length,1);
});
test('linked alert task requires a visible exact row after successful read',async()=>{
 const h=harness();h.setLoaded(true);h.setVisible(false);
 assert.equal(await h.open(alert),false);
 assert.match(h.toasts[0].message,/执行池未返回对应记录/);
});
