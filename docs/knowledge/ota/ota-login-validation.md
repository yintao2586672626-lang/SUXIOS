# OTA 登录与可用性验证规范

## 1. 文档定位

- 文档类型：可验证能力规范。
- 作用域：默认仅为 OTA_CHANNEL_SCOPE。
- 目标：判断一个已授权的平台数据源是否真的可以为指定酒店、POI、日期和业务能力提供可用数据。
- 来源边界：只提炼验证顺序、状态和证据要求，不复制外部系统的代码、路由、接口地址、页面、品牌或凭证。
- 非目标：不保存或接收明文密码、Cookie、令牌、Authorization、完整浏览器会话或客户隐私。

“登录成功”不是“数据可用”。本规范要求把身份、绑定、权限、目标数据接口和最终快照分成独立检查点。

## 2. 验证对象

| 检查对象 | 要回答的问题 | 通过条件 | 失败状态或原因 |
| --- | --- | --- | --- |
| 宿析OS系统身份 | 当前用户是否有权操作该酒店？ | 系统用户、酒店租户和权限上下文有效 | system_auth_required、system_permission_denied |
| OTA数据源绑定 | 系统酒店是否配置了目标平台数据源？ | 存在启用中的平台绑定记录 | binding_missing |
| 平台会话 | 平台会话是否仍有效？ | 能完成最小会话探针，且没有重新登录或人工验证要求 | session_expired、session_invalid、manual_verification_required |
| 酒店绑定 | 当前会话访问的酒店是否是目标系统酒店？ | 平台酒店引用与系统绑定记录一致 | hotel_binding_mismatch |
| POI绑定 | 当前平台门店/POI是否是目标门店？ | POI引用经过系统绑定或人工确认 | poi_missing、poi_mismatch |
| 能力权限 | 是否有目标模块的读取权限？ | 每项必需能力都有明确授权结果 | permission_denied、capability_unavailable |
| 目标数据探针 | 指定日期的数据接口或授权页面是否可用？ | 返回可识别的数据结果、字段证据和日期上下文 | target_data_unavailable、platform_response_invalid |
| 数据闭环 | 结果是否已标准化保存并可追溯？ | 有快照、来源引用、采集时间和质量状态 | snapshot_not_saved、evidence_missing |

## 3. 验证顺序与状态机

~~~text
SYSTEM_AUTH_REQUIRED
        |
        v
SYSTEM_AUTHENTICATED
        |
        v
SOURCE_BOUND
        |
        +--> BINDING_MISSING
        |
        v
SESSION_VERIFIED
        |
        +--> SESSION_EXPIRED / MANUAL_VERIFICATION_REQUIRED
        |
        v
HOTEL_AND_POI_VERIFIED
        |
        +--> HOTEL_BINDING_MISMATCH / POI_MISMATCH
        |
        v
CAPABILITY_VERIFIED
        |
        +--> PERMISSION_DENIED
        |
        v
TARGET_DATA_PROBED
        |
        +--> TARGET_DATA_UNAVAILABLE / COLLECTION_FAILED
        |
        v
READY_FOR_COLLECTION
        |
        v
SNAPSHOT_VERIFIED
~~~

每个节点都必须保存检查时间、结果、证据引用和失败原因。不能因为前一个节点成功就跳过后续节点，也不能用历史快照把失败节点标成成功。

## 4. 会话与数据可用性的区别

| 场景 | 会话判断 | 数据判断 | 对下游的允许 |
| --- | --- | --- | --- |
| 会话有效、酒店和POI正确、目标日期数据可探测 | 通过 | 通过 | 可进入采集和 OTA 分析 |
| 会话有效、但酒店未绑定 | 通过 | 不通过 | 只提示绑定问题，不采集 |
| 会话有效、但POI不匹配 | 通过 | 不通过 | 禁止写入目标酒店快照 |
| 会话有效、但订单/经营能力无权限 | 通过 | 不通过 | 只允许有权限的能力，其他能力标记 permission_denied |
| 会话有效、接口返回空结果且没有日期证据 | 通过 | 未验证 | 标记 unverified，不能当作零值 |
| 会话过期、页面仍可显示旧内容 | 不通过 | 不可验证 | 标记 permission_denied 或对应会话失败原因，旧内容只能作为历史参考 |
| 会话有效、采集任务超时或保存失败 | 通过 | 不通过 | 标记 collection_failed，保留失败证据 |

