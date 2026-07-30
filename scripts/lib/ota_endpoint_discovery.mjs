import { createHash } from 'node:crypto';

export const OTA_ENDPOINT_DISCOVERY_SCHEMA_VERSION = 1;

const PLATFORM_HOST_SUFFIXES = Object.freeze({
  ctrip: Object.freeze([
    'ctrip.com',
    'trip.com',
    'ctripbiz.cn',
    'ctripbiz.com',
  ]),
  meituan: Object.freeze([
    'meituan.com',
    'dianping.com',
    'sankuai.com',
  ]),
});

const SECTION_RULES = Object.freeze({
  meituan: Object.freeze([
    sectionRule('order_flow', [
      'orderloss', 'lossorder', 'lossreason', 'losstype', 'orderflow', 'flowdirection',
      'infloworder', 'outfloworder',
    ], ['orderloss', 'lossorder', 'lossreason', 'orderflow'], [
      'order/loss', 'orderloss', 'lossorder', 'order-flow',
    ]),
    sectionRule('reviews', [
      'commentlist', 'commentid', 'commentcount', 'commentscore', 'commentcontent',
      'reviewlist', 'reviewid', 'reviewcount', 'reviewscore', 'reviewcontent',
      'negativecommentcount', 'badreviewcount', 'rating',
    ], [
      'commentlist', 'commentid', 'commentcontent', 'reviewlist', 'reviewid', 'reviewcontent',
    ], ['comment', 'review', 'rating']),
    sectionRule('orders', [
      'orderlist', 'orderid', 'orderno', 'ordercount', 'bookingid', 'bookingno',
      'bookordernum', 'roomnights', 'checkindate', 'checkoutdate', 'purchasetime',
      'arrivaldate', 'departuredate',
    ], [
      'orderlist', 'orderid', 'orderno', 'bookingid', 'bookingno',
    ], ['order', 'booking', 'reservation']),
    sectionRule('room_types', [
      'roomtypes', 'roomtypelist', 'roomtypeid', 'roomtypename', 'rateplan',
      'rateplanid', 'productlist', 'roominventory', 'inventorycount',
    ], [
      'roomtypes', 'roomtypelist', 'roomtypeid', 'rateplanid',
    ], ['roomtype', 'room-type', 'rateplan', 'product']),
    sectionRule('ads', [
      'campaignlist', 'campaignid', 'campaignname', 'cureshops', 'bidprice',
      'adcost', 'clickcost', 'impressioncount', 'promotionlist', 'promotionid',
      'advertisingcost',
    ], [
      'campaignlist', 'campaignid', 'cureshops', 'bidprice', 'adcost',
    ], ['campaign', 'promotion', 'advert', 'cureshops', 'cpc']),
    sectionRule('traffic', [
      'listexposure', 'detailexposure', 'detailvisitor', 'visitorcount',
      'weighttraffic', 'flowconversion', 'flowtrend', 'flowforecast',
      'peerrank', 'peertrends', 'searchkeywords', 'searchkeyword',
      'exposurecount', 'pageview', 'uniquevisitor', 'conversionrate',
      'clickcount', 'trafficdata',
    ], [
      'listexposure', 'detailexposure', 'weighttraffic', 'flowconversion',
      'flowforecast', 'peerrank', 'searchkeywords', 'trafficdata',
    ], ['traffic', 'businessdata', 'flow', 'peer/rank', 'peertrends', 'searchkeyword']),
  ]),
  ctrip: Object.freeze([
    sectionRule('reviews', [
      'commentlist', 'commentid', 'commentcount', 'commentscore', 'commentcontent',
      'reviewlist', 'reviewid', 'reviewcount', 'reviewscore', 'reviewcontent',
      'negativecommentcount', 'badreviewcount', 'hotelrating',
    ], [
      'commentlist', 'commentid', 'commentcontent', 'reviewlist', 'reviewid', 'reviewcontent',
    ], ['comment', 'review', 'rating']),
    sectionRule('orders', [
      'orderlist', 'orderid', 'orderno', 'ordercount', 'bookingid', 'bookingno',
      'bookordernum', 'roomnights', 'checkindate', 'checkoutdate', 'purchasetime',
    ], [
      'orderlist', 'orderid', 'orderno', 'bookingid', 'bookingno',
    ], ['order', 'booking', 'reservation']),
    sectionRule('ads', [
      'campaignlist', 'campaignid', 'campaignname', 'bidprice', 'adcost',
      'clickcost', 'impressioncount', 'promotionlist', 'promotionid',
      'recommendbidprice', 'pyramidad',
    ], [
      'campaignlist', 'campaignid', 'bidprice', 'adcost', 'pyramidad',
    ], ['campaign', 'promotion', 'advert', 'pyramidad', 'cpc']),
    sectionRule('quality', [
      'qualityscore', 'servicescore', 'complaintcount', 'defectcount',
      'psilevel', 'psiscore', 'bpiscore', 'servicequantity',
    ], [
      'qualityscore', 'servicescore', 'psilevel', 'psiscore', 'bpiscore',
    ], ['quality', 'service', 'psi', 'bpi']),
    sectionRule('search_keyword', [
      'searchkeywords', 'searchkeyword', 'keywordrank', 'keywordtraffic',
      'keywordconversion', 'hotkeywords',
    ], [
      'searchkeywords', 'keywordrank', 'keywordtraffic',
    ], ['searchkeyword', 'search-keyword', 'keyword']),
    sectionRule('traffic', [
      'listexposure', 'detailexposure', 'detailvisitor', 'visitorcount',
      'flowrate', 'flowconversion', 'flowtrend', 'flowforecast',
      'trafficrank', 'competitorrank', 'exposurecount', 'pageview',
      'uniquevisitor', 'conversionrate', 'trafficdata',
    ], [
      'listexposure', 'detailexposure', 'detailvisitor', 'flowrate',
      'flowconversion', 'trafficrank', 'trafficdata',
    ], ['traffic', 'flow', 'visitor', 'marketanalysis']),
    sectionRule('business', [
      'saleamount', 'totalamount', 'orderamount', 'revenue', 'bookordernum',
      'roomnights', 'averageprice', 'occupancyrate', 'adr', 'revpar',
      'marketoverview', 'dayreport', 'capacityoverview', 'competitorhotel',
      'competitorhotellist', 'competitorrank', 'competitorhoteltotal',
      'competitoravgvisitor', 'competitoravgprice', 'hotelrank',
    ], [
      'saleamount', 'revenue', 'bookordernum', 'roomnights', 'occupancyrate',
      'revpar', 'marketoverview', 'dayreport', 'capacityoverview',
      'competitorhotel', 'competitorhotellist', 'competitorrank',
    ], ['businessreport', 'marketoverview', 'dayreport', 'capacity']),
  ]),
});

