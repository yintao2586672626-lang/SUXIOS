# 携程采集结果审计

- 生成时间：2026-05-31T05:16:32.916Z
- 输入文件数：1
- 已归档接口响应数：0
- 已抽取字段事实数：0
- 可入库标准行数：0
- 正式接口覆盖：0/34
- 字段覆盖：0/57
- 登录状态：login_required
- 未归档接口候选数：0
- 页面交互触发：0/0
- 页面交互未触发/异常：0/0
- P3证据草稿数：0
- P3完整证据数：0

## 已归档模块覆盖

| 模块 | 响应数 | 字段事实数 | 标准行数 | 已命中接口 | 已命中字段 |
|---|---:|---:|---:|---|---|

## Capture Gate

- Status: fail
- Failed checks: auth_session, response_count, standard_rows, endpoint_coverage, field_coverage

| Check | Status | Actual | Expected | Message |
|---|---|---|---|---|
| auth_session | fail | login_required | logged_in or ok_or_unverified | Capture must not be a Ctrip login page or partial login redirect. |
| response_count | fail | 0 | >=1 | Capture must include business XHR/fetch responses. |
| standard_rows | fail | 0 | >=1 | Capture must produce rows that can feed SUXIOS OTA analytics. |
| endpoint_coverage | fail | 0/34 | missing<=0 | Requested Ctrip sections must hit their cataloged formal endpoints. |
| field_coverage | fail | 0% | >=80% | Requested Ctrip sections must extract the configured catalog fields. |

## capture_gap_report

- Status: blocked_auth
- Blockers: auth_session, response_count, standard_rows
- Missing formal endpoints: 34
- P3 candidate sections: -

| Action | Section | Endpoint/Field | Reason |
|---|---|---|---|
| login_and_rerun_capture | - | - | login_required |
| capture_business_xhr | - | - | response_count_zero |
| verify_standard_row_mapping | - | - | standard_row_count_zero |
| capture_missing_formal_endpoint | business_overview | business_capacity | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | business_flow_compete | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | business_flow_transform | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | business_hotel_seq | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | business_market_overview | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | business_realtime | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | business_service_quantity | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | business_visitor_title | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | hotel_advice | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | platform_notifications | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | platform_resource_popups | endpoint_coverage_missing |
| capture_missing_formal_endpoint | business_overview | weekly_report | endpoint_coverage_missing |
| capture_missing_formal_endpoint | homepage | homepage_realtime | endpoint_coverage_missing |
| capture_missing_formal_endpoint | room_type | room_competing_hotels | endpoint_coverage_missing |
| capture_missing_formal_endpoint | room_type | room_competitive_market | endpoint_coverage_missing |
| capture_missing_formal_endpoint | room_type | room_type_info | endpoint_coverage_missing |
| capture_missing_formal_endpoint | room_type | room_venderbility | endpoint_coverage_missing |
| capture_missing_formal_endpoint | sales_report | sales_market_detail | endpoint_coverage_missing |
| capture_missing_formal_endpoint | sales_report | sales_market_room_tensity | endpoint_coverage_missing |
| capture_missing_formal_endpoint | sales_report | sales_min_price | endpoint_coverage_missing |
| capture_missing_formal_endpoint | sales_report | sales_occupied_room_trend | endpoint_coverage_missing |
| capture_missing_formal_endpoint | sales_report | sales_order_trend | endpoint_coverage_missing |
| capture_missing_formal_endpoint | sales_report | sales_tensities | endpoint_coverage_missing |
| capture_missing_formal_endpoint | sales_report | sales_tensity_overview | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_city_keywords | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_comment_score_summary | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_flow_source | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_flow_transform | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_hotel_min_price | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_order_overview | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_order_trend | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_picture_quality | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_scan_flow | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_search_details | endpoint_coverage_missing |
| capture_missing_fields | business_overview | advice_count, advice_text, avg_price, base_score, booking_days, comment_score_summary, competitor_average, config_name, config_value, conversion_rate, deduct_score, detail_visitor, diagnosis_level, diagnosis_score, flow_rate, hotel_collect, im_score, keyword, list_exposure, notice_text, notice_title, occupancy_rate, order_amount, order_count, order_page_visitor, order_submit_user, price_band, psi_score, rank, reply_rate, reward_score, room_nights, seq_rank, source_name, stay_days, strategy, target_url, tensity, user_age, user_sex | field_coverage_missing |
| capture_missing_fields | homepage | avg_price, base_score, comment_score_summary, competitor_average, conversion_rate, deduct_score, detail_visitor, flow_rate, hotel_collect, im_score, keyword, list_exposure, loss_order_count, occupancy_rate, order_amount, order_count, order_page_visitor, order_submit_user, psi_score, rank, reply_rate, reward_score, room_nights, source_name, target_url, tensity, visitor_count | field_coverage_missing |
| capture_missing_fields | room_type | available_room, avg_price, cancel_rate, competitor_average, competitor_hotel_name, conversion_rate, distance, occupancy_rate, order_amount, order_count, rank, room_nights, room_type_id, room_type_name, sale_status, star_level, suggest_action, tensity, total_room, zone_name | field_coverage_missing |
| capture_missing_fields | sales_report | avg_price, competitor_average, conversion_rate, min_price, min_price_rank, occupancy_rate, order_amount, order_count, rank, room_nights, tensity | field_coverage_missing |
| capture_missing_fields | traffic_report | avg_price, base_score, comment_score_summary, competitor_average, conversion_rate, deduct_score, detail_visitor, flow_rate, hotel_collect, im_score, keyword, list_exposure, min_price, min_price_rank, occupancy_rate, order_amount, order_count, order_page_visitor, order_submit_user, psi_score, rank, reply_rate, reward_score, room_nights, source_name, tensity, visitor_count | field_coverage_missing |

