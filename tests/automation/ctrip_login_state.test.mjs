import assert from 'node:assert/strict';
import test from 'node:test';

import { isCtripLoggedInPageState } from '../../scripts/lib/ctrip_login_state.mjs';

test('recognizes the authenticated Chinese eBooking workbench', () => {
  assert.equal(isCtripLoggedInPageState(
    'https://ebooking.ctrip.com/home/mainland?microJump=true',
    'eBooking 敦煌漠蓝 首页 订单管理 房价房态 促销推广 点评问答 财务结算 数据中心',
  ), true);
});

test('rejects login, challenge, sparse, and untrusted pages', () => {
  assert.equal(isCtripLoggedInPageState(
    'https://ebooking.ctrip.com/home/mainland',
    '账号登录 请输入密码 验证码',
  ), false);
  assert.equal(isCtripLoggedInPageState(
    'https://ebooking.ctrip.com/login',
    '首页 订单管理 数据中心',
  ), false);
  assert.equal(isCtripLoggedInPageState(
    'https://ebooking.ctrip.com/home/mainland',
    '欢迎',
  ), false);
  assert.equal(isCtripLoggedInPageState(
    'https://example.test/home/mainland',
    '首页 订单管理 数据中心',
  ), false);
});
