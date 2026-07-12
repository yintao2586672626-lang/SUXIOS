# OTA预订节奏与增长诊断知识库补强设计

日期：2026-07-12
状态：待用户复核
范围：知识库持久化，不新增业务事实表、采集器、页面或自动执行能力

## 1. 目标

在宿析OS现有 `knowledge_units`、`knowledge_chunks`、`knowledge_base` 三层知识体系中，持久化三项最有经营价值的 OTA 诊断知识：

1. 预订节奏与短窗口风险；
2. 流量增长但转化承接不足；
3. 客源城市损失贡献。

这些知识用于帮助 AI 解释真实 OTA 事实、识别缺口和建议人工动作，不代表已经取得真实 OTB、城市间夜或竞品全量数据，也不授权自动调价、自动投放或 OTA 写回。

## 2. 选择方案

采用“知识文档 + 幂等数据库知识迁移 + 独立验证器”的持久化方案。

未采用的方案：

- 只写 Markdown：改动最小，但系统知识检索和员工知识库无法稳定读取。
- 立即新建 OTB/城市事实表：当前缺少稳定真实来源，容易形成空壳结构或诱导使用模拟数据。

本方案复用现有知识表，不改变业务数据库结构；待真实来源明确后，再单独设计业务事实表。

## 3. 三项知识设计

### 3.1 预订节奏与短窗口风险

目的：回答未来入住窗口“当前订了多少、最近增长多快、短期预订是否断层、距目标还差多少”。

核心概念：

| 概念 | 推荐口径 |
| --- | --- |
| OTB | 指定未来入住窗口在观察时点仍有效的订单、间夜或收入 |
| Pickup | `current_on_books - previous_on_books`，必须使用相同入住窗口和业务口径 |
| 净 Pickup | `新增有效预订间夜 - 同观察窗口新增取消间夜` |
| Pace index | `current_otb / comparable_baseline_otb * 100` |
| 预订窗口占比 | `bucket_room_nights / total_otb_room_nights * 100` |
| 目标缺口 | `target_final_room_nights - current_net_otb_room_nights` |
| 每日所需 Pickup | `target_gap / remaining_observation_days` |

推荐提前期分桶必须完整且互斥：`0天`、`1天`、`2-3天`、`4-7天`、`8-14天`、`15天及以上`。不得省略中间分桶后仍把图表描述为完整结构。

必要事实：`system_hotel_id`、`platform`、`stay_date`、`observed_at`、`booking_date`、有效状态、间夜、取消时间、来源追踪和质量状态。

质量门禁：

- 没有两个可比较的真实 OTB 快照时，状态为 `on_books_snapshot_missing`，不得使用历史已售间夜冒充 Pickup。
- 没有目标、去年同提前期或近期均值时，只展示累计事实，不输出领先或落后结论。
- 只有 OTA 数据时统一标记 `metric_scope=ota_channel`，不得解释为全酒店入住预测。

建议动作只允许输出人工复核方向，例如短期促销、库存检查、价格梯度检查；不得表示动作已经执行。

### 3.2 流量增长但转化承接不足

目的：将“UV上涨、转化率下降”从描述性观点转成可量化的机会损失和排查路径。

核心公式：

| 指标 | 推荐口径 |
| --- | --- |
| 转化差距 | `hotel_conversion_rate - comparable_conversion_rate`，百分点表达 |
| 潜在订单机会 | `eligible_uv * max(0, comparable_conversion_rate - hotel_conversion_rate)` |
| 潜在间夜机会 | `potential_orders * verified_avg_room_nights_per_order` |
| 潜在收入机会 | `potential_room_nights * aligned_ota_adr` |

必要事实：相同平台、相同酒店、相同日期窗口、相同漏斗分母下的 UV、有效订单、间夜、ADR；竞品对比还需稳定竞品集和相同口径。

质量门禁：

- UV、详情访客、填单人数和提交人数不得混成同一个分母。
- 竞品转化率口径不一致时，只显示本店历史变化，不计算竞品机会损失。
- 潜在订单、间夜和收入必须标记 `derived_estimate`，不能作为已发生收入或投资事实。
- “流量增长导致转化下降”“降价未换来转化”等因果结论禁止直接输出；AI只能提示需要核查房型、图片、价格、早餐、退改、评价、库存和支付确认链路。

### 3.3 客源城市损失贡献

目的：避免只按城市同比跌幅排序，改为同时观察绝对损失、收入影响、样本量和贡献度。

核心公式：

