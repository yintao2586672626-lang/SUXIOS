import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test, { after } from 'node:test';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
const files = { main: 'public/app-main.js', component: process.env.DAILY_PREVIEW_COMPONENT_PATH || 'public/components/system/operating-opportunity-lab.js', system: 'public/system-static.js', template: 'resources/frontend/templates/fragments/19b-page-operating-opportunities.html' };
const raw = Object.fromEntries(Object.entries(files).map(([key, path]) => [key, readFileSync(path, 'utf8')]));
const main = raw.main.replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a); return main.slice(a, b); };
const requestSource = [
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'),
].join('\n');
assert.ok(main.includes('managerCapabilityRequest: apiRequest'));
const parentRender = new Function('Vue', compile(raw.template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const samples = {"normalized_snapshot":{"id":1001,"tenant_id":7,"user_id":11,"hotel_id":80,"suggestion_key":"daily_preview_20260915_b53c25740df9d27b8c04dbff_8c76cd4166822a4fc4ccc184","scenario":"daily_one_thing_selection","source_key":"daily_one_thing_input","source_version":"daily_one_thing_input.v1","evidence_digest":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","identity_digest":"7faa2f02804add35f0db2a9c63447ea762cdae956c1873904e856bcc317a176e","suggestion_payload":{"feature_key":"daily_one_thing","feature_identity":"b53c25740df9d27b8c04dbff6a6fca1b4f435541972ec2c130baba6b000dfbdb","feature_dimensions":{"source_type":"strict_fact_signal","platform":"ctrip","action_type":"human_reviewed_operating_check","metric_key":"detail_exposure"},"candidate_key":"round46_synthetic_ctrip_check","candidate_material_digest":"8c76cd4166822a4fc4ccc18457a8c79f8a31b5aca1329e99a3a1610f8fb07d9d","business_date":"2026-09-15","sample_identity_contract":"one_user_hotel_business_date_feature_material.v1"},"confidence":null,"content_digest":"e29922a33d8e329cc5f8b2308f3769149fc5a46e92934b57d88f3cc784f700b2","idempotency_hash":"be471d2eff1c911bcf16c4622ba505e2eb322710b7a3a413c8e8a6778e1526b7","created_at":"2026-09-15 10:00:00","frozen":true,"readback_verified":true},"normalized_feedback":{"id":2001,"suggestion_id":1001,"tenant_id":7,"user_id":11,"hotel_id":80,"feedback_status":"accepted","reason_code":"useful","reason_note":"","feedback_payload":{"surface":"operating_opportunity_daily_preview","business_date":"2026-09-15","feature_identity":"b53c25740df9d27b8c04dbff6a6fca1b4f435541972ec2c130baba6b000dfbdb","selection_digest":"58405029ef65f760f13bf7845e6a243e14b78befbb9fce0bb86fa84a52377872","context_digest":"464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e","decision_digest":"4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77","personalization_status":"not_applied"},"content_digest":"47266a73f0c72556d589f16492731c58abd282989f2fc39fb82e4c2573eb117e","idempotency_hash":"c9d419308d2a57915420d6d5c46869de1e16e1c15330f6733226c9032c168e50","created_at":"2026-09-15 10:00:01","readback_verified":true},"single_candidate_receipt_after":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":80},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"recorded","readback_verified":true,"feedback_status":"accepted","reason_code":"useful","feedback_ref":"ai_suggestion_calibration_feedback_events#2001","recorded_at":"2026-09-15 10:00:01"},"context_digest":"464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e","decision_digest":"4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}};
const calibrated = {"A|80|2026-09-15":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic same-scope read-only check","scope":{"tenant_id":7,"hotel_id":80,"platform":"ctrip","business_date":"2026-09-15","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"material_identity_digest":"8c76cd4166822a4fc4ccc18457a8c79f8a31b5aca1329e99a3a1610f8fb07d9d","content_digest":"58405029ef65f760f13bf7845e6a243e14b78befbb9fce0bb86fa84a52377872"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":80},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e","decision_digest":"4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"A|80|2026-09-14":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic same-scope read-only check","scope":{"tenant_id":7,"hotel_id":80,"platform":"ctrip","business_date":"2026-09-14","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"material_identity_digest":"1e7f852f39367f72c7e9d3702911229943c0856c11e9d08f58c6bc53b5b7e5c0","content_digest":"c75e3abed72f7d645bd69c6ae5cdb5e309ced4b8834678cd778dcd09ecda2f7c"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":80},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e","decision_digest":"4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"A|81|2026-09-15":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic same-scope read-only check","scope":{"tenant_id":7,"hotel_id":81,"platform":"ctrip","business_date":"2026-09-15","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"material_identity_digest":"4bf661ed7ad115c3758e1ba2cf07ad30a6337dbcbf7bdc64e46877d356f8e471","content_digest":"7d7c68900d95a3752f40769977640e9381a8134b9224b44eba5ae58b79389fd8"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":81},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"6e676d76e832d9cab798079b2fed7d720ce4005537798db3b338f81060debbe1","decision_digest":"6dcf6bb39f7844b790c2a6d40a1ff5e7ccb4ba8da55c2ebb660a2cab29d3875a","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"A|81|2026-09-14":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic same-scope read-only check","scope":{"tenant_id":7,"hotel_id":81,"platform":"ctrip","business_date":"2026-09-14","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"material_identity_digest":"2780c43b38593700933f92a21179102eef0f24f855ef2e860433e690ab8fabc5","content_digest":"e6818385e744347d13e595a7917208e07cb0cea9bcdbfa684909eb1dfa24a54b"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":81},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"6e676d76e832d9cab798079b2fed7d720ce4005537798db3b338f81060debbe1","decision_digest":"6dcf6bb39f7844b790c2a6d40a1ff5e7ccb4ba8da55c2ebb660a2cab29d3875a","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"B|80|2026-09-15":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic personal preview B","scope":{"tenant_id":7,"hotel_id":80,"platform":"ctrip","business_date":"2026-09-15","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","fact_refs":["online_daily_data#102"],"gap_codes":[]},"material_identity_digest":"525d0985b6f1c614a0a247df509496180bf27ba6248a442294010ae9277711fb","content_digest":"62d686fe5c7b78fd3dbc1e36d42df191a2c8ca22916e557f804a06b189b5b0f0"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":80},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e","decision_digest":"4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"B|80|2026-09-14":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic personal preview B","scope":{"tenant_id":7,"hotel_id":80,"platform":"ctrip","business_date":"2026-09-14","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","fact_refs":["online_daily_data#102"],"gap_codes":[]},"material_identity_digest":"2a8870604391134c827a6f996b9b81d1ffc1c6b22a079b03c8703c4b4a15e1ed","content_digest":"5e08d4dc0b64a9baf6584df52d8a77daee2080ca04c5152ef2864976f4c47449"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":80},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e","decision_digest":"4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"B|81|2026-09-15":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic personal preview B","scope":{"tenant_id":7,"hotel_id":81,"platform":"ctrip","business_date":"2026-09-15","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","fact_refs":["online_daily_data#102"],"gap_codes":[]},"material_identity_digest":"efd563243941feb43d6550fcffa1faf772d8d766cbd29f1fee3a0c205de4b31a","content_digest":"167baf1570a2a172c2682b34861658f20ffb65e65bb73d1fc4d1eac587176087"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":81},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"6e676d76e832d9cab798079b2fed7d720ce4005537798db3b338f81060debbe1","decision_digest":"6dcf6bb39f7844b790c2a6d40a1ff5e7ccb4ba8da55c2ebb660a2cab29d3875a","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"B|81|2026-09-14":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic personal preview B","scope":{"tenant_id":7,"hotel_id":81,"platform":"ctrip","business_date":"2026-09-14","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","fact_refs":["online_daily_data#102"],"gap_codes":[]},"material_identity_digest":"e08f8d5f7c047160ae8718a08cca52cfd3475098bf3d9129ab4ad98e02d25b50","content_digest":"eb51b3e6cab9d4683aa04abf21922ad7e00def5e2853869c6983f757587b5596"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":81},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"6e676d76e832d9cab798079b2fed7d720ce4005537798db3b338f81060debbe1","decision_digest":"6dcf6bb39f7844b790c2a6d40a1ff5e7ccb4ba8da55c2ebb660a2cab29d3875a","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}},"A_explanation|80|2026-09-15":{"selected":{"candidate_key":"round46_synthetic_ctrip_check","source_type":"strict_fact_signal","problem":"Synthetic explanation refreshed without changing facts","scope":{"tenant_id":7,"hotel_id":80,"platform":"ctrip","business_date":"2026-09-15","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","object":"ctrip_fact_scope"},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"source":{"record_id":101,"snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"material_identity_digest":"8c76cd4166822a4fc4ccc18457a8c79f8a31b5aca1329e99a3a1610f8fb07d9d","content_digest":"0e3983b0ce4935758675d50524b0065b95b081fc268ac7e8eee4fef2aa2fbcf2"},"receipt":{"contract_version":"daily_one_thing_personalization.v1","experience_version":"daily_one_thing.personalized_preview.v3","status":"not_applied","application_mode":"base_rank_exact_tie_break_only","scope":{"tenant_id":7,"user_id":11,"hotel_id":80},"base_selected_candidate_key":"round46_synthetic_ctrip_check","selected_candidate_key":"round46_synthetic_ctrip_check","selection_changed":false,"base_rank":[1,1,1,1],"base_tie_group_size":1,"why_you":{"summary":"当前没有使用个人偏好或反馈改变公共基础排序。","reason_code":"personalization_not_applied"},"applied_adjustments":[],"not_applied_reasons":["no_base_rank_tie"],"preference_refs":[],"feedback_refs":[],"feedback_progress":[],"current_feedback":{"status":"not_recorded","readback_verified":true,"feedback_status":null,"reason_code":null,"feedback_ref":null},"context_digest":"464bbb0ccb840e4ea13720e7ba73133f3809be18a2031062ef4a5fdd242bf33e","decision_digest":"4c63b53f6b2a638c5184dd53a10b3293b1ed40fae4748e27202b64e55bd8ee77","candidate_preferences_consumed":false,"facts_changed":false,"eligibility_changed":false,"business_rank_changed":false,"permissions_changed":false,"approval_changed":false,"external_write_authorized":false}}};
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
let assertions = 0;
const eq = (value, expected, note) => { assertions++; assert.deepEqual(value, expected, note); };
const ok = (value, note) => { assertions++; assert.ok(value, note); };
function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null });
  const remove = child => { if (child.parent) { const i = child.parent.children.indexOf(child); if (i >= 0) child.parent.children.splice(i, 1); } child.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(child, parent, anchor = null) { remove(child); child.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(child); else parent.children.splice(i, 0, child); },
    remove, patchProp: (n, key, old, value) => { n.props[key] = value; },
  } };
}
function harness() {
  const requests = [], notices = [];
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl, setTimeout, clearTimeout,
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 0,
    currentPage: Vue.ref('operating-opportunities'), filterReportHotel: Vue.ref('80'),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 80, name: 'Synthetic A', tenant_id: 7 }, { id: 81, name: 'Synthetic B', tenant_id: 7 }]), hotels: Vue.ref([]),
    user: Vue.ref({ id: 11, is_super_admin: true }), token: Vue.ref(''), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: '' }), isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    fetch: (url, options) => new Promise((resolve, reject) => {
      assert.ok(url.startsWith('https://synthetic.invalid/api/operating-opportunities/'));
      assert.ok(['/api/operating-opportunities/overview', '/api/operating-opportunities/daily-preview/feedback'].includes(new URL(url).pathname), 'Only synthetic preview reads/feedback allowed');
      requests.push({ url, options, resolve, reject, settled: false });
    }),
  };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name];
  vm.runInContext(requestSource + '\nglobalThis.actualRequest = request;', sandbox);
  const componentWindow = {};
  const FixedDate = class extends Date { constructor(...args) { super(...(args.length ? args : ['2026-09-15T04:00:00Z'])); } };
  vm.runInNewContext(raw.component, { window: componentWindow, Vue, Intl, Date: FixedDate, URLSearchParams });
  const definition = componentWindow.SUXI_SYSTEM_COMPONENTS.OperatingOpportunityLabBody;
  const host = memoryHost();
  const renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({
    components: { OperatingOpportunityLab: definition },
    setup: () => ({ currentPage: sandbox.currentPage, filterReportHotel: sandbox.filterReportHotel,
      hotels: [{ id: 80, name: 'Synthetic A' }, { id: 81, name: 'Synthetic B' }], managerCapabilityRequest: sandbox.actualRequest,
      openHomeOperatingScheduleItem: () => { throw new Error('Business action outside probe'); }, showToast: sandbox.showToast,
      assistantSessionEpoch: () => sandbox.authSessionEpoch }),
    render: parentRender,
  });
  app.config.warnHandler = () => {}; app.mount(host.root);
  const walk = (node, found = []) => { if (Array.isArray(node)) { node.forEach(n => walk(n, found)); return found; } if (!node || typeof node !== 'object') return found; found.push(node); if (node.component) walk(node.component.subTree, found); walk(node.children, found); return found; };
  const component = () => walk(app._instance.subTree).find(node => node.type?.name === 'OperatingOpportunityLabBody')?.component?.proxy || null;
  const nodes = () => component() ? walk(component().$.subTree) : [];
  const html = () => component() ? renderToString(Vue.createSSRApp({ render: () => definition.render.call(component()) })) : Promise.resolve('');
  const button = id => nodes().find(node => node.type === 'button' && node.props?.['data-testid'] === id);
  const feedback = reason => { const n = button('daily-one-thing-feedback-' + reason); ok(n && !n.props.disabled, 'Only enabled actual feedback controls are clicked'); const pending = n.props.onClick(); return { pending }; };
  const refresh = () => { const n = nodes().find(node => node.type === 'button' && node.children === '刷新事实'); ok(n && !n.props.disabled, 'Actual refresh remains reachable'); const pending = n.props.onClick(); return { pending }; };
  const reply = (req, body, status = 200) => { req.settled = true; req.response = clone(body); req.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
  const pending = method => requests.find(row => !row.settled && String(row.options.method || 'GET') === method);
  return { sandbox, requests, notices, component, nodes, button, feedback, refresh, html, reply, pending, stop: () => app.unmount() };
}
function overview(version = 'A', feedback = 'not_recorded', hotelId = 80, date = '2026-09-15') {
  const data = clone(calibrated[[version, hotelId, date].join('|')]);
  const receipt = data.receipt;
  if (feedback === 'recorded') receipt.current_feedback = clone(samples.single_candidate_receipt_after.current_feedback);
  else if (feedback === 'unavailable') receipt.current_feedback = { status: 'unavailable', readback_verified: false, reason_code: 'synthetic_feedback_read_failure' };
  const preview = { contract_version: 'daily_one_thing.v2', selected: data.selected, status: 'draft', selection_policy: { full_candidate_list_exposed: false } };
  return { code: 200, data: { contract_version: 'operating_opportunity_lab.v2', tenant_id: 7, system_hotel_id: hotelId, business_date: date,
    today: clone(preview), today_preview: clone(preview), personalized_today_preview: { ...clone(preview), personalization_receipt: receipt }, personalization_receipt: receipt,
    today_saved_run: null, today_execution_intent: null, today_state: 'not_saved' } };
}
const postReceipt = req => {
  const body = JSON.parse(req.options.body);
  return { code: 200, data: { contract_version: 'daily_one_thing_personalization_feedback.v1', snapshot: clone(samples.normalized_snapshot), feedback: clone(samples.normalized_feedback), adjustments: {},
    readback_verified: true, system_hotel_id: body.hotel_id, business_date: body.business_date, selected_candidate_key: samples.normalized_snapshot.suggestion_payload.candidate_key,
    selection_digest: body.expected_selection_digest, context_digest: body.expected_context_digest, decision_digest: body.expected_decision_digest,
    hotel_shared_daily_item_changed: false, execution_intent_created: false, external_write_count: 0 } };
};
async function ready(p) { await tick(); const req = p.pending('GET'); ok(req, 'Mounted original overview request: ' + (p.component()?.error || 'component missing')); p.reply(req, overview()); await tick(); ok(p.component()?.personalizedSelected, p.component()?.error); eq(p.button('daily-one-thing-feedback-useful').props.disabled, false); }
async function send(p) { const click = p.feedback('useful'); await tick(); const req = p.pending('POST'); ok(req); eq(p.button('daily-one-thing-feedback-wrong-focus').props.disabled, true); return { ...click, req }; }
const results = [];
async function scenario(name, fn) { return test(name, async () => { const p = harness(); try { await fn(p); } finally { p.stop(); } }); }
await scenario('normal feedback then overview readback stays recorded and no shared action is issued', async p => {
  await ready(p); const sent = await send(p); p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  eq(p.component().feedbackStatus, 'useful'); ok((await p.html()).includes('已记录：这个重点合适'));
  const read = p.refresh(); await tick(); const get = p.pending('GET'); ok(get); p.reply(get, overview('A', 'recorded')); await read.pending;
  eq(p.button('daily-one-thing-feedback-useful').props.disabled, true); eq(p.requests.filter(row => row.options.method === 'POST').length, 1);
  return { submitted: JSON.parse(sent.req.options.body), receipt: sent.req.response, html: await p.html() };
});
await scenario('refresh may change material without late A feedback marking the displayed B', async p => {
  await ready(p); const sent = await send(p); const refresh = p.refresh(); await tick(); const get = p.pending('GET'); ok(get);
  p.reply(get, overview('B')); await refresh.pending; eq(p.component().personalizedSelected.problem, 'Synthetic personal preview B'); eq(p.component().feedbackStatus, '');
  const before = await p.html(); p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  eq(p.component().feedbackStatus, '', 'Old A receipt must not be shown on B');
  eq(p.component().personalizationReceipt.current_feedback.status, 'not_recorded'); eq(p.button('daily-one-thing-feedback-useful').props.disabled, false);
  const after = await p.html(); ok(!after.includes('已记录：这个重点合适'));
  return { post: { payload: JSON.parse(sent.req.options.body), receipt: sent.req.response }, refreshedPreview: get.response, before, after };
});
await scenario('a delayed overview started before feedback confirmation cannot erase the confirmed save', async p => {
  await ready(p); const refresh = p.refresh(); await tick(); const get = p.pending('GET'); ok(get);
  const sent = await send(p); p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  eq(p.component().feedbackStatus, 'useful'); const before = await p.html();
  p.reply(get, overview('A')); await refresh.pending;
  eq(p.component().feedbackStatus, 'useful', 'Older overview cannot clear confirmed same-slot feedback');
  eq(p.button('daily-one-thing-feedback-wrong-focus').props.disabled, true); const after = await p.html(); ok(after.includes('已记录：这个重点合适'));
  return { before, after, postReceipt: sent.req.response, oldOverview: get.response };
});
for (const status of [422, 500]) await scenario('HTTP ' + status + ' remains failure, then explicit overview can recover recorded feedback without repost', async p => {
  await ready(p); const sent = await send(p); p.reply(sent.req, { code: status, message: 'Synthetic feedback rejected or unknown' }, status); await sent.pending;
  eq(p.component().feedbackStatus, ''); ok(p.component().error.includes('Synthetic')); eq(p.notices.filter(row => row.type === 'success').length, 0);
  const read = p.refresh(); await tick(); p.reply(p.pending('GET'), overview('A', status === 500 ? 'recorded' : 'not_recorded')); await read.pending;
  eq(p.component().feedbackStatus, status === 500 ? 'useful' : ''); eq(p.requests.filter(row => row.options.method === 'POST').length, 1);
  return { status, notices: p.notices, html: await p.html() };
});
for (const change of ['hotel', 'date']) await scenario('actual ' + change + ' control isolates late old-scope feedback', async p => {
  await ready(p); const sent = await send(p);
  const field = p.nodes().find(node => node.type === (change === 'hotel' ? 'select' : 'input') && (change === 'hotel' || node.props.type === 'date'));
  ok(field && !field.props.disabled);
  if (change === 'hotel') field.props.onChange({ target: { value: '81' } }); else field.props.onInput({ target: { value: '2026-09-14' } });
  await tick(); if (change === 'hotel') eq(p.sandbox.filterReportHotel.value, '81', 'Original parent template hotel binding is active');
  const get = p.pending('GET'); ok(get); p.reply(get, overview('B', 'not_recorded', change === 'hotel' ? 81 : 80, change === 'date' ? '2026-09-14' : '2026-09-15')); await tick();
  p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  eq(p.component().feedbackStatus, ''); eq(p.button('daily-one-thing-feedback-useful').props.disabled, false); eq(p.notices, []);
  return { html: await p.html() };
});
await scenario('unknown feedback plus unavailable strict readback blocks both buttons', async p => {
  await ready(p); const sent = await send(p); p.reply(sent.req, { code: 500, message: 'Synthetic unknown save' }, 500); await sent.pending;
  const read = p.refresh(); await tick(); p.reply(p.pending('GET'), overview('A', 'unavailable')); await read.pending;
  eq(p.component().feedbackReadbackBlocked, true); eq(p.button('daily-one-thing-feedback-useful').props.disabled, true); eq(p.button('daily-one-thing-feedback-wrong-focus').props.disabled, true);
  ok((await p.html()).includes('反馈回读暂不可用')); return {};
});

