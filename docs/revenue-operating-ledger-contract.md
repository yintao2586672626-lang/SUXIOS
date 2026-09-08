# 经营底账增量合同 v1

L02，2026-09-08。只读事实投影，不采集、不换汇、不调平、不产生经营动作。

## 现有入口与兼容

- `RevenueFactLayerService::build/assemble()` 在原 v1 结果新增 `operating_ledger`。原有 facts、analysis_metrics、reconciliation 和 DualOtaFieldClosure 日合同不改名。
- `RevenueOperatingLedgerService::fromFactLayer($layer, $entries = [])` 消费已限定酒店/日期的事实层和标准化每日金融金额。PMS 用现有 `RevenuePmsFactSelectorService` 选择；美团云 PMS 预计房费单独记为 estimated_room_revenue。
- `OtaRevenueMetricService::ledgerEntries($dataset, $tenantId, $hotelId, $platform)` 从已标准化 `fact_ota_daily` 与 `source_trace` 投影金额、币种、日期和引用。不输出 raw_data。未形成每日总额的订单明细不冒充日快照。
- 原首页 `GET /api/dashboard/revenue-facts` 与收益 overview 携带新增字段，无新路由。收益分析的服务端签发模型新增可选 `operatingLedger`；旧版本未携带时仍可读，页面明确提示未保存该明细。

## 03/04/05/07/09 可复用的最小接口

```php
$ledger = (new RevenueOperatingLedgerService())->build([
    'tenant_id' => 9,
    'hotel_id' => 80,
    'platforms' => ['ctrip'], // 具体平台，不传 all_ota
    'start_date' => '2026-08-20',
    'end_date' => '2026-08-21',
    'evidence_mode' => 'synthetic', // 默认 production；禁止混用
], $entries);
```

以上范围仅为 synthetic 示例。build 是纯函数，调用方必须提供来源服务核验过的记录，不能把用户或模型生成的金额当来源。

每条 entry 是同平台每日总额，必需字段：tenant_id、hotel_id、platform、platform_hotel_id（OTA）、business_date、metric_key、value、currency=CNY、unit=yuan、date_basis、source_refs（现有 table#id）、quality_status、readback_verified。推荐保留 source_field、source_version、source_grain、collected_at、definition；以 grain 标明非日总额时停止汇总。

可选 `reconciliation_group` 必须由来源服务证明订单和结算属于同一核对批次，不能由日期相同推断。退款可保留 `origin_business_date`。未知币种、单位、门店身份、日期归属和回读状态不补默认值。生产与 synthetic 输入必须一致。

日期最长相差 366 天，日期按 Asia/Shanghai 业务日解释，不接受无效日。币种单位转换需在有凭证的来源适配器完成，此接口不按数值大小猜单位。

## 输出

| 字段 | 含义/消费限制 |
| --- | --- |
| contract_version / version | revenue_operating_ledger.v1 / 规范化内容 SHA-256；来源、金额、质量或日期变更生成新版本 |
| scope | tenant_id、hotel_id、platforms、start_date、end_date、timezone、evidence_mode |
| metrics[] | 各平台独立指标；不合成全酒店收入或 GOP |
| metric_key | order_amount、room_revenue、estimated_room_revenue、settlement_amount、refund_amount、fee_amount |
| value | 全请求期间每日均有唯一已验证金额、同日期基准/平台门店/指标定义/来源粒度且无冲突才给值，否则 null |
| partial_value / partial_label | 仅有可比的已验证子集时单列“非全期间金额”；全缺或日期口径冲突时 partial_value 为 null；跨日指标定义/来源粒度冲突时两者均为 null |
| covered_dates / missing_dates | 逐日覆盖；缺失、未验证或冲突日不会算已覆盖 |
| days[].entries[] | 观察值、来源字段/引用、采集时间、来源版本、事实身份、日期基准、原订单日、原因码及中文解释 |
| conflicts[] | 同指标同日冲突的全部候选；不自动选择更新或更大的金额 |
| period_semantic_conflict / semantic_variants | 跨日 definition 或 source_grain 变化时阻断期间总额和部分金额；保留每种口径的定义、粒度、日期和 source_refs；conflicts[] 同时增加 period_metric_semantics_conflict |
| duplicate_snapshots | 同范围同日等值同口径重复快照不重复求和，全部来源引用保留 |
| differences[] | OTA 各平台订单额减结算的观察差额、关联证据解释额、未解释差额和逐项证据 |
| rejected_entries[] | 错租户/酒店/平台/日期/证据模式只返回序号和原因，不回显外范围数值或来源 |

差额公式：订单额 − 结算金额 = 有关联证据的退款/费用 + 未解释差额。日期不同或核对批次未闭合时仍可保留观察差，但不宣称可比、漏款或利润。跨期退款保留发生日及原订单日；尚无跨期订单归属桥接凭证时不用于解释当日新订单差额。负费用/退款需来源先明确冲回口径。佣金不默认包含其他费用。

## 保存与精确回读

复用 `revenue_decision_snapshots.visible_model_json` 存储服务端签发的 operatingLedger。现有 POST 决策快照校验浏览器提交模型与服务端一致；相同内容幂等，新版本追加。现有 GET 按 snapshot_id 与租户/酒店精确回读，保留原版本金额和缺失状态；快照完整性校验覆盖整份模型。

`RevenueDecisionSnapshotService` 比较当前范围 ledger.version 和已存版本。不同则 evidence_identity_status=stale_current_evidence，禁止把旧证据当当前依据。该状态不改写已存内容。不需要迁移；不修改历史 SQL。

## 集成边界

- 01：保持现有来源日期/币种/单位/回读证据；可选增加明确 settlement_date、refund_date、fee_date、refund_origin_business_date、reconciliation_group。缺这些证据仍显示缺失/未验证。
- 03：继续消费不变的日 closure。期间可把可信每日总额传 build；不要把 partial_value 当全期间金额，避免二次求和重复快照。
- 04/05/07/09：引用 version + metrics[].days[].entries[].fact_identity + source_refs；解释使用 difference reason_texts 与 unexplained_difference；不得补出退款、费用、房费或 GOP。
- 10/主任务：串行合并收益分析片段、快照签发/回读及构建脚本。运行现有 build:frontend-template 和 build:frontend-entry；后者现已自动同步 revenue-cockpit → revenue-ai → app-main 的两层内容哈希。不要手改 minified 或哈希。

本地 synthetic 验证不证明真实账号、线上数据覆盖、部署或经营效果。
