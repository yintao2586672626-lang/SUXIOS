# OTA 诊断与运营闭环规范

## 1. 目标闭环

宿析OS要学习的是可验证的运行模式：

~~~text
登录验证
-> 酒店/POI绑定验证
-> 数据可用性验证
-> OTA事实采集
-> 数据质量检查
-> 收益分析
-> AI诊断
-> 运营动作
-> 执行复核
~~~

每一步都有自己的输入、输出、证据和失败状态。任何一步失败，都必须阻断或收窄下一步的结论范围。

### 1.1 模块边界

| 模块 | 输入 | 输出 | 明确不负责 |
| --- | --- | --- | --- |
| 登录与权限验证 | 受控会话引用、系统酒店、平台、目标能力 | 会话、绑定、POI、能力和数据探针状态 | 保管明文密码或绕过人工验证 |
| 平台适配器 | 统一查询上下文和已授权采集方式 | 脱敏证据引用、原始字段映射、标准化候选值 | 复制平台页面、路由或接口地址 |
| 采集与快照 | 适配器结果、日期、来源、解析版本 | 采集任务、证据引用、标准化 OTA 快照 | 用空值或旧数据伪装本次成功 |
| 质量与门禁 | 验证结果、字段事实、日期和采集结果 | 七类质量状态、缺口代码、下游准入结论 | 将 `unverified` 或 `stale` 视为可决策事实 |
| 收益与诊断 | 已准入的 OTA 事实及其范围 | 渠道收益分析、可追溯 AI 诊断 | 仅凭OTA生成全酒店结论 |
| 运营动作与复核 | 诊断、审批、执行证据、复核指标 | 动作记录、执行状态、结果复核 | 自动替代人工审批或改写历史事实 |
| 隐私与审计 | 各模块输出 | 脱敏投影、访问审计、保留策略 | 输出凭证、完整响应或客户隐私 |

模块之间只传递脱敏摘要、结构化状态和受控证据引用；下游如需查看事实，必须通过权限校验后按最小字段读取。

## 2. 状态机

~~~text
NOT_VERIFIED
  -> AUTHENTICATED
  -> BOUND
  -> POI_VERIFIED
  -> DATA_PROBED
  -> SNAPSHOT_SAVED
  -> QUALITY_ASSESSED
  -> DIAGNOSIS_READY
  -> ACTION_PROPOSED
  -> ACTION_EXECUTED
  -> OUTCOME_REVIEWED

AUTHENTICATED  -> SESSION_EXPIRED
AUTHENTICATED  -> BINDING_MISSING
POI_VERIFIED   -> PERMISSION_DENIED
DATA_PROBED    -> COLLECTION_FAILED
SNAPSHOT_SAVED -> UNVERIFIED / STALE / PARTIAL
~~~

状态转换必须由验证结果触发，不得由按钮点击、页面加载或AI文本生成触发。

## 3. 诊断输出结构

每条诊断至少包含：

~~~json
{
  "diagnosis_id": "内部诊断引用",
  "metric_scope": "OTA_CHANNEL_SCOPE",
  "platform": "meituan",
  "system_hotel_id": "内部酒店引用",
  "stat_date": "YYYY-MM-DD",
  "finding": {
    "metric_code": "conversion_rate",
    "observed_value": 0.12,
    "baseline_value": 0.18,
    "quality_state": "available"
  },
  "evidence_refs": ["内部脱敏证据引用"],
  "data_gaps": [],
  "action_items": [
    {
      "action_type": "verify_channel_offer",
      "reason": "基于已验证的渠道转化差异",
      "owner": "待分配",
      "review_at": "YYYY-MM-DD"
    }
  ],
  "whole_hotel_conclusion_allowed": false
}
~~~

没有 evidence_refs、质量状态或数据缺口说明的诊断，不得标记为可执行决策。

## 4. 诊断到动作

