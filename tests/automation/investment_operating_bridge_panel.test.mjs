import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { test } from 'node:test';

const context = { window: {}, Vue: { h: (tag, props, children) => {
  if (children === undefined) { children = props; props = {}; }
  return { tag, props: props || {}, children };
} } };
vm.runInNewContext(readFileSync(new URL('../../public/components/system/investment-operating-bridge-panel.js', import.meta.url), 'utf8'), context);
const panel = context.window.SUXI_SYSTEM_COMPONENTS.InvestmentOperatingBridgePanel;
const walk = (node, result = []) => { if (node && typeof node === 'object') { result.push(node); for (const child of Array.isArray(node.children) ? node.children : []) walk(child, result); } return result; };
const render = bridge => panel.render.call({ bridge, loading: false, $emit() {} });

test('missing and failed ledgers never render zero-money cards', () => {
  for (const bridge of [{}, { status: 'error' }, { contract_version: 'investment_operating_bridge.v1', status: 'read_failed', projects: null }]) {
    const nodes = walk(render(bridge));
    assert.equal(nodes.filter(node => node.props['data-metric']).length, 0);
    assert.equal(nodes.filter(node => node.props['data-testid'] === 'investment-bridge-no-total').length, 1);
  }
});

test('partial totals retain caveat, scope, cutoff and per-project values', () => {
  const bridge = { contract_version: 'investment_operating_bridge.v1', hotel_id: 80, period_month: '2026-10', effective_as_of: '2026-10-02', requested_period_end: '2026-10-31', cutoff_status: 'current_month_to_date', status: 'partial', totals: null,
    recorded_totals: { actual_invested: '100.00', net_actual_recovered: '30.00', unrecovered: '70.00', excess_return: '0.00' },
    projects: [{ project_id: 1, project_name: '测试合成自投项目', investor_name: '测试主体', history_complete: false, amounts: { actual_invested: '100.00', net_actual_recovered: '30.00', unrecovered: '70.00' } }] };
  const tree = render(bridge);
  assert.equal(walk(tree).filter(node => node.props['data-metric']).length, 4);
  const serialized = JSON.stringify(tree);
  assert.match(serialized, /以上仅为已录入记录的部分合计/);
  assert.match(serialized, /2026-10-02/);
  assert.match(serialized, /账目核对不完整/);
  assert.match(serialized, /不会自动记作已回本/);
});

test('opening ledger is a navigation event and never an accounting write', () => {
  const events = [];
  const nodes = walk(panel.render.call({ bridge: {}, loading: false, $emit: name => events.push(name) }));
  nodes.find(node => node.tag === 'button').props.onClick();
  assert.deepEqual(events, ['open-ledger']);
});
