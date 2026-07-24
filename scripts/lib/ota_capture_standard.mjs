import { createHash } from 'node:crypto';
import { findCtripEndpointByUrl } from './ctrip_capture_catalog.mjs';
import { otaSessionCookieInjectionDomains, sanitizeOtaObservedUrl } from './ota_session_probe.mjs';

export const PLATFORM_CONFIGS = {
  meituan: {
    label: 'Meituan eBooking',
    profilePrefix: 'meituan_profile',
    defaultSections: ['traffic', 'orders'],
    fullSections: ['traffic', 'orders', 'ads', 'reviews'],
    allowedSections: ['traffic', 'order_flow', 'ads', 'orders', 'reviews'],
    cookieDomains: otaSessionCookieInjectionDomains('meituan'),
    sectionAliases: {
      business: 'traffic',
      businessdata: 'traffic',
      business_data: 'traffic',
      overview: 'traffic',
      peerrank: 'traffic',
      peer_rank: 'traffic',
      competitorrank: 'traffic',
      competitor_rank: 'traffic',
      ranking: 'traffic',
      rankings: 'traffic',
      traffic: 'traffic',
      flow: 'traffic',
      flowdata: 'traffic',
      flow_data: 'traffic',
      flowanalysis: 'traffic',
      flow_analysis: 'traffic',
      trafficanalysis: 'traffic',
      traffic_analysis: 'traffic',
      flowconversion: 'traffic',
      flowtrend: 'traffic',
      flowtrenddetail: 'traffic',
      flowforecast: 'traffic',
      flow_forecast: 'traffic',
      trafficforecast: 'traffic',
      traffic_forecast: 'traffic',
      orderflow: 'order_flow',
      order_flow: 'order_flow',
      orderloss: 'order_flow',
      order_loss: 'order_flow',
      lossorder: 'order_flow',
      loss_order: 'order_flow',
      realtime: 'traffic',
      realtime_snapshot: 'traffic',
      searchkeyword: 'traffic',
      searchkeywords: 'traffic',
      search_keyword: 'traffic',
      search_keywords: 'traffic',
      keyword: 'traffic',
      keywords: 'traffic',
      roomtype: 'traffic',
      roomtypes: 'traffic',
      room_type: 'traffic',
      room_types: 'traffic',
      product: 'traffic',
      products: 'traffic',
      ads: 'ads',
      ad: 'ads',
      advertising: 'ads',
      order: 'orders',
      orders: 'orders',
      review: 'reviews',
      reviews: 'reviews',
      comment: 'reviews',
      comments: 'reviews',
      reviewdata: 'reviews',
      review_data: 'reviews',
    },
    blockedResponseRules: [],
    responseRules: [
      { section: 'order_flow', keywords: ['/peerrank/order/loss/query'] },
      { section: 'traffic', keywords: ['businessdata', 'weighttraffic', 'traffic', 'peertrends', 'peer/rank', 'flowconversion', 'flowtrend', 'flowtrenddetail', 'flowforecast', 'searchkeyword', 'search-keyword', 'roomtype', 'room-type'] },
      { section: 'ads', keywords: ['cureshops'] },
      { section: 'orders', keywords: ['/api/v1/ebooking/orders', '/order/unhandled/count', '/order-eb/'] },
      { section: 'reviews', keywords: ['querygeneralcommentinfo', 'commentsinfo', 'comments/statistics', 'comment-manage'] },
    ],
  },
  ctrip: {
    label: 'Ctrip eBooking',
    profilePrefix: 'ctrip_profile',
    defaultSections: ['business', 'traffic'],
    allowedSections: ['business', 'traffic', 'ads', 'orders', 'quality', 'search_keyword', 'reviews'],
    cookieDomains: otaSessionCookieInjectionDomains('ctrip'),
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
      cpc: 'ads',
      pyramid: 'ads',
      order: 'orders',
      orders: 'orders',
      psi: 'quality',
      service: 'quality',
      quality: 'quality',
      bpi: 'quality',
      competitor: 'business',
      loss: 'business',
      user: 'business',
      user_behavior: 'business',
      im: 'quality',
      calendar: 'business',
      hot_calendar: 'business',
      biztravel: 'business',
      business_travel: 'business',
      room: 'business',
      room_type: 'business',
      keyword: 'search_keyword',
      keywords: 'search_keyword',
      review: 'reviews',
      reviews: 'reviews',
      comment: 'reviews',
      comments: 'reviews',
      comment_review: 'reviews',
    },
    blockedResponseRules: [],
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
      { section: 'reviews', keywords: ['getcommentnumv2', 'getcommentlist', 'gethotelrating', 'commentlist', 'comment/', '/comment'] },
    ],
  },
};

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
  if (!raw || raw === '*') {
    return [...config.defaultSections];
  }

  const selected = [];
  const invalid = [];
  for (const item of raw.split(/[,\s]+/)) {
    const token = item.trim();
    if (!token) {
      continue;
    }
    if (token === 'all' && !Array.isArray(config.fullSections)) {
      for (const section of config.defaultSections) {
        if (!selected.includes(section)) {
          selected.push(section);
        }
      }
      continue;
    }
    if (['all', 'full', 'complete'].includes(token) && Array.isArray(config.fullSections)) {
      for (const section of config.fullSections) {
        if (!selected.includes(section)) {
          selected.push(section);
        }
      }
      continue;
    }
    if (['default', 'core'].includes(token)) {
      for (const section of config.defaultSections) {
        if (!selected.includes(section)) {
          selected.push(section);
        }
      }
      continue;
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

  if (platformKey === 'meituan' && isMeituanOrderResponseUrl(value)) {
    if (!['xhr', 'fetch'].includes(resourceType) || !contentType.includes('json')) {
      return {
        capture: false,
        platform: platformKey,
        section: 'orders',
        reason: 'order_json_xhr_required',
      };
    }
  }

  const rules = PLATFORM_CONFIGS[platformKey].responseRules;
  for (const rule of PLATFORM_CONFIGS[platformKey].blockedResponseRules || []) {
    if (rule.keywords.some((keyword) => value.includes(keyword))) {
      return { capture: false, platform: platformKey, section: rule.section, reason: 'response_blocked_by_policy' };
    }
  }

  if (platformKey === 'ctrip') {
    const endpoint = findCtripEndpointByUrl(value);
    if (endpoint) {
      const section = endpoint.section === 'comment_review'
        ? 'reviews'
        : standardSectionName(endpoint.dataType);
      return {
        capture: true,
        platform: platformKey,
        section,
        capture_section: endpoint.section,
        endpoint_id: endpoint.id,
        reason: 'url_keyword',
      };
    }
  }

  for (const rule of rules) {
    if (rule.keywords.some((keyword) => value.includes(keyword))) {
      return { capture: true, platform: platformKey, section: rule.section, reason: 'url_keyword' };
    }
  }

  return { capture: false, platform: platformKey, section: '', reason: 'unmatched_url' };
}

function isMeituanOrderResponseUrl(value) {
  return ['/api/v1/ebooking/orders', '/order/unhandled/count', '/order-eb/']
    .some(keyword => String(value || '').includes(keyword));
}

function standardSectionName(dataType) {
  const value = String(dataType || '').trim().toLowerCase();
  if (value === 'advertising') {
    return 'ads';
  }
  if (value === 'review') {
    return 'reviews';
  }
  return value || 'business';
}

export function sanitizeOtaPayloadForStorage(value, section = '') {
  if (!value || typeof value !== 'object') {
    const urlValue = sanitizeOtaUrlValueForStorage(value);
    return urlValue ? urlValue.value : value;
  }
  const normalizedSection = normalizeCaptureSectionName(section);
  if (normalizedSection === 'reviews') {
    return sanitizeReviewPayloadNode(value);
  }
  const orderContext = normalizedSection === 'orders';
  return sanitizePayloadNode(value, orderContext);
}

export function sanitizeOtaUrlValueForStorage(value, existingHash = '') {
  if (typeof value !== 'string') {
    return null;
  }
  const raw = value.trim();
  if (!raw) {
    return null;
  }

  let safeValue = '';
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(raw)) {
    safeValue = sanitizeOtaObservedUrl(raw);
  } else if (/^(?:\/(?!\/)|\.\.?\/)/.test(raw)) {
    const boundary = [raw.indexOf('?'), raw.indexOf('#')]
      .filter((index) => index >= 0)
      .sort((left, right) => left - right)[0] ?? raw.length;
    safeValue = raw.slice(0, boundary) || '/';
  } else {
    return null;
  }

  if (!safeValue) {
    return null;
  }
  const suppliedHash = String(existingHash || '').trim().toLowerCase();
  const valueUrlHash = raw === safeValue && /^[a-f0-9]{64}$/.test(suppliedHash)
    ? suppliedHash
    : sha256Hex(raw);
  return {
    value: safeValue,
    value_url_hash: valueUrlHash,
  };
}