| 诊断层 | 证据要求 | 可形成的动作 |
| --- | --- | --- |
| 曝光异常 | 曝光字段、日期和来源可追溯 | 检查平台展示、广告或渠道内容 |
| 浏览转化异常 | 曝光和浏览分母口径一致 | 检查图片、标题、卖点和价格展示 |
| 支付转化异常 | 浏览、支付订单和窗口一致 | 检查房型、库存、价格和下单限制 |
| 评价异常 | 评分/数量和时间可验证 | 生成评价回复或服务整改任务 |
| 排名异常 | 竞争圈和更新时间明确 | 检查渠道表现，不直接推断全酒店竞争力 |
| 数据缺口 | 缺失原因和阻断节点明确 | 生成登录、绑定、权限或采集修复动作 |

动作必须记录：

- 来源诊断和证据引用；
- 动作类型、负责人和目标日期；
- 执行前状态；
- 执行结果或未执行原因；
- 复核时间窗口；
- 复核后的指标和质量状态。

AI只能提出建议或结构化动作意图，是否执行由权限、人工确认和运营流程决定。

## 5. OTA范围与全酒店范围

### 5.1 OTA_CHANNEL_SCOPE

可以描述：

- 指定平台的曝光、浏览、转化、订单、间夜、评价、排名和平台销售金额；
- 指定平台、指定POI、指定日期的渠道表现；
- 基于渠道证据的渠道运营动作。

不能直接描述：

- 全酒店总收入、总入住率、全酒店ADR或RevPAR；
- 线下直销、协议客、团购、PMS或财务结果；
- 酒店整体盈利、投资回报或资产价值。

### 5.2 WHOLE_HOTEL_SCOPE

只有在 PMS、财务或全渠道数据覆盖完整，并且有统一时间、酒店和口径校验时，才允许进入全酒店经营结论。OTA数据可以作为其中一个渠道证据，但不能替代全酒店分母。

## 6. 给宿析OS的学习指令

~~~text
请学习这组 OTA 运营能力规范，但不要复制原系统的代码、路由、接口地址、品牌、页面、凭证或客户数据。

请用宿析OS自己的架构重新设计：

1. OTA登录验证
- 验证会话是否有效；
- 验证酒店绑定是否正确；
- 验证平台店铺或POI是否存在且匹配；
- 验证订单、经营、评价等能力的权限；
- 验证登录后目标数据是否可用；
- 区分“会话有效但数据不可用”。

2. OTA数据获取
- 为美团、携程等平台实现独立适配器；
- 统一酒店、平台、POI、日期范围和指标查询上下文；
- 保存脱敏原始证据引用和标准化指标；
- 记录来源、采集时间、数据实际日期、能力和质量状态；
- 支持手动刷新、定时任务、受控浏览器会话和人工导入。

3. 数据质量
必须明确区分：
- available
- partial
- stale
- unverified
- binding_missing
- permission_denied
- collection_failed

禁止用空数组、0、空字符串或旧数据掩盖采集失败、字段缺失或日期不一致。

4. 业务闭环
实现：
登录验证
-> 数据可用性验证
-> OTA事实采集
-> 数据质量检查
-> 收益分析
-> AI诊断
-> 运营动作
-> 执行复核

5. 数据边界
OTA渠道数据只能用于渠道分析。
只有在PMS、财务或全渠道数据完整且经过范围校验时，才允许生成全酒店经营结论。

请输出：
- 模块设计；
- 逻辑数据表和字段字典；
- 状态机；
- 能力接口契约；
- 字段来源和标准化规则；
- 合同测试用例；
- 失败场景；
- 宿析OS自己的分阶段实现方案。
~~~

## 7. 最小验证任务

第一阶段只验证一个闭环：

~~~text
登录验证
-> 酒店/POI绑定验证
-> 查询昨日美团经营数据
-> 保存一条标准化快照
-> 显示质量状态、失败原因和来源引用
~~~

