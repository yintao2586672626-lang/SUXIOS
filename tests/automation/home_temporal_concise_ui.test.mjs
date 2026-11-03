import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadFrontendTemplateSource } from '../../scripts/lib/frontend_template_source.mjs';

const appMain = readFileSync('public/app-main.js', 'utf8');
const template = loadFrontendTemplateSource(process.cwd()).template;
const style = readFileSync('public/style.css', 'utf8');
const homeStatic = readFileSync('public/home-static.js', 'utf8');
const compassStyle = readFileSync('public/compass-authority-polish.css', 'utf8');

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
assert.doesNotMatch(
  template,
  /data-testid="home-temporal-backtest-matrix"/,
  'the retired workbench backtest matrix has no active entry; active model gates remain tested above',
);
assert.doesNotMatch(
  template,
  /data-testid="home-temporal-operation-review"/,
  'the retired workbench review panel is absent; the retained execution-intent bridge still enforces approval below',
);
assert.match(
  appMain,
  /request\(`\/temporal-insights\/forecasts\/\$\{forecastPointId\}\/execution-intent`[\s\S]*response\.task_created !== false[\s\S]*persistedIntent\.status !== 'pending_approval'[\s\S]*persistedIntent\.tasks\) && persistedIntent\.tasks\.length > 0/,
  'the review bridge must read back a pending intent with no task before opening operation tracking',
);
assert.match(
  template,
  /<home-business-time-axis[\s\S]*:model="homeBusinessTimeModel"[\s\S]*:selected-hotel-id="homeTemporalSelectedHotelId"[\s\S]*@generate="generateHomeTemporalForecast"/,
  'the active time-axis binds the current evidence model and explicit hotel to forecast generation',
);
assert.match(
  homeStatic,
  /const HomeBusinessTimeAxis[\s\S]*h\('p', null, stage\.detail\)[\s\S]*h\('button',[\s\S]*type: 'button',[\s\S]*disabled: this\.generating \|\| !this\.selectedHotelId/,
  'the current component renders each evidence detail and exposes a native button with hotel and busy gates',
);
assert.doesNotMatch(
  style,
  /data-current-page="ai-workbench"|\.dual-ota-compare-toggle/,
  'retired workbench comparison styling has no active CSS entry',
);
assert.match(
  compassStyle,
  /@media \(max-width: 599px\)[\s\S]*\.home-temporal-grid \{ grid-template-columns: minmax\(0, 1fr\); \}/,
  'the actual Compass time-axis retains its mobile single-column layout',
);
