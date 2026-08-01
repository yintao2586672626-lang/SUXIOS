# AI → 运营严格契约：已转绿守卫

## 范围

只覆盖现有主线：AI建议 → 执行意图 → 人工审批 → 人工执行证据 → 效果复盘。

不覆盖 OTA 采集、Profile/Cookie、投资、转让、开业或 PMS，也不授权 OTA 自动写回。

## 默认回归契约

1. 价格动作缺少平台、明确酒店、房型、价型、正数目标价、生效日或有效证据时，必须返回 422，并在数据库写入前终止；不能创建 `blocked` 意图后再返回成功。
2. 合法价格动作只创建 `pending_approval` 意图，仍需人工审批，不能宣称已经执行。
3. 必须提供酒店权限范围内的 intent/task 按 ID GET；越权与不存在统一为 404，不泄漏其他酒店资源。
4. AI日报只有在创建结果满足 `id > 0`、`status=pending_approval`、`blocked_reason` 为空时，才保存 `execution_intent_id`。
5. 前端每次 POST 后必须按返回 ID GET 回读，在状态、任务或证据吻合后才显示成功：
   - 创建：intent 为 `pending_approval` 且未阻塞。
   - 审批：intent 状态与操作一致；批准时至少存在一个 task。
   - 执行：task 为 `executed` 且 evidence 数量大于 0。
   - 复盘：task 的 `result_status` 与提交值一致。
6. 状态转换必须单向：只有 `pending_approval` 可审批；只有已执行任务可补执行/ROI证据；只有已执行且已有证据的任务可复盘；`success/near_success/failed` 终态不能被重试请求覆盖或退回 `observing`。

## 已落地文件

- `app/service/OperationManagementService.php`
  - 价格动作前置严格校验；补公开的酒店隔离 intent/task 读取；补状态守卫与必要事务。
- `app/controller/OperationManagement.php`
  - 新增两个资源读取动作；参数异常映射 422；不存在或越权映射 404；保留具体安全错误信息。
- `route/app.php`
  - 在集合路由前新增 `GET /execution-intents/:id` 与 `GET /execution-tasks/:id`。
- `app/service/AiDailyReportService.php`
  - 创建后断言 `id/status/blocked_reason`，断言通过后才回写日报。
- `public/app-main.js`
  - 增加两个读取助手；创建、审批、两条执行入口和复盘都改为 POST → GET → 校验 → 成功提示。
- 正式回归文件
  - 把本目录 PHP 用例合并进 `tests/OperationExecutionLoopTest.php` 或独立正式测试。
  - 把本目录 Node 用例合并进 `tests/automation/operation_frontend_closure.test.mjs` 与路由契约测试。

## 执行方式

PHP 用例已由 `phpunit.xml` 纳入默认 Backend 套件；Node 用例由 `tests/automation/ai_operation_strict_contract.test.mjs` 纳入默认串行自动化回归。

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --configuration phpunit.xml evals\ai-operation-strict-contract\AiOperationStrictContractPendingTest.php
C:\xampp\php\php.exe vendor\bin\phpunit --configuration phpunit.xml evals\ai-operation-strict-contract\AiOperationStrictPersistencePendingTest.php
node --test evals\ai-operation-strict-contract\ai_operation_strict_contract.pending.test.mjs
```

当前预期：三条命令均通过；任何 422、酒店隔离、状态机或 POST→GET 回读退化都会使默认回归失败。
