# 携程 eBooking 采集实测结果

生成时间：2026-05-31

## 结论

使用 `hotel_001` 浏览器 Profile 的既有登录态，项目已经证明“登录携程后可以抓到经营诊断所需的核心数据”。当前可用于诊断的方向包括收益销售、流量转化、竞争圈、服务质量/IM、房型、销售数据和用户画像。  

本轮不能继续新开浏览器补抓，因为当前沙箱禁止启动 Chromium，错误为 `browserType.launchPersistentContext: spawn EPERM`。这不是 Cookie 失效，也不是携程接口 500。

## 已验证输出

| 输出文件 | 登录状态 | 响应数 | 字段事实 | 标准行 | 说明 |
|---|---:|---:|---:|---:|---|
| `runtime/ctrip_capture/hotel_001_wide.json` | logged_in | 158 | 1124 | 93 | 首页、经营概要、流量、部分金字塔辅助响应 |
| `runtime/ctrip_capture/hotel_001_missing_sections.json` | logged_in | 41 | 3112 | 122 | 销售数据、房型 |
| `runtime/ctrip_capture/hotel_001_nav_sections.json` | logged_in | 69 | 955 | 76 | 竞争圈、流失分析、用户行为、IM |
| `runtime/ctrip_capture/hotel_001.diagnosis.snapshot.json` | ready | 268 | 5191 | 291 | 合并以上三次采集后的统一诊断输入 |

增强摘要文件：

- `runtime/ctrip_capture/hotel_001_wide.enhanced.summary.md`
- `runtime/ctrip_capture/hotel_001_missing_sections.enhanced.summary.md`
- `runtime/ctrip_capture/hotel_001_nav_sections.enhanced.summary.md`

聚合诊断快照：

- `runtime/ctrip_capture/hotel_001.diagnosis.snapshot.md`
- `runtime/ctrip_capture/hotel_001.diagnosis.snapshot.json`

这份快照由三次采集结果合并生成，作为当前携程诊断的统一输入，避免只看单页或单次采集导致漏判。

应用内读取入口：

- 接口：`GET /api/online-data/ctrip-diagnosis-snapshot`
- 页面：在线数据 -> 携程浏览器 Profile 兜底采集 -> `读取诊断快照`
- 作用：不用重新启动浏览器，也可以读取最近一次已验证采集形成的诊断方向、字段命中和标准行数量。

Cookie/Profile 采集入口：

- 页面按钮：在线数据 -> 携程浏览器 Profile 兜底采集 -> `启动 Profile 采集并入库`
- 账号登录：未传 Cookie 时，复用或打开本机 `storage/ctrip_profile_<profile_id>` 登录态。
- Cookie 注入：选中携程配置后，前端会把配置 Cookie 传给后端；后端只写入 `runtime/ota_cookie_injection/` 临时文件，并通过 `--cookies-file` 注入浏览器采集脚本，采集结束后删除临时文件。

审计文件：

- `runtime/ctrip_capture/hotel_001_wide.audit.md`
- `runtime/ctrip_capture/hotel_001_missing_sections.audit.md`
- `runtime/ctrip_capture/hotel_001_nav_sections.audit.md`

## 已可诊断数据

| 诊断方向 | 已命中字段 |
|---|---|
| 收益销售 | 预订订单数、间夜/在店间夜、预订销售额、平均卖价/起价、出租率、紧张度 |
| 流量转化 | 访客量、列表页曝光、详情页访客、订单页访客、订单提交人数、流量转化率、成交/下单转化率 |
| 竞争圈 | 竞争圈排名、竞争圈平均值、流失订单数、流失订单金额 |
| 服务质量/IM | PSI服务质量分、减分项、回复率、IM指标、酒店收藏数、点评分、5分钟回复率 |
| 房型 | 房型 ID、房型名称、可用房量、总房量、取消率、竞争酒店、距离、星级、商圈 |
| 用户行为 | 用户性别、用户年龄、客源来源、提前预订天数、连住天数 |
| 辅助事实 | 公告/提示、金字塔辅助配置、页面通知 |

