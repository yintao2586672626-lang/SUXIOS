# 携程采集结果审计

- 生成时间：2026-07-07T19:25:41.130Z
- 输入文件数：1
- 已归档接口响应数：716
- 已抽取字段事实数：6913
- 可入库标准行数：481
- 正式接口覆盖：51/53
- 字段覆盖：117/228
- 不适用模块：-
- 登录状态：ok_or_unverified
- 未归档接口候选数：11
- 页面交互触发：121/203
- 页面交互未触发/异常：81/1
- P3证据草稿数：19
- P3完整证据数：8

## 已归档模块覆盖

| 模块 | 响应数 | 字段事实数 | 标准行数 | 已命中接口 | 已命中字段 |
|---|---:|---:|---:|---|---|

## capture_gap_report

- Status: needs_evidence
- Blockers: -
- Not applicable sections: -
- Missing formal endpoints: 2
- P3 candidate sections: traffic_report, orders_detail, promotion

| Action | Section | Endpoint/Field | Reason |
|---|---|---|---|
| capture_missing_formal_endpoint | ads_pyramid | ads_interpretation | endpoint_coverage_missing |
| capture_missing_formal_endpoint | traffic_report | traffic_flow_source_popups | endpoint_coverage_missing |
| capture_missing_fields | ads_pyramid | ad_impressions, ad_order_amount, ad_orders, ad_room_nights, campaign_id, config_name, config_value, ctr, cvr, diagnosis_text, notice_count, notice_text, notice_title, peer_avg, peer_top, roas, target_url | field_coverage_missing |
| capture_missing_fields | business_overview | advice_count, advice_text, bad_review_tag, competitor_average, competitor_deal_rate, competitor_flow_rate, competitor_number, competitor_order_fill_rate, config_name, config_value, conversion_rate, deal_rate, deduct_score, diagnosis_level, diagnosis_score, im_score, keyword, notice_count, notice_text, notice_title, order_fill_rate, page_views, qunar_competitor_deal_rate, qunar_competitor_detail_visitor, qunar_competitor_flow_rate, qunar_competitor_list_exposure, qunar_competitor_order_fill_rate, qunar_competitor_order_page_visitor, qunar_competitor_order_submit_user, qunar_deal_rate, qunar_detail_visitor, qunar_flow_rate, qunar_list_exposure, qunar_order_fill_rate, qunar_order_page_visitor, qunar_order_submit_user, rank, source_name, target_url, tensity | field_coverage_missing |
| capture_missing_fields | business_weekly_overview | avg_booking_days, avg_price, avg_stay_days, avg_user_age, booking_days, booking_hour, booking_method, competitor_average, competitor_avg_visitor, competitor_deal_rate, competitor_detail_visitor, competitor_flow_rate, competitor_list_exposure, competitor_order_fill_rate, consumption_power, conversion_rate, conversion_rate_rank, deal_rate, detail_visitor, distribution_share, flow_rate, hotel_star_preference, keyword, list_exposure, loss_order_amount, loss_order_count, loss_room_nights, occupancy_rate, order_amount, order_count, order_fill_rate, order_hotel_count, order_preference, order_rank, page_views, preference_frequency, price_band, price_sensitivity, qunar_competitor_avg_visitor, qunar_competitor_deal_rate | field_coverage_missing |
| capture_missing_fields | comment_review | comment_date, comment_good_rate, comment_response_rate, comment_store_name, comment_unreply_count, review_cleanliness_score, review_environment_score, review_facility_score, review_photo_count, review_photo_rate, review_service_score, target_url | field_coverage_missing |
| capture_missing_fields | competitor_overview | bad_review_tag, base_score, comment_count, comment_good_rate, comment_response_rate, comment_unreply_count, competitor_average, competitor_avg_visitor, competitor_deal_rate, competitor_detail_visitor, competitor_flow_rate, competitor_list_exposure, competitor_order_fill_rate, competitor_order_page_visitor, competitor_order_submit_user, conversion_rate, ctrip_comment_count, ctrip_rating, ctrip_rating_rank, deal_rate, deduct_score, elong_comment_count, elong_rating, hotel_collect, hotel_collect_rank, hotel_label, im_score, keyword, list_exposure, order_fill_rate, order_page_visitor, page_views, qunar_comment_count, qunar_competitor_avg_visitor, qunar_competitor_deal_rate, qunar_competitor_detail_visitor, qunar_competitor_flow_rate, qunar_competitor_list_exposure, qunar_competitor_order_fill_rate, qunar_competitor_order_page_visitor | field_coverage_missing |
| capture_missing_fields | competitor_rank | comment_score_rank, competition_rank_app_conversion_rate, competition_rank_app_detail_visitor, competition_rank_ctrip_rating, competition_rank_qunar_rating, competition_rank_tongcheng_rating, competition_rank_zhixing_rating, conversion_rate_rank, order_rank, rank_metric, room_nights_rank, traffic_rank, visitor_rank | field_coverage_missing |
| capture_missing_fields | quality_psi | bad_review_tag, base_score, comment_count, comment_good_rate, comment_response_rate, comment_score_summary, comment_unreply_count, course_title, course_url, ctrip_comment_count, ctrip_rating, ctrip_rating_rank, deduct_score, elong_comment_count, elong_rating, hotel_collect, hotel_collect_rank, im_score, psi_basic_item_activity_name, psi_basic_item_activity_url, psi_basic_item_code, psi_basic_item_end_date, psi_basic_item_id, psi_basic_item_name, psi_basic_item_rank, psi_basic_item_score, psi_basic_item_score_gap, psi_basic_item_score_gap_unit, psi_basic_item_start_date, psi_basic_item_tips, psi_basic_item_type, psi_basic_item_weight, qunar_comment_count, qunar_rating, qunar_rating_rank, rating_competitor_total, reply_rank, reply_rate, review_cleanliness_score, review_environment_score | field_coverage_missing |
| capture_missing_fields | traffic_report | avg_price, bad_review_tag, base_score, city_hot_search_keyword, city_hot_search_pv, city_hot_search_uv, comment_count, comment_good_rate, comment_unreply_count, competitor_average, competitor_avg_source_proportion, competitor_avg_visitor, competitor_deal_rate, competitor_flow_rate, competitor_order_fill_rate, config_name, config_value, conversion_rate, deal_rate, deduct_score, hotel_collect, hotel_collect_rank, im_score, keyword, min_price, min_price_rank, notice_count, notice_text, notice_title, occupancy_rate, order_amount, order_fill_rate, page_views, qunar_competitor_avg_visitor, qunar_competitor_deal_rate, qunar_competitor_detail_visitor, qunar_competitor_flow_rate, qunar_competitor_list_exposure, qunar_competitor_order_fill_rate, qunar_competitor_order_page_visitor | field_coverage_missing |
| capture_missing_fields | user_profile | avg_price, competitor_average, conversion_rate, occupancy_rate, order_amount, order_count, rank, room_nights, strategy, tensity | field_coverage_missing |
| collect_p3_devtools_evidence | traffic_report | - | p3_candidate_needs_evidence |
| collect_p3_devtools_evidence | orders_detail | - | p3_candidate_needs_evidence |
| collect_p3_devtools_evidence | promotion | - | p3_candidate_needs_evidence |
| ads_pyramid | 195 | 268 | 0 | ads_click_live, ads_diagnosis, ads_diagnostic_details, ads_dynamic_config, ads_filters, ads_peer_comparison, ads_report_injection, ads_report_list, ads_resource_yellow_bar, ads_summary_report | ad_clicks, ad_cost |
| business_overview | 217 | 811 | 8 | business_capacity, business_flow_compete, business_flow_transform, business_hotel_seq, business_market_overview, business_realtime, business_service_quantity, business_visitor_title, hotel_advice, platform_notifications, platform_resource_popups | amount_rank, avg_price, avg_price_last_week, avg_price_rank, close_rate, close_rate_last_week, close_rate_rank, comment_score_summary, competitor_avg_occupied_rooms, competitor_avg_orders, competitor_avg_visitor, competitor_detail_visitor, competitor_list_exposure, competitor_order_page_visitor, competitor_order_submit_user, competitor_orders, competitor_revenue, competitor_visitor, ctrip_order_count, ctrip_order_count_rank, ctrip_order_count_sync, ctrip_rating, detail_visitor, elong_order_count, elong_order_count_rank, elong_order_count_sync, flow_rate, hotel_collect, hotel_collect_rank, list_exposure, list_exposure+competitor_list_exposure+detail_visitor, occupancy_rate, occupancy_rate_rank, occupancy_rate_sync, occupied_rooms, occupied_rooms+occupied_rooms_sync+occupied_rooms_rank, occupied_rooms_rank, occupied_rooms_sync, order_amount, order_amount+order_amount_last_week+amount_rank, order_amount_last_week, order_count, order_count+competitor_orders+competitor_visitor, order_count_rank, order_count_sync, order_page_visitor, order_submit_user, psi_score, psi_score+service_score_rank+comment_score_summary, quantity_rank, qunar_competitor_avg_visitor, qunar_order_count, qunar_order_count_rank, qunar_order_count_sync, qunar_visitor_count, qunar_visitor_count_last_week, qunar_visitor_rank, reply_rank, reply_rate, room_nights, room_nights_last_week, seq_rank, service_score_rank, visitor_count, visitor_count+order_count, visitor_count_last_week, visitor_rank, weekly_order_page_visitor, weekly_submit_user |
| business_weekly_overview | 11 | 139 | 36 | weekly_compete_report, weekly_report | amount_rank, amount_rank+comment_score_rank+visitor_rank, comment_score_rank, competitor_order_page_visitor, competitor_order_submit_user, flow_lost_amount, flow_lost_room_nights, flow_lost_room_nights+flow_lost_amount, hot_hotels_count, hot_hotels_count+top_hot_hotels, hot_words_count, hot_words_count+top_hot_words, last_week_bad_add, last_week_book_quantity, last_week_book_room_nights, last_week_book_sales, last_week_checkout_room_nights, last_week_checkout_room_nights+last_week_checkout_sales+last_week_checkout_room_price, last_week_checkout_room_price, last_week_checkout_sales, last_week_comment_score, last_week_comment_score+last_week_good_add+last_week_bad_add, last_week_good_add, last_week_price_score, order_page_visitor, order_submit_user, top_competitor_deal_rate, top_competitor_detail_exposure, top_competitor_flow_rate, top_competitor_list_exposure, top_competitor_list_exposure+top_competitor_detail_exposure+top_competitor_order_filling_num, top_competitor_order_fill_rate, top_competitor_order_filling_num, top_competitor_order_submit_num, top_flow_hotel, top_flow_hotel+top_flow_hotel_browse_rate+top_flow_hotel_order_rate, top_flow_hotel_browse_rate, top_flow_hotel_order_rate, top_hot_hotels, top_hot_room, top_hot_room+top_hot_room_nights+top_hot_room_sale_percent, top_hot_room_nights, top_hot_room_sale_percent, top_hot_words, visitor_rank, weekly_competitor_deal_rate, weekly_competitor_detail_exposure, weekly_competitor_flow_rate, weekly_competitor_list_exposure, weekly_competitor_list_exposure+weekly_competitor_detail_exposure+weekly_competitor_order_filling_num, weekly_competitor_order_fill_rate, weekly_competitor_order_filling_num, weekly_competitor_order_submit_num, weekly_order_page_visitor, weekly_self_deal_rate, weekly_self_detail_exposure, weekly_self_flow_rate, weekly_self_list_exposure, weekly_self_list_exposure+weekly_self_detail_exposure+weekly_self_order_filling_num, weekly_self_order_fill_rate, weekly_self_order_filling_num, weekly_self_order_submit_num, weekly_submit_user |
| comment_review | 10 | 96 | 14 | comment_hotel_rating, comment_review_aggregate | bad_review_count, comment_channel, comment_channel+comment_count+ctrip_comment_count, comment_channel+comment_count+elong_comment_count, comment_channel+comment_count+qunar_comment_count, comment_channel+comment_count+zx_comment_count, comment_count, comment_count+bad_review_count, comment_score, ctrip_comment_count, elong_comment_count, qunar_comment_count, zx_comment_count |
| competitor_overview | 29 | 78 | 11 | competitor_flow, competitor_flow_source, competitor_hotel_label, competitor_management, competitor_service | avg_price, comment_score_summary, detail_visitor, flow_rate, occupancy_rate, order_amount, order_count, order_submit_user, psi_score, room_nights, visitor_count |
| competitor_rank | 14 | 1320 | 89 | competitor_rank | amount_rank, competition_rank_occupancy_rate, competition_rank_order_amount, competition_rank_order_count, competition_rank_order_count+competition_rank_order_amount+amount_rank, competition_rank_psi_score, competition_rank_room_nights, occupancy_rate_rank |
| im_board | 15 | 1161 | 91 | im_index, im_trend | five_min_reply_rate, five_min_reply_rate+im_rank+manual_reply_rate, five_min_reply_rate+manual_reply_rate+robot_resolution_rate, im_order_conversion_rate, im_rank, manual_reply_rate, manual_session_count, robot_resolution_rate, robot_session_count, robot_session_count+manual_session_count, session_count, session_count+manual_session_count |
| quality_psi | 12 | 90 | 45 | psi_course, psi_growth_task, psi_history, psi_overview | psi_score |
| traffic_report | 87 | 958 | 73 | traffic_city_keywords, traffic_comment_score_summary, traffic_flow_source, traffic_flow_transform, traffic_hotel_min_price, traffic_hotel_seq, traffic_menu_key, traffic_order_overview, traffic_order_trend, traffic_picture_quality, traffic_scan_flow, traffic_search_details | comment_response_rate, comment_score_summary, competitor_detail_visitor, competitor_list_exposure, competitor_order_page_visitor, competitor_order_submit_user, ctrip_comment_count, ctrip_comment_count+qunar_comment_count+elong_comment_count, ctrip_rating, ctrip_rating_rank, detail_visitor, elong_comment_count, elong_rating, flow_rate, list_exposure, list_exposure+competitor_list_exposure+detail_visitor, order_count, order_page_visitor, order_submit_user, psi_score, qunar_comment_count, qunar_rating, qunar_rating_rank, rating_competitor_total, traffic_rank, weekly_order_page_visitor, weekly_submit_user |
| user_profile | 126 | 1992 | 114 | user_profile_dimensions, user_profile_features | avg_booking_days, avg_stay_days, avg_user_age, booking_days, booking_days+distribution_share, booking_hour, booking_method, booking_method+distribution_share, consumption_power, consumption_power+distribution_share, distribution_share, hotel_star_preference, hotel_star_preference+distribution_share, order_hotel_count, order_hotel_count+distribution_share, order_preference, order_preference+preference_frequency+distribution_share, preference_frequency, price_band, price_sensitivity, price_sensitivity+distribution_share, source_city, source_city+distribution_share, source_region, source_region+distribution_share, stay_days, stay_days+distribution_share, travel_time, user_age, user_age+distribution_share, user_age+user_sex+user_source, user_sex, user_sex+distribution_share, user_source, user_source_scope, user_source_scope+distribution_share, user_type, user_type+distribution_share |

