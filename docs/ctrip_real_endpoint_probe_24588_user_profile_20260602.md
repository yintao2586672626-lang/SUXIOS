# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:06:21.538Z
- Responses: 60
- Catalog facts: 87
- Standard rows: 44

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 用户行为-用户分析 | user_profile_features | captured_with_fields | 200 | 2 | 12 | 2 | hotel_name, user_age, user_sex, user_source, booking_days, stay_days |
| 用户行为-用户分析 | user_profile_dimensions | captured_response_only | 200 | 14 | 0 | 0 |  |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