## 登录状态

- 状态：login_required
- 登录页数量：1
- 登录页：https://ebooking.ctrip.com/login/index?targetPath=%2Fdatacenter%2Finland%2Fbusinessreport%2Foutline%3FmicroJump%3Dtrue

## 正式接口覆盖

| 模块 | 预期接口 | 已命中 | 缺失 | 缺失接口 |
|---|---:|---:|---:|---|
| business_overview | 12 | 0 | 12 | business_capacity, business_flow_compete, business_flow_transform, business_hotel_seq, business_market_overview, business_realtime, business_service_quantity, business_visitor_title, hotel_advice, platform_notifications, platform_resource_popups, weekly_report |
| homepage | 1 | 0 | 1 | homepage_realtime |
| room_type | 4 | 0 | 4 | room_competing_hotels, room_competitive_market, room_type_info, room_venderbility |
| sales_report | 7 | 0 | 7 | sales_market_detail, sales_market_room_tensity, sales_min_price, sales_occupied_room_trend, sales_order_trend, sales_tensities, sales_tensity_overview |
| traffic_report | 10 | 0 | 10 | traffic_city_keywords, traffic_comment_score_summary, traffic_flow_source, traffic_flow_transform, traffic_hotel_min_price, traffic_order_overview, traffic_order_trend, traffic_picture_quality, traffic_scan_flow, traffic_search_details |

## 字段覆盖

| 模块 | 预期字段 | 已命中 | 缺失 | 缺失字段 |
|---|---:|---:|---:|---|
| business_overview | 43 | 0 | 43 | advice_count, advice_text, avg_price, base_score, booking_days, comment_score_summary, competitor_average, config_name, config_value, conversion_rate, deduct_score, detail_visitor, diagnosis_level, diagnosis_score, flow_rate, hotel_collect, im_score, keyword, list_exposure, notice_text, notice_title, occupancy_rate, order_amount, order_count, order_page_visitor, order_submit_user, price_band, psi_score, rank, reply_rate |
| homepage | 27 | 0 | 27 | avg_price, base_score, comment_score_summary, competitor_average, conversion_rate, deduct_score, detail_visitor, flow_rate, hotel_collect, im_score, keyword, list_exposure, loss_order_count, occupancy_rate, order_amount, order_count, order_page_visitor, order_submit_user, psi_score, rank, reply_rate, reward_score, room_nights, source_name, target_url, tensity, visitor_count |
| room_type | 20 | 0 | 20 | available_room, avg_price, cancel_rate, competitor_average, competitor_hotel_name, conversion_rate, distance, occupancy_rate, order_amount, order_count, rank, room_nights, room_type_id, room_type_name, sale_status, star_level, suggest_action, tensity, total_room, zone_name |
| sales_report | 11 | 0 | 11 | avg_price, competitor_average, conversion_rate, min_price, min_price_rank, occupancy_rate, order_amount, order_count, rank, room_nights, tensity |
| traffic_report | 27 | 0 | 27 | avg_price, base_score, comment_score_summary, competitor_average, conversion_rate, deduct_score, detail_visitor, flow_rate, hotel_collect, im_score, keyword, list_exposure, min_price, min_price_rank, occupancy_rate, order_amount, order_count, order_page_visitor, order_submit_user, psi_score, rank, reply_rate, reward_score, room_nights, source_name, tensity, visitor_count |

## 页面交互触发覆盖

| 模块 | 页面数 | 计划动作 | 已点击 | 未点击 | 异常 | 未触发动作 |
|---|---:|---:|---:|---:|---:|---|
| auth | 1 | 0 | 0 | 0 | 0 | - |

## 未归档接口候选

| 候选方向 | 数量 | 状态 | 需要补充 | 样例接口 |
|---|---:|---|---|---|
| orders_detail (订单明细) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| price_inventory (价格房态) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| promotion (促销活动) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| settlement_finance (结算财务) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| contract_mice_rfp (合同 / MICE / RFP) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |

## P3 证据草稿覆盖

| 候选方向 | 状态 | 完整证据 | 不完整证据 | 字段草案 | 缺失证据 |
|---|---|---:|---:|---:|---|
| orders_detail | missing_evidence | 0 | 0 | 0 | - |
| price_inventory | missing_evidence | 0 | 0 | 0 | - |
| promotion | missing_evidence | 0 | 0 | 0 | - |
| settlement_finance | missing_evidence | 0 | 0 | 0 | - |
| contract_mice_rfp | missing_evidence | 0 | 0 | 0 | - |

## 下一步证据

- 暂无未归档候选接口。