## 登录状态

- 状态：ok_or_unverified
- 登录页数量：0

## 正式接口覆盖

| 模块 | 预期接口 | 已命中 | 缺失 | 缺失接口 |
|---|---:|---:|---:|---|
| ads_pyramid | 11 | 10 | 1 | ads_interpretation |
| business_overview | 11 | 11 | 0 | - |
| business_weekly_overview | 2 | 2 | 0 | - |
| comment_review | 2 | 2 | 0 | - |
| competitor_overview | 5 | 5 | 0 | - |
| competitor_rank | 1 | 1 | 0 | - |
| im_board | 2 | 2 | 0 | - |
| quality_psi | 4 | 4 | 0 | - |
| traffic_report | 13 | 12 | 1 | traffic_flow_source_popups |
| user_profile | 2 | 2 | 0 | - |

## 字段覆盖

| 模块 | 预期字段 | 已命中 | 缺失 | 缺失字段 |
|---|---:|---:|---:|---|
| ads_pyramid | 19 | 2 | 17 | ad_impressions, ad_order_amount, ad_orders, ad_room_nights, campaign_id, config_name, config_value, ctr, cvr, diagnosis_text, notice_count, notice_text, notice_title, peer_avg, peer_top, roas, target_url |
| business_overview | 107 | 63 | 44 | advice_count, advice_text, bad_review_tag, competitor_average, competitor_deal_rate, competitor_flow_rate, competitor_number, competitor_order_fill_rate, config_name, config_value, conversion_rate, deal_rate, deduct_score, diagnosis_level, diagnosis_score, im_score, keyword, notice_count, notice_text, notice_title, order_fill_rate, page_views, qunar_competitor_deal_rate, qunar_competitor_detail_visitor, qunar_competitor_flow_rate, qunar_competitor_list_exposure, qunar_competitor_order_fill_rate, qunar_competitor_order_page_visitor, qunar_competitor_order_submit_user, qunar_deal_rate |
| business_weekly_overview | 83 | 9 | 74 | avg_booking_days, avg_price, avg_stay_days, avg_user_age, booking_days, booking_hour, booking_method, competitor_average, competitor_avg_visitor, competitor_deal_rate, competitor_detail_visitor, competitor_flow_rate, competitor_list_exposure, competitor_order_fill_rate, consumption_power, conversion_rate, conversion_rate_rank, deal_rate, detail_visitor, distribution_share, flow_rate, hotel_star_preference, keyword, list_exposure, loss_order_amount, loss_order_count, loss_room_nights, occupancy_rate, order_amount, order_count |
| comment_review | 20 | 8 | 12 | comment_date, comment_good_rate, comment_response_rate, comment_store_name, comment_unreply_count, review_cleanliness_score, review_environment_score, review_facility_score, review_photo_count, review_photo_rate, review_service_score, target_url |
| competitor_overview | 85 | 11 | 74 | bad_review_tag, base_score, comment_count, comment_good_rate, comment_response_rate, comment_unreply_count, competitor_average, competitor_avg_visitor, competitor_deal_rate, competitor_detail_visitor, competitor_flow_rate, competitor_list_exposure, competitor_order_fill_rate, competitor_order_page_visitor, competitor_order_submit_user, conversion_rate, ctrip_comment_count, ctrip_rating, ctrip_rating_rank, deal_rate, deduct_score, elong_comment_count, elong_rating, hotel_collect, hotel_collect_rank, hotel_label, im_score, keyword, list_exposure, order_fill_rate |
| competitor_rank | 20 | 7 | 13 | comment_score_rank, competition_rank_app_conversion_rate, competition_rank_app_detail_visitor, competition_rank_ctrip_rating, competition_rank_qunar_rating, competition_rank_tongcheng_rating, competition_rank_zhixing_rating, conversion_rate_rank, order_rank, rank_metric, room_nights_rank, traffic_rank, visitor_rank |
| im_board | 8 | 8 | 0 | - |
| quality_psi | 51 | 1 | 50 | bad_review_tag, base_score, comment_count, comment_good_rate, comment_response_rate, comment_score_summary, comment_unreply_count, course_title, course_url, ctrip_comment_count, ctrip_rating, ctrip_rating_rank, deduct_score, elong_comment_count, elong_rating, hotel_collect, hotel_collect_rank, im_score, psi_basic_item_activity_name, psi_basic_item_activity_url, psi_basic_item_code, psi_basic_item_end_date, psi_basic_item_id, psi_basic_item_name, psi_basic_item_rank, psi_basic_item_score, psi_basic_item_score_gap, psi_basic_item_score_gap_unit, psi_basic_item_start_date, psi_basic_item_tips |
| traffic_report | 101 | 25 | 76 | avg_price, bad_review_tag, base_score, city_hot_search_keyword, city_hot_search_pv, city_hot_search_uv, comment_count, comment_good_rate, comment_unreply_count, competitor_average, competitor_avg_source_proportion, competitor_avg_visitor, competitor_deal_rate, competitor_flow_rate, competitor_order_fill_rate, config_name, config_value, conversion_rate, deal_rate, deduct_score, hotel_collect, hotel_collect_rank, im_score, keyword, min_price, min_price_rank, notice_count, notice_text, notice_title, occupancy_rate |
| user_profile | 33 | 23 | 10 | avg_price, competitor_average, conversion_rate, occupancy_rate, order_amount, order_count, rank, room_nights, strategy, tensity |

