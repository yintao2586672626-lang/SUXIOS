import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const ctripStaticSource = fs.readFileSync('public/ctrip-static.js', 'utf8');
const ctripFragment = fs.readFileSync(
  'resources/frontend/templates/fragments/24-page-ctrip-ebooking.html',
  'utf8',
);
const appMain = fs.readFileSync('public/app-main.js', 'utf8');

const h = (type, props = null, children = null) => ({ type, props: props || {}, children });
const sandbox = { window: {}, Vue: { h } };
vm.runInNewContext(ctripStaticSource, sandbox, { filename: 'public/ctrip-static.js' });

test('saved Ctrip history count is a registered interactive component', () => {
  assert.match(
    ctripFragment,
    /<ctrip-config-history v-if="Number\(config\.history_count \|\| 0\) &gt; 0" :config="config"><\/ctrip-config-history>/,
  );
  assert.match(appMain, /const CtripConfigHistory = window\.SUXI_CTRIP_STATIC\?\.CtripConfigHistory;/);
  assert.match(appMain, /components:\s*\{[\s\S]*CtripConfigHistory,/);
});

test('Ctrip history component toggles a non-secret, store-specific summary panel', () => {
  const component = sandbox.window.SUXI_CTRIP_STATIC.CtripConfigHistory;
  assert.ok(component);
  assert.equal(component.data().open, false);
  const instance = {
    open: false,
    config: {
      history_count: 2,
      history_items: [{
        id: 'old-1',
        status_label: '已替换',
        update_time: '2026-07-29 10:30:00',
        ctrip_hotel_id: '9987',
        hotel_room_count: 88,
        competitor_room_count: 1200,
        cookies: 'must-not-render',
      }],
    },
  };

  const trigger = component.render.call(instance);
  assert.equal(trigger.type, 'button');
  assert.equal(trigger.props['data-testid'], 'ctrip-config-history-trigger');
  assert.equal(trigger.props['aria-expanded'], 'false');
  assert.equal(trigger.props['aria-label'], '展开 2 条配置历史');
  assert.doesNotMatch(JSON.stringify(trigger), /查看/);
  let propagationStopped = false;
  trigger.props.onClick({ stopPropagation: () => { propagationStopped = true; } });
  assert.equal(propagationStopped, true);
  assert.equal(instance.open, true);

  const panelTree = component.render.call(instance);
  const rendered = JSON.stringify(panelTree);
  assert.doesNotMatch(rendered, /查看/);
  assert.match(rendered, /收起/);
  assert.match(rendered, /ctrip-config-history-panel/);
  assert.match(rendered, /2026-07-29 10:30/);
  assert.match(rendered, /已替换/);
  assert.match(rendered, /携程酒店ID 9987/);
  assert.match(rendered, /本店 88 间/);
  assert.doesNotMatch(rendered, /must-not-render/);
});
