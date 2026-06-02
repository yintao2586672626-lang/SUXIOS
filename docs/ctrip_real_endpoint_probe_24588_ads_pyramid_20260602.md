# Ctrip Supplied Endpoint Real Probe - 2026-06-02

- Profile: 24588
- OTA hotelId: 24588
- Auth: logged_in
- Captured at: 2026-06-02T11:08:31.348Z
- Responses: 42
- Catalog facts: 1456
- Standard rows: 469

| Section | Endpoint | Status | HTTP | Success responses | Facts | Rows | Sample metrics |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 金字塔推广 | ads_summary_report | captured_with_fields | 200 | 1 | 13 | 0 | campaign_id, ad_impressions, ad_clicks, ctr, ad_cost, ad_orders |
| 金字塔推广 | ads_report_list | captured_with_fields | 200 | 2 | 182 | 0 | campaign_id, ad_impressions, ad_clicks, ctr, ad_cost, ad_orders |
| 金字塔推广 | ads_click_live | captured_response_only | 200 | 1 | 0 | 0 |  |
| 金字塔推广 | ads_diagnosis | captured_response_only | 200 | 2 | 0 | 0 |  |
| 金字塔推广 | ads_diagnostic_details | captured_with_fields | 200 | 6 | 9 | 0 | hotel_id, campaign_id |
| 金字塔推广 | ads_interpretation | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_peer_comparison | captured_with_fields | 200 | 4 | 1176 | 424 | peer_top, peer_avg, date |
| 金字塔推广 | ads_filters | captured_response_only | 200 | 3 | 0 | 0 |  |
| 金字塔推广 | ads_resource_yellow_bar | captured_with_fields | 200 | 1 | 2 | 2 | config_value |
| 金字塔推广 | ads_dynamic_config | not_seen | - | 0 | 0 | 0 |  |
| 金字塔推广 | ads_report_injection | not_seen | - | 0 | 0 | 0 |  |

Status meanings: captured_with_fields = XHR seen, response success, and catalog fields extracted; captured_response_only = API returned success but current catalog did not extract target fields; http_seen_no_success_body = request seen but no success body; not_seen = target XHR did not fire in this run.
