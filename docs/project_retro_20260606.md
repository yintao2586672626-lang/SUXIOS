# 宿析OS项目复盘

日期：2026-06-06
范围：`HOTEL/` 当前工作区
口径：只记录本轮可验证事实；功能可用、发布就绪、数据完整度分开判断。

## 总结

当前项目方向正确，已经围绕以下主链路建立了较完整的代码、页面、文档和验证基础：

```text
OTA数据 -> 收益分析 -> AI决策 -> 运营管理 -> 投资决策
```

当前状态不能定义为发布就绪。更准确的判断是：

- 功能链路：基础可用，关键结构检查通过。
- 数据链路：OTA收益指标可追溯，但仍有明确字段缺口。
- AI链路：治理、日志、人工确认能力已具备，批量评估和部分生产证明未闭环。
- 运营链路：建议、审批、执行、证据、复盘结构已具备，真实执行效果仍依赖后续数据沉淀。
- 投资链路：测算和AI复核入口已具备，但AI失败 fallback 必须继续标记为本地公式兜底。
- 发布状态：未通过，缺生产环境、LLM连通性、设计交付、OTA凭证轮换证明。

## 当前事实

| 项目 | 当前证据 |
|---|---|
| Git 根目录 | `D:\桌面\SUXIOS\宿析OS初始版\HOTEL` |
| 当前分支 | `codex/save-project-20260531` |
| 当前提交 | `9d6a67c feat: optimize OTA auto capture pipeline` |
| 工作区状态 | dirty，`21` 个变更路径 |
| 核心前端规模 | `public/index.html` 约 `37137` 行 |
| 核心后端规模 | `app/controller/OnlineData.php` 约 `25344` 行 |
| 本地运行 | `http://127.0.0.1:8080/api/health` 返回 200；首页 `/` 返回 200 |
| 路由覆盖 | `324` 个 public controller action 全部被 `route/app.php` 覆盖 |

## 已通过验证

```text
git diff --check
npm.cmd run verify:p0-guards
npm.cmd run verify:taste-coverage
npm.cmd run verify:e2e-contracts
C:\xampp\php\php.exe scripts\verify_route_coverage.php
npm.cmd run review:non-security
npm.cmd run verify:ctrip-capture-catalog
npm.cmd run verify:context-assets
npm.cmd run verify:ota-revenue-metrics-smoke
C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OtaStandardModuleTest.php tests\OperationExecutionLoopTest.php tests\RevenuePricingRecommendationServiceTest.php tests\TransferDecisionServiceTest.php tests\LlmClientTest.php tests\AiModelCallLogTest.php
node --test tests/automation/ctrip_capture_catalog.test.mjs tests/automation/ota_capture_standard.test.mjs
C:\xampp\php\php.exe -l app\controller\OnlineData.php
C:\xampp\php\php.exe -l app\controller\AiGovernance.php
C:\xampp\php\php.exe -l app\service\OtaStandardEtlService.php
C:\xampp\php\php.exe -l app\service\OperationManagementService.php
C:\xampp\php\php.exe -l app\service\TransferDecisionService.php
```

关键结果：

- PHP 单元测试：`52 tests, 468 assertions` 通过。
- Node 自动化测试：`36` 个测试通过。
- E2E 合同：`112` 项检查通过。
- Ctrip 采集目录：`18` 个 section、`77` 个 endpoint、`246` 个 field，验证通过。
- 功能就绪结构检查：`103` 项结构检查通过。
- 发布议题登记：`70` 项结构检查通过。
- 发布状态合同：`86` 项结构检查通过。

## 未通过验证

```text
npm.cmd run review:release-readiness
npm.cmd run review:release-env
npm.cmd run review:release-llm
npm.cmd run review:release-design
npm.cmd run review:release-ota-credentials
```

失败原因：

| 阻塞项 | 缺失文件 | 影响 |
|---|---|---|
| 生产环境配置 | `.env.production` | 无法证明生产配置已准备 |
| LLM连通性证明 | `docs/llm_connectivity_attestation.json` | 无法证明生产模型路径可用 |
| 设计交付证明 | `docs/design_handoff_manifest.json` | 无法证明 Figma/Canva/设计源交付完整 |
| OTA凭证轮换证明 | `docs/ota_credential_rotation_attestation.json` | 无法证明发布前凭证安全状态 |

