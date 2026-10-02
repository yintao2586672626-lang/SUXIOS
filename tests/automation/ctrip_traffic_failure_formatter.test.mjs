import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const currentFull = fs.readFileSync(new URL('../../public/ctrip-static.js', import.meta.url), 'utf8');
const currentLoader = fs.readFileSync(new URL('../../public/ctrip-static-loader.js', import.meta.url), 'utf8');
const helperName = 'ctripTrafficFetchFailureMessage';

function loadModule(source, context = vm.createContext({ window: {} })) {
  // Only window is supplied: formatter execution has no network, browser, timer, or storage dependency.
  vm.runInContext(source, context);
  return { context, api: context.window.SUXI_CTRIP_STATIC };
}

const migrated = loadModule(currentFull).api;
const messages = {
  authorization: '当前酒店携程授权已失效，请更新授权后重试',
  config: '当前门店未绑定携程授权配置，请到“数据抓取设置”选择门店并保存配置',
  signature: '携程接口签名已失效，请更新授权后重试',
  missing: '本次接口未返回可入库的流量数据',
};

const cases = [
  ['undefined result', undefined, messages.missing],
  ['null result', null, messages.missing],
  ['empty result', {}, messages.missing],
  ['false result', false, messages.missing],
  ['true result', true, messages.missing],
  ['zero result', 0, messages.missing],
  ['unknown status and detail', { status: 'unknown', response: { message: 'synthetic unavailable' } }, messages.missing],
  ['real zero metrics remain a missing-data failure message', {
    status: 'incomplete', response: { code: 200, data: { saved_count: 0, rows: [{ pv: 0, uv: 0 }] } },
  }, messages.missing],
  ['direct message takes priority and is trimmed', {
    message: '  合成来源返回的明确失败  ', status: 'missing_config', response: { message: 'cookie signature invalid' },
  }, '合成来源返回的明确失败'],
  ['string zero direct message is preserved', { message: '0' }, '0'],
  ['numeric zero message keeps its existing fallback', { message: 0 }, messages.missing],
  ['blank direct message falls through', { message: '  ', response: { message: 'COOKIE expired' } }, messages.authorization],
  ['English cookie authorization', { response: { message: 'cookie expired' } }, messages.authorization],
  ['English login authorization', { response: { message: 'LOGIN required' } }, messages.authorization],
  ['English session authorization', { response: { data: { warning: 'session expired' } } }, messages.authorization],
  ['numeric 403 response message', { response: { message: 403 } }, messages.authorization],
  ['Chinese login authorization', { response: { message: '请重新登录' } }, messages.authorization],
  ['Chinese session authorization', { response: { data: { auth_status: { message: '会话已过期' } } } }, messages.authorization],
  ['Chinese authorization in exception', { error: { message: '授权已失效' } }, messages.authorization],
  ['English config binding', { response: { message: 'CONFIG missing' } }, messages.config],
  ['English binding failure', { response: { data: { warning: 'binding missing' } } }, messages.config],
  ['Chinese configuration failure', { response: { message: '配置缺失' } }, messages.config],
  ['Chinese binding failure', { error: { message: '尚未绑定' } }, messages.config],
  ['missing-config status without detail', { status: 'missing_config' }, messages.config],
  ['English spider signature failure', { response: { message: 'SPIDER rejected' } }, messages.signature],
  ['English signature failure', { response: { data: { warning: 'signature invalid' } } }, messages.signature],
  ['Chinese signature failure', { error: { message: '签名过期' } }, messages.signature],
  ['authorization retains priority over config and signature', {
    response: { message: 'cookie config signature rejected' },
  }, messages.authorization],
  ['config retains priority over signature', { response: { message: 'binding signature rejected' } }, messages.config],
  ['missing-config status retains priority over signature detail', {
    status: 'missing_config', error: { message: 'signature rejected' },
  }, messages.config],
];

for (const [name, input, expected] of cases) {
  test(`traffic failure formatter preserves its message contract: ${name}`, () => {
    assert.equal(typeof migrated[helperName], 'function', 'the full module must export the migrated formatter');
    const actual = migrated[helperName](input);
    assert.equal(actual, expected);
  });
}

test('formatter facade explicitly reports unavailable full capability before loading', () => {
  const { api: facade } = loadModule(currentLoader);
  assert.equal(typeof facade[helperName], 'function');
  assert.throws(() => facade[helperName]({ status: 'missing_config' }), {
    name: 'Error', message: `携程完整静态能力尚未加载：${helperName}`,
  });
});

test('formatter facade dynamically delegates through its saved reference after full loading', () => {
  const { context, api: savedFacade } = loadModule(currentLoader);
  assert.equal(typeof savedFacade[helperName], 'function');
  const savedDelegate = savedFacade[helperName];
  loadModule(currentFull, context);
  const full = context.window.SUXI_CTRIP_STATIC_FULL;
  assert.equal(context.window.SUXI_CTRIP_STATIC, full);
  assert.notEqual(savedFacade, full);
  assert.equal(savedFacade[helperName], savedDelegate, 'the original facade delegate must remain usable');
  for (const [, input, expected] of cases) {
    assert.equal(savedDelegate(input), expected);
    assert.equal(savedDelegate(input), full[helperName](input));
  }
});
