# 酒店收益成功实践延伸知识

## 0. 资料新鲜度纠偏（2026-07-30）

- 活跃知识的主证据窗口调整为 **2025-01-01 至 2026-07-30**；更早资料只保留历史追溯价值。
- 2021 年携程案例、2017 年 Duetto 案例，以及 2024 年美团案例已从活跃检索中降级为 `lifecycle_status = stale`、`evidence_state = historical_superseded`，不再支撑当前默认判断。
- 当前主证据优先级为：监管或行业协会一手材料 > 有样本说明的行业数据 > 平台当前能力说明 > 供应商客户案例。
- “页面仍在线”不等于资料仍新；“产品具备能力”不等于酒店已经取得经营结果；“供应商报告增长”不等于单一因果已被独立验证。

## 1. 本次吸收目标

本知识不是“如何把携程、美团、PMS 数据上传到网上”，而是回答：

> 宿析已经拥有携程、美团与 PMS 事实后，还能从行业成功实践中学到哪些新的经营判断与行动方法。

本次只补现有知识的缺口，不重复建立已有的流量漏斗、指标语义、价格实验、房型角色、渠道净收益、OTB/Pickup、保护线、止损、回滚和复盘规则。

## 2. 适用边界

- 携程、美团数据只证明对应 OTA 渠道内的曝光、浏览、订单、间夜、活动和渠道收入。
- PMS 数据可作为全酒店客房经营事实来源，但必须核验门店、经营日、房型、订单状态、取消、入住和离店口径。
- POS、商城、餐饮、体验项目等数据只有在独立来源已核验时，才能进入总收益和贡献利润。
- 外部平台、供应商和商家案例中的数字只作 `case_reference`，必须显式指定 `case_key` 才能读取。
- 本知识只生成经营诊断和人工复核建议，不直接执行 OTA 改价、开关房、库存、入住时长限制或投流。

## 3. 去重结论

| 外部经验 | 宿析已有知识 | 本次处理 |
|---|---|---|
| 内容、榜单、直播、活动带来流量 | `traffic_funnel_contract` 已覆盖曝光到贡献利润与入口归因 | 合并，不新增第二套流量漏斗 |
| 分房型、分客群、分日期动态定价 | `price_experiment_room_roles`、`房型角色方法` 已覆盖单变量实验与房型角色 | 合并，只保留外部案例 |
| 调整渠道和客群结构 | `渠道收益诊断` 已覆盖净 ADR、佣金、取消、提前期和房型结构 | 合并，不把渠道份额写成成功公式 |
| OTB、Pickup 和相邻快照变化 | `OTB与Pickup规则`、经营目标差值检测已覆盖 | 补强为“同入住日、同提前天数”的预订曲线与预测误差学习 |
| PMS 与 RMS 联动 | 现有知识已要求 PMS/OTA 范围分离和保存回读 | 只吸收决策方法，不把宿析扩成 PMS/CRS 执行系统 |
| 体验型产品带来房费外收入 | 现有指标有 TRevPAR 定义，但缺少可执行判断合同 | 新增“体验产品与总收益”方法 |

## 4. 新增知识一：预订曲线与预测误差学习

### 4.1 要解决的问题

只看今天比昨天多了多少订单，无法回答某个入住日是否真的落后或超前。可比对象必须是：

- 同一门店；
- 同一入住日或同类需求日；
- 同一提前预订天数；
- 同一事实范围与指标口径；
- 同一来源质量等级。

### 4.2 必需输入

`stay_date`、`snapshot_date`、`days_before_arrival`、`otb_room_nights`、`otb_room_revenue`、`pickup`、`cancellations`、`remaining_sellable_rooms`、`room_type`、`market_segment`、`channel`、`demand_date_type`、`source_method`、`quality_status`。

### 4.3 判断合同

