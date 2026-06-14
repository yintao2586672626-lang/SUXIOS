import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import {
  buildCtripEndpointCandidates,
  buildCtripStandardRowsFromFacts,
  ctripCatalogSummary,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
  generateCtripCaptureMarkdown,
  getCtripSectionInteractionPlan,
  normalizeCtripCaptureSections,
} from '../../scripts/lib/ctrip_capture_catalog.mjs';

test('normalizes Ctrip capture presets for core and wide collection', () => {
  assert.deepEqual(normalizeCtripCaptureSections('core'), [
    'homepage',
    'business_overview',
    'business_weekly_overview',
    'sales_report',
    'traffic_report',
  ]);

  assert.deepEqual(normalizeCtripCaptureSections('default'), [
    'business_overview',
    'business_weekly_overview',
    'traffic_report',
  ]);

  const wide = normalizeCtripCaptureSections('wide');
  for (const section of [
    'homepage',
    'business_overview',
    'business_weekly_overview',
    'sales_report',
    'traffic_report',
    'competitor_overview',
    'loss_analysis',
    'competitor_rank',
    'user_profile',
    'im_board',
    'ads_pyramid',
    'quality_psi',
    'market_calendar',
    'biztravel_bpi',
    'biztravel_business_report',
    'biztravel_competitor',
  ]) {
    assert.equal(wide.includes(section), true, section);
  }
  assert.equal(wide.includes('room_type'), false, 'room_type is not part of default/core/wide capture');
  assert.equal(new Set(wide).size, wide.length);

  const summary = ctripCatalogSummary();
  assert.equal(summary.interaction_plan_section_count >= 10, true);
  assert.equal(summary.interaction_plan_step_count > summary.interaction_plan_section_count, true);
});

test('does not use Profile ID as Ctrip platform hotel identity fallback', () => {
  const facts = [{
    metric_key: 'list_exposure',
    metric_label: 'listExposure',
    value: 128,
    value_type: 'number',
    source_key: 'listExposure',
    source_path: 'data.flowData.0.listExposure',
    source_parent_path: 'data.flowData.0',
    endpoint_id: 'traffic_flow_analysis',
    endpoint_label: 'traffic_flow_analysis',
    section: 'traffic_report',
    platform: 'ctrip',
    data_date: '2026-06-15',
    hotel_id: '',
    captured_at: '2026-06-15T00:00:00.000Z',
    source_url: 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1',
  }];

  const profileOnlyRows = buildCtripStandardRowsFromFacts(facts, {
    profileId: 'local-profile-60',
    dataDate: '2026-06-15',
  });
  assert.equal(profileOnlyRows.length, 1);
  assert.equal(profileOnlyRows[0].hotel_id, '');

  const platformScopedRows = buildCtripStandardRowsFromFacts(facts, {
    profileId: 'local-profile-60',
    hotelId: 'ctrip-platform-60',
    dataDate: '2026-06-15',
  });
  assert.equal(platformScopedRows.length, 1);
  assert.equal(platformScopedRows[0].hotel_id, 'ctrip-platform-60');
});

test('defines Ctrip section interaction plans for tabbed capture pages', () => {
  const sales = getCtripSectionInteractionPlan('sales_report').map(step => step.text);
  assert.equal(sales.includes('\u9500\u552e\u6570\u636e'), true);
  assert.equal(sales.includes('\u603b\u5e73\u53f0'), true);
  assert.equal(sales.includes('\u53bb\u54ea\u513f'), true);
  for (const label of ['\u5b9e\u65f6', '\u6309\u65e5', '\u6309\u5468', '\u6309\u6708', '\u6309\u5b63', '\u81ea\u5b9a\u4e49']) {
    assert.equal(sales.includes(label), true, label);
  }

  const roomType = getCtripSectionInteractionPlan('room_type').map(step => step.text);
  assert.deepEqual(roomType, ['\u9500\u552e\u6570\u636e', '\u623f\u578b']);

  const traffic = getCtripSectionInteractionPlan('traffic_report').map(step => step.text);
  for (const label of ['\u6d41\u91cf\u6570\u636e', '\u643a\u7a0b', '\u53bb\u54ea\u513f', '\u624b\u673aAPP', '\u7535\u8111\u7f51\u9875\u7248']) {
    assert.equal(traffic.includes(label), true, label);
  }

  const weekly = getCtripSectionInteractionPlan('business_weekly_overview').map(step => step.text);
  assert.deepEqual(weekly, ['\u6309\u5468']);

  const biztravel = getCtripSectionInteractionPlan('biztravel_competitor').map(step => step.text);
  assert.equal(biztravel.includes('\u7ade\u4e89\u5708\u699c\u5355'), true);
  assert.deepEqual(getCtripSectionInteractionPlan('unknown_section'), []);
});

test('prefers the active Ctrip page section for duplicate endpoint keywords', () => {
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/queryHotelMinPriceV1?hostType=Ebooking',
      { preferredSection: 'traffic_report' },
    )?.id,
    'traffic_hotel_min_price',
  );

  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/queryOrderTrendV1',
      { preferredSection: 'traffic_report' },
    )?.id,
    'traffic_order_trend',
  );

  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCurrentHotelSeqInfoV1',
      { preferredSection: 'traffic_report' },
    )?.id,
    'traffic_hotel_seq',
  );
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCurrentHotelSeqInfoV1',
      { preferredSection: 'business_overview' },
    )?.id,
    'business_hotel_seq',
  );
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCurrentHotelSeqInfoV1',
      { pageUrl: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true' },
    )?.id,
    'traffic_hotel_seq',
  );

  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/getReportSuggestV1',
      { preferredSection: 'business_weekly_overview' },
    )?.id,
    'weekly_report',
  );
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getUserBehavorV1',
      { preferredSection: 'business_weekly_overview' },
    )?.id,
    'weekly_report',
  );
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCapacityOverViewV4',
      { preferredSection: 'business_overview' },
    )?.id,
    'business_capacity',
  );
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCapacityOverViewV4',
      { pageUrl: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true' },
    )?.id,
    'sales_capacity_overview',
  );
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/restapi/soa2/24588/getEbkResourcePopups',
      { pageContext: { page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true' } },
    )?.id,
    'sales_resource_popups',
  );
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/restapi/soa2/24588/getEbkResourcePopups',
      {
        pageContext: {
          page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true',
          active_section: 'business_overview',
        },
      },
    )?.id,
    'platform_resource_popups',
  );
});

test('covers additional observed Ctrip screenshot endpoints outside review content', () => {
  assert.equal(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/queryFlowTransformNewV1?hostType=Ebooking',
      { preferredSection: 'traffic_report' },
    )?.id,
    'traffic_flow_transform',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/datacenter/api/queryMarketDetails')?.id,
    'sales_market_detail',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/datacenter/api/queryVendibilityRoom')?.id,
    'room_venderbility',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/comment/api/getCommentList')?.section,
    'comment_review',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/comment/api/getCommentNumV2')?.section,
    'comment_review',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/restapi/soa2/26353/getCommentNumV2?_fxpcqlniredt=demo')?.section,
    'comment_review',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/restapi/soa2/26353/getHotelRating?_fxpcqlniredt=demo')?.id,
    'comment_hotel_rating',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/datacenter/api/getMasterHotelLabel')?.id,
    'competitor_hotel_label',
  );
  assert.equal(
    findCtripEndpointByUrl('https://bbk.ctripbiz.cn/api/benefitInfoList')?.id,
    'biztravel_bpi_benefit',
  );
  assert.equal(
    findCtripEndpointByUrl('https://bbk.ctripbiz.cn/api/dataCenterComparisonReportDetail')?.id,
    'biztravel_competitor_report',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/pyramidad/api/getEbkResourceYellowBar?hostType=HE')?.id,
    'ads_resource_yellow_bar',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/pyramidad/api/getDynamicConfig?_fxpcqlniredt=demo')?.id,
    'ads_dynamic_config',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/pyramidad/api/reportInjectFnInfo?_fxpcqlniredt=demo')?.id,
    'ads_report_injection',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/comment/api/listNegativeComment')?.id,
    undefined,
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/restapi/soa2/24588/queryFlowSourcePopups?hostType=Ebooking')?.id,
    'traffic_flow_source_popups',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/queryMenuKey?_fxpcqlniredt=demo')?.id,
    'traffic_menu_key',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/collect2?metaSender=1.3.81')?.id,
    undefined,
  );
});

test('maps Ctrip comment aggregate response without review text fields', () => {
  const url = 'https://ebooking.ctrip.com/comment/api/getCommentNumV2';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'comment_review_aggregate');

  const payload = {
    rcode: 0,
    data: {
      hotelName: '西安空港城天诚商务宾馆',
      statDate: '2026-06-06',
      channelName: '携程',
      commentScore: 4.8,
      totalCount: 577,
      badReviewCount: 6,
      environmentScore: 4.91,
      facilityScore: 4.75,
      reviewServiceScore: 4.91,
      hygieneScore: 4.75,
      hasPicCount: 288,
    },
  };
  const facts = extractCtripCatalogFacts(payload, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-05',
    capturedAt: '2026-06-06T00:00:00.000Z',
    url,
  });

  const factKeys = new Set(facts.map((fact) => fact.metric_key));
  for (const key of ['comment_store_name', 'comment_date', 'comment_channel', 'comment_score', 'comment_count', 'bad_review_count', 'review_environment_score', 'review_facility_score', 'review_service_score', 'review_cleanliness_score', 'review_photo_count', 'review_photo_rate']) {
    assert.equal(factKeys.has(key), true, key);
  }
  assert.equal(factKeys.has('comment_rows'), false);
  assert.equal(factKeys.has('good_review_count'), false);

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'fallback hotel',
    profileId: '6866634',
    dataDate: '2026-06-05',
  });
  assert.equal(rows.length, 1);
  assert.equal(rows[0].hotel_name, '西安空港城天诚商务宾馆');
  assert.equal(rows[0].data_date, '2026-06-06');
  assert.equal(rows[0].raw_data.dimension_values.comment_channel, '携程');
  assert.equal(rows[0].comment_score, 4.8);
  assert.equal(rows[0].data_value, 577);
  assert.equal(rows[0].raw_data.metrics.bad_review_count, 6);
  assert.equal(rows[0].raw_data.metrics.review_environment_score, 4.91);
  assert.equal(rows[0].raw_data.metrics.review_facility_score, 4.75);
  assert.equal(rows[0].raw_data.metrics.review_service_score, 4.91);
  assert.equal(rows[0].raw_data.metrics.review_cleanliness_score, 4.75);
  assert.equal(rows[0].raw_data.metrics.review_photo_count, 288);
  assert.equal(rows[0].raw_data.metrics.review_photo_rate, 49.9);
});

