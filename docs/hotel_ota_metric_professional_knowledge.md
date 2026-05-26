# 酒店OTA专业指标口径知识库

## 资料融合原则

本文件把项目既有 `OTA标准指标与推荐公式清单`、`OTA运营思维导图2.0知识沉淀` 与联网检索到的行业/平台公开口径合并，用于宿析OS的指标解释、AI诊断、日报和运营任务拆解。

融合规则：

- 行业通用指标采用酒店行业或平台公开公式优先，例如 ADR、入住率、RevPAR、CTR、转化率。
- OTA 平台私有指标保留运营含义，不编造未公开权重，例如携程服务质量分、美团 HOS、飞猪 MCI。
- 旧资料中写作 `MIC` 的飞猪指标，与公开资料常见写法 `MCI` 合并为 `fliggy_mci`，保留 `MIC` 作为历史别名。
- 平台规则、排名、广告位、扣分项、权益和私有评分算法会变化，系统只沉淀解释与诊断口径，执行前必须以当前后台为准。
- 宿析OS不因本次知识深化新增业务表，指标优先落在 `knowledge_units`、`knowledge_chunks`、`knowledge_base`，计算字段仍复用现有 `online_daily_data` 和 `raw_data`。

## 外部参考

| 来源 | 可采用口径 | 宿析OS使用方式 |
| --- | --- | --- |
| Oracle OPERA Cloud Inventory and Rate Availability | Room Available、Occupancy Forecast、ADR、RevPAR、Rooms Sold 的酒店 PMS 口径 | 作为 ADR、入住率、RevPAR 的行业公式依据 |
| Google Ads Help | CTR = clicks / impressions；Conversion rate = conversions / interactions | 作为 OTA 流量、广告和详情页转化的通用口径 |
| Google Hotel Center Help | Hotel Center 报告展示 impressions、clicks、CTR、price bucket、price difference percent、booking window、length of stay 等 | 作为酒店搜索/预订链接流量、价格竞争力、提前预订窗口和入住天数维度依据 |
| Booking.com Connectivity Reservations API | reservation 表示一个或多个 room nights；可处理创建、修改、取消 | 作为订单、修改、取消和间夜的建模边界依据 |
| Trip.com / 携程 eBooking | 酒店可管理订单、房态、房价、收益、点评、营销活动和附加产品 | 作为携程数据模块范围依据；服务质量分公式仍以当前后台为准 |
| 美团酒店商学院 HOS 资料 | HOS 是开通预订门店的酒店经营水平综合评估，满分 5 分，包含月度多项指标；预留房是其中可观测运营项 | 作为 HOS 含义和预留房诊断依据 |
| 美团酒店商家诚信经营制度 | 诚信、服务类违规会影响结算、赔付、星级档次、促销资格、皇冠/积分资源及 HOS 评估 | 作为违规指标和风险预警依据 |
| 飞猪服务中心 MCI | 飞猪商家服务 MCI 综合咨询体验、预订体验、售后体验等，体现商家综合服务能力 | 作为飞猪 MCI/MIC 指标解释依据 |
| 酒店动态定价与价格弹性研究 | 在线酒店需求和入住率会随房型、时间和价格弹性变化，价格优化应最大化收益而非单看订单量 | 作为收益管理和 AI 调价建议的理论边界 |

## 指标分层

| 层级 | 指标 | 解决的问题 |
| --- | --- | --- |
| 经营收益 | ADR、入住率、RevPAR、TRevPAR、GOPPAR、渠道 RevPAR | 酒店是否卖得贵、卖得满、每间可售房是否产出足够收入 |
| OTA流量 | 曝光、点击、CTR、详情进入率、搜索流量、内容流量、付费流量 | 用户是否看得到、愿不愿点进来 |
| 交易转化 | 预订转化率、支付转化率、订单量、间夜量、取消率、拒单率 | 用户是否下单、支付、入住，订单是否稳定履约 |
| 价格竞争 | 价格差、价格桶、价格准确率、价差率、价格敏感度 | 价格是否有竞争力，是否因价格劣势损失流量或转化 |
| 库存房态 | 可售房、已售房、保留房/预留房、有房率、满房率、关房率 | 是否有库存承接流量，是否因库存导致拒单或低排名 |
| 口碑服务 | 点评分、点评量、差评率、回复率、投诉/违规、服务质量分、HOS、MCI | 服务质量是否影响排序、转化和平台权益 |
| 用户行为 | 提前预订天数、入住天数、客群、性别、年龄、复购率、LTV | 用户是谁、什么时候订、住多久、长期价值如何 |
| 投放活动 | CPC、消耗、ROAS、ROI、活动回报、归因收入 | 活动或广告是否带来有效收益 |

## 核心经营指标

