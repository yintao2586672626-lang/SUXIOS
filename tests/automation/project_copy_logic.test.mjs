import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(path, 'utf8');

test('project copy keeps the OTA evidence to action review logic explicit', () => {
  const readme = read('README.md');
  const systemLogic = read('docs/system_design_logic.md');
  const terminology = read('docs/ota_i18n_terminology_logic.md');
  const publicEntry = read('public/index.html');
  const systemStatic = read('public/system-static.js');

  assert.match(readme, /授权 OTA 可见数据 -> 采集证据 -> 字段目录 -> 标准事实/);
  assert.match(readme, /收益\/流量\/转化\/竞争圈诊断 -> 待确认 AI 建议/);
  assert.match(readme, /不写成全酒店出租率、ADR、RevPAR 或全渠道收入/);
  assert.match(readme, /从 OTA 数据进入收益分析、AI 决策、运营管理和投资决策/);

  assert.match(systemLogic, /术语到业务对象到动作/);
  assert.match(systemLogic, /竞争圈指标只代表平台定义的同圈对比/);
  assert.match(systemLogic, /待确认 AI 建议/);

  assert.match(terminology, /页面术语到业务对象/);
  assert.match(terminology, /商旅渠道表现/);
  assert.match(terminology, /不把竞争圈当作市场全量/);

  assert.match(publicEntry, /围绕授权 OTA 可见数据的线上获客、收益诊断、动作追踪和效果复盘，保留投前、开业、扩张和转让的辅助判断入口/);
  assert.match(publicEntry, />OTA数据<[^]*?>收益诊断<[^]*?>运营动作<[^]*?>效果复盘<[^]*?>投前辅助</);

  assert.match(systemStatic, /name: '线上数据'/);
  assert.match(systemStatic, /name: '收益分析中心'/);
  assert.match(systemStatic, /name: 'AI经营日报'/);
  assert.match(systemStatic, /name: '运营执行'/);
  assert.match(systemStatic, /name: 'P4·投决辅助'/);
});
