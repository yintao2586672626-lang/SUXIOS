import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import process from 'node:process';
import { launchOtaPersistentContext } from './lib/cloakbrowser_launcher.mjs';
import {
  buildCtripPageUrls,
  buildCtripStandardRowsFromFacts,
  ctripCatalogSummary,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
  normalizeCtripCaptureSections,
  sectionDataType,
  sectionLabel,
} from './lib/ctrip_capture_catalog.mjs';
import { buildCtripCaptureAudit, evaluateCtripCaptureAuditGate } from './lib/ctrip_capture_audit.mjs';
import { buildOtaCaptureEvidence, sanitizeOtaPayloadForStorage } from './lib/ota_capture_standard.mjs';
import { sanitizeOtaObservedUrl } from './lib/ota_session_probe.mjs';
import { fail, parseArgs, safeName, timestamp } from './lib/shared_helpers.mjs';

const args = parseArgs(process.argv.slice(2));
const profileId = stringValue(args.profileId || args.hotelId || '').trim();
if (!profileId) {
  fail('Missing --profile-id or --hotel-id.');
}

const requestedSections = normalizeCtripCaptureSections(args.sections || args.only || 'default');
const hotelId = stringValue(args.hotelId || args.ctripHotelId || args.otaHotelId || args.masterHotelId || '');
const dataDate = stringValue(args.dataDate || '');
const storageDir = resolve(args.profileDir || join('storage', `ctrip_profile_${safeName(profileId)}`));
const waitMs = Math.max(3000, Number(args.waitMs || 18000));
const outputPath = resolve(args.output || join('reports', `ctrip_quick_probe_${safeName(profileId)}_${timestamp()}.json`));
const pageUrls = buildCtripPageUrls();
const capturedAt = new Date().toISOString();

await mkdir(dirname(outputPath), { recursive: true });

const payload = {
  profile_id: profileId,
  hotel_id: hotelId,
  default_data_date: dataDate,
  source: 'ctrip_browser_profile',
  mode: 'quick_endpoint_probe',
  captured_at: capturedAt,
  requested_sections: requestedSections,
  catalog: ctripCatalogSummary(),
  pages: [],
  responses: [],
  xhr_urls: [],
  unmatched_xhr_urls: [],
  by_section: Object.fromEntries(requestedSections.map((section) => [section, []])),
  rows: [],
  standard_rows: [],
  catalog_facts: [],
};

let activeSection = '';
const browser = await launchOtaPersistentContext(storageDir, args);
const page = await browser.newPage();
registerResponseCapture(page, payload);

try {
  for (const section of requestedSections) {
    const targets = pageUrls[section] || [];
    if (targets.length === 0) {
      payload.pages.push({ name: section, label: sectionLabel(section), ok: false, error: 'no page URL configured' });
      continue;
    }
    for (const target of targets) {
      activeSection = section;
      const startedAt = Date.now();
      let ok = true;
      let error = '';
      try {
        await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: Number(args.gotoTimeoutMs || 45000) });
        await page.waitForTimeout(waitMs);
        await page.evaluate(() => window.scrollTo(0, Math.max(document.body.scrollHeight, document.documentElement.scrollHeight))).catch(() => null);
        await page.waitForTimeout(2000);
      } catch (err) {
        ok = false;
        error = 'page_navigation_failed';
      }
      payload.pages.push({
        name: section,
        label: sectionLabel(section),
        configured_url: sanitizeOtaObservedUrl(target.url),
        confidence: target.confidence || '',
        url: sanitizeOtaObservedUrl(page.url()),
        ok,
        elapsed_ms: Date.now() - startedAt,
        ...(error ? { error } : {}),
      });
    }
  }
} finally {
  activeSection = '';
  await finalizePayload(payload);
  await browser.close().catch(() => null);
}

console.log(JSON.stringify({
  output: outputPath,
  response_count: payload.responses.length,
  standard_row_count: payload.standard_rows.length,
  catalog_fact_count: payload.catalog_facts.length,
  by_section: Object.fromEntries(Object.entries(payload.by_section).map(([section, rows]) => [section, rows.length])),
  capture_gate: payload.capture_gate?.status || '',
}, null, 2));

