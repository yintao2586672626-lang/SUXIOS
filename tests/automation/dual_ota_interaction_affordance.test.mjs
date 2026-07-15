import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { loadFrontendTemplateSource } from '../../scripts/lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const css = readFileSync('public/style.css', 'utf8');
const entry = readFileSync('public/index.html', 'utf8');
const template = loadFrontendTemplateSource(repoRoot).template;

test('AI workbench buttons expose visible hover, press and keyboard feedback', () => {
  assert.match(css, /AI 工作台交互可发现性/);
  assert.match(css, /\.dual-ota-home\[data-testid="home-ai-workbench"\][\s\S]*:is\([\s\S]*\.dual-ota-range-button/);
  assert.match(css, /\.dual-ota-loss-node:hover[\s\S]*transform: translateY\(-3px\) !important/);
  assert.match(css, /\.dual-ota-deprioritized-link:hover em::after[\s\S]*translateX\(4px\)/);
  assert.match(css, /:focus-visible[\s\S]*outline: 3px solid rgba\(15, 107, 95, \.24\) !important/);
  assert.match(css, /:active[\s\S]*scale\(\.98\) !important/);
  assert.match(css, /button:disabled[\s\S]*cursor: not-allowed !important/);
});

test('AI workbench stylesheet cache key matches the current stylesheet content', () => {
  const hash = createHash('sha256').update(css).digest('hex').slice(0, 10);
  assert.match(entry, new RegExp(`style\\.css\\?v=[^"']*-h${hash}["']`));
});

test('AI workbench informational metric cards animate without claiming clickability', () => {
  assert.match(css, /AI 工作台指标卡动态/);
  assert.match(css, /\.dual-ota-system-metric \{[\s\S]*cursor: default/);
  assert.match(css, /\.dual-ota-system-metric:hover \{[\s\S]*translateY\(-3px\)/);
  assert.match(css, /\.dual-ota-system-metric:hover::before[\s\S]*scaleX\(1\)/);
  assert.match(css, /\.dual-ota-system-metric:hover strong[\s\S]*scale\(1\.035\)/);
  assert.match(css, /\.dual-ota-system-metric\.is-good:hover[\s\S]*rgba\(15, 107, 95, \.5\)/);
});

test('hotel order dialog escapes page stacking contexts through the app-managed teleport', () => {
  assert.match(template, /<teleport to="body">[\s\S]*data-testid="dual-ota-hotel-order-dialog"/);
  assert.match(template, /class="dual-ota-hotel-order-overlay[^"]*"/);
  assert.match(css, /\.dual-ota-hotel-order-overlay \{[\s\S]*z-index: 80/);
  assert.match(css, /\.dual-ota-hotel-order-panel button\[class\*="bg-amber-700"\] \{[\s\S]*background: linear-gradient/);
});

test('unavailable competitor price readiness is not rendered', () => {
  assert.match(template, /v-for="source in homeDataSources"[\s\S]*v-if="source\.name !== '竞对价格'"/);
  assert.match(template, /data-testid="home-data-source-card"/);
});

test('holiday operations keeps live bindings in the branded responsive layout', () => {
  assert.match(template, /data-testid="holiday-ops-panel"/);
  assert.match(template, /holiday-countdown-card-primary[\s\S]*holidayOperationCountdown\.nearest\.distance_text/);
  assert.match(template, /holiday-advice-item[\s\S]*\{\{ item \}\}/);
  assert.match(css, /\.holiday-ops-grid \{[\s\S]*grid-template-columns:/);
  assert.match(css, /\.holiday-countdown-card-primary \{[\s\S]*#06110d/);
  assert.match(css, /@media \(max-width: 767px\)[\s\S]*\.holiday-ops-grid/);
});
