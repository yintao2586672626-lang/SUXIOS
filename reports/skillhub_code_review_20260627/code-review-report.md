# 代码审查报告

- 审查日期：2026-06-27
- 审查范围：`HOTEL` 当前工作区差异；GitHub 仓库 `yintao2586672626-lang/SUXIOS` 当前无 Open PR，未取得远端 PR diff。
- 业务链路：OTA数据 -> 收益分析 -> AI决策 -> 运营管理 -> 投资决策
- SkillHub：`skillhub 2026.6.27`
- 已安装 Skill：`pr-reviewer`、`critical-code-reviewer`、`project-code-standard`、`security-audit`、`clean-code-review`、`cody`

## 获取层结果

### PR / Diff

- `gh pr list --repo yintao2586672626-lang/SUXIOS --state open` 返回 `[]`。
- 因无 Open PR，本次按本地工作区差异审查。
- `pr-reviewer/scripts/pr-review.sh` 需要 Bash/WSL；当前 Windows 环境无可用 Linux 发行版，自动脚本未能执行。已用 `gh pr list`、`git diff --stat`、`git diff --name-status`、本地 lint/测试作为等价获取层补偿。

### 变更范围摘要

- 已跟踪源文件差异：`81 files changed, 9340 insertions(+), 542 deletions(-)`，不含 `reports/`、`storage/`、`runtime/`、`output/`、`node_modules/`、`vendor/`、锁文件和 SQL。
- 主要影响面：
  - OTA Profile 登录后同步：`app/command/PlatformProfileLogin.php`、`public/auto-fetch-static.js`、`public/index.html`
  - 浏览器辅助 OTA 导入：`app/controller/concern/PlatformDataSourceConcern.php`、`app/service/OtaBrowserAssistImportService.php`、`scripts/lib/ota_browser_assist_normalize.mjs`
  - 投资决策辅助：`app/controller/InvestmentDecision.php`、`app/service/InvestmentDecisionSupportService.php`、`app/service/P0OtaDownstreamGateService.php`
  - P0/Phase1 证据闭环脚本与测试：`scripts/verify_p0_ota_field_loop_closure.php`、`tests/OnlineDataTest.php` 等

## 严重程度汇总

| 等级 | 数量 | 说明 |
| --- | ---: | --- |
| 致命 | 0 | 未发现会直接导致未授权写入、数据破坏或系统不可用的问题 |
| 严重 | 0 | 未发现需要立即阻断合并的已证实缺陷 |
| 警告 | 1 | UI 成功提示与后端同步失败状态不一致 |
| 建议 | 3 | 审计归因、体积拆分、测试补强 |

## 问题清单

### 警告：Profile 登录后同步失败时，完成 toast 仍显示成功

- 位置：`public/index.html:22745`
- 相关后端：`app/command/PlatformProfileLogin.php:189`
- 现象：后端在登录成功但同步异常时写入 `status=logged_in` 且 `after_login_sync.status=failed`；`public/auto-fetch-static.js:221` 已能把失败状态显示为“目标日同步未闭环”，但 `public/index.html:22745` 的 toast 只判断 `taskForView.status === 'logged_in'`，仍以 `success` 类型提示。
- 影响：运营人员可能把“平台账号已登录”误解为“目标日 OTA 数据也已同步入库”，削弱 P0 字段闭环和后续收益/AI/投决链路的证据可信度。
- 建议修复：toast 判断增加 `after_login_sync.status` 分支；当为 `failed`、`partial_success`、`unknown` 或 `saved_count <= 0` 时使用 `warning`，并复用 `platformProfileLoginTaskText(taskForView)`。

### 建议：后台登录后同步使用系统用户，审计归因不够细

- 位置：`app/command/PlatformProfileLogin.php:642`
- 现象：`syncDataSourceAfterProfileLogin()` 最终使用 `systemSyncUser()`，同步任务的 `requested_by` 可能落到系统用户 `id=1`，而不是触发登录的真实操作人。
- 已核销安全风险：触发入口有登录校验、`can_fetch_online_data`、酒店权限、数据源平台和酒店匹配校验。
- 残余影响：审计排查时无法直接从同步任务还原真实触发用户，需要依赖外层 `OperationLog` 或任务输入文件。
- 建议：在任务输入中保存 `requested_by`，同步时以“受控系统执行 + 原始操作者 ID”写入 `stats_json` 或同步任务扩展字段。

### 建议：`public/index.html` 继续膨胀，投决与 OTA 状态逻辑应拆出静态模块

- 位置：`public/index.html`
- 现象：本次单文件新增约 1100 行，且混入投决页面、Profile 登录状态、OTA 辅助导入入口等多类逻辑。
- 影响：代码审查、冲突解决、局部验证成本继续上升；后续 P0/投决状态修复容易误伤无关页面。
- 建议：按现有 `public/*-static.js` 模式，优先拆出 `investment-decision-static.js` 和 Profile 登录状态 toast/文案辅助函数。

### 建议：为 Profile 同步失败 toast 增加前端自动化回归

- 位置：`tests/automation/` 或现有前端契约脚本
- 现象：后端和静态文案测试覆盖了同步状态，但未覆盖 `public/index.html` 的最终 toast 类型。
- 影响：当前问题容易复发，因为核心状态文案和 toast 类型位于不同文件。
- 建议：新增一个轻量静态测试，构造 `taskForView = { status: 'logged_in', after_login_sync: { status: 'failed' } }`，断言 toast 类型不是 `success`。

## 已核销风险

- 投资决策 API 路由已挂 `Auth`：`route/app.php:413`。
- `InvestmentDecision::resolveHotelScope()` 依赖 `User::getPermittedHotelIds()`；`User` 对超级管理员返回所有启用酒店，不存在“超管空绑定导致固定 403”的已证实问题。
- 浏览器辅助导入入口在控制器层检查 `can_fetch_online_data`，服务层通过 `PlatformDataSyncService::importRows()` 复用酒店权限和入库链路。
- 浏览器辅助导入契约验证通过，且不会原样暴露 `source_url`，仅保存 hash。

## 验证记录

| 命令 | 结果 |
| --- | --- |
| `skillhub --version` | 通过，`skillhub 2026.6.27` |
| `skillhub list --dir HOTEL/.agents/skills` | 通过，6 个专家包 Skill 均已安装 |
| `gh pr list --repo yintao2586672626-lang/SUXIOS --state open` | 通过，无 Open PR |
| `npm.cmd run review:non-security` | 通过 |
| `npm.cmd run type-check` | 跳过：无 TypeScript 输入 |
| `npm.cmd run verify:investment-decision` | 通过 |
| `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\InvestmentDecisionSupportServiceTest.php tests\P0OtaDownstreamGateServiceTest.php` | 通过，6 tests / 71 assertions |
| `C:\xampp\php\php.exe scripts\verify_ota_browser_assist_import_contract.php` | 通过 |
| `node --test tests\automation\ota_browser_assist_normalize.test.mjs tests\automation\p0_profile_next_steps_report.test.mjs` | 通过，3 tests |
| `npm.cmd audit --omit=dev --json` | 通过，0 vulnerabilities |

## 未完成 / 受限项

- 未执行真实 PR diff 审查：当前无 Open PR。
- `pr-reviewer` 原生 Bash 脚本受当前 Windows/WSL 环境限制未运行成功。
- 未执行 Composer 依赖漏洞扫描：当前未发现 `composer` 或 `composer.phar`。
- 未启动完整 Web 服务做浏览器 UI 验证；本次为代码审查包，不改业务代码。
