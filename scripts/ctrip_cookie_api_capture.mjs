import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import {
  buildCtripEndpointCandidates,
  buildCtripStandardRowsFromFacts,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
} from './lib/ctrip_capture_catalog.mjs';

function parseArgs(argv) {
  const args = {
    input: '',
    output: '',
    cookies: '',
    cookiesFile: '',
    url: '',
    method: '',
    payloadJson: '',
    dataDate: '',
    hotelId: '',
    hotelName: '',
    profileId: '',
  };
  for (const item of argv) {
    if (item.startsWith('--input=')) args.input = item.slice('--input='.length);
    else if (item.startsWith('--output=')) args.output = item.slice('--output='.length);
    else if (item.startsWith('--cookies=')) args.cookies = item.slice('--cookies='.length);
    else if (item.startsWith('--cookie=')) args.cookies = item.slice('--cookie='.length);
    else if (item.startsWith('--cookies-file=')) args.cookiesFile = item.slice('--cookies-file='.length);
    else if (item.startsWith('--cookie-file=')) args.cookiesFile = item.slice('--cookie-file='.length);
    else if (item.startsWith('--url=')) args.url = item.slice('--url='.length);
    else if (item.startsWith('--request-url=')) args.url = item.slice('--request-url='.length);
    else if (item.startsWith('--method=')) args.method = item.slice('--method='.length);
    else if (item.startsWith('--payload-json=')) args.payloadJson = item.slice('--payload-json='.length);
    else if (item.startsWith('--data-date=')) args.dataDate = item.slice('--data-date='.length);
    else if (item.startsWith('--hotel-id=')) args.hotelId = item.slice('--hotel-id='.length);
    else if (item.startsWith('--hotel-name=')) args.hotelName = item.slice('--hotel-name='.length);
    else if (item.startsWith('--profile-id=')) args.profileId = item.slice('--profile-id='.length);
  }
  return args;
}

function readJson(path) {
  return JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
}

function readCookie(args, config) {
  const inline = stringValue(args.cookies || config.cookies || config.cookie);
  if (inline !== '') {
    return inline;
  }
  const file = stringValue(args.cookiesFile || config.cookies_file || config.cookie_file);
  if (file !== '') {
    if (!existsSync(file)) {
      throw new Error(`cookies file not found: ${file}`);
    }
    return readFileSync(file, 'utf8').trim();
  }
  return '';
}

function buildConfig(args) {
  const config = args.input ? readJson(args.input) : {};
  if (args.url) {
    config.endpoints = [{
      request_url: args.url,
      method: args.method || 'GET',
      payload: parseMaybeJson(args.payloadJson),
    }];
  }
  if (args.dataDate) config.data_date = args.dataDate;
  if (args.hotelId) config.hotel_id = args.hotelId;
  if (args.hotelName) config.hotel_name = args.hotelName;
  if (args.profileId) config.profile_id = args.profileId;
  return config;
}

function parseMaybeJson(value) {
  const text = stringValue(value);
  if (text === '') {
    return {};
  }
  return JSON.parse(text);
}

