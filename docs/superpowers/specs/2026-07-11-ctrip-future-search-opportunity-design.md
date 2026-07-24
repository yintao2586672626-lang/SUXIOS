# 携程未来30天搜索机会设计

## 目标

在现有“携程 eBooking 数据基座 → 流量数据”内增加一个统一的“携程未来30天搜索机会”功能。用户一次获取即可处理累计/昨日、本店/竞争圈四组数据，并在同一页面看到趋势、差距、机会分类和可追溯状态。

## 数据边界

- 数据仅代表携程 OTA 渠道，不代表全酒店需求、入住率、营收或投资结论。
- 来源接口为 `querySearchFlowDetails`。
- `dataType=0` 表示我的酒店，`dataType=3` 表示竞争圈平均。
- `searchType=0` 表示累计搜索数据，`searchType=1` 表示昨日搜索数据。
- `effectDateList` 是未来入住日期；采集日期与未来入住日期分开保存。
- `orderDataList=null` 保持 `field_missing`，不得转换为 0。
- Cookie、Authorization、`spiderkey` 和动态签名不得写入代码、日志、报告或数据库。

## 采集设计

功能保留两条采集路径，但用户只看到一个获取动作：

1. Cookie API 直查：支持手动获取及日常定时任务调用。preferred 核心预设一次生成四个 POST 请求。
2. 浏览器 Profile：作为 preferred 半自动采集路径，打开流量页后触发“累计搜索数据”和“昨日搜索数据”，监听四组真实响应。

两条路径共用同一解析器、标准行、入库模型、查询接口和 UI。采集来源只保存在 `ingestion_method`，用于审计和故障定位。

Cookie 直查若被携程要求动态签名，返回 `request_signature_required`，不得误报为空数据；用户可继续使用 Profile 路径完成同一功能。

## 字段与存储

每个响应按数组下标将以下字段对齐为 30 个目标日期事实：

| 字段 | metric_key | 类型 | 单位 |
|---|---|---|---|
| 未来入住日期 | `target_date` | date | 日 |
| 详情页浏览量 | `future_search_pv` | integer | 次 |
| 详情页访客量 | `future_search_uv` | integer | 人 |
| 订单页转化率 | `future_search_conversion_rate` | decimal | % |

每个目标日期、搜索口径和对比范围生成一条 `online_daily_data` 标准行：

- `data_date`：采集日期。
- `dimension`：包含 endpoint、`target_date`、`search_window` 和 `compare_scope`，用于幂等更新。
- `compare_type`：`self` 或 `competitor`。
- `raw_data.metrics`：PV、UV、转化率。
- `raw_data.dimension_values`：未来日期、累计/昨日、本店/竞争圈。
- `raw_data.missing_fields`：保留订单量等真实缺失字段。
- `raw_data.request_shape`：只保留 `platform`、`dataType`、`searchType`、`spiderVersion`。

真实 0 是有效事实，必须入库；数组长度不一致、日期不可解析或请求口径缺失时标记 `schema_mismatch`，不静默补齐。

## 页面设计

不新增顶部导航。在现有“流量数据”板块中加入“携程未来30天搜索机会”，页面顺序为：

1. 搜索需求概览：累计/昨日 PV、UV，本店与竞圈差距，转化率差值，采集时间和质量状态。
2. 未来30天趋势：累计/昨日与 PV/UV/转化率切换，本店与竞争圈同图对比。
3. 日期机会诊断：按目标日期显示差距及四象限经营类型。
4. 经营动作：只基于已有携程证据输出“拓流机会、转化修复、双低预警、优势保持”；缺价格、房态、订单证据时仅提示复核，不生成确定定价或营收结论。

## 衍生指标

- 流量差距率：`(本店UV - 竞圈UV) / 竞圈UV`。
- 浏览强度：`PV / UV`，UV 为 0 时保持不可计算。
- 转化差距：`本店转化率 - 竞圈转化率`，单位为百分点。
- 昨日贡献占比：`昨日UV / 累计UV`，累计 UV 为 0 时保持不可计算。
- 竞争追赶空间：`max(竞圈UV - 本店UV, 0)`。
- 高热日期：按当前30天竞圈 UV 的真实排序识别，不使用虚构行业阈值。

四象限规则：

- 拓流机会：本店 UV 低于竞圈，且本店转化率不低于竞圈。
- 转化修复：本店 UV 不低于竞圈，且本店转化率低于竞圈。
- 双低预警：本店 UV 和转化率都低于竞圈。
- 优势保持：本店 UV 和转化率都不低于竞圈。

## 失败状态

- `not_collected`：本轮未命中接口。
- `request_signature_required`：Cookie 直查需要动态签名。
- `auth_failed`：Cookie/Profile 失效。
- `schema_mismatch`：数组长度或结构不符合契约。
- `field_missing`：接口明确返回 null 或缺字段。
- `partial`：四组数据未全部到齐。
- `ready`：四组数据均已解析并可回显。

## 验收

1. preferred Cookie 预设包含四组请求，单次获取统一执行。
2. preferred Profile 采集触发累计和昨日并解析四组响应。
3. 30个日期按采集日、未来日期、累计/昨日、本店/竞圈正确入库。
4. `spiderkey` 等敏感字段不进入输出和数据库。
5. 流量数据页可查看趋势、衍生指标、机会分类和真实失败状态。
6. 解析、跨年日期、真实0、null、敏感字段清理、查询接口和页面展示均有聚焦验证。
