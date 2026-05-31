# 携程数据基石：项目口径、字段目录与采集逻辑

> 口径：本目录只描述携程 eBooking / 携程商旅后台中，酒店授权账号可见的 OTA 或商旅渠道数据；不等同于 PMS 全渠道经营数据。

## 项目定位

宿析OS 的携程数据基石不是单个接口清单，也不是把页面数值简单搬进系统；它是把平台可见经营事实接入“诊断、动作、复盘、沉淀”的经营反馈系统输入层。

```text
携程 / 携程商旅可见数据
-> 采集证据与字段目录
-> 标准事实与质量校验
-> 收益 / 流量 / 竞对 / 服务 / 广告 / 商旅诊断
-> AI 运营建议
-> 运营动作跟踪
-> 效果复盘
-> 投资和扩张判断
```

项目文字描述统一为：宿析OS 以授权 OTA 可见数据为经营输入，用采集证据、字段标准化和质量校验保证口径，再把收益、流量、转化、竞争圈、服务质量、广告和商旅数据转成可解释诊断、待确认 AI 建议、运营动作与效果复盘。

这句话对应的系统逻辑：

1. 先证明数据来源：保留页面、接口、Payload、Response、酒店、日期、采集方式和失败原因。
2. 再统一字段口径：中文名优先参考页面和 i18n 语言包，但翻译包不是业务数据，入库字段必须绑定 source path 和证据状态。
3. 再形成标准事实：数值事实进入 `standard_rows`，非数值事实保留在 `raw_data`，不伪造金额、订单或间夜。
4. 再进入经营诊断：收益、流量、转化、竞对、服务、广告、商旅分开分析，不混用 OTA、竞争圈、商旅和全酒店口径。
5. 最后进入动作复盘：AI 只给建议和解释，关键动作必须人工确认、记录执行证据，并用下一轮数据复盘。

本目录用于固定三类信息：

- 可抓页面、接口规则和字段命名。
- 字段来源、模块归属、渠道口径和观察状态。
- 后续保存、回显、编辑、质量监控和分析映射的统一依据。
- i18n 翻译包和前端埋点代码只作为页面语义、术语命名和触发线索，不进入经营指标事实。

## 字段到业务动作的链路

| 环节 | 输入 | 宿析OS处理 | 输出 |
|---|---|---|---|
| 采集证据 | Request URL、Payload、Preview / Response、页面上下文、酒店和日期参数 | 校验来源、脱敏敏感字段、标记 observed / inferred / P3 候选 | 可审计证据包和接口候选矩阵 |
| 字段目录 | i18n 术语、页面展示名、接口 source path | 统一标准字段、中文名、来源字段和口径；翻译包不作为业务数据，无法确认时保留原始字段名 | catalog_facts |
| 标准事实 | 订单、间夜、销售额、曝光、访客、转化、排名、PSI、BPI、广告等字段 | 按 OTA 渠道口径写入 rows；非数值事实只进 raw_data | standard_rows / online_daily_data |
| 经营诊断 | 标准事实、竞争圈均值、趋势和缺失状态 | 拆分收益、流量、转化、竞对、服务质量、广告和商旅原因 | 可解释诊断和阻塞原因 |
| 运营动作 | AI 建议、人工确认、执行条件和目标指标 | 记录动作、负责人、观察期、执行证据和风险 | 可追踪动作单 |
| 效果复盘 | 下一轮 OTA 数据、执行前后指标、失败原因 | 对比目标和实际结果，沉淀有效策略或暴露无效动作 | 复盘结论和投资/扩张参考 |

## 采集逻辑

```text
授权门店账号 / 浏览器 Profile
-> 打开携程或携程商旅后台页面
-> 页面触发 XHR / Fetch 业务接口
-> 按接口目录匹配模块和字段
-> 解析 JSON 响应
-> 脱敏、去重、字段抽取
-> 输出 catalog_facts / rows
-> 标准导入、质量校验和经营分析
```

- `observed` 表示已由页面截图或接口名称确认；`inferred` 表示导航地址按模块推断，仍需实测复核。
- 优先解析结构化 JSON；DOM 页面值只作为可见值补充，不替代接口口径。
- i18n 翻译包中的功能、按钮、指标、提示语、节假日、国家地区和埋点上报代码只用于理解页面，不作为订单、流量、收益、竞争圈或服务质量事实。
- 采集失败、字段缺失、口径不明必须显式记录状态，不用默认值覆盖问题。
- Profile 采集会按模块执行 `interaction_plan`，尝试点击页签、平台、周期和维度按钮；结果写入 `pages[].interactions` 作为触发证据。
- 未点击到的页面控件会记录为 `not_visible` 或 `disabled`，不会把未触发接口伪装成已采集字段。

## 字段进入系统的判定顺序