test('maps Ctrip getHotelRating response to rating aggregate fields only', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/26353/getHotelRating?_fxpcqlniredt=09031057118856912388';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'comment_hotel_rating');
  assert.equal(endpoint?.section, 'comment_review');

  const payload = {
    ResponseStatus: { Ack: 'Success' },
    ratingInfo: {
      scoreInfo: {
        avgScoreSimple: 4.83,
        commentLevel: '超棒',
        subScores: [
          { type: 'ratingLocation', name: '环境', score: 4.8, scoreSimple: 4.82 },
          { type: 'ratingFacility', name: '设施', score: 4.7, scoreSimple: 4.77 },
          { type: 'ratingService', name: '服务', score: 4.8, scoreSimple: 4.87 },
          { type: 'ratingRoom', name: '卫生', score: 4.8, scoreSimple: 4.86 },
        ],
      },
      ratingAll: 4.83,
      ratingLocation: 4.82,
      ratingFacility: 4.77,
      ratingService: 4.87,
      ratingRoom: 4.86,
      goodCommentTags: [
        { tagCount: 188, tagName: '提供接送' },
      ],
      poorCommentTags: [
        { tagCount: 2, tagName: '服务一般' },
      ],
      channelSource: 'trip',
    },
  };

  const facts = extractCtripCatalogFacts(payload, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-09',
    capturedAt: '2026-06-09T00:00:00.000Z',
    url,
  });

  const factKeys = new Set(facts.map((fact) => fact.metric_key));
  for (const key of ['comment_score', 'review_environment_score', 'review_facility_score', 'review_service_score', 'review_cleanliness_score']) {
    assert.equal(factKeys.has(key), true, key);
  }
  assert.equal(factKeys.has('bad_review_tag'), false);
  assert.equal(facts.some((fact) => fact.metric_key === 'comment_count' && String(fact.source_path || '').includes('subScores')), false);
  assert.equal(facts.some((fact) => fact.metric_key === 'bad_review_count' && String(fact.source_path || '').includes('subScores')), false);

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'fallback hotel',
    profileId: '6866634',
    dataDate: '2026-06-09',
  });
  const metricRows = rows.filter((row) => row.raw_data.metrics?.comment_score !== undefined);
  assert.equal(metricRows.length, 1);
  assert.equal(metricRows[0].comment_score, 4.83);
  assert.equal(metricRows[0].data_value, 4.83);
  assert.equal(metricRows[0].raw_data.metrics.review_environment_score, 4.82);
  assert.equal(metricRows[0].raw_data.metrics.review_facility_score, 4.77);
  assert.equal(metricRows[0].raw_data.metrics.review_service_score, 4.87);
  assert.equal(metricRows[0].raw_data.metrics.review_cleanliness_score, 4.86);

  const encodedRaw = JSON.stringify(rows.map((row) => row.raw_data));
  assert.equal(encodedRaw.includes('提供接送'), false);
  assert.equal(encodedRaw.includes('服务一般'), false);
});

test('maps Ctrip getHotelRating elongRatings response into aggregate fields', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/26353/getHotelRating?_fxpcqlniredt=09031057118856912388';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'comment_hotel_rating');

  const facts = extractCtripCatalogFacts({
    ResponseStatus: { Ack: 'Success' },
    elongRatings: {
      scoreInfo: {
        maxScore: 5,
        avgScore: 4.9,
        commentLevel: 'excellent',
        subScores: [
          { type: 'ratingLocation', name: 'environment', score: 4.9 },
          { type: 'ratingFacility', name: 'facility', score: 4.8 },
          { type: 'ratingService', name: 'service', score: 4.9 },
          { type: 'ratingRoom', name: 'cleanliness', score: 4.9 },
        ],
      },
      ratingAll: 4.9,
      ratingLocation: 4.9,
      ratingFacility: 4.8,
      ratingService: 4.9,
      ratingRoom: 4.9,
    },
    resStatus: { rcode: 200, rmsg: 'Success' },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-09',
    capturedAt: '2026-06-09T00:00:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'fallback hotel',
    profileId: '6866634',
    dataDate: '2026-06-09',
  });
  const metricRows = rows.filter((row) => row.raw_data.metrics?.comment_score !== undefined);
  assert.equal(metricRows.length, 1);
  assert.equal(metricRows[0].comment_score, 4.9);
  assert.equal(metricRows[0].raw_data.metrics.review_environment_score, 4.9);
  assert.equal(metricRows[0].raw_data.metrics.review_facility_score, 4.8);
  assert.equal(metricRows[0].raw_data.metrics.review_service_score, 4.9);
  assert.equal(metricRows[0].raw_data.metrics.review_cleanliness_score, 4.9);
  assert.equal(facts.some((fact) => String(fact.source_path || '').startsWith('elongRatings.')), true);
});

test('keeps Ctrip review photo rate missing when comment count denominator is unavailable', () => {
  const url = 'https://ebooking.ctrip.com/comment/api/getCommentNumV2';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'comment_review_aggregate');

  const facts = extractCtripCatalogFacts({
    data: {
      hasPicCount: 5,
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-05',
    capturedAt: '2026-06-06T00:00:00.000Z',
    url,
  });

  const factKeys = new Set(facts.map((fact) => fact.metric_key));
  assert.equal(factKeys.has('review_photo_count'), true);
  assert.equal(factKeys.has('review_photo_rate'), false);
});

test('maps Ctrip getCommentNumV2 channel containers to aggregate-only rows', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/26353/getCommentNumV2?_fxpcqlniredt=09031057118856912388&x-traceID=09031057118856912388-1780945769523-863965';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'comment_review_aggregate');

  const facts = extractCtripCatalogFacts({
    ResponseStatus: {
      Ack: 'Success',
      Errors: [],
    },
    hasCtripMapping: true,
    hasQunarMapping: true,
    hasElongMapping: true,
    hasZxMapping: true,
    ctripCount: {
      commentCount: 571,
      noRecommendCount: 6,
      unReplyCount: 0,
      hasPicCount: 122,
      goodRate: 0.989,
      responseRate: 1,
      jumpUrl: 'https://hotels.ctrip.com/hotels/detail/?hotelId=6866634#review',
    },
    qunarCount: {
      commentCount: 649,
      noRecommendCount: 3,
      unReplyCount: 0,
      hasPicCount: 41,
    },
    elongCount: {
      commentCount: 517,
      noRecommendCount: 2,
      unReplyCount: 0,
      hasPicCount: 2,
      jumpUrl: 'http://hotel.elong.com/92220123/',
    },
    zxCount: {
      commentCount: 450,
      noRecommendCount: 7,
      unReplyCount: 0,
      hasPicCount: 26,
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-08',
    capturedAt: '2026-06-08T10:00:00.000Z',
    url,
  });

  const factKeys = new Set(facts.map((fact) => fact.metric_key));
  for (const key of ['ctrip_comment_count', 'qunar_comment_count', 'elong_comment_count', 'zx_comment_count', 'comment_count', 'bad_review_count', 'comment_unreply_count', 'review_photo_count', 'review_photo_rate', 'comment_good_rate', 'comment_response_rate']) {
    assert.equal(factKeys.has(key), true, key);
  }
  assert.equal(factKeys.has('comment_rows'), false);
  assert.equal(factKeys.has('good_review_count'), false);

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: '西安空港城天诚商务宾馆',
    profileId: '6866634',
    dataDate: '2026-06-08',
  });
  assert.equal(rows.length, 4);

  const byChannel = new Map(rows.map((row) => [row.raw_data.dimension_values.comment_channel, row]));
  assert.equal(byChannel.get('携程')?.raw_data.metrics.comment_count, 571);
  assert.equal(byChannel.get('携程')?.raw_data.metrics.bad_review_count, 6);
  assert.equal(byChannel.get('携程')?.raw_data.metrics.comment_unreply_count, 0);
  assert.equal(byChannel.get('携程')?.raw_data.metrics.review_photo_count, 122);
  assert.equal(byChannel.get('携程')?.raw_data.metrics.review_photo_rate, 21.4);
  assert.equal(byChannel.get('携程')?.raw_data.metrics.comment_good_rate, 98.9);
  assert.equal(byChannel.get('携程')?.flow_rate, 100);
  assert.equal(byChannel.get('携程')?.raw_data.dimension_values.target_url, 'https://hotels.ctrip.com/hotels/detail/?hotelId=6866634#review');
  assert.equal(byChannel.get('去哪儿')?.raw_data.metrics.comment_count, 649);
  assert.equal(byChannel.get('艺龙')?.raw_data.metrics.comment_count, 517);
  assert.equal(byChannel.get('智行')?.raw_data.metrics.comment_count, 450);
  assert.equal(byChannel.get('智行')?.raw_data.metrics.bad_review_count, 7);
});

