# 100 条规则：逐项证据矩阵

对应规则原文：`ota-basic-data-rules-100-audit-20260904.md`。本表是审查进度，不是“100 条全部合格”证书。

PASS 只表示所列代码、隔离测试或明确注明的本机页面范围内有正向证据；并不表示全部历史数据、全部账号或生产环境已通过。BLOCKED 是已确认缺少前置条件；NOT RUN 是仍需继续检查，不能混写成通过。没有使用模拟数据冒充 OTA 事实。

本轮范围已逐项检查：**97 PASS / 3 BLOCKED / 0 NOT RUN**。这是证据分类，不是全系统 97% 合规率。用户完成本机登录后，R041/R081 已取得实际页面证据；仍受阻的是旧币种/单位与退款归属的来源证据。页面验收另发现并修复 F25/F26，未用此前代码测试替代实际验收。

最新功能优先验收：另修复F29旧版订单漏读及F30查询状态/恢复问题。本轮正常历史功能已取得本机操作证据；上述3项源资料认证缺口不阻挡功能验收，也不因此升级为PASS。

## 证据索引

- **AUTH**：`tests/OnlineDataHistoryAuthorizationContractTest.php`（含改名、单边日期、两类读取脱敏）；`tests/OnlineDataTenantScopeTest.php`；`tests/HotelPermissionSecurityClosureTest.php`。
- **DATE**：`tests/OtaHistoryDateInputTest.php`；`app/service/OtaReadDateRangeService.php`。
- **HISTORY**：`tests/OnlineDataHistoryDatabasePaginationTest.php`、`OnlineDataHistoryServerPaginationTest.php`；真实 MySQL 酒店80双平台快照查询/回读，主台账保留数字与范围。
- **TEMPORAL**：`tests/MeituanTemporalServiceTest.php`、`MeituanTemporalAccessIsolationTest.php`。
- **PERSIST**：`tests/OnlineDailyDataPersistenceServiceTest.php`、`OnlineDailyDataReadbackProofTest.php`、`MeituanOnlineDataPersistenceServiceTest.php`。
- **TRUST**：`tests/OnlineDataTrustStatusServiceTest.php`、`OnlineDataFieldFactServiceTest.php`、`StrictCtripTrafficHistoryReaderTest.php`。
- **METRIC**：`tests/OtaStandardModuleTest.php`、`RevenueFactLayerServiceTest.php`、`OnlineDataSummaryTruthTest.php`。
- **NUMBER**：`tests/OtaNumericBoundaryTest.php`。
- **ORDER-HISTORY**：`tests/CtripLegacyOrderHistoryTest.php`与分析/金额守卫直接依赖共17 tests/205 assertions；`tests/automation/ctrip_order_analysis_query_state.test.mjs`12项和订单面板入口9项通过。酒店80/8月份旧样例2条原no_data，适配后基础数据可回显；7月空范围→全部已存范围→8月8日恢复。明确测试样例，不冒充真实经营订单。
- **UNIT-GUARD**：`tests/OtaUnknownCurrencyBoundaryTest.php`、`tests/OtaPercentUnitEvidenceTest.php`，F27/F28显式未知金额与流量比例消费保护；与数字、流量聚合和标准ETL直接依赖最终79 tests /1070 assertions通过。证据限纯函数/合成数据，旧记录的真实单位仍未证实。
- **RATE**：`tests/OtaTrafficAggregationBoundaryTest.php`。
- **ADR**：`tests/OtaAdrScopeBoundaryTest.php`。
- **BATCH**：`tests/OtaBatchPersistenceBoundaryTest.php`，SQLite 双平台重复投递、第二条插入故障、回读值被改变；`PlatformDataSyncPreflightL8Test --filter ExactDateProfileRequest`（相同运行复用）；`PlatformDataSyncServiceTest --filter 'NormalizedPersistenceReceipt|FinishTaskFailSafe'`（失败回执与状态终结）。没有进行真实 MySQL 并发压力测试。
- **UNITS**：`tests/OtaCurrencyBoundaryTest.php`、`CtripOrderExportImportServiceTest.php`、`CtripOrderAnalysisServiceTest.php`；`tests/automation/meituan_browser_capture_normalize.test.mjs`（指定销售额字段的分转元、非全量页拒绝汇总）。
- **DISPLAY**：`tests/automation/meituan_summary_boundary.test.mjs`、`meituan_download_data.test.mjs`、`ctrip_visible_download_snapshot.test.mjs`；覆盖加权比率、缺失分母、异店/重复日范围、异常值、当前页 CSV 和 429 停止即时重试。
- **COMPETITOR**：`CompetitorEventFeedServiceTest.php`、`CompetitorManualObservationServiceTest.php`、`tests/automation/competitor_event_feed_ui.test.mjs`；只证明同口径证据门和字段展示代码，不证明已采齐真实竞品套餐。
- **UI**：`tests/automation/meituan_stored_history_range.test.mjs`、`ota_stored_list_cache_scope.test.mjs`、`ctrip_ranking_history.test.mjs`、`ctrip_competition_circle_closure.test.mjs`、`ctrip_store_data_overview.test.mjs`、`meituan_temporal_ui.test.mjs`、`truthful_refresh_state.test.mjs`。代码级隔离执行，不是已登录浏览器点击证据。
- **LIVE-UI**：2026-09-04 用户自行登录的本机8080页面。美团酒店80/8月1–3日的全部已存、自定义日期入口、A-B-A酒店切换、反向日期报错及恢复；携程酒店80/8月3日回读26条但保留来源日未核验，2020-01-01无记录时旧表清除。桌面与768×900视口检查；只查已保存数据，未采集、改业务记录或审批。F26后未核验记录四类订单估算显示“未核验”，汇总卡片为0个。
- **DERIVATION**：`tests/automation/ctrip_unverified_history_display.test.mjs`（4项）：未核验来源拒绝推算、已存原字段保留、已核验来源原计算兼容、汇总和表格复用同店/来源日/回读证据门。
- **BODY**：`tests/automation/ctrip_response_body.test.mjs`、`ota_response_body.test.mjs`。
- **ENTRY**：`npm.cmd run verify:public-entry`、`verify:frontend-startup-helpers`；主入口 hash `6ec8568472`，携程/美团静态助手延迟包 hash `ab4bc44234`，模板 hash `4c4cd35f83`。三者本机HTTP响应字节与活动工作区一致，非线上部署证明。