补充证据：

- `npm.cmd run review:release-security-scan` 通过。
- `git ls-files database/backups` 输出为空，备份目录未被 Git 跟踪。
- `npm.cmd run review:release-ota-credentials` 通过了备份目录和文本扫描检查，但仍因缺 OTA 凭证轮换证明失败。

## 前台页面复盘

已具备：

- 首页展示经营闭环工作台和 AI 决策追溯入口。
- 线上数据页展示数据健康钻取、授权、采集、字段、回放状态。
- 收益分析页展示指标口径，明确 OTA 渠道边界。
- 运营追踪页展示建议来源、人工审批、执行证据、ROI复盘。
- 投资测算页展示事实、假设、测算、风险四类信息层。
- AI 决策追溯页展示调用日志、Prompt 版本、评估集、人工确认。

主要风险：

- `public/index.html` 已超过 3.7 万行，继续叠加功能会放大模板闭合、响应式、状态污染和回归风险。
- 前端仍以单文件 Vue CDN 形态承载大量复杂业务，不适合持续扩展。
- 当前页面优化验证通过，但不等于所有真实用户流程都已做完整人工验收。

建议：

1. 短期只做局部增强，不重写整站。
2. 中期抽取稳定的状态计算和展示组件。
3. 长期把高频页面逐步迁移到 Vite 前端，保留兼容窗口。

## 后台与数据链路复盘

已具备：

- `route/app.php` 覆盖所有 public controller action。
- `online-data`、`ota-standard`、`ai-governance`、`operation`、`transfer` 已形成主链路路由分组。
- `OtaStandardEtlService` 能从 `online_daily_data` 构建标准事实。
- `OtaRevenueMetricService` 能汇总 OTA 收益指标并保留数据缺口。
- `OperationManagementService` 对缺少执行表、证据、ROI 输入返回 `data_gaps`。
- `TransferDecisionService` 具备资产定价、时机判断、来源快照和记录能力。

主要风险：

- `OnlineData.php` 超过 2.5 万行，承担过多 OTA 采集、配置、健康、分析、历史和平台逻辑。
- 部分服务仍有自动建表或兼容式路径，适合开发阶段，但发布前需要迁移/初始化策略明确。
- 部分指标使用“主字段缺失时取备用字段”的计算函数；当前代码会输出 data gap，但复盘和 UI 必须继续避免把它解释成全量真实指标。

建议：

1. 优先从 `OnlineData.php` 拆出 Ctrip Profile、采集健康、平台配置、历史记录四类服务。
2. 保留当前接口不变，先做服务抽取和测试补强。
3. 对涉及收益公式、字段映射、表结构的改动必须先补验证脚本。

## OTA数据复盘

已具备：

- Ctrip 字段目录和采集目录验证通过。
- 文档明确 OTA 渠道口径不是全酒店经营口径。
- 字段目录中保留 `api_not_hit / field_missing / parse_failed` 等缺失状态。
- 点评明文、手机号、住客名等敏感信息不进入字段目录和报告。

当前缺口：

收益指标烟测通过，但暴露以下字段组缺口：

| 缺口 | 当前影响 |
|---|---|
| `available_room_nights_missing` | OCC、RevPAR、Net RevPAR 不可完整计算 |
| `commission_fields_missing` | 佣金后收入和佣金率不可完整计算 |
| `net_revenue_fields_missing` | 净收入、净RevPAR不可完整计算 |
| `cancellation_fields_missing` | 取消率不可完整计算 |
| `competitor_price_fields_missing` | 竞品价差不可完整计算 |

建议：

1. 先补 `available_room_nights` 来源字段证据。
2. 再补 `commission / net_revenue` 来源字段。
3. 取消率和竞品价格先保留缺口状态，不做推算替代。

## AI决策复盘

已具备：

- `AiGovernance` 需要超级管理员访问。
- 支持模型调用摘要、日志查询、详情查看、Prompt 版本、评估集、人工确认。
- `LlmClient` 记录低置信度、人工确认状态、Prompt 版本、评估集字段。
- Agent 侧已有 AI governance payload 和人工复核逻辑。

主要风险：