test('maps Ctrip comment list response to aggregate fields only', () => {
  const url = 'https://ebooking.ctrip.com/comment/api/getCommentList';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'comment_review_aggregate');

  const facts = extractCtripCatalogFacts({
    data: {
      hotelName: '西安空港城天诚商务宾馆',
      statDate: '2026-06-06',
      channelName: '携程',
      commentList: [
        {
          commentId: 'COMMENT-SECRET-001',
          commentContent: '房间很吵',
          replyContent: '抱歉，我们会改进',
          userName: '张三',
          roomType: '商务大床房',
          orderId: 'ORDER-SECRET-001',
          commentScore: 3.2,
          commentTime: '2026-06-06',
        },
        {
          commentId: 'COMMENT-SECRET-002',
          commentContent: '位置方便',
          channelName: '携程',
          commentScore: 4.8,
          commentTime: '2026-06-06',
        },
      ],
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-05',
    capturedAt: '2026-06-06T00:00:00.000Z',
    url,
  });

  const factKeys = new Set(facts.map((fact) => fact.metric_key));
  assert.equal(factKeys.has('comment_count'), true);
  assert.equal(factKeys.has('comment_score'), true);
  assert.equal(factKeys.has('bad_review_count'), true);
  assert.equal(factKeys.has('comment_rows'), false);
  assert.equal(factKeys.has('good_review_count'), false);

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'fallback hotel',
    profileId: '6866634',
    dataDate: '2026-06-05',
  });
  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_value, 2);
  assert.equal(rows[0].raw_data.metrics.bad_review_count, 1);

  const encodedRaw = JSON.stringify(rows[0].raw_data);
  assert.equal(encodedRaw.includes('房间很吵'), false);
  assert.equal(encodedRaw.includes('位置方便'), false);
  assert.equal(encodedRaw.includes('COMMENT-SECRET-001'), false);
  assert.equal(encodedRaw.includes('ORDER-SECRET-001'), false);
});

test('maps Ctrip user behavior IM board observed endpoints', () => {
  for (const [url, endpointId] of [
    ['https://ebooking.ctrip.com/userbehavior/getImIndex?hostType=Ebooking&v=0.4544692596916936', 'im_index'],
    ['https://ebooking.ctrip.com/userbehavior/getImDateDistribute?hostType=Ebooking&v=0.889990888095976', 'im_trend'],
    ['https://ebooking.ctrip.com/userbehavior/getImSessionDistribute?hostType=Ebooking&v=0.3581193674166786', 'im_trend'],
    ['https://ebooking.ctrip.com/userbehavior/getImOrderConversionRateByDay?hostType=Ebooking&v=0.7937016081022331', 'im_trend'],
    ['https://ebooking.ctrip.com/userbehavior/getImOrderConversionDetail?hostType=Ebooking&v=0.3644864469238388', 'im_trend'],
  ]) {
    const endpoint = findCtripEndpointByUrl(url);
    assert.equal(endpoint?.id, endpointId, url);
    assert.equal(endpoint?.section, 'im_board', url);
  }

  const imIndex = findCtripEndpointByUrl('https://ebooking.ctrip.com/userbehavior/getImIndex?hostType=Ebooking&v=0.4544692596916936');
  const imTrend = findCtripEndpointByUrl('https://ebooking.ctrip.com/userbehavior/getImDateDistribute?hostType=Ebooking&v=0.889990888095976');
  assert.equal(imIndex?.fields.some((field) => field.id === 'five_min_reply_rate'), true);
  assert.equal(imIndex?.fields.some((field) => field.id === 'manual_reply_rate'), true);
  assert.equal(imIndex?.fields.some((field) => field.id === 'robot_resolution_rate'), true);
  assert.equal(imTrend?.fields.some((field) => field.id === 'session_count'), true);
  assert.equal(imTrend?.fields.some((field) => field.id === 'im_order_conversion_rate'), true);
});

test('maps Ctrip user behavior user-analysis distribution responses', () => {
  const buildRows = (url, payload, expectedEndpointId = 'user_profile_dimensions') => {
    const endpoint = findCtripEndpointByUrl(url);
    assert.equal(endpoint?.id, expectedEndpointId, url);
    const facts = extractCtripCatalogFacts(payload, {
      endpoint,
      section: endpoint.section,
      dataType: endpoint.dataType,
      hotelId: '6866634',
      dataDate: '2026-06-05',
      capturedAt: '2026-06-05T00:00:00.000Z',
      url,
    });
    return buildCtripStandardRowsFromFacts(facts, {
      systemHotelId: 58,
      hotelName: '西安天诚',
      profileId: '6866634',
      dataDate: '2026-06-05',
    });
  };

  const sexRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserSex', {
    rcode: 0,
    data: [{ name: '女', value: 47.19 }, { name: '男', value: 52.81 }],
  });
  const femaleRow = sexRows.find((row) => row.raw_data.dimension_values?.user_sex === '女');
  assert.ok(femaleRow);
  assert.equal(femaleRow.data_value, 47.19);
  assert.equal(femaleRow.raw_data.metrics.distribution_share, 47.19);
  assert.equal(femaleRow.raw_data.metrics.user_sex, '女');

  const percentRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserSex', {
    rcode: 0,
    data: [{ name: 'female', value: null, percent: 5.13 }, { name: 'male', percent: 94.87 }],
  });
  const percentFemaleRow = percentRows.find((row) => row.raw_data.dimension_values?.user_sex === 'female');
  assert.ok(percentFemaleRow);
  assert.equal(percentFemaleRow.data_value, 5.13);
  assert.equal(percentFemaleRow.raw_data.metrics.distribution_share, 5.13);
  assert.equal(percentFemaleRow.raw_data.facts.some((fact) => fact.source_path === 'data.0.percent'), true);

  const typeRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserType', {
    rcode: 0,
    data: [{ name: '休闲型', value: 71.6 }, { name: '商务型', value: 28.4 }],
  });
  const leisureRow = typeRows.find((row) => row.raw_data.dimension_values?.user_type === '休闲型');
  assert.ok(leisureRow);
  assert.equal(leisureRow.data_value, 71.6);

  const priceRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserPriceInfo', {
    rcode: 0,
    data: {
      avg: null,
      titleList: ['极度敏感', '比较敏感', '不太敏感', '非常不敏感'],
      valueList: [28.94, 39.04, 27.05, 4.97],
    },
  });
  const sensitiveRow = priceRows.find((row) => row.raw_data.dimension_values?.price_sensitivity === '比较敏感');
  assert.ok(sensitiveRow);
  assert.equal(sensitiveRow.data_value, 39.04);
  assert.equal(sensitiveRow.raw_data.facts.some((fact) => fact.source_path === 'data.valueList.1'), true);

  const ageRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserAge', {
    rcode: 0,
    data: {
      avg: '41.5',
      titleList: ['<25', '25-34', '35-44', '45-54', '>=55'],
      valueList: [10.75, 19.62, 32.94, 18.09, 18.6],
    },
  });
  const avgAgeRow = ageRows.find((row) => row.raw_data.metrics.avg_user_age === 41.5);
  assert.ok(avgAgeRow);
  assert.equal(avgAgeRow.data_value, 41.5);
  const ageBandRow = ageRows.find((row) => row.raw_data.dimension_values?.user_age === '35-44');
  assert.ok(ageBandRow);
  assert.equal(ageBandRow.data_value, 32.94);
  assert.equal(ageBandRow.raw_data.metrics.distribution_share, 32.94);

  const bookingRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserBookingDays', {
    rcode: 0,
    data: {
      avg: '1.9',
      titleList: ['当天预订', '提前1天预订', '提前2-7天预订', '提前一周预订'],
      valueList: [63.82, 13.51, 15.24, 7.42],
    },
  });
  const avgBookingRow = bookingRows.find((row) => row.raw_data.metrics.avg_booking_days === 1.9);
  assert.ok(avgBookingRow);
  assert.equal(avgBookingRow.data_value, 1.9);
  const sameDayBookingRow = bookingRows.find((row) => row.raw_data.dimension_values?.booking_days === '当天预订');
  assert.ok(sameDayBookingRow);
  assert.equal(sameDayBookingRow.data_value, 63.82);
  assert.equal(sameDayBookingRow.raw_data.metrics.distribution_share, 63.82);
  assert.equal(sameDayBookingRow.raw_data.facts.some((fact) => fact.source_path === 'data.valueList.0'), true);

  const stayRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserStayDays', {
    rcode: 0,
    data: {
      avg: '1.0',
      titleList: ['1天', '2天', '3-5天', '6天以上'],
      valueList: [99.37, 0.55, 0.08, 0.0],
    },
  });
  const avgStayRow = stayRows.find((row) => row.raw_data.metrics.avg_stay_days === 1);
  assert.ok(avgStayRow);
  assert.equal(avgStayRow.data_value, 1);
  const oneDayStayRow = stayRows.find((row) => row.raw_data.dimension_values?.stay_days === '1天');
  assert.ok(oneDayStayRow);
  assert.equal(oneDayStayRow.data_value, 99.37);
  assert.equal(oneDayStayRow.raw_data.metrics.distribution_share, 99.37);

  const pointRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserPoint', {
    rcode: 0,
    data: {
      titleList: ['促销类型敏感度', '优惠券偏好', '早餐订购'],
      userColumnBos: [
        { avg: null, titleList: ['从未', '偶尔', '较少', '经常'], valueList: [0.66, 13.82, 25.66] },
        { avg: null, titleList: ['从未', '偶尔', '较少', '经常'], valueList: [0.66, 71.71, 55.1] },
        { avg: null, titleList: ['从未', '偶尔', '较少', '经常'], valueList: [0.33, 6.25, 17.11] },
        { avg: null, titleList: ['从未', '偶尔', '较少', '经常'], valueList: [98.36, 8.22, 2.14] },
      ],
    },
  });
  const couponOccasionallyRow = pointRows.find((row) => (
    row.raw_data.dimension_values?.order_preference === '优惠券偏好'
    && row.raw_data.dimension_values?.preference_frequency === '偶尔'
  ));
  assert.ok(couponOccasionallyRow);
  assert.equal(couponOccasionallyRow.data_value, 71.71);
  assert.equal(couponOccasionallyRow.raw_data.metrics.distribution_share, 71.71);
  assert.equal(couponOccasionallyRow.raw_data.facts.some((fact) => fact.source_path === 'data.userColumnBos.1.valueList.1'), true);

  const featureRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserFeatures', {
    rcode: 0,
    data: [
      {
        hotelname: '',
        age: '35-44',
        sex: '男',
        source: '异地',
        type: '休闲型',
        traveltime: '工作日',
        consumer: '中低档',
        bookingdays: '当天预定',
        staydays: '1天',
      },
    ],
  }, 'user_profile_features');
  const featureRow = featureRows.find((row) => row.raw_data.dimension_values?.user_age === '35-44');
  assert.ok(featureRow);
  assert.equal(featureRow.raw_data.dimension_values.user_sex, '男');
  assert.equal(featureRow.raw_data.dimension_values.user_source, '异地');
  assert.equal(featureRow.raw_data.dimension_values.user_type, '休闲型');
  assert.equal(featureRow.raw_data.dimension_values.travel_time, '工作日');
  assert.equal(featureRow.raw_data.dimension_values.price_band, '中低档');
  assert.equal(featureRow.raw_data.dimension_values.booking_days, '当天预定');
  assert.equal(featureRow.raw_data.dimension_values.stay_days, '1天');
  assert.equal(featureRow.raw_data.fact_only, true);

  const sourceRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/queryUserSource', {
    rcode: 0,
    data: {
      localCityRate: 5.03,
      otherCityRate: 94.97,
      topCityRate: 14.27,
      provinces: [{ name: '陕西', value: 9.12 }, { name: '广东', value: 10.53 }],
      cities: [{ name: '西安', value: 5.03 }, { name: '茂名', value: 2.57 }],
    },
  });
  const localRow = sourceRows.find((row) => row.raw_data.dimension_values?.user_source_scope === '本地');
  assert.ok(localRow);
  assert.equal(localRow.data_value, 5.03);
  assert.equal(localRow.raw_data.facts.some((fact) => fact.source_path === 'data.localCityRate'), true);
  assert.equal(localRow.raw_data.facts.some((fact) => fact.source_path === 'data.localCityRate.localCityRate'), false);
  const cityRow = sourceRows.find((row) => row.raw_data.dimension_values?.source_city === '西安');
  assert.ok(cityRow);
  assert.equal(cityRow.data_value, 5.03);
  const regionRow = sourceRows.find((row) => row.raw_data.dimension_values?.source_region === '陕西');
  assert.ok(regionRow);
  assert.equal(regionRow.data_value, 9.12);

  for (const item of [
    ['getOrderDistribution', 'booking_hour', '14:00'],
    ['queryUserTravelTime', 'travel_time', 'weekday'],
    ['queryUserStar', 'hotel_star_preference', '3-star'],
    ['queryUserPrice', 'consumption_power', '<=200'],
    ['queryOrderType', 'booking_method', 'mobile'],
    ['queryUserOrders', 'order_hotel_count', '1'],
  ]) {
    const [endpointName, dimensionKey, dimensionValue] = item;
    const rows = buildRows(`https://ebooking.ctrip.com/datacenter/api/dataCenter/userbehavior/${endpointName}`, {
      rcode: 0,
      data: {
        titleList: [dimensionValue, 'other'],
        valueList: [11.97, 88.03],
      },
    });
    const row = rows.find((candidate) => candidate.raw_data.dimension_values?.[dimensionKey] === dimensionValue);
    assert.ok(row, `${endpointName} should map ${dimensionKey}`);
    assert.equal(row.data_value, 11.97);
    assert.equal(row.raw_data.metrics.distribution_share, 11.97);
  }

  const commentRows = buildRows('https://ebooking.ctrip.com/datacenter/api/dataCenter/comment/getCommentsScoreV2', {
    rcode: 0,
    data: {
      ctripCommentCount: 578,
      commentCount: 30,
      qunarCommentCount: null,
      elongCommentCount: null,
      ctripRatingall: 4.8,
      qunarRatingall: 4.9,
      elongRatingall: null,
      environmentScore: 4.91,
      facilityScore: 4.75,
      reviewServiceScore: 4.91,
      hygieneScore: 4.75,
      hasPicCount: 25,
      ctripRatingAllRanking: 4,
      qunarRatingAllRanking: 8,
      competitorHotelTotal: 26,
      responseRate: 1.0019999742507935,
      ctripId: 'fake-ctrip-id',
      qunarId: 'fake-qunar-id',
      elongId: 'fake-elong-id',
    },
  }, 'traffic_comment_score_summary');
  const commentRow = commentRows[0];
  assert.ok(commentRow);
  assert.equal(commentRow.data_value, 4.8);
  assert.equal(commentRow.raw_data.metrics.ctrip_comment_count, 578);
  assert.equal(commentRow.raw_data.metrics.ctrip_rating, 4.8);
  assert.equal(commentRow.raw_data.metrics.review_environment_score, 4.91);
  assert.equal(commentRow.raw_data.metrics.review_facility_score, 4.75);
  assert.equal(commentRow.raw_data.metrics.review_service_score, 4.91);
  assert.equal(commentRow.raw_data.metrics.review_cleanliness_score, 4.75);
  assert.equal(commentRow.raw_data.metrics.review_photo_count, 25);
  assert.equal(commentRow.raw_data.metrics.review_photo_rate, 83.3);
  assert.equal(commentRow.comment_score, 4.8);
  assert.equal(commentRow.qunar_comment_score, 4.9);
  assert.equal(commentRow.raw_data.rank_metrics.ctrip_rating_rank, 4);
  assert.equal(commentRow.raw_data.rank_metrics.qunar_rating_rank, 8);
  assert.equal(commentRow.raw_data.metrics.rating_competitor_total, 26);
  assert.equal(commentRow.flow_rate, 100.2);
  assert.equal(commentRow.raw_data.metrics.elong_comment_count, null);
  assert.equal(commentRow.raw_data.metrics.elong_rating, null);
  assert.equal('ctrip_comment_id' in commentRow.raw_data.metrics, false);
  assert.equal('qunar_comment_id' in commentRow.raw_data.metrics, false);
  assert.equal('elong_comment_id' in commentRow.raw_data.metrics, false);
});

