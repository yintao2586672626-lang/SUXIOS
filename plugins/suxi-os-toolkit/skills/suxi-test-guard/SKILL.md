---
name: suxi-test-guard
description: Guard宿析OS bug fixes and validation. Use when the request includes 报错、bug、修复、验证、测试、复现、回归、接口测试、健康检查、curl、Postman、test-api.ps1、test-login.ps1、public/index.html保护、不要构建、Vite覆盖、最小修复。
---

# Suxi Test Guard

## Rules

1. Reproduce or identify the failing path before editing.
2. Change only the minimal necessary file set.
3. Do not run Vite build in `public/`.
4. Do not touch `public/index.html` unless the user explicitly asks and the risk is stated first.
5. Prefer existing manual verification paths when no test framework exists.
6. Report only key errors, root cause, fix, verification, and risk.

## Verification Options

- `GET /api/health`
- Existing PowerShell API scripts if present.
- Targeted `curl` or browser flow for the affected page.