| 顺序 | 判断点 | 通过条件 | 不通过处理 |
|---|---|---|---|
| 1 | 页面与接口来源 | URL 属于携程 / 携程商旅授权后台，且能关联酒店、日期或当前门店上下文 | 标记为非业务接口或待补上下文 |
| 2 | 证据完整度 | 有 Request URL、Payload、Preview / Response 或可复现的页面触发记录 | 保留为 P3 候选，不进入正式字段目录 |
| 3 | 字段口径 | 能在页面、i18n 术语、source path 或接口值中确认含义，且业务值来自接口/页面展示而非翻译包 | 保留原字段名和 source path，不改写成确定指标 |
| 4 | 数据类型 | 能判断为收益、流量、转化、竞争圈、服务质量、广告、商旅或辅助事实 | 仅进入 `raw_data`，不参与指标计算 |
| 5 | 入库方式 | 数值事实可映射到标准行；文本、标签、建议、日历等事实只做来源记录 | 标记 `fact_only` 或 `review_required` |
| 6 | 分析使用 | 字段有来源、口径、时间和采集状态 | 缺字段或采集失败时显式阻塞，不使用默认值替代 |

## 数据分层

| 层级 | 作用 | 输出形态 |
|---|---|---|
| source_page | 后台页面和页签入口 | 页面 URL、模块、观察状态 |
| endpoint_rule | 可识别接口规则 | endpoint id、关键词、数据类型 |
| raw_response | 原始接口响应 | 脱敏后的 JSON 摘要 |
| catalog_facts | 字段目录抽取结果 | 标准字段、中文名、来源字段、值、来源路径 |
| standard_rows | 后续导入行 | 酒店、平台、日期、指标、数值、采集状态；非数值事实只进 raw_data |
| analysis_subject | 经营分析对象 | 收益、流量、转化、竞争圈、服务质量、广告、商旅 |

- 热度日历、用户画像、课程/策略等非数值事实会标记 `raw_data.fact_only=true`，不伪造 `amount`、`quantity`、`book_order_num`。
- 竞争圈卡片中的 `myValue / competitorAvg / rank` 会按单卡片成行，分别保留本店值、竞争圈平均和排名。
- Profile 采集结果会返回 `standard_data_type_counts`、`standard_section_counts`、`endpoint_candidate_counts`、`p3_evidence_counts` 和 `p3_evidence_status_counts`，用于判断标准事实命中情况、P3 候选接口缺口和证据草稿完整度。

## 模块优先级

| 优先级 | 范围 | 用途 |
|---|---|---|
| P0 | 首页实时、经营报告概要、销售数据、流量数据、房型 | 收益分析、日报、流量漏斗、房型库存和核心运营诊断 |
| P1 | 竞争圈概览、流失分析、竞争圈榜单、热点日历、用户分析 | 竞对对比、流失去向、市场热度和客群结构判断 |
| P2 | 金字塔推广、PSI 服务质量分、IM 看板、BPI 分、携程商旅经营报告 | 广告投放、服务质量、客服响应、商旅渠道和企业客户表现 |
| P3 | 订单明细、价格房态、促销活动、结算财务、合同/MICE/RFP | 仍需补充真实接口 Payload / Response 后再进入字段目录 |

## 采集范围预设

| 预设 | 覆盖范围 | 使用场景 |
|---|---|---|
| `default` | 经营报告概要、流量数据 | 日常低成本自动抓取 |
| `core` | 首页实时、经营报告概要、销售数据、房型、流量数据 | P0 核心经营诊断 |
| `wide` | P0 + 竞争圈、流失、榜单、用户行为、IM、金字塔、PSI、商旅 BPI/经营/竞争圈 | 周期性全量经营复核 |
| `all` | 字段目录内全部非点评明文模块 | 手动盘点或接口变更复核 |

- Profile 采集参数可传 `--sections=core`、`--sections=wide`、`--sections=all`，或逗号分隔的模块 ID / alias。

## 口径边界

- OTA 指标只代表携程或携程商旅渠道表现，不能直接写成全酒店出租率、ADR、RevPAR 或全渠道收入。
- 竞争圈指标代表平台定义的同圈对比，不代表市场全量。
- 携程 / Trip.com eBooking 中文前端翻译包是语言资源和前端线索，不是业务数据；埋点代码不进入经营诊断。
- 点评明文、住客隐私、账号 Cookie、token、签名和授权头不进入字段目录、日志或报告。
- 点评相关接口默认不采集明文内容；仅保留点评分、PSI、回复率等经营质量指标。

## 汇总

- 模块数：16
- 接口规则数：70
- 去重字段数：110
- 页面交互计划：14 个模块 / 74 个触发动作
- 点评明文采集：默认禁用；仅保留评分汇总、回复率等非点评明文指标。

## 未归档接口候选