1. 预订曲线按入住日回看，不按采集日简单横比。
2. 只有历史样本在星期、节假日、事件、房量和销售范围上可比，才计算节奏差。
3. 取消累计缺失时，净 Pickup 与毛新增预订保持分离，不用猜测值补齐。
4. 入住日结束后保存预测值与实际入住间夜、实际房费收入，计算预测误差。
5. 样本不足时输出 `experimental_rule`，不输出“应该提价/降价”的确定结论。

### 4.4 可派生指标

- `pace_gap_room_nights = current_otb - comparable_curve_otb`
- `forecast_error_room_nights = actual_stayed_room_nights - forecast_room_nights_as_of`
- `forecast_error_revenue = actual_room_revenue - forecast_room_revenue_as_of`
- `forecast_ape = abs(forecast_error) / actual`；实际值为 0 或缺失时返回 `null`

### 4.5 行动输出

只允许输出：保持、补数、检查房型/渠道结构、建立小范围价格或产品实验、调整人工复核时间。不得直接改价或改库存。

## 5. 新增知识二：稀缺库存的订单总价值与挤出判断

### 5.1 要解决的问题

接近满房时，“再来一个订单”不一定更好。接受一个低价值或跨越旺日的订单，可能挤出后续更高价值需求。判断对象应从单晚房价升级为整笔订单的净价值与机会成本。

### 5.2 必需输入

`stay_dates`、`room_type`、`remaining_inventory_by_date`、`length_of_stay`、`net_room_revenue`、`verified_ancillary_revenue`、`commission`、`variable_cost`、`cancellation_probability`、`no_show_probability`、`comparable_pickup_curve`、`expected_higher_value_demand`、`execution_permission`。

### 5.3 判断合同

1. 先核对整段入住日期是否占用稀缺旺日，再看整笔订单净价值，不能只看首晚价格。
2. `net_booking_value` 至少扣除佣金和可归属变动成本；附加收入未经核验不得计入。
3. `displacement_cost` 是接受当前订单可能挤出的后续净贡献，只能在有可比历史或有效预测时估计。
4. 最后一间房价值、最短入住、到店限制等只能形成“人工复核建议”。
5. 没有逐日房量、取消/未到风险或执行权限时，状态为 `blocked`，不得自动生成库存控制动作。
6. 禁止整月统一设置限制；必须按入住日、房型和需求状态逐段判断，并同时观察肩部日期损失。

## 6. 新增知识三：体验产品与总收益

### 6.1 要解决的问题

特色体验、套餐或附加服务可能同时带来：

- 更强的 OTA 点击与预订理由；
- 更高的客房价格承载；
- 房费外收入；
- 额外成本、产能和服务风险。

因此不能只看房费，也不能把总收入直接写成利润改善。

### 6.2 必需输入

`product_or_package_id`、`stay_date`、`ota_exposure`、`detail_views`、`bookings`、`stayed_bookings`、`room_revenue`、`ancillary_revenue`、`direct_product_cost`、`incremental_labor_cost`、`commission`、`refunds`、`capacity`、`guest_feedback`、`comparison_baseline`。

### 6.3 判断合同

1. 体验必须与目的地需求、客群和房型承接一致，不把普通赠品包装成差异化。
2. OTA 负责记录内容到预订的漏斗；PMS 负责入住和房费；POS/商城/人工台账负责附加收入与成本，三者不能互相代替。
3. 先做限定日期、限定房型、限定产能的小实验。
4. 同时观察附加购买率、总收入、净贡献、取消、差评和服务产能。
5. 没有直接成本和可比基线时，只能写“收入增加”，不能写“利润提升”或“由该体验导致”。

### 6.4 可派生指标

- `ancillary_attach_rate = ancillary_purchasing_stayed_bookings / stayed_bookings`
- `total_revenue = room_revenue + verified_ancillary_revenue`
- `net_total_revenue = total_revenue - commission - refunds`
- `incremental_contribution = incremental_total_revenue - incremental_direct_cost - incremental_labor_cost`

分母为 0、字段缺失或来源未核验时返回 `null`。

## 7. 2025—2026 活跃证据目录

