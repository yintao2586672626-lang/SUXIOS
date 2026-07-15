# 宿析OS核心业务链整改执行手册

更新日期：2026-07-15  
适用范围：`HOTEL/`  
目标链路：真实 OTA 数据 → 收益分析 → AI 决策 → 运营管理 → 投资决策

## 1. 执行结论

当前项目不需要整体重构。应按照以下顺序完成最小业务闭环：

1. 修正 OTA 数据可信边界和运行状态假绿灯。
2. 补齐 AI 价格建议到运营执行意图的字段契约。
3. 让运营和投资模块读取真实、同门店、同日期的权威状态。
4. 用一个授权测试酒店完成全链路样板验收。
5. 最后处理验证器、模板跟踪和交付整理。

本手册中的任何“通过”都必须同时有：真实来源、保存结果、数据库回读、页面回显或明确的只读接口回读。结构检查通过不能替代运行时业务闭环。

## 2. 当前基线

| 环节 | 2026-07-15 已验证状态 | 是否允许向下游宣称完成 |
|---|---|---|
| 本地入口 | `/api/health` 返回 200，登录页正常；health 本身不检查数据库 | 否，仅证明应用存活 |
| 前端结构 | 模板、公共入口、2221 项 E2E 结构契约通过 | 否，仅证明代码结构存在 |
| 携程 OTA | 目标日 124 行、traffic 62 行，field fact=`partial` | 否 |
| 美团 OTA | 目标日 141 行、traffic 0 行，field fact=`not_loaded` | 否 |
| OTA P0 | 2 个平台，0 个 ready，`claim_allowed=false` | 否 |
| 收益分析 | 仅有 OTA 渠道局部事实；缺全酒店可售房晚等可信分母 | 否 |
| 运营管理 | 7 条已执行记录，全部为数据采集/诊断；ROI ready=0 | 否 |
| 投资决策 | 30 条记录，eligible=0 | 否 |
| 发布 | 设计交付和 OTA 凭据轮换仍有外部阻塞 | 否 |

## 3. 执行边界

### 必须遵守

- OTA 数据只能用于对应渠道分析，不得自动扩大为全酒店结论。
- 缺失字段保持 `null` 或明确缺失状态，不得补 0、旧值或模拟值。
- 人工导入默认不高于 `unverified`，完成来源和回读复核后才能提升质量状态。
- 携程/美团返回的门店标识必须与系统酒店持久绑定一致；缺失或不一致时禁止保存。
- 不打印或落盘 Cookie、Token、敏感请求头和原始账号响应。
- 当前工作区有大量既有改动。只按工单路径暂存，不使用 `git add -A`、`git commit -am` 或破坏性回退。

### 本轮非目标

- 不重写 `Agent.php`、`RevenueAiOverviewService.php` 或整个前端入口。
- 不进行无关 UI 改版、企业级治理或完整发布流程。
- 不先扩充更多 AI 提示词、图表或投资模型页面。
- 不在 P0 数据门禁未通过时伪造完整演示闭环。

## 4. 执行参数与预检

所有命令从 `HOTEL/` 执行。先替换授权酒店 ID，不得使用占位符运行写入操作。

```powershell
Set-Location 'D:\桌面\SUXIOS\宿析OS初始版\HOTEL'

$AuditDate = '2026-07-15'
$HotelId = '<授权的 system_hotel_id>'
$Php = 'C:\xampp\php\php.exe'
```

### 预检命令

```powershell
# 1. 确认 MySQL，而不是只看静态 health
& 'C:\xampp\mysql\bin\mysql.exe' -u root hotelx -e 'SELECT 1 AS db_ok;'

# 2. 确认应用入口
(Invoke-WebRequest 'http://127.0.0.1:8080/api/health' -UseBasicParsing).Content

# 3. 保存当前工作区边界，只观察，不清理
git status --short
git diff --check

# 4. 获取当前业务链真值
npm.cmd run report:business-chain -- --date=$AuditDate --format=json
npm.cmd run verify:p0-ota-field-loop -- --date=$AuditDate --system-hotel-id=$HotelId
```

### 预检停止条件

出现以下任一情况时，不进入真实数据写入：

- `$HotelId` 未明确授权。
- 平台门店 ID 与系统酒店绑定不一致。
- MySQL 不可连接。
- 目标日期不明确。
- 唯一可用来源是历史数据、模拟数据或无法回读的数据。