| 候选方向 | 优先级 | 数据类型 | 触发关键词 | 入目录条件 |
|---|---|---|---|---|
| 订单明细 | P3 | order | orderdetail, orderdetails, orderdetailsearch, orderlist, ordersearch, searchorder, queryorder, orderquery | 必须补齐 Request URL、Payload、Preview / Response 后才能转为正式字段 |
| 价格房态 | P3 | business | ratecalendar, ratecalendarprice, pricequery, pricecalendar, roomstatus, inventory, stock, available | 必须补齐 Request URL、Payload、Preview / Response 后才能转为正式字段 |
| 促销活动 | P3 | advertising | promotion, campaign, coupon, benefit, discount, activity, marketing | 必须补齐 Request URL、Payload、Preview / Response 后才能转为正式字段 |
| 结算财务 | P3 | finance | settlement, settle, bill, billing, invoice, finance, payment, accountstatement | 必须补齐 Request URL、Payload、Preview / Response 后才能转为正式字段 |
| 合同 / MICE / RFP | P3 | contract | contract, contractpre, termssearch, termsearch, mice, rfp, meeting, venue | 必须补齐 Request URL、Payload、Preview / Response 后才能转为正式字段 |

## 真实采集结果审计

- 使用方式：`npm run audit:ctrip-capture -- --input=<ctrip_browser_capture_output.json>`。
- 门禁方式：`npm run audit:ctrip-capture -- --input=<ctrip_browser_capture_output.json> --fail-on-gate`。
- 字段覆盖门禁：`--min-field-coverage-rate=<0-100>`、`--max-missing-fields=<n>`、`--require-field-coverage`，用于防止接口命中但核心字段缺失的采集被误判为成功。
- `Capture Gate` 会把登录页、空业务响应、无标准行、正式接口缺失标记为失败，不能作为宿析OS数据分析基石。
- 输出文件：`reports/ctrip_capture_audit.json` 和 `docs/ctrip_capture_audit.md`。
- 审计脚本只汇总已归档字段、标准行和未归档接口候选；不会把候选接口自动升级为正式字段。
- 候选接口进入字段目录前，仍必须补齐 Request URL、Payload、Preview / Response、页面上下文和酒店/日期参数。
- Profile 抓取会对命中 P3 关键词的未归档接口生成脱敏 `p3_evidence_drafts`；完整状态为 `complete_redacted` 时也只代表可进入人工映射审核，不会自动启用入库字段。
- 审计报告会展示 `P3 证据草稿覆盖`，按候选方向汇总 `ready_for_review`、`incomplete_evidence` 和 `missing_evidence`。

## DevTools 证据包校验

- 证据模板：`npm run generate:ctrip-evidence-templates` 会输出 `docs/ctrip_endpoint_evidence_templates.md` 和 `reports/ctrip_endpoint_evidence_templates.json`。
- 使用方式：`npm run validate:ctrip-endpoint-evidence -- --input=<endpoint_evidence.json>`。
- 批量方式：重复传入多个 `--input=<endpoint_evidence.json>` 会输出 P3 证据覆盖矩阵。
- 输入内容：Request URL、method、headers、Payload、Preview / Response、page_context、hotel/date params。
- 输出文件：`reports/ctrip_endpoint_evidence.json` 和 `docs/ctrip_endpoint_evidence.md`。
- 校验结果为 `complete_redacted` 才能进入字段映射；缺少任一证据时只能保留为 P3 候选。
- 完整证据包会生成 `field_mapping_draft`，用于人工确认 source path、标准字段名、入库列和隐私处理方式。
- `field_mapping_draft.safe_to_auto_apply` 固定为 `false`；正式写入字段目录前必须人工复核。
- 草案转候选映射：`npm run promote:ctrip-mapping-draft -- --input=<ctrip_endpoint_evidence.json> --output=<approved_mapping.candidate.json>`。
- Profile 批量候选映射：`npm run promote:ctrip-mapping-draft -- --input=<ctrip_browser_capture_output.json> --output=<approved_mapping.candidate.json>` 会从 `complete_redacted` 的 `p3_evidence_drafts` 生成待审核映射。
- 转换结果固定为 `review_required` / `approved: false`，只用于人工审核，不会被自动采集流程启用。
- 输出会移除 Cookie、Authorization、Token、密码、签名等敏感字段，并对订单住客信息做 hash 或掩码。

## 已审核 P3 映射采集

- 模板文件：`docs/ctrip_approved_mapping.example.json`。
- 使用方式：`node scripts/ctrip_browser_capture.mjs ... --approved-mappings=<approved_mapping.json>`。
- 后端入口：手动采集请求或自动 Profile 配置可传 `approved_mappings_path` / `approved_mapping_path` / `p3_mappings_path`，文件必须位于项目目录内且为 JSON。
- 离线验证：`npm run dry-run:ctrip-approved-mapping -- --evidence=<endpoint_evidence.json> --mapping=<approved_mapping.json>`。
- 只有 `approved: true` 的映射会参与 P3 响应提取；未审核草案不会自动生效。
- 候选文件转正式规则前，必须人工确认 mapping 和字段级 `approved`，确认 source path、隐私处理和入库列后再启用。
- 提取结果写入 `standard_rows`，隐私字段只保留 hash 或掩码，不保存订单号、住客姓名、手机号明文。

## 模块