export function extractOtaRequestDateEvidence({ url = '', payload = '' } = {}) {
  const candidates = [];
  collectRequestQueryDateCandidates(url, candidates);
  collectRequestPayloadDateCandidates(payload, candidates);
  const uniqueDates = Array.from(new Set(candidates.map(item => item.date).filter(Boolean)));
  if (uniqueDates.length !== 1) {
    return { date: '', date_source: '' };
  }
  const first = candidates.find(item => item.date === uniqueDates[0]) || {};
  return {
    date: uniqueDates[0],
    date_source: first.source || 'request',
  };
}

export function buildOtaCaptureEvidence(platform, options = {}) {
  const platformKey = String(platform || '').trim().toLowerCase() || 'ota';
  const section = safeEvidenceText(options.section || '');
  const sourcePath = safeEvidenceText(options.sourcePath || '');
  const captureSource = safeEvidenceText(options.captureSource || '');
  const url = String(options.url || '').trim();
  const evidence = {};

  if (sourcePath) {
    evidence.source_path = sourcePath;
  }
  if (captureSource) {
    evidence.capture_source = captureSource;
  }
  if (section) {
    evidence.section = section;
  }
  if (url) {
    evidence.source_url_hash = sha256Hex(url);
  }

  const traceBasis = {
    platform: platformKey,
    section,
    source_path: sourcePath,
    capture_source: captureSource,
    source_url_hash: evidence.source_url_hash || '',
  };
  if (Object.values(traceBasis).some(Boolean)) {
    evidence.source_trace_id = `${platformKey}:${sha256Hex(JSON.stringify(traceBasis))}`;
  }

  return evidence;
}