const SENSITIVE_KEY = /(?:^|[_-])(?:access|auth|refresh)?token(?:$|[_-])|(?:^|[_-])(?:cookie|password|passwd|secret|signature|authorization|sessionid|ticket|apikey|api_key)(?:$|[_-])/i;
const LOGIN_PATH = /(^|\/)(?:login|passport|oauth|sso|account)(?:\/|$)/i;
const NON_BUSINESS_PATH = /(^|\/)(?:permissions?|menus?|notifications?|notices?|feature-?flags?|heartbeat|health|client-?logs?|tracking)(?:\/|$)/i;

export function classifyOtaEndpointDiscoveryResponse(platform, meta = {}) {
  const platformKey = normalizePlatform(platform);
  const status = Number(meta.status || 0);
  const resourceType = String(meta.resourceType || meta.resource_type || '').trim().toLowerCase();
  const contentType = String(meta.contentType || meta.content_type || '').trim().toLowerCase();
  if (!['xhr', 'fetch'].includes(resourceType)) {
    return { eligible: false, platform: platformKey, reason: 'non_ajax_resource' };
  }
  if (status < 200 || status >= 300) {
    return { eligible: false, platform: platformKey, reason: 'non_success_status' };
  }

  let parsed;
  try {
    parsed = new URL(String(meta.url || ''));
  } catch {
    return { eligible: false, platform: platformKey, reason: 'invalid_url' };
  }
  const host = parsed.hostname.toLowerCase();
  if (!isTrustedDiscoveryHost(platformKey, host)) {
    return { eligible: false, platform: platformKey, reason: 'untrusted_host' };
  }
  if (LOGIN_PATH.test(parsed.pathname)) {
    return { eligible: false, platform: platformKey, reason: 'authentication_route' };
  }
  if (NON_BUSINESS_PATH.test(parsed.pathname)) {
    return { eligible: false, platform: platformKey, reason: 'non_business_route' };
  }
  if (contentType && (
    contentType.includes('text/html')
    || contentType.includes('javascript')
    || contentType.includes('image/')
    || contentType.includes('font/')
    || contentType.includes('text/css')
  )) {
    return { eligible: false, platform: platformKey, reason: 'non_json_content_type' };
  }

  return {
    eligible: true,
    platform: platformKey,
    reason: contentType.includes('json') ? 'trusted_json_ajax' : 'trusted_ajax_body_check_required',
    host,
  };
}