## 5. 工单总览

| 顺序 | 工单 | 优先级 | 主要交付物 | 前置依赖 |
|---:|---|---|---|---|
| 1 | BC-01 修正业务链假绿灯 | P0 | 状态与 P0 门禁一致 | 无 |
| 2 | BC-02 隔离竞品并消除 ETL 静默截断 | P0 | 可信 OTA 收益输入 | BC-01 |
| 3 | BC-03 修复人工修改和门店绑定证据 | P0 | 可追溯事实版本 | BC-02 |
| 4 | BC-04 AI分析改为服务端取数 | P0 | 不可伪造的 AI 输入 | BC-02、BC-03 |
| 5 | BC-05 补齐价格执行目标契约 | P0 | 可审批、可回读的执行意图 | BC-04 |
| 6 | BC-06 运营与投资读取权威状态 | P0 | 真实运营统计和投决门禁 | BC-01、BC-05 |
| 7 | BC-07 完成一个授权酒店样板闭环 | P0 | 真实全链路验收记录 | BC-01 至 BC-06 |
| 8 | BC-08 修复验证器与交付完整性 | P1 | 干净环境可重复验证 | BC-07 |

---

## 6. BC-01：修正业务链假绿灯

### 目标

P0 未通过时，OTA、收益、AI、运营和投资任何阶段都不得显示 `ready` 或 `claim_allowed=true`。

### 修改文件

- `scripts/report_business_chain_status.php`
- `tests/automation/business_chain_status_report.test.mjs`
- `scripts/verify_business_chain_report_contract.mjs`

### 修改步骤

1. 为 `business_chain_stage_rows()` 增加目标日 P0 状态和 gate 参数。
2. 将 OTA 阶段 ready 条件改为：

   ```text
   p0_ready=true
   AND target_dataset.accepted_rows > 0
   AND target_dataset.data_quality.truncated != true
   ```

3. P0 未通过时统一返回：

   ```json
   {
     "status": "incomplete",
     "claim_allowed": false,
     "reason_code": "blocked_by_p0_ota_gate"
   }
   ```

4. 历史数据、参考日期数据只能进入 `reference_evidence`，不能决定当前阶段 ready。
5. 保证 overall 与各 stage 不再出现“P0 incomplete、OTA stage ready”的矛盾。

### 必增测试

- P0 blocked + 历史 accepted rows > 0：OTA stage 必须 incomplete。
- P0 ready + 目标日 accepted rows > 0：OTA stage 才能 ready。
- P0 ready + 目标日无行：OTA stage 必须 incomplete。
- 任一上游 stage 不允许 claim 时，依赖它的下游 stage 不允许 claim。

### 验证

```powershell
npm.cmd run verify:business-chain-report
npm.cmd run report:business-chain -- --date=$AuditDate --format=json
```

### 完成标准

- 报告中不存在 `p0_ready=false` 与 `ota_data.claim_allowed=true` 同时出现。
- 当前真实基线仍应如实显示 incomplete；修代码不能把缺失数据变成 ready。

---

## 7. BC-02：隔离竞品并消除 ETL 静默截断

### 目标

收益汇总只使用本店、经营类、来源可追溯的目标日事实；竞品、广告、流量和被截断的数据不能进入本店收入。

### 修改文件

- `app/service/OtaStandardEtlService.php`
- `app/service/OtaRevenueMetricService.php`
- `app/service/OtaDataCredibilityGateService.php`
- `app/service/CtripCompetitionCirclePersistenceService.php`
- `tests/OtaStandardModuleTest.php`
- `tests/OtaDataCredibilityGateServiceTest.php`
- `tests/CtripCompetitionCirclePersistenceServiceTest.php`

### 修改步骤

1. 在收益 ETL 入口明确允许的经营粒度：

   ```text
   compare_type = self
   data_type IN (business, order)
   platform IN (ctrip, meituan)
   system_hotel_id = 当前授权酒店
   data_date = 目标日期
   ```

2. `data_type=competitor` 永远不得进入本店收入、间夜、订单和 ADR 汇总；竞品只保留为 benchmark。
3. 将默认 1000 行单次读取改为以下二选一，由代码现状决定：
   - SQL 按业务 grain 直接选择 canonical/latest row；或
   - 分页读取直到完整结束。
