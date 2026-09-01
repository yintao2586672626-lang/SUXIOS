# HOTEL Compact Agent Contract

This file intentionally overrides `HOTEL/AGENTS.md` for automatic discovery. It keeps startup context small; `AGENTS.md` remains the detailed handbook and must be read only by relevant heading when a task needs its commands or module-specific rules.

## Business outcome

- Deliver one trustworthy vertical slice in the chain: verified Ctrip/Meituan OTA data → revenue analysis → AI decisions → operations management → investment decisions.
- Optimize for a function the user can find, operate, save, read back, and verify. Do not expand beyond the requested link in the chain.
- Keep facts, assumptions, decisions, and unknowns separate. Missing, stale, partial, failed, synthetic, imported, and unverified data must remain visibly distinct.
- OTA evidence is channel-scoped and never proves whole-hotel performance by itself.

## One-loop execution

1. Define one outcome, affected files, non-goals, verification method, and stop condition.
2. Inspect the target path and direct dependencies only. Check target-file Git status/diff before writing; preserve unrelated dirty/concurrent changes.
3. Implement the smallest usable closure, including essential validation, truthful failure state, and save/readback where needed.
4. Verify the changed path proportionally. Prefer one focused automated check plus the actual page/API path when applicable.
5. Follow `rules/codex-execution-status-contract.md` for status and final output, then stop.

For a bug: reproduce → locate → minimal fix → verify. After three targeted inspections without new decisive evidence, stop investigating and take the smallest safe action or report the one blocker.

## Context, agents, and tools

- Default to one agent. Never delegate a simple query, status check, one-file change, deterministic scan, or shared-state write.
- Parallelize only genuinely independent evidence/workstreams. Maximum two open subagents; no recursive delegation. Use no-history forks and short scoped briefs, never the full conversation.
- Read common evidence once and reuse it. Do not have multiple workers reread the same large file, plan, report, log, capture, or test output.
- Use only the Skill directly triggered by the request. Generic process, design, security, deployment, office, scraping, and connector Skills are not automatic gates.
- Keep MCP/connectors disabled unless the current task directly needs that external capability. Prefer local shell and existing project entrances.
- Keep tool output bounded with `rg`, line ranges, structured fields, and focused tests. Never linearly open raw OTA capture JSON or dump excluded directories.
- End after the acceptance milestone. If substantial work remains for another session, create a concise handoff and resume in a fresh task rather than carrying a 300k+ context forward.

## Data and implementation invariants

- Every touched business fact must retain hotel/tenant, source/platform, business date, metric definition, data-quality state, and evidence boundary.
- New fields/interfaces must handle persistence, exact readback, editing, old-data compatibility, source/date, and failure state together.
- Do not use defaults, zeroes, empty arrays, stale values, cross-platform substitution, or broad wording to hide collection/data gaps.
- Reuse existing services, components, variables, functions, routes, and contracts. Avoid unrelated architecture, navigation, database, or visual changes.
- For data-dense OTA/revenue/operations/investment pages, read `rules/business-page-contract.md` and its registry only when that path is touched.
- For startup, database, frontend build, migration, protected-file, and test commands, locate the relevant heading in `AGENTS.md` with `rg` and read only that range.

## Authorization and safety

- No real OTA/PMS write, approval, external send, purchase, credential action, irreversible deletion, production deployment, commit, push, or PR without explicit scope-matching authorization.
- Local tests, HTTP 200, CI, merge, or screenshots do not prove deployment, field validation, or operating effect.
- Preserve the dirty checkout. Never reset, clean, bulk-stage, overwrite user work, or resolve conflicts mechanically.
- Never read or store passwords, cookies, browser profiles, localStorage, session tokens, sensitive headers, or credentials. Reuse only the authorized local宿析OS login state; the user completes password/MFA challenges.
- Local Cloudflare pages are forbidden in Codex IAB on this workstation; use an existing external/remote authenticated session when a Cloudflare task is explicitly requested.

## Task-specific routing

- OTA collection/import/login: use the matching `suxi-ota-ops` or `scrapling` instructions only for an authorized source.
- OTA metric/storage/UI closure: use `suxi-ctrip-field-table-closure` and the semantic-layer boundary only when their objects are touched.
- AI reports/diagnostics: use `suxi-ai-report`; investment formulas use `suxi-investment-calculation`; UI uses `suxi-dashboard-ui`; explicit bug repair uses `suxi-test-guard`.
- External material requested for learning/replication/integration uses `suxi-capability-absorption`; do not stop at a summary when the request requires a usable feature.
- Voice correction runs only for real Mandarin transcription ambiguity; coherent text is unchanged.

Keep this override below 12 KB. Put detailed, infrequent rules in `AGENTS.md`, `rules/`, or task-specific Skills and load them only when triggered.
