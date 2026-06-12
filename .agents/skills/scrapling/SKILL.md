---
name: scrapling
description: Use Scrapling for authorized HTML extraction, scraping prototypes, selector evidence, parser fixtures, and OTA page field extraction in SUXIOS.
---

# Scrapling Skill

## Scope

Use this skill when the task needs Scrapling, scraping, crawling, HTML parsing, selector evidence, parser fixtures, or OTA page field extraction.

Use `suxi-plugin-priority-router` before browser-assisted extraction. Prefer Browser for local verification and Chrome only when the user's authorized logged-in browser state is explicitly needed.

Scrapling is only a tool for authorized extraction experiments and parser implementation. It is not permission to bypass login, captcha, SMS verification, account authorization, robots restrictions, or OTA platform controls.

## SUXIOS Boundaries

1. Keep OTA channel scope separate from whole-hotel operating scope.
2. Prefer response JSON and exported reports over DOM text when structured business data is available.
3. Use DOM extraction only for visible page evidence, labels, rankings, summaries, or fields missing from captured business JSON.
4. Do not fabricate values for missing fields, login failures, empty pages, or selector misses.
5. Preserve explicit failure states: missing dependency, network failure, blocked request, login expired, selector not found, schema mismatch.
6. Treat cookies, tokens, profile paths, phone numbers, account ids, and hotel ids as sensitive. Do not print or commit them.
7. Do not modify business code, database schema, navigation, or protected OTA collection logic unless explicitly requested.

## Before Implementation

1. Read `HOTEL/AGENTS.md` and task-relevant SUXIOS skills first.
2. Check whether Scrapling is locally available:

```powershell
python -m pip show scrapling
python -c "import importlib.util; print('installed' if importlib.util.find_spec('scrapling') else 'missing')"
```

3. If Scrapling is missing, do not install it silently. Ask before running networked dependency installation.
4. If package API details matter, verify the installed package version or official docs before writing code.
5. Locate the existing data flow before changes:
   - `route/app.php`
   - `app/controller/OnlineData.php`
   - `scripts/`
   - existing verifiers and tests

## Implementation Pattern

For a Scrapling extractor:

1. Define the target source: platform, collection mode, and business module.
2. Define the evidence contract: source URL or local HTML path, selector or JSON path, extracted key, type, unit, missing-state label, and confidence.
3. Keep raw evidence small and sanitized.
4. Map extracted values only after the evidence contract is clear.
5. Add focused verification for dependency presence, parser fixtures, missing selectors, and schema/type checks.

## Preferred Output

For audits or plans, use a correction-ready table:

| Field | Source | Selector or Path | Type | Unit | Missing State | Storage Target | Confidence |
| --- | --- | --- | --- | --- | --- | --- | --- |

## Do Not

- Do not bypass access controls or automation defenses.
- Do not scrape non-authorized hotel/account data.
- Do not hide failures behind fallback values.
- Do not turn OTA-only page data into whole-hotel occupancy, ADR, or RevPAR claims.
- Do not commit captured HTML, cookies, tokens, screenshots with sensitive data, or raw platform responses.