4. 返回数据质量元信息：

   ```json
   {
     "input_rows_total": 1592,
     "loaded_rows": 1592,
     "truncated": false
   }
   ```

5. 任何安全上限命中、分页中断或总数不一致都必须 `truncated=true`，可信门禁 fail-closed。
6. 缺 source trace、ingestion method、field fact 或门店绑定证明时，默认 `unverified`。

### 必增测试

- 1 条本店经营数据 + 10 条竞品数据：收益只能累计本店 1 条。
- 超过 1000 条且包含首尾不同业务 grain：首尾均进入结果。
- 模拟分页中断：`truncated=true` 且 `claim_allowed=false`。
- 缺 provenance 的行存在数值：不得成为 trusted revenue。

### 验证

```powershell
& $Php vendor\bin\phpunit --colors=never `
  tests\OtaStandardModuleTest.php `
  tests\OtaDataCredibilityGateServiceTest.php `
  tests\CtripCompetitionCirclePersistenceServiceTest.php

npm.cmd run verify:p0-ota-field-loop -- --date=$AuditDate --system-hotel-id=$HotelId
```

### 完成标准

- 竞品金额不会改变本店收入、间夜、订单、ADR。
- 大于 1000 行的数据不会静默缺行。
- 任何不完整读取都明确阻断可信状态。

---

## 8. BC-03：修复人工修改和门店绑定证据

### 目标

人工输入不能伪装成平台实采；平台门店身份不一致不能保存；原始事实必须可恢复。

### 修改文件

- `app/controller/concern/OnlineDataRecordConcern.php`
- `app/controller/concern/OnlineDataQualityConcern.php`
- `app/controller/concern/OnlineDataManualFetchConcern.php`
- `app/service/OnlineDailyDataPersistenceService.php`
- 相关数据库迁移和对应测试

### 修改步骤

1. 禁止对已验证平台事实执行原地覆盖。
2. 优先复用现有 provenance 字段；若现有结构无法表达修订历史，再新增 append-only correction ledger，最少包含：

   ```text
   original_row_id
   system_hotel_id
   platform
   data_date
   field_name
   old_value
   new_value
   reason
   actor_user_id
   review_status
   created_at
   reviewed_at
   reviewed_by
   ```

3. manual import/edit 使用 `source_method=manual_*`，初始质量状态为 `unverified` 或 `manual_override_pending_review`。
4. 删除改为 tombstone/retraction，记录操作者、原因和原 trace；不得硬删除后静默重算历史收益。
5. 自动保存前强制校验：返回平台酒店 ID 与持久绑定完全一致。
6. ID 缺失或不一致时允许只读展示，但返回 `binding_missing`，保存数量必须为 0。
7. 对同门店、平台、日期、业务类型、维度和事件粒度建立唯一键，并使用原子 upsert，避免并发重复。

### 必增测试

- 修改一条 verified fact 后，原值和原 trace 仍可回读。
- 人工 override 未复核时，可信门禁阻断。
- 删除后存在 tombstone，历史事实仍可审计。
- 平台酒店 ID 缺失或不匹配时，保存数量为 0。
- 两次并发写入同一 grain 后数据库仅有一个有效版本。

### 验证

```powershell
& $Php vendor\bin\phpunit --colors=never `
  tests\OnlineDataTest.php `
  tests\OtaStandardModuleTest.php `
  tests\OtaCredentialReadPathTest.php `
  tests\OnlineDataTenantScopeTest.php