| 指标 | 酒店含义 | 最优公式 | 宿析OS口径 | 不可计算条件 |
| --- | --- | --- | --- | --- |
| ADR | Average Daily Rate，平均已售房价，衡量售出客房的价格质量 | `room_revenue / rooms_sold` | 优先用房费收入和售出间夜；若只有 OTA 支付金额，命名为 `ota_adr` | 缺房费收入或售出间夜为 0 |
| 入住率 OCC | 已售房占可售房比例，衡量库存消化 | `occupied_rooms / available_rooms` | 只有全店可售房量时叫入住率；只有 OTA 数据时叫 OTA 售卖率 | 缺可售房量或可售房量为 0 |
| RevPAR | 每间可售房收入，合并价格和出租效率 | `room_revenue / available_rooms` 或 `ADR * occupancy` | 全店数据叫 RevPAR；渠道局部数据叫渠道 RevPAR | 缺可售房量，或 ADR/OCC 任一不可计算 |
| TRevPAR | 每间可售房总收入，包含房费外收入 | `total_revenue / available_rooms` | 适合餐饮、SPA、商城等附加产品完善后启用 | 缺附加收入或可售房量 |
| GOPPAR | 每间可售房经营毛利，关注利润而非收入 | `gross_operating_profit / available_rooms` | 投资决策、转让决策和门店利润诊断使用 | 缺成本费用或经营毛利 |
| 渠道 RevPAR | 某 OTA 渠道贡献到可售房的收入效率 | `channel_room_revenue / available_rooms` | 用于比较携程、美团、飞猪贡献；不能冒充全店 RevPAR | 缺渠道收入或全店可售房量 |

## OTA流量与转化指标

| 指标 | 酒店含义 | 最优公式 | 宿析OS使用 |
| --- | --- | --- | --- |
| 曝光 | 酒店在搜索、列表、广告或内容入口被展示的次数 | `impression_count` | 识别“看不到”的问题；关联排名、标签、广告和库存 |
| 点击 | 用户点击酒店、房型、广告或预订链接的次数 | `click_count` | 与曝光一起判断首图、价格和标题吸引力 |
| CTR | 点击率，衡量曝光后的吸引力 | `clicks / impressions` | 低 CTR 优先查首图、起价、标签、评分、竞对价 |
| 详情进入率 | 从列表/搜索进入详情页的比例 | `detail_uv / list_uv` 或 `detail_clicks / list_impressions` | 当平台不给 CTR 时作为内部替代口径 |
| 预订转化率 | 从详情、点击或访问到创建订单的比例 | `created_orders / detail_uv` 或 `created_orders / clicks` | 低值查房型、图片、退改、价格、点评和问答 |
| 支付转化率 | 创建订单后完成支付或有效确认的比例 | `paid_or_confirmed_orders / created_orders` | 低值查支付方式、库存、价差、确认失败、取消政策 |
| Look-to-book | 浏览/搜索到最终订单的漏斗效率 | `bookings / searches` 或 `bookings / page_views` | 可作为 OTA 全漏斗指标，必须声明分母口径 |
| 取消率 | 已创建或已确认订单中取消的比例 | `cancelled_orders / created_orders`，补充 `cancelled_room_nights / booked_room_nights` | 同时保留订单取消率和间夜取消率，避免低价长住取消被低估 |

## 订单与库存指标

| 指标 | 酒店含义 | 最优公式/口径 | 宿析OS使用 |
| --- | --- | --- | --- |
| 订单量 | 指定口径下的有效订单数 | `count(distinct order_id)`，必须标注创建、支付、确认、入住或完成口径 | 日报和平台贡献度使用，不同口径不能混算 |
| 间夜量 | 住宿交易量，核心房量指标 | `sum(room_count * nights)` | 收益、出租率、ADR 和渠道贡献基础 |
| 有房率 | 平台或房型在可售日期中的有房比例 | `available_dates / queried_dates` 或 `available_room_nights / total_room_nights` | 判断是否因缺库存损失排名或转化 |
| 保留房/预留房消费 | 平台保障库存被使用的间夜或订单 | 平台后台口径优先；系统记录库存、消费和违规 | 用于携程服务分、美团 HOS、飞猪 MCI 类诊断 |
| 拒单率 | 有订单需求但商家拒绝或无法履约的比例 | `rejected_orders / created_orders` | 高风险违规指标，关联平台扣分、赔付和排序 |
| 到店无房 | 用户到店后无法入住原订单的履约失败 | 事件型指标，不建议只用比例掩盖严重性 | P0 级风险，触发赔付、差评、平台处罚 |
| 提前预订天数 | 预订日到入住日之间的天数 | `checkin_date - booking_date` | 区分当晚急订、提前订、节假日蓄水 |
| 入住天数 LOS | 单笔订单住宿晚数 | `checkout_date - checkin_date` | 判断连住活动、长住优惠和库存压力 |
| Pickup | 某观察窗口新增的未来入住订单或间夜 | `current_on_books - previous_on_books` | 收益管理和节假日价格调整使用 |
| Booking pace | 未来入住日订单累积速度 | 按入住日观察 OTB 随时间变化 | 判断需求强弱，辅助调价和控房 |

## 平台私有指标