await scenario('same material survives changed current context and content without confusing old feedback payload digests', async p => {
  await ready(p); const sent = await send(p); const read = p.refresh(); await tick();
  const response = overview();
  response.data.personalized_today_preview.selected = clone(calibrated['A_explanation|80|2026-09-15'].selected);
  for (const receipt of [response.data.personalized_today_preview.personalization_receipt, response.data.personalization_receipt]) {
    receipt.context_digest = 'e'.repeat(64); receipt.decision_digest = 'd'.repeat(64);
  }
  p.reply(p.pending('GET'), response); await read.pending;
  const receipt = postReceipt(sent.req); p.reply(sent.req, receipt); await sent.pending;
  eq(p.component().feedbackStatus, 'useful'); eq(p.button('daily-one-thing-feedback-useful').props.disabled, true);
  eq(receipt.data.feedback.feedback_payload.context_digest, samples.normalized_feedback.feedback_payload.context_digest, 'Old event payload is not rewritten');
  return {};
});
await scenario('pending overview may still show a new material after old material feedback was confirmed', async p => {
  await ready(p); const read = p.refresh(); await tick(); const get = p.pending('GET');
  const sent = await send(p); p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  eq(p.component().feedbackStatus, 'useful'); p.reply(get, overview('B')); await read.pending;
  eq(p.component().personalizedSelected.problem, 'Synthetic personal preview B'); eq(p.component().feedbackStatus, '');
  eq(p.button('daily-one-thing-feedback-useful').props.disabled, false); return {};
});
await scenario('old overview HTTP500 retains confirmed feedback but returns failure with a visible error', async p => {
  await ready(p); const read = p.refresh(); await tick(); const get = p.pending('GET');
  const sent = await send(p); p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  p.reply(get, { code: 500, message: 'Obsolete overview failure' }, 500); const result = await read.pending;
  eq(p.component().feedbackStatus, 'useful'); ok(p.component().personalizedSelected);
  eq(result, null, 'The failed overview is not returned as a successful read');
  ok(p.component().error.includes('Obsolete overview failure'));
  const html = await p.html(); ok(html.includes('已记录：这个重点合适')); ok(html.includes('Obsolete overview failure'));
  eq(p.component().loading, false); return {};
});
await scenario('old overview contract failure retains confirmed feedback but does not confirm shared overview freshness', async p => {
  await ready(p); const read = p.refresh(); await tick(); const get = p.pending('GET');
  const sent = await send(p); p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  const response = overview(); response.data.today_preview.contract_version = 'invalid';
  p.reply(get, response); eq(await read.pending, null);
  eq(p.component().feedbackStatus, 'useful'); ok(p.component().personalizedSelected);
  ok(p.component().error.includes('唯一选择合同')); ok((await p.html()).includes('唯一选择合同'));
  eq(p.requests.filter(row => row.options.method === 'POST').length, 1); return {};
});
await scenario('an overview started after confirmation remains authoritative even when feedback readback is unavailable', async p => {
  await ready(p); const sent = await send(p); p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  const read = p.refresh(); await tick(); p.reply(p.pending('GET'), overview('A', 'unavailable')); await read.pending;
  eq(p.component().feedbackStatus, ''); eq(p.component().feedbackReadbackBlocked, true);
  ok((await p.html()).includes('反馈回读暂不可用')); return {};
});
await scenario('feedback for newly displayed B owns its busy state when old A finally finishes', async p => {
  await ready(p); const a = await send(p); const read = p.refresh(); await tick(); p.reply(p.pending('GET'), overview('B')); await read.pending;
  const buttonB = p.button('daily-one-thing-feedback-useful'); const enabledB = !buttonB.props.disabled;
  if (!enabledB) { p.reply(a.req, postReceipt(a.req)); await a.pending; }
  ok(enabledB, 'Different stable material B is not locked by A feedback');
  const b = p.feedback('useful'); await tick(); const reqB = p.requests.find(row => row.options.method === 'POST' && row !== a.req && !row.settled); ok(reqB);
  p.reply(a.req, postReceipt(a.req)); await a.pending;
  eq(p.component().feedbackSaving, true); eq(p.button('daily-one-thing-feedback-wrong-focus').props.disabled, true); eq(p.component().feedbackStatus, '');
  p.reply(reqB, { code: 422, message: 'Synthetic current B rejection' }, 422); await b.pending;
  eq(p.component().feedbackSaving, false); eq(p.component().feedbackStatus, ''); ok(p.component().error.includes('current B rejection'));
  return {};
});
await scenario('incomplete legacy preview without material cannot pretend to identify a stable feedback slot', async p => {
  await tick(); const response = overview(); delete response.data.personalized_today_preview.selected.material_identity_digest;
  p.reply(p.pending('GET'), response); await tick(); const clicked = p.feedback('useful'); await tick();
  const unexpected = p.pending('POST'); if (unexpected) p.reply(unexpected, { code: 422, message: 'Synthetic missing material rejection' }, 422); await clicked.pending;
  eq(p.requests.filter(row => row.options.method === 'POST').length, 0); ok(p.component().error.includes('精确回读')); return {};
});
// Explicit component lifecycle control: this does not claim a new navigation/button defect.
await scenario('unmounted old feedback cannot notify or alter the new component after remount', async p => {
  await ready(p); const old = p.component(); const sent = await send(p);
  p.sandbox.currentPage.value = 'compass'; p.sandbox.pageRequestGeneration++; await tick(); eq(p.component(), null);
  p.sandbox.currentPage.value = 'operating-opportunities'; p.sandbox.pageRequestGeneration++; await tick();
  p.reply(p.pending('GET'), overview()); await tick(); ok(p.component() !== old); eq(p.component().feedbackStatus, '');
  p.reply(sent.req, postReceipt(sent.req)); await sent.pending;
  eq(p.component().feedbackStatus, ''); eq(p.notices, []); return {};
});