| 模块ID | 模块 | 数据类型 | 导航地址口径 |
|---|---|---|---|
| homepage | 首页实时概览 | business | inferred |
| business_overview | 经营报告-概要 | business | observed |
| sales_report | 经营报告-销售数据 | business | observed |
| room_type | 经营报告-房型 | business | observed |
| traffic_report | 经营报告-流量数据 | traffic | observed |
| competitor_overview | 竞争圈动态-概览 | business | sidebar_navigation, inferred |
| loss_analysis | 竞争圈动态-流失分析 | business | sidebar_navigation, observed_from_response |
| competitor_rank | 竞争圈动态-榜单 | traffic | sidebar_navigation, inferred |
| user_profile | 用户行为-用户分析 | business | sidebar_navigation, inferred |
| im_board | 用户行为-IM看板 | quality | sidebar_navigation, inferred |
| ads_pyramid | 金字塔推广 | advertising | observed |
| quality_psi | PSI服务质量分 | quality | observed |
| market_calendar | 热点日历 | business | observed_from_endpoint |
| biztravel_bpi | 携程商旅-BPI分 | quality | observed_from_screenshot, fallback |
| biztravel_business_report | 携程商旅-经营报告 | business | observed_from_screenshot, fallback |
| biztravel_competitor | 携程商旅-竞争圈概览 | business | observed_from_screenshot, fallback |

## 接口与字段

### homepage_realtime

- 模块：首页实时概览
- 数据类型：business
- URL关键词：queryHomePageRealTimeData

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |
| loss_order_count | 流失订单数 | lossOrderCount | - |
| target_url | 页面跳转地址 | targetUrl | - |

### platform_resource_popups

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：getEbkResourcePopups

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| notice_title | 提示标题 | title, name, noticeTitle | - |
| notice_text | 提示内容 | content, message, text, tips, tip, description, desc | - |
| config_name | 配置名称 | configName, configKey, key, code | - |
| config_value | 配置值 | configValue, value | - |
| target_url | 页面跳转地址 | targetUrl, url | - |

### platform_notifications

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：getMultiNotifyMessage / queryEPush

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| notice_title | 提示标题 | title, name, noticeTitle | - |
| notice_text | 提示内容 | content, message, text, tips, tip, description, desc | - |
| config_name | 配置名称 | configName, configKey, key, code | - |
| config_value | 配置值 | configValue, value | - |
| target_url | 页面跳转地址 | targetUrl, url | - |

### hotel_advice

- 模块：经营报告-概要
- 数据类型：quality
- URL关键词：getHotelAdvice

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| diagnosis_score | 数据诊断分 | score | - |
| diagnosis_level | 评级 | scorelevel, level | - |
| advice_count | 经营提醒数量 | goodhotelAdviceEntityList, badhotelAdviceEntityList | - |
| advice_text | 经营建议 | tasktext, taskname, taskbutton | - |

### business_realtime

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：getDayReportRealTimeDate

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### business_capacity

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：fetchCapacityOverViewV4

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### business_market_overview

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：fetchMarketOverViewV2

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### business_flow_compete

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：getDayReportFlowCompete

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### business_visitor_title

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：fetchVisitorTitleV2

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### business_hotel_seq

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：fetchCurrentHotelSeqInfoV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| seq_rank | 实时排名 | rank, qunarRank, competitorRank, qunarCompetitorRank | - |

### business_flow_transform

- 模块：经营报告-概要
- 数据类型：traffic
- URL关键词：queryFlowTransformNewV1 / queryFlowTransforNewV1 / queryFlowTransferNewV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### business_service_quantity

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：getDayReportServerQuantity

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |

### weekly_report

- 模块：经营报告-概要
- 数据类型：business
- URL关键词：getReportSuggestV1 / getLastWeekReportV1 / getWeekSuggestionV1 / getTrafficReportV1 / getUserBehaviorV1 / getHotRoomsV1 / getFlowHotelsV1 / getHotHotelsV1 / getHotWordsV1 / getCompeteHotelReportV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |
| user_sex | 用户性别 | sex, gender, userSex | - |
| user_age | 年龄段 | age, ageRange, userAge | - |
| user_source | 客源来源 | source, userSource, cityName | - |
| user_type | 用户类型 | userType, travelType | - |
| booking_days | 提前预订天数 | bookingDays, advanceDays, leadTime | - |
| stay_days | 入住天数 | stayDays, stayLength | - |
| price_band | 消费档位 | price, priceInfo, priceBand | - |
| strategy | 提升策略 | strategy, suggestion, imageList | - |

### sales_market_detail

- 模块：经营报告-销售数据
- 数据类型：business
- URL关键词：queryMarketDetails / queryMarketDetailsV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### sales_tensity_overview

- 模块：经营报告-销售数据
- 数据类型：business
- URL关键词：fetchTensityOverViewV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### sales_order_trend

- 模块：经营报告-销售数据
- 数据类型：business
- URL关键词：queryOrderTrendV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### sales_occupied_room_trend

- 模块：经营报告-销售数据
- 数据类型：business
- URL关键词：queryHotelOccupiedRoomTrendV1 / getRoomOccupiedRoomTrend

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### sales_tensities

- 模块：经营报告-销售数据
- 数据类型：business
- URL关键词：queryHotelTensitiesV1 / queryRoomTensitiesV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### sales_min_price

