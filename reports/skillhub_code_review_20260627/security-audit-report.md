# 安全审计报告

- 审查日期：2026-06-27
- 审查范围：当前本地差异中的认证、授权、敏感信息、依赖、OTA 导入与投决读取链路。
- 安全基线：不把缺失采集、登录失败、字段缺口或同步失败包装成成功状态。

## 结论

未发现已证实的致命或严重安全漏洞。发现 1 个审计归因建议，另有若干受限项需要在后续环境补齐。

## 漏洞扫描结果

| 项目 | 结果 | 说明 |
| --- | --- | --- |
| 生产 NPM 依赖 | 通过 | `npm audit --omit=dev --json` 返回 0 vulnerabilities |
| Composer 依赖 | 未验证 | 当前环境未发现 `composer` 或 `composer.phar` |
| 明文凭证差异扫描 | 未发现新增硬编码密钥 | 差异中有 Cookie/Token 相关字段处理，但未发现新增真实凭证值 |
| 未授权路由 | 未发现 | 新增 `api/investment-decision` 和 `api/online-data/browser-assist-import` 均在 Auth/权限链路内 |
| OTA 文件上传 | 通过基本限制 | 上传 JSON 限制 5MB，要求 JSON 对象 |

## 认证与授权

### 投资决策辅助

- 路由：`route/app.php:413`
- 控制器：`app/controller/InvestmentDecision.php:21`
- 范围校验：`resolveHotelScope()` 使用当前用户可访问酒店列表。
- 超级管理员核销：`app/model/User.php:225` 对超管返回所有启用酒店。
- 结论：未发现越权读取证据。

### 浏览器辅助 OTA 导入

- 路由：`route/app.php:278`
- 控制器：`app/controller/concern/PlatformDataSourceConcern.php:117`
- 权限链路：
  - `checkPermission()`
  - `checkActionPermission('can_fetch_online_data')`
  - `OtaBrowserAssistImportService::importCapture()`
  - `PlatformDataSyncService::importRows()`
  - `saveDataSource()` / `syncDataSource()` 内部酒店权限校验
- 契约验证：`scripts/verify_ota_browser_assist_import_contract.php` 通过。
- 结论：未发现未授权导入入口。

### Profile 登录后同步

- 触发入口：`app/controller/concern/OnlineDataRequestConcern.php:854`
- 数据源请求构造：`app/controller/concern/AutoFetchConcern.php:967`
- 入口权限：登录、`can_fetch_online_data`、酒店权限、数据源平台/酒店匹配。
- 后台同步：`app/command/PlatformProfileLogin.php:208`
- 结论：未发现直接越权同步问题。

## 发现与建议

### 建议：同步任务审计归因应保留真实触发用户

- 位置：`app/command/PlatformProfileLogin.php:642`
- 风险：后台命令使用 `systemSyncUser()` 执行 `syncDataSource()`，任务归因可能显示系统用户。权限链路本身已在触发端完成，但审计闭环不够直接。
- 修复建议：
  - 在 `createPlatformProfileLoginTask()` 输入中保存 `requested_by`。
  - `PlatformProfileLogin` 执行同步时把 `requested_by` 写入同步任务 `stats_json` 或专用字段。
  - 保留 `system_executor=true`，明确区分执行身份和触发身份。

### 建议：同步失败 UI 提示不能显示成功

- 位置：`public/index.html:22745`
- 风险：不属于权限漏洞，但会误导运营把 OTA 目标日同步失败当成成功，从而污染后续收益/AI/投决判断。
- 修复建议：toast 类型按 `after_login_sync.status` 降级为 `warning` 或 `error`。

## 受限项

- `security-audit/scripts/audit.cjs` 是 Clawdbot/Linux 路径假设脚本，当前 Windows 项目环境不适用，未强行运行。
- Composer CVE 扫描未完成，原因是当前命令环境没有 Composer。
- 未执行动态渗透测试、浏览器会话安全测试或真实 OTA 平台抓取测试。

## 安全结论

当前差异没有证据显示新增未授权 OTA 导入、未授权投决读取、硬编码真实凭证或生产 NPM 依赖漏洞。主要安全相关改进点是审计归因和失败状态显式化。