export function attachOtaCaptureEvidence(row, platform, options = {}) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) {
    return row;
  }
  const existingEvidence = row.capture_evidence && typeof row.capture_evidence === 'object' && !Array.isArray(row.capture_evidence)
    ? row.capture_evidence
    : {};
  const sourcePath = options.sourcePath || row._source_path || row.source_path || existingEvidence.source_path || '';
  const captureSource = options.captureSource || row._capture_source || existingEvidence.capture_source || '';
  const sourceUrl = options.url
    || row._source_url
    || row.source_url
    || row.url
    || existingEvidence.source_url
    || existingEvidence._source_url
    || existingEvidence.url
    || '';
  const evidence = {
    ...existingEvidence,
    ...buildOtaCaptureEvidence(platform, {
      ...options,
      url: sourceUrl,
      sourcePath,
      captureSource,
    }),
  };
  delete evidence._source_url;
  delete evidence.source_url;
  delete evidence.url;
  const next = {
    ...row,
    capture_evidence: evidence,
  };
  if (!next.source_trace_id && evidence.source_trace_id) {
    next.source_trace_id = evidence.source_trace_id;
  }
  if (!next.source_url_hash && evidence.source_url_hash) {
    next.source_url_hash = evidence.source_url_hash;
  }
  delete next._source_url;
  delete next.source_url;
  delete next.url;
  return next;
}

function sha256Hex(value) {
  return createHash('sha256').update(String(value)).digest('hex');
}

function collectRequestQueryDateCandidates(url, candidates) {
  const text = String(url || '').trim();
  if (!text) {
    return;
  }
  try {
    const parsed = new URL(text);
    for (const [key, value] of parsed.searchParams.entries()) {
      const sourcePath = `request.query.${safeSourcePathKey(key)}`;
      addRequestDateCandidate(candidates, key, value, sourcePath);
      collectNestedRequestDateCandidatesFromText(value, sourcePath, candidates);
    }
  } catch {
    const query = text.includes('?') ? text.slice(text.indexOf('?') + 1) : text;
    for (const [key, value] of new URLSearchParams(query).entries()) {
      const sourcePath = `request.query.${safeSourcePathKey(key)}`;
      addRequestDateCandidate(candidates, key, value, sourcePath);
      collectNestedRequestDateCandidatesFromText(value, sourcePath, candidates);
    }
  }
}

