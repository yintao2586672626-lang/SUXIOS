# OTA 数据采集与标准化快照规范

## 1. 文档定位

- 文档类型：可验证能力规范。
- 作用域：默认仅为 OTA_CHANNEL_SCOPE。
- 目标：把平台事实采集成可追溯、可重放、可校验的标准化快照。
- 非目标：不复制外部系统的采集脚本、页面结构、接口地址、路由或品牌；不把浏览器临时响应直接当作宿析OS事实。

采集链路必须满足：

~~~text
已授权数据源
-> 统一查询上下文
-> 平台采集
-> 原始证据脱敏保存
-> 标准化字段
-> 质量评估
-> 快照保存
-> 下游消费
~~~

## 2. 采集方式

| collection_mode | 说明 | 可作为事实来源的条件 |
| --- | --- | --- |
| browser_profile | 用户在受控浏览器会话中完成平台登录，系统使用已验证会话读取数据 | 会话、酒店、POI和目标能力均已验证 |
| scheduled_task | 按计划任务触发已配置的数据源 | 任务执行前重新检查状态，失败必须落原因 |
| manual_refresh | 用户主动触发一次目标日期刷新 | 显示任务状态和结果，不得静默失败 |
| manual_import | 导入用户提供的脱敏结构化材料 | 保存导入来源、导入时间和验证等级 |
| user_provided_unverified | 用户提供但尚未核验的材料 | 只能作为未验证参考，不能进入可信快照 |

浏览器辅助、人工导入和定时任务是采集方式，不是质量状态。任何方式都必须经过统一标准化和质量检查。

## 3. 统一查询上下文

每次采集都必须显式携带以下上下文：

~~~json
{
  "system_hotel_id": "宿析OS内部酒店引用",
  "platform": "meituan",
  "poi_ref": "受控的平台门店引用",
  "stat_date": "YYYY-MM-DD",
  "date_range": {
    "from": "YYYY-MM-DD",
    "to": "YYYY-MM-DD"
  },
  "metric_scope": "OTA_CHANNEL_SCOPE",
  "requested_metrics": ["sales_amount", "paid_orders", "room_nights"],
  "collection_mode": "browser_profile",
  "collection_run_id": "内部采集任务引用"
}
~~~

禁止从页面当前选择器、上一次任务或全局变量隐式推断酒店、POI和日期。上下文切换时必须重新查询，防止串酒店、串POI和串日期。

## 4. 采集结果分层

### 4.1 原始证据层

原始证据层只用于追溯和重新标准化，至少记录：

- 平台和模块的抽象名称；
- 业务日期和采集时间；
- 脱敏的来源引用；
- 结构化响应的最小必要字段或人工导入摘要；
- 解析版本和失败原因。

完整请求头、凭证、Cookie、令牌、客户手机号、姓名、证件、完整订单联系人和未经脱敏的原始页面不得进入知识材料、普通日志或导出包。

### 4.2 标准化事实层

统一事实应至少包含：

~~~json
{
  "system_hotel_id": "内部酒店引用",
  "platform": "meituan",
  "poi_ref": "受控门店引用",
  "stat_date": "YYYY-MM-DD",
  "metric_code": "paid_orders",
  "metric_value": 12,
  "value_type": "integer",
  "unit": "order",
  "metric_scope": "OTA_CHANNEL_SCOPE",
  "source_type": "platform_response",
  "source_ref": "内部脱敏证据引用",
  "collected_at": "YYYY-MM-DDThh:mm:ss+08:00",
  "data_as_of": "YYYY-MM-DD",
  "quality_state": "available",
  "failure_code": null,
  "normalization_version": "v1",
  "collection_run_id": "内部采集任务引用"
}
~~~

### 4.3 快照层

快照是供收益分析、AI诊断和运营复核消费的稳定结果，不是临时页面状态。推荐逻辑唯一键：

~~~text
system_hotel_id
+ platform
+ poi_ref
+ stat_date
+ metric_code
+ normalization_version
~~~

同一唯一键重复采集时应形成新的采集运行记录，并按明确规则更新当前快照；不得静默覆盖来源、时间或质量状态。

### 4.4 逻辑数据表设计（规范层）

以下为宿析OS重新实现时的逻辑实体，不代表现有表名、迁移脚本或外部系统结构。实现时可按项目现有命名和租户模型落库，但不得省略关键追溯关系。

