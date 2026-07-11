# OTA 数据质量与可信状态规范

## 1. 质量目标

数据质量不是一个装饰性标签，而是每个指标能否进入收益分析、AI诊断和运营动作的门槛。质量状态必须来自可复核证据，不能由页面是否加载、数组是否非空或接口是否返回成功推断。

默认作用域为 OTA_CHANNEL_SCOPE。没有 PMS、财务或全渠道完整覆盖时，任何 OTA 指标都不能升级为 WHOLE_HOTEL_SCOPE。

## 2. 规范状态枚举

| quality_state | 定义 | 可用范围 | 必须显示的原因 |
| --- | --- | --- | --- |
| available | 来源、酒店、POI、日期、字段和时间均已验证，必需指标可用 | 可进入对应 OTA 分析 | 来源、采集时间、数据日期 |
| partial | 部分字段已验证，至少一个必需字段缺失、异常或未完成 | 只能分析已验证字段 | 缺失字段、缺失原因、覆盖范围 |
| stale | 最近成功数据早于当前目标或超过新鲜度策略 | 只能作历史参考 | 数据实际日期、最近采集时间 |
| unverified | 数据存在，但来源、日期、POI、口径或完整性未充分验证 | 不得生成强结论 | 未验证项和下一步核验 |
| binding_missing | 系统酒店、平台门店或POI绑定不存在或未确认 | 不可进入目标酒店分析 | 缺失的绑定层级 |
| permission_denied | 会话或目标业务能力没有读取权限 | 该能力不可用 | 被拒绝的能力和授权状态 |
| collection_failed | 采集、解析、保存或证据闭环失败 | 不可当作当前数据 | 结构化失败原因和任务引用 |

以下状态不是质量状态，必须单独保存：任务是否运行、会话是否验证、绑定是否启用、页面是否打开、AI是否生成建议。

## 3. 状态判定规则

判定顺序：

~~~text
绑定检查
-> 权限/会话检查
-> 采集完成检查
-> 来源与日期检查
-> 字段完整性检查
-> 新鲜度检查
-> 生成 primary_quality_state
~~~

建议的主状态优先级：

~~~text
binding_missing
> permission_denied
> collection_failed
> unverified
> stale
> partial
> available
~~~

如果多个问题同时存在，主状态使用优先级最高者，其他问题写入 quality_flags。例如：POI未绑定且旧快照存在时，主状态必须是 binding_missing，不能显示为 stale 或 available。

## 4. 字段级质量合同

每个标准化指标都应保存：

~~~json
{
  "metric_code": "paid_orders",
  "value": 12,
  "unit": "order",
  "metric_scope": "OTA_CHANNEL_SCOPE",
  "source_ref": "内部脱敏证据引用",
  "source_path": "抽象字段路径",
  "data_as_of": "YYYY-MM-DD",
  "collected_at": "YYYY-MM-DDThh:mm:ss+08:00",
  "quality_state": "available",
  "quality_flags": [],
  "missing_reason": null
}
~~~

允许的缺失原因示例：

- source_not_returned
- date_not_confirmed
- poi_not_confirmed
- permission_denied
- response_parse_failed
- normalization_not_defined
- snapshot_not_saved
- freshness_expired

缺失值必须使用 null 或明确的缺失结构，不能用0、空字符串、空数组或最近一次值代替。

## 5. 新鲜度与目标日期

新鲜度判断必须同时使用：

- 目标业务日期；
- 数据实际对应日期或时间 data_as_of；
- 采集完成时间 collected_at；
- 当前平台或项目定义的 freshness_policy_id；
- 是否已经过目标日期的可采集时间窗口。

没有可验证的新鲜度策略时，状态应为 unverified，不应自行假设“24小时内一定新鲜”。对于昨日数据的最小闭环，只有在 data_as_of 明确等于昨日且采集时间符合项目策略时，才允许标记 available。

## 6. 下游使用门槛

| 状态 | 收益分析 | AI诊断 | 运营动作 | 全酒店结论 |
| --- | --- | --- | --- | --- |
| available | 可用，但标注 OTA 范围 | 可生成有证据的 OTA 诊断 | 可提出可追溯动作 | 仍不可仅凭OTA升级 |
| partial | 仅使用已验证字段 | 只能生成局部、带缺口说明的诊断 | 需要人工确认 | 禁止 |
| stale | 历史参考 | 不得作为当前事实 | 不得直接触发当前动作 | 禁止 |
| unverified | 仅展示待核验数据 | 禁止强结论 | 只能提出核验动作 | 禁止 |
| binding_missing | 不可用 | 不可用 | 只能提出绑定动作 | 禁止 |
| permission_denied | 该能力不可用 | 不可用 | 只能提出授权/人工处理动作 | 禁止 |
| collection_failed | 不可用 | 不可用 | 只能提出采集修复动作 | 禁止 |

## 7. 禁止的“看起来成功”

以下行为一律判定为质量违规：

