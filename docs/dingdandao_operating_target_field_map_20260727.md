# 订单来了住宿经营指标 → 宿析OS 字段映射表

更新日期：2026-07-27
目标酒店：宿析OS `hotel=5`（敦煌漠蓝新）
当前订单来了权威门店名称：敦煌漠蓝
数据范围：订单来了「统计 → 数据中心 → 住宿 → 经营指标」

> 本表不记录 Cookie、Authorization、请求头、`tid`、Webhook 或浏览器会话材料。
> “敦煌漠蓝新 ↔ 敦煌漠蓝”已按用户确认登记为精确、可审计别名；完成服务器侧平台门店 ID 绑定前，生产保存和企业微信发送仍必须保持阻断。

## 1. 接口总表

| 业务范围 | 方法 | 接口 | 请求业务参数 | 响应主体 | 宿析OS用途 | 当前处理 |
|---|---|---|---|---|---|---|
| 当前门店身份 | POST | `/v2/ntw/web/ntw/get` | 当前登录门店上下文 | `data.id`, `data.name` | 门店身份门禁 | 必须成功且与服务器绑定一致 |
| 门店经营汇总 | POST | `/v2/um-b/web/pro/data/businessIndicatorsTotal` | `startDate=today`, `endDate=today`, `festivalType=-1200` | 汇总经营字段 | 当天经营事实 | 核心必需 |
| 房型/房间汇总明细 | POST | `/v2/um-b/web/pro/data/businessIndicatorsSumDetail` | 当天日期 + `type=0..3` | `data.list[]` | 房型名称、汇总值、层级对账 | 房费 `type=0` 为核心 |
| 房型/房间逐日明细 | POST | `/v2/um-b/web/pro/data/businessIndicatorsDailyDetail` | 当天日期 + `type=0..3` | `data.list[].dailyRoomRate[]` | 房间事实、日期、数值、明细对账 | 房费 `type=0` 为核心 |
| 门店经营趋势 | POST | `/v2/um-b/web/pro/data/businessIndicatorsTrend` | 当天日期 + 指标 `type` | `data.list[{date,value}]` | 趋势展示与报告上下文 | 总房费 `type=5` 已验证 |
| 当前区域汇总 | POST | `/v2/um-b/web/pro/data/businessIndicatorsTotal/county` | 当天日期 | 区域汇总字段 + `boolCity` | 区域对标 | 可选，禁止替代门店事实 |
| 当前区域趋势 | POST | `/v2/um-b/web/pro/data/businessIndicatorsTrend/county` | 当天日期 + 指标 `type` | 区域 `data.list[{date,value}]` + `boolCity` | 区域趋势对标 | 可选，禁止替代门店趋势 |

所有业务接口还必须满足：

- HTTP 状态成功；
- JSON `code="1"`；
- 统计日期等于上海时区当天；
- 不得使用昨天、周累计、月累计或自定义历史范围冒充今天；
- `errorDetail` 非空或关键字段缺失时标记采集失败/缺失。

### 2026-07-27 一次性直连实测

在已登录的云端浏览器设备内完成了一次只读直连验证。会话值只在同一进程内使用，未打印、未上传、未写入数据库或快照。

| 验证项 | 实测结果 |
|---|---|
| 会话来源 | localStorage `token` 作为同名请求头；`networkInfo.ntwNum`、`networkInfo.ntwInviteCode`、`networkNumNew` 三者必须非空且严格一致 |
| Cookie | 仅随同源请求由浏览器会话携带；不把 Cookie 名或值作为 `ntwNum` 回退来源 |
| 门店身份 | `/v2/ntw/web/ntw/get` 成功；权威名称为“敦煌漠蓝”，平台门店 ID 存在但不在本文展示 |
| 接口总数 | 13 个 POST 全部 HTTP 200、`code="1"`、`errorDetail=null` |
| 明细类型 | SumDetail 与 DailyDetail 的 `type=0/1/2/3` 全部成功 |
| 明细行数 | SumDetail 分别为 5/5/4/4 行；DailyDetail 分别为 25/25/5/5 行 |
| 房费对账 | 物理房间+未排房、房型小计、总计三种口径均为 6450.14 |
| 趋势 | 门店与区域 `type=5` 均返回 7 个点，目标日期点存在 |
| 副作用 | 未修改订单来了、未写生产经营数据、未发送企业微信 |

该实测说明主采集路径可以采用“临时读取当前设备会话 → 精确 POST → 生成脱敏业务快照 → 立即结束”，无需长期保持业务页面打开，也无需同账号再次登录。会话失效时必须返回 `session_expired`，不得用旧数据继续。

## 2. 明细 `type` 映射