async function finalizePayload(target) {
  const audit = buildCtripCaptureAudit([{ path: outputPath, payload: target }], { generatedAt: capturedAt });
  target.capture_audit = audit;
  target.capture_gate = evaluateCtripCaptureAuditGate(audit, {
    requireEndpointCoverage: false,
    requireFieldCoverage: false,
  });
  target.capture_gap_report = audit.capture_gap_report || null;
  await writeFile(outputPath, `${JSON.stringify(target, null, 2)}\n`, 'utf8');
}

function registerResponseCapture(pageInstance, target) {
  pageInstance.on('response', async (response) => {
    const request = response.request();
    const requestType = request.resourceType();
    if (requestType !== 'xhr' && requestType !== 'fetch') {
      return;
    }
    const url = response.url();
    if (!isCtripCaptureUrl(url)) {
      return;
    }
    const urlMetadata = captureUrlMetadata(url);
    target.xhr_urls.push({ ...urlMetadata, status: response.status(), request_type: requestType, method: request.method() });
    if (response.status() !== 200) {
      return;
    }

    const endpoint = findCtripEndpointByUrl(url, { preferredSection: activeSection });
    const section = endpoint?.section || activeSection || '';
    if (!section || !requestedSections.includes(section)) {
      if (!endpoint) {
        target.unmatched_xhr_urls.push({ ...urlMetadata, status: response.status(), request_type: requestType, method: request.method() });
      }
      return;
    }

    let body = null;
    try {
      body = parseResponseBody(await response.text());
    } catch (err) {
      target.responses.push({ ...urlMetadata, section, endpoint_id: endpoint?.id || '', status: response.status(), request_type: requestType, error: 'response_json_invalid' });
      return;
    }

    const dataType = endpoint?.dataType || sectionDataType(section);
    const safeBody = sanitizeOtaPayloadForStorage(body, dataType);
    const factContext = {
      endpoint,
      section,
      dataType,
      hotelId,
      dataDate,
      capturedAt,
      url,
      captureEvidence: {
        source_trace_id: urlMetadata.source_trace_id || '',
        source_url_hash: urlMetadata.source_url_hash || '',
      },
      sourceTraceId: urlMetadata.source_trace_id || '',
      sourceUrlHash: urlMetadata.source_url_hash || '',
      persistRawSourceUrl: false,
    };
    const catalogFacts = extractCtripCatalogFacts(safeBody, factContext);
    const standardRows = buildCtripStandardRowsFromFacts(catalogFacts, {
      ...factContext,
      profileId,
      defaultDataDate: dataDate,
    });

    target.catalog_facts.push(...catalogFacts);
    target.standard_rows.push(...standardRows);
    target.rows.push(...standardRows);
    target.by_section[section] ||= [];
    target.by_section[section].push(...standardRows);
    target.responses.push({
      ...urlMetadata,
      section,
      section_label: sectionLabel(section),
      endpoint_id: endpoint?.id || '',
      endpoint_label: endpoint?.label || '',
      data_type: dataType,
      status: response.status(),
      request_type: requestType,
      method: request.method(),
      request_payload_present: Boolean(request.postData()),
      catalog_fact_count: catalogFacts.length,
      standard_row_count: standardRows.length,
      data: safeBody,
    });
  });
}

function parseResponseBody(text) {
  const trimmed = String(text || '').trim();
  if (!trimmed) {
    return null;
  }
  return JSON.parse(trimmed);
}

function captureUrlMetadata(value) {
  const rawUrl = stringValue(value);
  const evidence = buildOtaCaptureEvidence('ctrip', { url: rawUrl });
  return {
    url: sanitizeOtaObservedUrl(rawUrl),
    source_trace_id: evidence.source_trace_id || '',
    source_url_hash: evidence.source_url_hash || '',
  };
}

function isCtripCaptureUrl(value) {
  const url = String(value || '').toLowerCase();
  return url.includes('ebooking.ctrip.com')
    && (
      url.includes('/restapi/')
      || url.includes('datacenter')
      || url.includes('pyramid')
      || url.includes('toolcenter/ladder')
      || url.includes('/api/ladder/')
      || url.includes('psi')
      || url.includes('growth')
      || url.includes('competition')
      || url.includes('advertise')
    );
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  return String(value).trim();
}
