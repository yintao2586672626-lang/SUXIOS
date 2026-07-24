# Ctrip Competition Circle Data Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Ctrip competition-circle acquisition classifiable, traceable, filterable, AI-safe, and truthfully backfill all identifiable historical rows.

**Architecture:** Keep `online_daily_data` as the fact table and preserve `system_hotel_id` as the circle owner. Add role/classification and provenance through existing columns, create sync-task evidence for new and backfilled data, then update history and AI consumers to read the corrected semantics.

**Tech Stack:** ThinkPHP 8, ThinkORM, MySQL, Vue 3 CDN, Node test runner, PHPUnit.

---

### Task 1: Lock the persistence contract with failing tests

**Files:**
- Modify: `tests/OtaCredentialReadPathTest.php`
- Modify: `tests/OnlineDataTest.php`
- Modify: `tests/automation/manual_minimum_credential_ui.test.mjs`

- [ ] Add assertions that manual Ctrip competition rows use `data_type=competitor`, `dimension=competition_circle_hotel`, and `compare_type=self|competitor`.
- [ ] Add assertions that new rows carry `data_source_id`, `sync_task_id`, `snapshot_time`, and `source_trace_id`.
- [ ] Add assertions that missing Qunar score becomes `NULL` with `field_missing:qunar_comment_score` and non-normal validation.
- [ ] Add UI assertions for update-aware save text, own-hotel-only AI selection, retained AI estimate title, derived disclosure, and health baseline text.
- [ ] Run the focused tests and confirm they fail on the old behavior.

### Task 2: Add the competition-circle persistence context

**Files:**
- Modify: `app/controller/concern/OnlineDataManualFetchConcern.php`
- Modify: `app/controller/concern/BusinessDisplayConcern.php`
- Modify: `app/service/OnlineDailyDataPersistenceService.php`

- [ ] Resolve or create the stable manual Ctrip competitor data source for the selected system hotel.
- [ ] Create one `platform_data_sync_tasks` row per fetch and generate a sanitized trace from source id, task id, hotel id, date, and response fingerprint.
- [ ] Extend `parseAndSaveData` with an optional persistence context while preserving existing callers.
- [ ] Detect the self row from explicit response flags first and the resolved OTA self-hotel ID second; never infer it from non-null `system_hotel_id`.
- [ ] Persist classification, role, provenance, snapshot time, nullable Qunar score, validation flags, and inserted/updated counts.
- [ ] Complete the sync task with accurate statistics or failure state.

### Task 3: Correct history filtering and display semantics

**Files:**
- Modify: `app/controller/concern/OnlineDataHistoryConcern.php`
- Modify: `public/ctrip-static.js`
- Modify: `public/index.html` only where existing template labels cannot be supplied by the static helper.

- [ ] Include competition-circle hotel rows in the competitor filter while retaining old competitor-average compatibility.
- [ ] Scope hotel filtering by `system_hotel_id` as circle ownership and derive `is_my_hotel` only from `compare_type=self` or explicit raw evidence.
- [ ] Use the latest of create/update time in summaries and show inserted versus updated row counts.
- [ ] Keep `全渠道AI预计总间夜数`, add derived/source disclosure, and require a comparable prior snapshot before showing trend direction.
- [ ] Restrict AI selectable hotels to self rows; expose competitors as read-only comparison context and display the system hotel name for self.

### Task 4: Build and run the idempotent historical backfill

**Files:**
- Create: `scripts/backfill_ctrip_competition_circle_history.php`
- Create: `tests/automation/ctrip_competition_circle_backfill.test.mjs`

- [ ] Implement `--dry-run` to report candidate rows, stores, dates, self-resolved rows, unresolved groups, and missing-score rows without writes.
- [ ] Identify candidates by Ctrip source plus the competition response field signature; exclude traffic, advertising, review, order, and already-classified rows.
- [ ] In transactions grouped by system hotel, create a legacy backfill data source/sync task and update only candidate rows.
- [ ] Generate `legacy_backfill:<hash>` trace ids, infer snapshot time from existing timestamps with an explicit flag, set missing Qunar scores to `NULL`, and preserve verified new-capture provenance.
- [ ] Run dry-run, review counts, run apply, then rerun dry-run to prove idempotency.

### Task 5: Verify the full loop

**Files:**
- No additional production files.

- [ ] Run focused PHPUnit and Node tests.
- [ ] Run `npm.cmd run verify:ctrip-capture-catalog` and PHP syntax checks.
- [ ] Query safe database columns to verify classification, roles, trace coverage, nullable scores, and idempotent counts.
- [ ] In Browser, fetch yesterday data for the authorized test hotel, verify display, competitor filtering, history update time, and AI self-only selection.
- [ ] Report changed files, verified behavior, backfill counts, and any rows left `unverified` or `self_identity_unresolved`.
