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
  /detail: futureMetric[\s\S]*?\$\{futureDateLead\} \$\{futureRange\} · \$\{homeTemporalOperationalStatusText[\s\S]*?fullDetail: futureMetric[\s\S]*?operational_gate\?\.reason[\s\S]*?homeTemporalConfidenceLabel/,
  'the future card must show its independent operational gate while retaining confidence semantics as fallback detail',
);
assert.match(
  appMain,
  /总计 \$\{matchedPoints\} 个到期点，整体命中率 \$\{homeTemporalPercentText\(review\.range_hit_rate\)\}（仅诊断）/,
  'overall range hit rate must remain diagnostic instead of becoming an operational trust claim',
);
assert.match(
  appMain,
  /按指标和 T\+周期分别回测；每个分组至少 \$\{policySamples\} 个到期样本/,
  'each metric and forecast horizon must be gated independently',
);
assert.match(
  appMain,
  /diagnostic_matched_points[\s\S]*另 \$\{excludedSamples\} 个仅诊断/,
  'matured forecasts without verified source evidence must stay visible but not inflate operational samples',
);
assert.match(
  template,
  /data-testid="home-temporal-backtest-matrix"[\s\S]*指标 × 预测周期独立回测[\s\S]*总命中率只作诊断/,
  'the workbench must expose the independent backtest matrix',
);
assert.match(
  template,
  /data-testid="home-temporal-operation-review"[\s\S]*审批通过后才生成运营任务[\s\S]*不自动调价[\s\S]*送人工审核/,
  'the workbench must keep human approval before task creation and forbid automatic pricing',
);
assert.match(
  appMain,
  /request\(`\/temporal-insights\/forecasts\/\$\{forecastPointId\}\/execution-intent`[\s\S]*response\.task_created !== false[\s\S]*persistedIntent\.status !== 'pending_approval'[\s\S]*persistedIntent\.tasks\) && persistedIntent\.tasks\.length > 0/,
  'the review bridge must read back a pending intent with no task before opening operation tracking',
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
