import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const context={window:{},URLSearchParams};
for(const path of ['public/revenue-overview-contract-static.js','public/revenue-cockpit-static.js','public/revenue-ai-static.js']){
 vm.runInNewContext(fs.readFileSync(path,'utf8'),context,{filename:path});
}
const helpers=context.window.SUXI_REVENUE_AI_STATIC;
const main=fs.readFileSync('public/app-main.js','utf8');
const template=fs.readFileSync('resources/frontend/templates/fragments/23c-page-compass-detail.html','utf8');
const prior={business_date:'2026-09-26',metrics:{ota_room_revenue:{display:'¥9,999',status:'verified'}},signals:{demand_7d:{value:'高需求',status:'verified'}}};

test('failed current overview never presents a previous successful amount or demand signal',()=>{
 const cards=helpers.buildRevenueAiMetricCards({overview:prior,overviewError:'本次读取失败'});
 assert.equal(cards.find(card=>card.key==='ota_room_revenue').display,'--');
 const signals=helpers.buildRevenueAiSignalRows({overview:prior,overviewError:'本次读取失败'});
 assert.equal(signals.find(row=>row.key==='demand_7d').value,'--');
 assert.match(main,/revenueAiBuildSignalRows\(\{\s*overview: revenueAiOverview\.value,\s*overviewError: revenueAiOverviewError\.value,\s*overviewLoading:/);
 assert.match(main,/revenueAiOverviewError\.value \? null : revenueAiOverview\.value/);
 assert.match(template,/revenueAiOverviewError \? '读取失败'/);
 assert.match(template,/v-if="!revenueAiOverviewError &amp;&amp; revenueAiOverview\?\.manual_order_imports/);
});
test('a real zero signal remains visible on a successful overview',()=>{
 const signals=helpers.buildRevenueAiSignalRows({overview:{signals:{demand_7d:{value:0,status:'verified'}}}});
 assert.equal(signals.find(row=>row.key==='demand_7d').value,0);
});