1. 采集失败时返回空数组并显示“今日无数据”；
2. 缺失金额时写入0，再计算ADR或转化率；
3. 目标日期没有行时复制历史行；
4. 只验证登录状态，不验证酒店、POI和目标数据；
5. 只验证接口HTTP成功，不验证业务日期和字段；
6. 将平台收入、间夜、排名包装成全酒店事实；
7. 将AI建议文案作为数据可用性的证明；
8. 将人工导入的未核验材料标成当前可用；
9. 使用旧快照覆盖本次失败而不展示 stale；
10. 把部分平台成功描述成全平台成功。

## 8. 质量测试用例

| 用例 | 输入状态 | 预期主状态 | 预期下游 |
| --- | --- | --- | --- |
| 全部证据齐全 | 绑定、权限、日期、字段和来源均通过 | available | 可做 OTA 分析 |
| 只缺一个非核心字段 | 核心字段通过，扩展字段缺失 | partial | 仅使用已验证字段 |
| 只有上周快照 | 目标日期无当前数据 | stale | 仅历史参考 |
| 来源引用缺失 | 有数值但无法追溯 | unverified | 禁止强结论 |
| 无绑定记录 | 酒店或POI未配置 | binding_missing | 只显示绑定动作 |
| 权限被拒 | 目标能力不可读 | permission_denied | 只显示授权问题 |
| 任务超时或保存失败 | 无完整采集闭环 | collection_failed | 只显示采集失败 |
| 采集返回空结果 | 无“无数据”业务证据 | unverified 或 collection_failed | 不显示为0 |

## 9. 质量状态接口最小输出

~~~json
{
  "primary_quality_state": "partial",
  "quality_flags": ["sales_amount_missing", "freshness_unconfirmed"],
  "available_metric_codes": ["paid_orders", "room_nights"],
  "missing_metric_codes": ["sales_amount"],
  "data_as_of": "YYYY-MM-DD",
  "collected_at": "YYYY-MM-DDThh:mm:ss+08:00",
  "metric_scope": "OTA_CHANNEL_SCOPE",
  "evidence_refs": ["内部脱敏证据引用"],
  "next_verification_action": "补充金额字段来源并复核目标日期"
}
~~~

### 9.1 下游门禁传递

收益分析、AI诊断和运营动作只能接收由采集/验证层生成的脱敏摘要，不接收原始响应或凭证。内部契约可使用 `collection_quality`：

~~~json
{
  "collection_quality": {
    "primary_quality_state": "available",
    "quality_flags": [],
    "metric_scope": "ota_channel"
  }
}
~~~

- `available`：允许进入对应 OTA 渠道分析，仍不得升级为全酒店结论；
- `partial`：仅允许已验证指标，AI 和运营动作必须保持人工复核；
- `stale`、`unverified`、`binding_missing`、`permission_denied`、`collection_failed`：阻断当前收益、AI和运营决策，保留原因和下一步核验动作；
- 显式传入未知状态或非 `ota_channel` 范围时，必须阻断，不能按可用数据处理；
- 旧数据暂未携带该摘要时保持兼容，并显式保留 `provided=false`；缺少基础质量证据时仍使用 `data_quality_missing`，不能把缺失摘要包装为 `available`。
- 当下游只有 `p0_downstream_gate`（P0 OTA 字段闭环门禁）时，可将其投影为同一摘要：门禁 `ready` 对应 `available`；被阻断时，绑定、权限、采集失败和过期原因优先映射为对应状态，其余缺口映射为 `unverified`；只传递缺口代码，不传递原始响应或凭证。

### 9.2 任务级验证快照

每次采集任务可在任务统计中保存一份 `collection_quality` 脱敏快照，用于追溯该任务是否形成了目标日期、字段事实和保存闭环。最小结构如下：

~~~json
{
  "primary_quality_state": "available",
  "quality_flags": [],
  "metric_scope": "ota_channel",
  "evidence_scope": "sync_task",
  "target_date": "YYYY-MM-DD",
  "data_as_of": "YYYY-MM-DD",
  "collected_at": "YYYY-MM-DD HH:mm:ss",
  "evidence": {
    "task_status": "success",
    "p0_status": "ready",
    "target_date_rows": 1,
    "target_date_traffic_rows": 1,
    "field_fact_status": "ready",
    "saved_count": 1
  },
  "next_action": ""
}
~~~

- 快照只能保存状态、计数、日期和缺口代码；不得保存原始响应、账号密码、Cookie、令牌、接口地址或客户隐私数据。
- 同一任务统计可额外保存 `sync_diagnostics.capability_states`，固定使用 `business`、`orders`、`reviews` 三项能力和 `verified`、`permission_denied`、`capability_unavailable`、`unverified`、`collection_failed` 枚举值；它只说明该任务的能力证据，不能替代当前平台登录、绑定或新鲜度验证。
- `available` 只表示该同步任务已形成可验证的 OTA 任务证据，不替代平台当前登录、酒店/POI 绑定或跨任务新鲜度检查。
- 人工导入即使写入成功，也应先标记为 `unverified`，直到来源、绑定、日期和字段口径完成独立复核。
- 平台状态页面可以展示该任务级快照，但收益、AI 和运营下游仍必须使用实时质量判定与 P0 门禁；不得把任务快照升级为全酒店结论。
