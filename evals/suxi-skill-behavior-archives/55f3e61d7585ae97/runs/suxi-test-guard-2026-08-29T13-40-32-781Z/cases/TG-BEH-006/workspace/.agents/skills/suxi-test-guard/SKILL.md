---
name: suxi-test-guard
description: 用于宿析OS问题修复和按影响面选择验证，触发场景包括 报错、bug、修复、验证、测试、复现、回归、接口测试、健康检查、curl、Postman、test-api.ps1、test-login.ps1、public/index.html保护、不要构建、Vite覆盖和最小修复。验证用于支持具体判断，不以测试数量、commit/PR 标签或重复全量运行制造信心。
---

# Suxi Test Guard

## Tool Choice

First follow [`rules/codex-execution-status-contract.md`](../../../rules/codex-execution-status-contract.md). This Skill only helps choose the smallest command that proves the affected behavior.

## Rules

1. Reproduce or identify the failing path first. Change the smallest file set only when the user asked to fix or change behavior; read-only verification, diagnosis, readiness, or acceptance requests do not authorize edits.
2. Select Level 1/2/3 from impact radius, never from commit/PR/release labels.
3. Tenant/hotel isolation, OTA identity/date, persistence, formulas, auth, approval and irreversible writes require targeted regression coverage.
4. Tests use fixture/stub/test-only data and never call real OTA/LLM, production writes, messaging or publishing without exact authorization.
5. Reuse unchanged evidence. After a fix, rerun the failed check and direct dependencies; broaden only when the change broadened.
6. Use an actual page/API check when that is the user-visible claim. Bind the observation to the named hotel or tenant, platform, business date, source and quality state; local tests never prove deployment or field validation.
7. Do not build inside `public/`; edit protected `public/index.html` only when the reproduced path requires it and verify the actual page.
8. Report with the five-line status template and stop when the claim is proved.

## Evidence-Backed Acceptance

Before selecting commands for a behavior change, bug fix, or acceptance claim, record the smallest useful internal contract:

```text
test_basis / user_visible_claim / deterministic_oracle / highest_risk_path /
planned_check / target_and_environment / retained_evidence / execution_status
```

- `test_basis` must point to an actual requirement, reproduced symptom, route/schema contract, approved example, fixture, or current project rule. Record conflicts instead of silently choosing one source.
- Cover the representative success path and only the boundary, failure, isolation, persistence, permission, recovery, or compatibility paths justified by the current risk. Ordinary fixes do not require a formal QA dossier or every testing dimension.
- Reuse an explicit requirement, SLA, budget, or approved tolerance. When no numeric oracle exists, use a deterministic qualitative outcome or `threshold_pending`; never invent coverage percentages, p95 latency, concurrency multiples, pass ratios, retry counts, or other professional-looking thresholds.
- Record every in-scope check as `PASS`, `FAIL`, `BLOCKED`, `NOT RUN`, or `N/A`. `PASS` requires execution against the named target and observed oracle; `BLOCKED` and `NOT RUN` never collapse into `PASS`.
- The aggregate status follows the named user claim, not the highest green sub-check. If the user asks for current health, page behavior, or an end-to-end claim and an in-scope page/API layer is `NOT RUN`, keep the aggregate claim `NOT RUN` while listing syntax or unit checks as their own `PASS` rows. Use aggregate `PASS` from syntax/unit evidence only when the named claim is explicitly limited to that evidence layer.
- Do not invent `MIXED`, `unverified`, or another aggregate status. Preserve each check's own status, then assign the named user claim exactly one allowed status. Conflicting flaky runs with unknown attribution make the aggregate claim `BLOCKED`, not `MIXED`.
- When an in-scope check was simply not attempted and no missing prerequisite is established, use `NOT RUN`; when a missing environment, data, dependency, authorization, or valid oracle prevents execution or judgment, use `BLOCKED`. Do not substitute a generic `unverified` label for either state.
- Do not propagate one blocked dependency into an unrequested evidence layer. If the user did not make a page, API, deployment, or field claim, that layer is `N/A`; it becomes `BLOCKED` only when it is in scope and a known prerequisite prevents it.
- When missing page/API evidence becomes the one next action for OTA or operating data, name the complete observation identity in that action: hotel or tenant, platform, business date, source, and quality state. “Check the page” or a hotel/date-only instruction is not enough to support a later page-level `PASS`.
- Do not edit an approved fixture, golden file, snapshot, expected value, or acceptance oracle merely because the implementation failed. A real requirement change creates a separate baseline revision; a test-code bug may be fixed only without changing the product oracle, and the distinction must be stated.
- Before changing an approved oracle, explicitly classify all three branches: implementation/product violation; test-code defect that leaves the product oracle unchanged; or approved requirement revision that creates a new baseline. Omitting a branch is incomplete and never authorizes a refresh.
- An intermittent failure is non-passing. Preserve the first failure and every rerun; follow an existing rerun policy when one exists instead of rerunning until green. A valid observation of the product violating the oracle is `FAIL`; when environment or test-infrastructure uncertainty prevents a valid product judgment, use `BLOCKED`. If the request only reports inconsistent outcomes and gives no evidence that the failed run was a valid product observation, the aggregate product verdict is `BLOCKED` pending classification, while the individual runs retain their observed outcomes.
- Use stable case IDs and a requirement-to-evidence matrix only when several cases, handoff, audit, or an explicitly formal acceptance task needs them. Keep ordinary implementation verification lightweight.