function collectRequestPayloadDateCandidates(payload, candidates) {
  const text = String(payload || '').trim();
  if (!text) {
    return;
  }
  const parsed = parseRequestPayloadForEvidence(text);
  if (parsed && typeof parsed === 'object') {
    collectRequestDateCandidates(parsed, 'request.payload', candidates, 0);
    return;
  }
  for (const [key, value] of new URLSearchParams(text).entries()) {
    const sourcePath = `request.payload.${safeSourcePathKey(key)}`;
    addRequestDateCandidate(candidates, key, value, sourcePath);
    collectNestedRequestDateCandidatesFromText(value, sourcePath, candidates);
  }
}

function parseRequestPayloadForEvidence(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

function collectNestedRequestDateCandidatesFromText(text, sourcePath, candidates) {
  const parsed = parseRequestPayloadForEvidence(String(text || '').trim());
  if (parsed && typeof parsed === 'object') {
    collectRequestDateCandidates(parsed, sourcePath, candidates, 0);
  }
}

function collectRequestDateCandidates(value, sourcePath, candidates, depth) {
  if (depth > 6 || value === null || value === undefined) {
    return;
  }
  if (Array.isArray(value)) {
    value.slice(0, 80).forEach((item, index) => collectRequestDateCandidates(item, `${sourcePath}.${index}`, candidates, depth + 1));
    return;
  }
  if (typeof value !== 'object') {
    return;
  }
  for (const [key, item] of Object.entries(value)) {
    const keyPath = `${sourcePath}.${safeSourcePathKey(key)}`;
    addRequestDateCandidate(candidates, key, item, keyPath);
    collectRequestDateCandidates(item, keyPath, candidates, depth + 1);
  }
}

function addRequestDateCandidate(candidates, key, value, source) {
  if (!isRequestDateKey(key)) {
    return;
  }
  const date = normalizeRequestEvidenceDate(value);
  if (!date) {
    return;
  }
  candidates.push({ date, source });
}

function isRequestDateKey(key) {
  const normalized = String(key || '').replace(/[^a-zA-Z0-9]+/g, '').toLowerCase();
  return [
    'date',
    'day',
    'datadate',
    'statdate',
    'bizdate',
    'businessdate',
    'reportdate',
    'targetdate',
    'querydate',
    'startdate',
    'enddate',
    'begindate',
    'fromdate',
    'todate',
    'starttime',
    'endtime',
  ].includes(normalized);
}

function normalizeRequestEvidenceDate(value) {
  if (value === null || value === undefined) {
    return '';
  }
  const text = String(value).trim();
  let match = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
  if (!match) {
    match = text.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);
  }
  if (!match) {
    match = text.match(/^(\d{4})(\d{2})(\d{2})$/);
  }
  if (!match && /^\d{10,13}$/.test(text)) {
    const epoch = Number(text) * (text.length === 10 ? 1000 : 1);
    if (Number.isFinite(epoch)) {
      const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
      }).formatToParts(new Date(epoch));
      const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
      if (values.year && values.month && values.day) {
        return `${values.year}-${values.month}-${values.day}`;
      }
    }
  }
  if (!match) {
    return '';
  }
  return `${match[1]}-${String(match[2]).padStart(2, '0')}-${String(match[3]).padStart(2, '0')}`;
}

function safeSourcePathKey(key) {
  return String(key || '').replace(/[^a-zA-Z0-9_:-]+/g, '_').slice(0, 80) || 'key';
}

function safeEvidenceText(value) {
  const text = String(value || '').trim();
  if (!text || /\b(cookie|authorization|bearer|token|password|secret)\b/i.test(text)) {
    return '';
  }
  return text.slice(0, 300);
}