验收条件：

- 能判断会话是否有效；
- 能判断酒店是否绑定；
- 能判断POI是否正确；
- 能判断数据是否为目标日期最新可用数据；
- 能记录失败原因；
- 能追溯来源和采集时间；
- 不保存明文密码、Cookie和令牌；
- 快照明确标记为 OTA_CHANNEL_SCOPE；
- 不执行真实采集前，不得声称此闭环已完成。

## 8. 分阶段实现方案（仅作为规范，当前不执行）

| 阶段 | 交付物 | 通过条件 |
| --- | --- | --- |
| P0 规范 | 状态枚举、上下文、事实结构、隐私边界 | 合成数据可以覆盖成功和失败分支 |
| P1 验证 | 会话、酒店、POI和能力验证 | 每个检查点有状态和证据引用 |
| P2 最小采集 | 昨日美团单平台经营快照 | 快照可追溯、质量状态正确 |
| P3 扩展适配器 | 携程和更多平台能力 | 平台差异不污染统一事实层 |
| P4 下游闭环 | 收益分析、AI诊断、运营动作和复核 | 每条结论能回到事实和证据 |
| P5 全酒店 | PMS、财务或全渠道整合 | 通过范围、时间和分母完整性验证 |

## 9. 宿析OS当前实现追溯

本节只记录“规范要求已由哪些宿析OS模块和测试承接”，不把代码存在、历史数据存在或任务成功记录等同于真实平台数据已验证。

| 规范能力 | 宿析OS承接模块 | 最小验证证据 | 仍需真实证据的部分 |
| --- | --- | --- | --- |
| 会话、酒店、POI与登录后可用性验证 | `PlatformProfileBindingReadinessService`、`OtaCapabilityStateService` | 绑定检查、能力状态和安全投影测试 | 账户所有者在本机完成登录、验证码和目标酒店确认 |
| 经营、订单、点评能力分离 | `OtaCapabilityStateService`、`PlatformDataSyncService` | `business`、`orders`、`reviews` 分别为已验证、权限拒绝、采集失败或未验证 | 每项能力都必须有目标日期的实际结果，不能用其他能力代替 |
| 平台适配与标准化快照 | `CtripBrowserProfileDataSourceAdapter`、`MeituanBrowserProfileDataSourceAdapter`、`ManualImportDataSourceAdapter`、`PlatformDataSyncService` | 适配器合同、字段事实、采集时间、来源引用和标准化行测试 | 平台响应、酒店身份和字段口径仍须逐店逐日期复核 |
| 数据质量与下游门禁 | `OtaCollectionQualityStateService`、`OtaDataCredibilityGateService`、`P0OtaDownstreamGateService` | 七类质量状态、P0门禁和失败投影测试 | 目标日期流量行、字段事实和保存闭环必须真实存在 |
| 收益、AI、运营与投资边界 | `OtaRevenueMetricService`、`RevenueAiOverviewService`、`BusinessClosureOverviewService`、`Phase3OperationEffectLoopService` | OTA范围和全酒店范围阻断测试 | PMS、财务或全渠道分母完整前不得生成全酒店结论 |
| 凭证与隐私边界 | OTA凭证执行边界、浏览器Profile适配器和任务诊断脱敏逻辑 | `OtaCredentialReadPathTest`、`PlatformDataSyncPrivacyBoundaryTest` | 凭证仅由账户所有者在受控运行环境使用，不进入知识文档、日志或任务摘要 |

建议的本地回归集合：

~~~text
php vendor/bin/phpunit
npm run verify:p0-guards
npm run verify:platform-data-source-contract
npm run verify:phase1-ota-loop
npm run verify:p0-ota-field-loop -- --date=<目标日期>
~~~

其中最后一项只读取已保存的事实并报告门禁结果；它不会登录平台、不会采集数据，也不能替代账户所有者授权后的真实采集和复核。
