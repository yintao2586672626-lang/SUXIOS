# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T10:54:12.336Z
- Responses: 24
- Catalog facts: 113
- Standard rows: 0

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 竞争圈动态-流失分析 | loss_order_summary | captured_with_fields | 200 | 1 | 41 | 0 | hotel_id, hotel_name, competitor_hotel_name |
| 竞争圈动态-流失分析 | loss_compete_hotel | captured_response_only | 200 | 1 | 0 | 0 |  |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