- 模块：经营报告-销售数据
- 数据类型：business
- URL关键词：queryHotelMinPriceV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| min_price | 实时起价 | minPrice | - |
| min_price_rank | 起价排名 | minPriceRank | - |

### sales_market_room_tensity

- 模块：经营报告-销售数据
- 数据类型：business
- URL关键词：queryMarketRoomTensity / queryRoomOccupiedTrend

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### room_type_info

- 模块：经营报告-房型
- 数据类型：business
- URL关键词：queryRoomTypeInfo

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| room_type_id | 房型ID | roomId, roomTypeId, basicRoomTypeId | - |
| room_type_name | 房型名称 | roomName, roomTypeName | - |
| cancel_rate | 取消率 | cancelRate | - |
| available_room | 可用房量 | canUseBlockRoom, availableRoom | - |
| total_room | 房型房量 | totalBlockRoom, roomCount | - |

### room_competing_hotels

- 模块：经营报告-房型
- 数据类型：business
- URL关键词：queryCompetingHotelsV2

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| competitor_hotel_name | 竞品酒店名称 | hotelName | - |
| distance | 距离 | distance | - |
| star_level | 星级 | starLevel | - |
| zone_name | 商圈 | zoneName | - |

### room_competitive_market

- 模块：经营报告-房型
- 数据类型：business
- URL关键词：fetchCompetitiveMarket

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### room_venderbility

- 模块：经营报告-房型
- 数据类型：business
- URL关键词：queryVendibilityRoom / queryVenderbilityRoom

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| sale_status | 售卖状态 | saleStatus, status | - |
| suggest_action | 建议操作 | suggestAction, action | - |

### traffic_scan_flow

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：queryScanFlowDetailsV2

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### traffic_flow_transform

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：queryFlowTransformNewV1 / queryFlowTransforNewV1 / queryFlowTransferNewV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### traffic_order_overview

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：fetchOrderOverView

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### traffic_order_trend

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：queryOrderTrendV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### traffic_flow_source

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：queryFlowSource / getRealTimeVisitorSourceV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### traffic_city_keywords

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：queryCityHotKeywords / queryQunarCityHotSearch

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### traffic_search_details

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：querySearchFlowDetails

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### traffic_hotel_min_price

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：queryHotelMinPriceV1

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| min_price | 实时起价 | minPrice | - |
| min_price_rank | 起价排名 | minPriceRank | - |

### traffic_picture_quality

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：getPictureQualityScore

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |

### traffic_comment_score_summary

- 模块：经营报告-流量数据
- 数据类型：traffic
- URL关键词：getCommentsScoreV2
- 备注：只采集评分汇总，不采集点评明文。

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |

### competitor_management

- 模块：竞争圈动态-概览
- 数据类型：business
- URL关键词：getManagementData

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### competitor_hotel_label

- 模块：竞争圈动态-概览
- 数据类型：business
- URL关键词：getMasterHotelLabel

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| hotel_label | 酒店标签 | label, labelName, tagName, hotelLabel, labelValue | - |

### competitor_flow

- 模块：竞争圈动态-概览
- 数据类型：business
- URL关键词：getFlowData

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### competitor_service

- 模块：竞争圈动态-概览
- 数据类型：business
- URL关键词：getServiceData

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |

### competitor_flow_source

- 模块：竞争圈动态-概览
- 数据类型：business
- URL关键词：getFlowSource

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### loss_order_summary

- 模块：竞争圈动态-流失分析
- 数据类型：business
- URL关键词：getTripartiteOrderLoss

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| loss_order_count | 流失订单量 | lossOrderCount, lossOrderNum, orderCount | - |
| loss_room_nights | 流失间夜量 | lossRoomNight, lossNightCount, roomNights | - |
| loss_order_amount | 流失订单金额 | lossOrderAmount, amount | - |
| common_view_rate | 共同浏览率 | commonViewRate, browseRate | - |
| order_conversion_rate | 下单转化率 | orderConversionRate, conversionRate | - |
| competitor_hotel_name | 流失酒店名称 | hotelName, competeHotelName | - |

### loss_compete_hotel

- 模块：竞争圈动态-流失分析
- 数据类型：business
- URL关键词：getLossOrderCompeteHotel

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| competitor_hotel_name | 流失酒店名称 | hotelName, competeHotelName | - |
| common_view_rate | 共同浏览率 | commonViewRate, browseRate | - |
| order_conversion_rate | 下单转化率 | orderConversionRate, conversionRate | - |
| follow_status | 关注状态 | followStatus, isFollow | - |

### competitor_rank

- 模块：竞争圈动态-榜单
- 数据类型：traffic
- URL关键词：getCompetingRank

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| rank_metric | 榜单指标 | rankType, metric, rankName | - |
| order_rank | 预订订单量排名 | orderRank, orderQuantityRank | - |
| amount_rank | 预订销售额排名 | amountRank, orderAmountRank | - |
| traffic_rank | 流量排名 | trafficRank, appDetailUvRank | - |

### user_profile_features