## 页面交互触发覆盖

| 模块 | 页面数 | 计划动作 | 已点击 | 未点击 | 异常 | 未触发动作 |
|---|---:|---:|---:|---:|---:|---|
| ads_pyramid | 10 | 80 | 40 | 40 | 0 | 同行对比, 我的数据, 数据报告, 计划维度, 诊断报告, 账户维度, 过去30天, 过去7天 |
| business_overview | 1 | 2 | 0 | 2 | 0 | 实时, 按日 |
| business_weekly_overview | 2 | 2 | 0 | 2 | 0 | 按周 |
| comment_review | 1 | 3 | 1 | 2 | 0 | 全部, 点评列表 |
| competitor_overview | 3 | 21 | 9 | 11 | 1 | 上周, 上月, 实时, 昨日, 竞争圈动态, 竞争圈概览 |
| competitor_rank | 4 | 44 | 30 | 14 | 0 | 上周, 上月, 实时, 携程, 昨日, 流量排名, 竞争圈动态, 竞争圈榜单, 销售排名 |
| im_board | 3 | 9 | 6 | 3 | 0 | IM看板, 用户行为 |
| quality_psi | 3 | 3 | 2 | 1 | 0 | 历史得分 |
| traffic_report | 1 | 27 | 24 | 3 | 0 | 微信小程序, 手机APP, 昨日 |
| user_profile | 4 | 12 | 9 | 3 | 0 | 用户分析, 用户行为 |