test('maps Ctrip business overview visitor title daily fields', () => {
  const url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchVisitorTitleV2';
  const endpoint = findCtripEndpointByUrl(url, { preferredSection: 'business_overview' });
  assert.equal(endpoint?.id, 'business_visitor_title');
  assert.equal(endpoint?.section, 'business_overview');

  const sourceKeyByField = new Map(endpoint.fields.map((item) => [item.id, item.sourceKeys]));
  for (const [fieldId, sourceKey] of [
    ['visitor_count', 'visitorTotal'],
    ['visitor_rank', 'visitorRank'],
    ['visitor_count_last_week', 'lastVisitorTotal'],
    ['competitor_avg_visitor', 'competitorAvgNumber'],
    ['qunar_visitor_count', 'qunarVisitorTotal'],
    ['qunar_visitor_rank', 'qunarCompetitorRank'],
    ['qunar_visitor_count_last_week', 'lastQunarVisitorTotal'],
    ['qunar_competitor_avg_visitor', 'qunarCompetitorAvgNumber'],
  ]) {
    assert.equal(sourceKeyByField.get(fieldId)?.includes(sourceKey), true, `${fieldId} source key`);
  }

  const facts = extractCtripCatalogFacts({
    visitorTotal: 2,
    visitorRank: 15,
    lastVisitorTotal: 16,
    competitorAvgNumber: 37,
    qunarVisitorTotal: 1,
    qunarCompetitorRank: 11,
    lastQunarVisitorTotal: 6,
    qunarCompetitorAvgNumber: 23,
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-04',
    capturedAt: '2026-06-04T10:00:00.000Z',
    url,
  });

  const metricKeys = new Set(facts.map((fact) => fact.metric_key));
  for (const fieldId of sourceKeyByField.keys()) {
    if (fieldId !== 'hotel_id' && fieldId !== 'hotel_name' && fieldId !== 'date') {
      assert.equal(metricKeys.has(fieldId), true, `${fieldId} fact`);
    }
  }

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: '西安天诚',
    profileId: '6866634',
    dataDate: '2026-06-04',
  });
  const row = rows.find((item) => item.dimension.includes('business_visitor_title'));
  assert.ok(row, 'visitor title standard row');
  assert.equal(row.data_type, 'traffic');
  assert.equal(row.detail_exposure, 2);
  assert.equal(row.raw_data.metrics.visitor_count_last_week, 16);
  assert.equal(row.raw_data.metrics.competitor_avg_visitor, 37);
  assert.equal(row.raw_data.metrics.qunar_visitor_count, 1);
  assert.equal(row.raw_data.metrics.qunar_visitor_count_last_week, 6);
  assert.equal(row.raw_data.metrics.qunar_competitor_avg_visitor, 23);
  assert.equal(row.raw_data.rank_metrics.visitor_rank, 15);
  assert.equal(row.raw_data.rank_metrics.qunar_visitor_rank, 11);
});

test('derives Ctrip metric-pair actual values from percent when a denominator exists', () => {
  const url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchVisitorTitleV2';
  const endpoint = findCtripEndpointByUrl(url, { preferredSection: 'business_overview' });
  assert.ok(endpoint);

  const context = {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    platform: 'Ctrip',
    hotelId: '6866634',
    hotelName: 'Test Hotel',
    dataDate: '2026-06-05',
    capturedAt: '2026-06-05T00:00:00.000Z',
    url,
  };
  const facts = extractCtripCatalogFacts({
    data: [
      { title: 'visitor count', value: null, percent: 5.13, total: 1000 },
      { title: 'visitor count', percent: 5.13 },
    ],
  }, context);

  const visitorFacts = facts.filter((fact) => fact.metric_key === 'visitor_count');
  assert.equal(visitorFacts.length, 1);
  assert.equal(visitorFacts[0].value, 51.3);
  assert.equal(visitorFacts[0].derived_from, 'percent_of_total');
  assert.equal(visitorFacts[0].derived_percent, 5.13);
  assert.equal(visitorFacts[0].derived_total, 1000);
  assert.equal(visitorFacts[0].denominator_source_path, 'data.0.total');

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'Test Hotel',
    profileId: '6866634',
    dataDate: '2026-06-05',
  });
  const row = rows.find((candidate) => candidate.raw_data.metrics.visitor_count === 51.3);
  assert.ok(row);
  assert.equal(row.detail_exposure, 51);
  assert.equal(row.data_value, 51);
});

