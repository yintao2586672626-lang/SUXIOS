# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:10:04.063Z
- Responses: 23
- Catalog facts: 97
- Standard rows: 46

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 热点日历 | hot_calendar | captured_with_fields | 200 | 1 | 25 | 5 | hot_spot_name, date, start_date, end_date |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
