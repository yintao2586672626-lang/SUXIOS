---
name: suxi-ota-pms-collector-operating-loop
description: Use for SUXIOS OTA/PMS collection workflow design, login/Profile diagnosis, Ctrip/Meituan/PMS collection boundaries, single-store probes, ETL/write decisions, data-gap reporting, and mapping hotel-auto-x collector skills into the SUXIOS operating loop.
---

# SUXIOS OTA/PMS Collector Operating Loop

Use this skill when a task touches SUXIOS collection flow, platform login state,
Profile handling, Ctrip/Meituan/PMS diagnostics, collection status wording, or
the transition from collected data into revenue, AI, operations, or investment
decisions.

This skill adapts the learned `hotel-auto-x-*` collector skills into SUXIOS.
Those skills are process references and source maps for another project. Do not
treat their paths, functions, tables, or scheduler names as SUXIOS implementation
facts unless the current SUXIOS repo has matching code.

## Product Chain

Every collection decision must preserve the chain:

```text
verified OTA/PMS source facts -> revenue analysis -> AI decisions -> operations management -> investment decisions
```

Do not promote OTA-channel evidence into whole-hotel truth. Do not use PMS facts
to fill OTA facts. Do not use realtime snapshots to fill settled daily reports.

## Source Mapping

| Source | SUXIOS role | Allowed use | Not allowed |
| --- | --- | --- | --- |
| Ctrip OTA | OTA-channel business, traffic, order, quality, rank, review summary, and field-loop evidence | Ctrip-scoped revenue/traffic/operation inputs with source path and verifier evidence | Whole-hotel occupancy/ADR/RevPAR without PMS/full-inventory evidence |
| Meituan OTA | OTA-channel business, traffic, order, rank, ad, review summary, and field-loop evidence | Meituan-scoped revenue/traffic/operation inputs with settlement/date caveats | Treating current-day `-` or not-settled fields as permanent gaps |
| PMS daily | Whole-hotel or property operating facts when actually sourced from daily PMS APIs/imports | Daily room nights, available room nights, revenue, occupancy, ADR/RevPAR denominators | Backfilling OTA platform facts |
| PMS realtime | Current operational view and live workbench status | Same-day monitoring and alert context | Overwriting yesterday daily-report facts |
| Manual import or operator packet | Explicit user-provided evidence | Temporary repair, first onboarding, source-backed missing inputs | Silent fallback or fake closure |

## State Layers

Keep these layers separate in UI, reports, and verifier language:

1. `system_auth`: user is logged into SUXIOS.
2. `platform_binding`: hotel is mapped to a Ctrip/Meituan/PMS identifier.
3. `profile_login`: authorized browser Profile is present and manually verified.
4. `collection_run`: a platform/date/module collection was attempted.
5. `etl_write`: collected or imported facts reached the approved SUXIOS storage path.
6. `field_closure`: source path, metric key, storage field, UI status, and verifier are closed.
7. `decision_readiness`: revenue/AI/operation/investment consumers have enough evidence.

`manual_login_state_verified` only proves layer 3. It does not prove target-date
rows, field closure, AI readiness, execution, or investment readiness.

## Default Workflow

Start read-only unless the user explicitly asks to collect, repair, import, or
write data.

1. Define scope: platform, hotel/store id, target date, module, and source scope.
2. Check current evidence first: status rows, summaries, verifier output, logs,
   and existing field-gap reports.
3. Diagnose one hotel/store and one date before broad or all-store actions.
4. Check login/Profile state separately from collection and ETL state.
5. If collection is approved, use the smallest relevant path and keep the write
   path explicit.
6. After collection/import, verify target-date rows and field closure before
   enabling downstream revenue/AI/operation/investment conclusions.

## Write Rules

- Do not write directly to business tables from ad hoc scripts.
- Use the existing SUXIOS importer, ETL, controller, or verifier-approved write
  path for the current source.
- Keep raw capture files, Profile material, cookies, tokens, screenshots, and
  sensitive exports out of Git.
