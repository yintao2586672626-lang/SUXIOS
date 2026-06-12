# 宿析OS第一阶段目标：OTA 数据可信获取与经营诊断闭环

Updated: 2026-06-12

## 结论

第一阶段目标不是让 AI 全自动管酒店，而是先把携程、美团 OTA 经营数据做成可信、可解释、可追溯、可执行的经营诊断闭环。

系统必须让酒店员工每天清楚回答：

1. 今天携程、美团 OTA 数据有没有采到。
2. 哪些字段可信，证据来自哪里。
3. 哪些字段缺失、失败、未授权或未采集。
4. 收入、流量、转化、广告、服务质量哪里出了问题。
5. AI 建议依据了哪些 OTA 数据、指标和缺口。
6. 下一步该执行什么动作，以及是否需要审批、执行证据和复盘。

## 核心保护边界

- 不改变携程和美团手动获取、自动获取逻辑。
- 不改变现有获取字段、字段映射和历史入库兼容口径。
- 不把 OTA 渠道数据包装成全酒店经营事实。
- 不默认自动写回 OTA 房价、库存、规则或订单状态。
- 不新增默认 `reviews`、`orders`、`traffic_data` 明细表；确需结构化明细时必须单独评审。
- 不用兜底值、假成功、空数据默认值或宽泛 catch 掩盖采集失败、字段缺失和授权失败。
- 点评数据不作为默认自动采集重点；默认只保留评分、数量、回复率等聚合口径，点评明文必须显式授权。

## 第一阶段业务链条

```text
携程/美团 OTA 数据
-> 手动获取或自动获取
-> 原始响应证据
-> source path
-> metric key
-> online_daily_data
-> UI 字段状态
-> ota-standard 收益指标
-> AI 经营诊断
-> 运营执行意图
-> 审批、执行证据、复盘
```

每一环都必须保留状态：成功、部分成功、失败、缺字段、未授权、未采集、未命中接口、解析失败。

## 数据资源优先级

| 优先级 | 资源 | 目标 | 当前口径 |
|---|---|---|---|
| P0 | `businessData` 经营概况 | 收入、间夜、订单、评分的日经营判断 | 使用现有 `amount`、`quantity`、`book_order_num`、`comment_score`、`raw_data` |
| P0 | `flowData` 流量转化 | 曝光、详情页、下单漏斗、转化异常 | 使用现有 `list_exposure`、`detail_exposure`、`flow_rate`、`order_filling_num`、`order_submit_num` |
| P0 | `tradeData` / order 聚合 | 订单量、订单金额、间夜和取消问题 | 不改现有字段；订单明细先脱敏留在 `raw_data` |
| P0 | `peerRank` 竞品排名 | 判断是否是曝光、价格、排名或竞争问题 | 排名、竞品和来源证据保留在 `raw_data`/既有字段 |
| P1 | `searchKeywords` 搜索词 | 辅助解释流量变化 | 未采到时必须显式标注 |
| P1 | `roomTypes` 房型/产品 | 辅助价格、库存、房型转化分析 | 只做目录和产品信息，不做房态或房源映射 |
| P1 | `advertising` 广告 | 判断花费、点击、转化和 ROAS | 仅在账号和成本口径明确时纳入 |
| P2 | `reviewData` 点评聚合 | 评分、数量、回复率和服务质量趋势 | 不默认采集点评正文 |

## 状态口径

第一阶段必须显式展示并传递这些状态，而不是隐藏成成功：

- `success`
- `partial_success`
- `failed`
- `waiting_config`
- `waiting_auth`
- `auth_failed`
- `api_not_hit`
- `field_missing`
- `parse_failed`
- `not_collected`
- `manual_intervention_required`

## AI 决策边界

- AI 只做经营诊断和执行建议，不直接替员工执行 OTA 后台动作。
- AI 结论必须引用 OTA 指标、数据缺口、证据来源或数据库证据。
- 数据缺口必须进入 `data_gaps`、置信度和建议限制，不允许被话术消解。
- 执行动作进入运营管理后，必须经过审批、执行证据和复盘。

## 验收标准

1. 数据获取状态可信：携程、美团手动/自动路径都能明确返回成功、部分成功、失败、未授权、未配置、未采集或字段缺失。
2. 字段资产可信：关键字段能追到原始响应、source path、metric key、存储字段和 UI 状态。
3. 收益指标可信：OTA 标准层能输出收入、间夜、订单、ADR、RevPAR、Net RevPAR、流量转化、广告和质量指标，并保留 `data_gaps`。
4. AI 诊断可信：AI 建议必须有证据来源和下一步动作，不把缺失数据写成确定结论。
5. 运营闭环可信：建议能形成执行意图；阻塞、审批、执行证据和复盘状态可追踪。
6. 范围可信：所有报表和诊断都标注 OTA 渠道范围，不冒充全酒店经营真相。

## 验证命令

```powershell
npm.cmd run verify:phase1-ota-loop
npm.cmd run verify:phase1-ota-audit
npm.cmd run verify:phase1-employee-console
npm.cmd run verify:phase1-gap-explanations
npm.cmd run verify:phase1-live-closure-contract
npm.cmd run verify:platform-data-source-contract
npm.cmd run verify:field-asset-ledger
npm.cmd run verify:ota-revenue-metrics-smoke
npm.cmd run verify:ota-data-batch
npm.cmd run verify:e2e-contracts
```

`verify:phase1-ota-loop` 是第一阶段目标保护命令：它只做结构化只读检查，不启动 OTA 采集，不访问外部平台，不改字段、不改获取逻辑、不写数据库。

真实闭环验收使用 `npm.cmd run inspect:phase1-live-closure` 读取当前数据库和脱敏接口证据；严格验收使用 `npm.cmd run verify:phase1-live-closure`，缺真实采集、AI 证据或运营执行样例时必须失败。
