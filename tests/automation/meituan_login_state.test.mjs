import assert from 'node:assert/strict';
import test from 'node:test';

import { isMeituanLoggedInPageState } from '../../scripts/lib/meituan_login_state.mjs';

test('recognizes the authenticated Chinese Meituan merchant data center', () => {
  assert.equal(isMeituanLoggedInPageState(
    'https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Fdata-center',
    '美团酒店商家 数据中心 工作台 订单管理 产品管理 促销推广 经营指导 交易分析 流量分析 销售间夜',
  ), true);
});

test('rejects login, sparse, and untrusted Meituan pages', () => {
  assert.equal(isMeituanLoggedInPageState(
    'https://me.meituan.com/ebooking/merchant/ebIframe',
    '欢迎使用美团 eBooking 账号登录 请输入密码',
  ), false);
  assert.equal(isMeituanLoggedInPageState(
    'https://me.meituan.com/ebooking/merchant/ebIframe',
    '欢迎',
  ), false);
  assert.equal(isMeituanLoggedInPageState(
    'https://example.test/ebooking/merchant',
    '美团酒店商家 工作台 数据中心',
  ), false);
});