### 7.1 深圳美高梅：携程、美团等 OTA 对账与 PMS 业财融合

- `case_key`: `shiji_shenzhen_mgm_ota_reconciliation_2025`
- 中国旅游饭店业协会与石基信息发布的《2026年中国酒店业数字化转型趋势报告》基于 577 份有效问卷，并收录酒店集团专家实践。
- 报告记载：深圳美高梅酒店在 2025 年试运营 OTA 与银行自动对账，对接携程、美团、飞猪及境外 OTA 直连渠道；同时把 PMS、POS、成本采购与 OA 数据接入 BI 审计看板，用于识别异常折扣、违规减免、库存预警、免费房合规和 Upsell 绩效等问题。
- 可吸收：**先对账、再分析；先统一编码和业务规则、再汇集数据；看板必须能生成异常清单和预警，而不是只展示指标。**
- 不能吸收：报告未披露人工差错率下降的具体数值、项目成本、回收期或对照组；试运营也不等于已在全部集团酒店完成推广。
- 来源：[《2026年中国酒店业数字化转型趋势报告》](https://pdf.dfcfw.com/pdf/H3_AP202603311820910990_1.pdf?1774972249000.pdf=)

### 7.2 保利商旅：统一数据底座不替代单店判断

- `case_key`: `shiji_poly_business_finance_data_2026`
- 同一报告记载：保利商旅把财务数据补上业务属性，建立统一数据语言，并同步推进培训与机制调整；集团收益管理是在统一数据底座上提供需求预测、价格和渠道结构参考，不替代单店判断。
- 可吸收：**系统建设、数据标准、人员训练和单店决策权必须同时存在；没有数据连接，不能宣称已经实现管理输出。**
- 不能吸收：2026 年部分托管酒店直连仍是计划，不得写成已经全部完成。
- 来源：[《2026年中国酒店业数字化转型趋势报告》](https://pdf.dfcfw.com/pdf/H3_AP202603311820910990_1.pdf?1774972249000.pdf=)

### 7.3 携程：云顶世界酒店与主题乐园预订系统直连

- `case_key`: `tripcom_resorts_world_genting_api_2025`
- Trip.com 于 2025-07-09 披露与 Resorts World Genting 的合作包含酒店和主题乐园预订系统的直接 API 集成。
- 可吸收：当酒店产品包含门票、体验或套餐时，库存和权益应按同一产品标识、使用日期与核销状态贯通，避免只记录客房订单。
- 已知未知：来源没有披露单店收入、入住、佣金、退款、核销成功率或利润变化，因此它是**当前集成实践**，不是经营结果证明。
- 来源：[Trip.com Group / Resorts World Genting](https://www.trip.com/newsroom/resorts-world-genting-and-trip-com-group-strengthen-partnership-with-dual-memoranda-of-understanding-to-elevate-malaysias-inbound-tourism/)

### 7.4 美团酒店管理系统：当前能力已知，经营结果未知

- `case_key`: `meituan_hms_current_capability_2025`
- 美团酒店管理系统当前官网声明支持美团订单深度直连、库存实时同步、PMS 统一修改价量态，并称已有超过 100,000 家酒店使用。
- 可吸收：价、量、态和订单需要同一门店、同一房型映射与回读状态，避免“平台已改、PMS 未回写”。
- 已知未知：这是美团产品页自述，未给出酒店级前后对照、取消率、净收入、利润或数据准确率，不能作为“接入即可增收”的证据。
- 来源：[美团酒店管理系统官网](https://hms.meituan.com/)

### 7.5 SiteMinder：2025 年 1.35 亿笔预订的渠道价值参照

- `case_key`: `siteminder_booking_trends_2025`
- SiteMinder 基于 20 个成熟目的地、超过 1.35 亿笔预订披露：2025 年酒店官网每笔预订均值为 516 美元，OTA 为 312 美元；平均提前预订期为 32.15 天，取消率为 19.15%。
- 可吸收：渠道评价应联合观察订单价值、入住时长、附加消费、取消和获客成本，不能只看订单份额。
- 不能吸收：这些是国际市场聚合均值，不是中国酒店或当前门店阈值，也不能直接证明直销一定更盈利。
- 来源：[SiteMinder Hotel Booking Trends](https://www.siteminder.com/hotel-booking-trends/)

### 7.6 Cloudbeds：2026 独立酒店预订窗口与 OTA 依赖参照

- `case_key`: `cloudbeds_independent_hotels_2026`
- Cloudbeds 的 2026 报告覆盖 180 个国家、9,000 万笔预订及数万家独立酒店；其页面披露 2025 年平均预订窗口为 40 天、平均取消窗口为 38.7 天、平均入住 2.6 晚，7—13 晚订单同比增长 25%。
- 可吸收：预订与取消窗口应分别建模；更早预订不等于更早锁定收入，仍需保留取消、重售和净 Pickup。
- 不能吸收：全球独立酒店样本不能替代当前酒店的同入住日历史曲线，聚合比例不能成为自动改价阈值。
- 来源：[Cloudbeds 2026 State of Independent Hotels](https://www.cloudbeds.com/hospitality-industry-report/)

### 7.7 中国住宿业消费指数：入住增长不能替代价格与利润

- `case_key`: `china_hotel_hci_2025_12`
- 中国饭店协会于 2026-03-16 发布的 2025 年 12 月 HCI 显示：入住率指数环比增幅高于平均房价指数，报告据此判断行业仍有“增收不增利”压力；数据来自平台数据以及约 30 个酒店集团、110 家门店调研。
- 可吸收：需求回暖必须同时检查 ADR、收入结构、渠道成本和利润；行业指数只作为需求环境，不替代门店实绩。
- 不能吸收：指数不是当前酒店目标值，也不覆盖康养、娱乐等全部收入。
- 来源：[中国住宿业消费指数报告（2025年12月）](https://www.chinahotel.org.cn/articles/17644)

### 7.8 Jannah Hotels & Resorts：2025 年 RMS 供应商客户案例

- `case_key`: `duetto_jannah_2025`
- Duetto 于 2025-01-27 披露：Jannah 五家酒店在接入 Opera PMS、TravelClick CRS/预订引擎并使用自动调价与预测工具后，供应商报告组合 RevPAR 增长 22%、ADR 增长 4.8%，同时减少人工任务。
- 可吸收：技术选择需同时验证预测准确性、易用性、报表、ROI 与 PMS/CRS 兼容性；结果应按组合和单店分开。
- 不能吸收：这是供应商客户案例，缺少独立审计、完整成本和匹配对照，比例不得跨店套用。
- 来源：[Duetto / Jannah Hotels & Resorts](https://www.duettocloud.com/en-us/success-stories/22-revpar-increase-for-uae-based-jannah-hotels-resorts-portfolio?hs_amp=true)

### 7.9 Terrace Bay Hotel：2025 年动态定价与附加销售案例

- `case_key`: `mews_terrace_bay_2025`
- Mews 于 2025-12-02 披露：117 间房的 Terrace Bay Hotel 用实时定价替代每日两次人工改价，并结合直销投放与自动宾客消息；供应商报告平均房价提升 20%—25%，餐位、升级和延迟退房等 Upsell 收入增加。
- 可吸收：实时需求、价格、直销和附加销售应拆开归因；收入变化要继续验证佣金、广告、人工和服务成本。
- 不能吸收：来源未给出 Upsell 的具体增量、完整基线和独立审计，不能把全部增长归因于 PMS 或自动调价。
- 来源：[Mews / Terrace Bay Hotel](https://www.mews.com/en/blog/hotel-revenue-optimization)

## 8. 历史案例（已从活跃知识降级）

以下记录保留审计追溯，但 `lifecycle_status = stale`、`evidence_state = historical_superseded`，不再进入默认检索，也不能继续作为“当前成功经验”的主证据。

### 8.1 美团：洛阳汉服酒店（2024）

- `case_key`: `meituan_luoyang_hanfu_hotel_2024`
- `superseded_by`: `shiji_shenzhen_mgm_ota_reconciliation_2025`、`meituan_hms_current_capability_2025`
- 美团于 2024-04-26 报道：约 30 间房的洛见·汉服·观影民宿酒店开业半年后进入同商圈流量前五，平均入住率约 80%；2024 年 2 月整店营收超过 10 万元，汉服附加营收超过 2 万元。
- 可吸收：目的地文化需求 × 住宿产品 × 付费附加服务的总收益设计。
- 不可吸收：80% 入住率、收入、投资回收期不能作为其他酒店目标；报道未给出完整成本、取消、渠道结构和对照组。
- 来源：[美团新闻中心，2024-04-26](https://www.meituan.com/zh-HK/news/NN240426050008430)

### 8.2 携程：温德姆 919 超级品牌日（2021）

- `case_key`: `tripcom_wyndham_919_campaign_2021`
- `superseded_by`: `tripcom_resorts_world_genting_api_2025`
- Trip.com Group 于 2021-11-18 报道：丽江金林温德姆至尊豪廷度假酒店在携程 919 超级品牌日的单店总销售额为 1,200 万元，并列为单店类最高销量。
- 可吸收：大型活动、直播、品牌产品与明确库存组合可以形成短期销售峰值。
- 不可吸收：销售额不是入住、净收入或利润；单次活动不能替代日常需求与长期价格策略。
- 来源：[Trip.com Group Newsroom，2021-11-18](https://www.trip.com/newsroom/trip-com-group-and-wyndham-hotels-resorts-sign-strategic-global-agreement/)

### 8.3 NH Hotel Group：业务结构、系统与总收益（2017）

- `case_key`: `duetto_nh_hotel_group_2017`
- `superseded_by`: `duetto_jannah_2025`
- Duetto 案例称：NH Hotel Group 更新 PMS、CRS、RMS，重做细分、品牌和业务结构；2017 年相较 2016 年 RevPAR 增长 8.5%、ADR 增长 4.9%、入住率增长 3.4%。
- 可吸收：先识别低价值业务占用，再从 RevPAR 升级到 TRevPAR、净 TRevPAR。
- 不可吸收：供应商案例的前后对比不构成单一因果证明，比例不能跨店套用。
- 来源：[Duetto / NH Hotel Group case study](https://www.duettocloud.com/hubfs/case-study-NH-Hotel-2023.pdf)

### 8.4 Nira Caledonia：小体量酒店的前瞻预测与业务结构（2017）

- `case_key`: `duetto_nira_caledonia_2017`
- `superseded_by`: `duetto_jannah_2025`、`cloudbeds_independent_hotels_2026`
- Duetto 案例称：28 间房的 Nira Caledonia 采用分客群定价、提前预测和业务结构调整；2017 年 6 月相较 2016 年 6 月 RevPAR 增长 24.6%、ADR 增长 18.4%，收益会议由 2 小时缩短至 20 分钟。
- 可吸收：小团队把时间从抄表转向预测、分层和实验。
- 不可吸收：单月同比与供应商实施同时发生，不足以证明全部增长来自系统或某一动作。
- 来源：[Duetto / Nira Caledonia case study](https://www.duettocloud.com/hubfs/case-study-Nira-Caledonia.pdf)

## 9. 旧方法来源（仅保留历史追溯）

- 携程平台产品、榜单、内容与交易案例：[Trip.com Group 2022 Global Partner Summit](https://www.trip.com/newsroom/cooperating-with-partners-to-ensure-the-continual-evolution-of-travel/)
- 美团 PMS 的直销、分销、经营分析和收益复盘能力：[美团酒店管理系统单体酒店方案](https://hms.meituan.com/home/industry-solution/individual)
- 预订曲线、细分、预测与收益管理方法：[Cornell Hotel Revenue Management introduction](https://ecommons.cornell.edu/bitstreams/e15688e4-4b0e-437f-9c39-aa507f9a0b1f/download)
- 最后一间房价值与入住时长控制的 PMS 决策方法：[IDeaS / Stayntouch，2026-05-05](https://ideas.com/news/ideas-expands-integration-with-stayntouch-pms-lrv/)
- 分房型、分客群、分入住日观察实时 Pickup 的案例：[Duetto / Ovolo Hotels](https://www.duettocloud.com/hubfs/case-study-ovolo-hotels.pdf?hsLang=en-us)

## 10. 由新资料补强的活跃知识

### 10.1 对账先于诊断

携程、美团或其他 OTA 数据进入经营判断前，至少要保存：

`platform`、`hotel_id`、`business_date`、`order_id`、`room_type_mapping`、`gross_amount`、`commission`、`subsidy`、`refund`、`settlement_amount`、`pms_readback_status`、`reconciliation_status`、`exception_reason`。

未完成订单、金额和结算对账时，只能输出 `partial` 或异常清单，不能把平台成交额写成 PMS 已入住收入。

### 10.2 标准化先于多系统汇集

接入 PMS、POS、采购成本、OA 或 OTA 前，先统一门店、房型、产品、收入科目、优惠、订单状态和经营日规则。未统一时保留源值与映射状态，不在汇总层静默修正。

### 10.3 看板必须导向异常和行动

成功的看板不是“多展示几个指标”，而是能把跨系统差异转成：

- 待补数；
- 待对账；
- 异常折扣或减免；
- 库存或权益映射错误；
- 渠道净收入偏差；
- 需要人工确认的价格、产品或服务动作。

每条行动必须带事实来源、差距、保护线、停止条件和回滚方式。

### 10.4 集团建议不替代单店判断

集团或 AI 可提供预测、价格和渠道结构参考，但最终判断必须绑定目标门店、入住日、当前库存、订单结构、当地事件、数据质量和人工权限。数据标准与培训是系统价值转化为组织能力的必要条件。

### 10.5 平台定价自主权保护

国家市场监督管理总局 2026-07-25 公布的携程案件明确涉及独家合作、“全网最低价”和平台直接调价对酒店自主经营权的限制。宿析不得把以下做法吸收为成功经验：

- 以流量交换跨平台独家；
- 把“全网最低价”设为默认经营目标；
- 允许平台或算法在没有酒店明确授权、保护线和回读的情况下直接降价；
- 因平台排名或挂牌状态而忽略净收入、利润和多渠道韧性。

宿析只生成可解释、可回滚、待人工确认的建议，并保留平台、原价、建议价、授权人、执行时间与回读结果。来源：[国家市场监督管理总局，2026-07-25](https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html)

## 11. 明确拒绝吸收

- “入驻某平台就能从亏损变盈利”。
- “曝光、榜单、直播销量可以直接代表酒店利润”。
- “某案例的 ADR、RevPAR、入住率或销售额可以作为所有门店阈值”。
- “供应商案例的上线前后变化已经证明单一因果”。
- “没有 PMS 逐日库存、取消、未到和权限，也可以自动设置最短入住或关房”。
- “房费外收入存在，就可以忽略成本、产能、退款和客诉”。
- “产品页写着直连、实时或智能，就等于当前门店已经采集成功、保存成功并产生收益”。
- “全球或行业聚合均值可以直接作为当前门店自动改价、投流或渠道份额阈值”。
- “全网最低价、跨平台独家或平台无授权自动调价是酒店成功经营的必要条件”。

## 12. 宿析调用规则

默认知识检索只返回第 4、5、6、10、11 节的通用方法和边界；第 7 节案例默认排除。只有用户明确要求查看某个案例，且调用方提供完全匹配的活跃 `case_key`，才返回对应案例事实。

第 8 节历史 `case_key` 已退出活跃检索；请求旧键时返回 `partial` 与“资料已被新证据替代”，数据库中仅保留审计记录。所有外部数字必须同时展示来源年份、证据类型和不可迁移边界。
