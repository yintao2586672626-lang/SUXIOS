# 前后端开发规范思路吸纳记录（2026-08-22）

## 来源指纹

- 材料类型：用户提供截图
- 原始文件名：`codex-clipboard-6215de46-dadb-4c80-aa5d-30162d3cfb5d.png`
- SHA-256：`7A53E8840461523E2BEE2E6F7F0DE221B04D0F862EA934AD1184FD4CC22404CC`
- 观察日期：2026-08-22（Asia/Shanghai）
- 授权范围：学习、吸纳并在宿析OS内适当严格执行
- 证据边界：截图仅证明可见规范文字，不证明其隐藏实现、测试覆盖或线上效果

## 可见方法

材料提出颜色卡片、页面 Token、先理解 BI 页面、统一页面状态、API 合同、前后端共享合同、统一服务响应、Mock、视觉 E2E 和六视口基线。

## 宿析OS吸收决策

### 严格采用

- 酒店/租户、平台/来源、业务日期、指标口径与数据质量状态绑定；
- 新增或被修改接口的响应与字段合同；
- 请求状态与数据可信状态分离；
- 页面、下载和导出共享 ViewModel/列定义/格式化与缺失语义；
- 正常样例加关键缺失、部分或失败样例的聚焦回归。

### 条件采用

- Token 在触及页面视觉时按语义收敛，不进行全局主题重写；
- 视觉 E2E 先覆盖关键稳定页面和状态；
- Mock 只用于 `synthetic`/`test-only` UI状态和接口合同验证。

### 延后或不设为普通功能阻塞

- 所有历史控制器响应的一次性迁移；
- 全页面、全状态、六视口截图基线；
- 通用 Mock 服务或为治理而治理的全局重构。

## 现有黄金样例

携程 eBooking 下载当前直接读取竞争圈页面已经渲染出的卡片、当前标签表头、当前排序与分页行、来源提示和缺失状态，不再维护独立的 `ctripDownloadRows` 字段表；由 `ctrip_visible_download_snapshot.test.mjs` 与 `ctrip_channel_order_breakdown.test.mjs` 保护。它用于证明“页面与下载同源”的代码级合同，不提升为真实账号、生产或现场证据。

## 现有页面补齐

- 新增 `rules/business-page-contract-registry.json`，以模板 manifest 为事实源覆盖 42 个现有产品链片段（含 2 个业务弹窗）；
- 当前可用的 AI 工作台、高级 AI 工具箱、携程、美团、数据健康、运营底座、运营闭环和收益分析按 `strict` 登记；
- 纯容器、辅助配置和策略草案片段显式继承父页面合同，禁止静默漏管；
- 当前阶段隐藏的旧策略、开业、扩张、投决和生命周期页面按 `frozen_hidden + baseline` 登记，保留已知限制；
- shell、酒店管理、系统管理、AI治理和知识中心为 5 个明确排除域；每个排除域均记录原因和替代专项门禁，不再静默遗漏；
- 新增核心片段、丢失源码锚点、回归文件或关键合同字段时，`verify:business-page-contract` 失败关闭。
- 专用验证器会读取登记表并实际执行全部 `regression_checks`；源码锚点优先使用 `data-testid`、字段和函数，普通展示文案不再成为脆弱门禁。

该补齐达到代码级 `unit_contract_pass` 或静态 `static_contract_only`，不将任何页面提升为 `field_validated`。

## 持久化入口与重验触发

- 执行规则：`rules/business-page-contract.md`
- 现有页面登记：`rules/business-page-contract-registry.json`
- Agent 触发入口：`AGENTS.md` 的“经营页面合同（适当严格执行）”
- 自动门禁：`npm.cmd run verify:business-page-contract`
- 重验触发：规则、响应基类、携程展示/下载 ViewModel、视觉 Mock 边界或 P0 守卫发生变化
- 当前目标阶段：规则、来源指纹、黄金样例与回归门禁均存在后可标记 `guarded`；不代表任一具体页面已经 `field_validated`
