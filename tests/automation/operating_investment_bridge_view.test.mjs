import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import {compile, parse} from '@vue/compiler-dom';
import {renderToString} from '@vue/server-renderer';
const context={window:{}};
vm.runInNewContext(fs.readFileSync('public/components/system/operating-finance-control-center.js','utf8'),context);
const component=context.window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody;
function find(nodes){for(const node of nodes){if(node.type===1 && node.props.some(p=>p.name==='data-testid' && p.value?.content==='operating-investment-bridge'))return node;const nested=node.children && find(node.children);if(nested)return nested;}}
const panel=find(parse(component.template).children);
const render=new Function('Vue', compile(panel.loc.source,{mode:'function',prefixIdentifiers:true}).code)(Vue);
const sample={contract_version:'investment_operating_bridge.v1',tenant_id:2,hotel_id:7,period_month:'2026-09',effective_as_of:'2026-09-30',status:'partial',projects:[{project_id:11,project_name:'合成项目',investor_name:'合成投资人',scope_compatible:true,amounts:{actual_invested:1000,net_actual_recovered:0,unrecovered:null}}]};
async function html(bridge,extra={}) {
 return renderToString(Vue.createSSRApp({data:()=>({hotels:[{id:7,tenant_id:2}],hotelId:'7',periodMonth:'2026-09',activeTab:'finance',loading:false,overview:{investment_bridge:bridge},...extra}),computed:{currentInvestmentBridge:component.computed.currentInvestmentBridge},methods:{money:component.methods.money,statusText:component.methods.statusText},render}));
}
test('read-only bridge shows cumulative manual cash, explicit zero and missing amount separately',async()=>{
 const output=await html(sample); assert.match(output,/合成项目/);assert.match(output,/¥1,000/);assert.match(output,/¥0/);assert.match(output,/未取得/);assert.match(output,/未经独立核验/);assert.match(output,/经营利润和测算不能当作投资人回款/);assert.doesNotMatch(output,/<button|<input/);
});
for(const change of [{hotel_id:8},{tenant_id:3},{period_month:'2026-08'},{hotel_id:true},{contract_version:'unexpected'}])test(`foreign or invalid bridge scope ${JSON.stringify(change)} hides its projects`,async()=>{
 const output=await html({...sample,...change});assert.doesNotMatch(output,/合成项目|¥1,000/);assert.match(output,/未取得当前酒店及账期/);
});
test('blocked and absent bridge remains missing instead of zero cash success',async()=>{
 for(const bridge of [null,{...sample,status:'blocked',projects:null}]){const output=await html(bridge);assert.doesNotMatch(output,/¥0/);assert.match(output,/未取得|尚不可展示/);}
});
test('incompatible currency, basis or cutoff cannot display amounts as CNY cash',async()=>{
 const output=await html({...sample,projects:[{...sample.projects[0],scope_compatible:false}]});assert.doesNotMatch(output,/¥1,000|¥0/);assert.match(output,/资金口径、币种或截止日待核/);assert.match(output,/未取得/);
});
test('canonical decimal cash retains every cent beyond Number precision, including negative recovery and true zero',async()=>{
 const output=await html({...sample,projects:[{...sample.projects[0],amounts:{actual_invested:'90071992547409.91',net_actual_recovered:'-90071992547409.93',unrecovered:'0.00'}}]});
 assert.match(output,/¥90,071,992,547,409\.91/);assert.match(output,/¥-90,071,992,547,409\.93/);assert.match(output,/¥0\.00/);assert.doesNotMatch(output,/¥90,071,992,547,409\.9</);
});
test('ordinary unit costs keep two-place rounding while missing cash stays missing',async()=>{
 const output=await html({...sample,projects:[{...sample.projects[0],amounts:{actual_invested:'12.345',net_actual_recovered:'-1.239',unrecovered:null}}]});
 assert.match(output,/¥12\.35/);assert.match(output,/¥-1\.24/);assert.match(output,/未取得/);assert.doesNotMatch(output,/¥12\.34|¥0/);
});
test('changing the selected hotel or period hides a still-loaded previous bridge',async()=>{
 for(const extra of [{hotelId:'8'},{periodMonth:'2026-10'},{hotels:[{id:7,tenant_id:3}]}]){
  const output=await html(sample,extra);assert.doesNotMatch(output,/合成项目|¥1,000/);assert.match(output,/未取得当前酒店及账期/);
 }
});
