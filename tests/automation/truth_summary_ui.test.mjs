import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const revenueAiStatic = readFileSync('public/revenue-ai-static.js', 'utf8');
const fragments = [
  '08-shared-transfer-context.html',
  '13-page-opening-overview.html',
  '16-page-ai-daily-report.html',
  '23c-page-compass-detail.html',
  '35-page-online-data.html',
].map(name => readFileSync(`resources/frontend/templates/fragments/${name}`, 'utf8'));

test('truth cards default to a concise summary and fold technical trace', () => {
  assert.match(appMain, /const OnlineTruthSummary = \{/);
  assert.match(appMain, /onlineTruthSummaryText/);
  assert.match(appMain, /onlineTruthNextActionText/);
  assert.match(appMain, /h\('details'/);
  assert.match(appMain, /'查看详情'/);
  assert.match(appMain, /OnlineTruthSummary,/);

  for (const fragment of fragments) {
    assert.match(fragment, /<online-truth-summary/);
    assert.doesNotMatch(fragment, /onlineTruthDetailText\(|card\.truthLines|metric\.sourceRefsText|metric\.truthDetailText/);
  }
});

test('Revenue AI cards carry the truth envelope into the shared summary', () => {
  const start = revenueAiStatic.indexOf('const buildRevenueAiMetricCards');
  const end = revenueAiStatic.indexOf('const buildRevenueAiGapRows', start);
  assert.ok(start >= 0 && end > start, 'Revenue AI metric card builder must exist');
  const block = revenueAiStatic.slice(start, end);
  assert.match(block, /\btruth,\s*\n\s*truthStatus/);
});