## 未归档接口候选

| 候选方向 | 数量 | 状态 | 需要补充 | 样例接口 |
|---|---:|---|---|---|
| traffic_report (经营报告-流量数据) | 3 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?micro=true&microJump=true |
| orders_detail (订单明细) | 1 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | https://ebooking.ctrip.com/ebkorderv2/api/order/domestic/unprocessOrderList?_fxpcqlniredt=09031060219661935072&x-traceID=09031060219661935072-1783451531477-5259267 |
| price_inventory (价格房态) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| promotion (促销活动) | 7 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | https://ebooking.ctrip.com/toolcenter/api/coupon/queryCouponPopInfo?hostType=HE&v=0.05475780165424882 |
| settlement_finance (结算财务) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| contract_mice_rfp (合同 / MICE / RFP) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |

## P3 证据草稿覆盖

| 候选方向 | 状态 | 完整证据 | 不完整证据 | 字段草案 | 缺失证据 |
|---|---|---:|---:|---:|---|
| traffic_report | incomplete_evidence | 0 | 3 | 0 | Payload |
| orders_detail | ready_for_review | 2 | 0 | 30 | - |
| price_inventory | missing_evidence | 0 | 0 | 0 | - |
| promotion | ready_for_review | 6 | 8 | 420 | Payload |
| settlement_finance | missing_evidence | 0 | 0 | 0 | - |
| contract_mice_rfp | missing_evidence | 0 | 0 | 0 | - |

## 下一步证据

- Request URL
- Payload
- Preview / Response
- page/tab context
- hotel/date parameters
