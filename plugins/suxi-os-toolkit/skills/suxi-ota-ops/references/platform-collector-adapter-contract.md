# 平台采集器公共适配合同

## 定位

携程、美团、订单来了等网站页面和字段不同，但采集目的与证据闭环相同。宿析OS统一公共采集器骨架，各平台只实现适配差异。

```text
任务范围
-> 授权会话与门店身份
-> 最小业务请求
-> 平台字段适配
-> 标准字段事实
-> 质量判断
-> 正式保存与精确回读
-> 时间序列/复盘
-> 推送或下游门禁
```

统一方法不等于把不同网站写成一个万能页面脚本，也不等于把同名数值强行当成相同业务口径。

## 公共层与适配层

| 公共层统一负责 | 平台适配层分别声明 |
| --- | --- |
| `tenant_id`、`system_hotel_id`、用户权限与任务范围 | 平台账号、平台门店 ID/名称及身份响应路径 |
| Profile/BrowserContext 隔离、互斥锁、登录状态 | 允许域名、登录页、页面模块及平台风控状态 |
| 目标业务日、目标入住日、采集时间和日期角色 | 请求日期参数、平台更新窗口和时区细节 |
| 从经营输出反推最少请求 | 端点、方法、Payload、页签和触发动作 |
| 结构化 JSON 优先、DOM 最后兜底 | JSON `source_path`、DOM 选择器及响应 schema |
| 标准事实、缺失状态、派生状态和来源证据 | `metric_key`、平台字段名、单位、枚举和转换规则 |
| 保存、幂等/版本策略、数据库精确回读 | 目标表映射、平台专属明细和内部对账规则 |
| 快照新鲜度、上一条可比证据和复盘引用 | 结算阶段、更新频率及平台特有可比条件 |
| 统一 blocker 与安全边界 | 平台错误码、登录失效和 schema 漂移映射 |

平台之间通常不只是“返回数值不同”，字段路径、单位、日期角色、结算阶段和指标语义也可能不同；这些全部留在适配层，公共层不猜测。

## 公共任务输入

每次运行先构造 `CollectionScope`：

```json
{
  "tenant_id": 1,
  "system_hotel_id": 80,
  "platform": "ctrip | meituan | dingdandao_pms",
  "business_module": "overview | traffic | order | price_inventory | realtime | forward",
  "data_date": "YYYY-MM-DD",
  "target_stay_date": "YYYY-MM-DD or null",
  "date_role": "business_date | capture_date | target_stay_date",
  "source_method": "profile_capture | authorized_api | file_import | manual_entry"
}
```

- 样例中的数字只说明字段形状，不是任何门店事实。
- `data_date`、`target_stay_date` 和 `captured_at` 不得互相替代。
- `platform` 决定适配器；端口、当前标签页或历史记录都不能决定门店身份。

## 平台适配器清单

每个平台至少声明：

```text
adapter_key
allowed_origins
session/profile policy
identity probe and platform-hotel fields
business modules
minimal request plans
request date roles
response schema fingerprints
field mappings: platform field -> metric_key/type/unit/source_path
explicit-zero and missing rules
update/settlement windows
summary/detail reconciliation rules
failure-code mappings
representative success/missing/schema-drift fixtures
```

新增网站时先补适配器清单和代表样例，再接入公共流程；禁止复制一份旧网站采集器后只替换 URL。

## 公共执行阶段

### 1. Scope

- 固定租户、系统门店、平台、平台门店、模块和日期角色。
- 缺少平台门店绑定或多条绑定冲突时停止，不自动选择。

### 2. Session

- 使用当前用户设备上的授权 Profile/BrowserContext。
- 不复制 Cookie、token、localStorage 或 Profile 到任务参数、日志、知识和 Git。
- 登录失效、验证码、短信或人机验证交给用户在原设备完成。

### 3. Identity

- 先调用平台身份端点或读取平台明确身份字段。
- 同时校验系统门店授权与平台门店 ID/名称。
- 身份不一致时禁止解析结果进入保存。

### 4. Request Plan

- 从本次输出反推最少端点。
- 已知合同走隔离会话内直请求；网络捕获只用于首次发现和 schema 漂移。
- 不因平台有更多页面就执行无关全量扫描。

### 5. Normalize

适配器把平台响应转换为标准 `FieldFact`：

```json
{
  "metric_key": "visitor_count",
  "value": 8,
  "value_type": "integer",
  "unit": "people",
  "status": "verified | derived | missing | unverified",
  "source_path": "data.visitorCount",
  "endpoint_id": "platform_endpoint_id",
  "data_date": "YYYY-MM-DD",
  "captured_at": "YYYY-MM-DD HH:mm:ss"
}
```