```

### 完成标准

- 无法通过人工修改继承旧平台可信证明。
- 错店、缺绑定和重复 grain 无法进入真实收益。
- 所有修订均能追溯到原始事实、操作者和原因。

---

## 9. BC-04：AI分析改为服务端取数

### 目标

客户端不能通过伪造酒店数组、收入、间夜或转化率生成正式 AI 建议。

### 修改文件

- `app/controller/concern/OnlineDataRecordConcern.php`
- `app/service/OnlineDataAnalysisReportService.php`
- `public/ai-analysis-static.js`
- `public/app-main.js`
- `tests/OnlineDataTest.php`

### 新请求契约

```json
{
  "system_hotel_id": 123,
  "platform": "ctrip",
  "date_from": "2026-07-15",
  "date_to": "2026-07-15",
  "scope": "ota_channel"
}
```

### 修改步骤

1. 删除 `hotels[]`、收入、订单、间夜、分数等客户端事实字段的信任路径。
2. 控制器只接收门店、平台、日期和分析范围。
3. 先执行用户门店授权，再由服务端调用标准 OTA 数据服务加载事实。
4. 读取并校验 P0 gate、field facts、source trace 和数据质量。
5. P0 incomplete 时返回明确 blocked 响应，不生成定价、营销或经营建议。
6. 缺失值保持 `null`；竞品只作为 benchmark，不进入本店经营合计。
7. 响应必须携带 `scope=ota_channel`、日期、平台、数据质量和阻断原因。

### 必增测试

- 客户端提交伪造收入字段：接口拒绝或完全忽略该字段。
- 越权酒店 ID：403。
- P0 incomplete：409，且无正式建议。
- P0 ready：建议中的每个数值可追溯到服务端事实。

### 验证

```powershell
& $Php vendor\bin\phpunit --colors=never `
  tests\OnlineDataTest.php `
  tests\OtaDataCredibilityGateServiceTest.php `
  tests\OtaHotelScopeAuthorizationTest.php

npm.cmd run verify:e2e-contracts
```

### 完成标准

- 修改浏览器请求体中的收入、间夜和分数不会改变服务端事实。
- P0 未通过时页面显示真实阻断原因和重试入口，不展示正式经营建议。

---

## 10. BC-05：补齐价格执行目标契约

### 目标

AI 价格建议只有在酒店、平台、房型、价格计划、目标价和生效日期完整时，才能创建运营执行意图。

### 修改文件

- `app/service/AiDailyReportService.php`
- `app/service/OperationManagementService.php`
- `app/controller/RevenueAi.php`
- `public/app-main.js`
- `resources/frontend/templates/fragments/16-page-ai-daily-report.html`
- `resources/frontend/templates/fragments/27-page-agent-center.html`
- 对应 PHP 和自动化测试

### 统一目标契约

```json
{
  "system_hotel_id": 123,
  "platform": "ctrip",
  "room_type_key": "deluxe_king",
  "rate_plan_key": "room_only",
  "target_price": 399,
  "effective_date": "2026-07-16",
  "source_suggestion_id": 456,
  "evidence_scope": "ota_channel"
}
```

### 修改步骤

1. 提取共享的 `PriceExecutionTarget` 校验器，AI日报和 Revenue AI 共用。
2. UI 在创建意图前展示并要求确认平台、房型、价格计划、目标价和生效日期。
3. 必要字段缺失时返回 422；不得先保存一条 blocked intent 再返回成功。
4. 后端成功时必须返回 `execution_intent_id` 和真实状态。
5. 前端只有在状态允许进入审批时才显示成功提示。
6. POST 成功后立即按 ID 严格 GET 回读；GET 失败或找不到该 ID 时显示失败并允许重试。
7. 已生成但被后端拒绝的建议不能永久禁用按钮。

### 必增测试

- 缺 room type/rate plan/target price：422，数据库无新 intent。
- 酒店或平台不一致：403/422，数据库无新 intent。
- 完整目标：创建成功，GET 回读字段完全一致。
- POST 200 但 GET 找不到：页面不得提示闭环成功。
- blocked 响应后修正字段可以重试。

### 验证

```powershell
& $Php vendor\bin\phpunit --colors=never `
  tests\AiDailyReportReadinessServiceTest.php `
  tests\OperationManagementServiceTest.php `
  tests\RevenueAiOverviewServiceTest.php

