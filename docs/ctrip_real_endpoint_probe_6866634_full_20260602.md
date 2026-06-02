# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 6866634
- OTA hotelId: 6866634
- Auth: logged_in
- Captured at: 2026-06-02T12:53:33.644Z
- Responses: 350
- Catalog facts: 8905
- Standard rows: 1425

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 首页实时概览 | homepage_realtime | captured_with_fields | 200 | 2 | 86 | 24 | visitor_count, rank, order_amount |
| 经营报告-概要 | hotel_advice | captured_with_fields | 200 | 2 | 24 | 6 | hotel_id, advice_text |
| 经营报告-概要 | business_realtime | captured_with_fields | 200 | 1 | 6 | 1 | hotel_id, visitor_count, order_count |
| 经营报告-概要 | business_flow_compete | captured_with_fields | 200 | 1 | 7 | 1 | hotel_id, order_count, competitor_orders, competitor_visitor, order_amount, competitor_revenue |
| 经营报告-概要 | business_capacity | http_seen_no_success_body | 200 | 0 | 21 | 3 | room_nights, competitor_average, order_count, rank, occupancy_rate |
| 经营报告-概要 | business_visitor_title | http_seen_no_success_body | 200 | 0 | 15 | 3 | visitor_count, visitor_rank, competitor_avg_visitor, qunar_competitor_avg_visitor |
| 经营报告-概要 | business_market_overview | captured_with_fields | 200 | 1 | 13 | 1 | order_amount, rank, room_nights, conversion_rate, occupancy_rate, avg_price |
| 经营报告-概要 | business_flow_transform | http_seen_no_success_body | 200 | 0 | 16 | 2 | date, list_exposure, detail_visitor, flow_rate, order_page_visitor, order_submit_user, hotel_id |
| 经营报告-概要 | business_service_quantity | captured_with_fields | 200 | 1 | 12 | 2 | deduct_score, psi_score, service_score, service_score_rank, comment_score_summary, ctrip_rating, im_score, reply_rank |
| 经营报告-概要 | platform_resource_popups | captured_with_fields | 200 | 16 | 32 | 32 | config_value |
| 经营报告-销售数据 | sales_market_detail | captured_response_only | 200 | 12 | 0 | 0 |  |
| 经营报告-销售数据 | sales_tensity_overview | http_seen_no_success_body | 200 | 0 | 8 | 2 | date, tensity |
| 经营报告-销售数据 | sales_order_trend | http_seen_no_success_body | 200 | 0 | 216 | 21 | date, order_count |
| 经营报告-销售数据 | sales_occupied_room_trend | captured_with_fields | 200 | 2 | 804 | 60 | date, room_nights, occupancy_rate |
| 经营报告-销售数据 | sales_tensities | http_seen_no_success_body | 200 | 0 | 1008 | 68 | date, tensity, competitor_average |
| 经营报告-销售数据 | sales_min_price | captured_with_fields | 200 | 2 | 4 | 2 | min_price, min_price_rank |
| 经营报告-销售数据 | sales_market_room_tensity | captured_with_fields | 200 | 2 | 1344 | 0 | date, room_nights |
| 经营报告-房型 | room_type_info | http_seen_no_success_body | 200 | 0 | 28 | 8 | room_type_id, room_type_name, cancel_rate, total_room, available_room |
| 经营报告-房型 | room_competing_hotels | captured_with_fields | 200 | 1 | 175 | 25 | hotel_id, hotel_name, competitor_hotel_name, zone_name, star_level, distance |
| 经营报告-房型 | room_competitive_market | http_seen_no_success_body | 200 | 0 | 141 | 28 | hotel_id, hotel_name, order_amount, room_nights |
| 经营报告-房型 | room_venderbility | captured_response_only | 200 | 1 | 0 | 0 |  |
| 经营报告-流量数据 | traffic_scan_flow | captured_response_only | 200 | 8 | 0 | 0 |  |
| 经营报告-流量数据 | traffic_flow_transform | http_seen_no_success_body | 200 | 0 | 144 | 18 | date, list_exposure, detail_visitor, flow_rate, order_page_visitor, order_submit_user, hotel_id |
| 经营报告-流量数据 | traffic_order_overview | captured_with_fields | 200 | 1 | 4 | 1 | competitor_average, order_count, rank |
| 经营报告-流量数据 | traffic_order_trend | http_seen_no_success_body | 200 | 0 | 72 | 7 | date, order_count |
| 经营报告-流量数据 | traffic_flow_source | captured_with_fields | 200 | 5 | 62 | 16 | source_name, page_views, hotel_id, visitor_count |
| 经营报告-流量数据 | traffic_city_keywords | captured_with_fields | 200 | 3 | 110 | 30 | keyword, visitor_count, page_views |
| 经营报告-流量数据 | traffic_search_details | captured_response_only | 200 | 8 | 0 | 0 |  |
| 经营报告-流量数据 | traffic_hotel_min_price | captured_with_fields | 200 | 1 | 2 | 1 | min_price, min_price_rank |
| 经营报告-流量数据 | traffic_picture_quality | captured_with_fields | 200 | 2 | 4 | 2 | psi_score, bad_review_tag |
| 经营报告-流量数据 | traffic_comment_score_summary | captured_with_fields | 200 | 3 | 9 | 3 | comment_score_summary, ctrip_rating |
| 竞争圈动态-概览 | competitor_management | captured_with_fields | 200 | 4 | 76 | 24 | order_count, competitor_average, rank, order_amount, room_nights |
| 竞争圈动态-概览 | competitor_hotel_label | captured_response_only | 200 | 1 | 0 | 0 |  |
| 竞争圈动态-概览 | competitor_flow | captured_with_fields | 200 | 1 | 15 | 5 | visitor_count, competitor_average, rank, detail_visitor, order_submit_user |
| 竞争圈动态-概览 | competitor_service | captured_with_fields | 200 | 1 | 7 | 2 | comment_score_summary, competitor_average, rank, psi_score, date |
| 竞争圈动态-概览 | competitor_flow_source | captured_response_only | 200 | 1 | 0 | 0 |  |
| 竞争圈动态-流失分析 | loss_order_summary | captured_with_fields | 200 | 4 | 48 | 15 | hotel_id, hotel_name, competitor_hotel_name, loss_order_amount |
| 竞争圈动态-流失分析 | loss_compete_hotel | captured_response_only | 200 | 1 | 0 | 0 |  |
| 竞争圈动态-榜单 | competitor_rank | captured_with_fields | 200 | 5 | 708 | 78 | hotel_id, hotel_name, order_rank, amount_rank, room_nights_rank, occupancy_rate_rank |
| 用户行为-用户分析 | user_profile_features | captured_with_fields | 200 | 2 | 12 | 2 | hotel_name, user_age, user_sex, user_source, booking_days, stay_days |
| 用户行为-用户分析 | user_profile_dimensions | captured_response_only | 200 | 14 | 0 | 0 |  |
| PSI服务质量分 | psi_overview | captured_with_fields | 200 | 1 | 760 | 28 | hotel_name, hotel_id, psi_score, base_score, deduct_score |
| PSI服务质量分 | psi_growth_task | captured_with_fields | 200 | 1 | 4 | 2 | hotel_name, task_name, task_action |
| PSI服务质量分 | psi_history | captured_with_fields | 200 | 1 | 780 | 30 | hotel_id, hotel_name, psi_score, base_score, deduct_score, reward_score, date |
| PSI服务质量分 | psi_course | captured_with_fields | 200 | 3 | 58 | 29 | course_title, course_url |
| 金字塔推广 | ads_summary_report | captured_with_fields | 200 | 1 | 13 | 0 | campaign_id, ad_impressions, ad_clicks, ctr, ad_cost, ad_orders |
| 金字塔推广 | ads_report_list | captured_with_fields | 200 | 2 | 182 | 0 | campaign_id, ad_impressions, ad_clicks, ctr, ad_cost, ad_orders |
| 金字塔推广 | ads_click_live | captured_response_only | 200 | 1 | 0 | 0 |  |
| 金字塔推广 | ads_diagnosis | captured_response_only | 200 | 3 | 0 | 0 |  |
| 金字塔推广 | ads_diagnostic_details | captured_with_fields | 200 | 6 | 9 | 0 | hotel_id, campaign_id |
| 金字塔推广 | ads_interpretation | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_peer_comparison | captured_with_fields | 200 | 4 | 1176 | 424 | peer_top, peer_avg, date |
| 金字塔推广 | ads_filters | captured_response_only | 200 | 3 | 0 | 0 |  |
| 金字塔推广 | ads_resource_yellow_bar | captured_with_fields | 200 | 4 | 8 | 8 | config_value |
| 金字塔推广 | ads_dynamic_config | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_report_injection | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_interpretation | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_dynamic_config | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_report_injection | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_diagnosis | captured_response_only | 200 | 3 | 0 | 0 |  |
| 金字塔推广 | ads_diagnostic_details | captured_with_fields | 200 | 6 | 9 | 0 | hotel_id, campaign_id |
| 热点日历 | hot_calendar | captured_with_fields | 200 | 1 | 25 | 5 | hot_spot_name, date, start_date, end_date |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