const POST_PAYLOAD_DEFAULT_RULES = [
  {
    ids: ['sales_market_detail'],
    urlKeywords: ['querymarketdetails', 'querymarketdetail', 'queryhotroom', 'queryhotroomsv1', 'queryordertrend', 'queryordertrendv1', 'queryhoteloccupiedroomtrend', 'queryhoteloccupiedroomtrendv1', 'queryroomtensities', 'queryroomtensitiesv1', 'queryhoteltensities', 'queryhoteltensitiesv1', 'querymarketroomtensity', 'queryroomoccupiedtrend'],
    defaults: {
      hostType: 'HE',
      platform: 'EBK',
    },
    dateFields: ['startDate', 'endDate'],
  },
  {
    ids: ['traffic_scan_flow', 'traffic_flow_transform', 'traffic_order_overview', 'traffic_flow_source', 'traffic_city_keywords', 'traffic_search_details'],
    urlKeywords: ['queryscanflowdetailsv2', 'queryflowtransformnewv1', 'queryflowsource', 'querycityhotkeywords', 'querysearchflowdetails'],
    defaults: {
      hostType: 'HE',
      platform: 'EBK',
    },
    dateFields: ['startDate', 'endDate'],
  },
  {
    ids: ['ads_summary_report'],
    urlKeywords: ['querycampaignsummaryreport'],
    defaults: {
      hostType: 'HE',
      platform: 'EBK',
      pageIndex: 1,
      pageSize: 20,
    },
    dateFields: ['startDate', 'endDate'],
    includeHotelId: false,
  },
  {
    ids: ['user_profile_features', 'user_profile_dimensions'],
    urlKeywords: ['queryuser', 'getuserimagelist', 'getorderdistribution'],
    defaults: {
      hostType: 'HE',
      platform: 'EBK',
    },
    dateFields: ['startDate', 'endDate'],
    includeHotelId: true,
  },
  {
    ids: ['im_index', 'im_trend'],
    urlKeywords: ['getimindex', 'getimdatedistribute', 'getimsessiondistribute', 'getimorderconversionbyday', 'getimorderconversiondetail'],
    defaults: {
      hostType: 'HE',
      platform: 'EBK',
    },
    dateFields: ['startDate', 'endDate'],
    includeHotelId: true,
  },
  {
    ids: ['competitor_management', 'competitor_hotel_label', 'competitor_flow', 'competitor_service', 'competitor_flow_source', 'loss_order_summary', 'loss_compete_hotel', 'competitor_rank'],
    urlKeywords: ['getmanagementdata', 'getmasterhotellabel', 'getflowdata', 'getservicedata', 'getflowsource', 'gettripartiteorderloss', 'getlossordercompetehotel', 'getcompetingrank'],
    defaults: {
      hostType: 'HE',
      platform: 'EBK',
    },
    dateFields: ['startDate', 'endDate'],
    dateAliases: {
      startDate: ['beginDate'],
      endDate: ['endDate'],
    },
    includeHotelId: true,
  },
  {
    ids: ['biztravel_bpi_table'],
    urlKeywords: ['getbbkcomprehensivetable'],
    defaults: {
      date: '',
      hostType: 'HE',
    },
    includeDateAlias: ['date', 'reportDate'],
    includeHotelId: true,
  },
  {
    ids: ['biztravel_business_report', 'biztravel_competitor_report'],
    urlKeywords: ['datacenterbusinessreportdetail', 'datacentercomparisonreportdetail'],
    defaults: {
      hostType: 'HE',
      platform: 'BBK',
    },
    dateFields: ['startDate', 'endDate'],
    includeHotelId: true,
  },
];

function normalizeEndpoints(config, context = {}) {
  const raw = config.endpoints ?? config.requests ?? config.request_urls ?? config.requestUrls ?? [];
  const items = Array.isArray(raw) ? raw : [raw];
  return items.map((item) => {
    if (typeof item === 'string') {
      return { request_url: item, method: 'GET', payload: {} };
    }
    if (!item || typeof item !== 'object') {
      return null;
    }
    const normalized = {
      ...item,
      request_url: stringValue(item.request_url || item.requestUrl || item.url),
      method: stringValue(item.method || item.request_method || item.requestMethod || 'GET').toUpperCase(),
      payload: normalizeObject(item.payload ?? item.request_payload ?? item.requestPayload ?? item.params ?? {}),
      headers: normalizeObject(item.headers || item.request_headers || item.requestHeaders || {}),
    };
    normalized.payload = normalizeDefaultPostPayload(
      normalized,
      {
        hotelId: stringValue(context.hotelId),
        dataDate: stringValue(context.dataDate),
      },
      findCtripEndpointByUrl(normalized.request_url, { preferredSection: normalized.section || normalized.capture_section || '' }),
    );
    return normalized;
  }).filter((item) => item && item.request_url);
}

function isPlainObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function isEmptyPayload(payload) {
  return !isPlainObject(payload) || Object.keys(payload).length === 0;
}

