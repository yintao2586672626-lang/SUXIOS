# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 58
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T10:48:35.591Z
- Responses: 28
- Catalog facts: 780
- Standard rows: 0

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 竞争圈动态-榜单 | competitor_rank | captured_with_fields | 200 | 5 | 708 | 0 | hotel_id, hotel_name, order_rank, amount_rank, room_nights_rank, occupancy_rate_rank |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
