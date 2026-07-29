import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const appMain = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync(
  'resources/frontend/templates/fragments/23b-page-ai-workbench.html',
  'utf8',
);
const style = readFileSync('public/style.css', 'utf8');

assert.match(
  appMain,
  /detail: pastMetric[\s\S]*?近 \$\{recentWindowDays\} 个有数据日均值[\s\S]*?fullDetail: pastMetric/,
  'the historical card must show one concise judgement while retaining its full evidence wording',
);
assert.match(
  appMain,
  /detail: presentRowCount > 0[\s\S]*?最近更新 \$\{presentUpdatedAtText\}[\s\S]*?fullDetail: present\.today_reason/,
  'the today card must show only its latest update time while retaining the full source reason',
);
assert.match(
  appMain,
  /detail: futureMetric[\s\S]*?\$\{futureDateLead\} \$\{futureRange\}[\s\S]*?fullDetail: futureMetric[\s\S]*?homeTemporalConfidenceLabel/,
  'the future card must show the date and range as its golden sentence while retaining confidence semantics',
);
assert.match(
  appMain,
  /历史预测区间命中率：\$\{homeTemporalPercentText\(review\.range_hit_rate\)\}/,
  'range hit rate must not be generalized into overall prediction accuracy',
);
assert.match(
  template,
  /class="dual-ota-temporal-detail" :title="card\.fullDetail \|\| card\.detail"/,
  'full temporal evidence wording must remain available without cluttering the card',
);
assert.match(
  template,
  /dual-ota-compare-copy">同期对比<\/span>[\s\S]*dual-ota-compare-switch/,
  'the comparison label and switch must be rendered as one compact control group',
);
assert.match(
  style,
  /\.dual-ota-compare-toggle \{[\s\S]*?justify-content: center;[\s\S]*?gap: 8px;[\s\S]*?padding: 7px 10px;/,
  'the comparison control must keep its label and switch adjacent',
);
assert.match(
  style,
  /\.dual-ota-compare-toggle\.is-active \.dual-ota-compare-switch::after \{[\s\S]*?transform: translateX\(12px\);/,
  'the compact comparison switch must retain a visible selected state',
);
