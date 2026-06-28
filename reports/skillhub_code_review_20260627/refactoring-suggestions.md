# 重构建议清单

- 审查日期：2026-06-27
- Skill：`clean-code-review`
- 原则：KISS / DRY / YAGNI；只提出与当前业务链路相关的最小重构，不建议重写无关模块。

## 优先级 1：拆出 Profile 登录任务状态提示

- 当前位置：`public/index.html:22745`、`public/auto-fetch-static.js:216`
- 问题：状态文案和 toast 类型分散，导致 `after_login_sync.failed` 被正文识别但 toast 仍显示成功。
- 建议：
  - 新增 `platformProfileLoginToastState(task)` 或复用 `platformProfileLoginTaskText(task)`。
  - 返回 `{ message, type }`，统一处理 `success`、`warning`、`error`。
  - 增加静态测试覆盖 `failed`、`partial_success`、`success saved_count=0`、`success saved_count>0`。

## 优先级 2：投决前端逻辑模块化

- 当前位置：`public/index.html`
- 问题：投决页面、业务闭环链、行动队列和状态样式直接堆在主入口文件，审查与回归成本高。
- 建议：
  - 新建 `public/investment-decision-static.js`。
  - 只迁移纯函数：状态文案、severity class、summary cards、section rows、formula rows。
  - 保持 Vue state 和请求入口暂留 `index.html`，降低一次性改动风险。

## 优先级 3：`InvestmentDecisionSupportService` 按证据读取与视图组装分层

- 当前位置：`app/service/InvestmentDecisionSupportService.php`
- 问题：同一服务同时做 DB 读取、证据归一、业务链组装、风险队列生成。
- 建议：
  - 先只抽出只读 Evidence Reader：`InvestmentDecisionEvidenceReader`。
  - 保留现有响应结构不变。
  - 用现有 `InvestmentDecisionSupportServiceTest` 锁住响应契约后再迁移。

## 优先级 4：浏览器辅助导入 PHP/Node 归一规则保持单源

- 当前位置：
  - `app/service/OtaBrowserAssistImportService.php`
  - `scripts/lib/ota_browser_assist_normalize.mjs`
- 问题：PHP 导入服务和 Node 离线归一脚本存在相似规则，长期可能出现字段定义漂移。
- 建议：
  - 短期：继续用 `scripts/verify_ota_browser_assist_import_contract.php` 和 `ota_browser_assist_normalize.test.mjs` 双向守住关键字段。
  - 中期：把字段映射表沉淀为 JSON contract，由 PHP 和 Node 各自读取。
  - 不建议现在强行合并实现，避免影响线上导入链路。

## 优先级 5：后台同步审计归因显式化

- 当前位置：`app/command/PlatformProfileLogin.php:642`
- 问题：后台同步使用系统执行身份，真实操作者需要从外部日志推断。
- 建议：
  - `createPlatformProfileLoginTask()` 写入 `requested_by`。
  - `finishTask()` 统计中增加 `requested_by`、`system_executor`。
  - 报表层显示“触发人”和“执行方式”，避免误判为系统自动采集。

## 不建议做的重构

- 不建议现在重写 OTA 采集/入库主链路。
- 不建议把 `public/index.html` 一次性拆完。
- 不建议为了通过审查引入兜底状态，把 `failed`、`missing`、`not_loaded` 包装成可用。
- 不建议修改数据库结构，除非后续明确要做同步任务审计字段持久化。
