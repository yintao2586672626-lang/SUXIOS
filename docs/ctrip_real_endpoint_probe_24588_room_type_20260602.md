# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:02:23.698Z
- Responses: 86
- Catalog facts: 3794
- Standard rows: 302

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 经营报告-房型 | room_type_info | http_seen_no_success_body | 200 | 0 | 28 | 8 | room_type_id, room_type_name, cancel_rate, total_room, available_room |
| 经营报告-房型 | room_competing_hotels | captured_with_fields | 200 | 1 | 175 | 25 | hotel_id, hotel_name, competitor_hotel_name, zone_name, star_level, distance |
| 经营报告-房型 | room_competitive_market | http_seen_no_success_body | 200 | 0 | 141 | 28 | hotel_id, hotel_name, order_amount, room_nights |
| 经营报告-房型 | room_venderbility | captured_response_only | 200 | 1 | 0 | 0 |  |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
