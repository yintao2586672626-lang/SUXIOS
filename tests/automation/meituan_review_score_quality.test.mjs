import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import {renderToString} from '@vue/server-renderer';
const sandbox={window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api=sandbox.window.SUXI_MEITUAN_STATIC;
const row=(id,score)=>({id,source:'meituan',data_type:'review',system_hotel_id:80,data_date:'2026-09-25',
  dimension:'review:meituan',comment_score:score,quantity:12,data_value:0});
const source=readFileSync('public/components/system/app-main-components.js','utf8');
const start=source.indexOf('    const MeituanStoredRecordDetail =');
const end=source.indexOf('    const MeituanSearchKeywordWorkbench =',start);
const component=new Function('Vue','h','window',source.slice(start,end)+'\nreturn MeituanStoredRecordDetail;')(Vue,Vue.h,sandbox.window);
const render=record=>renderToString(Vue.createSSRApp({render:()=>Vue.h(component,{record})}));

test('invalid saved standard scores cannot inflate the average or erase independent counts',()=>{
  const data=api.buildMeituanDownloadData([row(1,101),row(2,4.8)]);
  assert.equal(data.reviewAverageScore,4.8);
  assert.equal(data.reviewRows[0].review_score_value,null);
  assert.equal(data.reviewRows[0].review_score_status,'invalid');
  assert.equal(data.reviewTotalCount,24);
  assert.equal(data.reviewBadCount,0);
  assert.equal(data.reviewCoverage.score.invalid,1);
  assert.equal(data.reviewCoverage.score.missing,0);
  assert.match(data.reviewCoverage.score.label,/1 条评分异常.*未计入均值/);
});

test('invalid, missing and different invalid scores retain distinct saved fact identities',()=>{
  const data=api.buildMeituanDownloadData([row(1,101),row(2,null),row(3,102),row(4,101)]);
  assert.deepEqual(Array.from(data.reviewRows,r=>r.id),[1,2,3]);
  assert.equal(data.reviewAverageScore,null);
  assert.equal(data.reviewCoverage.score.invalid,2);
  assert.equal(data.reviewCoverage.score.missing,1);
  assert.match(data.reviewCoverage.score.label,/评分异常/);
});

test('valid upper bound and legacy zero-as-unrated remain compatible',()=>{
  const data=api.buildMeituanDownloadData([row(1,5),row(2,0)]);
  assert.equal(data.reviewAverageScore,5);
  assert.equal(data.reviewRows[1].review_score_status,'missing');
  assert.equal(data.reviewCoverage.score.invalid,0);
  assert.equal(data.reviewCoverage.score.missing,1);
});

test('stored standard values are not silently rescaled and original data remains intact',()=>{
  const rows=[row(1,45),row(2,90),row(3,-1),{...row(4,null),raw_data:JSON.stringify({comment_score:4.5,score:90})}];
  const before=JSON.stringify(rows);
  const data=api.buildMeituanDownloadData(rows);
  assert.equal(data.reviewAverageScore,4.5);
  assert.equal(data.reviewCoverage.score.invalid,3);
  assert.equal(JSON.stringify(rows),before);
});

test('raw and projected record details both explain the invalid read value',async()=>{
  const original=row(1,101);
  const [projected]=api.buildMeituanDownloadData([original]).reviewRows;
  for(const record of [original,projected,{...original,data_type:'comment'},{...original,data_type:'comments'}]){
    const html=await render(record);
    assert.match(html,/评分异常.*101.*未计入均值/);
    assert.doesNotMatch(html,/评分<\/dt><dd[^>]*>101<\/dd>/);
    assert.doesNotMatch(html,/点评分值<\/dt><dd[^>]*>未返回/);
  }
  assert.match(await render(row(2,null)),/点评分值<\/dt><dd[^>]*>未返回/);
});