| 逻辑实体 | 关键字段 | 职责 | 安全边界 |
| --- | --- | --- | --- |
| ota_data_sources | source_id、system_hotel_id、platform、ingestion_method、enabled、credential_present | 描述可用数据源和采集方式 | 只保存凭证存在性或受控引用，不保存明文凭证 |
| ota_hotel_platform_bindings | binding_id、system_hotel_id、platform、poi_ref、binding_state、verified_at | 维护系统酒店与平台店铺/POI绑定 | POI不匹配时不得写入目标快照 |
| ota_login_validations | validation_id、source_id、session_state、capability_states、validated_at、failure_codes | 保存会话、权限和目标数据探针结果 | 不保存Cookie、令牌或完整请求材料 |
| ota_collection_runs | run_id、source_id、target_date、trigger_mode、status、started_at、finished_at、failure_codes | 记录一次手动、定时、浏览器会话或人工导入任务 | 任务消息只能使用结构化原因码 |
| ota_evidence_refs | evidence_id、run_id、source_type、source_ref、payload_hash、collected_at、retention_policy | 保存脱敏证据引用和重放所需元数据 | 不保存完整响应、访问地址或客户隐私 |
| ota_metric_snapshots | snapshot_id、run_id、system_hotel_id、platform、poi_ref、stat_date、metric_code、metric_value、quality_state | 保存标准化 OTA 事实和当前快照 | 默认 `OTA_CHANNEL_SCOPE`，不升级为全酒店事实 |
| ota_quality_assessments | assessment_id、run_id、primary_quality_state、quality_flags、evidence_summary、next_action | 保存质量判定和下游门禁依据 | 只记录状态、计数、日期和缺口代码 |
| ota_operation_actions | action_id、diagnosis_id、action_type、owner、approval_state、evidence_refs | 将诊断转为可审计运营动作 | AI只可提出建议，不能替代审批 |
| ota_action_reviews | review_id、action_id、review_date、outcome_metrics、review_state | 保存执行结果和复核结论 | 必须保留原始质量状态与范围标签 |

关系要求：一个数据源可对应多次登录验证和采集任务；一次任务可产生多条证据引用、指标快照和一份质量评估；一个运营动作必须指回诊断、事实快照和质量评估；复核不得覆盖原始快照。

## 5. 字段字典

| 字段 | 类型 | 说明 | 缺失处理 |
| --- | --- | --- | --- |
| system_hotel_id | string | 宿析OS内部酒店引用 | 缺失则 binding_missing |
| platform | enum | 目标平台代码 | 不识别则 collection_failed |
| poi_ref | string | 受控的平台POI引用 | 缺失或不匹配则禁止入库 |
| stat_date | date | 指标所属业务日期 | 缺失则 unverified |
| metric_code | enum | 统一指标编码 | 未映射则保留扩展字段，不进入统一指标 |
| metric_value | number/string/null | 标准化值 | 不可确认时为 null，并记录缺失原因 |
| value_type | enum | integer、decimal、percent、money、text、boolean | 类型不明则 unverified |
| unit | enum | 单、间夜、元、人、百分比等 | 口径不明不得计算 |
| metric_scope | enum | OTA_CHANNEL_SCOPE 或 WHOLE_HOTEL_SCOPE | OTA采集默认前者 |
| source_type | enum | 平台响应、浏览器可见证据、人工导入等 | 必填 |
| source_ref | string | 脱敏证据引用 | 缺失则不可验证 |
| collected_at | datetime | 采集完成时间 | 必填 |
| data_as_of | date/datetime | 数据实际对应时间 | 与目标日期不一致时标记质量问题 |
| quality_state | enum | 统一质量状态 | 必填 |
| failure_code | string/null | 结构化缺失或失败原因 | 失败时必填 |
| normalization_version | string | 字段转换版本 | 用于回放和兼容 |
| collection_run_id | string | 采集任务引用 | 必填 |

建议的最小指标编码包括 sales_amount、paid_orders、room_nights、average_daily_rate、exposure_users、browse_users 和 conversion_rate。平台没有真实证据的指标必须保持缺失，不得用相近字段推断。

## 6. 口径保存要求

每个可计算指标还必须记录：

- 金额口径：订单金额、支付金额、结算金额或其他；
- 订单口径：创建、支付、入住、完成或取消；
- 间夜口径：入住间夜、售卖间夜或库存间夜；
- 时间口径：平台业务日、系统时区和采集时间；
- 分母与比较基线：分母字段、同行集合、上一周期或其他基线；
- 转换版本：原始字段到统一指标的映射版本。

如果任一口径缺失，指标可以保存为未验证事实，但不得进入“可执行结论”。

## 7. 最小闭环验证规范

仅定义，不执行真实采集：

~~~text
验证平台会话
-> 验证系统酒店与平台绑定
-> 验证目标POI
-> 探测昨日美团经营数据
-> 生成一条标准化快照
-> 写入质量状态、来源引用和采集时间
~~~

验收必须能回答：

1. 会话是否有效；
2. 酒店是否绑定；
3. POI是否正确；
4. 数据是否对应昨日且满足新鲜度策略；
5. 失败原因是什么；
6. 快照来自哪次采集、哪类证据；
7. 快照是否明确标记为 OTA_CHANNEL_SCOPE。

## 8. 失败场景

| 场景 | 处理 |
| --- | --- |
| 会话过期 | 停止采集，记录 session_expired，不读取旧页面冒充成功 |
| 酒店或POI未绑定 | 记录 binding_missing 或 poi_missing，不写目标快照 |
| 目标能力无权限 | 记录 permission_denied，只允许其他已授权能力继续 |
| 页面可见但目标数据未返回 | 记录 target_data_unavailable，不写零值 |
| 响应结构变化 | 记录 platform_response_invalid，保留解析版本和证据引用 |
| 部分字段成功 | 写入可确认字段，整体标记 partial，缺失字段为 null |
| 采集成功但保存失败 | 标记 collection_failed 或 snapshot_not_saved，不可进入下游 |
| 只有历史快照 | 标记 stale，只能作为历史参考 |
