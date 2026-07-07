---
name: hotel-auto-x-meituan-collector
description: Use when working on hotel-auto-x Meituan collection, including Meituan review collection, bad reviews, review score, full daily collection, realtime traffic/order metrics, ad collection, Meituan session/profile problems, and Meituan data mapping into daily_report.
---

# hotel-auto-x Meituan Collector

Use this skill for Meituan collection work in `/home/qing/hotel-auto-x/hotel_auto_x`.

## Safety

- Do not run full all-store collection during busy scheduler windows unless explicitly requested.
- Do not touch other platform Profiles when fixing one Meituan store.
- Use `stealth=False` for Meituan browser contexts unless existing code says otherwise.
- Do not bypass `process_result()` for `daily_report` or bad-review writes.

## Project Map

- Main collector: `platforms/meituan.py::MeituanCollector`
- Shared identity helpers: `platforms/meituan_common.py`
- Realtime collector: `platforms/meituan_realtime.py::collect_meituan_realtime`
- Ad collector: `platforms/ad_meituan.py::collect_meituan_ad`
- Review scheduler: `scheduler/engine.py::task_collect_meituan_review`
- Full scheduler: `scheduler/engine.py::task_collect_meituan_full`
- Realtime scheduler: `scheduler/realtime_engine.py::task_realtime_meituan`
- Ad scheduler: `scheduler/ad_engine.py::task_collect_meituan_ad`
- ETL: `pipeline/etl.py`

## Main Data Types

Daily/full collection can fill:

- Meituan score and review counters.
- Bad review details through `_bad_reviews`, written into `bad_reviews`.
- Data-center business metrics when captured or fetched by fallback.

Realtime collection fills `realtime_snapshot` fields such as exposure, browsing/intention UV, pay orders, pay rooms, conversion, rankings, and related peer values.

Ad collection writes ad daily data through scheduler/ad pipeline, not the normal review ETL path.

## Review-Only Flow

Production entry: `MeituanCollector().run_review_only(store_id, stat_date)`.

Scheduler path:

1. Filter active stores with `meituan_account` and `collect_meituan=True`.
2. Skip circuit-open or locked login Profile.
3. Run review-only collector with timeout.
4. Call `process_result(result)`.
5. Mark session active/expired based on result.
6. Retry retryable failures once.

## Full Flow

Production entry: `MeituanCollector().run(store_id, stat_date)`.

Full collection uses API capture plus fallback paths, then writes via ETL. Account groups are collected serially inside each account group.

## Realtime Flow

Production entry: `collect_meituan_realtime(store_id)`.

Validate that returned data has at least one core field:

- `mt_intention_uv`
- `mt_pay_orders`
- `mt_pay_rooms`
- `mt_exposure`

Repeated `no_data`, `load_timeout`, `network_error`, `anti_bot`, or `session_expired` triggers platform/store cooldowns in `scheduler/realtime_engine.py`.

## Known Timing Rule

Some Meituan traffic fields for the current day can show `-`. For yesterday data, collect or audit on the next day after platform settlement. Do not classify all current-day `-` exposure as a permanent data gap.

## Minimal Diagnostic Pattern

```python
from platforms.meituan import MeituanCollector

result = MeituanCollector().run_review_only("MD00001", "2026-07-06")
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
- `anti_bot`: stop aggressive retries and inspect platform page/state.
- `api_empty` or `no_data`: inspect identity extraction, request params, and whether the date is settled.
- `resource_busy_login`: user login window is open; skip instead of forcing close.

## Reporting

Report store_id, target date, collection phase, method, key fields, `_bad_reviews` count if present, and ETL/write status.
