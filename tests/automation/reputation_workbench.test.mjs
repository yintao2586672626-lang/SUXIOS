import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');

test('reputation workbench uses persisted OTA review summaries and keeps platform scores separate', () => {
  assert.match(template, /data-testid="online-data-reputation-tab"/);
  assert.match(template, /data-testid="reputation-workbench"/);
  assert.match(template, /onlineDataSummary\?\.ota_channel_supplement\?\.reviews/);
  assert.match(template, /平台原始分，不跨平台平均/);
  assert.match(template, /当前范围没有可验证趋势；不会用 0 或演示值补齐/);
  assert.doesNotMatch(template, />4\.72</);
  assert.doesNotMatch(template, />4\.61</);
  assert.doesNotMatch(template, /platform\.source === 'douyin'/);
  assert.doesNotMatch(template, /platform\.source === 'fliggy'/);
});

test('reputation workbench routes to existing privacy-bounded review evidence entrances', () => {
  assert.match(template, /openCtripManualTab\('ctrip-review-match'\)/);
  assert.match(template, /openMeituanManualTab\('meituan-review-match'\)/);
  assert.match(template, /不展示点评正文或住客身份/);
  assert.match(template, /进入携程点评证据/);
  assert.match(template, /进入美团点评证据/);
});

test('reputation workbench exposes every reviewed field without inventing unavailable capabilities', () => {
  assert.match(template, /data-testid="reputation-platform-field-evidence"/);
  assert.match(template, /data-testid="reputation-field-readiness"/);
  for (const label of [
    '好评率（来源返回）',
    '点评回复率',
    '未回复点评',
    '带图点评数',
    '带图率',
    '环境 / 位置子分',
    '设施子分',
    '服务子分',
    '卫生 / 清洁子分',
  ]) {
    assert.match(template, new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
  assert.match(template, /好评数量不使用“点评总量－差评量”推算/);
  assert.match(template, /相邻业务日基线缺失，只展示“较前次可用快照”/);
  assert.match(template, /点评预测 · 未放行/);
  assert.match(template, /截图中的“每日 09:00”不作为宿析配置/);
});