test('covers reusable Ctrip platform notice endpoints as support facts only', () => {
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/getEbkResourcePopups')?.id,
    'platform_resource_popups',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/getMultiNotifyMessage')?.id,
    'platform_notifications',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/queryEPush')?.id,
    'platform_notifications',
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/collect?metaSender=1.3.81')?.id,
    undefined,
  );
  assert.equal(
    findCtripEndpointByUrl('https://ebooking.ctrip.com/api/collect2?metaSender=1.3.81')?.id,
    undefined,
  );

  const endpoint = findCtripEndpointByUrl('https://ebooking.ctrip.com/api/getEbkResourcePopups');
  const facts = extractCtripCatalogFacts({
    data: [{ title: '活动提示', content: '请查看账户余额', targetUrl: '/pyramidad/dataReport' }],
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url: 'https://ebooking.ctrip.com/api/getEbkResourcePopups',
  });
  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: 'Demo Hotel',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].raw_data.fact_only, true);
  assert.equal(rows[0].amount, 0);
  assert.equal(rows[0].book_order_num, 0);
  assert.equal(rows[0].raw_data.dimension_values.notice_title, '活动提示');
});

test('does not treat Ctrip nodeId as a hotel_id catalog fact', () => {
  const endpoint = findCtripEndpointByUrl('https://ebooking.ctrip.com/restapi/soa2/24588/getManagementData');
  const facts = extractCtripCatalogFacts({
    data: {
      nodeId: 24588,
      hotelId: 6866634,
      amount: 100,
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    nodeId: '24588',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getManagementData',
  });

  const hotelIdFacts = facts.filter((fact) => fact.metric_key === 'hotel_id');
  assert.ok(hotelIdFacts.some((fact) => fact.source_key === 'hotelId'));
  assert.equal(hotelIdFacts.some((fact) => fact.source_key === 'nodeId'), false);
});

test('uses masterHotelId as Ctrip platform hotel ownership id', () => {
  const endpoint = findCtripEndpointByUrl('https://ebooking.ctrip.com/restapi/soa2/24588/getManagementData');
  const facts = extractCtripCatalogFacts({
    data: {
      hotelId: 24588,
      masterHotelId: 6866634,
      hotelName: '西安天诚',
      amount: 100,
      quantity: 2,
      bookOrderNum: 1,
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'profile-24588',
    dataDate: '2026-06-02',
    capturedAt: '2026-06-04T01:40:00.000Z',
    url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getManagementData',
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: '西安天诚',
    profileId: 'profile-24588',
    dataDate: '2026-06-02',
  });

  assert.ok(rows.length >= 1);
  assert.equal(rows[0].hotel_id, '6866634');
  assert.equal(rows[0].raw_data.metrics.hotel_id, 6866634);
  assert.equal(rows[0].raw_data.hotel_id_source_key, 'masterHotelId');
});

test('extracts Ctrip metric-pair response items into catalog facts and standard rows', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'homepage_realtime');

  const payload = {
    data: {
      realTimeDataItems: [
        { key: 'UV', name: 'APP 访客量', value: '5', rank2: '14/22' },
        { key: 'OrderAmount', name: '预订销售额', value: '309.00', rank2: '16/22' },
        { key: 'MinPrice', name: '实时起价', value: '289.00' },
        { key: 'HotelRating', name: '点评分', value: '4.5' },
        { key: 'OccupiedRooms', name: '在店间夜', value: '4' },
        { key: 'orderQuantity', name: '预订订单数', value: '1' },
        { key: 'Tensity', name: '紧张度', value: '3.74%' },
      ],
      lossOrderDetail: {
        lossOrderCount: 5,
        targetUrl: '/datacenter/inland/marketanalysis/flowanalysis',
      },
    },
  };

  const facts = extractCtripCatalogFacts(payload, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const metricKeys = new Set(facts.map((fact) => fact.metric_key));
  assert.equal(metricKeys.has('visitor_count'), true);
  assert.equal(metricKeys.has('order_amount'), true);
  assert.equal(metricKeys.has('avg_price'), true);
  assert.equal(metricKeys.has('comment_score_summary'), true);
  assert.equal(metricKeys.has('room_nights'), true);
  assert.equal(metricKeys.has('order_count'), true);
  assert.equal(metricKeys.has('tensity'), true);
  assert.equal(facts.some((fact) => fact.metric_key === 'hotel_name' && fact.value === 'APP 访客量'), false);

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: '长沙智选假日酒店',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });
  const core = rows.find((row) => row.dimension.includes('homepage_realtime') && row.amount === 309);

  assert.ok(core);
  assert.equal(core.system_hotel_id, 7);
  assert.equal(core.hotel_id, 'ctrip-1001');
  assert.equal(core.hotel_name, '长沙智选假日酒店');
  assert.equal(core.data_date, '2026-05-31');
  assert.equal(core.data_type, 'business');
  assert.equal(core.amount, 309);
  assert.equal(core.quantity, 4);
  assert.equal(core.book_order_num, 1);
  assert.equal(core.detail_exposure, 5);
  assert.equal(core.comment_score, 4.5);

  const loss = rows.find((row) => row.raw_data.metrics.loss_order_count === 5);
  assert.ok(loss);
  assert.equal(loss.book_order_num, 5);
});

test('builds standard rows for sales, traffic, competitor, PSI and biztravel endpoints', () => {
  const cases = [
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/queryMarketDetailsV1',
      payload: { data: { rows: [{ statDate: '2026-05-31', orderQuantity: 3, roomNights: 4, orderAmount: '241.72' }] } },
      expected: { data_type: 'business', amount: 241.72, quantity: 4, book_order_num: 3 },
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/queryScanFlowDetailsV2?hostType=Ebooking',
      payload: { data: { rows: [{ statDate: '2026-05-31', listExposure: 48, detailUv: 2, orderFillingNum: 1, orderSubmitNum: 0, flowRate: '4.17%' }] } },
      expected: { data_type: 'traffic', list_exposure: 48, detail_exposure: 2, order_filling_num: 1, flow_rate: 4.17 },
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/getTripartiteOrderLoss',
      payload: { data: { lossOrderCount: 11, lossRoomNight: 16, lossOrderAmount: '5560.04', commonViewRate: '18.31%' } },
      expected: { data_type: 'business', amount: 5560.04, quantity: 16, book_order_num: 11, flow_rate: 18.31 },
    },
    {
      url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getCompetingRank',
      payload: {
        data: [{
          hotelId: 75280795,
          hotelName: '我的酒店',
          amount: 12,
          quantity: 8,
          bookOrderNum: 12,
          commentScore: 21,
          totalDetailNum: 14,
          convertionRate: 4,
        }],
        sellRanksBO: [{
          masterHotelId: 91914887,
          hotelName: '慕思健康睡眠酒店（长沙德思勤省政府店）',
          bookingOrdersrank: 1,
          bookingGMVrank: 2,
          stayInRNrank: 2,
          rentalRaterank: 2,
        }],
        flowRanksBO: [],
        serviceRanksBO: [],
      },
      expected: { data_type: 'ranking', amount: 0, quantity: 0, book_order_num: 0, data_value: 0 },
      expected_metrics: {
        order_rank: 12,
        amount_rank: 12,
        room_nights_rank: 8,
        comment_score_rank: 21,
        visitor_rank: 14,
        conversion_rate_rank: 4,
      },
      expected_rank_metrics: {
        order_rank: 12,
        amount_rank: 12,
        room_nights_rank: 8,
        comment_score_rank: 21,
        visitor_rank: 14,
        conversion_rate_rank: 4,
      },
      expected_raw: {
        fact_only: true,
        metric_status: 'rank_fact',
      },
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
      payload: {
        rcode: 0,
        data: [{
          hotelId: 75280795,
          hotelName: '我的酒店',
          amount: 12,
          quantity: 8,
          bookOrderNum: 12,
          commentScore: 21,
          totalDetailNum: 14,
          convertionRate: 4,
        }],
      },
      expected: { data_type: 'ranking', amount: 0, quantity: 0, book_order_num: 0, data_value: 0 },
      expected_metrics: {
        amount_rank: 12,
        room_nights_rank: 8,
        order_rank: 12,
        comment_score_rank: 21,
        visitor_rank: 14,
        conversion_rate_rank: 4,
      },
      expected_rank_metrics: {
        amount_rank: 12,
        room_nights_rank: 8,
        order_rank: 12,
        comment_score_rank: 21,
        visitor_rank: 14,
        conversion_rate_rank: 4,
      },
      expected_raw: {
        fact_only: true,
        metric_status: 'rank_fact',
      },
    },
    {
      url: 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2?hostType=HE',
      payload: { data: { psiScore: '4.54', baseScore: '4.06', rewardScore: '0.48', replyRate: '100%' } },
      expected: { data_type: 'quality', data_value: 4.54, flow_rate: 100 },
    },
    {
      url: 'https://ebooking.ctrip.com/toolcenter/api/psi/queryHistPsiScoreList?hostType=HE&v=0.8928221408368409',
      payload: {
        data: {
          list: [{
            date: '2026-06-04',
            totalScore: '4.97',
            basicScore: '4.67',
            rewardScore: '0.30',
            deductScore: '-0.00',
          }],
        },
      },
      expected: { data_type: 'quality', data_date: '2026-06-04', data_value: 4.97 },
      expected_metrics: {
        psi_score: 4.97,
        base_score: 4.67,
        reward_score: 0.3,
        deduct_score: -0,
      },
      expected_fact_sources: {
        psi_score: 'totalScore',
        base_score: 'basicScore',
        reward_score: 'rewardScore',
        deduct_score: 'deductScore',
      },
    },
    {
      url: 'https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail',
      payload: { data: { rows: [{ statDate: '2026-05-31', roomNights: 1, amount: '340.00', orderQuantity: 2 }] } },
      expected: { data_type: 'business', amount: 340, quantity: 1, book_order_num: 2 },
    },
  ];

  for (const item of cases) {
    const endpoint = findCtripEndpointByUrl(item.url);
    assert.ok(endpoint, item.url);
    const facts = extractCtripCatalogFacts(item.payload, {
      endpoint,
      section: endpoint.section,
      dataType: endpoint.dataType,
      hotelId: 'ctrip-1001',
      dataDate: '2026-05-31',
      capturedAt: '2026-05-31T03:30:00.000Z',
      url: item.url,
    });
    const rows = buildCtripStandardRowsFromFacts(facts, {
      systemHotelId: 7,
      hotelName: '长沙智选假日酒店',
      profileId: 'ctrip-1001',
      dataDate: '2026-05-31',
    });
    const row = rows.find((candidate) => candidate.data_type === item.expected.data_type);
    assert.ok(row, item.url);
    for (const [key, value] of Object.entries(item.expected)) {
      assert.equal(row[key], value, `${item.url} ${key}`);
    }
    for (const [key, value] of Object.entries(item.expected_metrics || {})) {
      assert.equal(row.raw_data.metrics[key], value, `${item.url} raw_data.metrics.${key}`);
    }
    for (const [key, value] of Object.entries(item.expected_rank_metrics || {})) {
      assert.equal(row.raw_data.rank_metrics?.[key], value, `${item.url} raw_data.rank_metrics.${key}`);
    }
    for (const [key, value] of Object.entries(item.expected_raw || {})) {
      assert.equal(row.raw_data[key], value, `${item.url} raw_data.${key}`);
    }
    for (const [key, value] of Object.entries(item.expected_fact_sources || {})) {
      const fact = row.raw_data.facts.find((candidate) => candidate.metric_key === key);
      assert.equal(fact?.source_key, value, `${item.url} raw_data.facts.${key}.source_key`);
    }
  }
});

