# Playwright 测试分层

## 1. 日常快速回归

用于每次改代码后的快速验证，覆盖登录、16 个模块导航、页面渲染、基础可交互元素统计、API 4xx/5xx 和页面异常。

```powershell
$env:E2E_BASE_URL='http://localhost:8080/'
$env:E2E_USERNAME='admin'
$env:E2E_PASSWORD='admin123'
npm run test:e2e:daily
```

报告目录：

```text
output/playwright/daily-regression/
```

Actual reports are written to `output/playwright/daily-regression/runs/<run-id>/`.
Use `output/playwright/daily-regression/latest-run.json` to find the latest run.

## 2. 前端极端输入与异常捕获

用于自动遍历 Vue 3 页面可见表单字段和按钮，写入空值、超长文本、特殊字符、非法邮箱/URL、非法日期、负数、极大数等边界值，并捕获页面异常、console error/warning、API 4xx/5xx。

默认 `E2E_EDGE_LIVE_API=0`：登录后拦截 POST/PUT/PATCH/DELETE API，统一返回 422，用于模拟服务端校验异常并避免真实写库。需要打真实接口时再显式设置 `E2E_EDGE_LIVE_API=1`。

```powershell
$env:E2E_BASE_URL='http://localhost:8080/'
$env:E2E_USERNAME='admin'
$env:E2E_PASSWORD='admin123'
$env:E2E_EDGE_MAX_FIELDS_PER_MODULE='12'
$env:E2E_EDGE_CASES_PER_FIELD='4'
$env:E2E_EDGE_MAX_ACTIONS_PER_MODULE='8'
$env:E2E_EDGE_LIVE_API='0'
npm run test:e2e:edge
```

报告目录：

```text
output/playwright/edge-input-guard/
```

Actual reports are written to `output/playwright/edge-input-guard/runs/<run-id>/`.
Use `output/playwright/edge-input-guard/latest-run.json` to find the latest run.

## 3. 异步串页专项回归

用于防止历史详情接口慢返回后，把当前页面错误回填到旧模块。

```powershell
$env:E2E_BASE_URL='http://localhost:8080/'
$env:E2E_USERNAME='admin'
$env:E2E_PASSWORD='admin123'
npm run test:e2e:async
```

报告目录：

```text
output/playwright/async-page-guard/
```

Actual reports are written to `output/playwright/async-page-guard/runs/<run-id>/`.
Use `output/playwright/async-page-guard/latest-run.json` to find the latest run.

## 4. 全按钮/数据互通回归

用于阶段验收或上线前压力验证，不建议每次小改都跑 100 轮。

```powershell
$env:E2E_MUTATE='1'
# 关键功能验证阶段：E2E_LOOP 会被限制在 50~100
$env:E2E_LOOP='50'
$env:E2E_ALLOW_DESTRUCTIVE='0'
$env:E2E_MAX_BUTTONS_PER_MODULE='30'
$env:E2E_MAX_FIELDS_PER_MODULE='40'
$env:E2E_DB_BACKUP='1'
$env:E2E_DB_RESTORE='0'
$env:E2E_BASE_URL='http://localhost:8080/'
$env:E2E_USERNAME='admin'
$env:E2E_PASSWORD='admin123'
npm run test:e2e:full
```

报告目录：

```text
output/playwright/full-click/
```

Actual reports are written to `output/playwright/full-click/runs/<run-id>/`.
Use `output/playwright/full-click/latest-run.json` to find the latest run.

数据库备份默认写入：

```text
output/playwright/db-backup/
```

需要在测试后恢复备份时，显式设置 `E2E_DB_RESTORE=1`。默认不恢复，避免误覆盖调试现场。

## 5. 业务链路断言

用于验证 OTA 数据、运营动作、扩张评估、转让决策、战略推演、量化模拟、可行性报告之间的保存、回显和读取链路。

```powershell
$env:E2E_BASE_URL='http://localhost:8080/'
$env:E2E_USERNAME='admin'
$env:E2E_PASSWORD='admin123'
$env:E2E_API_REQUEST_TIMEOUT_MS='15000'
npm run test:e2e:business
```

报告目录：

```text
output/playwright/business-chains/
```

Actual reports are written to `output/playwright/business-chains/runs/<run-id>/`.
Use `output/playwright/business-chains/latest-run.json` to find the latest run.

## 6. CI 快速回归

建议 CI 使用快速组合，夜间或上线前再运行全按钮/数据互通回归。

```powershell
npm run verify:e2e-contracts
npm run test:e2e:quick
```

需要覆盖前端 UI 自动化和极端输入时：

```powershell
npm run test:e2e:ui
```

## 7. 静态测试契约校验

用于 CI 快速检查稳定选择器、语义化输入、报告分类、数据库备份入口是否仍存在。

```powershell
npm run verify:e2e-contracts
```

## 并发报告安全

每个测试套件都会写入 `output/playwright/<suite>/runs/<run-id>/`，并更新 `output/playwright/<suite>/latest-run.json` 指向最新报告。

这样同时运行多条 Playwright 命令时，不会互相删除或覆盖 `summary.json`、截图和 API 事件报告。

## 报告分类

新增报告字段 `category`：

- `api-error`：请求失败、网络错误、5xx 或非预期 4xx。
- `page-error`：页面 JS 异常。
- `product-bug`：接口成功但业务断言失败、页面状态与预期不一致。
- `selector-or-dom-state`：元素超时、脱离、不可见、定位不稳定。
- `test-data-invalid`：测试数据不符合字段或业务校验，典型为 400/422。
- `safe-skip`：删除、归档、重置等破坏性按钮被安全跳过。
- `test-environment`：本地依赖、数据库备份工具或运行环境缺失。
- `missing-history-action`：专项用例中当前环境没有可点击历史按钮。

每个报告目录会生成 `summary.json`，用于快速看总数和分类。
