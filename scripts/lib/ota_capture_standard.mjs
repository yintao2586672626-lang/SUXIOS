import { createHash } from 'node:crypto';

export const PLATFORM_CONFIGS = {
  meituan: {
    label: 'Meituan eBooking',
    profilePrefix: 'meituan_profile',
    defaultSections: ['traffic', 'orders'],
    allowedSections: ['traffic', 'ads', 'orders'],
    cookieDomains: ['me.meituan.com', 'eb.meituan.com', '.meituan.com', '.dianping.com'],
    sectionAliases: {
      traffic: 'traffic',
      flow: 'traffic',
      ads: 'ads',
      ad: 'ads',
      advertising: 'ads',
      order: 'orders',
      orders: 'orders',
    },
    blockedResponseRules: [
      { section: 'reviews', keywords: ['querygeneralcommentinfo', 'commentsinfo', 'comments/statistics', 'comment-manage'] },
    ],
    responseRules: [
      { section: 'traffic', keywords: ['businessdata', 'weighttraffic', 'traffic', 'peertrends'] },
      { section: 'ads', keywords: ['cureshops'] },
      { section: 'orders', keywords: ['/orders/list', '/order/unhandled/count', '/order-eb/'] },
    ],
  },
  ctrip: {
    label: 'Ctrip eBooking',
    profilePrefix: 'ctrip_profile',
    defaultSections: ['business', 'traffic'],
    allowedSections: ['business', 'traffic', 'ads', 'orders'],
    cookieDomains: ['ebooking.ctrip.com', '.ctrip.com'],
    sectionAliases: {
      business: 'business',
      overview: 'business',
      report: 'business',
      traffic: 'traffic',
      flow: 'traffic',
      ads: 'ads',
      ad: 'ads',
      advertising: 'ads',
      campaign: 'ads',
      order: 'orders',
      orders: 'orders',
    },
    blockedResponseRules: [
      { section: 'reviews', keywords: ['getcommentlist', 'commentlist', 'comment/', '/comment', 'review'] },
    ],
    responseRules: [
      {
        section: 'business',
        keywords: [
          'getdayreportrealtimedate',
          'fetchmarketoverviewv2',
          'getdayreportflowcompete',
          'getdayreportserverquantity',
          'fetchcurrenthotelseqinfov1',
          'fetchvisitortitlev2',
          'fetchcapacityoverviewv4',
          'getdayreportcompetehotelreport',
          'getcompetehotelreportv1',
          'getlastweekreportv1',
          'gettrafficreportv1',
        ],
      },
      {
        section: 'traffic',
        keywords: [
          'queryscanflowdetailsv2',
          'queryflowtransfornew',
          'queryhomepagerealtimedata',
          'getflowdata',
          'gettrafficdata',
          'getstatdata',
        ],
      },
      {
        section: 'ads',
        keywords: [
          'querycampaignsummaryreport',
          'querycampaignreportlist',
          'queryhomecampaignlist',
          'queryrecommendbidprice',
          'querypyramidcpcdiagnosis',
          'pyramidad',
          'promotion',
        ],
      },
      { section: 'orders', keywords: ['queryorderlist', 'getorderlist', 'unprocessorderlist', 'orderdetail', '/order/'] },
    ],
  },
};

const DISABLED_COMMENT_SECTION_ALIASES = new Set(['review', 'reviews', 'comment', 'comments']);

export function normalizePlatform(platform) {
  const key = String(platform || '').trim().toLowerCase();
  if (!Object.prototype.hasOwnProperty.call(PLATFORM_CONFIGS, key)) {
    throw new Error(`Unsupported OTA platform: ${platform}`);
  }
  return key;
}

export function normalizeCaptureSections(platform, value = '') {
  const platformKey = normalizePlatform(platform);
  const config = PLATFORM_CONFIGS[platformKey];
  const raw = String(value || '').trim().toLowerCase();
  if (!raw || raw === 'all' || raw === '*') {
    return [...config.defaultSections];
  }

  const selected = [];
  const invalid = [];
  for (const item of raw.split(/[,\s]+/)) {
    const token = item.trim();
    if (!token) {
      continue;
    }
    if (DISABLED_COMMENT_SECTION_ALIASES.has(token)) {
      throw new Error('Comment/review capture is disabled by policy');
    }
    const section = config.sectionAliases[token] || '';
    if (!section || !config.allowedSections.includes(section)) {
      invalid.push(token);
      continue;
    }
    if (!selected.includes(section)) {
      selected.push(section);
    }
  }

  if (invalid.length > 0) {
    throw new Error(`Unsupported ${platformKey} capture section: ${invalid.join(', ')}`);
  }
  return selected.length > 0 ? selected : [...config.defaultSections];
}