| 指标 | 推荐口径 |
| --- | --- |
| 城市间夜同比 | `(current_city_room_nights - baseline_city_room_nights) / baseline_city_room_nights * 100` |
| 城市绝对损失 | `max(0, baseline_city_room_nights - current_city_room_nights)` |
| 损失贡献率 | `city_lost_room_nights / total_lost_room_nights * 100` |
| 收入影响估计 | `city_lost_room_nights * aligned_city_or_hotel_adr` |

必要事实：客源城市、OTA 平台、入住日期窗、观察日期、有效间夜、对比基期、来源追踪和质量状态。

质量门禁：

- 只有 `source_city + distribution_share` 时，只能描述客群结构，不能反推城市间夜损失。
- 基期为 0 或样本过小时，不输出同比百分比，改为显示绝对值和 `small_base`。
- TOP 城市列表未覆盖全部客源时，不得把已返回城市占比当作完整酒店客源结构。
- OTA 城市客源不得升级为全酒店客源；收入影响只能标记 `derived_estimate`。

建议动作只允许输出候选方向，例如城市定向投放、交通/景区套餐、异地客退改优化，并要求人工确认预算、产品和执行结果。

## 4. 持久化结构

实施阶段新增一份幂等迁移：

`database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql`

迁移写入：

- `knowledge_units`：单一知识单元“OTA预订节奏与增长诊断知识库”；
- `knowledge_chunks`：`使用边界`、`预订节奏诊断`、`转化机会诊断`、`客源城市诊断`、`AI行动边界` 五类结构化 JSON；
- `knowledge_base`：面向员工和 Agent 检索的 Markdown 正文；
- `knowledge_categories`：复用现有系统级“OTA运营”分类，不重复创建同名分类。

幂等策略：按 `name + source` 更新知识单元，按目标 `type` 删除后重建知识块，按 `hotel_id=0 + title` 更新员工知识条目。

同时更新：

- `docs/hotel_ota_metric_professional_knowledge.md`：增加“三项高价值诊断知识”章节；
- `scripts/verify_ota_booking_diagnosis_knowledge.mjs`：静态验证迁移、文档、公式、质量门禁和禁用结论；
- `package.json`：增加 `verify:ota-booking-diagnosis-knowledge` 命令。

不修改当前已存在大量用户改动的 `scripts/verify_e2e_contracts.mjs`，避免扩大冲突面。

## 5. AI读取与输出约束

AI检索到知识后按以下顺序处理：

1. 确认门店、平台、入住日期窗、观察时点和指标范围；
2. 确认必要事实和分母是否存在；
3. 输出事实、派生指标、假设和缺口；
4. 只有质量门禁通过时才给诊断；
5. 只生成候选人工动作，不声称已执行，不自动写 OTA。

统一缺口状态至少包括：

- `on_books_snapshot_missing`
- `comparison_baseline_missing`
- `conversion_denominator_missing`
- `source_city_room_nights_missing`
- `competitor_caliber_mismatch`
- `small_base`
- `derived_estimate`

缺失分母返回 `null + data_gap`，不得返回 `0` 或用旧数据静默替代。

## 6. 验证与验收

独立验证器必须检查：

- 迁移包含三层知识写入且可重复执行；
- 三项知识名称、公式、必要字段和数据缺口状态完整；
- 明确包含 `metric_scope=ota_channel`；
- 明确禁止历史已售间夜冒充 OTB/Pickup；
- 提前期分桶包含 `4-7天`，且完整互斥；
- 转化机会标记 `derived_estimate`，不写因果结论；
- 城市占比不能反推城市间夜；
- 不新增业务事实表、采集逻辑、页面或 OTA 写回；
- 不包含账号、Cookie、令牌、平台原始身份或客户识别信息。

实施完成后的最小验证集：

```powershell
node scripts/verify_ota_booking_diagnosis_knowledge.mjs
git diff --check -- database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql docs/hotel_ota_metric_professional_knowledge.md scripts/verify_ota_booking_diagnosis_knowledge.mjs package.json
```

若本地 MySQL 可用且用户授权实际应用迁移，再执行迁移并只读回查：知识单元 1 条、目标知识块 5 类、员工知识条目 1 条。数据库未运行时，结果必须标记为“迁移文件已验证、数据库未应用”。

## 7. 非目标

- 不在本轮建立 OTB、竞品集、城市间夜或房价可比性业务事实表；
- 不导入截图中的酒店数值；
- 不把截图 AI 文案当作事实或模型训练样本；
- 不修改 OTA 采集、登录、Cookie/Profile、房态、订单或写回逻辑；
- 不新增页面和图表；
- 不自动生成、批准或执行调价、促销、投放动作。

## 8. 停止条件

迁移、文档、验证器和命令入口形成闭环并通过最小验证后停止。真实 OTB 数据接入、业务事实表和用户界面必须作为后续独立功能，经新一轮范围确认后实施。