## 已命中模块和接口

| 模块 | 状态 | 已命中接口 |
|---|---|---|
| 首页实时概览 | 已命中 | `queryHomePageRealTimeData` |
| 经营报告-概要 | 已命中 | `getHotelAdvice`、`getDayReportRealTimeDate`、`fetchCapacityOverViewV4`、`fetchMarketOverViewV2`、`getDayReportFlowCompete`、`fetchVisitorTitleV2`、`fetchCurrentHotelSeqInfoV1`、`queryFlowTransformNewV1`、`getDayReportServerQuantity` |
| 经营报告-流量数据 | 已命中 | `queryScanFlowDetailsV2`、`queryFlowTransformNewV1`、`fetchOrderOverView`、`queryOrderTrendV1`、`queryFlowSource`、`queryCityHotKeywords`、`querySearchFlowDetails`、`queryHotelMinPriceV1`、`getPictureQualityScore`、`getCommentsScoreV2` |
| 经营报告-销售数据 | 已命中 | `queryMarketDetailsV1`、`fetchTensityOverViewV1`、`queryOrderTrendV1`、`queryHotelOccupiedRoomTrendV1`、`getRoomOccupiedRoomTrend`、`queryHotelTensitiesV1`、`queryRoomTensitiesV1`、`queryHotelMinPriceV1`、`queryMarketRoomTensity` |
| 经营报告-房型 | 已命中 | `queryRoomTypeInfo`、`queryCompetingHotelsV2`、`fetchCompetitiveMarket`、`queryVendibilityRoom` |
| 竞争圈动态-概览 | 已命中 | `getManagementData`、`getMasterHotelLabel`、`getFlowData`、`getServiceData`、`getFlowSource` |
| 竞争圈动态-流失分析 | 已命中 | `getTripartiteOrderLoss`、`getLossOrderCompeteHotel` |
| 竞争圈动态-榜单 | 已命中 | `getCompetingRank` |
| 用户行为-用户分析 | 已命中 | `queryUserSex`、`queryUserAge`、`queryUserSource`、`queryUserBookingDays`、`queryUserStayDays`、`queryUserFeatures` 等 |
| 用户行为-IM看板 | 已命中 | `getImIndex`、`getImDateDistribute`、`getImSessionDistribute`、`getImOrderConversionRateByDay`、`getImOrderConversionDetail` |
| 金字塔推广 | 部分命中 | `reportInjectFnInfo`、`getEbkResourceYellowBar`，当前只形成辅助事实，未形成广告花费/ROAS 标准指标 |

## 仍需补抓或补映射

| 模块 | 当前状态 | 原因判断 |
|---|---|---|
| PSI 独立页 | 未单独命中 | PSI 相关指标已从经营概要/流量接口拿到一部分，但 `/psi/index` 独立页这轮未触发 |
| 热点日历 | 未命中 | 需要单独触发 `queryHotCalendarInfo` 或从页面日期组件入口触发 |
| 金字塔广告正式指标 | 未完整命中 | 已命中辅助接口，但 `queryCampaignSummaryReport`、`queryCampaignReportList`、`queryPyramidCpcDiagnosis` 等未形成标准广告指标 |
| 携程商旅 BPI/经营/竞争圈 | 未命中 | 商旅后台是另一套域名/权限入口，当前 eBooking Profile 未触发对应接口 |

## 当前可交付判断

可以进入携程经营诊断的基础能力已经成立：拿到登录态或 Cookie 后，系统能监听页面接口并生成标准经营事实。  

还不能宣称“所有截图模块 100% 全量自动抓取完成”。缺口集中在独立 PSI 页、热点日历、金字塔广告正式指标和携程商旅后台。
