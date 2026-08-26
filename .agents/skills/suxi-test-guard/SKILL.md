---
name: suxi-test-guard
description: 用于宿析OS问题修复和按影响面选择验证，触发场景包括 报错、bug、修复、验证、测试、复现、回归、接口测试、健康检查、curl、Postman、test-api.ps1、test-login.ps1、public/index.html保护、不要构建、Vite覆盖和最小修复。验证用于支持具体判断，不以测试数量、commit/PR 标签或重复全量运行制造信心。
---

# Suxi Test Guard

## Tool Choice

First follow [`rules/codex-execution-status-contract.md`](../../../rules/codex-execution-status-contract.md). This Skill only helps choose the smallest command that proves the affected behavior.

## Rules

1. Reproduce or identify the failing path, then change the smallest file set.
2. Select Level 1/2/3 from impact radius, never from commit/PR/release labels.
3. Tenant/hotel isolation, OTA identity/date, persistence, formulas, auth, approval and irreversible writes require targeted regression coverage.
4. Tests use fixture/stub/test-only data and never call real OTA/LLM, production writes, messaging or publishing without exact authorization.
5. Reuse unchanged evidence. After a fix, rerun the failed check and direct dependencies; broaden only when the change broadened.
6. Use an actual page/API check when that is the user-visible claim. Local tests never prove deployment or field validation.
7. Do not build inside `public/`; edit protected `public/index.html` only when the reproduced path requires it and verify the actual page.
8. Report with the five-line status template and stop when the claim is proved.

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