| 页面标签 | `type` | SumDetail | DailyDetail | 是否进入当前经营目标核心事实 |
|---|---:|---|---|---|
| 房费明细 | 0 | 房型/房间房费汇总 | 当日逐房房费 | 是，必须用于总房费明细对账 |
| 间夜明细 | 1 | 房型/房间间夜汇总 | 当日逐房间夜 | 可作为辅助事实，不得冒充房费 |
| 入住率明细 | 2 | 房型入住率汇总 | 当日入住率明细 | 可作为辅助事实，不得冒充房费 |
| RevPAR明细 | 3 | 房型 RevPAR 汇总 | 当日 RevPAR 明细 | 可作为辅助事实，不得冒充房费 |

同一接口会因 `type` 不同重复出现。采集器必须使用“接口路径 + `type`”精确取数，不能只取同一路径的第一条响应。

## 3. 门店经营汇总字段

当前日期样例：2026-07-27。

| 订单来了字段 | 页面含义 | 宿析OS字段 | 类型/单位 | 当前样例 | 质量规则 |
|---|---|---|---|---:|---|
| `totalRoomFee` | 总房费 | `summary.total_room_fee` / `actual_revenue` | 金额，元 | 6450.14 | 必需；必须与房费明细合计一致 |
| `adr` | ADR / 平均房价 | `summary.adr` | 金额，元 | 645.01 | 必需；校验 `totalRoomFee / totalSalesNight` |
| `occ` | 入住率 | `summary.occupancy_rate_percent` | 百分比 | 66.67 | 必需；范围 0–100 |
| `revPar` | RevPAR / 平均客房收益 | `summary.revpar` | 金额，元 | 430.01 | 必需；与房费和可售间夜进行数学校验 |
| `totalSalesNight` | 累计售出间夜 | `summary.sold_room_nights` | 间夜 | 10 | 必需；不得用明细行数代替 |
| `adn` | 平均每日间夜 | `summary.average_daily_room_nights` | 间夜/天 | 10 | 必需 |
| `totalBusiness` | 总营业额 | 暂不映射到住宿房费事实 | 金额，元或缺失 | `null` | `null` 必须保持缺失，不能填 0 |
| `repairRoomQuantity` | 维修房数量 | 辅助房态事实 | 间 | 已观察到 0 | 来源返回 0 可保留，不用于填未知指标 |
| `blockupRoomQuantity` | 停用/封房数量 | 辅助房态事实 | 间 | 已观察到 0 | 同上 |
| `closeLinkRoomQuantity` | 关闭联房数量 | 辅助房态事实 | 间 | 已观察到 0 | 同上 |
| `selfNight` | 自用间夜 | 辅助间夜事实 | 间夜 | 已观察到 0 | 同上 |
| `freeNight` | 免费间夜 | 辅助间夜事实 | 间夜 | 已观察到 0 | 同上 |

### 同比/环比字段

| 字段组 | 含义 | 当前用途 |
|---|---|---|
| `ratioStartDate`, `ratioEndDate` | 去年同期日期范围 | 报告对比元数据；日期为空时不得生成同比结论 |
| `seqRatioStartDate`, `seqRatioEndDate` | 上一对比周期日期范围 | 报告对比元数据 |
| `totalRoomFeeRatioItem`, `totalRoomFeeRatio` | 总房费同期值与变化率 | 可选对比，不参与当天事实完整性补位 |
| `totalRoomFeeSeqRatioItem`, `totalRoomFeeSeqRatio` | 总房费上一周期值与变化率 | 可选对比 |
| `adrRatioItem`, `adrRatio`, `adrSeqRatioItem`, `adrSeqRatio` | ADR 对比值与变化率 | 可选对比 |
| `occRatioItem`, `occRatio`, `occSeqRatioItem`, `occSeqRatio` | 入住率对比值与变化率 | 可选对比 |
| `revParRatioItem`, `revParRatio`, `revParSeqRatioItem`, `revParSeqRatio` | RevPAR 对比值与变化率 | 可选对比 |

## 4. 房型/房间明细结构

| 响应字段 | 宿析OS字段/作用 | 说明 |
|---|---|---|
| `roomTypeId` | 仅用于本次响应内关联房型 | 不向用户展示，不作为跨酒店身份 |
| `roomTypeName` | `room_type` | 房型名称 |
| `roomId` | 明细层级判断 | 物理房间、未排房、房型小计或总计 |
| `roomName` | `room_number` 或合计标签 | 房间号/名称；合计行不作为物理房间 |
| `roomList[]` | 房型下汇总集合 | `sum` 随 `type` 表示房费、间夜、入住率或 RevPAR |
| `dailyRoomRate[].date` | `business_date` | 必须唯一且等于目标日期 |
| `dailyRoomRate[].price` | `room_fee` 或对应类型数值 | 0 是可保留事实；未知值不得填 0 |

2026-07-27 房费明细的已核验结构：

- 共 25 行；
- 16 个物理房间行；
- 4 个未排房行；
- 4 个房型小计行；
- 1 个总计行；
- 11 个来源明确返回的 0 值；
- 物理房间 + 未排房合计、房型小计合计和总计均为 6450.14。

