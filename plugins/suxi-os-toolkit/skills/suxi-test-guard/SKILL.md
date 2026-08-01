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
3. Do not run Vite build in `public/`.
4. Treat `public/index.html` as protected, but do not require the user to know its filename. It may be edited when the reproduced user-visible path directly points there, targeted inspection confirms necessity, and the change is the smallest viable fix; state the risk and verify the actual page.
5. Prefer existing manual verification paths when no test framework exists.
6. Report only key errors, root cause, fix, verification, and risk.

## Verification Options

- `GET /api/health`
- Existing PowerShell API scripts if present.
- Targeted `curl` or browser flow for the affected page.