function normalizeDefaultPostPayload(item, context = {}, endpoint = null) {
  const payload = normalizeObject(item.payload);
  if (item.method !== 'POST' || !isEmptyPayload(payload)) {
    return payload;
  }

  const matchedRule = findPostPayloadRule(item.request_url, endpoint);
  if (!matchedRule) {
    return payload;
  }

  const defaults = { ...matchedRule.defaults };
  const dateWindow = buildDateWindow(context.dataDate);
  if (dateWindow && matchedRule.dateFields && matchedRule.dateFields.length > 0) {
    for (const field of matchedRule.dateFields) {
      if (dateWindow[field]) {
        defaults[field] = dateWindow[field];
      }
    }
  }
  if (dateWindow && matchedRule.dateAliases) {
    for (const [sourceField, aliasFields] of Object.entries(matchedRule.dateAliases)) {
      const sourceValue = dateWindow[sourceField];
      if (!sourceValue) {
        continue;
      }
      for (const aliasField of [].concat(aliasFields || [])) {
        if (!aliasField) {
          continue;
        }
        defaults[aliasField] = sourceValue;
      }
    }
  }
  if (matchedRule.includeDateAlias) {
    const aliasDate = context.dataDate || dateWindow?.startDate || dateWindow?.endDate || '';
    if (aliasDate) {
      for (const field of matchedRule.includeDateAlias) {
        defaults[field] = aliasDate;
      }
    }
  }
  if (matchedRule.includeHotelId && context.hotelId) {
    defaults.hotelId = context.hotelId;
    defaults.nodeId = context.hotelId;
  }
  if (matchedRule.includePlatform !== false) {
    defaults.platform = defaults.platform || 'EBK';
  }

  return defaults;
}

function findPostPayloadRule(url, endpoint) {
  const lowerUrl = String(url || '').toLowerCase();
  return POST_PAYLOAD_DEFAULT_RULES.find((rule) => {
    if (rule.ids.includes(endpoint?.id)) {
      return true;
    }
    return (rule.urlKeywords || []).some((keyword) => lowerUrl.includes(keyword.toLowerCase()));
  }) || null;
}

function buildDateWindow(dataDate) {
  const date = stringValue(dataDate);
  if (!date) {
    return null;
  }
  return {
    startDate: date,
    endDate: date,
  };
}

function normalizeObject(value) {
  if (!value) {
    return {};
  }
  if (typeof value === 'string') {
    return parseMaybeJson(value);
  }
  return typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function isAllowedCtripUrl(url) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return false;
  }
  if (parsed.protocol !== 'https:') {
    return false;
  }
  const host = parsed.hostname.toLowerCase();
  return host === 'ebooking.ctrip.com'
    || host.endsWith('.ctrip.com')
    || host === 'bbk.ctripbiz.cn'
    || host.endsWith('.ctripbiz.cn')
    || host === 'bbk.ctripbiz.com'
    || host.endsWith('.ctripbiz.com');
}

function requestOrigin(url) {
  const parsed = new URL(url);
  if (parsed.hostname.includes('ctripbiz.cn')) return 'https://bbk.ctripbiz.cn';
  if (parsed.hostname.includes('ctripbiz.com')) return 'https://bbk.ctripbiz.com';
  return 'https://ebooking.ctrip.com';
}

function requestReferer(url, section = '') {
  if (url.includes('ctripbiz.cn')) return 'https://bbk.ctripbiz.cn/';
  if (url.includes('ctripbiz.com')) return 'https://bbk.ctripbiz.com/';
  if (section === 'ads_pyramid') return 'https://ebooking.ctrip.com/pyramidad/dataReport?micro=true';
  if (section === 'quality_psi') return 'https://ebooking.ctrip.com/psi/index?micro=true&fromType=menu&microJump=true';
  return 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true';
}

function redactHeaders(headers = {}) {
  const redacted = {};
  for (const [key, value] of Object.entries(headers)) {
    if (/cookie|authorization|token|sign|ticket/i.test(key)) {
      redacted[key] = '<redacted>';
    } else {
      redacted[key] = value;
    }
  }
  return redacted;
}

function buildRequest(endpoint, item, cookies) {
  const method = item.method === 'POST' ? 'POST' : 'GET';
  const headers = {
    Accept: 'application/json, text/javascript, */*; q=0.01',
    'Accept-Language': 'zh-CN,zh;q=0.9',
    'Content-Type': 'application/json',
    Origin: requestOrigin(item.request_url),
    Referer: requestReferer(item.request_url, endpoint?.section || item.section || ''),
    'X-Requested-With': 'XMLHttpRequest',
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ...item.headers,
    Cookie: cookies,
  };
  let requestUrl = item.request_url;
  const init = { method, headers, redirect: 'manual' };
  if (method === 'GET') {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(item.payload || {})) {
      if (value !== undefined && value !== null && value !== '') {
        query.set(key, String(value));
      }
    }
    const queryString = query.toString();
    if (queryString) {
      requestUrl += (requestUrl.includes('?') ? '&' : '?') + queryString;
    }
  } else {
    init.body = JSON.stringify(item.payload || {});
  }
  return { requestUrl, init };
}