- 模块：用户行为-用户分析
- 数据类型：business
- URL关键词：queryUserFeatures / getUserImageList

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| user_sex | 用户性别 | sex, gender, userSex | - |
| user_age | 年龄段 | age, ageRange, userAge | - |
| user_source | 客源来源 | source, userSource, cityName | - |
| user_type | 用户类型 | userType, travelType | - |
| booking_days | 提前预订天数 | bookingDays, advanceDays, leadTime | - |
| stay_days | 入住天数 | stayDays, stayLength | - |
| price_band | 消费档位 | price, priceInfo, priceBand | - |
| strategy | 提升策略 | strategy, suggestion, imageList | - |

### user_profile_dimensions

- 模块：用户行为-用户分析
- 数据类型：business
- URL关键词：queryUserSex / queryUserType / queryUserPriceInfo / queryUserSource / queryUserBookingDays / queryUserStayDays / queryUserAge / queryUserPoint / queryUserTravelTime / queryUserStar / queryUserPrice / queryOrderType / queryUserOrders / getOrderDistribution

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| user_sex | 用户性别 | sex, gender, userSex | - |
| user_age | 年龄段 | age, ageRange, userAge | - |
| user_source | 客源来源 | source, userSource, cityName | - |
| user_type | 用户类型 | userType, travelType | - |
| booking_days | 提前预订天数 | bookingDays, advanceDays, leadTime | - |
| stay_days | 入住天数 | stayDays, stayLength | - |
| price_band | 消费档位 | price, priceInfo, priceBand | - |
| strategy | 提升策略 | strategy, suggestion, imageList | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### im_index

- 模块：用户行为-IM看板
- 数据类型：quality
- URL关键词：getImIndex

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| five_min_reply_rate | 5分钟回复率 | replyRate5m, fiveMinReplyRate, replyRate | - |
| manual_reply_rate | 5分钟人工回复率 | manualReplyRate, humanReplyRate | - |
| robot_resolution_rate | 机器人解决率 | robotResolutionRate, robotResolveRate | - |
| im_rank | IM竞争圈排名 | rank, rank2 | - |

### im_trend

- 模块：用户行为-IM看板
- 数据类型：quality
- URL关键词：getImDateDistribute / getImSessionDistribute / getImOrderConversionRateByDay / getImOrderConversionDetail

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| session_count | 会话量 | sessionCount, totalSession, conversationCount | - |
| manual_session_count | 人工会话量 | manualSessionCount, humanSessionCount | - |
| robot_session_count | 机器人会话量 | robotSessionCount | - |
| im_order_conversion_rate | IM客人转化率 | orderConversionRate, conversionRate | - |

### ads_summary_report

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：queryCampaignSummaryReport

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |

### ads_report_list

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：queryCampaignReportList

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |

### ads_click_live

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：queryCpcClickLive

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |

### ads_diagnosis

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：queryPyramidCpcDiagnosis / fetchPyramidCpcDiagnosis

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |

### ads_diagnostic_details

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：getCpcDiagnosticDetails

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |

### ads_interpretation

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：fetchCpcDataReportInterpretation

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |

### ads_peer_comparison

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：getPeerComparisonInfoDetail / getHotelZoneName

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |
| peer_avg | 同行平均 | peerAvg, avg | - |
| peer_top | 同行头部 | peerTop, top | - |

### ads_filters

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：queryBasePremiumFilterList / queryPromotionKeywords / getDspAccounts / getCpcCampaignList

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| ad_impressions | 广告曝光 | impression, impressions, exposure, showCount | - |
| ad_clicks | 广告点击 | click, clicks, clickCount | - |
| ad_cost | 广告花费 | todayCost, cost, cashCost, bonusCost, spend, amount | - |
| ad_order_amount | 广告预订金额 | orderAmount, saleAmount, revenue | - |
| ad_orders | 广告预订订单 | orderCount, bookingCount, bookings | - |
| ad_room_nights | 广告预订间夜 | roomNights, nights, quantity | - |
| ctr | 点击率 | ctr, clickRate | - |
| cvr | 转化率 | cvr, conversionRate | - |
| roas | 广告投产比ROAS | roas, roi | - |
| campaign_id | 推广计划ID | campaignId, campaign_id | - |
| diagnosis_text | 诊断建议 | diagnosis, suggestion, interpretation, tasktext | - |

### ads_resource_yellow_bar

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：getEbkResourceYellowBar

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| notice_title | 提示标题 | title, name, noticeTitle | - |
| notice_text | 提示内容 | content, message, text, tips, tip, description, desc | - |
| config_name | 配置名称 | configName, configKey, key, code | - |
| config_value | 配置值 | configValue, value | - |
| target_url | 页面跳转地址 | targetUrl, url | - |

### ads_dynamic_config

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：getDynamicConfig

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| notice_title | 提示标题 | title, name, noticeTitle | - |
| notice_text | 提示内容 | content, message, text, tips, tip, description, desc | - |
| config_name | 配置名称 | configName, configKey, key, code | - |
| config_value | 配置值 | configValue, value | - |
| target_url | 页面跳转地址 | targetUrl, url | - |

