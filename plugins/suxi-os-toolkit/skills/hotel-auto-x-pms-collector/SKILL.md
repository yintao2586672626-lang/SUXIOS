---
name: hotel-auto-x-pms-collector
description: Use when working on hotel-auto-x PMS collection, including JY01/JY03/hybrid daily report collection, PMS realtime collection, PMS quality gates, PMS session issues, and PMS data entering daily_report or realtime_snapshot.
---

# hotel-auto-x PMS Collector

Use this skill for PMS collection work in `/home/qing/hotel-auto-x/hotel_auto_x`.

## Safety

- Do not change scheduler timing or restart service unless explicitly requested.
- Do not use workbench realtime data as a daily-report fallback. Daily PMS must come from PMS daily APIs.
- Keep all writes through the existing ETL path: `pipeline.etl.process_result()`.
- For diagnostics, use one store/date first.

## Project Map

- Daily PMS API collector: `platforms/pms.py::collect_pms_daily_api`
- PMS collector class and login checks: `platforms/pms.py::PMSCollector`
- JY03 API mapping: `platforms/pms_jy03.py`
- JY03 audit/compare: `pipeline/pms_jy03_audit.py`
- Daily scheduler task: `scheduler/engine.py::task_collect_pms`
- Realtime PMS collector: `platforms/pms_realtime.py::collect_pms_realtime`
- Realtime scheduler task: `scheduler/realtime_engine.py::task_realtime_pms`
- ETL destination: `daily_report`, plus collection logs
- Realtime destination: `realtime_snapshot`

## Daily PMS Flow

`task_collect_pms()` is the production flow:

1. Calculate `stat_date = yesterday`.
2. Run preflight and heavy collection lock.
3. Load active stores with `collect_pms=True`.
4. Call `collect_pms_daily_api(stores, stat_date)`.
5. Apply unready-data defer logic and PMS quality gate.
6. Write each `CollectResult` with `process_result(result)`.
7. Retry retryable failures.
8. Record `task_runs`, `TaskRunItem`, collection logs, and alerts.

## Source Mode

`config.settings.pms_daily_source` controls daily source:

- `jy01`: JY01 only.
- `jy03`: JY03 only.
- `hybrid`: JY03 covers core operating/channel fields and JY01 fills detail fields.

Hybrid acceptance requires JY03 to pass self-checks and core guard checks. Otherwise the code falls back to valid JY01 when possible.

## Required Store Fields

Daily API needs a PMS org/hotel identifier from store config. Missing IDs produce `pms_org_id_missing`. Realtime PMS uses `pms_hotel_id` for group store switching.

## Core Data Quality

Treat these as hard failures or high-priority investigation:

- `invalid_or_empty_core_metrics`
- `pms_core_fields_missing`
- invalid `overnight_occ_rate`
- missing cookies from `_extract_jy01_cookies_from_ctx`
- repeated `api_code` errors for the same date/store

Do not accept rows with empty core fields just to make the task green.

## Realtime PMS Flow

`collect_pms_realtime(store_list)` opens PMS group Profile, switches stores, reads workbench APIs, and writes snapshots through `scheduler/realtime_engine.py::_save_snapshot`.

Realtime data is for current operational view. It must not overwrite yesterday daily-report PMS facts.

## Minimal Diagnostic Pattern

Use this shape for local investigation, adapting store/date:

```python
from platforms.pms import collect_pms_daily_api

stores = [{"store_id": "MD00001", "name": "门店", "pms_hotel_id": "...", "pms_org_code": "..."}]
results = collect_pms_daily_api(stores, "2026-07-06", sleep_between=0)
for r in results:
    print(r.store_id, r.success, r.error, r.method, sorted((r.data or {}).keys()))
```

Only call `process_result(r)` when the user asked to write/repair data.

## Reporting

Report source mode, target date, store count, success/failed/skipped, failed store errors, and whether data reached `daily_report`.