function sanitizePayloadNode(value, orderContext) {
  if (Array.isArray(value)) {
    return value.map((item) => {
      const urlValue = sanitizeOtaUrlValueForStorage(item);
      return urlValue ? urlValue.value : sanitizePayloadNode(item, orderContext);
    });
  }
  if (!value || typeof value !== 'object') {
    return value;
  }

  const result = {};
  for (const [key, item] of Object.entries(value)) {
    if (isSensitiveKey(key)) {
      continue;
    }

    if (key.endsWith('_url_hash')) {
      const sourceKey = key.slice(0, -'_url_hash'.length);
      if (sanitizeOtaUrlValueForStorage(value[sourceKey])) {
        continue;
      }
    }

    const childOrderContext = orderContext || isOrderContainerKey(key);
    if (item && typeof item === 'object') {
      result[key] = sanitizePayloadNode(item, childOrderContext);
      continue;
    }

    const urlValue = sanitizeOtaUrlValueForStorage(item, value[`${key}_url_hash`]);
    if (urlValue) {
      result[key] = urlValue.value;
      result[`${key}_url_hash`] = urlValue.value_url_hash;
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

function sanitizeReviewPayloadNode(value) {
  if (Array.isArray(value)) {
    return reviewListAggregate(value);
  }
  if (!value || typeof value !== 'object') {
    return value;
  }

  const result = {};
  for (const [key, item] of Object.entries(value)) {
    if (isSensitiveKey(key) || isReviewBlockedKey(key)) {
      continue;
    }

    if (Array.isArray(item) && isReviewListKey(key)) {
      mergeReviewAggregate(result, reviewListAggregate(item));
      continue;
    }

    if (item && typeof item === 'object') {
      const sanitized = sanitizeReviewPayloadNode(item);
      if (hasSanitizedReviewValue(sanitized)) {
        result[key] = sanitized;
      }
      continue;
    }

    if (isReviewAllowedScalarKey(key)) {
      result[key] = item;
    }
  }
  return result;
}

function reviewListAggregate(rows) {
  const aggregate = {};
  const safeRows = rows.filter((row) => row && typeof row === 'object' && !Array.isArray(row));
  if (safeRows.length === 0) {
    return aggregate;
  }

  aggregate.commentCount = safeRows.length;

  const scores = [];
  let badByScore = 0;
  let explicitBadCount = null;
  const storeNames = new Set();
  const dates = new Set();
  const channels = new Set();

  for (const row of safeRows) {
    const score = reviewScore(firstReviewValue(row, ['score', 'commentScore', 'rating', 'rate', 'totalScore', 'overallScore', 'star']));
    if (score !== null && score > 0) {
      scores.push(score);
      if (score < 4) {
        badByScore += 1;
      }
    }

    const badCount = reviewInteger(firstReviewValue(row, ['badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount']));
    if (badCount !== null) {
      explicitBadCount = explicitBadCount === null ? badCount : Math.max(explicitBadCount, badCount);
    }

    addReviewText(storeNames, firstReviewValue(row, ['hotelName', 'masterHotelName', 'storeName', 'hotel_name']));
    addReviewText(dates, normalizeReviewDate(firstReviewValue(row, ['date', 'dataDate', 'statDate', 'commentTime', 'reviewTime', 'createTime', 'submitTime'])));
    addReviewText(channels, firstReviewValue(row, ['channel', 'channelName', 'platform', 'source', 'commentChannel', 'bizType']));
  }

  const storeName = singleSetValue(storeNames);
  if (storeName) {
    aggregate.hotelName = storeName;
  }
  const date = singleSetValue(dates);
  if (date) {
    aggregate.statDate = date;
  }
  const channel = singleSetValue(channels);
  if (channel) {
    aggregate.channelName = channel;
  }
  if (scores.length > 0) {
    aggregate.commentScore = Math.round((scores.reduce((sum, score) => sum + score, 0) / scores.length) * 10) / 10;
  }
  aggregate.badReviewCount = explicitBadCount !== null ? explicitBadCount : badByScore;

  return aggregate;
}

function mergeReviewAggregate(target, aggregate) {
  if (!aggregate || typeof aggregate !== 'object') {
    return;
  }
  for (const [key, value] of Object.entries(aggregate)) {
    if (value === null || value === undefined || value === '') {
      continue;
    }
    if (key === 'commentCount' && hasReviewCount(target)) {
      continue;
    }
    if (key === 'badReviewCount' && hasReviewBadCount(target)) {
      continue;
    }
    if (key === 'commentScore' && hasReviewScore(target)) {
      continue;
    }
    target[key] = value;
  }
}

function hasReviewCount(value) {
  return ['commentCount', 'commentsCount', 'reviewCount', 'totalCommentCount', 'totalCount', 'allCount']
    .some((key) => value[key] !== undefined && value[key] !== null && value[key] !== '');
}

function hasReviewBadCount(value) {
  return ['badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount']
    .some((key) => value[key] !== undefined && value[key] !== null && value[key] !== '');
}

function hasReviewScore(value) {
  return ['score', 'commentScore', 'rating', 'rate', 'totalScore', 'overallScore', 'star', 'ratingall', 'hotelRating', 'ctripRatingall']
    .some((key) => value[key] !== undefined && value[key] !== null && value[key] !== '');
}

function firstReviewValue(row, keys) {
  for (const key of keys) {
    if (row[key] !== undefined && row[key] !== null && row[key] !== '') {
      return row[key];
    }
  }
  return null;
}

function addReviewText(target, value) {
  const text = String(value ?? '').trim();
  if (text) {
    target.add(text);
  }
}

function singleSetValue(values) {
  return values.size === 1 ? [...values][0] : '';
}

function reviewScore(value) {
  if (typeof value === 'string') {
    value = value.replace(/[,％%]/g, '').trim();
  }
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
    return null;
  }
  const number = Number(value);
  if (number > 5 && number <= 50) {
    return Math.round((number / 10) * 10) / 10;
  }
  if (number > 50 && number <= 100) {
    return Math.round((number / 20) * 10) / 10;
  }
  return Math.round(number * 10) / 10;
}

function reviewInteger(value) {
  if (typeof value === 'string') {
    value = value.replace(/,/g, '').trim();
  }
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
    return null;
  }
  return Math.max(0, Math.round(Number(value)));
}

function normalizeReviewDate(value) {
  const text = String(value ?? '').trim();
  const match = text.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/);
  if (!match) {
    return '';
  }
  return `${match[1]}-${match[2].padStart(2, '0')}-${match[3].padStart(2, '0')}`;
}