- Do not convert missing values to `0`.
- Do not catch broad failures and mark the task as successful.
- Do not run all-store/full collection during scheduler-sensitive windows unless
  the user explicitly requested it and the risk is stated.

## Failure Labels

Use explicit states. Prefer these labels over generic failure wording:

- `not_logged_in`
- `session_expired`
- `captcha_required`
- `human_verification_required`
- `profile_missing`
- `profile_locked`
- `resource_busy_login`
- `platform_hotel_identifier_missing`
- `permission_denied`
- `api_empty`
- `no_data`
- `target_date_no_data`
- `field_missing`
- `parse_failed`
- `source_path_missing`
- `metric_key_missing`
- `sync_completed_without_saved_rows`
- `etl_write_not_confirmed`
- `verifier_incomplete`
- `policy_disabled`

Blocked, partial, missing-table, missing-row, fallback-only, and unverified states
must remain visible.

## Ctrip Guidance

Use `hotel-auto-x-ctrip-collector` only as a process reference. In SUXIOS:

- Ctrip data remains OTA-channel scoped unless PMS/full-property evidence exists.
- JSON/network response evidence is stronger than DOM text.
- DOM-only values can support diagnosis but must not be presented as complete API
  closure without endpoint evidence.
- Missing Ctrip-family sub-channel room nights should be investigated through
  Ctrip channel/tab evidence, not filled from PMS.
- A Ctrip field group is complete only after:

```text
Ctrip response -> source path -> metric_key -> storage -> UI status -> verifier
```

## Meituan Guidance

Use `hotel-auto-x-meituan-collector` only as a process reference. In SUXIOS:

- Respect platform settlement timing; current-day blanks can be timing state, not
  permanent absence.
- Keep review, business, traffic, order, rank, and ad data modules separate.
- `session_expired`, `anti_bot`, `resource_busy_login`, and `no_data` should stop
  aggressive retries and surface next action.
- Meituan peer/rank fields must preserve source naming and normalization evidence
  before they count as closed facts.

## PMS Guidance

Use `hotel-auto-x-pms-collector` only as a process reference. In SUXIOS:

- Daily PMS facts must come from a daily PMS source or approved daily import.
- Realtime PMS is current operational context and must not overwrite settled daily
  facts.
- PMS identifiers are required before a store can be treated as PMS-ready.
- Empty core PMS metrics are a hard data-quality issue, not a green state.
- PMS can provide whole-hotel denominators only when the source, date, and hotel
  identity are verified.

## Login/Profile Guidance

Use `hotel-auto-x-login` only as a process reference. In SUXIOS:

- Browser Profile login is the product mainline for authorized OTA sessions.
- Cookie/API paths are temporary assist or repair paths unless the current task
  explicitly approves them as source evidence.
- Do not delete Profile directories or clear cookies/localStorage except for a
  specific user-approved platform/store repair.
- Do not bypass captcha, SMS, human verification, platform risk controls, tenant
  boundaries, or permissions.
- Report login work with platform, hotel/store id, Profile id/status, evidence
  checked, and whether any state row changed.

## Downstream Gate

Do not let revenue, AI, operations, or investment surfaces imply readiness until
the collection layer reports both source evidence and the relevant verifier state.

Use this gate:

```text
source reachable
-> login/profile verified when needed
-> target-date collection/import attempted
-> ETL/write confirmed
-> field closure verified
-> revenue/AI/operation/investment consumer allowed
```

If any step is missing, report the exact blocker and stop at the earliest failed
layer.

## Verification

Choose the smallest relevant verifier for the touched surface. Common SUXIOS
commands include:

```powershell
npm.cmd run verify:p0-ota-field-loop -- --date=<date> --format=json
npm.cmd run report:p0-ota-field-loop-audit -- --date=<date> --format=markdown
npm.cmd run verify:platform-data-source-contract
npm.cmd run verify:phase1-employee-console
npm.cmd run verify:public-entry
npm.cmd run verify:e2e-contracts
```

For skill/process-only updates, run:

```powershell
npm.cmd run verify:context-assets
```

Report what passed, what was not run, and which gates remain blocked.