Read [references/acceptance-evidence.md](references/acceptance-evidence.md) when planning formal acceptance, interpreting blocked/flaky results, or auditing source provenance.

## Three-Level Verification

### Level 1: Simple single-module or low-risk edit

- PHP: `C:\xampp\php\php.exe -l <touched.php>`
- JavaScript: `node --check <touched.js>`
- If behavior changed, add only the smallest related PHPUnit/Node test, API check, or actual page check.
- Documentation-only or instruction-only edits normally use a targeted content check plus `git diff --check`; do not run project-wide guards by default.

### Level 2: Complex change and direct dependencies

- Run the targeted PHP/Node regression tests for the affected modules and only their direct dependencies.
- Run `npm.cmd run verify:public-entry` only when the frontend entry, login-critical assets, public shell, or performance budget can be affected.
- Run `npm.cmd run verify:e2e-contracts` only when routes, API/UI contracts, cross-file integration, or guarded frontend code shape can be affected.
- Add the actual page/API check when the feature is user-facing. Choose by affected surface; these commands are not an automatic pair.

### Level 3: Large/core refactor or explicit full-suite gate

- Use only when the change is a large/core refactor, its dependency radius cannot be isolated, the user explicitly requests full testing, or the target environment has an explicit full-suite gate. A commit, PR, or release-candidate label alone stays at Level 1 or 2.
- Run `git diff --check`, full PHP and Node suites, and the relevant project guards once on the final unchanged candidate.
- `npm.cmd run self:check` is the main repository umbrella and already invokes `verify:p0-guards`.
- `verify:p0-guards` already invokes `verify:e2e-contracts`; do not rerun nested guards after an umbrella pass unless isolating a failure or the earlier command exited before reaching them.
- A local Level 3 pass does not prove live OTA capture, production data, scheduled delivery, or external deployment; report those states separately.

## Smart Verification Trial

- Preview a Level 1 plan: `node scripts/verify_smart.mjs <touched files...>`
- Preview a complex/direct-dependency plan: `node scripts/verify_smart.mjs --feature <touched files...>`
- Preview an explicitly justified full plan: `node scripts/verify_smart.mjs --full <touched files...>`
- `--commit` remains a compatibility alias for `--feature`; it does not select a full suite.
- Add `--run` only after the file scope and commands are correct.
- In a mixed or dirty worktree, always pass the current task's files explicitly. With no file arguments the script reads every current Git change and reports that broader scope.
- Level 3 emits `self:check` once and omits its nested `verify:p0-guards` and `verify:e2e-contracts` commands. Do not rerun them after a passing umbrella result.

## Verification Options

- Targeted PHP: `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\TargetTest.php`
- PHP syntax: `C:\xampp\php\php.exe -l <file>`
- Targeted Node: `node --test tests/automation/<target>.test.mjs`
- Full backend for an explicitly justified Level 3: `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never`
- Full Node for an explicitly justified Level 3: `npm.cmd run test:node`
- `GET /api/health`
- Existing PowerShell API scripts if present.
- Targeted `curl` or browser flow for the affected page.