| 指标 | 最优解释 | 既有资料保留点 | 宿析OS处理 |
| --- | --- | --- | --- |
| 携程服务质量分 | 携程合作运营中的服务与履约质量信号，影响平台权益和流量判断 | 5 分钟确认、保留房/Freesale、无缺陷订单、确认后满房/涨价/拒单等 | 字段建议 `ctrip_service_score`；公式和权重不写死，以后台值为准 |
| 携程挂牌/排序分 | 平台合作层级、排序池和综合排序信号 | 特牌、金牌、银牌、排序分、订单、付费排序、服务质量分 | 字段建议 `ctrip_badge_level`、`ctrip_sort_score`；作为诊断维度不反推排名 |
| 美团 HOS | Hotel Operation System 指数，综合评估开通预订门店经营水平，公开资料显示满分 5 分 | 酒店信息、服务质量、经营产能、违规违约、预留房、点评、订单 | 字段建议 `meituan_hos_score`；拆解到可操作项：图片、资质、确认、预留房、点评、违规 |
| 美团冠级/金币 | 平台权益和资源位相关信号 | 彩冠、皇冠、银冠、金币兑换、推广通权益 | 字段建议 `meituan_crown_level`、`meituan_coin_balance`；只记录后台值 |
| 飞猪 MCI/MIC | 飞猪商家服务 MCI，综合咨询体验、预订体验、售后体验等，体现综合服务能力 | 旧图写 MIC，包含基础信息、资质、图片、营销、有房率、可退改、闪电确认、点评、拒单、销售 | 字段统一 `fliggy_mci`，别名 `MIC`；不编造权重 |
| Google Hotel Center 价格准确率 | Google 对展示价格与实际落地价格一致性的质量信号 | 价格准确率、价格覆盖、价格竞争力 | 字段建议 `google_price_accuracy_score`、`google_price_bucket`、`price_difference_percent` |
| Google booking link CTR | 酒店预订链接曝光后的点击效率 | impressions、clicks、CTR、device、country、booking window、LOS | 可用于官网直连或免费预订链接分析 |

## 诊断模板

| 现象 | 优先判断 | 可执行动作 |
| --- | --- | --- |
| 曝光低 | 排名、标签、库存、价格竞争力、平台分、广告预算 | 补标签、补库存、修价格准确率、提升平台分、做小预算测试 |
| CTR低 | 首图、起价、评分、点评量、标题、促销标签、竞对价格 | 换首图、调起价房型、补卖点标签、优化促销展示 |
| 预订转化低 | 详情页内容、房型信息、退改、发票、价格梯度、问答、差评 | 补房型图、优化房型名、明确退改和支付，处理差评 |
| 支付/确认转化低 | 支付方式、库存不足、确认慢、价差、取消政策过严 | 开启自动确认、维护库存、优化支付方式和退改 |
| ADR低 | 低价房型占比、促销过重、竞对价格、长住折扣、房型结构 | 控制低价库存、优化价格梯度、按需求日提价 |
| 入住率低 | 流量不足、价格过高、库存策略、竞对强、淡季需求低 | 提升曝光、做定向促销、开放库存、调整价格 |
| RevPAR低 | ADR 与入住率至少一项拖累 | 拆成 ADR 和 OCC 两条线分别诊断 |
| 取消率高 | 免费取消占比、价格倒挂、用户犹豫、竞对更低价、确认慢 | 分渠道分析取消原因，优化价差、确认效率和取消政策 |
| 平台分低 | 服务、库存、订单确认、资质图片、点评、违规 | 转成日常任务：补资质、补图、保留房、提升确认率、减少拒单 |

## 宿析OS落地

### 字段映射

优先复用现有字段：

- `source`：平台，如 ctrip、meituan、fliggy、google_hotel。
- `data_type`：traffic、order、revenue、review、advertising、platform_score。
- `dimension`：hotel、room_type、rate_plan、channel、device、keyword、campaign。
- `data_date`：指标归属日期。
- `amount`：收入、GMV、广告消耗等金额类字段。
- `quantity`：间夜、房量、库存等数量类字段。
- `book_order_num`：订单量。
- `data_value`：单值指标，例如 ADR、评分、转化率。
- `list_exposure`、`detail_exposure`、`flow_rate`、`order_filling_num`、`order_submit_num`：流量和转化既有字段。
- `raw_data`：平台原始字段、后台截图转录、维度明细、计算口径和来源。

### 计算守卫

- 所有指标必须有 `metric_scope`：全店、OTA渠道、平台、房型、活动、广告。
- 所有指标必须有 `calculation_basis`：创建、支付、确认、入住、离店、取消。
- 分母为 0 或缺失时返回不可计算，不返回 0。
- 平台私有分值只保存后台值和拆解建议，不写死计算公式。
- 用户、设备、订单和点评明细必须脱敏后进入 `raw_data`。

### AI回答边界

AI 可以回答：

- 指标是什么意思。
- 当前指标低可能说明什么。
- 需要补哪些字段才能计算。
- 下一步应检查哪些后台模块。
- 如何把指标转成运营任务。

AI 不可以回答：

- 未联网复核的当前平台权重。
- 未入库数据的精确排名原因。
- 没有成本数据时的 ROI。
- 只有 OTA 局部数据时的全店入住率。
- 平台私有分数的反向工程公式。