function normalizeErrorResponse(status, bodyText) {
  if ([301, 302, 401, 403].includes(status)) {
    return 'cookie_or_permission_failed';
  }
  if (/^\s*<!DOCTYPE|^\s*<html/i.test(bodyText)) {
    return 'html_response_not_json';
  }
  return status === 200 ? 'json_parse_failed' : `http_${status}`;
}

function normalizeBusinessStatusValue(value) {
  return String(value ?? '').trim().toLowerCase();
}

function ctripBusinessError(body) {
  const ack = normalizeBusinessStatusValue(body?.ResponseStatus?.Ack);
  if (ack && !['success', 'ok'].includes(ack)) {
    const message = body?.ResponseStatus?.Errors?.[0]?.Message
      || body?.ResponseStatus?.Errors?.[0]?.message
      || body?.ResponseStatus?.Errors?.[0]?.Code
      || body?.ResponseStatus?.Errors?.[0]?.code
      || `ResponseStatus.Ack=${body.ResponseStatus.Ack}`;
    return { error: classifyCtripBusinessError(message), message: String(message) };
  }

  const rcode = body?.resStatus?.rcode ?? body?.resultStatus?.rcode ?? body?.status?.rcode;
  if (rcode !== undefined && rcode !== null && String(rcode) !== '' && Number(rcode) !== 0 && Number(rcode) !== 200) {
    const message = body?.resStatus?.rmsg
      || body?.resultStatus?.rmsg
      || body?.status?.rmsg
      || `rcode=${rcode}`;
    return { error: classifyCtripBusinessError(message), message: String(message), rcode };
  }

  const code = body?.code ?? body?.errorCode;
  if (code !== undefined && code !== null && String(code) !== '' && Number(code) !== 0 && Number(code) !== 200) {
    const message = body?.message || body?.msg || body?.errorMessage || `code=${code}`;
    return { error: classifyCtripBusinessError(message), message: String(message), code };
  }

  const messageText = normalizeBusinessStatusValue(body?.message || body?.msg || body?.errorMessage || body?.resStatus?.rmsg);
  if (/token_expired|login|未登录|登录|权限|forbidden|unauthorized|auth/.test(messageText)) {
    return { error: classifyCtripBusinessError(messageText), message: String(body?.message || body?.msg || body?.errorMessage || body?.resStatus?.rmsg || '') };
  }

  return null;
}

function classifyCtripBusinessError(message) {
  const text = normalizeBusinessStatusValue(message);
  if (/token_expired|no_token|login|未登录|登录|auth|unauthorized/.test(text)) {
    return 'cookie_or_permission_failed';
  }
  if (/permission|forbidden|权限|无权|拒绝/.test(text)) {
    return 'cookie_or_permission_failed';
  }
  return 'business_error';
}