test('keeps Ctrip PSI V2 base-score detail items as fact-only diagnostic rows', () => {
  const url = 'https://ebooking.ctrip.com/toolcenter/api/psiV2/getHotelPsiV2?hostType=HE&v=0.14653639846260236';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'psi_overview');
  assert.equal(endpoint?.section, 'quality_psi');

  const facts = extractCtripCatalogFacts({
    psiScoreBo: {
      masterHotelId: 6866634,
      hotelName: '西安空港新城天诚商务宾馆',
      basicScore: 4.67,
      basicScoreExtList: [{
        id: 1,
        code: 'A',
        name: '历史间夜量',
        tips: '根据酒店历史30天成交间夜量排名评定',
        score: 4.33,
        rank: '达同城前30%',
        scoreGap: 206,
        scoreGapUnit: '间夜',
        startDate: '2026/05/06',
        endDate: '2026/06/04',
        weight: '20%',
        activityName: '购买金字塔广告',
        activityUrl: '/toolcenter/cpc/pyramid',
        ruleConfigList: [
          { name: '达同城前10%', value: '5.0', desc: '达同城前10%' },
        ],
      }],
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-05',
    capturedAt: '2026-06-05T00:00:00.000Z',
    url,
  });

  const itemFacts = facts.filter((fact) => fact.source_parent_path === 'psiScoreBo.basicScoreExtList.0');
  const byMetric = new Map(itemFacts.map((fact) => [fact.metric_key, fact]));
  assert.equal(byMetric.get('psi_basic_item_type')?.value, '经营产能');
  assert.equal(byMetric.get('psi_basic_item_code')?.value, 'A');
  assert.equal(byMetric.get('psi_basic_item_name')?.value, '历史间夜量');
  assert.equal(byMetric.get('psi_basic_item_weight')?.value, '20%');
  assert.equal(byMetric.get('psi_basic_item_score')?.value, 4.33);
  assert.equal(byMetric.get('psi_basic_item_score_gap')?.value, 206);
  assert.equal(byMetric.get('psi_basic_item_start_date')?.value, '2026/05/06');
  assert.equal(byMetric.get('psi_basic_item_end_date')?.value, '2026/06/04');
  assert.equal(itemFacts.some((fact) => fact.metric_key === 'date'), false);

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: '西安空港新城天诚商务宾馆',
    profileId: '6866634',
    dataDate: '2026-06-05',
  });
  const baseRow = rows.find((row) => row.dimension === 'catalog:quality_psi:psi_overview:hotel_id+hotel_name+base_score:psiScoreBo');
  assert.ok(baseRow);
  assert.equal(baseRow.data_type, 'quality');
  assert.equal(baseRow.data_value, 4.67);

  const detailRow = rows.find((row) => row.raw_data.metrics?.psi_basic_item_code === 'A');
  assert.ok(detailRow);
  assert.equal(detailRow.data_type, 'quality');
  assert.equal(detailRow.data_value, 0);
  assert.equal(detailRow.data_date, '2026-06-05');
  assert.equal(detailRow.raw_data.fact_only, true);
  assert.equal(detailRow.raw_data.metric_status, 'non_numeric_fact');
  assert.equal(detailRow.raw_data.metrics.psi_basic_item_score, 4.33);
  assert.equal(detailRow.raw_data.metrics.psi_basic_item_type, '经营产能');
  assert.equal(detailRow.raw_data.metrics.psi_basic_item_weight, 20);
  assert.equal(detailRow.raw_data.dimension_values.psi_basic_item_type, '经营产能');
  assert.equal(detailRow.raw_data.dimension_values.psi_basic_item_weight, '20%');
  assert.equal(detailRow.raw_data.dimension_values.psi_basic_item_start_date, '2026/05/06');
  assert.equal(detailRow.raw_data.dimension_values.psi_basic_item_end_date, '2026/06/04');
});

test('maps Ctrip loss-analysis screenshot summary fields into loss metrics', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24588/getTripartiteOrderLoss';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'loss_order_summary');

  const facts = extractCtripCatalogFacts({
    data: {
      lossOrderVo: {
        ordernum: 49,
        ordquantity: 76,
        ordamount: 18578.8,
      },
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '24588',
    dataDate: '2026-05-31',
    capturedAt: '2026-06-05T10:00:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'Xian Tiancheng',
    profileId: '24588',
    dataDate: '2026-05-31',
  });
  const row = rows.find((candidate) => candidate.endpoint_id === 'loss_order_summary');
  assert.ok(row);
  assert.equal(row.amount, 18578.8);
  assert.equal(row.quantity, 76);
  assert.equal(row.book_order_num, 49);
  assert.equal(row.raw_data.metrics.loss_order_count, 49);
  assert.equal(row.raw_data.metrics.loss_room_nights, 76);
  assert.equal(row.raw_data.metrics.loss_order_amount, 18578.8);
  assert.equal(row.raw_data.metrics.order_count, undefined);
  assert.equal(row.raw_data.metrics.order_amount, undefined);
});

test('keeps Ctrip weekly lossOrderVo fields as loss metrics', () => {
  const url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getFlowHotelsV1';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'weekly_report');

  const facts = extractCtripCatalogFacts({
    data: {
      lossOrderVo: {
        ordernum: 49,
        ordquantity: 76,
        ordamount: 18578.8,
      },
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '24588',
    dataDate: '2026-05-31',
    capturedAt: '2026-06-05T10:00:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'Xian Tiancheng',
    profileId: '24588',
    dataDate: '2026-05-31',
  });
  const row = rows.find((candidate) => candidate.endpoint_id === 'weekly_report');
  assert.ok(row);
  assert.equal(row.amount, 18578.8);
  assert.equal(row.quantity, 76);
  assert.equal(row.book_order_num, 49);
  assert.equal(row.raw_data.metrics.loss_order_count, 49);
  assert.equal(row.raw_data.metrics.loss_room_nights, 76);
  assert.equal(row.raw_data.metrics.loss_order_amount, 18578.8);
  assert.equal(row.raw_data.metrics.order_count, undefined);
  assert.equal(row.raw_data.metrics.order_amount, undefined);
});

test('maps Ctrip weekly report responses to exact Profile field keys', () => {
  const baseContext = {
    section: 'business_weekly_overview',
    dataType: 'business',
    hotelId: '58',
    dataDate: '2026-06-08',
    capturedAt: '2026-06-08T10:00:00.000Z',
  };
  const samples = [
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getLastWeekReportV1',
      data: {
        data: {
          lastWeekCheckoutRoomNights: 30,
          lastWeekCheckoutSales: 3086,
          lastWeekCheckoutRoomPrice: 96.44,
          lastWeekBookQuantity: 26,
          lastWeekBookRoomNights: 29,
          lastWeekBookSales: 2827,
        },
      },
      expected: ['last_week_checkout_room_nights', 'last_week_checkout_sales', 'last_week_book_sales'],
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getTrafficReportV1',
      data: {
        data: {
          myHotel: {
            totalListExposure: 1397,
            totalDetailExposure: 118,
            orderFillingNum: 16,
            orderSubmitNum: 15,
            listTransforDetailRate: '8.45%',
            detailTransforOrderFillRate: '13.56%',
            orderFillTransforOrderSubmitRate: '93.75%',
          },
          competeHotelAvg: {
            totalListExposure: 10568,
            totalDetailExposure: 1901,
            orderFillingNum: 153,
            orderSubmitNum: 84,
            listTransforDetailRate: '17.99%',
            detailTransforOrderFillRate: '8.04%',
            orderFillTransforOrderSubmitRate: '54.9%',
          },
          topCompeteHotel: {
            totalListExposure: 25561,
            totalDetailExposure: 4238,
            orderFillingNum: 366,
            orderSubmitNum: 232,
            listTransforDetailRate: '16.58%',
            detailTransforOrderFillRate: '8.64%',
            orderFillTransforOrderSubmitRate: '63.39%',
          },
        },
      },
      expected: ['weekly_self_list_exposure', 'weekly_competitor_detail_exposure', 'top_competitor_deal_rate'],
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getUserBehaviorV1',
      data: { data: { lastWeekCommentScore: 4.7, lastWeekGoodAdd: 3, lastWeekBadAdd: 1, lastWeekPriceScore: 80 } },
      expected: ['last_week_comment_score', 'last_week_good_add', 'last_week_price_score'],
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getFlowHotelsV1',
      data: {
        data: {
          lossOrderVo: { ordernum: 57, ordquantity: 95.0, ordamount: 21809.88 },
          flowHotelItemVos: [
            { hotelName: 'Home Inn Airport T3 T5', proportion: '36.49%', orderPro: '9.26%', masterHotelId: 105975125 },
            { hotelName: 'Harbor Business Hotel', proportion: '19.59%', orderPro: '13.79%', masterHotelId: 98485819 },
          ],
        },
      },
      expected: ['flow_lost_order_num', 'top_flow_hotel', 'top_flow_hotel_order_rate'],
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotRoomsV1',
      data: { data: { hotRooms: [{ roomShortName: '精选大床房', saleRoomNights: 12, salePercent: '28.5%' }] } },
      expected: ['top_hot_room', 'top_hot_room_nights', 'top_hot_room_sale_percent'],
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotWordsV1',
      data: { data: ['钟楼', '地铁'] },
      expected: ['hot_words_count', 'top_hot_words'],
    },
    {
      url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotHotelsV1',
      data: { data: ['竞品酒店A', '竞品酒店B'] },
      expected: ['hot_hotels_count', 'top_hot_hotels'],
    },
  ];

  const facts = samples.flatMap((sample) => {
    const endpoint = findCtripEndpointByUrl(sample.url, { preferredSection: 'business_weekly_overview' });
    assert.equal(endpoint?.id, 'weekly_report');
    return extractCtripCatalogFacts(sample.data, {
      ...baseContext,
      endpoint,
      url: sample.url,
    });
  });
  const ids = new Set(facts.map((fact) => fact.metric_key));
  for (const sample of samples) {
    for (const expected of sample.expected) {
      assert.equal(ids.has(expected), true, expected);
    }
  }

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'Xian Tiancheng',
    profileId: '58',
    dataDate: '2026-06-08',
  });
  const metricRows = rows.filter((row) => row.capture_section === 'business_weekly_overview');
  assert.equal(metricRows.some((row) => row.raw_data.metrics.last_week_book_sales === 2827), true);
  assert.equal(metricRows.some((row) => row.raw_data.metrics.weekly_self_list_exposure === 1397), true);
  assert.equal(metricRows.some((row) => row.raw_data.metrics.flow_lost_order_num === 57), true);
  assert.equal(metricRows.some((row) => row.raw_data.metrics.flow_lost_room_nights === 95), true);
  assert.equal(metricRows.some((row) => row.raw_data.metrics.flow_lost_amount === 21809.88), true);
  assert.equal(metricRows.some((row) => row.raw_data.metrics.top_flow_hotel === 'Home Inn Airport T3 T5'), true);
  assert.equal(metricRows.some((row) => row.raw_data.metrics.top_flow_hotel_browse_rate === 36.49), true);
  assert.equal(metricRows.some((row) => row.raw_data.metrics.top_flow_hotel_order_rate === 9.26), true);
  assert.equal(metricRows.some((row) => Array.isArray(row.raw_data.metrics.top_hot_words) && row.raw_data.metrics.top_hot_words[0] === '钟楼'), true);
  assert.equal(metricRows.some((row) => Array.isArray(row.raw_data.metrics.top_hot_hotels) && row.raw_data.metrics.top_hot_hotels[1] === '竞品酒店B'), true);
});

