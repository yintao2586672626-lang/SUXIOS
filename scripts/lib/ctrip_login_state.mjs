const BUSINESS_TEXT_SIGNALS = [
  /首页/u,
  /订单管理/u,
  /房价房态/u,
  /促销推广/u,
  /点评问答/u,
  /财务结算/u,
  /数据中心/u,
  /工作台/u,
  /经营/u,
];

const LOGIN_TEXT_SIGNALS = [
  /(?:账号|密码|扫码|验证码|手机号码?)登录/u,
  /(?:重新|立即)登录/u,
  /登录(?:页面|账号|密码|已过期)/u,
  /(?:会话|登录状态)(?:已)?(?:过期|失效|无效)/u,
  /请输入(?:账号|密码|验证码)/u,
  /login\s*(?:required|expired)/iu,
  /sign\s*in/iu,
  /captcha/iu,
];

export function isCtripLoggedInPageState(urlValue, bodyTextValue) {
  let url;
  try {
    url = new URL(String(urlValue || ''));
  } catch {
    return false;
  }
  if (url.protocol !== 'https:'
    || url.hostname !== 'ebooking.ctrip.com'
    || /(?:login|passport|account|oauth|sso)/iu.test(url.pathname + url.search)
  ) {
    return false;
  }

  const text = String(bodyTextValue || '').replace(/\s+/gu, ' ').trim();
  if (text === '' || LOGIN_TEXT_SIGNALS.some((pattern) => pattern.test(text))) {
    return false;
  }
  const businessSignalCount = BUSINESS_TEXT_SIGNALS
    .reduce((count, pattern) => count + (pattern.test(text) ? 1 : 0), 0);
  return businessSignalCount >= 2
    || (/eBooking/iu.test(text) && businessSignalCount >= 1);
}
