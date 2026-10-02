import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { loadFrontendTemplateSource } from '../../scripts/lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const css = readFileSync('public/style.css', 'utf8');
const authenticatedStyle = readFileSync('public/style.min.css', 'utf8');
const entry = readFileSync('public/index.html', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const template = loadFrontendTemplateSource(repoRoot).template;
const compassStyle = readFileSync('public/compass-authority-polish.css', 'utf8');
const homeStatic = readFileSync('public/home-static.js', 'utf8');

test('retired workbench controls are absent and active Compass controls retain visible keyboard focus', () => {
  assert.doesNotMatch(template, /home-ai-workbench|dual-ota-range-button|dual-ota-loss-node/);
  assert.doesNotMatch(css, /data-current-page="ai-workbench"/);
  assert.match(template, /class="home-facts-refresh" @click="refreshCompassDashboard" :disabled="compassLoading \|\| homeRevenueFactLayerLoading"/);
  assert.match(compassStyle, /main\[data-current-page="compass"\] :is\(\.home-workspace-toolbar, \.home-priority-layout, \.home-records-fold\) :is\(button, select, input, summary\):focus-visible \{\s*outline: 3px solid/);
  assert.match(compassStyle, /@media \(max-width: 599px\)[\s\S]*home-workspace-controls[\s\S]*min-height: 44px/);
});

test('authenticated stylesheet cache key matches the current stylesheet content', () => {
  const hash = createHash('sha256').update(authenticatedStyle).digest('hex').slice(0, 10);
  assert.match(entry, new RegExp(`style\\.min\\.css\\?v=[^"']*-h${hash}["']`));
});

test('retired metric-card DOM is absent while retained drilldown handlers preserve Enter and Space semantics', () => {
  assert.doesNotMatch(template, /dual-ota-system-metric/);
  assert.doesNotMatch(css, /data-current-page="ai-workbench"[^\n]*dual-ota-system-metric/);
  assert.match(appMain, /metricEl\.setAttribute\('role', 'button'\)/);
  assert.match(appMain, /metricEl\.setAttribute\('tabindex', '0'\)/);
  assert.match(appMain, /document\.addEventListener\('click', handleDualOtaSystemMetricDomDrilldown\)/);
  assert.match(appMain, /document\.addEventListener\('keydown', handleDualOtaSystemMetricDomKeydown\)/);
  assert.match(appMain, /event\?\.key !== 'Enter' && event\?\.key !== ' '/);
});

test('retired hotel order dialog is not part of the active template', () => {
  assert.doesNotMatch(template, /data-testid="dual-ota-hotel-order-dialog"/);
  assert.doesNotMatch(template, /dual-ota-hotel-order-overlay|dual-ota-hotel-order-panel/);
});

test('active time-axis future action is a native keyboard button with explicit hotel and busy gates', () => {
  const sandbox = { window: { Vue: { h: (type, props, children) => ({ type, props: props || {}, children }), Fragment: 'fragment' } } };
  vm.runInNewContext(homeStatic, sandbox, { filename: 'public/home-static.js' });
  const component = sandbox.window.SUXI_HOME_STATIC.HomeBusinessTimeAxis;
  const nodes = [];
  const visit = node => { if (!node || typeof node !== 'object') return; nodes.push(node); (Array.isArray(node.children) ? node.children : []).forEach(visit); };
  const emitted = [];
  const render = (selectedHotelId, generating = false) => {
    nodes.length = 0;
    visit(component.render.call({ model: { timeline: [{ key: 'future', testid: 'future-case', detail: 'synthetic-evidence' }] }, competitorReadiness: {}, selectedHotelId, generating, $emit: event => emitted.push(event) }));
    return nodes.find(node => node.type === 'button');
  };
  const readyButton = render('7');
  assert.equal(readyButton.props.type, 'button');
  assert.equal(readyButton.props.disabled, false);
  readyButton.props.onClick();
  assert.deepEqual(emitted, ['generate']);
  assert.equal(render('').props.disabled, true);
  assert.equal(render('7', true).props.disabled, true);
  assert.ok(nodes.some(node => node.props['data-testid'] === 'future-case'));
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
  assert.match(css, /main\[data-current-page="compass"\] \.holiday-ops-grid/);
  assert.doesNotMatch(css, /main\[data-current-page="ai-workbench"\][^\n]*holiday-/);
  assert.match(css, /prefers-reduced-motion: reduce[\s\S]*holiday-countdown-card[\s\S]*transition-duration: 1ms/);
});