- 明确的 `0` 保留为真实零值。
- 未返回保持 `missing`，不使用旧值、空数组、默认日期或其他来源补齐。
- 派生值必须引用同一已验证快照中的输入字段并标记 `derived`。
- 同名指标只有在定义、分子分母、单位、日期角色和范围一致时才允许映射到同一 `metric_key`。

### 6. Quality

公共质量门检查：

- 身份是否匹配；
- 目标日期是否由请求与响应共同证明；
- 必需字段是否命中；
- 响应 schema 是否仍匹配；
- 平台专属汇总/明细是否对账；
- 当前结果是实时、已结算、更新中还是缺失。

### 7. Persist And Read Back

- 只走来源现有正式 importer、ETL、controller 或 service。
- 保存后按租户、酒店、平台、模块、日期和采集批次精确回读。
- 只有关键字段、保存数量和来源引用一致时标记 `readback_verified`。
- 一日多次实时快照使用版本/批次策略；汇总只选择最新合格快照，不把多次快照累加。

### 8. Compare And Learn

- 只与同门店、同来源、同 `metric_key`、同单位、同日期角色和已验证质量的快照比较。
- 实时、昨日结算和未来目标日是三条时间线。
- 变化是观察信号，不自动证明原因；动作效果还需要执行证据和后续同口径回读。
- 长期复盘遵循 `suxi-ota-pms-collector-operating-loop` 的 longitudinal evidence contract。

### 9. Deliver

- 页面、报告、AI和企业微信只消费已经保存回读的事实。
- 预览、HTTP 成功、历史成功或登录成功都不等于本次交付完成。
- 正式发送、自动推送和 timer 是独立配置与验收层。

## 标准结果

公共层接收平台适配器结果后输出 `CollectorResult`：

```json
{
  "scope": {},
  "identity_status": "matched | blocked",
  "collection_status": "verified | partial | missing | blocked",
  "quality_status": "verified | partial | unverified | blocked",
  "metrics": [],
  "saved_count": 0,
  "readback_status": "readback_verified | readback_failed | not_attempted",
  "snapshot_ref": "source#id or null",
  "previous_comparable_ref": "source#id or null",
  "blockers": [],
  "sensitive_material_exposed": false
}
```

公共层不能把 `saved_count=0` 自动解释为“平台数据为 0”，也不能因为部分可选字段缺失把已验证核心字段全部丢弃。

## 当前平台差异

| 适配器 | 身份重点 | 主要指标差异 | 日期/更新差异 | 专属对账 |
| --- | --- | --- | --- | --- |
| `ctrip` | Ctrip hotel/node 标识与当前 Profile | 经营、流量、排名、质量、订单、ARI；字段闭合到 endpoint/source path/metric | 实时、日报、目标入住日和渠道页签分别证明 | 字段目录、响应 evidence 和 verifier |
| `meituan` | partner/poi/shop 与美团门店 | 经营卡片、两级流量漏斗、销售、未来 PV/UV/提前订、同行 | 零点更新窗口、今日/昨日/未来页签分别证明 | 业务卡片、漏斗、曝光来源和未来页签不串型 |
| `dingdandao_pms` | provider hotel ID/名称与系统酒店 | 全店实时经营、房费明细、区域、单项趋势、远期房态 | 营业日、当前采集时点和未来入住日分层 | 汇总与房费/房型明细、容量和来源指纹 |

平台新增指标时只修改对应适配器字段表、fixture 和测试；除非公共结果合同本身不足，否则不改公共流程。

## 统一失败状态

公共 blocker 至少包括：

- `binding_missing`
- `binding_conflict`
- `not_logged_in`
- `session_expired`
- `human_verification_required`
- `hotel_identity_mismatch`
- `target_date_unverified`
- `endpoint_not_hit`
- `schema_changed`
- `field_missing`
- `pending_source_update`
- `response_partial`
- `save_failed`
- `readback_mismatch`
- `resource_busy`

适配器负责把平台错误映射到这些状态，同时保留脱敏的平台原始错误类别。任何失败都不能转成 `0`、空数组、旧数据或成功。

## 验收

共同方法完成需要同时证明：

1. 三个平台技能均引用本合同。
2. 每个平台保留自己的身份、端点、字段、单位、日期和对账规则。
3. 代表成功、缺字段、身份不匹配和 schema 漂移样例可重放。
4. 保存与精确回读在公共结果中可见。
5. 不同来源缺失不互相补值。
6. 项目权威技能与插件分发副本的本合同一致。