npm.cmd run verify:e2e-contracts
npm.cmd run verify:frontend-template
npm.cmd run verify:public-entry
```

### 页面验收

使用授权测试酒店执行：

1. 打开 AI 日报或 Revenue AI。
2. 选择一条真实、P0 ready 的价格建议。
3. 确认房型、价格计划、目标价和日期。
4. 创建执行意图。
5. 打开运营管理并按返回 ID 查到同一条记录。
6. 刷新页面，记录仍存在且字段一致。

### 完成标准

- 不再出现“后端 blocked、前端提示成功”。
- 一条完整建议能够创建、回读、刷新后保留，并进入人工审批。

---

## 11. BC-06：运营与投资读取权威状态

### 目标

业务链报告显示真实运营记录；投资模块只在同门店、同日期的真实 OTA、运营和 ROI 条件满足时解锁。

### 修改文件

- `scripts/report_business_chain_status.php`
- `app/service/BusinessClosureOverviewService.php`
- `app/service/InvestmentDecisionSupportService.php`
- `app/controller/TransferDecision.php`
- `app/service/TransferDecisionService.php`
- 对应测试

### 修改步骤

1. 报告通过只读服务加载真实 `OperationManagementService::executionFlow()` 聚合，删除固定 0。
2. 无法加载时返回 `null/not_loaded`，不得用 0 表示未知。
3. `BusinessClosureOverviewService` 接收或查询权威 P0 gate，作用域必须包含：

   ```text
   system_hotel_id + platform + target_date
   ```

4. 缺 gate、门店不一致、日期过期时默认 blocked；同门店同日期 ready 才允许继续。
5. 转让/投资经营快照由服务端按授权门店重建，拒绝使用客户端提交的收入、利润和入住率作为事实。
6. OTA 渠道收入与全酒店经营收入使用不同字段和口径标签。
7. 投资 eligible 最少要求：真实 P0 ready、运营任务闭环、同口径 ROI ready、全酒店经营事实 ready。

### 必增测试

- 当前本地聚合应显示 7 条运营记录、ROI ready=0，而不是 0/0。
- P0 ready，但酒店不一致：投资 blocked。
- P0 ready，但日期陈旧：投资 blocked。
- 同酒店、同日期 P0 ready，且运营/ROI未闭环：投资仍 blocked。
- 客户端伪造经营快照：服务端忽略或拒绝。

### 验证

```powershell
& $Php vendor\bin\phpunit --colors=never `
  tests\OperationManagementServiceTest.php `
  tests\InvestmentDecisionSupportServiceTest.php `
  tests\TransferDecisionServiceTest.php

npm.cmd run verify:business-chain-report
npm.cmd run verify:investment-decision
```

### 完成标准

- 报告数字与运营服务只读聚合一致。
- 投资状态不再被固定 P0 gate 永久阻塞。
- 也不能通过客户端快照或 OTA 局部数据绕过全酒店经营门槛。

---

## 12. BC-07：完成一个授权酒店样板闭环

### 目标

不追求一次覆盖所有酒店。只选择一个授权测试酒店和一个明确目标日期，完成可重复验收的完整样板。

### 前置条件

- 携程或美团至少一个平台的目标日 P0 gate 为 ready。
- 酒店、平台门店 ID、日期和采集方式均明确。
- 用户同意人工审批和人工执行，不自动写入 OTA 平台。
- 有最小全酒店经营事实；若暂无 PMS/CRS，使用经确认的人工导入，但必须标记来源和确认状态。

### 最小全酒店经营事实

优先复用现有表和服务。若现有结构无法保存以下字段及 provenance，再增加日粒度事实表：

| 字段 | 必需 | 说明 |
|---|---|---|
| `system_hotel_id` | 是 | 系统酒店 |
| `data_date` | 是 | 经营日期 |
| `available_room_nights` | 是 | 全酒店可售房晚 |
| `sold_room_nights` | 是 | 全酒店售出房晚 |
| `net_room_revenue` | 是 | 净客房收入 |
| `variable_cost` | ROI需要 | 可变成本 |
| `fixed_cost` | 投决需要 | 固定成本 |
| `source_method` | 是 | PMS/CRS/manual_verified |
| `source_reference` | 是 | 脱敏来源引用 |
| `verification_status` | 是 | unverified/verified/failed |
| `verified_by/verified_at` | 是 | 确认人和时间 |

### 操作步骤

1. 获取目标日 OTA 数据并保存。
2. 数据库回读行数、关键字段、门店、平台和日期。
3. 运行 P0 verifier，必须 ready。
4. 生成一条有明确证据来源的收益诊断。
5. 生成一条完整价格建议。
6. 人工确认房型、价格计划、目标价和日期。
7. 创建执行意图并按 ID 回读。
8. 人工审批。
9. 人工在 OTA 平台执行，并只保存脱敏执行证据。
10. 记录同口径执行前后窗口：同酒店、同平台、同房型、同价格计划。
11. 计算效果和 ROI；数据采集类任务不得计算收入 ROI。
12. 刷新运营页面，确认审批、执行、证据、复盘和 ROI 均可回显。
13. 打开投资决策页，确认状态只在所有前置条件满足后变化。