test('maps Ctrip loss-analysis hotel rows into per-hotel loss metrics', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24588/getLossOrderCompeteHotel';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'loss_compete_hotel');

  const facts = extractCtripCatalogFacts({
    data: {
      list: [{
        hotelName: 'Competitor A',
        proportion: '31.78%',
        orderPro: '24.39%',
        ordernum: 10,
        followStatus: 1,
      }],
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '24588',
    dataDate: '2026-05-31',
    capturedAt: '2026-06-05T10:00:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'Xian Tiancheng',
    profileId: '24588',
    dataDate: '2026-05-31',
  });
  const row = rows.find((candidate) => candidate.raw_data.metrics.competitor_hotel_name === 'Competitor A');
  assert.ok(row);
  assert.equal(row.book_order_num, 10);
  assert.equal(row.raw_data.metrics.loss_order_count, 10);
  assert.equal(row.raw_data.metrics.common_view_rate, 31.78);
  assert.equal(row.raw_data.metrics.order_conversion_rate, 24.39);
  assert.equal(row.raw_data.metrics.follow_status, 1);
  assert.equal(row.raw_data.metrics.order_count, undefined);
});

test('marks Ctrip weekly competition rows for non-current hotels as competitors', () => {
  const url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1';
  const endpoint = findCtripEndpointByUrl(url);
  assert.ok(endpoint, url);

  const facts = extractCtripCatalogFacts({
    rcode: 0,
    data: [
      { hotelId: 6866634, hotelName: 'My Hotel', amount: 19, quantity: 18, bookOrderNum: 20 },
      { hotelId: 24588, hotelName: 'Node Resource Row', amount: 1, quantity: 1, bookOrderNum: 1 },
      { hotelId: 1056408, hotelName: 'Airport Rival Hotel', amount: 24, quantity: 24, bookOrderNum: 24 },
    ],
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    nodeId: '24588',
    dataDate: '2026-05-31',
    capturedAt: '2026-06-01T14:40:20.814Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'My Hotel',
    profileId: '6866634',
    nodeId: '24588',
    dataDate: '2026-05-31',
  });

  const selfRow = rows.find((row) => row.hotel_id === '6866634');
  const nodeResourceRow = rows.find((row) => row.hotel_id === '24588');
  const competitorRow = rows.find((row) => row.hotel_id === '1056408');
  assert.ok(selfRow, 'current hotel row must be present');
  assert.ok(nodeResourceRow, 'node resource row must be present');
  assert.ok(competitorRow, 'competitor hotel row must be present');
  assert.equal(selfRow.compare_type, 'self');
  assert.equal(nodeResourceRow.compare_type, 'competitor');
  assert.equal(competitorRow.compare_type, 'competitor');
});

test('keeps Ctrip getCompetingRank sellRanksBO rows as ranking facts', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24588/getCompetingRank';
  const endpoint = findCtripEndpointByUrl(url);
  assert.ok(endpoint, url);

  const facts = extractCtripCatalogFacts({
    sellRanksBO: [{
      masterHotelId: 6866634,
      hotelName: 'Xi An Airport Hotel',
      bookingOrdersrank: 18,
      bookingGMVrank: 7,
      stayInRNrank: 2,
      rentalRaterank: 3,
    }],
    flowRanksBO: [],
    serviceRanksBO: [],
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-03',
    capturedAt: '2026-06-04T01:20:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'Xi An Airport Hotel',
    profileId: '6866634',
    dataDate: '2026-06-03',
  });

  const row = rows.find((candidate) => candidate.hotel_id === '6866634');
  assert.ok(row, 'sellRanksBO ranking row must be present');
  assert.equal(row.data_type, 'ranking');
  assert.equal(row.amount, 0);
  assert.equal(row.quantity, 0);
  assert.equal(row.book_order_num, 0);
  assert.equal(row.data_value, 0);
  assert.equal(row.raw_data.metric_status, 'rank_fact');
  assert.equal(row.raw_data.rank_metrics.order_rank, 18);
  assert.equal(row.raw_data.rank_metrics.amount_rank, 7);
  assert.equal(row.raw_data.rank_metrics.room_nights_rank, 2);
  assert.equal(row.raw_data.rank_metrics.occupancy_rate_rank, 3);
  assert.equal(row.raw_data.rank_metrics.competition_rank_order_count, 18);
  assert.equal(row.raw_data.rank_metrics.competition_rank_order_amount, 7);
  assert.equal(row.raw_data.rank_metrics.competition_rank_room_nights, 2);
  assert.equal(row.raw_data.rank_metrics.competition_rank_occupancy_rate, 3);
});

test('keeps Ctrip getCompetingRank flowRanksBO and serviceRanksBO rows as scoped ranking facts', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24588/getCompetingRank';
  const endpoint = findCtripEndpointByUrl(url);
  assert.ok(endpoint, url);

  const facts = extractCtripCatalogFacts({
    sellRanksBO: [],
    flowRanksBO: [{
      masterHotelId: 6866634,
      hotelName: 'Xi An Airport Hotel',
      totalDetailNum: 16,
      convertionRate: 10,
    }],
    serviceRanksBO: [{
      masterHotelId: 6866634,
      hotelName: 'Xi An Airport Hotel',
      serviceScoreRank: 19,
      commentScore: 4,
      qunarCommentScoreRank: 5,
      tongchengCommentScoreRank: 1,
      zhixingCommentScoreRank: 3,
    }],
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: '6866634',
    dataDate: '2026-06-03',
    capturedAt: '2026-06-04T01:20:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 58,
    hotelName: 'Xi An Airport Hotel',
    profileId: '6866634',
    dataDate: '2026-06-03',
  });

  const flowRow = rows.find((row) => row.raw_data.rank_metrics?.competition_rank_app_detail_visitor === 16);
  const serviceRow = rows.find((row) => row.raw_data.rank_metrics?.competition_rank_psi_score === 19);
  assert.ok(flowRow, 'flowRanksBO ranking row must be present');
  assert.ok(serviceRow, 'serviceRanksBO ranking row must be present');

  assert.equal(flowRow.data_type, 'ranking');
  assert.equal(flowRow.amount, 0);
  assert.equal(flowRow.quantity, 0);
  assert.equal(flowRow.book_order_num, 0);
  assert.equal(flowRow.detail_exposure, 0);
  assert.equal(flowRow.raw_data.metric_status, 'rank_fact');
  assert.equal(flowRow.raw_data.rank_metrics.competition_rank_app_conversion_rate, 10);

  assert.equal(serviceRow.data_type, 'ranking');
  assert.equal(serviceRow.amount, 0);
  assert.equal(serviceRow.quantity, 0);
  assert.equal(serviceRow.book_order_num, 0);
  assert.equal(serviceRow.comment_score, 0);
  assert.equal(serviceRow.raw_data.metric_status, 'rank_fact');
  assert.equal(serviceRow.raw_data.rank_metrics.competition_rank_ctrip_rating, 4);
  assert.equal(serviceRow.raw_data.rank_metrics.competition_rank_qunar_rating, 5);
  assert.equal(serviceRow.raw_data.rank_metrics.competition_rank_tongcheng_rating, 1);
  assert.equal(serviceRow.raw_data.rank_metrics.competition_rank_zhixing_rating, 3);
});

