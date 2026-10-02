# HOTEL Compact Agent Contract

This file intentionally overrides `HOTEL/AGENTS.md` for automatic discovery. It keeps startup context small; `AGENTS.md` remains the detailed handbook and must be read only by relevant heading when a task needs its commands or module-specific rules.

## Business outcome

- Deliver the complete requested outcome in the chain: verified Ctrip/Meituan OTA data → revenue analysis → AI decisions → operations management → investment decisions. Quality and completeness take priority over token savings; necessary dependencies belong in acceptance.
- Optimize for a function the user can find, operate, save, read back, and verify. Do not expand beyond the requested link in the chain.
- Keep facts, assumptions, decisions, and unknowns separate. Missing, stale, partial, failed, synthetic, imported, and unverified data must remain visibly distinct.
- OTA evidence is channel-scoped and never proves whole-hotel performance by itself.

## One-loop execution

1. Define one outcome, affected files, non-goals, verification method, and stop condition.
2. Inspect the target path and direct dependencies only. Check target-file Git status/diff before writing; preserve unrelated dirty/concurrent changes.
3. Complete the in-scope behavior, including relevant validation, compatibility, recovery, truthful failure state, and save/readback. Handle ordinary technical problems autonomously.
4. Verify the changed path and its actual risk. Focused checks are the starting point, not a maximum; add dependencies, integration and actual page/API evidence when acceptance needs them.
5. Follow `rules/codex-execution-status-contract.md` for status and final output, then stop.

For a bug: reproduce → locate → scoped complete fix → verify. If investigation stalls, change the hypothesis or observation method; do not guess a fix or stop at a fixed inspection count. Report difficult decisions with evidence, impact, a recommended solution and all remaining acceptance gaps.

For broad scans and issue handling, use `rules/quality-completeness-and-issue-handling.md`. Cover every requested module, distinguish inspected/tested/blocked/not-run evidence, and retain all material findings. Existing process rules guide execution; they do not override the user's current outcome or authorize external effects.

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

- Visual UI changes follow `rules/interface-design-standard.md` for the affected page and shared shell, including actual states and responsive verification.

- OTA collection/import/login: use the matching `suxi-ota-ops` or `scrapling` instructions only for an authorized source.
- OTA metric/storage/UI closure: use `suxi-ctrip-field-table-closure` and the semantic-layer boundary only when their objects are touched.
- AI reports/diagnostics: use `suxi-ai-report`; investment formulas use `suxi-investment-calculation`; UI uses `suxi-dashboard-ui`; explicit bug repair uses `suxi-test-guard`.
- For monthly hotel occupancy/revenue analysis or “吉店分析格式”, read `.agents/skills/suxi-ai-report/references/occupancy-analysis-format.md` and `occupancy-revenue-methods.md` in the same directory; use `docs/report-formats/occupancy-analysis-template.md`. These are authoring and reasoning references, not permission to reuse sample figures or execute operating recommendations.
- For visual UI changes, apply `rules/interface-design-standard.md` to the affected page and shared shell; verify actual states and responsive layout.
- External material requested for learning/replication/integration uses `suxi-capability-absorption`; do not stop at a summary when the request requires a usable feature.
- For collaboration preferences, local-material study, or review of learning claims, read `docs/collaboration_learning_contract.md`; report evidence coverage and apply only supported, task-relevant conclusions.
- Voice correction runs only for real Mandarin transcription ambiguity; coherent text is unchanged.

Keep this override below 12 KB. Put detailed, infrequent rules in `AGENTS.md`, `rules/`, or task-specific Skills and load them only when triggered.