## 5. 门店趋势

| 指标 | 已验证请求 `type` | 响应字段 | 宿析OS字段 | 规则 |
|---|---:|---|---|---|
| 总房费趋势 | 5 | `data.list[].date`, `data.list[].value` | `trend.total_room_fee[]` | 只保留目标日期及之前、最多 31 天 |

页面还提供平均房价、入住率、平均客房收益和间夜数趋势标签；其精确请求 `type` 未逐一完成网络验证前，不写入正式映射。

## 6. 当前区域对标

区域：甘肃省 / 酒泉市 / 敦煌市。区域值必须存放在独立的 `regional_benchmark` 语义中，不能写入门店 `summary`。

下表是页面截图时点的样例值。同日稍后的只读直连结果已变化为总房费 4571.44、ADR 410.73、入住率 44.13%、RevPAR 181.27、间夜 11.13，说明区域指标会在日内变化；生产快照必须同时保存采集时间，不能硬编码本文样例。

| 指标 | 门店值 | 区域值 | 页面显示“我比区域” | 计算 |
|---|---:|---:|---:|---|
| 总房费 | 6450.14 | 4573.08 | +41.05% | `(6450.14-4573.08)/4573.08` |
| ADR | 645.01 | 411.18 | +56.87% | `(645.01-411.18)/411.18` |
| 入住率 | 66.67% | 44.10% | +51.18% | `(66.67-44.10)/44.10` |
| RevPAR | 430.01 | 181.33 | +137.14% | `(430.01-181.33)/181.33` |
| 累计售出间夜 | 10 | 11.12 | -10.07% | `(10-11.12)/11.12` |
| 平均每日间夜 | 10 | 11.12 | -10.07% | `(10-11.12)/11.12` |

区域总房费趋势样例：

| 日期 | 区域总房费 |
|---|---:|
| 2026-07-21 | 6097.90 |
| 2026-07-22 | 6121.05 |
| 2026-07-23 | 5995.23 |
| 2026-07-24 | 5321.96 |
| 2026-07-25 | 5302.26 |
| 2026-07-26 | 5456.66 |
| 2026-07-27 | 4573.08 |

`boolCity=false` 原样保留为来源元数据；在没有官方字段说明前，不自行解释为新的行政层级事实。

## 7. 经营目标计算字段

| 宿析OS输出 | 公式 | 缺失处理 |
|---|---|---|
| 目标完成率 | `actual_revenue / revenue_target × 100%` | 任一值缺失则显示缺失 |
| 剩余营业额 | `max(revenue_target - actual_revenue, 0)` | 任一值缺失则显示缺失 |
| 售卖进度 | `sold_room_nights / sellable_room_nights × 100%` | 可售间夜缺失或为 0 时不计算 |
| 剩余可售房 | `max(sellable_room_nights - sold_room_nights, 0)` | 必须有真实可售间夜 |
| 剩余可售房所需平均房价 | `remaining_revenue / remaining_sellable_room_nights` | 剩余可售房缺失或为 0 时不计算 |
| 区域差异率 | `(hotel_value - regional_value) / regional_value × 100%` | 区域值缺失或为 0 时不计算；不影响门店核心数据质量 |

## 8. 宿析OS落库与推送门禁

| 阶段 | 存储/输出 | 必须满足 |
|---|---|---|
| 原始脱敏快照 | `dingdandao_operating_target_captures` | 门店、日期、来源、采集时间、身份、质量状态 |
| 房费可追溯明细 | `dingdandao_room_fee_capture_details` | 行级层级、房型/房间、金额、来源顺序 |
| 经营目标与结果 | `operating_target_daily_records` | 每酒店每天唯一，保存目标、事实、计算状态 |
| 历史修订快照 | `operating_target_daily_snapshots` | 记录 revision，支持按酒店与日期回读 |
| 发送逻辑记录 | `manual_notification_schedule_dispatches` | 门店、机器人、窗口、幂等键、最终状态 |
| 每次发送尝试 | `manual_notification_dispatch_attempts` | 尝试次数、业务返回、失败原因、重试状态 |
| 调度运行记录 | `manual_notification_schedule_runs` | 采集、报告门禁、发送计数和最终结果 |

推送前必须全部通过：

1. 权威门店身份与服务器绑定一致；
2. 业务日期等于上海时区当天；
3. 六个门店汇总指标齐全；
4. 房费明细合计等于门店总房费；
5. ADR、入住率、RevPAR 数学对账通过；
6. 快照已保存并从数据库完整回读；
7. 当日经营目标已配置；
8. 报告门禁为 `ready`；
9. 目标机器人属于同酒店、作用域为 `operating_target_test`；
10. 企业微信 HTTP 成功且业务 `errcode=0` 才记录为发送成功。