export function parseCookieHeader(raw) {
  return String(raw || '')
    .split(';')
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => {
      const index = part.indexOf('=');
      if (index <= 0) {
        return null;
      }
      const name = part.slice(0, index).trim();
      const value = part.slice(index + 1).trim();
      if (!name || /[\s;]/.test(name)) {
        return null;
      }
      return { name, value };
    })
    .filter(Boolean);
}

export function buildCookieInjectionPlan(platform, rawCookie) {
  const platformKey = normalizePlatform(platform);
  const raw = String(rawCookie || '').trim();
  if (!raw) {
    return { attempted: false, domains: [], pairs: [], cookies: [] };
  }

  const pairs = parseCookieHeader(raw);
  if (pairs.length === 0) {
    throw new Error('Cookie injection failed: empty or invalid Cookie header');
  }

  const domains = PLATFORM_CONFIGS[platformKey].cookieDomains;
  const cookies = [];
  for (const domain of domains) {
    for (const pair of pairs) {
      cookies.push({
        name: pair.name,
        value: pair.value,
        domain,
        path: '/',
        secure: true,
        sameSite: 'Lax',
      });
    }
  }

  return { attempted: true, domains: [...domains], pairs, cookies };
}

export async function injectBrowserCookies(context, parsedArgs, platform) {
  const raw = await readCookieSource(parsedArgs);
  const plan = buildCookieInjectionPlan(platform, raw);
  if (!plan.attempted) {
    return { attempted: false, injected_count: 0, domains: [] };
  }

  await context.addCookies(plan.cookies);
  return { attempted: true, injected_count: plan.cookies.length, domains: plan.domains };
}

export async function readCookieSource(parsedArgs = {}) {
  const inline = String(parsedArgs.cookies || parsedArgs.cookie || '').trim();
  if (inline) {
    return inline;
  }

  const filePath = String(parsedArgs.cookiesFile || parsedArgs.cookieFile || '').trim();
  if (!filePath) {
    return '';
  }

  const { readFile } = await import('node:fs/promises');
  const { resolve } = await import('node:path');
  return (await readFile(resolve(filePath), 'utf8')).trim();
}

export function buildCapturePlan(options = {}) {
  const platform = normalizePlatform(options.platform);
  const config = PLATFORM_CONFIGS[platform];
  const profileId = String(options.profileId || options.storeId || options.poiId || options.hotelId || '').trim();
  const safeProfileId = safeName(profileId || 'default');
  const storageDir = String(options.profileDir || `storage/${config.profilePrefix}_${safeProfileId}`);

  return {
    platform,
    label: config.label,
    sections: normalizeCaptureSections(platform, options.sections || options.captureSections || options.only || ''),
    profile: {
      id: profileId,
      storageDir,
    },
    cookies: buildCookieInjectionPlan(platform, options.cookies || options.cookie || ''),
  };
}

export function classifyOtaResponse(platform, url, meta = {}) {
  const platformKey = normalizePlatform(platform);
  const value = String(url || '').toLowerCase();
  const resourceType = String(meta.resourceType || '').toLowerCase();
  const contentType = String(meta.contentType || '').toLowerCase();
  const status = Number(meta.status || 0);

  if (!value) {
    return { capture: false, platform: platformKey, section: '', reason: 'empty_url' };
  }
  if (status > 0 && (status < 200 || status >= 400)) {
    return { capture: false, platform: platformKey, section: '', reason: 'http_status' };
  }
  if (
    ['image', 'stylesheet', 'font', 'media'].includes(resourceType)
    || /^image\//.test(contentType)
    || /\.(?:png|jpe?g|gif|svg|webp|ico|woff2?|ttf|css)(?:\?|$)/i.test(value)
  ) {
    return { capture: false, platform: platformKey, section: '', reason: 'non_business_resource' };
  }

  const rules = PLATFORM_CONFIGS[platformKey].responseRules;
  for (const rule of PLATFORM_CONFIGS[platformKey].blockedResponseRules || []) {
    if (rule.keywords.some((keyword) => value.includes(keyword))) {
      return { capture: false, platform: platformKey, section: rule.section, reason: 'comment_collection_disabled' };
    }
  }

  for (const rule of rules) {
    if (rule.keywords.some((keyword) => value.includes(keyword))) {
      return { capture: true, platform: platformKey, section: rule.section, reason: 'url_keyword' };
    }
  }

  return { capture: false, platform: platformKey, section: '', reason: 'unmatched_url' };
}

