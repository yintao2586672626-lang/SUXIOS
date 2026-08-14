const BUSINESS_TEXT_SIGNALS = [
  /美团酒店商家/u,
  /工作台/u,
  /订单管理/u,
  /产品管理/u,
  /促销推广/u,
  /评价管理/u,
  /数据中心/u,
  /经营指导/u,
  /交易分析/u,
  /流量分析/u,
  /销售间夜/u,
  /入住间夜/u,
];

const LOGIN_TEXT_SIGNALS = [
  /欢迎使用美团\s*eBooking/iu,
  /美团员工登录/u,
  /(?:账号|密码|扫码|验证码|手机号码?)登录/u,
  /(?:重新|立即)登录/u,
  /登录(?:页面|账号|密码|已过期)/u,
  /(?:会话|登录状态)(?:已)?(?:过期|失效|无效)/u,
  /请输入(?:账号|密码|验证码)/u,
  /login\s*(?:required|expired)/iu,
  /captcha/iu,
];

export function isMeituanLoggedInPageState(urlValue, bodyTextValue) {
  let url;
  try {
    url = new URL(String(urlValue || ''));
  } catch {
    return false;
  }
  const trustedBusinessPage = (
    url.protocol === 'https:'
    && (
      (url.hostname === 'me.meituan.com' && url.pathname.startsWith('/ebooking/merchant'))
      || (url.hostname === 'eb.meituan.com' && url.pathname.startsWith('/newhb-sub-app'))
      || url.hostname === 'ebmidas.dianping.com'
    )
  );
  if (!trustedBusinessPage || /(?:login|passport|account)/iu.test(url.pathname)) {
    return false;
  }

  const text = String(bodyTextValue || '').replace(/\s+/gu, ' ').trim();
  if (text === '' || LOGIN_TEXT_SIGNALS.some((pattern) => pattern.test(text))) {
    return false;
  }
  return BUSINESS_TEXT_SIGNALS
    .reduce((count, pattern) => count + (pattern.test(text) ? 1 : 0), 0) >= 2;
}