test('extracts Ctrip competitor comparison cards into self value, peer average and rank rows', () => {
  const url = 'https://ebooking.ctrip.com/datacenter/api/getManagementData';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'competitor_management');

  const facts = extractCtripCatalogFacts({
    data: {
      cards: [
        { name: '预订订单量', myValue: 143, competitorAvg: 144, rank: 7 },
        { name: '预订销售额', myValue: '4.62万', competitorAvg: '5.12万', rank: 10 },
      ],
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-24',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: '长沙智选假日酒店',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-24',
  });

  const orderRow = rows.find((row) => row.raw_data.metrics.order_count === 143);
  assert.ok(orderRow);
  assert.equal(orderRow.book_order_num, 143);
  assert.equal(orderRow.raw_data.metrics.competitor_average, 144);
  assert.equal(orderRow.raw_data.metrics.rank, 7);
  assert.equal(orderRow.compare_type, 'competitor');

  const amountRow = rows.find((row) => row.amount === 46200);
  assert.ok(amountRow);
  assert.equal(amountRow.raw_data.metrics.competitor_average, 51200);
  assert.equal(amountRow.raw_data.metrics.rank, 10);
});

test('extracts Ctrip competition profile indexType data into business, traffic and quality rows', () => {
  const cases = [
    {
      url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getManagementData',
      payload: { dataList: [{ indexType: 1, val: 1993.72, avgComp: 146877.73, rankComp: 18 }] },
      expected: { amount: 1993.72, data_type: 'business', competitor_average: 146877.73, rank: 18 },
    },
    {
      url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getFlowData',
      payload: { dataList: [{ indexType: 6, val: 1149, avgComp: 8264.16, rankComp: 15 }] },
      expected: { detail_exposure: 1149, data_type: 'traffic', competitor_average: 8264.16, rank: 15 },
    },
    {
      url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getServiceData',
      payload: { dataList: [{ indexType: 11, val: 5.11, avgComp: 5.108, rankComp: 16 }] },
      expected: { comment_score: 5.11, data_type: 'quality', competitor_average: 5.108, rank: 16 },
    },
  ];

  for (const item of cases) {
    const endpoint = findCtripEndpointByUrl(item.url);
    assert.ok(endpoint, item.url);
    const facts = extractCtripCatalogFacts(item.payload, {
      endpoint,
      section: endpoint.section,
      dataType: endpoint.dataType,
      hotelId: 'ctrip-1001',
      dataDate: '2026-05-31',
      capturedAt: '2026-05-31T03:30:00.000Z',
      url: item.url,
    });
    const rows = buildCtripStandardRowsFromFacts(facts, {
      systemHotelId: 7,
      hotelName: 'Demo Hotel',
      profileId: 'ctrip-1001',
      dataDate: '2026-05-31',
    });
    const row = rows.find((candidate) => candidate.endpoint_id === endpoint.id);
    assert.ok(row, item.url);
    for (const [key, value] of Object.entries(item.expected)) {
      if (key === 'competitor_average' || key === 'rank') {
        assert.equal(row.raw_data.metrics[key], value, `${item.url} raw_data.metrics.${key}`);
      } else {
        assert.equal(row[key], value, `${item.url} ${key}`);
      }
    }
  }
});

test('keeps non-numeric Ctrip facts as standard rows without inventing metrics', () => {
  const url = 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'hot_calendar');

  const facts = extractCtripCatalogFacts({
    otherDataList: [{
      hotSpotName: 'Concert A',
      startDate: '2026-06-06',
      endDate: '2026-06-06',
    }],
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: 'Demo Hotel',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'business');
  assert.equal(rows[0].data_date, '2026-06-06');
  assert.equal(rows[0].amount, 0);
  assert.equal(rows[0].quantity, 0);
  assert.equal(rows[0].book_order_num, 0);
  assert.equal(rows[0].raw_data.fact_only, true);
  assert.equal(rows[0].raw_data.metric_status, 'non_numeric_fact');
  assert.equal(rows[0].raw_data.dimension_values.hot_spot_name, 'Concert A');
  assert.equal(rows[0].raw_data.metrics.hot_spot_name, 'Concert A');
});

test('keeps numeric Ctrip support facts out of operating metrics', () => {
  const url = 'https://ebooking.ctrip.com/pyramidad/api/getDynamicConfig?hostType=HE';
  const endpoint = findCtripEndpointByUrl(url);
  assert.equal(endpoint?.id, 'ads_dynamic_config');

  const facts = extractCtripCatalogFacts({
    data: {
      items: [{
        configKey: 'showBudgetWarning',
        value: 1,
        title: '账户余额提醒',
      }],
    },
  }, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'ctrip-1001',
    dataDate: '2026-05-31',
    capturedAt: '2026-05-31T03:30:00.000Z',
    url,
  });

  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 7,
    hotelName: 'Demo Hotel',
    profileId: 'ctrip-1001',
    dataDate: '2026-05-31',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'advertising');
  assert.equal(rows[0].amount, 0);
  assert.equal(rows[0].quantity, 0);
  assert.equal(rows[0].book_order_num, 0);
  assert.equal(rows[0].data_value, 0);
  assert.equal(rows[0].raw_data.fact_only, true);
  assert.equal(rows[0].raw_data.metric_status, 'non_numeric_fact');
  assert.equal(rows[0].raw_data.dimension_values.config_value, 1);
});

test('classifies unmatched Ctrip URLs into evidence candidates for P3 catalog work', () => {
  const candidates = buildCtripEndpointCandidates([
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/rateCalendarPriceQuery' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/promotionCampaignList' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/settlementBillList' },
    { url: 'https://bbk.ctripbiz.cn/api/miceRfpQuoteSearch' },
    { url: 'https://bbk.ctripbiz.cn/api/agreementContractSearch' },
    { url: 'https://ebooking.ctrip.com/restapi/soa2/12345/getEbkResourcePopups' },
  ]);

  const byUrl = new Map(candidates.map((item) => [item.url, item]));
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch')?.candidate_section, 'orders_detail');
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/rateCalendarPriceQuery')?.candidate_section, 'price_inventory');
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/promotionCampaignList')?.candidate_section, 'promotion');
  assert.equal(byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/settlementBillList')?.candidate_section, 'settlement_finance');
  assert.equal(byUrl.get('https://bbk.ctripbiz.cn/api/miceRfpQuoteSearch')?.candidate_section, 'contract_mice_rfp');
  assert.equal(byUrl.get('https://bbk.ctripbiz.cn/api/agreementContractSearch')?.candidate_section, 'contract_mice_rfp');
  assert.equal(byUrl.has('https://ebooking.ctrip.com/restapi/soa2/12345/getEbkResourcePopups'), false);

  const order = byUrl.get('https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch');
  assert.equal(order.evidence_status, 'needs_payload_response');
  assert.equal(order.safe_to_catalog, false);
  assert.equal(order.required_evidence.includes('Request URL'), true);
  assert.equal(order.required_evidence.includes('Payload'), true);
  assert.equal(order.required_evidence.includes('Preview / Response'), true);
});

test('renders Ctrip i18n metric definitions into the generated field inventory', () => {
  const markdown = generateCtripCaptureMarkdown({
    i18nReference: {
      source: '携程酒店商家后台 i18n 语言包 (zh-CN)',
      total_modules: 9,
      total_entries: 6771,
      matched_terms: ['预订订单数'],
      metric_definitions: [{
        term: '预订订单数',
        definition: '统计周期内全平台预订订单量合计，不含取消订单',
        source_key: 'Key.DataCenter.IndexType.Order.HoverText',
      }],
    },
  });

  assert.match(markdown, /### 指标口径速查/);
  assert.match(markdown, /预订订单数/);
  assert.match(markdown, /Key\.DataCenter\.IndexType\.Order\.HoverText/);
});

test('renders project logic from Ctrip fields to operating review', () => {
  const markdown = generateCtripCaptureMarkdown();

  assert.match(markdown, /项目文字描述统一为/);
  assert.match(markdown, /诊断、动作、复盘、沉淀/);
  assert.match(markdown, /## 字段到业务动作的链路/);
  assert.match(markdown, /## 字段进入系统的判定顺序/);
  assert.match(markdown, /采集证据/);
  assert.match(markdown, /标准事实/);
  assert.match(markdown, /经营诊断/);
  assert.match(markdown, /效果复盘/);
  assert.match(markdown, /--fail-on-gate/);
  assert.match(markdown, /Capture Gate/);
});

test('keeps i18n terminology as naming reference instead of auto-approved fields', () => {
  const markdown = generateCtripCaptureMarkdown({
    i18nReference: {
      source: '携程酒店商家后台 i18n 语言包 (zh-CN)',
      total_modules: 9,
      total_entries: 6771,
      matched_terms: ['预订订单数', 'PSI'],
      metric_definitions: [],
    },
  });

  assert.match(markdown, /i18n 只作为命名和页面语义参考/);
  assert.match(markdown, /翻译包本身不是业务数据/);
  assert.match(markdown, /前端埋点上报代码不能直接生成经营指标/);
  assert.match(markdown, /正式字段仍以接口证据、source path 和可复现上下文为准/);
  assert.match(markdown, /竞争圈、商旅、广告和 OTA 零售渠道必须分开表达/);
});

test('catalog verifier can resolve i18n terminology from SUXIOS_CTRIP_I18N_FILE env', () => {
  const dir = mkdtempSync(join(tmpdir(), 'ctrip-i18n-'));
  const i18nPath = join(dir, 'i18n_translations.json');
  writeFileSync(i18nPath, JSON.stringify({
    meta: { source: 'fixture_i18n_translations.json', total_modules: 1, total_entries: 3 },
    modules: {
      datacenter: {
        entries: {
          'Key.DataCenter.IndexType.Order.HoverText': '预订订单数：统计所选日期内的订单数量。',
          'Key.DataCenter.IndexType.Sale.HoverText': '预订销售额：统计所选日期内的预订金额。',
          'Key.DataCenter.IndexType.ListExposure.Title': '列表页曝光量',
        },
      },
    },
  }), 'utf8');

  const output = execFileSync('node', ['scripts/verify_ctrip_capture_catalog.mjs', '--json', '--no-write'], {
    cwd: process.cwd(),
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
    env: {
      ...process.env,
      SUXIOS_CTRIP_I18N_FILE: i18nPath,
    },
  });
  const summary = JSON.parse(output);

  assert.equal(summary.i18n_reference.source, 'fixture_i18n_translations.json');
  assert.equal(summary.i18n_reference.matched_terms.includes('预订订单数'), true);
  assert.equal(summary.i18n_reference.metric_definitions.some((item) => item.term === '预订销售额'), true);
});