export function sanitizeOtaPayloadForStorage(value, section = '') {
  if (!value || typeof value !== 'object') {
    return value;
  }
  const orderContext = normalizeCaptureSectionName(section) === 'orders';
  return sanitizePayloadNode(value, orderContext);
}

function sanitizePayloadNode(value, orderContext) {
  if (Array.isArray(value)) {
    return value.map((item) => sanitizePayloadNode(item, orderContext));
  }
  if (!value || typeof value !== 'object') {
    return value;
  }

  const result = {};
  for (const [key, item] of Object.entries(value)) {
    if (isSensitiveKey(key)) {
      continue;
    }

    const childOrderContext = orderContext || isOrderContainerKey(key);
    if (item && typeof item === 'object') {
      result[key] = sanitizePayloadNode(item, childOrderContext);
      continue;
    }

    if (childOrderContext || isOrderPiiKey(key)) {
      appendRedactedOrderField(result, key, item);
      continue;
    }

    result[key] = item;
  }
  return result;
}

function appendRedactedOrderField(target, key, value) {
  if (isOrderIdKey(key)) {
    const text = String(value ?? '').trim();
    if (text) {
      target[redactedFieldName(key, 'hash')] = createHash('sha256').update(`ota_order|${text}`).digest('hex');
    }
    return;
  }
  if (isPhoneKey(key)) {
    const masked = maskPhone(value);
    if (masked) {
      target[redactedFieldName(key, 'masked')] = masked;
    }
    return;
  }
  if (isGuestNameKey(key)) {
    const masked = maskName(value);
    if (masked) {
      target[redactedFieldName(key, 'masked')] = masked;
    }
    return;
  }
  if (isSensitiveOrderTextKey(key)) {
    return;
  }
  target[key] = value;
}

function normalizeCaptureSectionName(section) {
  const value = String(section || '').trim().toLowerCase();
  return value === 'order' ? 'orders' : value;
}

function isSensitiveKey(key) {
  return /cookie|authorization|token|api[-_]?key|secret|password|spidertoken|mtgsig/i.test(String(key || ''));
}

function isOrderContainerKey(key) {
  return /order[_-]?(list|rows|items|data|detail|details|info)|orders/i.test(String(key || ''));
}

function isOrderPiiKey(key) {
  return isOrderIdKey(key) || isPhoneKey(key) || isGuestNameKey(key) || isSensitiveOrderTextKey(key);
}

function isOrderIdKey(key) {
  return /^(order[_-]?(id|no|num|number|sn)|booking[_-]?(id|no|number))$/i.test(String(key || ''));
}

function isPhoneKey(key) {
  return /(phone|mobile|tel)$/i.test(String(key || ''));
}

function isGuestNameKey(key) {
  return /(guest|customer|contact|user|traveller|passenger)[_-]?name$/i.test(String(key || ''));
}

function isSensitiveOrderTextKey(key) {
  return /(certificate|credential|id[_-]?card|card[_-]?no|passport|remark|memo|note|address)/i.test(String(key || ''));
}

function redactedFieldName(key, suffix) {
  if (isOrderIdKey(key)) {
    return 'order_id_hash';
  }
  const name = String(key || 'field')
    .replace(/(?<!^)[A-Z]/g, '_$&')
    .replace(/[^a-zA-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .toLowerCase();
  return `${name || 'field'}_${suffix}`;
}

function maskPhone(value) {
  const digits = String(value ?? '').replace(/\D+/g, '');
  if (!digits) {
    return '';
  }
  if (digits.length <= 4) {
    return '*'.repeat(digits.length);
  }
  return `${'*'.repeat(digits.length - 4)}${digits.slice(-4)}`;
}

function maskName(value) {
  const text = String(value ?? '').trim();
  if (!text) {
    return '';
  }
  return `${text.slice(0, 1)}***`;
}

function safeName(value) {
  return String(value || 'default').replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 80);
}
