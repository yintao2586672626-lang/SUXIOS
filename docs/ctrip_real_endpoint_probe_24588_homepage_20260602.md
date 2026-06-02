# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T10:59:30.847Z
- Responses: 31
- Catalog facts: 74
- Standard rows: 43

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 首页实时概览 | homepage_realtime | captured_with_fields | 200 | 1 | 43 | 12 | visitor_count, rank, order_amount |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
