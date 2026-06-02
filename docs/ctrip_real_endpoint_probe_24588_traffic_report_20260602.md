# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:03:26.372Z
- Responses: 48
- Catalog facts: 249
- Standard rows: 101

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 经营报告-流量数据 | traffic_scan_flow | captured_response_only | 200 | 3 | 0 | 0 |  |
| 经营报告-流量数据 | traffic_flow_transform | http_seen_no_success_body | 200 | 0 | 16 | 2 | date, list_exposure, detail_visitor, flow_rate, order_page_visitor, order_submit_user, hotel_id |
| 经营报告-流量数据 | traffic_order_overview | captured_with_fields | 200 | 1 | 4 | 1 | competitor_average, order_count, rank |
| 经营报告-流量数据 | traffic_order_trend | http_seen_no_success_body | 200 | 0 | 72 | 7 | date, order_count |
| 经营报告-流量数据 | traffic_flow_source | captured_with_fields | 200 | 1 | 32 | 8 | source_name, page_views |
| 经营报告-流量数据 | traffic_city_keywords | captured_with_fields | 200 | 1 | 10 | 10 | keyword |
| 经营报告-流量数据 | traffic_search_details | captured_response_only | 200 | 2 | 0 | 0 |  |
| 经营报告-流量数据 | traffic_hotel_min_price | captured_with_fields | 200 | 1 | 2 | 1 | min_price, min_price_rank |
| 经营报告-流量数据 | traffic_picture_quality | captured_with_fields | 200 | 1 | 2 | 1 | psi_score, bad_review_tag |
| 经营报告-流量数据 | traffic_comment_score_summary | captured_with_fields | 200 | 1 | 3 | 1 | comment_score_summary, ctrip_rating |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