export async function runCtripCookieApiCapture(config, options = {}) {
  const fetchImpl = options.fetchImpl || globalThis.fetch;
  if (typeof fetchImpl !== 'function') {
    throw new Error('fetch is not available in this Node runtime');
  }
  const cookies = stringValue(options.cookies || config.cookies || config.cookie);
  if (!cookies) {
    throw new Error('missing Ctrip Cookie');
  }
  const capturedAt = options.capturedAt || new Date().toISOString();
  const dataDate = stringValue(config.data_date || config.dataDate || options.dataDate);
  const hotelId = stringValue(config.hotel_id || config.hotelId || config.ctrip_hotel_id || config.profile_id || options.hotelId);
  const endpoints = normalizeEndpoints(config, { hotelId, dataDate });
  if (endpoints.length === 0) {
    throw new Error('missing endpoint request list');
  }
  const hotelName = stringValue(config.hotel_name || config.hotelName || options.hotelName);
  const profileId = stringValue(config.profile_id || config.profileId || hotelId || 'ctrip_cookie_api');
  const payload = {
    source: 'ctrip_cookie_api',
    profile_id: profileId,
    hotel_id: hotelId,
    hotel_name: hotelName,
    system_hotel_id: config.system_hotel_id || config.systemHotelId || options.systemHotelId || null,
    default_data_date: dataDate,
    captured_at: capturedAt,
    auth_status: { ok: false, status: 'unknown', message: 'No request completed yet.' },
    requested_sections: [],
    pages: [],
    xhr_urls: [],
    responses: [],
    unmatched_xhr_urls: [],
    endpoint_candidates: [],
    catalog_facts: [],
    standard_rows: [],
    errors: [],
  };

  for (const item of endpoints) {
    if (!isAllowedCtripUrl(item.request_url)) {
      payload.errors.push({ url: item.request_url, error: 'url_not_allowed' });
      continue;
    }
    const endpoint = findCtripEndpointByUrl(item.request_url, { preferredSection: item.section || item.capture_section || '' });
    const section = endpoint?.section || stringValue(item.section || item.capture_section);
    const { requestUrl, init } = buildRequest(endpoint, item, cookies);
    const xhrEntry = {
      url: requestUrl,
      request_type: init.method.toLowerCase(),
      endpoint_id: endpoint?.id || '',
      section,
      status: 0,
    };
    try {
      const response = await fetchImpl(requestUrl, init);
      const text = await response.text();
      xhrEntry.status = response.status;
      payload.xhr_urls.push(xhrEntry);
      if (!response.ok) {
        payload.errors.push({ url: requestUrl, status: response.status, error: normalizeErrorResponse(response.status, text) });
        continue;
      }
      if (/^\s*<!DOCTYPE|^\s*<html/i.test(text)) {
        payload.errors.push({ url: requestUrl, status: response.status, error: 'html_response_not_json' });
        continue;
      }
      let body;
      try {
        body = JSON.parse(text);
      } catch {
        payload.errors.push({ url: requestUrl, status: response.status, error: normalizeErrorResponse(response.status, text) });
        continue;
      }
      const responseEntry = {
        url: requestUrl,
        section,
        endpoint_id: endpoint?.id || '',
        endpoint_label: endpoint?.label || '',
        data_type: endpoint?.dataType || item.data_type || '',
        status: response.status,
        request_type: init.method.toLowerCase(),
        request_payload: item.payload || {},
        request_headers: redactHeaders(init.headers || {}),
        data: body,
      };
      payload.responses.push(responseEntry);
      const businessError = ctripBusinessError(body);
      if (businessError) {
        payload.errors.push({ url: requestUrl, status: response.status, ...businessError });
        continue;
      }
      if (endpoint) {
        const factContext = {
          endpoint,
          section: endpoint.section,
          dataType: endpoint.dataType,
          hotelId,
          hotelName,
          dataDate,
          capturedAt,
          url: requestUrl,
        };
        const facts = extractCtripCatalogFacts(body, factContext);
        const rows = buildCtripStandardRowsFromFacts(facts, {
          systemHotelId: Number(config.system_hotel_id || config.systemHotelId || 0),
          hotelName,
          profileId: hotelId || profileId,
          dataDate,
          capturedAt,
        });
        payload.catalog_facts.push(...facts);
        payload.standard_rows.push(...rows);
      } else {
        payload.unmatched_xhr_urls.push({ url: requestUrl, status: response.status, request_type: init.method.toLowerCase() });
      }
    } catch (error) {
      payload.xhr_urls.push(xhrEntry);
      payload.errors.push({ url: requestUrl, error: error?.message || String(error) });
    }
  }

  payload.requested_sections = [...new Set(payload.standard_rows.map((item) => item.capture_section).filter(Boolean))].sort();
  payload.endpoint_candidates = buildCtripEndpointCandidates(payload.unmatched_xhr_urls);
  payload.auth_status = payload.standard_rows.length > 0 || payload.catalog_facts.length > 0
    ? { ok: true, status: 'logged_in_or_cookie_valid', message: 'At least one Ctrip API request returned business data.' }
    : { ok: false, status: payload.responses.length > 0 ? 'no_business_data' : 'no_json_response', message: 'No Ctrip API request returned usable business data. Check Cookie, URL, payload and permissions.' };
  return payload;
}

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function stringValue(value) {
  return String(value ?? '').trim();
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const config = buildConfig(args);
  const cookies = readCookie(args, config);
  const output = resolve(args.output || `runtime/ctrip_capture/ctrip_cookie_api_${Date.now()}.json`);
  const payload = await runCtripCookieApiCapture(config, { cookies });
  ensureParent(output);
  writeFileSync(output, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  console.log(JSON.stringify({
    status: payload.auth_status.ok ? 'ready' : 'not_ready',
    output,
    responses: payload.responses.length,
    catalog_facts: payload.catalog_facts.length,
    standard_rows: payload.standard_rows.length,
    errors: payload.errors.length,
    requested_sections: payload.requested_sections,
  }, null, 2));
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((error) => {
    console.error(error?.stack || error?.message || String(error));
    process.exit(1);
  });
}