## 5. 最小接口契约

宿析OS应按自己的架构命名接口。以下是能力契约，不是外部平台的接口地址。

### 5.1 验证请求

~~~json
{
  "system_hotel_id": "宿析OS内部酒店引用",
  "platform": "meituan",
  "poi_ref": "受控的平台门店引用",
  "requested_capabilities": ["business", "orders", "reviews"],
  "target_date": "YYYY-MM-DD",
  "verification_run_id": "内部验证任务引用"
}
~~~

### 5.2 验证响应

~~~json
{
  "verification_run_id": "内部验证任务引用",
  "session_state": "verified",
  "binding_state": "verified",
  "poi_state": "verified",
  "capability_states": {
    "business": "verified",
    "orders": "permission_denied",
    "reviews": "unverified"
  },
  "target_data_state": "available",
  "quality_state": "available",
  "checked_at": "YYYY-MM-DDThh:mm:ss+08:00",
  "evidence_refs": ["内部脱敏证据引用"],
  "failure_codes": []
}
~~~

禁止在请求或响应中出现密码、Cookie、令牌、Authorization、完整请求头、完整外部URL或客户身份字段。

## 6. 需要保存的验证字段

| 字段 | 含义 | 保存要求 |
| --- | --- | --- |
| verification_run_id | 一次验证任务的内部引用 | 可追踪、可重放摘要 |
| system_hotel_id | 宿析OS酒店引用 | 必填 |
| platform | 平台代码 | 使用枚举，不使用页面品牌文案 |
| poi_ref | 受控的平台门店引用 | 不保存凭证；按最小权限保护 |
| session_state | 会话验证结果 | 必须与数据状态分开 |
| binding_state | 酒店绑定结果 | 必须记录失败原因 |
| poi_state | POI匹配结果 | 不允许不匹配时写入快照 |
| capability_states | 每项业务能力的权限结果 | 不用一个总成功值覆盖缺失能力 |
| target_date | 被验证的业务日期 | 必须参与证据判断 |
| checked_at | 验证完成时间 | 用于追溯和过期判断 |
| evidence_refs | 脱敏证据引用 | 只指向内部证据，不保存敏感原文 |
| failure_codes | 结构化失败原因 | 禁止只保存“同步失败” |

## 7. 验收用例

| 用例 | 前置条件 | 预期结果 | 禁止行为 |
| --- | --- | --- | --- |
| 会话有效但无酒店绑定 | 平台会话探针通过 | binding_missing | 显示“数据可用” |
| 酒店绑定正确但POI错误 | POI与系统绑定不一致 | poi_mismatch | 将数据写入目标酒店 |
| 会话有效但经营能力无权限 | 经营能力探针被拒绝 | 对应能力为 permission_denied | 用订单或旧数据补经营指标 |
| 登录成功但目标日期无数据证据 | 只返回空结果 | unverified 或明确的目标日期失败原因 | 写入0或空数组表示成功 |
| 采集响应成功但快照保存失败 | 保存操作报错 | snapshot_not_saved，整体不可用 | 标记采集闭环完成 |
| 目标日期可用 | 所需字段、日期、来源都验证通过 | available | 隐藏来源和质量状态 |

## 8. 实现边界

建议先实现只读验证和状态展示，再实现采集。验证模块不得直接修改平台页面、执行绕过人工验证的操作或长期托管平台凭证。浏览器会话、人工登录和人工验证只能作为受控的授权输入，系统最终只保存验证结果、受控引用和脱敏证据。

