# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:00:51.284Z
- Responses: 54
- Catalog facts: 1408
- Standard rows: 95

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 经营报告-销售数据 | sales_market_detail | captured_response_only | 200 | 8 | 0 | 0 |  |
| 经营报告-销售数据 | sales_tensity_overview | http_seen_no_success_body | 200 | 0 | 4 | 1 | date, tensity |
| 经营报告-销售数据 | sales_order_trend | http_seen_no_success_body | 200 | 0 | 144 | 14 | date, order_count |
| 经营报告-销售数据 | sales_occupied_room_trend | captured_response_only | 200 | 1 | 0 | 0 |  |
| 经营报告-销售数据 | sales_tensities | http_seen_no_success_body | 200 | 0 | 504 | 34 | date, tensity, competitor_average |
| 经营报告-销售数据 | sales_min_price | captured_with_fields | 200 | 1 | 2 | 1 | min_price, min_price_rank |
| 经营报告-销售数据 | sales_market_room_tensity | captured_with_fields | 200 | 1 | 672 | 0 | date, room_nights |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
