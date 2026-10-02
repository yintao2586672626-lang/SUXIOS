import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
const component=fs.readFileSync(process.env.MEDIA_COMPONENT_SOURCE||'public/components/system/operating-intelligence-components.js','utf8');
const row=(changes={})=>({id:701,hotel_id:7,created_by:17,original_name:'synthetic.wav',extraction_status:'ready',...changes});
const find=(node,id)=>!node||typeof node!=='object'?null:node.props?.['data-testid']===id?node:(Array.isArray(node.children)?node.children:[node.children]).map(child=>find(child,id)).find(Boolean)||null;
const text=node=>typeof node==='string'?node:!node||typeof node!=='object'?'':(Array.isArray(node.children)?node.children:[node.children]).map(text).join(' ');
function harness(record,userId=17){
  const state={value:{media_history:[record],media_result:record,media_selected_ids:[],media_error:''}},user={value:{id:userId}},form={value:{hotel_id:7}};
  const ui={state,user,form,ensureScope:()=>Number(form.value.hotel_id),request:async()=>{throw new Error('render/select must not request');}};
  const deps={ref:value=>({value}),computed:fn=>({get value(){return fn();}}),inject:()=>ui,h:(type,props,children)=>({type,props,children}),nextTick:async()=>{},onMounted:()=>{},onUnmounted:()=>{}};
  const sandbox={window:{},console};
  vm.runInNewContext(fs.readFileSync('public/components/system/hotel-data-analyst-components.js','utf8'),sandbox);
  vm.runInNewContext(component,sandbox);
  const render=sandbox.window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL.create(deps).operatingQuestionPanel.setup();
  return{state,user,form,render,node:id=>find(render(),id)};
}
for(const status of ['ready','partial'])test(`own ${status} media remains explicitly selectable from history and exact result`,()=>{
  const h=harness(row({extraction_status:status}));
  assert.deepEqual(h.state.value.media_selected_ids,[]);
  for(const id of ['local-media-evidence-701','local-media-use-in-question']){
    const button=h.node(id);assert.equal(button.props.disabled,false);button.props.onClick();
    assert.deepEqual(Array.from(h.state.value.media_selected_ids),[701]);button.props.onClick();assert.deepEqual(Array.from(h.state.value.media_selected_ids),[]);
  }
});
for(const [label,record,userId,reason] of [
  ['other owner',row({created_by:18}),17,'仅提取者本人'],
  ['missing owner',row({created_by:undefined}),17,'仅提取者本人'],
  ['missing account',row(),undefined,'当前账号未确认'],
  ['zero account',row(),0,'当前账号未确认'],
  ['failed extraction',row({extraction_status:'failed'}),17,'提取未就绪'],
  ['blocked extraction',row({extraction_status:'blocked_not_configured'}),17,'提取未就绪'],
  ['different hotel',row({hotel_id:8}),17,'当前门店'],
])test(`${label} is visibly unavailable and click cannot claim evidence binding`,()=>{
  const h=harness(record,userId);if(label==='missing account')h.user.value={};
  for(const id of ['local-media-evidence-701','local-media-use-in-question']){
    const button=h.node(id);assert.equal(button.props.disabled,true);button.props.onClick();assert.deepEqual(Array.from(h.state.value.media_selected_ids),[]);
  }
  assert.ok(text(h.render()).includes(reason));
});
test('a rendered button rechecks the live account when clicked after account replacement',()=>{
  const h=harness(row()),click=h.node('local-media-evidence-701').props.onClick;
  h.user.value={id:18};click();assert.deepEqual(Array.from(h.state.value.media_selected_ids),[]);assert.equal(h.node('local-media-evidence-701').props.disabled,true);
});
test('legacy numeric-string identity is matched without upgrading missing ownership',()=>{
  const h=harness(row({created_by:'17'}),'17');assert.equal(h.node('local-media-evidence-701').props.disabled,false);
  h.node('local-media-evidence-701').props.onClick();assert.deepEqual(Array.from(h.state.value.media_selected_ids),[701]);
});
test('the original provided question UI carries the live user ref across authentication replacement',()=>{
  const main=fs.readFileSync(process.env.MEDIA_MAIN_SOURCE||'public/app-main.js','utf8');
  const match=main.match(/provide\('operatingQuestionUi', \{([\s\S]*?)\n            \}\);/);assert.ok(match);
  const names=[...new Set(match[1].split('\n').map(line=>line.trim().replace(/,$/,'')).filter(Boolean).flatMap(line=>line==='user'?['user']:line.includes(':')?line.endsWith('authSessionEpoch')?['authSessionEpoch']:[line.split(':').at(-1).trim()]:[line]))];
  const args=Object.fromEntries(names.map(name=>[name,name==='user'?{value:{id:17}}:name==='authSessionEpoch'?1:()=>{}]));
  const ui=new Function(...names,'return ({'+match[1]+'});')(...Object.values(args));
  assert.ok(ui.user,'missing user ref');assert.equal(ui.user.value.id,17);args.user.value={id:18};assert.equal(ui.user.value.id,18);
});
test('question submission preserves explicit selection order and rejects invalid IDs within its ten-record limit',()=>{
  const main=fs.readFileSync('public/app-main.js','utf8'),start=main.indexOf('const mediaEvidenceIds =');
  const body=main.slice(start,main.indexOf('operatingQuestionHistoryOpenRequestId +=',start));
  const normalize=new Function('state',body+';return mediaEvidenceIds;');
  assert.deepEqual(normalize({media_selected_ids:['702',701,'702',null,undefined,'',false,NaN,Infinity,-1,0,1.2,'bad',...Array.from({length:14},(_,i)=>800+i)]}),[702,701,800,801,802,803,804,805,806,807]);
  assert.deepEqual(normalize({media_selected_ids:null}),[]);
});