export function buildOtaEndpointDiscoveryCandidate(platform, input = {}) {
  const eligibility = classifyOtaEndpointDiscoveryResponse(platform, input);
  if (!eligibility.eligible || !isStructuredPayload(input.body)) {
    return null;
  }

  const platformKey = eligibility.platform;
  const keyPaths = collectPayloadKeyPaths(input.body);
  const keyTokens = collectKeyTokens(keyPaths);
  const safeRoute = sanitizeDiscoveryRoute(input.url);
  const routeText = safeRoute.toLowerCase().replace(/[^a-z0-9]+/g, '');
  const scored = SECTION_RULES[platformKey]
    .map(rule => scoreSectionRule(rule, keyTokens, routeText))
    .sort((left, right) => right.score - left.score || right.key_hits.length - left.key_hits.length);
  const top = scored[0] || emptyScore();
  const second = scored[1] || emptyScore();
  if (top.key_hits.length === 0) {
    return null;
  }
  const margin = top.score - second.score;
  const ambiguous = top.score > 0 && margin < 2;
  const highConfidence = !ambiguous
    && top.distinctive_hits.length > 0
    && top.key_hits.length >= 2
    && top.score >= 8;
  const confidence = highConfidence ? 'high' : 'medium';
  const candidateSection = top.section;
  const reasonIds = [];
  if (top.key_hits.length > 0) reasonIds.push('payload_key_signature');
  if (top.distinctive_hits.length > 0) reasonIds.push('distinctive_payload_key');
  if (top.url_hits.length > 0) reasonIds.push('route_hint');
  if (ambiguous) reasonIds.push('ambiguous_section');

  return {
    schema_version: OTA_ENDPOINT_DISCOVERY_SCHEMA_VERSION,
    platform: platformKey,
    host: eligibility.host,
    safe_route: safeRoute,
    source_url_hash: sha256Hex(input.url || ''),
    method: safeMethod(input.method),
    status: Number(input.status || 0),
    request_type: String(input.resourceType || input.resource_type || '').trim().toLowerCase(),
    content_type: safeContentType(input.contentType || input.content_type || ''),
    candidate_section: candidateSection,
    confidence,
    score: top.score,
    score_margin: margin,
    auto_capture: highConfidence,
    reason_ids: reasonIds,
    matched_key_terms: top.key_hits.slice(0, 16),
    matched_route_terms: top.url_hits.slice(0, 8),
    top_level_keys: collectTopLevelKeys(input.body),
    sampled_key_paths: keyPaths.slice(0, 60),
    requested_sections: normalizeSectionList(input.requestedSections || input.requested_sections),
    observed_count: 1,
    sensitive_values_exposed: false,
  };
}

export function upsertOtaEndpointDiscoveryCandidate(items, candidate, limit = 80) {
  const current = Array.isArray(items) ? items : [];
  if (!candidate || typeof candidate !== 'object') {
    return current;
  }
  const key = `${candidate.platform || ''}:${candidate.source_url_hash || ''}:${candidate.candidate_section || ''}`;
  const index = current.findIndex(item => (
    `${item?.platform || ''}:${item?.source_url_hash || ''}:${item?.candidate_section || ''}` === key
  ));
  if (index >= 0) {
    const next = [...current];
    next[index] = {
      ...next[index],
      observed_count: Math.min(1000, Number(next[index]?.observed_count || 1) + 1),
    };
    return next;
  }
  if (current.length >= Math.max(1, Number(limit || 80))) {
    return current;
  }
  return [...current, candidate];
}

function sectionRule(section, keyTerms, distinctiveTerms, urlTerms) {
  return Object.freeze({
    section,
    keyTerms: Object.freeze([...keyTerms]),
    distinctiveTerms: Object.freeze([...distinctiveTerms]),
    urlTerms: Object.freeze([...urlTerms]),
  });
}

function scoreSectionRule(rule, keyTokens, routeText) {
  const keyHits = rule.keyTerms.filter(term => keyTokens.some(token => token.includes(term)));
  const distinctiveHits = rule.distinctiveTerms.filter(term => keyTokens.some(token => token.includes(term)));
  const urlHits = rule.urlTerms.filter(term => routeText.includes(normalizeKeyToken(term)));
  return {
    section: rule.section,
    key_hits: [...new Set(keyHits)],
    distinctive_hits: [...new Set(distinctiveHits)],
    url_hits: [...new Set(urlHits)],
    score: keyHits.length * 3 + distinctiveHits.length * 2 + urlHits.length,
  };
}

