# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:05:15.836Z
- Responses: 27
- Catalog facts: 113
- Standard rows: 54

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 竞争圈动态-概览 | competitor_management | captured_with_fields | 200 | 1 | 19 | 6 | order_count, competitor_average, rank, order_amount, room_nights |
| 竞争圈动态-概览 | competitor_hotel_label | captured_response_only | 200 | 1 | 0 | 0 |  |
| 竞争圈动态-概览 | competitor_flow | captured_with_fields | 200 | 1 | 15 | 5 | visitor_count, competitor_average, rank, detail_visitor, order_submit_user |
| 竞争圈动态-概览 | competitor_service | captured_with_fields | 200 | 1 | 7 | 2 | comment_score_summary, competitor_average, rank, psi_score, date |
| 竞争圈动态-概览 | competitor_flow_source | captured_response_only | 200 | 1 | 0 | 0 |  |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
