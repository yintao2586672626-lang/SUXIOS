# OTA 平台适配器与统一指标规范

## 1. 设计目标

美团、携程等平台的页面、字段名称、权限和时间口径不同。宿析OS应实现“平台适配器 + 统一事实层”，而不是复制某个平台的请求路径或页面实现。

适配器只负责：

1. 按统一上下文读取已授权平台数据；
2. 返回平台能力和原始证据摘要；
3. 把平台字段映射为统一指标；
4. 把无法映射或无法验证的字段显式标记出来。

适配器不负责生成全酒店结论、不负责绕过人工验证、不负责保管明文凭证。

## 2. 抽象接口契约

以下是宿析OS自己的能力接口示意，不对应任何外部接口地址。

~~~text
OtaPlatformAdapter
  platformCode() -> PlatformCode
  validateSession(context) -> SessionValidation
  resolveBinding(context) -> BindingValidation
  probeCapabilities(context) -> CapabilityReport
  collect(context) -> CollectionResult
  normalize(rawEvidence, context) -> NormalizationResult
~~~

### 2.1 输入上下文

~~~json
{
  "system_hotel_id": "宿析OS内部酒店引用",
  "platform": "ctrip",
  "poi_ref": "受控的平台门店引用",
  "stat_date": "YYYY-MM-DD",
  "date_range": {
    "from": "YYYY-MM-DD",
    "to": "YYYY-MM-DD"
  },
  "requested_capabilities": ["business"],
  "metric_scope": "OTA_CHANNEL_SCOPE",
  "collection_run_id": "内部任务引用"
}
~~~

### 2.2 输出结果

~~~json
{
  "platform": "ctrip",
  "collection_run_id": "内部任务引用",
  "session_state": "verified",
  "binding_state": "verified",
  "capability_report": {
    "business": "verified",
    "orders": "unverified"
  },
  "raw_evidence_ref": "内部脱敏证据引用",
  "facts": [],
  "quality_state": "available",
  "failure_codes": [],
  "collected_at": "YYYY-MM-DDThh:mm:ss+08:00"
}
~~~

## 3. 能力报告

平台能力应按模块拆分，不使用一个总开关掩盖差异：

| 能力代码 | 说明 | 最小证据 |
| --- | --- | --- |
| business | 经营概况或经营指标 | 目标日期、指标字段、来源引用 |
| orders | 订单聚合或订单状态 | 权限结果、日期口径、脱敏聚合 |
| reviews | 评价聚合 | 评分/数量口径和采集时间 |
| traffic | 曝光、浏览、转化 | 分母、时间窗口和指标来源 |
| inventory | 房型或库存状态 | 房型、日期、状态口径 |
| peer_rank | 平台竞争圈排名 | 竞争圈范围、更新时间和排名口径 |

平台没有提供某项能力时，应返回 capability_unavailable；被拒绝时返回 permission_denied；尚未完成验证时返回 unverified。三者不能混为“暂无数据”。

## 4. 统一事实字段字典

| metric_code | 业务含义 | 类型/单位 | 必须保存的口径 | 缺失原因示例 |
| --- | --- | --- | --- | --- |
| sales_amount | OTA销售或成交金额 | money/元 | 金额类型、含税/服务费、订单状态 | amount_scope_unverified |
| paid_orders | 已支付订单数 | integer/单 | 支付状态和统计窗口 | paid_orders_missing |
| room_nights | OTA间夜 | integer/间夜 | 入住/售卖/完成口径 | room_nights_scope_unverified |
| average_daily_rate | 平均房价 | decimal/元 | 金额分子和间夜分母 | adr_denominator_missing |
| exposure_users | 曝光人数或次数 | integer/人或次 | 平台定义和统计窗口 | exposure_definition_missing |
| browse_users | 浏览人数或次数 | integer/人或次 | 平台定义和统计窗口 | browse_definition_missing |
| conversion_rate | 转化率 | percent/% | 分子、分母和窗口 | conversion_denominator_missing |
| review_score | 平台评分 | decimal/分 | 评分范围和更新时间 | review_score_missing |
| peer_rank | 竞争圈排名 | integer/名次 | 竞争圈集合、城市/品牌范围 | peer_scope_missing |
| room_sale_status | 房型销售状态 | enum | 状态枚举和业务日期 | room_status_missing |

这些名称是宿析OS的统一指标编码，不代表平台原始字段。平台专有字段应放入扩展区域，并保留原始标签的脱敏描述。

## 5. 标准化映射表

每一条映射都需要有版本和证据：

| 字段 | 说明 |
| --- | --- |
| platform | 来源平台 |
| module | 经营、订单、流量、评价、库存或排名 |
| source_label | 平台显示标签的非敏感描述 |
| source_path | 脱敏后的字段路径或语义路径 |
| metric_code | 宿析OS统一指标编码 |
| value_type | number、integer、percent、money、text、boolean |
| unit | 元、单、间夜、人、百分比等 |
| data_as_of | 业务日期或时间窗口 |
| metric_scope | 默认 OTA_CHANNEL_SCOPE |
| quality_state | 字段级质量状态 |
| normalization_version | 映射版本 |
| missing_reason | 缺失或未验证原因 |

如果平台字段的业务含义无法确定，保留为扩展事实或未验证事实，不强行映射成统一指标。

## 6. 计算规则

统一计算只在分子、分母、时间窗口和口径均可验证时执行：

~~~text
average_daily_rate = sales_amount / room_nights
exposure_to_browse_rate = browse_users / exposure_users
browse_to_paid_rate = paid_orders / browse_users
~~~

分母缺失、分母为0、统计窗口不一致或金额口径不一致时，结果为不可计算，并记录对应原因；不能返回0或沿用旧值。

## 7. 合同测试

| 测试 | 输入 | 预期 |
| --- | --- | --- |
| 正常经营响应 | 合成的目标日期经营字段和来源路径 | 生成 available 标准事实 |
| 字段缺失 | 缺少金额但有订单和间夜 | 金额为 null，整体 partial |
| 日期不一致 | 响应日期与查询日期不同 | unverified，不得写当前快照 |
| POI不一致 | 响应门店引用与绑定不一致 | binding_missing 或 poi_mismatch |
| 权限拒绝 | 能力探针拒绝 | 对应能力 permission_denied |
| 结构变化 | 字段路径无法解析 | collection_failed，保留解析版本 |
| 计算分母缺失 | 有分子无分母 | 结果不可计算，不写0 |
| 平台专有字段 | 无统一指标映射 | 保留扩展字段和 normalization_not_defined |

## 8. 适配器实施方案

1. 先定义统一上下文、质量状态和事实结构；
2. 用合成 fixture 验证适配器输入输出，不使用真实账号或客户数据；
3. 每个平台先实现一个只读、单能力适配器；
4. 完成字段证据链后再接入收益分析；
5. 平台扩展字段必须隔离在适配器扩展区；
6. 适配器失败不得由公共层静默改写成成功。

