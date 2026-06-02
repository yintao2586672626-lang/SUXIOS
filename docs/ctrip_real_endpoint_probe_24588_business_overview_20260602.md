# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T10:55:21.486Z
- Responses: 36
- Catalog facts: 179
- Standard rows: 0

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 经营报告-概要 | hotel_advice | captured_with_fields | 200 | 1 | 12 | 0 | hotel_id, advice_text |
| 经营报告-概要 | business_realtime | captured_with_fields | 200 | 1 | 6 | 0 | hotel_id, visitor_count, order_count |
| 经营报告-概要 | business_flow_compete | captured_with_fields | 200 | 1 | 7 | 0 | hotel_id, order_count, competitor_orders, competitor_visitor, order_amount, competitor_revenue |
| 经营报告-概要 | business_capacity | http_seen_no_success_body | 200 | 0 | 7 | 0 | room_nights, competitor_average, order_count, rank, occupancy_rate |
| 经营报告-概要 | business_visitor_title | http_seen_no_success_body | 200 | 0 | 5 | 0 | visitor_count, visitor_rank, competitor_avg_visitor, qunar_competitor_avg_visitor |
| 经营报告-概要 | business_market_overview | captured_with_fields | 200 | 1 | 13 | 0 | order_amount, rank, room_nights, conversion_rate, occupancy_rate, avg_price |
| 经营报告-概要 | business_flow_transform | http_seen_no_success_body | 200 | 0 | 16 | 0 | date, list_exposure, detail_visitor, flow_rate, order_page_visitor, order_submit_user, hotel_id |
| 经营报告-概要 | business_service_quantity | captured_with_fields | 200 | 1 | 12 | 0 | deduct_score, psi_score, service_score, service_score_rank, comment_score_summary, ctrip_rating, im_score, reply_rank |
| 经营报告-概要 | platform_resource_popups | captured_with_fields | 200 | 2 | 4 | 0 | config_value |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
