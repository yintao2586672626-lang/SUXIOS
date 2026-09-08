import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = file => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const fullSource = read('public/components/system/app-main-components.js');
const facadeSource = read('public/components/system/app-main-components-loader.js');
const h = (type, props, children) => children === undefined && (Array.isArray(props) || typeof props === 'string')
  ? { type, props: {}, children: props } : { type, props: props || {}, children };
const walk = node => [node, ...(Array.isArray(node?.children) ? node.children.flatMap(walk) : [])];

test('existing quant simulation entry exposes a lazy native commission panel without new page routes', async () => {
  const window = {};
  const Vue = { h, defineAsyncComponent: config => ({ config }) };
  const requested = [];
  const document = {
    querySelector: () => null,
    createElement: () => ({ dataset: {}, remove() {} }),
    head: { appendChild(script) {
      requested.push(script.src);
      queueMicrotask(() => {
        const file = 'public/' + script.src.split('?')[0];
        assert.ok(file.startsWith('public/components/revenue/'));
        new Function('window', read(file))(window);
        script.onload();
      });
    } },
  };
  new Function('window', 'document', fullSource)(window, document);
  new Function('window', 'document', facadeSource)(window, document);
  const facade = window.SUXI_APP_MAIN_COMPONENTS.create({ Vue, h });
  const hero = await facade.SimulationHeroActions.config();
  const ctx = { ...hero.data(), hotelId: 80, hotels: [{ id: 80, name: 'TEST-ONLY Hotel' }], loading: false, hotelValid: true, $emit() {} };
  let tree = hero.render.call(ctx);
  const button = walk(tree).find(node => node?.props?.['data-testid'] === 'open-commission-acquisition');
  assert.ok(button);
  assert.equal(requested.length, 0, 'helper scripts must not become login/startup dependencies');
  button.props.onClick();
  tree = hero.render.call(ctx);
  const panel = walk(tree).find(node => node?.type?.config?.loader);
  assert.ok(panel);
  assert.equal(panel.props.hotelId, 80);
  const component = await panel.type.config.loader();
  assert.equal(component.name, 'CommissionAcquisitionCalculatorPanel');
  assert.equal(requested.length, 4);
  assert.match(requested[0], /commission-calculator-core/);
  assert.match(requested[1], /commission-paid-traffic-core/);
  assert.match(requested[2], /promotion-experiment-panel/);
  assert.match(requested[3], /commission-acquisition-panel/);
  button.props.onClick();
  assert.equal(ctx.commissionCalculatorOpen, false);
  assert.equal(ctx.commissionCalculatorMounted, true, 'closing panel preserves in-session inputs');
  assert.match(read('resources/frontend/templates/fragments/02-page-ai-simulation.html'), /<simulation-hero-actions/);
  assert.match(read('public/system-static.js'), /testid: 'nav-ai-simulation'/);
});

test('runtime cache references match modified component and scenarios remain explicitly unverified', () => {
  const hash = createHash('sha256').update(fullSource).digest('hex').slice(0, 10);
  for (const source of [facadeSource, read('public/index.html')]) assert.ok(source.includes('app-main-components.js?v=20260830-operating-finance-h' + hash));
  const panel = read('public/components/revenue/commission-acquisition-panel.js');
  assert.match(panel, /source_method: 'user_scenario_input'/);
  assert.match(panel, /fact_status: 'unverified'/);
  assert.match(panel, /广告归因营收 ÷ 广告费/);
  assert.match(panel, /不自动调佣或投放/);
  assert.doesNotMatch(panel, /localStorage|sessionStorage|apiRequest\(|fetch\(/);
});