### 最终验收命令

```powershell
npm.cmd run verify:p0-ota-field-loop -- --date=$AuditDate --system-hotel-id=$HotelId
npm.cmd run report:business-chain -- --date=$AuditDate --format=json
npm.cmd run verify:business-chain-report
npm.cmd run verify:investment-decision
npm.cmd run verify:e2e-contracts
npm.cmd run verify:frontend-template
npm.cmd run verify:public-entry
```

### 完成标准

- OTA 事实有真实来源、门店绑定、目标日期、保存和回读证据。
- 收益指标明确标注 OTA 渠道或全酒店口径。
- AI 建议能创建真实执行意图，并完成审批、执行、证据、复盘和 ROI。
- 投资决策使用同门店、同日期的真实闭环数据。
- 页面刷新后所有关键状态仍能回显。

### 立即停止条件

- 任一步出现跨酒店、错误平台门店或不可逆真实数据风险。
- P0 仍 incomplete。
- 只能用历史、模拟或默认 0 补齐当前数据。
- 执行意图无法严格回读。
- ROI 前后数据口径不一致。

---

## 13. BC-08：修复验证器与交付完整性

### 目标

让干净检出环境得到与当前工作区一致的验证结果，消除结构假绿和旧模板假失败。

### 修改步骤

1. 投资验证器改为读取 frontend template source/assembled template 和 `public/app-main.js`，不再只读旧单体 `public/index.html`。
2. 将新模板片段、manifest、source map、模板快照和生成产物作为一个原子变更处理。
3. 检查 manifest 引用的 9 个未跟踪模板片段，逐个确认后按明确路径暂存。
4. 将 `review:functional-readiness` 改名为 `review:structural-readiness`，或让它实际执行运行时门禁；兼容旧脚本可保留别名。
5. 将路由覆盖、OTA 授权强提醒 Playwright 测试加入标准快速门禁。
6. 通知中心和“转运营”操作增加 loading/ready/error 状态及严格回读失败提示。

### 暂存检查

```powershell
git status --short
git diff --check

# 只检查本工单涉及的路径；不要执行 git add -A
git diff --name-only -- `
  resources/frontend/templates `
  scripts/verify_investment_decision_support_contract.mjs `
  package.json `
  tests/automation
```

### 验证

```powershell
npm.cmd run verify:frontend-template
npm.cmd run verify:public-entry
npm.cmd run verify:e2e-contracts
npm.cmd run verify:investment-decision
npm.cmd run review:functional-readiness
git diff --check
```

### 完成标准

- 干净检出后模板构建不缺文件。
- 投资验证器不因模板拆分产生假失败。
- 结构检查不再被误解释为运行时业务就绪。
- 不包含当前工单之外的用户改动。

## 14. 每个工单的固定交付格式

每完成一个工单，按以下模板记录：

```markdown
### 工单：BC-XX

- 结果：完成 / 部分完成 / 阻塞
- 改动文件：
- 用户可见入口：
- 数据来源与口径：
- 保存数量：
- 回读数量：
- 运行的验证：
- 通过项：
- 未运行项：
- 剩余阻塞：
- 是否允许进入下一工单：是 / 否
```

## 15. 最终 Definition of Done

只有同时满足以下条件，核心业务链才能标记为完成：

- [ ] 目标酒店、平台、日期和来源均明确。
- [ ] 携程/美团目标范围内的 P0 gate 按实际数据返回 ready。
- [ ] 竞品、广告、人工未复核、被截断和错店数据不会进入 trusted revenue。
- [ ] OTA 渠道指标与全酒店经营指标明确分开。
- [ ] AI 输入来自服务端可信事实，客户端不能伪造正式建议。
- [ ] 一条价格建议完成目标确认、创建、回读、审批、执行和证据保存。
- [ ] ROI 使用同酒店、同平台、同房型、同时间窗口和同口径数据。
- [ ] 投资模块读取真实 P0、运营和 ROI 状态，不使用客户端快照绕过门禁。
- [ ] 页面刷新后关键数据和状态仍能正确回显。
- [ ] 业务链报告不存在阶段假绿灯或未知伪装成 0。
- [ ] 相关测试和实际页面验收均通过。
- [ ] 未把无关工作区改动带入交付。

完成以上闭环后停止，不自动扩展到 UI 重做、企业级治理或正式生产发布。