### ads_report_injection

- 模块：金字塔推广
- 数据类型：advertising
- URL关键词：reportInjectFnInfo

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| notice_title | 提示标题 | title, name, noticeTitle | - |
| notice_text | 提示内容 | content, message, text, tips, tip, description, desc | - |
| config_name | 配置名称 | configName, configKey, key, code | - |
| config_value | 配置值 | configValue, value | - |
| target_url | 页面跳转地址 | targetUrl, url | - |

### psi_overview

- 模块：PSI服务质量分
- 数据类型：quality
- URL关键词：getHotelPsiV2

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |

### psi_growth_task

- 模块：PSI服务质量分
- 数据类型：quality
- URL关键词：queryPsiGrowthTaskList / queryRewardScoreActivityList

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |
| task_name | 提分任务 | taskName, title | - |
| task_action | 行动入口 | action, targetUrl | - |

### psi_history

- 模块：PSI服务质量分
- 数据类型：quality
- URL关键词：queryHistPsiScoreList

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| psi_score | PSI服务质量分 | psi, PSI, psiScore, qualityscore, serviceScore | - |
| base_score | 基础分 | baseScore, basicScore | - |
| reward_score | 奖励分 | rewardScore, bonusScore | - |
| deduct_score | 减分项 | deductScore, penaltyScore | - |
| reply_rate | 5分钟回复率 | replyrate5m, replyRate, fiveMinuteReplyRate | - |
| im_score | IM评分 | imScore | - |
| hotel_collect | 酒店收藏数 | hotelCollect, favoriteCount, collectCount | - |
| comment_score_summary | 点评分汇总 | ctripRatingall, qunarRatingall, HotelRating, ratingall | - |

### psi_course

- 模块：PSI服务质量分
- 数据类型：quality
- URL关键词：getRecommendedCourseBy

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| course_title | 推荐课程 | title, courseTitle | - |
| course_url | 课程链接 | url, targetUrl | - |

### hot_calendar

- 模块：热点日历
- 数据类型：business
- URL关键词：queryHotCalendarInfo

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| hot_spot_name | 热点名称 | hotSpotName | - |
| start_date | 开始日期 | startDate | - |
| end_date | 结束日期 | endDate | - |

### biztravel_bpi_overview

- 模块：携程商旅-BPI分
- 数据类型：quality
- URL关键词：searchBpiOverview

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| bpi_score | BPI总分 | bpiScore, score, totalScore | - |
| basis_score | 基础分 | baseScore, basicScore | - |
| plus_score | 加分 | plusScore, bonusScore, rewardScore | - |
| minus_score | 减分 | minusScore, deductScore | - |
| agreement_accept_rate | 协议酒店接单率 | acceptRate, orderReceivingRate | - |
| business_room_nights | 商旅间夜 | roomNights, occupiedRooms, nightNum | - |
| business_amount | 商旅营业额 | amount, orderAmount, businessAmount, saleAmount | - |
| business_commission_rate | 商旅佣金率 | commissionRate, commission_rate | - |

### biztravel_bpi_benefit

- 模块：携程商旅-BPI分
- 数据类型：quality
- URL关键词：benefitInfoList

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| benefit_name | 权益名称 | benefitName, name, title | - |
| benefit_status | 权益状态 | benefitStatus, status | - |
| benefit_text | 权益说明 | content, description, desc | - |
| target_url | 页面跳转地址 | targetUrl, url | - |

### biztravel_bpi_table

- 模块：携程商旅-BPI分
- 数据类型：quality
- URL关键词：getBbkComprehensiveTable

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| bpi_score | BPI总分 | bpiScore, score, totalScore | - |
| basis_score | 基础分 | baseScore, basicScore | - |
| plus_score | 加分 | plusScore, bonusScore, rewardScore | - |
| minus_score | 减分 | minusScore, deductScore | - |
| agreement_accept_rate | 协议酒店接单率 | acceptRate, orderReceivingRate | - |
| business_room_nights | 商旅间夜 | roomNights, occupiedRooms, nightNum | - |
| business_amount | 商旅营业额 | amount, orderAmount, businessAmount, saleAmount | - |
| business_commission_rate | 商旅佣金率 | commissionRate, commission_rate | - |

### biztravel_bpi_rank

- 模块：携程商旅-BPI分
- 数据类型：quality
- URL关键词：searchBpiHotelRank

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| bpi_score | BPI总分 | bpiScore, score, totalScore | - |
| basis_score | 基础分 | baseScore, basicScore | - |
| plus_score | 加分 | plusScore, bonusScore, rewardScore | - |
| minus_score | 减分 | minusScore, deductScore | - |
| agreement_accept_rate | 协议酒店接单率 | acceptRate, orderReceivingRate | - |
| business_room_nights | 商旅间夜 | roomNights, occupiedRooms, nightNum | - |
| business_amount | 商旅营业额 | amount, orderAmount, businessAmount, saleAmount | - |
| business_commission_rate | 商旅佣金率 | commissionRate, commission_rate | - |