await scenario('old login feedback cannot notify or mark the same page after session rollover', async p => {
  await ready(p); const sent = await send(p);
  eq(typeof p.component().$root.assistantSessionEpoch, 'function');
  p.sandbox.authSessionEpoch++;
  p.reply(sent.req, postReceipt(sent.req));
  eq(await sent.pending, null);
  eq(p.component().feedbackStatus, '');
  eq(p.notices, []);
  return {};
});
await scenario('old login feedback failure cannot notify the new session', async p => {
  await ready(p); const sent = await send(p);
  p.sandbox.authSessionEpoch++;
  p.reply(sent.req, { code: 500, message: 'Synthetic old session failure' }, 500);
  eq(await sent.pending, null);
  eq(p.component().feedbackStatus, '');
  eq(p.notices, []);
  return {};
});

const receiptFaults = {
  'event reason': data => { data.feedback.reason_code = 'wrong_focus'; },
  'event status': data => { data.feedback.feedback_status = 'rejected'; },
  'event actor': data => { data.feedback.user_id = 12; },
  'event tenant': data => { data.feedback.tenant_id = 8; },
  'event hotel': data => { data.feedback.hotel_id = 81; },
  'event id missing': data => { delete data.feedback.id; },
  'event id boolean': data => { data.feedback.id = true; },
  'event id fractional': data => { data.feedback.id = 2.5; },
  'event unverified': data => { data.feedback.readback_verified = false; },
  'event date': data => { data.feedback.feedback_payload.business_date = '2026-09-14'; },
  'receipt contract': data => { data.contract_version = 'invalid'; },
  'receipt candidate': data => { data.selected_candidate_key = 'another'; },
  'receipt selection digest': data => { data.selection_digest = 'f'.repeat(64); },
  'receipt context digest': data => { data.context_digest = 'f'.repeat(64); },
  'receipt decision digest': data => { data.decision_digest = 'f'.repeat(64); },
  'snapshot linkage': data => { data.feedback.suggestion_id = 1002; },
  'snapshot id malformed': data => { data.snapshot.id = true; data.feedback.suggestion_id = true; },
  'snapshot candidate': data => { data.snapshot.suggestion_payload.candidate_key = 'another'; },
  'snapshot date': data => { data.snapshot.suggestion_payload.business_date = '2026-09-14'; },
  'same-scope other material': data => { data.snapshot.suggestion_payload.candidate_material_digest = calibrated['B|80|2026-09-15'].selected.material_identity_digest; },
};
for (const [name, mutate] of Object.entries(receiptFaults)) {
  await scenario('unverified feedback receipt is not claimed as recorded: ' + name, async p => {
    await ready(p); const sent = await send(p); const response = postReceipt(sent.req); mutate(response.data);
    p.reply(sent.req, response); eq(await sent.pending, null);
    eq(p.component().feedbackStatus, ''); eq(p.component().feedbackSaving, false);
    ok(p.component().error.includes('精确回读')); ok(!(await p.html()).includes('已记录：这个重点合适'));
    eq(p.notices.filter(row => row.type === 'success').length, 0);
    eq(p.requests.filter(row => row.options.method === 'POST').length, 1, 'No automatic replay of an unverified POST');
    return {};
  });
}
await scenario('same-slot replay accepts numeric string ids and original event digests under a new top-level selection context', async p => {
  await tick(); const response = overview();
  response.data.personalized_today_preview.selected = clone(calibrated['A_explanation|80|2026-09-15'].selected);
  for (const receipt of [response.data.personalization_receipt, response.data.personalized_today_preview.personalization_receipt]) {
    receipt.context_digest = 'e'.repeat(64); receipt.decision_digest = 'd'.repeat(64);
  }
  p.reply(p.pending('GET'), response); await tick();
  const sent = await send(p); const replay = postReceipt(sent.req);
  replay.data.feedback.created = false; replay.data.feedback.idempotent_replay = true;
  for (const field of ['id', 'suggestion_id', 'tenant_id', 'user_id', 'hotel_id']) replay.data.feedback[field] = String(replay.data.feedback[field]);
  replay.data.snapshot.id = String(replay.data.snapshot.id);
  ok(replay.data.selection_digest !== replay.data.feedback.feedback_payload.selection_digest);
  ok(replay.data.context_digest !== replay.data.feedback.feedback_payload.context_digest);
  ok(!replay.data.snapshot.feedback_events, 'First snapshot does not need an appended event array');
  p.reply(sent.req, replay); ok(await sent.pending); eq(p.component().feedbackStatus, 'useful');
  ok((await p.html()).includes('已记录：这个重点合适')); return {};
});
for (const firstCompleted of ['A', 'B']) {
await scenario('date A to B to A preserves both pending feedback owners when ' + firstCompleted + ' completes first', async p => {
  await ready(p); const a = await send(p);
  const setDate = async date => {
    const input = p.nodes().find(node => node.type === 'input' && node.props?.type === 'date');
    ok(input && !input.props.disabled, 'Only enabled original date input'); input.props.onInput({ target: { value: date } }); await tick();
    p.reply(p.pending('GET'), overview('A', 'not_recorded', 80, date)); await tick();
  };
  await setDate('2026-09-14'); const b = p.feedback('useful'); await tick();
  const bReq = p.requests.find(row => row.options.method === 'POST' && row !== a.req && !row.settled); ok(bReq);
  await setDate('2026-09-15'); const pendingAWasLocked = p.button('daily-one-thing-feedback-useful').props.disabled;
  if (firstCompleted === 'B') {
    p.reply(bReq, { code: 422, message: 'Synthetic B rejection' }, 422); await b.pending;
    eq(p.component().feedbackSaving, true); eq(p.component().error, '');
  }
  p.reply(a.req, postReceipt(a.req)); await a.pending;
  if (firstCompleted === 'A') {
    p.reply(bReq, { code: 422, message: 'Synthetic B rejection' }, 422); await b.pending;
  }
  eq(pendingAWasLocked, true, 'Returning to A does not forget the still-pending A write');
  eq(p.component().feedbackStatus, 'useful'); eq(p.component().error, '');
  eq(p.requests.filter(row => row.options.method === 'POST').length, 2); return {};
});
}

after(() => console.log('Personal feedback assertions: ' + assertions));
