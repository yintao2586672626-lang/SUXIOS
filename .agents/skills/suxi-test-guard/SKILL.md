---
name: suxi-test-guard
description: 用于宿析OS问题修复和验证守卫，触发场景包括 报错、bug、修复、验证、测试、复现、回归、接口测试、健康检查、curl、Postman、test-api.ps1、test-login.ps1、public/index.html保护、不要构建、Vite覆盖和最小修复。
---

# Suxi Test Guard

## Tool Choice

Use the smallest direct verification tool that matches the failing surface. Do not route every bug through a plugin selector; use Browser/Chrome, CI evidence, error traces, or desktop control only when that source is actually required.

## Rules

1. Reproduce or identify the failing path before editing.
2. Change only the minimal necessary file set.
3. Match verification depth to business risk and select one default tier from **Three-Level Verification** below; add checks only for a concrete touched boundary:
   - Small or local change: syntax/static check plus the smallest related test, API check, or actual page check.
   - Core OTA, revenue, AI-to-operation, investment formula, tenant, auth, persistence, or data-quality contract: add/update a targeted regression test and run adjacent tests when the change can cross a boundary.
   - Cross-module change, release candidate, commit/PR preparation: run the full PHPUnit and Node suites plus the relevant project guards.
4. Automated tests are development/CI guards, not production automation. Never let a test call real OTA collection, a real LLM, production data writes, messaging, publishing, or irreversible actions unless the user explicitly authorizes that exact test target.
5. Use fixtures, stubs, or test-only data. Tests that write runtime files or test databases must use unique paths/scopes and precisely restore or remove only what they created.
6. Coverage is diagnostic, not a 100% KPI or default release gate. Prioritize zero-coverage services that directly break the product chain and critical failure/boundary paths. If Xdebug/PCOV is unavailable, report that limitation instead of silently installing it.
7. A test or coverage run never authorizes `git add`, commit, push, PR creation, or deployment. These require an explicit user request.
8. Do not run Vite build in `public/`.
9. Treat `public/index.html` as protected, but do not require the user to know its filename. It may be edited when the reproduced user-visible path directly points there, targeted inspection confirms necessity, and the change is the smallest viable fix; state the risk and verify the actual page.
10. Prefer existing manual verification paths when no test framework exists.
11. Report only key errors, root cause, fix, verification, and risk.

## Three-Level Verification

### Level 1: Daily single-file or low-risk edit

- PHP: `C:\xampp\php\php.exe -l <touched.php>`
- JavaScript: `node --check <touched.js>`
- If behavior changed, add only the smallest related PHPUnit/Node test, API check, or actual page check.
- Documentation-only or instruction-only edits normally use a targeted content check plus `git diff --check`; do not run project-wide guards by default.

### Level 2: One feature loop completed

- Run the targeted PHP/Node regression tests for the changed feature.
- Run `npm.cmd run verify:public-entry` only when the frontend entry, login-critical assets, public shell, or performance budget can be affected.
- Run `npm.cmd run verify:e2e-contracts` only when routes, API/UI contracts, cross-file integration, or guarded frontend code shape can be affected.
- Add the actual page/API check when the feature is user-facing. Choose by affected surface; these commands are not an automatic pair.

### Level 3: Cross-module change, commit/PR, or release candidate

- Run `git diff --check`, full PHP and Node suites, and the relevant project guards in proportion to the changed scope.
- `npm.cmd run self:check` is the main repository umbrella and already invokes `verify:p0-guards`.
- `verify:p0-guards` already invokes `verify:e2e-contracts`; do not rerun nested guards after an umbrella pass unless isolating a failure or the earlier command exited before reaching them.
- A local Level 3 pass does not prove live OTA capture, production data, scheduled delivery, or external deployment; report those states separately.

## Smart Verification Trial

- Preview a Level 1 plan: `node scripts/verify_smart.mjs <touched files...>`
- Preview a completed-feature plan: `node scripts/verify_smart.mjs --feature <touched files...>`
- Preview a commit/PR plan: `node scripts/verify_smart.mjs --commit <touched files...>`
- Add `--run` only after the file scope and commands are correct.
- In a mixed or dirty worktree, always pass the current task's files explicitly. With no file arguments the script reads every current Git change and reports that broader scope.
- Level 3 emits `self:check` once and omits its nested `verify:p0-guards` and `verify:e2e-contracts` commands.

## Verification Options

- Targeted PHP: `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\TargetTest.php`
- PHP syntax: `C:\xampp\php\php.exe -l <file>`
- Targeted Node: `node --test tests/automation/<target>.test.mjs`
- Full backend before release/PR: `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never`
- Full Node before release/PR: `npm.cmd run test:node`
- `GET /api/health`
- Existing PowerShell API scripts if present.
- Targeted `curl` or browser flow for the affected page.