- 评估集已有存储入口，但批量运行、自动评分、失败样本回流还未闭环。
- 生产 LLM 连通性证明缺失。
- 投决服务中存在 AI 失败后的本地公式 fallback；它提高可用性，但不能被表述成真实 AI 结论。

建议：

1. 补 `docs/llm_connectivity_attestation.json` 的真实生产连通证明。
2. 给 AI 治理补批量评估运行器。
3. 所有 fallback 输出统一带 `source=fallback` 和“本地公式兜底”文案。

## 运营管理复盘

已具备：

- 运营执行流支持建议、审批、任务、证据、复盘、ROI。
- 缺执行表、证据表、收入证据、成本证据时返回明确 `data_gaps`。
- UI 已展示建议来源、人工审批、执行证据、ROI复盘。

主要风险：

- 当前结构证明“流程存在”，不证明真实运营动作已经产生有效 ROI。
- ROI 依赖执行前后收入和成本证据；缺证据时只能显示 data gap。
- 自动执行边界必须持续谨慎，不能把 AI 建议直接当成已执行动作。

建议：

1. 优先补真实执行样本。
2. 建立“建议 -> 审批 -> 执行 -> 证据 -> 复盘”的最小验收用例。
3. 对每个动作记录目标指标、观察期、执行成本、平台响应。

## 投资决策复盘

已具备：

- 投资测算服务能计算资产定价、转让时机和决策看板。
- 来源快照能聚合日报和线上数据。
- 前端已拆分事实数据、人工假设、测算结果、风险与决策。

主要风险：

- OTA 数据只能作为渠道经营输入，不能直接代表全酒店投资事实。
- 租约、证照、历史流水、装修、加盟、物业等关键投决资料仍依赖人工录入或外部证明。
- AI 失败 fallback 不能当成真实模型复核。

建议：

1. 投资测算报告中强制展示事实/假设/未知。
2. 把缺少租约、证照、历史流水的项目标记为尽调未完成。
3. 投资结论必须保留人工确认状态。

## 安全与发布复盘

已具备：

- 正式安全扫描证据存在，安全扫描门禁通过。
- 发布 issue register、release status 合同、功能就绪结构检查通过。
- 数据库备份目录没有被 Git 跟踪。

未完成：

- 生产环境文件缺失。
- LLM 生产连通证明缺失。
- 设计交付 manifest 缺失。
- OTA 凭证轮换证明缺失。
- 当前工作区 dirty，不能作为干净发布状态。

建议：

1. 在发布前先补齐四个证明文件。
2. 再执行 release-readiness 全套门禁。
3. 最后处理 Git 工作区分组、提交、PR/CI 状态。

## 当前优先级

### P0：工作区收口

当前有 `21` 个变更路径。先分组：

- 产品设计前端优化：`public/index.html`、`public/style.css`
- OTA/字段/采集目录：`OnlineData.php`、`OtaStandardEtlService.php`、`ctrip_capture_catalog`、相关测试
- Data Analytics 语义层：`.agents/skills/suxi-ota-revenue-semantic-layer/`、`docs/data_analytics_suxios_improvement_report.md`
- 发布/上下文验证：`hooks/verify-context-assets.mjs`、`package.json`、`vault/project-state.md`

### P1：发布证明补齐

补齐：

- `.env.production`
- `docs/llm_connectivity_attestation.json`
- `docs/design_handoff_manifest.json`
- `docs/ota_credential_rotation_attestation.json`

### P1：收益指标字段闭环

按优先级补：

1. `available_room_nights`
2. `commission_amount / commission_rate`
3. `net_revenue`
4. `cancellation`
5. `competitor_price`

### P2：复杂度治理

先拆服务，不重写：

- Ctrip Profile 字段配置服务
- OTA 采集健康服务
- 平台配置服务
- 前端状态计算模块

### P2：AI治理增强

- 批量评估运行器
- 自动评分和失败样本沉淀
- fallback 输出统一标识
- 成本与频控

## 执行门禁

后续执行建议按顺序推进：

```text
执行第1项：工作区变更分组与收口清单
执行第2项：补发布证明文件模板和真实证据要求
执行第3项：收益指标字段缺口闭环
执行第4项：OnlineData.php 服务拆分计划
执行第5项：AI治理批量评估运行器
```

未执行前，不建议直接提交、合并或声明发布完成。