## 矩阵

| 规则 | 当前判定 | 已检查内容 / 证据及边界 |
|---|---|---|
| R001 | PASS | AUTH：内部酒店 ID 授权；HISTORY：分组与回读保留内部酒店。 |
| R002 | PASS | AUTH：非管理员不能用指定酒店扩大权限；租户范围由后端决定。 |
| R003 | PASS | UI：携程 nodeId 不作 OTA hotelId；PERSIST：内部酒店与平台身份分开。 |
| R004 | PASS | DATE 拒绝错拼平台；AUTH/UI 维持平台范围；不把其他平台结果填空。 |
| R005 | PASS | METRIC/UI：本店、同行与竞争圈分别筛选，非本店不进入本店流量。 |
| R006 | PASS | UI：身份识别忽略竞争酒店，只接受配置绑定的本店身份。 |
| R007 | PASS | AUTH 新用例更新酒店名称后，原记录仍按 ID 查到，展示新名称。 |
| R008 | PASS | TEMPORAL：当前采集源登录故障不再将历史已保存证据标为阻塞。 |
| R009 | PASS | UI 9项缓存用例覆盖A-B-A、在途回复和账号变化；LIVE-UI美团历史酒店80→64→80未残留异店行。真实多账号/租户切换仍仅有隔离测试证据。 |
| R010 | PASS | TRUST/RevenueFactLayer：OTA channel 与全酒店/PMS 证据分开；UI 禁止用订单估全渠道间夜。 |
| R011 | PASS | TEMPORAL：空 snapshot_time 不遮住 captured_at；HISTORY 保留 data_date 与采集时间。 |
| R012 | PASS | DATE 与 UI 拒绝不存在的日期；历史和明细两个接口入口均覆盖。 |
| R013 | PASS | DATE：非法携程 range 返回 422，不能退成最新日期。 |
| R014 | PASS | DATE/UI：反向日期区间拒绝；单边业务日期保持单边约束。 |
| R015 | PASS | TEMPORAL：UTC 15:59 / 16:01 对应上海日界的回放用例。 |
| R016 | PASS | METRIC：携程 checkout 房费、booking 投影分离；该证据不扩展到未接入的 PMS 对账对象。 |
| R017 | PASS | PERSIST/HISTORY：历史、实时、未来在库存/预测期间与分组身份上分离。 |
| R018 | PASS | METRIC：同粒度优先最终历史；实时缺失不能自己成为最终历史。 |
| R019 | PASS | TEMPORAL：历史 as-of 不读取观察日之后的快照，包括预测引用池。 |
| R020 | PASS | DATE/UI：精确日期、缺日和返回日期错误均不换日补值。 |
| R021 | PASS | TRUST：成功必须有身份、采集时间、字段与回读证据；BODY 的 HTTP 内容读取成功不能直接放行。 |
| R022 | PASS | BODY：HTML、未知文本被拒绝，不保留原始敏感错误内容；支持结构化 JSON。 |
| R023 | PASS | TEMPORAL/PERSIST：任务完成、保存数量、精确回读分别验证。 |
| R024 | PASS | TEMPORAL：未来模块未更新时任务为 partial，不能整批 completed。 |
| R025 | PASS | BODY/UI/TRUST：请求失败、明确空列表、字段未返回分别处理。 |
| R026 | PASS | TRUST/UI：来源与请求平台门店身份匹配，竞争酒店身份不能代用。 |
| R027 | PASS | UI/PERSIST：携程竞争圈来源日期不符时阻止保存；未来目标日期不改写为历史日终。 |
| R028 | PASS | PERSIST/TRUST：source_trace_id、sync_task_id、来源路径和回读行关联。 |
| R029 | PASS | DISPLAY：最多3轮，429/限流异常不再进入600ms即时重试；普通网络错误仍有界。BATCH：同一精确日期运行复用；不同范围不能借用。 |
| R030 | PASS | TEMPORAL：历史证据状态与当前 source_state 分开，后者仍可真实显示 blocked。 |
| R031 | PASS | PERSIST/TEMPORAL：未保存/未回读不能报告完成。 |
| R032 | PASS | PERSIST：身份、日期、版本与实际观测值均参与精确回读。 |
| R033 | PASS | BATCH：双平台相同投递只保留1条并更新同一ID，真实0保留；唯一hash约束存在。证据不扩展为生产并发吞吐保证。 |
| R034 | PASS | PERSIST：身份哈希包含最终目的范围；美团榜单类型、期间与日期范围有独立身份。 |
| R035 | PASS | HISTORY：实时按任务/时间区分，同日多个快照不再合并成一个。 |
| R036 | PASS | HISTORY/PERSIST：next_30_days、future_on_books 保留观察版本，不伪装日终。 |
| R037 | PASS | RATE：先选最新批次再检查字段；同批次互补允许，不能借旧批次补齐。 |
| R038 | PASS | PERSIST：写入会清旧回读证明；缺值和真实 0 分开，旧事实不能冒充新采集。 |
| R039 | PASS | BATCH：第二条插入失败、回读金额被篡改均整批回滚，保存数不能报成功；失败状态收口用例通过。 |
| R040 | PASS | OnlineDailyDataReadbackProofTest：比较完整快照后 CAS，过期版本/错误租户不能加证明。 |
| R041 | PASS | HISTORY/LIVE-UI/ORDER-HISTORY：美团8月1–3日及自定义已存入口能回读；携程8月3日26条保留未核验。F29另修复携程旧版渠道汇总被漏读，酒店80/8月旧样例可查看；都不需要重新采集，不声称每天齐全或样例为真实订单。 |
| R042 | PASS | UI：已保存查询不调用采集、不读取 Cookie、不要求 OTA 登录。 |
| R043 | PASS | HISTORY/UI：每条记录保留实际业务日期；查询成功提示当前页，不声称每天覆盖。 |
| R044 | PASS | UI：已保存读取与重新采集分开；缺数据不自动发起补采。 |
| R045 | PASS | HISTORY：分组后缀带期间和采集身份；历史/过程快照不再被压进同组。 |
| R046 | PASS | HISTORY：SQL 先分完整组再分页，再以相同组键读取明细。 |
| R047 | PASS | HISTORY/AUTH：相同过滤用于总数/摘要/页数据，双平台 SQL/PHP 键一致。 |
| R048 | PASS | UI：失败返回 null，确认为空返回 []；不从旧内存数组推断查询成功。 |
| R049 | PASS | AUTH：详情记录所在酒店仍要经过线上数据查看权限校验。 |
| R050 | PASS | TEMPORAL/携程历史回归：显式观察日、业务日与基期，不能用任意最新快照代昨日。 |
| R051 | PASS | NUMBER/METRIC/TRUST/UNITS：缺失、null、未采集不是0；新增携程缺晚数回归，导入、分析汇总及房型表保留null，不因内部累加器初值0而变成事实。 |
| R052 | PASS | NUMBER/FieldFact/Temporal/SummaryTruth：来源显式 0 保留。 |
| R053 | PASS | NUMBER：拒绝非法千位分隔、百分号金额、NaN、INF 和数值溢出输入。 |
| R054 | PASS | FieldFact/METRIC：原始字段、标准字段、推导公式与来源路径分开。 |
| R055 | PASS | TRUST/QualityConcern：已保存回读、字段校验通过与业务来源可信为不同状态。 |
| R056 | PASS | TRUST/UI：stale 不能升为 verified；历史参考与当前值分开。 |
| R057 | PASS | TRUST：人工导入即使回读后仍不能自动当作平台验证事实；本任务测试均隔离。 |
| R058 | PASS | UNITS/DISPLAY：携程负晚数/房量现为422而非截成0，非法底价计入invalid计数；美团保留观测负值但不据此计算比率。METRIC 保留异常/可信度提示。未对所有产品页面做穷举。 |
| R059 | PASS | METRIC：美团订单/业务收入冲突显式报告，不静默拣值。 |
| R060 | PASS | TRUST/StrictCtripTrafficHistoryReader/RevenueFactLayer：来源证据不足保持阻塞或缺口。 |
| R061 | BLOCKED | UNITS/UNIT-GUARD：F27禁止显式未知或不支持单位进入可信金额/ADR/参考底价；有未知行不能把已知子集冒充完整总额，订单间夜保留。无标记旧行仍为legacy_unspecified，真实币种/单位不能自动补写或声明全部可比。 |
| R062 | PASS | METRIC：generic revenue、支付/结算值不代替 room_revenue。 |
| R063 | PASS | METRIC/FieldFact：订单 ID、房量、入住数量不冒充订单数或销售间夜。 |
| R064 | PASS | TEMPORAL：销售均价只在同一快照内用销售额/销售间夜推导。 |
| R065 | PASS | ADR：禁止跨天、跨店、跨平台凑分子分母；完全缺值的business经营日仍计作未完整范围；同日间夜调整、明确非营收投影排除合同保留。 |
| R066 | PASS | METRIC：可售房量或入住间夜缺失时，OCC/RevPAR 不可计算。 |
| R067 | PASS | ADR/METRIC：按范围房费与间夜总量计算，不简单平均每日 ADR。 |
| R068 | PASS | METRIC/ADR/RATE：派生比率分母无效时不可计算；单条来源已提供的比率作为直接事实另标 basis。 |
| R069 | BLOCKED | UNITS/METRIC：取消状态分类和订单版本去重通过，没有将取消笔数机械冲减销售额。现有美团日聚合只保留销售额/预订间夜；无法从它恢复完整退款事件ID、退款金额及归属日，因此跨期退款对账未获证明，不补造。 |
| R070 | PASS | METRIC：最终/实时、累计订单/点评投影去重，稳定事件保留。 |
| R071 | PASS | TEMPORAL/FieldFact：未来 pv/uv/advance_orders 类型分开；不把它们互为别名。 |
| R072 | PASS | TEMPORAL/METRIC：曝光、浏览、填单、提交、支付保留分环节字段与来源。 |
| R073 | PASS | RATE/UI：同条规范事实的分子分母汇总，界面读取范围再验；不同批次不拼字段。 |
| R074 | BLOCKED | NUMBER/UNIT-GUARD：显式0.5%=0.5；F28令无单位0–1流量值不可直接作可信百分数，保留原值与明确缺口，匹配原始单位或同范围计数可用。私有旧解析仍兼容；其他旧比例字段和全历史单位证明仍缺失，不猜测改写。 |
| R075 | PASS | 新 count_aggregation_scope 明确为每日渠道计数之和，不声称跨日/跨平台去重人数。 |
| R076 | PASS | RATE + DISPLAY：后端及美团当前页均按对应计数加权；缺比率日不得过滤掉以伪装整段完整；缺分母、异店或重叠快照不平均。单条来源直接比率单独标识。 |
| R077 | PASS | MeituanOnlineDataPersistenceServiceTest：榜单类型、期间与日期进入身份，percent-only 榜单不伪造 data_value。 |
| R078 | PASS | UI/METRIC：competitor_avg 明确为竞争圈平均，不以它替代本店或具体竞争酒店。 |
| R079 | PASS | COMPETITOR：房型/日期/来源面不能交叉；缺套餐可比证据只能显示可售观察，不能升级为可比价格或决策证据；前端字段/缺口绑定测试通过，真实登录后展示另属R081。 |
| R080 | PASS | METRIC：广告 ROAS 保留归因收入/花费语义，未用其声称因果增量；经营执行另有审批链。 |
| R081 | PASS | LIVE-UI：双平台桌面与768×900视口中，门店、平台、请求日期/范围、来源质量提示可见，查询可操作。仅验收这些范围控件与结果状态，不宣称所有页面布局或更小手机尺寸通过。 |
| R082 | PASS | UI：携程/美团历史入口仅读保存数据，提示未重新采集。 |
| R083 | PASS | 美团多日范围已解除单日限制；确认成功只描述当前页，未把单日可信 gate 扩成整段可信。 |
| R084 | PASS | UI 新缓存用例：切换范围先清旧数据；在途时修改日期，旧响应不能落入新范围。 |
| R085 | PASS | UI/F25：9项缓存/失败回归；清空列表同步作废snapshot，覆盖已缓存A→B未完成→返回A，以及筛选变化后网络失败。只匹配查询键/会话，失败不读旧数组或旧总数。 |
| R086 | PASS | DISPLAY：携程下载读取当前可见卡片和表格，不保留第二份映射；美团分模块当前页CSV及空值通过，卡片多日转化率重复算法本次已一并修复。 |
| R087 | PASS | QualityConcern 返回 limited/current_page；UI 读取成功提示当前页，不承诺全量。 |
| R088 | PASS | DISPLAY：美团CSV显式保留空白与真实0的差别、公式前缀转义；携程可见快照下载保留原展示文本和来源缺口；未声称所有未来导出格式已验证。 |
| R089 | PASS | DATE/UI/LIVE-UI/ORDER-HISTORY：F25修复反向日期误报暂无数据及残留2048旧总数。F30修复订单深度分析失败标题/旧响应/加载状态；12项动态测试通过，实际空范围可点击全部已存范围恢复。网络故障是隔离测试，不伪装实际断网测试。 |
| R090 | PASS | ENTRY：源码重新构建并通过入口保护；8080 实际静态产物 hash 与活动工作区一致。 |
| R091 | PASS | TEMPORAL/TRUST/ORDER-HISTORY：空时间字段有记录时间兼容；旧版渠道汇总通过只读适配回显，未保存分布维持缺失、明确样例标签，不全部放行。 |
| R092 | PASS | NUMBER/UNITS/ORDER-HISTORY：读取与聚合合同已为20260904.v3；旧dataset标legacy_unspecified。旧订单原import_contract保留null，通过独立read_adapter兼容，不伪造v1或改写源记录；既有消费者回归通过。 |
| R093 | PASS | 本次仅源码/隔离样例变更，真实库仅查询；没有重写任意酒店历史。 |
| R094 | PASS | AUTH：历史详情与明细列表均在读取边界脱敏；测试假凭证不返回，数据库原始记录不被修改。 |
| R095 | PASS | METRIC 的订单/点评/rooming 隐私回归通过；未读取客人真实联系方式。 |
| R096 | PASS | SQLite/VM/函数 fixture；本轮没有调用真实 OTA/LLM、发消息或写真实经营数据。 |
| R097 | PASS | 新输入、缓存、数值、聚合缺陷保留先失败后通过证据；F17 为明确源码违约加 SQLite 回归。 |
| R098 | PASS | 主台账区分代码、隔离测试、真实本地数据库、浏览器、Git与部署。 |
| R099 | PASS | 本次不造数据；无保存记录时明确缺失。无法恢复的原始值不倒推、不填0。 |
| R100 | PASS | 本表为全部100个ID给出判定与证据边界，未检与受阻项继续保持非通过。 |

## 收口条件

1. 本轮100项均有代码/样例检查结论；没有剩余NOT RUN，但仍不能宣称100项全部通过。
2. R061/R074 需要字段级币种/单位来源证据；R069需要退款事件与业务日期证据。不能猜测、自动换汇、批量改写旧数值或生成缺失事件。
3. R041/R081 的本机实际操作已完成；登录阻塞已解除。只复用用户现有登录态，未读取浏览器凭证或令牌。
4. R061/R069/R074继续保留来源证据缺口，不能把100条标为全部通过。按用户最新“主要考虑功能性问题”，本轮历史查询、旧版回显和错误恢复功能验收通过后收口，不让单位/退款资料考证无限阻挡正常功能。未提交、未推送、未上线。
5. F27/F28消费端保护不证明缺失历史单位；F29/F30补齐旧版订单读取与查询恢复并经实际页面验证。功能里程碑结束，停止；若用户另要求历史金额/退款认证，再按对应来源资料处理。
