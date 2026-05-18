# Codex 自动化 Runner

用于统一执行全模块自动化验证，并集中生成日志、异常和汇总报告。

## 执行命令

```powershell
$env:E2E_BASE_URL='http://localhost:8080/'
$env:E2E_USERNAME='admin'
$env:E2E_PASSWORD='admin123'
npm run codex:runner
```

默认 `codex:runner` 使用 extreme profile：

- 迭代型 E2E suite 循环 10 次。
- `module-smoke` 覆盖全部业务模块。
- `full-click` 执行一次，内部关键功能循环保持 50 次，且 `E2E_ALLOW_DESTRUCTIVE=0`。
- 自动设置 `E2E_INPUT_PROFILE=extreme` 与 `E2E_EXTREME_INPUTS=1`，输入长文本、边界日期、高数值、特殊字符等极端场景值。

## 快速验证

```powershell
npm run codex:runner:dry
npm run verify:codex-runner-contract
```

## 快速回归

```powershell
npm run codex:runner:quick
```

## 报告目录

```text
output/codex-runner/<run-id>/
├── plan.json
├── runner.log
├── exceptions.ndjson
├── summary.json
├── summary.md
└── logs/
```
