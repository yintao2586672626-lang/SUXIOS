# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:10:58.001Z
- Responses: 23
- Catalog facts: 437
- Standard rows: 92

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 竞争圈动态-榜单 | competitor_rank | captured_with_fields | 200 | 3 | 340 | 26 | hotel_id, hotel_name, order_rank, amount_rank, room_nights_rank, occupancy_rate_rank |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