function hasSanitizedReviewValue(value) {
  if (Array.isArray(value)) {
    return value.length > 0;
  }
  if (value && typeof value === 'object') {
    return Object.keys(value).length > 0;
  }
  return value !== null && value !== undefined && value !== '';
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
  if (value === 'order') {
    return 'orders';
  }
  if (['review', 'comment', 'comments'].includes(value)) {
    return 'reviews';
  }
  return value;
}

function isSensitiveKey(key) {
  return /cookie|authorization|token|api[-_]?key|secret|password|spider(?:token|key|_key)|fingerprint|mtgsig/i.test(String(key || ''));
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

function isReviewListKey(key) {
  const normalized = String(key || '').replace(/[^a-zA-Z0-9]+/g, '').toLowerCase();
  return ['commentlist', 'comments', 'reviews', 'rows', 'list'].includes(normalized);
}

function isReviewBlockedKey(key) {
  const normalized = String(key || '').replace(/[^a-zA-Z0-9]+/g, '').toLowerCase();
  return [
    'content',
    'comment',
    'commentcontent',
    'commenttext',
    'reviewcontent',
    'reviewtext',
    'contenttext',
    'text',
    'reply',
    'replycontent',
    'merchantreply',
    'bizreply',
    'hotelreply',
    'replytext',
    'username',
    'nickname',
    'customername',
    'guestname',
    'travellername',
    'passengername',
    'roomtype',
    'roomname',
    'productname',
    'rateplanname',
    'orderid',
    'orderno',
    'ordernumber',
    'commentid',
    'reviewid',
    'id',
    'userid',
    'memberid',
    'uid',
    'avatar',
    'photo',
    'tags',
    'taglist',
    'labellist',
    'labels',
    'tagnames',
  ].includes(normalized);
}

function isReviewAllowedScalarKey(key) {
  const normalized = String(key || '').replace(/[^a-zA-Z0-9]+/g, '').toLowerCase();
  return [
    'rcode',
    'code',
    'status',
    'success',
    'hotelid',
    'masterhotelid',
    'hotelname',
    'masterhotelname',
    'storename',
    'date',
    'datadate',
    'statdate',
    'commenttime',
    'reviewtime',
    'createtime',
    'submittime',
    'channel',
    'channelname',
    'platform',
    'source',
    'commentchannel',
    'biztype',
    'score',
    'commentscore',
    'rating',
    'rate',
    'totalscore',
    'overallscore',
    'star',
    'ratingall',
    'hotelrating',
    'ctripratingall',
    'commentcount',
    'commentscount',
    'reviewcount',
    'totalcommentcount',
    'totalcount',
    'allcount',
    'badreviewcount',
    'negativecommentcount',
    'negativecount',
    'badcount',
    'lowscorecount',
  ].includes(normalized);
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