function emptyScore() {
  return {
    section: 'unknown',
    key_hits: [],
    distinctive_hits: [],
    url_hits: [],
    score: 0,
  };
}

function collectPayloadKeyPaths(value, prefix = '', depth = 0, output = new Set()) {
  if (depth > 6 || output.size >= 160 || !value || typeof value !== 'object') {
    return [...output];
  }
  if (Array.isArray(value)) {
    for (const item of value.slice(0, 4)) {
      collectPayloadKeyPaths(item, prefix ? `${prefix}[]` : '[]', depth + 1, output);
      if (output.size >= 160) break;
    }
    return [...output];
  }

  for (const rawKey of Object.keys(value).slice(0, 50)) {
    const key = safePayloadKey(rawKey);
    if (!key) continue;
    const path = prefix ? `${prefix}.${key}` : key;
    output.add(path);
    collectPayloadKeyPaths(value[rawKey], path, depth + 1, output);
    if (output.size >= 160) break;
  }
  return [...output];
}

function collectKeyTokens(paths) {
  const tokens = new Set();
  for (const path of paths) {
    for (const segment of String(path).split(/[.[\]]+/).filter(Boolean)) {
      const token = normalizeKeyToken(segment);
      if (token) tokens.add(token);
    }
  }
  return [...tokens];
}

function collectTopLevelKeys(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return [];
  }
  return Object.keys(value)
    .map(safePayloadKey)
    .filter(Boolean)
    .slice(0, 30);
}

function safePayloadKey(value) {
  const key = String(value || '').trim();
  const normalized = normalizeKeyToken(key);
  if (
    !key
    || key.length > 64
    || SENSITIVE_KEY.test(key)
    || /(?:access|auth|refresh)?token|cookie|password|passwd|secret|signature|authorization|sessionid|ticket|apikey/.test(normalized)
  ) {
    return '';
  }
  return /^[A-Za-z_][A-Za-z0-9_-]*$/.test(key) ? key : '';
}

function normalizeKeyToken(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function isStructuredPayload(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  if (Array.isArray(value)) {
    return value.some(item => item && typeof item === 'object');
  }
  const keys = Object.keys(value).filter(key => key !== '_raw_text');
  return keys.length > 0;
}

function isTrustedDiscoveryHost(platform, host) {
  return PLATFORM_HOST_SUFFIXES[platform].some(suffix => host === suffix || host.endsWith(`.${suffix}`));
}

function sanitizeDiscoveryRoute(value) {
  try {
    const parsed = new URL(String(value || ''));
    const segments = parsed.pathname.split('/').map(segment => {
      const decoded = safeDecode(segment);
      if (!decoded) return '';
      if (/^\d+$/.test(decoded)
        || /^[0-9a-f]{16,}$/i.test(decoded)
        || /^[0-9a-f]{8}-[0-9a-f-]{27,}$/i.test(decoded)
        || decoded.length >= 48
      ) {
        return ':id';
      }
      return /^[A-Za-z0-9._~-]{1,80}$/.test(decoded) ? decoded : ':redacted';
    });
    return `${parsed.hostname.toLowerCase()}${segments.join('/')}`.slice(0, 320);
  } catch {
    return '';
  }
}

function safeDecode(value) {
  try {
    return decodeURIComponent(String(value || ''));
  } catch {
    return String(value || '');
  }
}

function safeMethod(value) {
  const method = String(value || '').trim().toUpperCase();
  return /^(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)$/.test(method) ? method : '';
}

function safeContentType(value) {
  return String(value || '').split(';')[0].trim().toLowerCase().slice(0, 80);
}

function normalizeSectionList(value) {
  const items = Array.isArray(value) ? value : String(value || '').split(/[,\s]+/);
  return [...new Set(items
    .map(item => String(item || '').trim().toLowerCase())
    .filter(item => /^[a-z][a-z0-9_]{0,63}$/.test(item)))]
    .slice(0, 30);
}

function normalizePlatform(platform) {
  const key = String(platform || '').trim().toLowerCase();
  if (!Object.hasOwn(PLATFORM_HOST_SUFFIXES, key)) {
    throw new TypeError(`Unsupported OTA endpoint discovery platform: ${key || 'empty'}`);
  }
  return key;
}

function sha256Hex(value) {
  return createHash('sha256').update(String(value || '')).digest('hex');
}
