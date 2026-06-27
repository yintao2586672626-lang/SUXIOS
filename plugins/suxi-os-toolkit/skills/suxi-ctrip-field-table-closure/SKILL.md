---
name: suxi-ctrip-field-table-closure
description: 用于宿析OS携程接口响应到字段、表、UI状态和验证器的闭环任务，触发场景包括接口证据、source path、metric key、字段目录、表设计、采集缺口、Profile字段、标准行和 OTA 响应入库展示。
---

# Suxi Ctrip Field Table Closure

## Plugin Priority

Use `suxi-plugin-priority-router` when endpoint evidence, UI verification, or metric/table reasoning benefits from an installed plugin. Prefer Browser/Chrome for authorized response evidence, Data Analytics for metric reasoning, and keep the closure chain auditable.

## Goal

Convert one Ctrip endpoint or field group into a complete, auditable SUXIOS chain:

```text
Ctrip response -> source path -> metric_key -> table/storage -> UI status -> verifier
```

This skill is for field/table closure, not broad OTA redesign.

## Scope Rules

1. Work on one endpoint or one tightly related field group at a time.
2. Keep every fact labeled as Ctrip OTA channel scope unless PMS/CRS/direct-booking evidence is present.
3. Do not create placeholder facts from i18n text, UI labels, screenshots, or response-only endpoints.
4. Do not fill missing numeric values with `0`; use explicit missing states such as `api_not_hit`, `field_missing`, `parse_failed`, or `captured_response_only`.
5. If an existing saved Profile config will not auto-refresh from defaults, state that a manual sync/addition may be required.

## Closure Checklist

For each endpoint, capture and preserve:

- Request URL, method, sanitized headers category, Payload, Response sample, page context, hotel id, and date parameters.
- Endpoint id, section, source path, raw field name, `metric_key`, type, unit, conversion rule, and missing-state labels.
- Storage target: table, shared dimensions, fact fields, `raw_data` shape, old-data compatibility, save/display/edit behavior.
- UI state: where the field appears, how missing/failed collection is shown, and whether the user can correct or resync it.
- Verification: catalog verifier, endpoint evidence test, table/schema test when relevant, and display/status test when UI changes.

## Default Files

- Catalog definitions: `scripts/lib/ctrip_capture_catalog.mjs`
- Catalog verifier: `scripts/verify_ctrip_capture_catalog.mjs`
- Backend capture/defaults: `app/controller/OnlineData.php`
- Routes: `route/app.php`
- Field/catalog UI: `public/index.html`
- Ctrip table plan and gaps: `docs/ctrip_table_build_plan_20260602.md`, `docs/ctrip_capture_gaps_user_assist_20260602.md`
- Tests: `tests/OnlineDataTest.php`, `tests/automation/ctrip_capture_catalog.test.mjs`, `tests/automation/ctrip_endpoint_evidence.test.mjs`

Read only the relevant line ranges. Do not bulk-read raw capture JSON.

## Verification

Prefer the smallest relevant set:

```powershell
npm.cmd run verify:ctrip-capture-catalog
node --check scripts/lib/ctrip_capture_catalog.mjs
C:\xampp\php\php.exe -l app/controller/OnlineData.php
```

Add or run more tests only when the touched surface requires it.
