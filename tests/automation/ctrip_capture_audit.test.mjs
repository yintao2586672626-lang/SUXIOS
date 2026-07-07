import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import {
  buildCtripCaptureAudit,
  evaluateCtripCaptureAuditGate,
  renderCtripCaptureAuditMarkdown,
} from '../../scripts/lib/ctrip_capture_audit.mjs';

test('summarizes Ctrip capture output and groups pending endpoint evidence', () => {
  const payload = {
    captured_at: '2026-05-31T08:00:00.000Z',
    requested_sections: ['business_overview', 'traffic_report'],
    responses: [
      { url: 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData', section: 'homepage', endpoint_id: 'homepage_realtime' },
      { url: 'https://ebooking.ctrip.com/datacenter/api/queryScanFlowDetailsV2', section: 'traffic_report', endpoint_id: 'traffic_scan_flow' },
    ],
    catalog_facts: [
      { metric_key: 'order_count', section: 'homepage', endpoint_id: 'homepage_realtime' },
      { metric_key: 'visitor_count', section: 'traffic_report', endpoint_id: 'traffic_scan_flow' },
      { metric_key: 'order_count', section: 'homepage', endpoint_id: 'homepage_realtime' },
    ],
    standard_rows: [
      { data_type: 'business', dimension: 'catalog:homepage:homepage_realtime:order_count:root' },
      { data_type: 'traffic', dimension: 'catalog:traffic_report:traffic_scan_flow:visitor_count:root' },
    ],
    unmatched_xhr_urls: [
      { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch', status: 200, request_type: 'xhr' },
      { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/settlementBillList', status: 200, request_type: 'xhr' },
      { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/getEbkResourcePopups', status: 200, request_type: 'xhr' },
    ],
    pages: [
      {
        name: 'sales_report',
        interactions: [
          { action: 'click_text', text: '\u9500\u552e\u6570\u636e', clicked: true },
          { action: 'click_text', text: '\u623f\u578b', clicked: false, skipped: 'not_visible' },
        ],
      },
      {
        name: 'traffic_report',
        interactions: [
          { action: 'click_text', text: '\u624b\u673aAPP', clicked: true },
          { action: 'click_text', text: '\u7535\u8111\u7f51\u9875\u7248', clicked: false, error: 'detached' },
        ],
      },
    ],
  };

  const audit = buildCtripCaptureAudit([{ path: 'capture.json', payload }], {
    generatedAt: '2026-05-31T08:10:00.000Z',
  });

  assert.equal(audit.summary.input_file_count, 1);
  assert.equal(audit.summary.response_count, 2);
  assert.equal(audit.summary.catalog_fact_count, 3);
  assert.equal(audit.summary.unique_metric_count, 2);
  assert.equal(audit.summary.standard_row_count, 2);
  assert.equal(audit.summary.endpoint_candidate_count, 2);
  assert.equal(audit.summary.requested_section_count, 2);
  assert.equal(audit.summary.expected_endpoint_count > audit.summary.captured_catalog_endpoint_count, true);
  assert.equal(audit.summary.captured_catalog_endpoint_count, 1);
  assert.equal(audit.summary.missing_catalog_endpoint_count, audit.summary.expected_endpoint_count - 1);
  assert.equal(audit.summary.page_count, 2);
  assert.equal(audit.summary.interaction_planned_count, 4);
  assert.equal(audit.summary.interaction_clicked_count, 2);
  assert.equal(audit.summary.interaction_skipped_count, 1);
  assert.equal(audit.summary.interaction_error_count, 1);
  assert.equal(audit.by_section.homepage.response_count, 1);
  assert.equal(audit.by_section.traffic_report.catalog_fact_count, 1);
  assert.equal(audit.endpoint_coverage.sections.traffic_report.captured_endpoint_ids.includes('traffic_scan_flow'), true);
  assert.equal(audit.endpoint_coverage.sections.business_overview.captured_endpoint_ids.length, 0);
  assert.equal(audit.endpoint_coverage.sections.business_overview.missing_endpoint_ids.includes('business_realtime'), true);
  assert.equal(audit.interactions_by_section.sales_report.clicked_count, 1);
  assert.equal(audit.interactions_by_section.sales_report.skipped_count, 1);
  assert.deepEqual(audit.interactions_by_section.sales_report.missed_actions, ['房型']);
  assert.equal(audit.interactions_by_section.traffic_report.error_count, 1);
  assert.equal(audit.endpoint_candidates.orders_detail.count, 1);
  assert.equal(audit.endpoint_candidates.settlement_finance.count, 1);
  assert.equal(audit.endpoint_candidates.promotion.count, 0);
  assert.equal(audit.endpoint_candidates.orders_detail.items[0].safe_to_catalog, false);
  assert.deepEqual(audit.next_evidence_required, ['Request URL', 'Payload', 'Preview / Response', 'page/tab context', 'hotel/date parameters']);
  assert.equal(audit.capture_gap_report.status, 'needs_evidence');
  assert.equal(audit.capture_gap_report.missing_formal_endpoints.some((item) => item.id === 'business_realtime'), true);
  assert.equal(audit.capture_gap_report.missing_fields_by_section.business_overview.missing_field_count > 0, true);
  assert.equal(audit.capture_gap_report.p3_candidate_sections.orders_detail.count, 1);
  assert.equal(audit.capture_gap_report.p3_candidate_sections.settlement_finance.count, 1);
  assert.equal(audit.capture_gap_report.next_actions.some((item) => item.action === 'capture_missing_formal_endpoint'), true);
  assert.equal(audit.capture_gap_report.next_actions.some((item) => item.action === 'collect_p3_devtools_evidence'), true);

  const markdown = renderCtripCaptureAuditMarkdown(audit);
  assert.equal(markdown.includes('## 未归档接口候选'), true);
  assert.equal(markdown.includes('orders_detail'), true);
  assert.equal(markdown.includes('settlement_finance'), true);
  assert.equal(markdown.includes('promotion'), true);
  assert.equal(markdown.includes('正式接口覆盖'), true);
  assert.equal(markdown.includes('business_realtime'), true);
  assert.equal(markdown.includes('页面交互触发覆盖'), true);
  assert.equal(markdown.includes('sales_report'), true);
  assert.equal(markdown.includes('capture_gap_report'), true);
  assert.equal(markdown.includes('collect_p3_devtools_evidence'), true);
});

test('excludes configured not-applicable Ctrip sections from coverage gaps', () => {
  const payload = {
    captured_at: '2026-06-26T08:00:00.000Z',
    requested_sections: ['business_overview', 'ads_pyramid'],
    not_applicable_sections: ['ads_pyramid'],
    responses: [
      { url: 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData', section: 'business_overview', endpoint_id: 'business_realtime' },
    ],
    catalog_facts: [
      { metric_key: 'order_count', section: 'business_overview', endpoint_id: 'business_realtime' },
    ],
    standard_rows: [
      { data_type: 'business', dimension: 'catalog:business_overview:business_realtime:order_count:root' },
    ],
  };

  const audit = buildCtripCaptureAudit([{ path: 'capture.json', payload }], {
    generatedAt: '2026-06-26T08:10:00.000Z',
  });

  assert.deepEqual(audit.not_applicable_sections, ['ads_pyramid']);
  assert.equal(audit.summary.requested_section_total_count, 2);
  assert.equal(audit.summary.requested_section_count, 1);
  assert.equal(audit.summary.not_applicable_section_count, 1);
  assert.equal(audit.endpoint_coverage.sections.ads_pyramid, undefined);
  assert.equal(audit.field_coverage.sections.ads_pyramid, undefined);
  assert.equal(
    audit.capture_gap_report.missing_formal_endpoints.some((item) => item.section === 'ads_pyramid' || item.id === 'ads_interpretation'),
    false,
  );
  assert.deepEqual(audit.capture_gap_report.not_applicable_sections, ['ads_pyramid']);

  const markdown = renderCtripCaptureAuditMarkdown(audit);
  assert.equal(markdown.includes('不适用模块：ads_pyramid'), true);
});

test('does not fail the capture gate for missing supporting Ctrip endpoints', () => {
  const trafficRequiredEndpoints = [
    'traffic_scan_flow',
    'traffic_hotel_seq',
    'traffic_flow_transform',
    'traffic_order_overview',
    'traffic_order_trend',
    'traffic_flow_source',
    'traffic_city_keywords',
    'traffic_search_details',
    'traffic_hotel_min_price',
    'traffic_picture_quality',
    'traffic_comment_score_summary',
  ];
  const payload = {
    captured_at: '2026-06-26T08:20:00.000Z',
    requested_sections: ['traffic_report'],
    pages: [
      { name: 'traffic_report', url: 'https://ebooking.ctrip.com/home/mainland' },
    ],
    responses: trafficRequiredEndpoints.map((endpoint_id) => ({
      url: `https://ebooking.ctrip.com/datacenter/api/${endpoint_id}`,
      section: 'traffic_report',
      endpoint_id,
    })),
    catalog_facts: trafficRequiredEndpoints.map((endpoint_id) => ({
      metric_key: endpoint_id,
      section: 'traffic_report',
      endpoint_id,
    })),
    standard_rows: [
      { data_type: 'traffic', dimension: 'catalog:traffic_report:traffic_scan_flow:list_exposure:root' },
    ],
  };

  const audit = buildCtripCaptureAudit([{ path: 'traffic_capture.json', payload }], {
    generatedAt: '2026-06-26T08:30:00.000Z',
  });
  const gate = evaluateCtripCaptureAuditGate(audit);

  assert.equal(audit.summary.missing_catalog_endpoint_count > 0, true);
  assert.equal(audit.summary.missing_required_endpoint_count, 0);
  assert.equal(
    audit.capture_gap_report.missing_formal_endpoints.some((item) => item.id === 'traffic_flow_source_popups'),
    true,
  );
  assert.equal(gate.status, 'pass');
});

test('summarizes Ctrip P3 evidence drafts and renders coverage status', () => {
  const payload = {
    captured_at: '2026-05-31T09:00:00.000Z',
    requested_sections: ['core'],
    p3_evidence_drafts: [
      {
        request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch',
        candidate_section: 'orders_detail',
        candidate_label: '订单明细',
        data_type: 'order',
        evidence_status: 'complete_redacted',
        catalog_ready: true,
        missing_evidence: [],
        field_mapping_draft: {
          fields: [
            { source_key: 'orderAmount', suggested_field_id: 'order_amount' },
            { source_key: 'roomNights', suggested_field_id: 'room_nights' },
          ],
        },
      },
      {
        request_url: 'https://ebooking.ctrip.com/restapi/soa2/12345/rateCalendarPrice',
        candidate_section: 'price_inventory',
        candidate_label: '价格房态',
        data_type: 'business',
        evidence_status: 'incomplete',
        catalog_ready: false,
        missing_evidence: ['Payload', 'hotel/date parameters'],
        field_mapping_draft: { fields: [] },
      },
    ],
  };

  const audit = buildCtripCaptureAudit([{ path: 'capture_with_p3.json', payload }], {
    generatedAt: '2026-05-31T09:10:00.000Z',
  });

  assert.equal(audit.summary.p3_evidence_draft_count, 2);
  assert.equal(audit.summary.p3_evidence_ready_count, 1);
  assert.equal(audit.summary.p3_evidence_incomplete_count, 1);
  assert.equal(audit.summary.p3_evidence_ready_section_count, 1);
  assert.equal(audit.p3_evidence_matrix.sections.orders_detail.status, 'ready_for_review');
  assert.equal(audit.p3_evidence_matrix.sections.orders_detail.field_draft_count, 2);
  assert.equal(audit.p3_evidence_matrix.sections.price_inventory.status, 'incomplete_evidence');
  assert.deepEqual(audit.p3_evidence_matrix.sections.price_inventory.missing_evidence, ['Payload', 'hotel/date parameters']);
  assert.equal(audit.p3_evidence_matrix.sections.promotion.status, 'missing_evidence');

  const markdown = renderCtripCaptureAuditMarkdown(audit);
  assert.equal(markdown.includes('## P3 证据草稿覆盖'), true);
  assert.equal(markdown.includes('orders_detail'), true);
  assert.equal(markdown.includes('ready_for_review'), true);
  assert.equal(markdown.includes('price_inventory'), true);
  assert.equal(markdown.includes('incomplete_evidence'), true);
  assert.equal(markdown.includes('Payload, hotel/date parameters'), true);
});

test('marks capture audits as login required when pages stay on Ctrip login', () => {
  const audit = buildCtripCaptureAudit([{
    path: 'login_required.json',
    payload: {
      captured_at: '2026-05-31T10:00:00.000Z',
      requested_sections: ['business_overview'],
      pages: [
        {
          name: 'business_overview',
          url: 'https://ebooking.ctrip.com/login/index?targetPath=%2Fdatacenter%2Finland%2Fbusinessreport%2Foutline%3FmicroJump%3Dtrue',
          ok: true,
        },
      ],
      responses: [],
      catalog_facts: [],
      standard_rows: [],
    },
  }], {
    generatedAt: '2026-05-31T10:10:00.000Z',
  });

  assert.equal(audit.auth_status.status, 'login_required');
  assert.equal(audit.auth_status.login_page_count, 1);
  assert.equal(audit.summary.auth_login_required_count, 1);

  const markdown = renderCtripCaptureAuditMarkdown(audit);
  assert.equal(markdown.includes('登录状态'), true);
  assert.equal(markdown.includes('login_required'), true);
});

test('fails the capture gate for login pages, empty responses, and missing formal endpoints', () => {
  const audit = buildCtripCaptureAudit([{
    path: 'login_required.json',
    payload: {
      captured_at: '2026-05-31T10:00:00.000Z',
      requested_sections: ['business_overview', 'traffic_report'],
      pages: [
        {
          name: 'business_overview',
          url: 'https://ebooking.ctrip.com/login/index?targetPath=%2Fdatacenter%2Finland%2Fbusinessreport%2Foutline%3FmicroJump%3Dtrue',
          ok: false,
        },
      ],
      responses: [],
      catalog_facts: [],
      standard_rows: [],
    },
  }], {
    generatedAt: '2026-05-31T10:10:00.000Z',
  });

  const gate = evaluateCtripCaptureAuditGate(audit);

  assert.equal(gate.status, 'fail');
  assert.equal(gate.failed, true);
  assert.deepEqual(gate.failed_check_ids, [
    'auth_session',
    'response_count',
    'standard_rows',
    'endpoint_coverage',
  ]);
  const endpointCoverage = gate.checks.find((check) => check.id === 'endpoint_coverage');
  assert.equal(endpointCoverage.actual, `0/${audit.summary.required_endpoint_count}`);
  assert.equal(audit.summary.expected_endpoint_count > 0, true);
  assert.equal(audit.capture_gap_report.status, 'blocked_auth');
  assert.equal(audit.capture_gap_report.blockers.includes('auth_session'), true);
  assert.equal(audit.capture_gap_report.next_actions[0].action, 'login_and_rerun_capture');
});

test('passes the capture gate when required auth, response, rows, and endpoints are covered', () => {
  const audit = {
    auth_status: { status: 'ok_or_unverified' },
    summary: {
      response_count: 3,
      standard_row_count: 2,
      expected_endpoint_count: 3,
      captured_catalog_endpoint_count: 3,
      missing_catalog_endpoint_count: 0,
    },
    endpoint_coverage: {
      summary: {
        expected_endpoint_count: 3,
        captured_endpoint_count: 3,
        missing_endpoint_count: 0,
        coverage_rate: 100,
      },
    },
  };

  const gate = evaluateCtripCaptureAuditGate(audit);

  assert.equal(gate.status, 'pass');
  assert.equal(gate.failed, false);
  assert.deepEqual(gate.failed_check_ids, []);
});

test('fails the capture gate when field coverage is below the configured minimum', () => {
  const audit = {
    auth_status: { status: 'ok_or_unverified' },
    summary: {
      response_count: 3,
      standard_row_count: 2,
      expected_endpoint_count: 3,
      captured_catalog_endpoint_count: 3,
      missing_catalog_endpoint_count: 0,
      expected_field_count: 10,
      captured_catalog_field_count: 2,
      missing_catalog_field_count: 8,
    },
    endpoint_coverage: {
      summary: {
        expected_endpoint_count: 3,
        captured_endpoint_count: 3,
        missing_endpoint_count: 0,
        coverage_rate: 100,
      },
    },
    field_coverage: {
      summary: {
        expected_field_count: 10,
        captured_field_count: 2,
        missing_field_count: 8,
        coverage_rate: 20,
      },
    },
  };

  const gate = evaluateCtripCaptureAuditGate(audit, { minFieldCoverageRate: 80 });

  assert.equal(gate.status, 'fail');
  assert.equal(gate.failed, true);
  assert.deepEqual(gate.failed_check_ids, ['field_coverage']);
  assert.equal(gate.checks.find((check) => check.id === 'field_coverage').actual, '20%');
});

test('minimum field coverage gate does not imply zero missing fields', () => {
  const audit = {
    auth_status: { status: 'ok_or_unverified' },
    summary: {
      response_count: 3,
      standard_row_count: 2,
      expected_endpoint_count: 3,
      captured_catalog_endpoint_count: 3,
      missing_catalog_endpoint_count: 0,
      expected_field_count: 10,
      captured_catalog_field_count: 8,
      missing_catalog_field_count: 2,
    },
    endpoint_coverage: {
      summary: {
        expected_endpoint_count: 3,
        captured_endpoint_count: 3,
        missing_endpoint_count: 0,
      },
    },
    field_coverage: {
      summary: {
        expected_field_count: 10,
        captured_field_count: 8,
        missing_field_count: 2,
        coverage_rate: 80,
      },
    },
  };

  const gate = evaluateCtripCaptureAuditGate(audit, { minFieldCoverageRate: 80 });

  assert.equal(gate.status, 'pass');
  assert.equal(gate.failed, false);
  assert.equal(gate.thresholds.max_missing_fields, null);
});

test('CLI capture gate accepts the minimum field coverage option', () => {
  const dir = mkdtempSync(join(tmpdir(), 'ctrip-audit-'));
  const inputPath = join(dir, 'capture.json');
  const outputPath = join(dir, 'audit.json');
  const markdownPath = join(dir, 'audit.md');
  writeFileSync(inputPath, JSON.stringify({
    requested_sections: ['business_overview'],
    pages: [
      { name: 'business_overview', url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true' },
    ],
    responses: [
      { section: 'business_overview', endpoint_id: 'business_realtime' },
    ],
    catalog_facts: [
      { section: 'business_overview', endpoint_id: 'business_realtime', metric_key: 'order_count' },
    ],
    standard_rows: [
      { dimension: 'catalog:business_overview:business_realtime:order_count:root' },
    ],
  }), 'utf8');

  execFileSync(process.execPath, [
    'scripts/audit_ctrip_capture_output.mjs',
    `--input=${inputPath}`,
    `--output=${outputPath}`,
    `--markdown=${markdownPath}`,
    '--gate',
    '--allow-missing-endpoints',
    '--min-field-coverage-rate=80',
  ], { cwd: process.cwd(), encoding: 'utf8' });

  const audit = JSON.parse(readFileSync(outputPath, 'utf8'));

  assert.equal(audit.capture_gate.status, 'fail');
  assert.equal(audit.capture_gate.failed_check_ids.includes('field_coverage'), true);
});

test('summarizes requested Ctrip field coverage without treating missing fields as captured', () => {
  const audit = buildCtripCaptureAudit([{
    path: 'field_coverage.json',
    payload: {
      requested_sections: ['business_overview'],
      responses: [
        { section: 'business_overview', endpoint_id: 'business_realtime' },
      ],
      catalog_facts: [
        { section: 'business_overview', endpoint_id: 'business_realtime', metric_key: 'order_count' },
      ],
      standard_rows: [
        { dimension: 'catalog:business_overview:business_realtime:room_nights:root' },
      ],
      pages: [
        { name: 'business_overview', url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true' },
      ],
    },
  }], {
    generatedAt: '2026-05-31T11:00:00.000Z',
  });

  assert.equal(audit.summary.expected_field_count > audit.summary.captured_catalog_field_count, true);
  assert.equal(audit.field_coverage.sections.business_overview.captured_field_ids.includes('order_count'), true);
  assert.equal(audit.field_coverage.sections.business_overview.captured_field_ids.includes('room_nights'), true);
  assert.equal(audit.field_coverage.sections.business_overview.missing_field_ids.includes('order_amount'), true);
  assert.equal(audit.field_coverage.sections.business_overview.missing_field_ids.includes('hotel_id'), false);

  const markdown = renderCtripCaptureAuditMarkdown(audit);
  assert.equal(markdown.includes('字段覆盖'), true);
  assert.equal(markdown.includes(audit.field_coverage.sections.business_overview.missing_field_ids[0]), true);
});

test('limits Ctrip field coverage to enabled profile field config keys', () => {
  const audit = buildCtripCaptureAudit([{
    path: 'field_config_coverage.json',
    payload: {
      requested_sections: ['business_overview'],
      responses: [
        { section: 'business_overview', endpoint_id: 'business_realtime' },
      ],
      catalog_facts: [
        { section: 'business_overview', endpoint_id: 'business_realtime', metric_key: 'order_count' },
      ],
      standard_rows: [
        { dimension: 'catalog:business_overview:business_realtime:order_count:root' },
      ],
      pages: [
        { name: 'business_overview', url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true' },
      ],
    },
  }], {
    generatedAt: '2026-05-31T11:30:00.000Z',
    allowedFieldKeys: ['order_count'],
  });

  assert.equal(audit.field_coverage.sections.business_overview.expected_field_count, 1);
  assert.deepEqual(audit.field_coverage.sections.business_overview.missing_field_ids, []);
  assert.equal(audit.capture_gap_report.missing_fields_by_section.business_overview, undefined);
});

test('Ctrip browser capture payload keeps the structured capture gap report', () => {
  const script = readFileSync('scripts/ctrip_browser_capture.mjs', 'utf8');

  assert.match(script, /capture_gap_report:\s*null/);
  assert.match(script, /payload\.capture_gap_report\s*=\s*audit\.capture_gap_report/);
  assert.match(script, /capture_gap_report:\s*audit\.capture_gap_report\s*\|\|\s*null/);
});

test('Ctrip browser capture supports a login-only profile preparation mode', () => {
  const script = readFileSync('scripts/ctrip_browser_capture.mjs', 'utf8');

  assert.match(script, /loginOnly/);
  assert.match(script, /args\.loginOnly/);
  assert.match(script, /args\.authOnly/);
  assert.match(script, /mode:\s*loginOnly\s*\?\s*'login_only'\s*:\s*'capture'/);
  assert.match(script, /finalizeLoginOnlyPayload/);
  assert.match(script, /status:\s*'login_prepared'/);
  assert.match(script, /reason:\s*'login_only'/);
});

test('Ctrip browser capture creates the requested output directory', () => {
  const script = readFileSync('scripts/ctrip_browser_capture.mjs', 'utf8');

  assert.match(script, /import\s*\{\s*dirname,\s*join,\s*resolve\s*\}\s*from\s*'node:path'/);
  assert.match(script, /await\s+mkdir\(dirname\(outputPath\),\s*\{\s*recursive:\s*true\s*\}\)/);
});

test('Ctrip capture summary reports auth, modules and diagnosis groups without raw secrets', () => {
  const dir = mkdtempSync(join(tmpdir(), 'ctrip-summary-'));
  const input = join(dir, 'capture.json');
  const output = join(dir, 'summary.json');
  const markdown = join(dir, 'summary.md');
  writeFileSync(input, JSON.stringify({
    profile_id: '63',
    mode: 'capture',
    auth_status: { ok: true, status: 'logged_in', message: 'ok' },
    requested_sections: ['homepage', 'quality_psi'],
    cookie_injection: { attempted: true, injected_count: 12, domains: ['ebooking.ctrip.com'] },
    pages: [{ name: 'homepage', url: 'https://ebooking.ctrip.com/datacenter/inland/home' }],
    responses: [
      { section: 'homepage', endpoint_id: 'homepage_realtime', data_type: 'business', url: 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData' },
      { section: 'quality_psi', endpoint_id: 'psi_overview', data_type: 'quality', url: 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2' },
    ],
    catalog_facts: [
      { section: 'homepage', endpoint_id: 'homepage_realtime', metric_key: 'order_amount', value: 309 },
      { section: 'homepage', endpoint_id: 'homepage_realtime', metric_key: 'visitor_count', value: 5 },
      { section: 'quality_psi', endpoint_id: 'psi_overview', metric_key: 'psi_score', value: 4.54 },
    ],
    standard_rows: [
      { capture_section: 'homepage', endpoint_id: 'homepage_realtime', data_type: 'business', dimension: 'catalog:homepage:homepage_realtime:order_amount:root', amount: 309 },
      { capture_section: 'quality_psi', endpoint_id: 'psi_overview', data_type: 'quality', dimension: 'catalog:quality_psi:psi_overview:psi_score:root', data_value: 4.54 },
    ],
  }), 'utf8');

  const stdout = execFileSync('node', [
    'scripts/summarize_ctrip_capture_result.mjs',
    `--input=${input}`,
    `--output=${output}`,
    `--markdown=${markdown}`,
  ], { cwd: process.cwd(), encoding: 'utf8' });
  const cli = JSON.parse(stdout);
  const summary = JSON.parse(readFileSync(output, 'utf8'));
  const md = readFileSync(markdown, 'utf8');

  assert.equal(cli.status, 'ready');
  assert.equal(summary.auth_status.status, 'logged_in');
  assert.equal(summary.sections.homepage.status, 'captured');
  assert.equal(summary.sections.quality_psi.endpoint_ids.includes('psi_overview'), true);
  assert.equal(summary.diagnosis_groups.some((item) => item.name === '收益销售' && item.status === 'available'), true);
  assert.equal(summary.diagnosis_groups.some((item) => item.name === '服务质量/IM' && item.status === 'available'), true);
  assert.match(md, /携程采集结果摘要/);
  assert.doesNotMatch(md, /Cookie=/i);
});

test('Ctrip probe script chains capture and summary with safe status output', () => {
  const script = readFileSync('scripts/probe_ctrip_capture.mjs', 'utf8');
  const packageJson = JSON.parse(readFileSync('package.json', 'utf8'));

  assert.match(script, /scripts\/ctrip_browser_capture\.mjs/);
  assert.match(script, /scripts\/summarize_ctrip_capture_result\.mjs/);
  assert.match(script, /login_prepared/);
  assert.match(script, /summary_markdown/);
  assert.match(script, /process\.exitCode\s*=\s*finalStatus === 'ready'/);
  assert.equal(packageJson.scripts['probe:ctrip-capture'], 'node scripts/probe_ctrip_capture.mjs');
});

test('Ctrip latest audit handoff mirrors the generated audit markdown', () => {
  const generated = readFileSync('docs/ctrip_capture_audit.md', 'utf8').replace(/\r\n/g, '\n');
  const latest = readFileSync('docs/ctrip_capture_audit_latest.md', 'utf8').replace(/\r\n/g, '\n');

  assert.equal(latest, generated);
  assert.match(latest, /Status: needs_evidence/);
  assert.doesNotMatch(latest, /Status: blocked_auth/);
});
