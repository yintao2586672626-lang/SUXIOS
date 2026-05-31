# Codex 主控 Agent 有限并行工作流

本文用于约束 Codex、Claude、Qwen、Codbuddy 或自研 CLI 等多 Agent 协作时的分工、改动边界、验证和提交规则。适用范围为宿析OS `HOTEL/` 项目。

## 一、定位

Codex 默认定位为主控 Agent，不把多个子 Agent 的输出直接合并为最终结果。

```text
需求输入
  -> Codex 主控理解范围
  -> 拆分只读扫描或有限写入任务
  -> 子 Agent 输出证据/补丁/风险
  -> Codex 主控审查 diff
  -> Codex 主控统一验证
  -> Codex 主控汇总或提交
```

## 二、任务分级

| 等级 | 类型 | 是否允许并行 | 写入规则 |
|---|---|---:|---|
| R0 | 只读扫描、字段清单、路由核对、文档审计、测试失败分类 | 允许 | 禁止写文件 |
| R1 | 单文件小修复、单脚本修复、单文档口径修正 | 谨慎允许 | 每个子 Agent 只能改一个明确文件或目录 |
| R2 | 前后端联动、接口和页面同时变化、指标口径变化 | 主控拆分后允许 | 子 Agent 只提交补丁建议，主控合并 |
| R3 | OTA 采集核心、鉴权、多租户、数据库迁移、收益公式、发布状态 | 默认禁止并行写入 | 必须由主控单线处理或等待明确授权 |

## 三、可并行范围

以下任务优先并行做只读或有限写入：

| 任务 | 推荐 Agent 产出 |
|---|---|
| OTA 字段清单 | 字段、来源、口径、缺失项、未验证后端行为 |
| 携程/美团接口证据整理 | 请求来源、Payload、Response 摘要、敏感字段遮蔽状态 |
| 收益指标口径审计 | OTA 渠道口径和全酒店口径分离说明 |
| 前端页面问题扫描 | 页面、按钮、状态、错误提示、截图证据 |
| 路由和权限核对 | `route/app.php`、中间件、Controller 方法对应关系 |
| 自动化失败分类 | 失败命令、首个错误、影响范围、是否环境阻塞 |
| 文档一致性检查 | 过期说法、缺少验证命令、release blocker 是否被误关 |

## 四、禁止并行写入范围

以下范围默认不允许多个 Agent 同时改：

```text
app/controller/OnlineData.php
app/controller/Agent.php
app/middleware/Auth.php
route/app.php
public/index.html
database/
database/migrations/
scripts/verify_release_*.mjs
docs/release_readiness_status.json
```

如必须修改，主控 Agent 先声明：

```md
改动范围：
- 文件：
- 原因：
- 旧功能兼容点：
- 验证命令：
- 禁止触碰范围：
```

## 五、子 Agent 任务单模板

主控分发任务时使用以下格式：

```md
任务名称：

目标：

允许读取：

允许修改：

禁止修改：

必须输出：
- 证据文件/行号
- 问题判断
- 修改建议或补丁
- 风险
- 建议验证命令

禁止：
- 不要提交
- 不要改 release-ready 状态
- 不要用 mock 或兜底逻辑掩盖失败
- 不要把 OTA 渠道指标写成全酒店指标
```

## 六、主控合并规则

Codex 主控收集子 Agent 结果后必须执行：

1. 比对子 Agent 结论是否互相冲突。
2. 检查是否触碰禁止范围。
3. 检查是否存在凭空事实、隐藏失败、宽泛兜底。
4. 手动审查 `git diff`。
5. 只合并能用当前代码或命令验证的改动。
6. 对不能验证的外部状态保留为 blocker，不写成完成。

## 七、验证矩阵

按改动范围选择最小验证集。

| 改动类型 | 必跑命令 |
|---|---|
| 文档/流程 | `git diff --check -- <files>` |
| Node 脚本 | `node --check <file>`、相关 `npm.cmd run verify:*` |
| PHP 代码 | `C:\xampp\php\php.exe -l <file>`、相关 PHPUnit |
| 路由/接口 | `C:\xampp\php\php.exe scripts\verify_route_coverage.php`、`npm.cmd run verify:e2e-contracts` |
| 前端主文件 | `npm.cmd run verify:p0-guards`、相关 Playwright |
| release 文档/状态 | `npm.cmd run review:release-issues`、`npm.cmd run verify:release-status`、`npm.cmd run review:release-readiness` |

全量自动化使用：

```powershell
npm.cmd run codex:runner:dry
npm.cmd run verify:codex-runner-contract
npm.cmd run codex:runner:quick
```

需要真实浏览器和本地服务时，先确认：

```powershell
$env:E2E_BASE_URL='http://localhost:8080/'
$env:E2E_USERNAME='admin'
$env:E2E_PASSWORD='admin123'
```

## 八、提交前门槛

提交前必须满足：

| 门槛 | 要求 |
|---|---|
| 范围 | 只包含本次任务相关文件 |
| 证据 | 每个关键结论有代码、文档或命令结果支撑 |
| 兼容 | 新字段考虑保存、回显、编辑、旧数据 |
| 口径 | OTA channel scope 和 whole-hotel scope 分开 |
| 失败 | 缺字段、采集失败、后端未验证必须显式暴露 |
| 验证 | 相关命令已运行，失败项说明原因 |

## 九、当前项目默认策略

当前 `HOTEL/` 工作区存在较多历史未提交变更时，默认策略为：

1. 并行只读扫描优先。
2. 写代码前先收敛到单一文件或单一目录。
3. 多 Agent 输出只作为候选补丁。
4. Codex 主控统一应用补丁、统一验证。
5. 不因局部脚本通过而声明 release-ready。
