# 携程采集结果摘要

- 来源：runtime\platform_data_sources\ctrip_browser_source_120820008_20260708031204.json
- Profile：120820008
- 模式：capture
- 登录状态：logged_in / ok
- 登录说明：Ctrip profile is logged in.
- Cookie 注入：否
- 响应数：716
- 标准行：481
- 字段事实：6913

## 诊断能力

| 方向 | 状态 | 已命中字段 |
|---|---|---|
| 收益销售 | available | 预订订单数 (order_count), 间夜/在店间夜 (room_nights), 预订销售额 (order_amount), 平均卖价/起价 (avg_price), 出租率 (occupancy_rate) |
| 流量转化 | available | 访客量 (visitor_count), 列表页曝光 (list_exposure), 详情页访客 (detail_visitor), 订单页访客 (order_page_visitor), 订单提交人数 (order_submit_user), 流量转化率 (flow_rate) |
| 竞争圈 | missing | - |
| 服务质量/IM | available | PSI服务质量分 (psi_score), 回复率 (reply_rate), 5分钟回复率 (five_min_reply_rate), 5分钟人工回复率 (manual_reply_rate), 机器人解决率 (robot_resolution_rate), IM竞争圈排名 (im_rank), 会话量 (session_count), 人工会话量 (manual_session_count), 机器人会话量 (robot_session_count), IM客人转化率 (im_order_conversion_rate), 酒店收藏数 (hotel_collect), 点评分 (comment_score_summary) |
| 广告推广 | available | 广告点击 (ad_clicks), 广告花费 (ad_cost) |
| 商旅BPI | missing | - |
| 辅助事实 | available | 用户性别 (user_sex), 用户年龄 (user_age), 客源来源 (user_source), 用户类型 (user_type) |

## 模块命中

| 模块 | 状态 | 接口数 | 标准行 | 字段事实 | 命中接口 | 缺失接口 |
|---|---|---:|---:|---:|---|---|
| 金字塔推广 | captured | 195 | 0 | 268 | ads_click_live (ads_click_live), ads_diagnosis (ads_diagnosis), ads_diagnostic_details (ads_diagnostic_details), ads_dynamic_config (ads_dynamic_config), ads_filters (ads_filters), ads_peer_comparison (ads_peer_comparison), ads_report_injection (ads_report_injection), ads_report_list (ads_report_list), ads_resource_yellow_bar (ads_resource_yellow_bar), ads_summary_report (ads_summary_report) | ads_interpretation (ads_interpretation) |
| 经营报告-概要 | captured | 217 | 8 | 811 | business_capacity (business_capacity), business_flow_compete (business_flow_compete), business_flow_transform (business_flow_transform), business_hotel_seq (business_hotel_seq), business_market_overview (business_market_overview), business_realtime (business_realtime), business_service_quantity (business_service_quantity), business_visitor_title (business_visitor_title), hotel_advice (hotel_advice), platform_notifications (platform_notifications), platform_resource_popups (platform_resource_popups) | - |
| business_weekly_overview | captured | 11 | 36 | 139 | weekly_compete_report (weekly_compete_report), weekly_report (weekly_report) | - |
| comment_review | captured | 10 | 14 | 96 | comment_hotel_rating (comment_hotel_rating), comment_review_aggregate (comment_review_aggregate) | - |
| 竞争圈动态-概览 | captured | 29 | 11 | 78 | competitor_flow (competitor_flow), competitor_flow_source (competitor_flow_source), competitor_hotel_label (competitor_hotel_label), competitor_management (competitor_management), competitor_service (competitor_service) | - |
| 竞争圈动态-竞争圈榜单 | captured | 14 | 89 | 1320 | competitor_rank (competitor_rank) | - |
| 用户行为-IM看板 | captured | 15 | 91 | 1161 | im_index (im_index), im_trend (im_trend) | - |
| PSI服务质量分 | captured | 12 | 45 | 90 | psi_course (psi_course), psi_growth_task (psi_growth_task), psi_history (psi_history), psi_overview (psi_overview) | - |
| 经营报告-流量数据 | captured | 87 | 73 | 958 | traffic_city_keywords (traffic_city_keywords), traffic_comment_score_summary (traffic_comment_score_summary), traffic_flow_source (traffic_flow_source), traffic_flow_transform (traffic_flow_transform), traffic_hotel_min_price (traffic_hotel_min_price), traffic_hotel_seq (traffic_hotel_seq), traffic_menu_key (traffic_menu_key), traffic_order_overview (traffic_order_overview), traffic_order_trend (traffic_order_trend), traffic_picture_quality (traffic_picture_quality), traffic_scan_flow (traffic_scan_flow), traffic_search_details (traffic_search_details) | traffic_flow_source_popups (traffic_flow_source_popups) |
| 用户行为-用户分析 | captured | 126 | 114 | 1992 | user_profile_dimensions (user_profile_dimensions), user_profile_features (user_profile_features) | - |

## 结论

- 已解析到标准经营事实，可以进入携程经营诊断。