### biztravel_bpi_trend

- 模块：携程商旅-BPI分
- 数据类型：quality
- URL关键词：searchBpiScoreTrend / bpiScoreTrendFilterList

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| bpi_score | BPI总分 | bpiScore, score, totalScore | - |
| basis_score | 基础分 | baseScore, basicScore | - |
| plus_score | 加分 | plusScore, bonusScore, rewardScore | - |
| minus_score | 减分 | minusScore, deductScore | - |
| agreement_accept_rate | 协议酒店接单率 | acceptRate, orderReceivingRate | - |
| business_room_nights | 商旅间夜 | roomNights, occupiedRooms, nightNum | - |
| business_amount | 商旅营业额 | amount, orderAmount, businessAmount, saleAmount | - |
| business_commission_rate | 商旅佣金率 | commissionRate, commission_rate | - |

### biztravel_business_report

- 模块：携程商旅-经营报告
- 数据类型：business
- URL关键词：dataCenterBusinessReportDetail

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| bpi_score | BPI总分 | bpiScore, score, totalScore | - |
| basis_score | 基础分 | baseScore, basicScore | - |
| plus_score | 加分 | plusScore, bonusScore, rewardScore | - |
| minus_score | 减分 | minusScore, deductScore | - |
| agreement_accept_rate | 协议酒店接单率 | acceptRate, orderReceivingRate | - |
| business_room_nights | 商旅间夜 | roomNights, occupiedRooms, nightNum | - |
| business_amount | 商旅营业额 | amount, orderAmount, businessAmount, saleAmount | - |
| business_commission_rate | 商旅佣金率 | commissionRate, commission_rate | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |

### biztravel_competitor_report

- 模块：携程商旅-竞争圈概览
- 数据类型：business
- URL关键词：dataCenterComparisonReportDetail / dataCenterComparatorReportDetail

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| bpi_score | BPI总分 | bpiScore, score, totalScore | - |
| basis_score | 基础分 | baseScore, basicScore | - |
| plus_score | 加分 | plusScore, bonusScore, rewardScore | - |
| minus_score | 减分 | minusScore, deductScore | - |
| agreement_accept_rate | 协议酒店接单率 | acceptRate, orderReceivingRate | - |
| business_room_nights | 商旅间夜 | roomNights, occupiedRooms, nightNum | - |
| business_amount | 商旅营业额 | amount, orderAmount, businessAmount, saleAmount | - |
| business_commission_rate | 商旅佣金率 | commissionRate, commission_rate | - |
| order_count | 预订订单数 | orderQuantity, bookOrderNum, orderCount, orderNum, ordquantity, bookingCount | OTA预订订单量 |
| room_nights | 间夜量 | quantity, roomNights, nightNum, occupiedRooms, checkOutQuantity | OTA间夜或在店间夜 |
| order_amount | 预订销售额 | amount, orderAmount, saleAmount, ordamount, totalAmount, bookingAmount | OTA预订或离店销售额 |
| avg_price | 平均卖价 | averagePrice, avgPrice, adr, minPrice | OTA均价或起价 |
| conversion_rate | 成交/下单转化率 | orderConversionRate, closeRate, conversionRate, convertionRate, cvr | 从流量到订单的转化 |
| occupancy_rate | 出租率 | rentalRate, occupancyRate | - |
| tensity | 紧张度 | tensityScore, tensity, Tensity, nowTensityDetail | - |
| rank | 竞争圈排名 | rank, rank2, visitorRank, rankOfAmount, rankOfOrderQuantity, competitorRank, ranking | - |
| competitor_average | 竞争圈平均值 | competitorsAverageOrderQuantity, competitorsAverageOccupiedRooms, competitorAvgNumber, competitorTensityScore | - |
| visitor_count | 访客量 | visitorTotal, UV, uv, visitorCount, pv, pageViews | - |
| list_exposure | 列表页曝光量 | listExposure, exposure, exposureCount, impressions | - |
| detail_visitor | 详情页访客量 | detailExposure, detailUv, detailVisitors | - |
| order_page_visitor | 订单页访客量 | orderFillingNum, orderVisitors, fillUsers | - |
| order_submit_user | 订单提交人数 | orderSubmitNum, submitUsers, submitNum | - |
| flow_rate | 流量转化率 | flowRate, transforRate, transferRate, convertRate | - |
| source_name | 流量来源 | sourceName, sourceNameTag | - |
| keyword | 搜索关键词 | keyword, searchKeyword, filterWords | - |

### biztravel_notice

- 模块：携程商旅-经营报告
- 数据类型：business
- URL关键词：announcementInfoGet

| 标准字段 | 中文名 | 来源字段 | 说明 |
|---|---|---|---|
| hotel_id | 酒店ID | hotelId, hotel_id, masterHotelId, masterhotelid, hotelID, nodeId | - |
| hotel_name | 酒店名称 | hotelName, hotel_name, name | - |
| date | 日期 | date, dataDate, effectDate, effectTime, statDate, startDate, endDate, updateTime | - |
| announcement | 公告提示 | content, message, title | - |
