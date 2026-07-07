---
name: hotel-auto-x-ctrip-collector
description: Use when working on hotel-auto-x Ctrip collection, including Ctrip review collection, bad reviews across ctrip/qunar/tongcheng/zhixing, review score, full daily collection, realtime Ctrip metrics, Ctrip session/profile problems, and Ctrip data mapping into daily_report.
---

# hotel-auto-x Ctrip Collector

Use this skill for Ctrip collection work in `/home/qing/hotel-auto-x/hotel_auto_x`.

## Safety

- Do not run full all-store collection during busy scheduler windows unless explicitly requested.
- Respect `collect_ctrip=False`; some stores intentionally do not collect Ctrip.
- Use `stealth=False` for Ctrip contexts because stealth can interfere with Ctrip micro-frontend/XHR behavior.
- Do not write directly to `daily_report` or `bad_reviews`; use `process_result()`.

## Project Map

- Main collector: `platforms/ctrip.py::CtripCollector`
- Realtime collector: `platforms/ctrip_realtime.py::collect_ctrip_realtime`
- Ad collector: `platforms/ad_ctrip.py::collect_ctrip_ad`
- Review scheduler: `scheduler/engine.py::task_collect_ctrip_review`
- Full scheduler: `scheduler/engine.py::task_collect_ctrip_full`
- Realtime scheduler: `scheduler/realtime_engine.py::task_realtime_ctrip`
- Ad scheduler: `scheduler/ad_engine.py::task_collect_ctrip_ad`
- ETL: `pipeline/etl.py`

## Main Data Types

Daily/full collection can fill:

- Ctrip visitor/order/heat/ranking/sold-room metrics.
- Ctrip review score, PSI, reply rate, collect count/rank.
- Bad reviews from Ctrip-family platforms.

Bad reviews may use platform values such as `ctrip`, `qunar`, `tongcheng`, or `zhixing` in `bad_reviews`. For blackhole push payloads, keep source as `ctrip` and put the sub-channel in row `platform`.

## Review-Only Flow

Production entry: `CtripCollector().run_review_only(store_id, stat_date)`.

Scheduler path:

1. Filter active stores with `ctrip_account` and `collect_ctrip=True`.
2. Skip circuit-open, expired-session, or locked Profile stores.
3. Run collector with timeout.
4. Call `process_result(result)`.
5. Mark session active/expired.
6. Retry retryable failures once.

## Full Flow

Production entry: `CtripCollector().run(store_id, stat_date)`.

The collector captures API responses and has DOM fallback parsing for important quality/overview values. Treat DOM-only results as useful but verify missing fields against captured API changes.

## Realtime Flow

Production entry: `collect_ctrip_realtime(store_id)`.

Validate at least one core field:

- `ctrip_orders`
- `ctrip_room_nights`
- `ctrip_visitor`
- `ctrip_rank`

`api_empty`/`no_data` should be investigated by checking login state, hotel identity, request params, and whether the selected store is configured to collect Ctrip.

## Ctrip-Family Channel Rule

For weekly OTA data, if a store normally collects Ctrip, then all-zero Ctrip/Qunar/Tongcheng room-night values are suspicious unless the store is explicitly excluded. Do not use PMS to fill OTA room-night facts.

## Minimal Diagnostic Pattern

```python
from platforms.ctrip import CtripCollector

result = CtripCollector().run_review_only("MD00001", "2026-07-06")
print(result.success, result.error, result.method, result.data)
```

Only write with:

```python
from pipeline.etl import process_result
process_result(result)
```

when the user explicitly wants data repaired or imported.

## Failure Meaning

- `session_expired`: re-login the store Profile.
- `anti_bot`: stop aggressive retries and inspect browser state.
- `api_empty` or `no_data`: inspect endpoint response, selected hotel identity, date range, and account permissions.
- Missing sub-platform room nights: inspect Ctrip data-center channel-tab API rather than PMS.

## Reporting

Report store_id, target date, whether `collect_ctrip` is enabled, phase, method, core fields, sub-platform bad-review counts, and ETL/write status.
