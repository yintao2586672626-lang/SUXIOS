import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import {renderToString} from '@vue/server-renderer';
const sandbox={window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api=sandbox.window.SUXI_MEITUAN_STATIC;
const row=extra=>({id:101,source:'meituan',data_type:'review',system_hotel_id:80,data_date:'2026-09-25',
  dimension:'review:meituan',comment_score:4.8,quantity:12,data_value:0,...extra});

test('distinct explicit review dimensions remain visible when all metrics match',()=>{
  const data=api.buildMeituanDownloadData([row(),row({id:100,dimension:'review:other'})]);
  assert.deepEqual(Array.from(data.reviewRows,r=>r.id),[101,100]);
  assert.equal(data.reviewCoverage.count.total,2);
});

test('legacy blank dimension still deduplicates against the default review dimension',()=>{
  const data=api.buildMeituanDownloadData([row(),row({id:100,dimension:''}),row({id:99,dimension:null})]);
  assert.deepEqual(Array.from(data.reviewRows,r=>r.id),[101]);
});

test('raw review dimension aliases are shared by identity and visible label',()=>{
  const rows=['dimension','dimName','reviewDimension','review_dimension'].map((key,i)=>row({id:101-i,dimension:null,raw_data:JSON.stringify({[key]:'scope-'+i})}));
  const data=api.buildMeituanDownloadData(rows);
  assert.equal(data.reviewRows.length,4);
  assert.deepEqual(Array.from(data.reviewRows,r=>r.review_dimension_label),['scope-0','scope-1','scope-2','scope-3']);
});

test('whitespace dimensions fall through to raw aliases and canonical values take priority',()=>{
  const data=api.buildMeituanDownloadData([
    row({dimension:' ',raw_data:JSON.stringify({dimension:' ',dimName:' channel-a '})}),
    row({id:100,dimension:'channel-a',raw_data:JSON.stringify({dimension:'channel-b'})}),
    row({id:99,dimension:'channel-b'}),
  ]);
  assert.equal(data.reviewRows.length,2);
  assert.deepEqual(Array.from(data.reviewRows,r=>r.review_dimension_label),['channel-a','channel-b']);
});

test('hotel/date/platform scope and original records survive review identity projection',()=>{
  const rows=[row(),row({id:100,system_hotel_id:121}),row({id:99,data_date:'2026-09-26'}),
    row({id:98,source:'ctrip'}),row({id:97,quantity:13})];
  const before=JSON.stringify(rows);
  assert.equal(api.buildMeituanDownloadData(rows).reviewRows.length,4);
  assert.equal(JSON.stringify(rows),before);
});

test('record detail uses the same resolved legacy dimension as the review table',async()=>{
  const source=readFileSync('public/components/system/app-main-components.js','utf8');
  const start=source.indexOf('    const MeituanStoredRecordDetail =');
  const end=source.indexOf('    const MeituanSearchKeywordWorkbench =',start);
  const component=new Function('Vue','h','window',source.slice(start,end)+'\nreturn MeituanStoredRecordDetail;')(Vue,Vue.h,sandbox.window);
  const [record]=api.buildMeituanDownloadData([row({dimension:' ',raw_data:JSON.stringify({reviewDimension:'legacy-review-scope'})})]).reviewRows;
  const html=await renderToString(Vue.createSSRApp({render:()=>Vue.h(component,{record})}));
  assert.match(html,/维度<\/dt><dd[^>]*>legacy-review-scope/);
});
