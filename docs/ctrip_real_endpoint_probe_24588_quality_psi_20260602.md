# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:07:24.408Z
- Responses: 25
- Catalog facts: 1674
- Standard rows: 130

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| PSI服务质量分 | psi_overview | captured_with_fields | 200 | 1 | 760 | 28 | hotel_name, hotel_id, psi_score, base_score, deduct_score |
| PSI服务质量分 | psi_growth_task | captured_with_fields | 200 | 1 | 4 | 2 | hotel_name, task_name, task_action |
| PSI服务质量分 | psi_history | captured_with_fields | 200 | 1 | 780 | 30 | hotel_id, hotel_name, psi_score, base_score, deduct_score, reward_score, date |
| PSI服务质量分 | psi_course | captured_with_fields | 200 | 3 | 58 | 29 | course_title, course_url |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
