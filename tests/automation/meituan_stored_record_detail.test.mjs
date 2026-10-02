import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';

const componentSource = readFileSync('public/components/system/app-main-components.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
const sandbox = {window:{},console};
vm.runInNewContext(readFileSync('public/meituan-static.js','utf8'),sandbox);
const api = sandbox.window.SUXI_MEITUAN_STATIC;
function component() {
  const start = componentSource.indexOf('    const MeituanStoredRecordDetail =');
  const end = componentSource.indexOf('    const MeituanSearchKeywordWorkbench =', start);
  assert.ok(start >= 0 && end > start, 'Saved Meituan rows need a usable detail disclosure');
  return new Function('Vue', 'h', 'window', componentSource.slice(start, end) + '\nreturn MeituanStoredRecordDetail;')(Vue, Vue.h, sandbox.window);
}
const row = overrides => ({
  id: 901, source: 'meituan', system_hotel_id: 80, hotel_id: 'ota-80',
  hotel_name: 'Synthetic A', data_date: '2026-09-25', data_type: 'advertising',
  validation_status: 'partial', readback_verified: 1, storage_status_label: '已保存·部分待核',
  field_fact_status: 'partial', metric_status: 'partial', create_time: '2026-09-26 01:00:00',
  dimension: 'campaign-a', list_exposure: 0, detail_exposure: null, amount: 0,
  ...overrides,
});
const render = async item => renderToString(Vue.createSSRApp({render: () => Vue.h(component(), {record:item})}));

test('all Meituan detail entrances resolve to the saved-record component rather than a Ctrip action', () => {
  assert.doesNotMatch(template, /viewOnlineDataDetail/);
  assert.equal((template.match(/<meituan-stored-record-detail\b/g) || []).length, 7);
  assert.match(componentSource, /h\(MeituanStoredRecordDetail, \{ record: item \}\)/);
  const loader = readFileSync('public/components/system/app-main-components-loader.js','utf8');
  assert.match(loader, /'MeituanStoredRecordDetail'/);
  assert.match(readFileSync('public/app-main.js','utf8'), /MeituanStoredRecordDetail,/);
});

test('saved detail preserves identity, distinct collection date, partial truth and genuine zero', async () => {
  const html = await render(row());
  for (const expected of ['901', 'Synthetic A', 'ota-80', '2026-09-25', '2026-09-26 01:00:00', '美团', '广告', 'campaign-a', '已保存·部分待核', 'partial', '已验证']) assert.ok(html.includes(expected), expected);
  assert.match(html, /列表曝光<\/dt><dd[^>]*>0<\/dd>/);
  assert.match(html, /详情曝光<\/dt><dd[^>]*>未返回<\/dd>/);
  assert.match(html, /金额<\/dt><dd[^>]*>0<\/dd>/);
  assert.match(html, /渠道/);
});

test('old data distinguishes missing readback from explicit unverified and has no implicit zero', async () => {
  const missing = await render(row({readback_verified:undefined, validation_status:null, amount:null}));
  const pending = await render(row({readback_verified:0}));
  assert.match(missing, /精确回读<\/dt><dd[^>]*>未返回<\/dd>/);
  assert.match(pending, /精确回读<\/dt><dd[^>]*>未验证<\/dd>/);
  assert.match(missing, /金额<\/dt><dd[^>]*>未返回<\/dd>/);
});

test('details allow only business fields and escape text without exposing raw source payload', async () => {
  const html = await render(row({hotel_name:'<script>synthetic()</script>', dimension:'<img src=x onerror=synthetic()>',
    raw_data: {never_show:'synthetic-secret-marker'}, cookie:'synthetic-cookie-marker',
    unrelated:'synthetic-unrelated-marker', amount:{nested:'synthetic-object-marker'},
  }));
  assert.doesNotMatch(html, /synthetic-secret-marker|synthetic-cookie-marker|synthetic-unrelated-marker|synthetic-object-marker|<script>|<img /);
  assert.match(html, /&lt;script&gt;/);
  assert.match(html, /格式异常/);
});

test('non-Meituan or empty records fail closed instead of being labeled as Meituan facts', async () => {
  assert.match(await render(null), /记录不可用/);
  assert.match(await render(row({source:'ctrip'})), /记录不可用/);
  assert.doesNotMatch(await render(row({source:'ctrip'})), /Synthetic A/);
});

test('peer identity and manual source stay distinct from the owning hotel and successful readback', async () => {
  const html = await render(row({system_hotel_name:'Owning Hotel', captured_hotel_name:'Competitor Hotel',
    data_type:'peer_rank', readback_verified:1, total_order_num:0,
    truth:{status:'unverified', source:{method:'manual_import'}}, data_quality:{status:'ok'},
  }));
  assert.match(html, /归属系统酒店<\/dt><dd[^>]*>Owning Hotel/);
  assert.match(html, /采集对象<\/dt><dd[^>]*>Competitor Hotel/);
  assert.match(html, /来源真实性<\/dt><dd[^>]*>unverified/);
  assert.match(html, /来源方式<\/dt><dd[^>]*>manual_import/);
  assert.doesNotMatch(html, /总订单/);
});

test('actual nested quality shapes retain failure and source time without filling the business date', async () => {
  const html = await render(row({data_date:null, field_fact_status:{status:'partial'},
    truth:{status:'collection_failed',collected_at:'2026-09-26 03:00:00',failure_reason:'Synthetic upstream failure'},
  }));
  assert.match(html, /业务日期<\/dt><dd[^>]*>未返回/);
  assert.match(html, /来源采集时间<\/dt><dd[^>]*>2026-09-26 03:00:00/);
  assert.match(html, /来源真实性<\/dt><dd[^>]*>collection_failed/);
  assert.match(html, /字段事实状态<\/dt><dd[^>]*>partial/);
  assert.match(html, /Synthetic upstream failure/);
});

test('the real saved review projection retains old aliases, zero bad reviews and its dimension', async () => {
  const data = api.buildMeituanDownloadData([row({data_type:'review',dimension:null,quantity:null,data_value:null,
    raw_data:JSON.stringify({metrics:{reviewCount:12,badReviewCount:0,score:4.6},reviewDimension:'旧来源点评'}),
  })]);
  assert.equal(data.reviewRows.length,1);
  const html = await render(data.reviewRows[0]);
  assert.match(html, /点评数量<\/dt><dd[^>]*>12/);
  assert.match(html, /低分 \/ 差评数量<\/dt><dd[^>]*>0/);
  assert.match(html, /旧来源点评/);
});

test('traffic and advertising details reuse the page metric aliases without zero-filling missing values', async () => {
  for (const data_type of ['traffic','advertising']) {
    const html = await render(row({data_type,list_exposure:null,detail_exposure:null,exposure_count:20,click_count:0,order_filling_num:0,order_submit_num:null}));
    assert.match(html, /列表曝光<\/dt><dd[^>]*>20/);
    assert.match(html, /详情曝光<\/dt><dd[^>]*>0/);
    assert.match(html, data_type === 'traffic' ? /页面转化率（%）<\/dt><dd[^>]*>0/ : /页面转化率（%）<\/dt><dd[^>]*>未返回/);
    if (data_type === 'traffic') assert.match(html, /下单用户<\/dt><dd[^>]*>未返回/);
  }
});
